<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

use OCA\ChatArchive\Db\ArchiveStore;
use OCA\ChatArchive\Db\Source;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Waha\WahaClient;
use OCA\ChatArchive\Waha\WahaConfig;
use OCA\ChatArchive\Waha\WahaException;
use OCA\ChatArchive\Waha\WahaMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

/**
 * Read-only live sync of one source from its WAHA session. A message pass
 * adds the messages that are newer than the last_at of each chat; a media
 * pass fetches some media files of synced messages. The message pass ends
 * after three quarters of the time box, so the media pass always gets the
 * rest. A sync adds messages and files, and it can fill in a chat name. It
 * never marks, edits or removes a message (I20.1).
 *
 * The sync keeps the invariants I20.1, I20.5, I20.7, I20.9 and I20.10 of
 * docs/DEVELOPER-GUIDE.md.
 */
class SyncService {
	/** Seconds of one run (I20.10). A test subclass can set a shorter box. */
	protected const BOX = 40;
	/** Part of the box for the message pass. The media pass gets the rest, also when many chats need a request. */
	protected const MESSAGE_SHARE = 0.75;
	private const CHAT_PAGE = 50;
	private const MESSAGE_PAGE = 100;
	/** Seconds that a sync of one open chat can take. */
	private const CHAT_BOX = 8;
	/** Media fetch errors that stop the run. Other errors count as a failed fetch of one item. */
	private const FATAL = ['waha_down', 'waha_auth', 'waha_local_blocked'];

	/** End of the run, in seconds since the epoch. */
	private float $deadline = 0.0;
	/** End of the message pass, in seconds since the epoch. */
	private float $messageDeadline = 0.0;
	private string $session = '';
	/** @var array<string, ?string> '<id>@lid' => phone id or null; one run only */
	private array $lids = [];

	public function __construct(
		private SourceMapper $sources,
		private ArchiveStore $store,
		private WahaConfig $config,
		private WahaClient $client,
		private IRootFolder $root,
		private LoggerInterface $log,
	) {
	}

	/**
	 * Sync one source. A WAHA failure goes to sync_error and is not thrown.
	 * The counts of a failed run hold what was stored before the failure.
	 *
	 * @return array{skipped?:bool, chats:int, messages:int, media:int}
	 * @throws SourceBusyException when an import or a sync already holds the source
	 */
	public function run(Source $source): array {
		$stats = ['chats' => 0, 'messages' => 0, 'media' => 0];
		$session = $source->getSyncSession();
		if ($session === null) {
			return ['skipped' => true] + $stats;
		}
		if (!$this->sources->claim($source)) {
			throw new SourceBusyException('an import or a sync of this source is running');
		}
		$start = microtime(true);
		$this->messageDeadline = $start + static::BOX * static::MESSAGE_SHARE;
		$this->deadline = $start + static::BOX;
		$this->session = $session;
		$this->lids = [];
		try {
			$folder = $this->check($source, $session);
			$this->messagePass($source->getId(), $stats);
			$stats['media'] = $this->mediaPass($source->getId(), $folder);
			$source->setSyncAt(time());
			$source->setSyncError(null);
		} catch (WahaException $e) {
			$source->setSyncError($e->errorCode);
		} catch (\Throwable $e) {
			// The trace of a throwable holds chat text: log the message and its place only (I20.7).
			$this->log->warning('chat_archive sync failed for source {id}: {class} {msg} at {file}:{line}', [
				'id' => $source->getId(),
				'class' => get_class($e),
				'msg' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			]);
			$source->setSyncError('sync_failed');
		} finally {
			$source->setImportState(null);
			$source->setImportSince(null);
			$this->sources->update($source);
			$this->lids = [];
		}
		return $stats;
	}

	/**
	 * Add the new messages of one stored chat, for a chat page that is open.
	 * The checks are those of run(). This call does no media pass and does not
	 * change sync_at or sync_error: the timed run reports the state of the
	 * source. It only adds rows (I20.1).
	 *
	 * @param string $session the session of the source
	 * @param string $jid the platform chat id of the stored chat
	 * @param ?int $lastAt the last_at of the chat, in unix ms
	 * @return int messages added; 0 also when an import or a sync holds the source
	 * @throws WahaException when a check fails or WAHA does not answer
	 */
	public function runChat(Source $source, string $session, int $chatId, string $jid, ?int $lastAt): int {
		if (!$this->sources->claim($source)) {
			return 0;
		}
		$this->messageDeadline = microtime(true) + self::CHAT_BOX;
		$this->session = $session;
		$this->lids = [];
		try {
			$this->check($source, $session);
			$rows = $this->fetch(self::wahaChatId($jid), str_ends_with($jid, '@g.us'), $lastAt);
			return $this->insertNew($source->getId(), $chatId, $rows);
		} finally {
			$source->setImportState(null);
			$source->setImportSince(null);
			$this->sources->update($source);
			$this->lids = [];
		}
	}

