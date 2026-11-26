<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

use OCA\ChatArchive\Db\ArchiveStore;
use OCA\ChatArchive\Db\Source;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Source\IBackupReader;
use OCA\ChatArchive\Source\ProviderRegistry;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Backup file -> rows. Safe to re-run: only messages whose platform id is
 * new for their chat are inserted. One transaction per chat.
 * Each import that reads a backup also compares it with the store: it marks
 * deleted and edited messages and gone chats. The message body and kind do
 * not change after insert.
 */
class ImportService {
	public function __construct(
		private SourceMapper $sources,
		private ArchiveStore $store,
		private ProviderRegistry $providers,
		private IRootFolder $root,
		private ICrypto $crypto,
		private MediaLinker $linker,
		private LoggerInterface $log,
	) {
	}

	/**
	 * @return array{skipped?:bool, chats?:int, messages?:int, calls?:int, linked?:int, deleted?:int, revoked?:int, edited?:int, gone_chats?:int}
	 * @throws SourceBusyException when an import or a sync already holds this source
	 */
	public function run(Source $source, bool $force = false): array {
		if (!$this->sources->claim($source)) {
			// Not claimed: nothing was touched, so nothing here may clear
			// lastError or the running state of whichever import does hold it.
			throw new SourceBusyException('an import or a sync of this source is running');
		}
		try {
			$stats = $this->import($source, $force);
			$source->setLastError(null);
			return $stats;
		} catch (\Throwable $e) {
			// The trace of a throwable holds chat text: log the message and its place only (I20.7).
			$this->log->warning('chat_archive import failed for source {id}: {class} {msg} at {file}:{line}', [
				'id' => $source->getId(),
				'class' => get_class($e),
				'msg' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			]);
			$source->setLastError($e instanceof ImportException ? $e->errorCode : 'import_failed');
			throw $e;
		} finally {
			$source->setImportState(null);
			$source->setImportSince(null);
			$this->sources->update($source);
		}
	}

	/**
	 * The skip path (unchanged stamp) does no compare and no marks.
	 * New stat keys come after 'linked'.
	 * @return array{skipped?:bool, chats?:int, messages?:int, calls?:int, linked?:int, deleted?:int, revoked?:int, edited?:int, gone_chats?:int}
	 */
	private function import(Source $source, bool $force): array {
		$provider = $this->providers->get($source->getProvider());
		$folder = $this->sourceFolder($source);
		$backup = $provider->findBackup($folder);
		if ($backup === null) {
			throw new ImportException('no_backup', 'no backup file found in ' . $source->getFolder());
		}
		$stamp = $backup->getEtag() . ':' . $backup->getSize();
		if (!$force && $stamp === $source->getLastBackup()) {
			$linked = $this->linker->link($source, $folder);
			$source->setLastSynced(time());
			return ['skipped' => true, 'linked' => $linked];
		}

		$secret = $source->getSecret() ? $this->crypto->decrypt($source->getSecret()) : null;
		$sid = $source->getId();
		$nChats = $nMsg = $nCalls = $nGone = 0;
		$marks = ['deleted' => 0, 'revoked' => 0, 'edited' => 0];
		$seenChats = [];
		// One time value for all marks of this run.
		$now = time() * 1000;
		// The reader is made inside the try. open() keeps the decrypted data in
		// memory and can still throw, for example on an unknown schema. The
		// finally must run in that case too, or the reader's memory stays held.
		$reader = null;
		try {
			$reader = $provider->open($backup, $secret);
			$secret = null;
			foreach ($reader->chats() as $chat) {
				$nChats++;
				$seenChats[] = $chat['platform_chat_id'];
				$c = $this->importChat($reader, $sid, $chat, $now);
				$nMsg += $c['added'];
				foreach ($marks as $k => $n) {
					$marks[$k] = $n + $c[$k];
				}
			}
			// Only here, after a chat loop without a throw.
			$nGone = $this->store->markGoneChats($sid, $seenChats, $now);
			foreach ($reader->calls() as $call) {
				if ($this->store->insertCall($sid, $call)) {
					$nCalls++;
				}
			}
		} finally {
			$reader?->close();
		}
		$linked = $this->linker->link($source, $folder);
		$source->setLastBackup($stamp);
		$source->setLastSynced(time());
		return ['chats' => $nChats, 'messages' => $nMsg, 'calls' => $nCalls, 'linked' => $linked]
			+ $marks + ['gone_chats' => $nGone];
	}

