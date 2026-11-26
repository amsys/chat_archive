<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Controller;

use OCA\ChatArchive\AppInfo\Application;
use OCA\ChatArchive\Cron\ImportSourceJob;
use OCA\ChatArchive\Db\ArchiveStore;
use OCA\ChatArchive\Db\Source;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\ClickAction;
use OCA\ChatArchive\Service\FullTextSearchService;
use OCA\ChatArchive\Service\Preserve;
use OCA\ChatArchive\Service\ViewPrefs;
use OCA\ChatArchive\Source\ProviderRegistry;
use OCA\ChatArchive\Source\WhatsApp\WhatsAppProvider;
use OCA\ChatArchive\Waha\WahaConfig;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\Security\ICrypto;

/**
 * Sources and preferences of the calling user. The secret is stored
 * encrypted and never read back out. An error response holds an English
 * `error` text and a stable `code` that the frontend translates.
 */
class SettingsController extends OCSController {
	public function __construct(
		IRequest $request,
		private SourceMapper $sources,
		private ArchiveStore $store,
		private ProviderRegistry $providers,
		private IRootFolder $root,
		private ICrypto $crypto,
		private IJobList $jobs,
		private IAppConfig $config,
		private FullTextSearchService $fts,
		private ClickAction $click,
		private ViewPrefs $viewPrefs,
		private Preserve $preserve,
		private WahaConfig $waha,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/settings/sources')]
	public function index(): DataResponse {
		$uid = (string)$this->userId;
		return new DataResponse([
			'sources' => array_map(
				fn (Source $s) => $s->toArray() + ['backupAt' => $this->backupAt($s)],
				$this->sources->findByUser($uid)),
			'providers' => $this->providers->describe(),
			'preserve' => $this->preserve->read($uid),
			'live' => ['enabled' => $this->waha->enabled(), 'sessions' => $this->waha->sessionsOf($uid)],
		]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/settings/sources')]
	public function create(string $provider, string $label, string $folder, #[\SensitiveParameter] ?string $secret = null,
		string $onDelete = Source::ON_DELETE_RESTORE, string $mode = Source::MODE_BACKUP, ?string $syncSession = null,
		bool $tos = false): DataResponse {
		if (!in_array($mode, [Source::MODE_BACKUP, Source::MODE_LIVE], true)) {
			return $this->error('bad_mode', "unknown mode: $mode");
		}
		$source = new Source();
		$source->setUserId((string)$this->userId);
		$source->setMode($mode);
		return $this->save($source, $provider, $label, $folder, $secret, $onDelete, $syncSession, $tos);
	}

	/**
	 * The mode of a source cannot change, so this route ignores `$mode`.
	 * A null `$syncSession` keeps the stored session; an empty string turns
	 * the live sync off.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/settings/sources/{id}')]
	public function update(int $id, string $provider, string $label, string $folder, #[\SensitiveParameter] ?string $secret = null,
		string $onDelete = Source::ON_DELETE_RESTORE, string $mode = Source::MODE_BACKUP, ?string $syncSession = null,
		bool $tos = false): DataResponse {
		try {
			$source = $this->sources->findForUser($id, (string)$this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return $this->save($source, $provider, $label, $folder, $secret, $onDelete, $syncSession, $tos);
	}

	/**
	 * Refused while the caller's preserve switch is on (I20.8). The claim
	 * keeps an import from starting while the rows are deleted.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/settings/sources/{id}')]
	public function destroy(int $id): DataResponse {
		try {
			$source = $this->sources->findForUser($id, (string)$this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		if ($this->preserve->read((string)$this->userId)) {
			return $this->error('preserve_on', 'the archive is preserved: turn off the preserve switch to remove a source',
				Http::STATUS_CONFLICT);
		}
		if (!$this->sources->claim($source)) {
			return $this->error('running', 'an import or a sync of this source is running', Http::STATUS_CONFLICT);
		}
		$this->fts->forgetSource($id, $this->store->messageIds($id));
		$this->store->deleteSource($id);
		$this->sources->delete($source);
		return new DataResponse([]);
	}

	/**
	 * Queue a forced import; it runs with the next cron tick. Only the call
	 * that changes the state adds the job, so a second click queues nothing.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/settings/sources/{id}/import')]
	public function import(int $id): DataResponse {
		try {
			$source = $this->sources->findForUser($id, (string)$this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		if ($this->sources->queue($source)) {
			$this->jobs->add(ImportSourceJob::class, ['sourceId' => $id]);
		}
		return new DataResponse($source->toArray());
	}

	/**
	 * Look for a backup file in a folder of the caller. The key is only
	 * format-checked; the backup is not opened.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/settings/probe')]
	public function probe(string $folder, #[\SensitiveParameter] ?string $secret = null): DataResponse {
		$path = '/' . trim($folder, '/');
		$node = $this->ownNode($path);
		if ($node === null) {
			return $this->error('folder_not_found', "folder not found: $path");
		}
		if (!$node instanceof Folder) {
			return $this->error('not_a_folder', "not a folder: $path");
		}
		$keyError = $this->checkKey(WhatsAppProvider::ID, $secret);
		if ($keyError !== null) {
			return $keyError;
		}
		$backup = $this->providers->get(WhatsAppProvider::ID)->findBackup($node);
		if ($backup === null) {
			return new DataResponse(['found' => false]);
		}
		return new DataResponse([
			'found' => true,
			'name' => $backup->getName(),
			'size' => (int)$backup->getSize(),
			'mtime' => $backup->getMTime(),
		]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/settings/preserve')]
	public function setPreserve(bool $preserve): DataResponse {
		$this->preserve->write((string)$this->userId, $preserve);
		return new DataResponse(['preserve' => $this->preserve->read((string)$this->userId)]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/settings/click')]
	public function setClickAction(string $action, string $template = ''): DataResponse {
		try {
			$this->click->write((string)$this->userId, $action, $template);
		} catch (\InvalidArgumentException $e) {
			return $this->error('bad_click_action', $e->getMessage());
		}
		return new DataResponse($this->click->read((string)$this->userId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/settings/view')]
	public function setViewPrefs(?bool $showDeleted = null, ?bool $showArchived = null): DataResponse {
		$this->viewPrefs->write((string)$this->userId, $showDeleted, $showArchived);
		return new DataResponse($this->viewPrefs->read((string)$this->userId));
	}

	/** Admin only: no #[NoAdminRequired] here on purpose, do not add one. */
	#[ApiRoute(verb: 'PUT', url: '/settings/fulltextsearch')]
	public function setFullTextSearch(bool $enabled): DataResponse {
		$this->config->setAppValueBool(FullTextSearchService::CONFIG_KEY, $enabled);
		return new DataResponse($this->fts->status());
	}

	private function save(Source $source, string $provider, string $label, string $folder,
		#[\SensitiveParameter] ?string $secret, string $onDelete, ?string $syncSession, bool $tos): DataResponse {
		$folder = '/' . trim($folder, '/');
		$label = trim($label);
		$error = $this->checkFields($provider, $label, $folder, $onDelete)
			?? $this->checkKey($provider, $secret)
			?? $this->checkLive($source, $syncSession, $tos);
		if ($error !== null) {
			return $error;
		}
		$source->setProvider($provider);
		$source->setLabel($label);
		$source->setFolder($folder);
		$source->setOnDelete($onDelete);
		// The parameter is marked sensitive, so a trace hides it as given.
		// Keep the trimmed key in a local variable. Do not write it back to the parameter.
		$key = trim($secret ?? '');
		if ($key !== '') {
			$source->setSecret($this->crypto->encrypt($key));
		}
		if ($syncSession === '') {
			// An empty string turns live sync off for this source.
			$source->setSyncSession(null);
		} elseif ($syncSession !== null) {
			$source->setSyncSession($syncSession);
			if ($source->getTosAt() === null) {
				$source->setTosAt(time());
			}
		}
		$saved = $source->getId() ? $this->sources->update($source) : $this->sources->insert($source);
		return new DataResponse($saved->toArray());
	}

	/** Provider, folder, delete policy and label. The folder and label come in trimmed. */
	private function checkFields(string $provider, string $label, string $folder, string $onDelete): ?DataResponse {
		try {
			$this->providers->get($provider);
		} catch (\InvalidArgumentException $e) {
			return $this->error('unknown_provider', $e->getMessage());
		}
		$node = $this->ownNode($folder);
		if ($node === null) {
			return $this->error('folder_not_found', "folder not found: $folder");
		}
		if (!$node instanceof Folder) {
			return $this->error('not_a_folder', "not a folder: $folder");
		}
		if (!in_array($onDelete, Source::ON_DELETE, true)) {
			return $this->error('bad_policy', "unknown on_delete policy: $onDelete");
		}
		if ($label === '') {
			return $this->error('label_required', 'label is required');
		}
		return null;
	}

	/**
	 * The node at `$path` in the folder of the caller. Null when the node is
	 * not there, when access to it is refused, or when another user owns it,
	 * for example in a received share.
	 */
	private function ownNode(string $path): ?\OCP\Files\Node {
		$uid = (string)$this->userId;
		try {
			$node = $this->root->getUserFolder($uid)->get($path);
		} catch (NotFoundException|NotPermittedException) {
			return null;
		}
		return $node->getOwner()?->getUID() === $uid ? $node : null;
	}

	/** Format check of a backup key. An empty key passes: it keeps the stored one. */
	private function checkKey(string $provider, #[\SensitiveParameter] ?string $secret): ?DataResponse {
		$key = trim($secret ?? '');
		if ($key !== '' && $provider === WhatsAppProvider::ID && preg_match('/^[0-9a-fA-F]{64}$/', $key) !== 1) {
			return $this->error('bad_key', 'the WhatsApp backup key is 64 hex characters');
		}
		return null;
	}

	/**
	 * Rules for a new live-only source and for a request that sets a WAHA
	 * session. A new live-only source needs a session. Only the admin
	 * assigns sessions to users, and one session feeds one source at most (I20.4).
	 * An existing source can drop its session.
	 */
	private function checkLive(Source $source, ?string $syncSession, bool $tos): ?DataResponse {
		$setsSession = $syncSession !== null && $syncSession !== '';
		$newLive = !$source->getId() && $source->getMode() === Source::MODE_LIVE;
		if (!$setsSession && !$newLive) {
			return null;
		}
		if (!$this->waha->enabled()) {
			return $this->error('live_not_allowed', 'live sync is not allowed on this server');
		}
		if (!$setsSession) {
			return $this->error('session_required', 'a live source needs a session');
		}
		if (!in_array($syncSession, $this->waha->sessionsOf((string)$this->userId), true)) {
			return $this->error('bad_session', 'this live sync session is not assigned to you');
		}
		if ($this->sessionInUse($syncSession, $source)) {
			return $this->error('session_in_use', 'another source uses this live sync session');
		}
		if (!$tos && $source->getTosAt() === null) {
			return $this->error('tos_required', 'accept the warning about the WhatsApp Terms of Service first');
		}
		if ($source->getMode() === Source::MODE_BACKUP && $source->getLastBackup() === null) {
			return $this->error('backup_first', 'import a backup before you turn on live sync');
		}
		return null;
	}

	/** True when a source other than `$source`, of any user, uses the session. */
	private function sessionInUse(string $session, Source $source): bool {
		foreach ($this->sources->findAll() as $other) {
			if ($other->getSyncSession() === $session && $other->getId() !== $source->getId()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Modification time of the backup file of a backup source. Null when the
	 * source is live-only or has no folder or no file. A storage error gives
	 * null too, so the list never fails with a 500.
	 */
	private function backupAt(Source $source): ?int {
		if ($source->getMode() === Source::MODE_LIVE) {
			return null;
		}
		try {
			$node = $this->root->getUserFolder((string)$this->userId)->get($source->getFolder());
			if (!$node instanceof Folder) {
				return null;
			}
			return $this->providers->get($source->getProvider())->findBackup($node)?->getMTime();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Error response with the English text and the stable code.
	 * @param Http::STATUS_BAD_REQUEST|Http::STATUS_CONFLICT $status
	 */
	private function error(string $code, string $text, int $status = Http::STATUS_BAD_REQUEST): DataResponse {
		return new DataResponse(['error' => $text, 'code' => $code], $status);
	}
}