	/**
	 * The checks before a sync, in this order: the admin switch, the session
	 * of the user, the session state, the account and the source folder. The
	 * first check that fails throws its code. The first sync stores the
	 * account id of the session in sync_me.
	 *
	 * @return Folder the source folder
	 * @throws WahaException
	 */
	private function check(Source $source, string $session): Folder {
		if (!$this->config->enabled()) {
			throw new WahaException('waha_off');
		}
		if (!in_array($session, $this->config->sessionsOf($source->getUserId()), true)) {
			throw new WahaException('waha_unassigned');
		}
		$state = $this->client->session($session);
		if ($state['status'] !== 'WORKING') {
			throw new WahaException('waha_session_state');
		}
		$me = WahaMapper::account($source->getSyncMe(), $state['me']);
		if ($me !== null) {
			$source->setSyncMe($me);
		}
		return $this->sourceFolder($source);
	}

	/** @throws WahaException folder_missing */
	private function sourceFolder(Source $source): Folder {
		try {
			$node = $this->root->getUserFolder($source->getUserId())->get($source->getFolder());
		} catch (NotFoundException|NotPermittedException) {
			throw new WahaException('folder_missing');
		}
		if (!$node instanceof Folder) {
			throw new WahaException('folder_missing');
		}
		return $node;
	}

	/**
	 * Page the whole chat overview, newest chat first, from the first page to
	 * an empty page or the end of the message share. An up-to-date chat costs
	 * no message request, so each run reads every page: a run after an
	 * interrupted run reaches the chats that it did not load. No cursor is
	 * stored: the last_at of each chat is the cursor (I20.10).
	 *
	 * @param array{chats:int, messages:int, media:int} $stats
	 */
	private function messagePass(int $sid, array &$stats): void {
		for ($offset = 0; microtime(true) < $this->messageDeadline; $offset += self::CHAT_PAGE) {
			$page = $this->client->chatsOverview($this->session, self::CHAT_PAGE, $offset);
			if ($page === []) {
				return;
			}
			$this->chatPage($sid, $page, $stats);
		}
	}

	/**
	 * @param list<array<string, mixed>> $page chats/overview entries
	 * @param array{chats:int, messages:int, media:int} $stats
	 */
	private function chatPage(int $sid, #[\SensitiveParameter] array $page, array &$stats): void {
		foreach ($page as $chat) {
			if (microtime(true) >= $this->messageDeadline) {
				return;
			}
			$added = $this->chat($sid, $chat) ?? 0;
			$stats['chats'] += (int)($added > 0);
			$stats['messages'] += $added;
		}
	}

	/**
	 * Sync one chat of the overview.
	 *
	 * @param array<string, mixed> $chat one chats/overview entry
	 * @return ?int messages added, or null when the entry is no chat or the chat is up to date
	 */
	private function chat(int $sid, #[\SensitiveParameter] array $chat): ?int {
		$wahaId = $chat['id'] ?? null;
		if (!is_string($wahaId)) {
			return null;
		}
		$jid = WahaMapper::jid($wahaId);
		if ($jid === null) {
			return null;
		}
		$name = $chat['name'] ?? null;
		$isGroup = str_ends_with($jid, '@g.us');
		$row = $this->store->syncChat($sid, $jid, is_string($name) && $name !== '' ? $name : null, $isGroup);
		$ts = $chat['lastMessage']['timestamp'] ?? null;
		// Limit: a chat with no timestamp loads only while its last_at is null; later messages wait for a newer timestamp.
		if ($row['last_at'] !== null && (!is_numeric($ts) || (int)round((float)$ts * 1000) <= $row['last_at'])) {
			return null;
		}
		return $this->insertNew($sid, $row['id'], $this->fetch($wahaId, $isGroup, $row['last_at']));
	}

	/**
	 * Page the messages of one chat from the second of last_at on, oldest
	 * first. Stop at an empty page or at the end of the message share.
	 *
	 * @return list<array<string, mixed>> neutral message rows, oldest first
	 */
	private function fetch(string $wahaId, bool $isGroup, ?int $lastAt): array {
		$gteSec = intdiv($lastAt ?? 0, 1000);
		$rows = [];
		for ($offset = 0; ; $offset += self::MESSAGE_PAGE) {
			$page = $this->client->messages($this->session, $wahaId, $gteSec, self::MESSAGE_PAGE, $offset);
			foreach ($page as $m) {
				$row = WahaMapper::message($m, $isGroup, $this->lid(...));
				if ($row !== null) {
					$rows[] = $row;
				}
			}
			if ($page === [] || microtime(true) >= $this->messageDeadline) {
				return $rows;
			}
		}
	}