	/**
	 * One transaction per chat. A message with a new platform id is inserted;
	 * a stored one goes through known(); a stored one that the backup does
	 * not have is marked 'missing'. A throw rolls back the message work of the
	 * chat; the chat row that upsertChat() wrote before the transaction stays.
	 * Only rows with in_backup true can become 'missing': a row that only the
	 * live sync has seen is not in any backup yet (I20.2).
	 * @param array{platform_chat_id: string, name: ?string, is_group: bool, archived?: ?bool} $chat
	 * @return array{added:int, deleted:int, revoked:int, edited:int}
	 */
	private function importChat(IBackupReader $reader, int $sid, #[\SensitiveParameter] array $chat, int $now): array {
		$chatId = $this->store->upsertChat($sid, $chat['platform_chat_id'], $chat['name'], $chat['is_group'], $chat['archived'] ?? null);
		$n = ['added' => 0, 'deleted' => 0, 'revoked' => 0, 'edited' => 0];
		$this->store->db()->beginTransaction();
		try {
			// Inside the transaction: the hash backfill of old rows writes.
			$known = $this->store->existingMessages($chatId);
			$seen = [];
			$adopt = [];
			foreach ($reader->messages($chat['platform_chat_id']) as $m) {
				$key = $m['platform_msg_id'];
				if (isset($seen[$key])) {
					// The same key twice in one backup: the first one counts.
					continue;
				}
				$seen[$key] = true;
				if (isset($known[$key])) {
					$r = $this->known($sid, $known[$key], $m, $now);
					if ($r !== null) {
						$n[$r]++;
					}
					if (!$known[$key]['in_backup']) {
						$adopt[] = $known[$key]['id'];
					}
					continue;
				}
				$mid = $this->store->insertMessage($sid, $chatId, $m, true);
				if ($m['media'] !== null) {
					$this->store->insertMedia($sid, $mid, $m['media']);
				}
				$n['added']++;
			}
			$this->store->setInBackup($adopt);
			$gone = [];
			foreach (array_diff_key($known, $seen) as $s) {
				if ($s['deleted'] === null && $s['in_backup']) {
					$gone[] = $s['id'];
				}
			}
			$n['deleted'] = $this->store->markMissing($gone, $now);
			if ($n['added'] > 0) {
				$this->store->refreshChatStats($chatId);
			}
			$this->store->db()->commit();
		} catch (\Throwable $e) {
			$this->store->db()->rollBack();
			throw $e;
		}
		return $n;
	}

	/**
	 * A backup message whose key is stored. Flag its media, compare it, and,
	 * when only the live sync has seen the row so far, add the backup media
	 * facts to it (the phone media arrives with a later backup).
	 * @param array{id:int, kind:string, deleted:?string, hash:string, in_backup:bool} $s the stored row
	 * @param array<string, mixed> $b the backup message
	 * @return 'revoked'|'edited'|null the mark that compare() set, if any
	 */
	private function known(int $sid, #[\SensitiveParameter] array $s, #[\SensitiveParameter] array $b, int $now): ?string {
		// Backfill: a value seen for the first time on a re-run still flags the stored row.
		if (($b['media']['suspicious'] ?? 0) > 0) {
			$this->store->flagMedia($s['id'], (int)$b['media']['suspicious']);
		}
		$r = $this->compare($sid, $s, $b, $now);
		if (!$s['in_backup'] && $b['media'] !== null) {
			$this->store->fillMedia($sid, $s['id'], $b['media']);
		}
		return $r;
	}

	/**
	 * Compare a backup message with its stored row. The rules run in this
	 * order; body and kind of the stored row do not change.
	 * @param array{id:int, kind:string, deleted:?string, hash:string, in_backup:bool} $s the stored row
	 * @param array<string, mixed> $b the backup message
	 * @return 'revoked'|'edited'|null the mark that this call set, if any
	 */
	private function compare(int $sid, #[\SensitiveParameter] array $s, #[\SensitiveParameter] array $b, int $now): ?string {
		// Rule 1. A revoked message has no text, so no edit compare.
		if ($b['kind'] === 'revoked') {
			if ($s['deleted'] !== 'revoked' && $s['kind'] !== 'revoked') {
				$this->store->markRevoked($s['id'], $now);
				return 'revoked';
			}
			// A row stored with kind 'revoked' before 0.2.0 has no 'revoked'
			// mark and can be 'missing'. It is in the backup again.
			if ($s['deleted'] === 'missing') {
				$this->store->clearMissing($s['id']);
			}
			return null;
		}
		// Rule 2. The message is in the backup again.
		if ($s['deleted'] === 'missing') {
			$this->store->clearMissing($s['id']);
		}
		// Rule 3. The hash tracks the last seen version, so a re-run records nothing.
		if ($b['kind'] === $s['kind'] && in_array($s['kind'], ArchiveStore::EDITABLE, true) && $b['body'] !== null) {
			$h = ArchiveStore::bodyHash($b['body']);
			if ($h !== $s['hash'] && $this->syncedText($s, $h)) {
				// The backup text is the text that the live sync saw. That is the first version, not an edit.
				$this->store->setSeenHash($s['id'], $h);
			} elseif ($h !== $s['hash']) {
				$this->store->recordEdit($sid, $s['id'], $b['body'], $h, $now);
				return 'edited';
			}
		}
		return null;
	}

	/**
	 * True when only the live sync has seen the stored row, the sync stored no
	 * body, and the backup text has the hash of the media caption or file name
	 * that the sync stored. WAHA gives the text of a media message as its
	 * caption, and the sync keeps it in the media row (WahaMapper); the backup
	 * gives the same text as the body.
	 * @param array{id:int, hash:string, in_backup:bool} $s the stored row
	 * @param string $h bodyHash() of the backup body
	 */
	private function syncedText(array $s, string $h): bool {
		return !$s['in_backup'] && $s['hash'] === ArchiveStore::bodyHash(null)
			&& in_array($h, $this->store->mediaTextHashes($s['id']), true);
	}

	/** @throws ImportException */
	private function sourceFolder(Source $source): Folder {
		try {
			$node = $this->root->getUserFolder($source->getUserId())->get($source->getFolder());
		} catch (NotFoundException) {
			throw new ImportException('folder_missing', 'folder not found: ' . $source->getFolder());
		}
		if (!$node instanceof Folder) {
			throw new ImportException('folder_missing', 'not a folder: ' . $source->getFolder());
		}
		return $node;
	}
}
