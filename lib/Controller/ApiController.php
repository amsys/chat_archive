<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Controller;

use OCA\ChatArchive\AppInfo\Application;
use OCA\ChatArchive\Db\ArchiveStore;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\ContactNames;
use OCA\ChatArchive\Service\DisplayText;
use OCA\ChatArchive\Service\FullTextSearchService;
use OCA\ChatArchive\Service\SyncService;
use OCA\ChatArchive\Service\ViewPrefs;
use OCA\ChatArchive\Waha\WahaConfig;
use OCA\ChatArchive\Waha\WahaException;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\ICacheFactory;
use OCP\IPreview;
use OCP\IRequest;

/**
 * Read side of the archive, and the realtime update of an open chat. Every
 * route checks that the source belongs to the caller.
 */
class ApiController extends OCSController {
	/** Seconds between two WAHA requests for the same chat, over all open pages. */
	private const LIVE_GAP = 4;

	public function __construct(
		IRequest $request,
		private SourceMapper $sources,
		private ArchiveStore $store,
		private ContactNames $names,
		private FullTextSearchService $fts,
		private ViewPrefs $viewPrefs,
		private IRootFolder $root,
		private IPreview $preview,
		private SyncService $sync,
		private WahaConfig $waha,
		private ICacheFactory $caches,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/sources/{id}/chats')]
	public function chats(int $id): DataResponse {
		if (!$this->owns($id)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$chats = $this->store->chats($id);
		foreach ($chats as &$c) {
			$c['name'] = $this->chatName($c);
		}
		return new DataResponse($chats);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/chats/{id}/messages')]
	public function messages(int $id, ?int $beforeAt = null, ?int $beforeId = null, ?int $afterAt = null,
		?int $afterId = null, ?int $at = null): DataResponse {
		$owner = $this->store->chatSource($id);
		if ($owner === null || $owner['userId'] !== $this->userId) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$cursor = [];
		if ($beforeAt !== null && $beforeId !== null) {
			$cursor['before'] = [$beforeAt, $beforeId];
		} elseif ($afterAt !== null && $afterId !== null) {
			$cursor['after'] = [$afterAt, $afterId];
		} elseif ($at !== null) {
			$cursor['at'] = $at;
		}
		$hideDeleted = !$this->viewPrefs->read((string)$this->userId)['showDeleted'];
		$messages = $this->store->messages($id, $cursor, $hideDeleted);
		return new DataResponse([
			'messages' => $this->attachMedia($messages),
			'senders' => $this->senderNames($messages),
		]);
	}

	/**
	 * Realtime update of an open chat: add the new messages of this one chat
	 * from WAHA. The page then loads them with the messages route. `live` is
	 * false when live sync or its realtime option is off, or the source has no
	 * session; the
	 * page then stops asking. A server with a memory cache asks WAHA at most
	 * one time in LIVE_GAP seconds for each chat.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/chats/{id}/live')]
	public function live(int $id): DataResponse {
		$chat = $this->store->chatSource($id);
		if ($chat === null || $chat['userId'] !== $this->userId) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$source = $this->sources->findForUser($chat['sourceId'], (string)$this->userId);
		$session = $source->getSyncSession();
		if (!$this->waha->realtime() || $session === null) {
			return new DataResponse(['live' => false]);
		}
		$added = 0;
		$cache = $this->caches->createDistributed('chat_archive_live');
		if ($cache->get((string)$id) === null) {
			$cache->set((string)$id, 1, self::LIVE_GAP);
			try {
				$added = $this->sync->runChat($source, $session, $id, $chat['platformChatId'], $chat['lastAt']);
			} catch (WahaException) {
				// A WAHA failure here adds no message. The timed sync reports the state of the session.
			}
		}
		$lastAt = $added > 0 ? ($this->store->chatSource($id)['lastAt'] ?? null) : $chat['lastAt'];
		return new DataResponse(['live' => true, 'added' => $added, 'lastAt' => $lastAt]);
	}

	/**
	 * Search across one source or all of the caller's sources. $q may be
	 * empty when a filter is set ("all photos in March"). Times are unix ms.
	 * $kind: one of ArchiveStore::KINDS, 'media' or 'link'. $who: me | others.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/search')]
	public function search(string $q = '', ?int $source = null, ?int $chat = null, ?int $from = null, ?int $to = null,
		string $kind = '', string $who = '', int $offset = 0): DataResponse {
		$q = trim($q);
		$offset = max(0, min($offset, 10000));
		$sourceIds = $source === null
			? array_map(static fn ($s) => $s->getId(), $this->sources->findByUser((string)$this->userId))
			: ($this->owns($source) ? [$source] : []);
		$filters = array_filter(['chat' => $chat, 'from' => $from, 'to' => $to, 'kind' => $kind, 'who' => $who]);
		$empty = ['messages' => [], 'senders' => [], 'chats' => [], 'more' => false];
		// The guard looks at the user's own filters only: a bare request stays empty whatever the toggles are
		if ($sourceIds === [] || ($q === '' && $filters === [])) {
			return new DataResponse($empty);
		}
		$prefs = $this->viewPrefs->read((string)$this->userId);
		$filters += array_filter([
			'hide_deleted' => $prefs['showDeleted'] ? null : true,
			'hide_archived' => $prefs['showArchived'] ? null : true,
		]);
		$limit = ArchiveStore::SEARCH_LIMIT;
		$hits = $this->indexHits($q, $source, $sourceIds, $filters, $limit, $offset)
			?? $this->store->search($sourceIds, $q, $filters, $limit, $offset);
		$more = count($hits) > $limit;
		$hits = array_slice($hits, 0, $limit);
		$chats = [];
		foreach ($this->store->chatsByIds(array_unique(array_column($hits, 'chatId'))) as $id => $c) {
			$chats[$id] = ['name' => $this->chatName($c), 'sourceId' => $c['sourceId']];
		}
		return new DataResponse([
			'messages' => $this->attachMedia($hits),
			'senders' => $this->senderNames($hits),
			'chats' => $chats,
			'more' => $more,
		]);
	}

	/**
	 * Hits ranked by the full text index, or null when the caller must run the plain scan.
	 * @param int[] $sourceIds
	 * @param array<string, mixed> $filters
	 * @return ?list<array<string, mixed>>
	 */
	private function indexHits(string $q, ?int $source, array $sourceIds, array $filters, int $limit, int $offset): ?array {
		if ($q === '' || !$this->fts->isEnabled()) {
			return null;
		}
		// The index ranks and SQL filters afterwards. Fetch a wide top-N, so a date filter does not starve the page.
		$ids = $this->fts->searchIds((string)$this->userId, $q, $source, $filters === [] ? $limit + 1 : 10 * $limit, $offset);
		// no ids means the index is stale or the platform is down, not "no matches": fall through to LIKE
		if ($ids === []) {
			return null;
		}
		$hits = array_slice($this->store->messagesByIds($sourceIds, $ids, $filters), $offset, $limit + 1);
		// filters emptied the first page: the index may be stale, so run the plain scan
		return $hits === [] && $offset === 0 ? null : $hits;
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/sources/{id}/calls')]
	public function calls(int $id): DataResponse {
		if (!$this->owns($id)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$calls = $this->store->calls($id);
		// Batch resolve all distinct peer names in one pass, then map results
		$peers = array_filter(array_unique(array_column($calls, 'peer')));
		$peerNames = $this->names->namesFor($peers);
		foreach ($calls as &$c) {
			$c['peerName'] = DisplayText::name($c['peer'] === null ? null
				: ($peerNames[$c['peer']] ?? ContactNames::fallback($c['peer'])));
		}
		return new DataResponse($calls);
	}

	/** The versions of an edited message after the original, oldest first. The original is the message body. Owner-checked like the other message routes. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/messages/{id}/edits')]
	public function edits(int $id): DataResponse {
		if (!$this->ownsMessage($id)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['edits' => $this->store->edits($id)]);
	}

	/** Whether the message belongs to a chat in a source owned by the caller. */
	private function ownsMessage(int $messageId): bool {
		$q = $this->store->db()->getQueryBuilder();
		$q->select('s.user_id')->from('chatarchive_message', 'm')
			->join('m', 'chatarchive_chat', 'c', $q->expr()->eq('c.id', 'm.chat_id'))
			->join('c', 'chatarchive_source', 's', $q->expr()->eq('s.id', 'c.source_id'))
			->where($q->expr()->eq('m.id', $q->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)));
		$row = $q->executeQuery()->fetch();
		return $row !== false && $row['user_id'] === $this->userId;
	}

	/** Forget cached contact names so the next chat list resolves them again. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/sources/{id}/names/refresh')]
	public function refreshNames(int $id): DataResponse {
		if (!$this->owns($id)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$n = 0;
		foreach ($this->store->chats($id) as $c) {
			$this->store->setChatDisplayName($c['id'], null);
			$n++;
		}
		return new DataResponse(['reset' => $n]);
	}

	private function owns(int $sourceId): bool {
		try {
			$this->sources->findForUser($sourceId, (string)$this->userId);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	/**
	 * Private chats resolve from the jid first: the address books of the user
	 * give the name, and the result is cached on the row ('' = tried, no
	 * match) so the list stays fast. With no match, the stored name shows
	 * when it is a name (the live sync stores the name that WhatsApp holds).
	 * A stored name that is only a number does not show; the number of the
	 * jid shows in its place, because a chat mapped from an @lid id can keep a
	 * stale number.
	 * @param array<string, mixed> $chat
	 */
	private function chatName(array $chat): string {
		if ($chat['isGroup']) {
			return (string)DisplayText::name($chat['name'] ?: $chat['platformChatId']);
		}
		if ($chat['displayName'] === null) {
			$resolved = $this->names->nameFor($chat['platformChatId']);
			$this->store->setChatDisplayName($chat['id'], $resolved ?? '');
			$chat['displayName'] = $resolved ?? '';
		}
		$stored = DisplayText::noName($chat['name']) ? ContactNames::fallback($chat['platformChatId']) : $chat['name'];
		return (string)DisplayText::name($chat['displayName'] !== '' ? $chat['displayName'] : $stored);
	}

	/**
	 * @param array<int, array<string, mixed>> $messages
	 * @return array<int, array<string, mixed>>
	 */
	private function attachMedia(array $messages): array {
		$media = $this->store->mediaForMessages(array_column($messages, 'id'));
		if ($media === []) {
			return $messages;
		}
		$userFolder = $this->root->getUserFolder((string)$this->userId);
		foreach ($messages as &$m) {
			$md = $media[$m['id']] ?? null;
			if ($md === null) {
				continue;
			}
			$md['path'] = null;
			$md['exists'] = false;
			$md['hasPreview'] = false;
			$md['realName'] = null;
			$md['realMime'] = null;
			$md['mimeMismatch'] = false;
			if ($md['fileId'] !== null) {
				$node = $userFolder->getFirstNodeById($md['fileId']);
				if ($node !== null) {
					$md['path'] = $userFolder->getRelativePath($node->getPath());
					$md['displayPath'] = DisplayText::name($md['path']);
					$md['exists'] = true;
					$md['hasPreview'] = $this->preview->isAvailable($node);
					$md['realName'] = DisplayText::name($node->getName());
					$md['realMime'] = $node->getMimetype();
					$md['mimeMismatch'] = DisplayText::mimeMismatch($md['mime'], $md['realMime']);
					$md['mime'] = $md['mime'] ?: $node->getMimetype();
					$md['mime'] = DisplayText::name($md['mime']);
					$md['realMime'] = DisplayText::name($md['realMime']);
				}
			}
			if ($md['suspicious']) {
				$md['hasPreview'] = false;
			}
			$m['media'] = $md;
		}
		return $messages;
	}

	/**
	 * @param array<int, array<string, mixed>> $messages
	 * @return array<string, string> jid -> display name for every sender on the page
	 */
	private function senderNames(array $messages): array {
		$out = [];
		foreach ($messages as $m) {
			$jid = $m['sender'];
			if ($jid !== null && !isset($out[$jid])) {
				$out[$jid] = (string)DisplayText::name($this->names->nameFor($jid) ?? ContactNames::fallback($jid));
			}
		}
		return $out;
	}
}