	/**
	 * Insert the rows whose key is new for the chat, in one transaction.
	 * The rows are oldest first, so after an interrupted run the last_at of
	 * the chat is still a valid cursor. A re-run inserts nothing (I20.9).
	 *
	 * @param list<array<string, mixed>> $rows neutral message rows, oldest first
	 * @return int messages inserted
	 */
	private function insertNew(int $sid, int $chatId, #[\SensitiveParameter] array $rows): int {
		if ($rows === []) {
			return 0;
		}
		$added = 0;
		$this->store->db()->beginTransaction();
		try {
			$known = $this->store->existingMessages($chatId);
			$seen = [];
			foreach ($rows as $m) {
				$key = (string)$m['platform_msg_id'];
				if (isset($known[$key]) || isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$mid = $this->store->insertMessage($sid, $chatId, $m, false);
				if ($m['media'] !== null) {
					$this->store->insertMedia($sid, $mid, $m['media']);
				}
				$added++;
			}
			if ($added > 0) {
				$this->store->refreshChatStats($chatId);
			}
			$this->store->db()->commit();
		} catch (\Throwable $e) {
			$this->store->db()->rollBack();
			throw $e;
		}
		return $added;
	}

	/** '<id>@lid' to the phone id of the contact; a failure gives null, so the lid stays. */
	private function lid(string $lid): ?string {
		if (!array_key_exists($lid, $this->lids)) {
			try {
				$this->lids[$lid] = $this->client->lid($this->session, $lid);
			} catch (WahaException) {
				$this->lids[$lid] = null;
			}
		}
		return $this->lids[$lid];
	}

	/**
	 * Fetch the media files of synced messages, newest first, up to the
	 * admin limit and the deadline. A file that cannot be written stops the
	 * pass: the next files would fail in the same way (a full quota, for
	 * example), and each failure counts as one try of its row.
	 *
	 * @return int files stored
	 */
	private function mediaPass(int $sid, Folder $folder): int {
		$limit = $this->config->mediaPerRun();
		if ($limit <= 0) {
			return 0;
		}
		$stored = 0;
		foreach ($this->store->pendingSyncMedia($sid, $limit) as $row) {
			if (microtime(true) >= $this->deadline || ($done = $this->fetchMedia($sid, $folder, $row)) === null) {
				break;
			}
			$stored += (int)$done;
		}
		return $stored;
	}

	/**
	 * Store one media file in <source folder>/WAHA/<YYYY-MM>/ and point the
	 * media row at it. A message with no media URL, a media error or an item
	 * error from WAHA counts as one failed fetch. So does a file that cannot
	 * be written. WAHA gets the message by its plain key id, also for a group
	 * message of another member.
	 *
	 * @param array{id:int, message_id:int, platform_msg_id:string, platform_chat_id:string, from_me:bool, mime:?string, file_name:?string, sent_at:int} $row
	 * @return ?bool true when the file is stored, false after a failed fetch, null when the file cannot be written
	 * @throws WahaException when WAHA is down, refuses the key or is blocked, or a part of the WAHA folder path is a file
	 */
	private function fetchMedia(int $sid, Folder $folder, #[\SensitiveParameter] array $row): ?bool {
		$chatId = self::wahaChatId($row['platform_chat_id']);
		try {
			$m = $this->client->message($this->session, $chatId, $row['platform_msg_id']);
			$url = $m['media']['url'] ?? null;
			if (!is_string($url) || $url === '' || !empty($m['media']['error'])) {
				$this->store->bumpSyncTries($row['id']);
				return false;
			}
			$stream = $this->client->download($url);
		} catch (WahaException $e) {
			if (in_array($e->errorCode, self::FATAL, true)) {
				throw $e;
			}
			$this->store->bumpSyncTries($row['id']);
			return false;
		}
		$month = gmdate('Y-m', intdiv($row['sent_at'], 1000));
		$mime = $row['mime'] ?? (is_string($m['media']['mimetype'] ?? null) ? $m['media']['mimetype'] : null);
		try {
			$dir = $this->subFolder($folder, ['WAHA', $month]);
			$name = $dir->getNonExistingName(WahaMapper::mediaName($row['platform_msg_id'], $mime, $row['file_name']));
			$file = $dir->newFile($name, $stream);
		} catch (WahaException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->store->bumpSyncTries($row['id']);
			// The trace of a throwable holds chat data: log the message and its place only (I20.7).
			$this->log->warning('chat_archive sync cannot write a media file for source {id}: {class} {msg} at {file}:{line}', [
				'id' => $sid,
				'class' => get_class($e),
				'msg' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			]);
			return null;
		} finally {
			// newFile() can close the stream itself.
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
		$this->store->setMediaStored($row['id'], $file->getId(), 'WAHA/' . $month . '/' . $name, (int)$file->getSize());
		return true;
	}

	/**
	 * Get or make the folder path $parts below $dir.
	 *
	 * @param list<string> $parts
	 * @throws WahaException folder_missing when a part is a file
	 */
	private function subFolder(Folder $dir, array $parts): Folder {
		foreach ($parts as $part) {
			try {
				$next = $dir->get($part);
			} catch (NotFoundException) {
				$next = $dir->newFolder($part);
			}
			if (!$next instanceof Folder) {
				throw new WahaException('folder_missing');
			}
			$dir = $next;
		}
		return $dir;
	}

	/** Backup jid to WAHA chat id: '<digits>@s.whatsapp.net' gives '<digits>@c.us'; other ids stay. */
	private static function wahaChatId(string $jid): string {
		return str_ends_with($jid, '@s.whatsapp.net') ? substr($jid, 0, -15) . '@c.us' : $jid;
	}
}
