<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Db;

use OCA\ChatArchive\Service\DisplayText;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** All chat, message, media and call queries. Plain rows in, plain rows out. */
class ArchiveStore {
	public const PAGE = 100;
	public const SEARCH_LIMIT = 50;
	public const INDEX_PAGE = 1000;
	/** Message kinds whose body can change after an edit on the phone. arch: D3.2 */
	public const EDITABLE = ['text', 'image', 'video', 'document', 'gif'];
	/** Largest id list in one IN (...) clause. */
	private const CHUNK = 500;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function db(): IDBConnection {
		return $this->db;
	}

	// ---- import side -----------------------------------------------------

	/**
	 * Insert or update a chat; returns its id. The chat is in the backup, so
	 * gone_at is cleared. $phoneArchived null means the backup cannot tell:
	 * phone_archived then stays as it is (D6.I4). The backup has the chat,
	 * so in_backup turns true.
	 * arch: D3.5, D5.2
	 * arch: D22.1
	 */
	public function upsertChat(int $sourceId, string $platformChatId, #[\SensitiveParameter] ?string $name, bool $isGroup, ?bool $phoneArchived = null): int {
		$platformChatId = (string)self::text($platformChatId, 255);
		$name = self::text($name, 255);
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'name')->from('chatarchive_chat')
			->where($q->expr()->eq('source_id', $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('platform_chat_id', $q->createNamedParameter($platformChatId)));
		$row = $q->executeQuery()->fetch();
		if ($row) {
			if ($name !== null && $name !== $row['name']) {
				$u = $this->db->getQueryBuilder();
				$u->update('chatarchive_chat')->set('name', $u->createNamedParameter($name))
					->where($u->expr()->eq('id', $u->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
			$g = $this->db->getQueryBuilder();
			$g->update('chatarchive_chat')->set('gone_at', $g->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($g->expr()->eq('id', $g->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($g->expr()->isNotNull('gone_at'))
				->executeStatement();
			$b = $this->db->getQueryBuilder();
			$b->update('chatarchive_chat')->set('in_backup', $b->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->where($b->expr()->eq('id', $b->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($b->expr()->eq('in_backup', $b->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
				->executeStatement();
			$this->setPhoneArchived((int)$row['id'], $phoneArchived);
			return (int)$row['id'];
		}
		$i = $this->db->getQueryBuilder();
		$i->insert('chatarchive_chat')->values([
			'source_id' => $i->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT),
			'platform_chat_id' => $i->createNamedParameter($platformChatId),
			'name' => $i->createNamedParameter($name),
			'is_group' => $i->createNamedParameter($isGroup, IQueryBuilder::PARAM_BOOL),
			'in_backup' => $i->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
		])->executeStatement();
		$id = $i->getLastInsertId();
		$this->setPhoneArchived($id, $phoneArchived);
		return $id;
	}

	/**
	 * The live sync side of upsertChat(). A new chat gets in_backup = false.
	 * On a stored chat only a NULL name changes: the sync never touches
	 * gone_at or phone_archived (I20.1).
	 * @return array{id:int, last_at:?int}
	 * arch: D22.1
	 */
	public function syncChat(int $sourceId, string $platformChatId, #[\SensitiveParameter] ?string $name, bool $isGroup): array {
		$platformChatId = (string)self::text($platformChatId, 255);
		$name = self::text($name, 255);
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'name', 'last_at')->from('chatarchive_chat')
			->where($q->expr()->eq('source_id', $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('platform_chat_id', $q->createNamedParameter($platformChatId)));
		$row = $q->executeQuery()->fetch();
		if ($row) {
			// Only a NULL name changes; a NULL $name leaves it NULL.
			$u = $this->db->getQueryBuilder();
			$u->update('chatarchive_chat')->set('name', $u->createNamedParameter($name))
				->where($u->expr()->eq('id', $u->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($u->expr()->isNull('name'))
				->executeStatement();
			return ['id' => (int)$row['id'], 'last_at' => self::intOrNull($row['last_at'])];
		}
		$i = $this->db->getQueryBuilder();
		$i->insert('chatarchive_chat')->values([
			'source_id' => $i->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT),
			'platform_chat_id' => $i->createNamedParameter($platformChatId),
			'name' => $i->createNamedParameter($name),
			'is_group' => $i->createNamedParameter($isGroup, IQueryBuilder::PARAM_BOOL),
			'in_backup' => $i->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
		])->executeStatement();
		return ['id' => $i->getLastInsertId(), 'last_at' => null];
	}

	/**
	 * A database row value of an in_backup column. NULL counts as true: a
	 * database that did not fill the default holds only backup rows.
	 * arch: D20.2
	 */
	private static function inBackup(mixed $v): bool {
		return (bool)($v ?? true);
	}

	/** Write phone_archived only when the backup has the fact (D6.I4). */
	private function setPhoneArchived(int $chatId, ?bool $phoneArchived): void {
		if ($phoneArchived === null) {
			return;
		}
		$u = $this->db->getQueryBuilder();
		$u->update('chatarchive_chat')->set('phone_archived', $u->createNamedParameter($phoneArchived, IQueryBuilder::PARAM_BOOL))
			->where($u->expr()->eq('id', $u->createNamedParameter($chatId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Mark the chats of a source that the newest complete backup did not have.
	 * Call only after the whole chat loop finished without a throw (D6.I7).
	 * A chat that is already gone keeps its first gone_at. A chat that only
	 * the live sync has seen (in_backup false) is never marked (I20.2).
	 * @param string[] $seenPlatformChatIds raw reader chat ids; scrubbed here the same way upsertChat() stores them
	 * @return int chats newly marked
	 * arch: D3.5, D5.2
	 * arch: D22.1
	 */
	public function markGoneChats(int $sourceId, array $seenPlatformChatIds, int $now): int {
		$seen = array_map(static fn ($id) => (string)self::text((string)$id, 255), $seenPlatformChatIds);
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'platform_chat_id', 'in_backup')->from('chatarchive_chat')
			->where($q->expr()->eq('source_id', $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('gone_at'));
		$rows = array_filter($q->executeQuery()->fetchAll(), static fn (array $r) => self::inBackup($r['in_backup']));
		$open = array_map('strval', array_column($rows, 'platform_chat_id', 'id'));
		return $this->updateIds('chatarchive_chat', array_keys(array_diff($open, $seen)), 'gone_at',
			static fn (IQueryBuilder $u) => $u->set('gone_at', $u->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
	}

	/**
	 * Run one UPDATE per chunk of ids. Only rows where $nullColumn IS NULL
	 * change, so a second run changes nothing.
	 * @param int[] $ids
	 * @param callable(IQueryBuilder): mixed $set adds the SET clauses
	 * @return int rows changed
	 */
	private function updateIds(string $table, array $ids, string $nullColumn, callable $set): int {
		$n = 0;
		self::eachChunk($ids, function (array $chunk) use ($table, $nullColumn, $set, &$n): void {
			$u = $this->db->getQueryBuilder();
			$u->update($table);
			$set($u);
			$n += $u->where($u->expr()->in('id', $u->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($u->expr()->isNull($nullColumn))
				->executeStatement();
		});
		return $n;
	}

	/**
	 * Call $fn once per chunk of CHUNK ids, so that no IN (...) list is longer.
	 * @param int[] $ids
	 * @param callable(int[]): void $fn
	 */
	private static function eachChunk(array $ids, callable $fn): void {
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$fn($chunk);
		}
	}

	/**
	 * The stored messages of a chat, for the per-import compare. Rows stored
	 * before 0.1.6 have no seen_hash: backfillHashes() writes it first.
	 * @return array<string, array{id:int, kind:string, deleted:?string, hash:string, in_backup:bool}> keyed by platform_msg_id
	 * arch: D4.3, D5.1
	 * arch: D22.1
	 */
	public function existingMessages(int $chatId): array {
		$this->backfillHashes($chatId);
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'platform_msg_id', 'kind', 'deleted', 'seen_hash', 'in_backup')->from('chatarchive_message')
			->where($q->expr()->eq('chat_id', $q->createNamedParameter($chatId, IQueryBuilder::PARAM_INT)));
		$rows = $q->executeQuery()->fetchAll();
		return array_combine(
			array_map('strval', array_column($rows, 'platform_msg_id')),
			array_map(static fn (array $r) => [
				'id' => (int)$r['id'],
				'kind' => (string)$r['kind'],
				'deleted' => $r['deleted'],
				'hash' => (string)$r['seen_hash'],
				'in_backup' => self::inBackup($r['in_backup']),
			], $rows),
		);
	}

	/**
	 * Write seen_hash for the rows of a chat that have none, from the stored
	 * body. Such rows were stored before 0.1.6 and have no edits, so the
	 * stored body is the last seen version (D4.3). Reads body only for these
	 * rows, once in the life of the archive.
	 */
	private function backfillHashes(int $chatId): void {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'body')->from('chatarchive_message')
			->where($q->expr()->eq('chat_id', $q->createNamedParameter($chatId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('seen_hash'));
		$byHash = [];
		foreach ($q->executeQuery()->fetchAll() as $row) {
			$byHash[self::bodyHash($row['body'])][] = (int)$row['id'];
		}
		foreach ($byHash as $h => $ids) {
			$this->updateIds('chatarchive_message', $ids, 'seen_hash',
				static fn (IQueryBuilder $u) => $u->set('seen_hash', $u->createNamedParameter((string)$h)));
		}
	}

	/**
	 * sha1 of the scrubbed body, so raw backup bytes and their stored form
	 * give the same hash. NULL has its own value: text() removes NUL bytes,
	 * so no scrubbed body can equal it.
	 * arch: D3.1
	 */
	public static function bodyHash(#[\SensitiveParameter] ?string $body): string {
		return sha1(self::text($body) ?? "\0null");
	}

	/**
	 * A message the backup now shows as deleted for everyone. Permanent (D6.I5).
	 * A row that is already revoked keeps its first deleted_at.
	 * arch: D3.2, D5.2
	 */
	public function markRevoked(int $messageId, int $now): void {
		$u = $this->db->getQueryBuilder();
		$u->update('chatarchive_message')
			->set('deleted', $u->createNamedParameter('revoked'))
			->set('deleted_at', $u->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($u->expr()->eq('id', $u->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($u->expr()->orX(
				$u->expr()->isNull('deleted'),
				$u->expr()->neq('deleted', $u->createNamedParameter('revoked')),
			))
			->executeStatement();
	}

	/**
	 * A 'missing' message is in the backup again. A 'revoked' row does not change.
	 * arch: D3.2, D5.2, D6.I5
	 */
	public function clearMissing(int $messageId): void {
		$u = $this->db->getQueryBuilder();
		$u->update('chatarchive_message')
			->set('deleted', $u->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('deleted_at', $u->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($u->expr()->eq('id', $u->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($u->expr()->eq('deleted', $u->createNamedParameter('missing')))
			->executeStatement();
	}

	/**
	 * Keep a new version of an edited message. The message body and kind do
	 * not change (D6.I1): the version goes to chatarchive_edit, and only
	 * seen_hash and edited_at change on the message row.
	 * @param string $hash bodyHash($body)
	 * arch: D3.2, D5.2
	 */
	public function recordEdit(int $sourceId, int $messageId, #[\SensitiveParameter] ?string $body, string $hash, int $now): void {
		$i = $this->db->getQueryBuilder();
		$i->insert('chatarchive_edit')->values([
			'source_id' => $i->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT),
			'message_id' => $i->createNamedParameter($messageId, IQueryBuilder::PARAM_INT),
			'seen_at' => $i->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'body' => $i->createNamedParameter(self::text($body)),
		])->executeStatement();
		$u = $this->db->getQueryBuilder();
		$u->update('chatarchive_message')
			->set('seen_hash', $u->createNamedParameter($hash))
			->set('edited_at', $u->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($u->expr()->eq('id', $u->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Mark messages that the backup no longer has. Only rows with no mark
	 * change: a 'revoked' row stays 'revoked'.
	 * @param int[] $messageIds
	 * @return int rows newly marked
	 * arch: D3.4, D5.2
	 */
	public function markMissing(array $messageIds, int $now): int {
		return $this->updateIds('chatarchive_message', $messageIds, 'deleted',
			static fn (IQueryBuilder $u) => $u->set('deleted', $u->createNamedParameter('missing'))
				->set('deleted_at', $u->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
	}

	/**
	 * The backup now has these messages: set in_backup true, one UPDATE per
	 * chunk of ids.
	 * @param int[] $messageIds
	 * arch: D22.1
	 */
	public function setInBackup(array $messageIds): void {
		self::eachChunk($messageIds, function (array $chunk): void {
			$u = $this->db->getQueryBuilder();
			$u->update('chatarchive_message')
				->set('in_backup', $u->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->where($u->expr()->in('id', $u->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		});
	}

	/**
	 * Insert one message. A message that is first seen revoked gets
	 * deleted = 'revoked' with deleted_at = sent_at. $inBackup is false for a
	 * row that only the live sync has seen.
	 * @param array<string, mixed> $m a reader message row
	 * arch: D3.1, D3.3
	 * arch: D22.1
	 */
	public function insertMessage(int $sourceId, int $chatId, #[\SensitiveParameter] array $m, bool $inBackup = true): int {
		$call = $m['call'] ?? null;
		$kind = self::text($m['kind'], 32);
		$q = $this->db->getQueryBuilder();
		$q->insert('chatarchive_message')->values([
			'source_id' => $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT),
			'chat_id' => $q->createNamedParameter($chatId, IQueryBuilder::PARAM_INT),
			'platform_msg_id' => $q->createNamedParameter(self::text($m['platform_msg_id'], 255)),
			'sent_at' => $q->createNamedParameter($m['sent_at'], IQueryBuilder::PARAM_INT),
			'from_me' => $q->createNamedParameter($m['from_me'], IQueryBuilder::PARAM_BOOL),
			'sender' => $q->createNamedParameter(self::text($m['sender'], 255)),
			'kind' => $q->createNamedParameter($kind),
			'body' => $q->createNamedParameter(self::text($m['body'])),
			'seen_hash' => $q->createNamedParameter(self::bodyHash($m['body'])),
			'call_video' => $q->createNamedParameter($call === null ? null : $call['video'], IQueryBuilder::PARAM_BOOL),
			'call_result' => $q->createNamedParameter(self::text($call['result'] ?? null, 16)),
			'call_duration' => $q->createNamedParameter($call['duration_s'] ?? null, IQueryBuilder::PARAM_INT),
			'in_backup' => $q->createNamedParameter($inBackup, IQueryBuilder::PARAM_BOOL),
		])->executeStatement();
		$id = $q->getLastInsertId();
		if ($kind === 'revoked') {
			$this->markRevoked($id, (int)$m['sent_at']);
		}
		return $id;
	}

	/** @param array<string, mixed> $md a reader media row. arch: D2.2 */
	public function insertMedia(int $sourceId, int $messageId, #[\SensitiveParameter] array $md): void {
		$q = $this->db->getQueryBuilder();
		$q->insert('chatarchive_media')->values([
			'source_id' => $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT),
			'message_id' => $q->createNamedParameter($messageId, IQueryBuilder::PARAM_INT),
			'file_hash' => $q->createNamedParameter(self::text($md['file_hash'] ?? null, 64)),
			'mime' => $q->createNamedParameter(self::text($md['mime'] ?? null, 255)),
			'size' => $q->createNamedParameter($md['size'] ?? null, IQueryBuilder::PARAM_INT),
			'file_name' => $q->createNamedParameter(self::text($md['file_name'] ?? null, 255)),
			'caption' => $q->createNamedParameter(self::text($md['caption'] ?? null)),
			'src_path' => $q->createNamedParameter(self::relPath($md['src_path'] ?? null)),
			'suspicious' => $q->createNamedParameter($md['suspicious'] ?? null, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/**
	 * Set the flag on a stored media row unless it is already flagged. Sticky:
	 * once set above 0, no path here clears or lowers it (I2.1).
	 * arch: D2.2, D2.3
	 */
	public function flagMedia(int $messageId, int $value): void {
		$u = $this->db->getQueryBuilder();
		$u->update('chatarchive_media')
			->set('suspicious', $u->createNamedParameter($value, IQueryBuilder::PARAM_INT))
			->where($u->expr()->eq('message_id', $u->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($u->expr()->orX(
				$u->expr()->isNull('suspicious'),
				$u->expr()->eq('suspicious', $u->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
			))
			->executeStatement();
	}

	/**
	 * Add backup media facts to a message that the live sync stored first.
	 * With no media row, insert one. On a stored row, set only the columns
	 * that are NULL (COALESCE keeps a stored value). src_path changes only
	 * when the row has neither a src_path nor a file_id. This method does
	 * not write suspicious on a stored row: the import sets it through
	 * flagMedia() for every stored key.
	 * @param array<string, mixed> $md a reader media row
	 * arch: D22.1
	 */
	public function fillMedia(int $sourceId, int $messageId, #[\SensitiveParameter] array $md): void {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('chatarchive_media')
			->where($q->expr()->eq('message_id', $q->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)));
		$id = $q->executeQuery()->fetchOne();
		if ($id === false) {
			$this->insertMedia($sourceId, $messageId, $md);
			return;
		}
		$u = $this->db->getQueryBuilder();
		$keep = static fn (string $col, mixed $value, mixed $type = IQueryBuilder::PARAM_STR) => $u->createFunction(
			'COALESCE(' . $u->getColumnName($col) . ', ' . $u->createNamedParameter($value, $type) . ')');
		$u->update('chatarchive_media')
			->set('file_hash', $keep('file_hash', self::text($md['file_hash'] ?? null, 64)))
			->set('mime', $keep('mime', self::text($md['mime'] ?? null, 255)))
			->set('size', $keep('size', $md['size'] ?? null, IQueryBuilder::PARAM_INT))
			->set('file_name', $keep('file_name', self::text($md['file_name'] ?? null, 255)))
			->set('caption', $keep('caption', self::text($md['caption'] ?? null)))
			->set('src_path', $u->createFunction('CASE WHEN ' . $u->getColumnName('file_id') . ' IS NULL THEN '
				. $keep('src_path', self::relPath($md['src_path'] ?? null)) . ' ELSE ' . $u->getColumnName('src_path') . ' END'))
			->where($u->expr()->eq('id', $u->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Media rows that the live sync may still fetch from WAHA: no file yet,
	 * fewer than 3 failed fetches, and a message that only the sync has seen.
	 * Rows with fewer failed fetches come first, so a file that WAHA cannot
	 * give does not hold back the files behind it. Then newest sent_at first.
	 * @return list<array{id:int, message_id:int, platform_msg_id:string, platform_chat_id:string, from_me:bool, mime:?string, file_name:?string, sent_at:int}>
	 * arch: D22.1
	 */
	public function pendingSyncMedia(int $sourceId, int $limit): array {
		$q = $this->db->getQueryBuilder();
		$q->select('md.id', 'md.message_id', 'm.platform_msg_id', 'c.platform_chat_id', 'm.from_me', 'md.mime',
			'md.file_name', 'm.sent_at')
			->from('chatarchive_media', 'md')
			->join('md', 'chatarchive_message', 'm', $q->expr()->eq('m.id', 'md.message_id'))
			->join('m', 'chatarchive_chat', 'c', $q->expr()->eq('c.id', 'm.chat_id'))
			->where($q->expr()->eq('md.source_id', $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('md.file_id'))
			->andWhere($q->expr()->orX(
				$q->expr()->isNull('md.sync_tries'),
				$q->expr()->lt('md.sync_tries', $q->createNamedParameter(3, IQueryBuilder::PARAM_INT)),
			))
			->andWhere($q->expr()->eq('m.in_backup', $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy($q->createFunction('COALESCE(' . $q->getColumnName('sync_tries', 'md') . ', 0)'), 'ASC')
			->addOrderBy('m.sent_at', 'DESC')->addOrderBy('md.id', 'DESC')
			->setMaxResults($limit);
		return array_map(static fn (array $r) => [
			'id' => (int)$r['id'],
			'message_id' => (int)$r['message_id'],
			'platform_msg_id' => (string)$r['platform_msg_id'],
			'platform_chat_id' => (string)$r['platform_chat_id'],
			'from_me' => (bool)$r['from_me'],
			'mime' => $r['mime'],
			'file_name' => $r['file_name'],
			'sent_at' => (int)$r['sent_at'],
		], $q->executeQuery()->fetchAll());
	}

	/**
	 * One more failed media fetch from WAHA. A NULL counter counts as 0.
	 * arch: D22.1
	 */
	public function bumpSyncTries(int $mediaId): void {
		$u = $this->db->getQueryBuilder();
		$u->update('chatarchive_media')
			->set('sync_tries', $u->createFunction('COALESCE(' . $u->getColumnName('sync_tries') . ', 0) + 1'))
			->where($u->expr()->eq('id', $u->createNamedParameter($mediaId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * The live sync stored the media file: point the row at it. A NULL $size
	 * keeps the stored size.
	 * @param string $srcPath path relative to the source folder
	 * arch: D22.1
	 */
	public function setMediaStored(int $mediaId, int $fileId, string $srcPath, ?int $size): void {
		$u = $this->db->getQueryBuilder();
		$u->update('chatarchive_media')
			->set('file_id', $u->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->set('src_path', $u->createNamedParameter(self::relPath($srcPath)))
			->set('size', $u->createFunction('COALESCE(' . $u->createNamedParameter($size, IQueryBuilder::PARAM_INT)
				. ', ' . $u->getColumnName('size') . ')'))
			->where($u->expr()->eq('id', $u->createNamedParameter($mediaId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Text from a backup is untrusted: drop NUL bytes and invalid UTF-8, which
	 * break the database and json_encode, and cut to the column length.
	 */
	private static function text(?string $s, int $max = 0): ?string {
		if ($s === null) {
			return null;
		}
		$s = str_replace("\0", '', mb_scrub($s, 'UTF-8'));
		return $max > 0 ? mb_substr($s, 0, $max, 'UTF-8') : $s;
	}

	/** A media path from a backup must stay inside the source folder: relative, and no '..' segment. */
	private static function relPath(?string $p): ?string {
		$p = ltrim((string)self::text($p, 4000), '/');
		return $p === '' || in_array('..', explode('/', $p), true) ? null : $p;
	}

	private static function intOrNull(mixed $v): ?int {
		return $v === null ? null : (int)$v;
	}

	/**
	 * Insert a call unless its platform id is known. Returns true when inserted.
	 * @param array<string, mixed> $c a reader call row
	 */
	public function insertCall(int $sourceId, array $c): bool {
		$c['platform_call_id'] = self::text($c['platform_call_id'], 255);
		$c['peer'] = self::text($c['peer'], 255);
		$c['result'] = self::text($c['result'], 16);
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('chatarchive_call')
			->where($q->expr()->eq('source_id', $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('platform_call_id', $q->createNamedParameter($c['platform_call_id'])));
		if ($q->executeQuery()->fetchOne() !== false) {
			return false;
		}
		$i = $this->db->getQueryBuilder();
		$i->insert('chatarchive_call')->values([
			'source_id' => $i->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT),
			'platform_call_id' => $i->createNamedParameter($c['platform_call_id']),
			'peer' => $i->createNamedParameter($c['peer']),
			'started_at' => $i->createNamedParameter($c['started_at'], IQueryBuilder::PARAM_INT),
			'from_me' => $i->createNamedParameter($c['from_me'], IQueryBuilder::PARAM_BOOL),
			'video' => $i->createNamedParameter($c['video'], IQueryBuilder::PARAM_BOOL),
			'duration_s' => $i->createNamedParameter($c['duration_s'], IQueryBuilder::PARAM_INT),
			'result' => $i->createNamedParameter($c['result']),
		])->executeStatement();
		return true;
	}

	public function refreshChatStats(int $chatId): void {
		$q = $this->db->getQueryBuilder();
		$q->select($q->func()->max('sent_at'), $q->func()->count('id'))->from('chatarchive_message')
			->where($q->expr()->eq('chat_id', $q->createNamedParameter($chatId, IQueryBuilder::PARAM_INT)));
		$row = $q->executeQuery()->fetch(\PDO::FETCH_NUM);
		$u = $this->db->getQueryBuilder();
		$u->update('chatarchive_chat')
			->set('last_at', $u->createNamedParameter(self::intOrNull($row[0]), IQueryBuilder::PARAM_INT))
			->set('msg_count', $u->createNamedParameter((int)$row[1], IQueryBuilder::PARAM_INT))
			->where($u->expr()->eq('id', $u->createNamedParameter($chatId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function setChatDisplayName(int $chatId, #[\SensitiveParameter] ?string $name): void {
		$u = $this->db->getQueryBuilder();
		$u->update('chatarchive_chat')->set('display_name', $u->createNamedParameter($name))
			->where($u->expr()->eq('id', $u->createNamedParameter($chatId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	// ---- media pointers --------------------------------------------------

	/** @return iterable<array{id:int, src_path:?string, file_name:?string, file_id:?int}> */
	public function mediaOfSource(int $sourceId): iterable {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'src_path', 'file_name', 'file_id')->from('chatarchive_media')
			->where($q->expr()->eq('source_id', $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)));
		$r = $q->executeQuery();
		while ($row = $r->fetch()) {
			yield ['id' => (int)$row['id'], 'src_path' => $row['src_path'], 'file_name' => $row['file_name'],
				'file_id' => self::intOrNull($row['file_id'])];
		}
		$r->closeCursor();
	}

	public function setMediaFile(int $mediaId, ?int $fileId): void {
		$u = $this->db->getQueryBuilder();
		$u->update('chatarchive_media')->set('file_id', $u->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->where($u->expr()->eq('id', $u->createNamedParameter($mediaId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function hasSources(): bool {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('chatarchive_source')->setMaxResults(1);
		return $q->executeQuery()->fetchOne() !== false;
	}

	/**
	 * Media rows (with their source) that point at any of the given file ids.
	 * @param int[] $fileIds
	 * @return array<int, array{id:int, file_id:int, src_path:?string, source_id:int, user_id:string, folder:string, on_delete:string, suspicious:?int}>
	 * arch: D2.2
	 */
	public function mediaByFileIds(array $fileIds): array {
		$out = [];
		self::eachChunk($fileIds, function (array $chunk) use (&$out): void {
			$q = $this->db->getQueryBuilder();
			$q->select('m.id', 'm.file_id', 'm.src_path', 'm.source_id', 's.user_id', 's.folder', 's.on_delete', 'm.suspicious')
				->from('chatarchive_media', 'm')
				->join('m', 'chatarchive_source', 's', $q->expr()->eq('s.id', 'm.source_id'))
				->where($q->expr()->in('m.file_id', $q->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($q->executeQuery()->fetchAll() as $row) {
				$out[] = ['id' => (int)$row['id'], 'file_id' => (int)$row['file_id'], 'src_path' => $row['src_path'],
					'source_id' => (int)$row['source_id'], 'user_id' => $row['user_id'], 'folder' => $row['folder'],
					'on_delete' => $row['on_delete'], 'suspicious' => self::intOrNull($row['suspicious'])];
			}
		});
		return $out;
	}

	/**
	 * Ids of every message of a source. Read this before deleteSource();
	 * the rows are gone after it.
	 * @return int[]
	 */
	public function messageIds(int $sourceId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('chatarchive_message')
			->where($q->expr()->eq('source_id', $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)));
		return array_map('intval', array_column($q->executeQuery()->fetchAll(), 'id'));
	}

	/** Remove a source's chats, messages, edits, media and calls. The source row itself is the caller's. */
	public function deleteSource(int $sourceId): void {
		foreach (['chatarchive_call', 'chatarchive_media', 'chatarchive_edit', 'chatarchive_message', 'chatarchive_chat'] as $table) {
			$d = $this->db->getQueryBuilder();
			$d->delete($table)
				->where($d->expr()->eq('source_id', $d->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	// ---- viewer side -----------------------------------------------------

	/** @return array<int, array<string, mixed>> */
	public function chats(int $sourceId): array {
		$q = $this->chatQuery();
		$q->where($q->expr()->eq('source_id', $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->orderBy('last_at', 'DESC');
		return array_map([$this, 'chatRow'], $q->executeQuery()->fetchAll());
	}

	/**
	 * @param int[] $ids
	 * @return array<int, array<string, mixed>> chat id => chat row
	 */
	public function chatsByIds(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$q = $this->chatQuery();
		$q->where($q->expr()->in('id', $q->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$out = [];
		foreach ($q->executeQuery()->fetchAll() as $r) {
			$out[(int)$r['id']] = $this->chatRow($r);
		}
		return $out;
	}

	private function chatQuery(): IQueryBuilder {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'source_id', 'platform_chat_id', 'name', 'display_name', 'is_group', 'last_at', 'msg_count',
			'phone_archived', 'gone_at')
			->from('chatarchive_chat');
		return $q;
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	private function chatRow(array $r): array {
		return [
			'id' => (int)$r['id'],
			'sourceId' => (int)$r['source_id'],
			'platformChatId' => $r['platform_chat_id'],
			'name' => $r['name'],
			'displayName' => $r['display_name'],
			'isGroup' => (bool)$r['is_group'],
			'lastAt' => self::intOrNull($r['last_at']),
			'msgCount' => (int)$r['msg_count'],
			'phoneArchived' => (bool)$r['phone_archived'],
			'goneAt' => self::intOrNull($r['gone_at']),
		];
	}

	/** @return array{sourceId: int, userId: string, platformChatId: string, lastAt: ?int}|null */
	public function chatSource(int $chatId): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('c.id', 'c.source_id', 'c.platform_chat_id', 'c.last_at', 's.user_id')->from('chatarchive_chat', 'c')
			->join('c', 'chatarchive_source', 's', $q->expr()->eq('s.id', 'c.source_id'))
			->where($q->expr()->eq('c.id', $q->createNamedParameter($chatId, IQueryBuilder::PARAM_INT)));
		$row = $q->executeQuery()->fetch();
		return $row ? [
			'sourceId' => (int)$row['source_id'],
			'userId' => $row['user_id'],
			'platformChatId' => (string)$row['platform_chat_id'],
			'lastAt' => self::intOrNull($row['last_at']),
		] : null;
	}

	/**
	 * One page of messages. The cursor is the row value (sent_at, id): message
	 * ids are not monotonic per chat, so a two-column cursor is the only one
	 * that neither skips nor repeats rows.
	 *
	 * $hideDeleted drops rows marked 'missing' or 'revoked'. Only the in-app
	 * message page sets it, from the user's toggle (D6.I3).
	 *
	 * @param array{before?: array{int,int}, after?: array{int,int}, at?: int} $cursor
	 * @return array<int, array<string, mixed>>
	 * arch: D5.3
	 */
	public function messages(int $chatId, array $cursor, bool $hideDeleted = false): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'sent_at', 'from_me', 'sender', 'kind', 'body', 'call_video', 'call_result', 'call_duration',
			'deleted', 'deleted_at', 'edited_at')
			->from('chatarchive_message')
			->where($q->expr()->eq('chat_id', $q->createNamedParameter($chatId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(self::PAGE);
		if ($hideDeleted) {
			$q->andWhere($q->expr()->isNull('deleted'));
		}
		$desc = false;
		if (isset($cursor['before'])) {
			[$at, $id] = $cursor['before'];
			$q->andWhere($q->expr()->orX(
				$q->expr()->lt('sent_at', $q->createNamedParameter($at, IQueryBuilder::PARAM_INT)),
				$q->expr()->andX(
					$q->expr()->eq('sent_at', $q->createNamedParameter($at, IQueryBuilder::PARAM_INT)),
					$q->expr()->lt('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
				),
			));
			$desc = true;
		} elseif (isset($cursor['after'])) {
			[$at, $id] = $cursor['after'];
			$q->andWhere($q->expr()->orX(
				$q->expr()->gt('sent_at', $q->createNamedParameter($at, IQueryBuilder::PARAM_INT)),
				$q->expr()->andX(
					$q->expr()->eq('sent_at', $q->createNamedParameter($at, IQueryBuilder::PARAM_INT)),
					$q->expr()->gt('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
				),
			));
		} elseif (isset($cursor['at'])) {
			$q->andWhere($q->expr()->gte('sent_at', $q->createNamedParameter($cursor['at'], IQueryBuilder::PARAM_INT)));
		} else {
			$desc = true; // newest page
		}
		$dir = $desc ? 'DESC' : 'ASC';
		$q->orderBy('sent_at', $dir)->addOrderBy('id', $dir);
		$rows = $q->executeQuery()->fetchAll();
		if ($desc) {
			$rows = array_reverse($rows);
		}
		return $this->withLatestBodies(array_map([$this, 'messageRow'], $rows));
	}

	/**
	 * Add latestBody to each row: the body of the newest chatarchive_edit
	 * row for an edited message, null for a message with no edit. One
	 * query for the whole page, then the newest row per message is picked
	 * in PHP (arch: U4).
	 * @param array<int, array<string, mixed>> $rows rows with 'id' and 'editedAt'
	 * @return array<int, array<string, mixed>>
	 */
	private function withLatestBodies(array $rows): array {
		$editedIds = array_column(array_filter($rows, static fn (array $r) => $r['editedAt'] !== null), 'id');
		$newest = $editedIds === [] ? [] : $this->newestEditBodies($editedIds);
		foreach ($rows as &$r) {
			$r['latestBody'] = $newest[$r['id']] ?? null;
		}
		unset($r);
		return $rows;
	}

	/**
	 * The newest chatarchive_edit body per message id. Ordering the query
	 * oldest first and overwriting on each row leaves the newest body last.
	 * @param int[] $messageIds
	 * @return array<int, ?string> message id => body
	 */
	private function newestEditBodies(array $messageIds): array {
		$q = $this->db->getQueryBuilder();
		$q->select('message_id', 'body')->from('chatarchive_edit')
			->where($q->expr()->in('message_id', $q->createNamedParameter($messageIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('seen_at', 'ASC')->addOrderBy('id', 'ASC');
		$out = [];
		foreach ($q->executeQuery()->fetchAll() as $e) {
			$out[(int)$e['message_id']] = $e['body'];
		}
		return $out;
	}

	/**
	 * The versions of an edited message after the original, oldest first.
	 * The original is the message body.
	 * @return list<array{seenAt:int, body:?string}>
	 * arch: D5.3
	 */
	public function edits(int $messageId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('seen_at', 'body')->from('chatarchive_edit')
			->where($q->expr()->eq('message_id', $q->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->orderBy('seen_at', 'ASC')->addOrderBy('id', 'ASC');
		return array_map(static fn ($r) => [
			'seenAt' => (int)$r['seen_at'],
			'body' => $r['body'],
		], $q->executeQuery()->fetchAll());
	}

	/** UI filter name => message kinds it covers. 'link' is a body match, not a kind. */
	public const KINDS = [
		'text' => ['text'],
		'image' => ['image', 'gif', 'view_once_image'],
		'video' => ['video', 'video_note', 'view_once_video'],
		'audio' => ['audio'],
		'document' => ['document'],
		'call' => ['call_log', 'missed_call'],
	];

	/**
	 * LIKE scan over body, media caption and media file name, newest first.
	 * Fallback path when fulltextsearch is off, unavailable or stale. Returns
	 * one row more than $limit when there is a next page.
	 *
	 * @param int[] $sourceIds
	 * @param array{chat?:int, from?:int, to?:int, kind?:string, who?:string, hide_deleted?:bool, hide_archived?:bool} $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function search(array $sourceIds, string $term, array $filters, int $limit = self::SEARCH_LIMIT, int $offset = 0): array {
		$q = $this->searchQuery($sourceIds, $filters);
		if ($term !== '') {
			$like = $q->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%');
			$q->andWhere($q->expr()->orX(
				$q->expr()->iLike('m.body', $like),
				$q->expr()->iLike('md.caption', $like),
				$q->expr()->iLike('md.file_name', $like),
			));
		}
		$q->orderBy('m.sent_at', 'DESC')->addOrderBy('m.id', 'DESC')
			->setMaxResults($limit + 1)->setFirstResult($offset);
		return $this->withLatestBodies(array_map([$this, 'searchRow'], $q->executeQuery()->fetchAll()));
	}

	/**
	 * Search hits by message id, for the FullTextSearch path. Ids are
	 * re-authorized against the caller's sources here: a search-platform id
	 * is never trusted on its own. Result order follows $ids (best match
	 * first), not SQL. The id list goes to SQL in chunks of 500 (P12).
	 * @param int[] $sourceIds
	 * @param int[] $ids
	 * @param array{chat?:int, from?:int, to?:int, kind?:string, who?:string, hide_deleted?:bool, hide_archived?:bool} $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function messagesByIds(array $sourceIds, array $ids, array $filters): array {
		if ($ids === []) {
			return [];
		}
		$rows = [];
		// arch: D22.1
		self::eachChunk($ids, function (array $chunk) use ($sourceIds, $filters, &$rows): void {
			$q = $this->searchQuery($sourceIds, $filters);
			$q->andWhere($q->expr()->in('m.id', $q->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($rows, ...$this->withLatestBodies(array_map([$this, 'searchRow'], $q->executeQuery()->fetchAll())));
		});
		$order = array_flip($ids);
		usort($rows, static fn ($a, $b) => $order[$a['id']] <=> $order[$b['id']]);
		return $rows;
	}

	/**
	 * Message rows joined with their media row, restricted to $sourceIds and the filters both search paths share.
	 * hide_deleted and hide_archived have no default: a key that is absent
	 * or false filters nothing. Only ApiController sets them (D6.I3).
	 * arch: D5.3
	 * @param int[] $sourceIds
	 * @param array{chat?:int, from?:int, to?:int, kind?:string, who?:string, hide_deleted?:bool, hide_archived?:bool} $filters
	 */
	private function searchQuery(array $sourceIds, array $filters): IQueryBuilder {
		$q = $this->db->getQueryBuilder();
		$q->select('m.id', 'm.source_id', 'm.chat_id', 'm.sent_at', 'm.from_me', 'm.sender', 'm.kind', 'm.body',
			'm.call_video', 'm.call_result', 'm.call_duration', 'm.deleted', 'm.deleted_at', 'm.edited_at')
			->from('chatarchive_message', 'm')
			->leftJoin('m', 'chatarchive_media', 'md', $q->expr()->eq('md.message_id', 'm.id'))
			->where($q->expr()->in('m.source_id', $q->createNamedParameter($sourceIds, IQueryBuilder::PARAM_INT_ARRAY)));
		// The chat row holds gone_at and phone_archived. Join it only for a hide filter.
		if (!empty($filters['hide_deleted']) || !empty($filters['hide_archived'])) {
			$q->join('m', 'chatarchive_chat', 'c', $q->expr()->eq('c.id', 'm.chat_id'));
		}
		if (isset($filters['chat'])) {
			$q->andWhere($q->expr()->eq('m.chat_id', $q->createNamedParameter($filters['chat'], IQueryBuilder::PARAM_INT)));
		}
		if (isset($filters['from'])) {
			$q->andWhere($q->expr()->gte('m.sent_at', $q->createNamedParameter($filters['from'], IQueryBuilder::PARAM_INT)));
		}
		if (isset($filters['to'])) {
			$q->andWhere($q->expr()->lte('m.sent_at', $q->createNamedParameter($filters['to'], IQueryBuilder::PARAM_INT)));
		}
		if (($filters['who'] ?? '') === 'me' || ($filters['who'] ?? '') === 'others') {
			$q->andWhere($q->expr()->eq('m.from_me', $q->createNamedParameter($filters['who'] === 'me', IQueryBuilder::PARAM_BOOL)));
		}
		if (!empty($filters['hide_deleted'])) {
			$q->andWhere($q->expr()->isNull('m.deleted'))
				->andWhere($q->expr()->isNull('c.gone_at'));
		}
		if (!empty($filters['hide_archived'])) {
			$q->andWhere($q->expr()->orX(
				$q->expr()->isNull('c.phone_archived'),
				$q->expr()->eq('c.phone_archived', $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
			));
		}
		$kind = $filters['kind'] ?? '';
		if (isset(self::KINDS[$kind])) {
			$q->andWhere($q->expr()->in('m.kind', $q->createNamedParameter(self::KINDS[$kind], IQueryBuilder::PARAM_STR_ARRAY)));
		} elseif ($kind === 'media') {
			$q->andWhere($q->expr()->isNotNull('md.id'));
		} elseif ($kind === 'link') {
			$q->andWhere($q->expr()->iLike('m.body', $q->createNamedParameter('%http%')));
		}
		return $q;
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	private function searchRow(array $r): array {
		$m = $this->messageRow($r);
		$m['chatId'] = (int)$r['chat_id'];
		$m['sourceId'] = (int)$r['source_id'];
		return $m;
	}

	/**
	 * @param int[] $sourceIds
	 * @return array<int,int> sourceId => message count, for the given sources
	 */
	public function messageCounts(array $sourceIds): array {
		if ($sourceIds === []) {
			return [];
		}
		$q = $this->db->getQueryBuilder();
		$q->selectAlias($q->createFunction('COUNT(*)'), 'n')->addSelect('source_id')
			->from('chatarchive_message')
			->where($q->expr()->in('source_id', $q->createNamedParameter($sourceIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('source_id');
		$out = [];
		foreach ($q->executeQuery()->fetchAll() as $r) {
			$out[(int)$r['source_id']] = (int)$r['n'];
		}
		return $out;
	}

	/**
	 * One page of messages for the FullTextSearch indexer, oldest id first.
	 * Each row also has the caption and file name of its media row; hasMedia
	 * is true when the message has a media row.
	 * @return array<int, array{id:int, chatId:int, chatName:string, sentAt:int, body:?string, caption:?string, fileName:?string, hasMedia:bool}>
	 * arch: D29.4
	 */
	public function messagesForIndex(int $sourceId, int $limit, int $offset): array {
		$q = $this->db->getQueryBuilder();
		$q->select('m.id', 'm.chat_id', 'm.sent_at', 'm.body', 'c.name', 'c.display_name', 'c.platform_chat_id')
			->selectAlias('md.id', 'media_id')->selectAlias('md.caption', 'caption')->selectAlias('md.file_name', 'file_name')
			->from('chatarchive_message', 'm')
			->join('m', 'chatarchive_chat', 'c', $q->expr()->eq('c.id', 'm.chat_id'))
			->leftJoin('m', 'chatarchive_media', 'md', $q->expr()->eq('md.message_id', 'm.id'))
			->where($q->expr()->eq('m.source_id', $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->orderBy('m.id', 'ASC')->setMaxResults($limit)->setFirstResult($offset);
		return array_map(static fn ($r) => [
			'id' => (int)$r['id'],
			'chatId' => (int)$r['chat_id'],
			'chatName' => (string)DisplayText::name($r['name'] ?: ($r['display_name'] ?: $r['platform_chat_id'])),
			'sentAt' => (int)$r['sent_at'],
			'body' => $r['body'],
		] + self::indexMedia($r), $q->executeQuery()->fetchAll());
	}

	/**
	 * One message for the FullTextSearch orphan-cleanup path, with the owning
	 * user and the media fields of messagesForIndex().
	 * @return array{id:int, chatId:int, chatName:string, sentAt:int, body:?string, sourceId:int, userId:string, caption:?string, fileName:?string, hasMedia:bool}|null
	 * arch: D29.4
	 */
	public function messageForIndex(int $messageId): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('m.id', 'm.chat_id', 'm.sent_at', 'm.body', 'm.source_id', 's.user_id',
			'c.name', 'c.display_name', 'c.platform_chat_id')
			->selectAlias('md.id', 'media_id')->selectAlias('md.caption', 'caption')->selectAlias('md.file_name', 'file_name')
			->from('chatarchive_message', 'm')
			->join('m', 'chatarchive_chat', 'c', $q->expr()->eq('c.id', 'm.chat_id'))
			->join('m', 'chatarchive_source', 's', $q->expr()->eq('s.id', 'm.source_id'))
			->leftJoin('m', 'chatarchive_media', 'md', $q->expr()->eq('md.message_id', 'm.id'))
			->where($q->expr()->eq('m.id', $q->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)));
		$r = $q->executeQuery()->fetch();
		if ($r === false) {
			return null;
		}
		return [
			'id' => (int)$r['id'],
			'chatId' => (int)$r['chat_id'],
			'chatName' => (string)DisplayText::name($r['name'] ?: ($r['display_name'] ?: $r['platform_chat_id'])),
			'sentAt' => (int)$r['sent_at'],
			'body' => $r['body'],
			'sourceId' => (int)$r['source_id'],
			'userId' => $r['user_id'],
		] + self::indexMedia($r);
	}

	/**
	 * The media fields of an index row.
	 * @param array<string, mixed> $r
	 * @return array{caption:?string, fileName:?string, hasMedia:bool}
	 */
	private static function indexMedia(array $r): array {
		return [
			'caption' => $r['caption'],
			'fileName' => DisplayText::name($r['file_name']),
			'hasMedia' => $r['media_id'] !== null,
		];
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, array<string, mixed>> keyed by message id
	 * arch: D2.2
	 */
	public function mediaForMessages(array $messageIds): array {
		if ($messageIds === []) {
			return [];
		}
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'message_id', 'mime', 'size', 'file_name', 'caption', 'src_path', 'file_id', 'suspicious')
			->from('chatarchive_media')
			->where($q->expr()->in('message_id', $q->createNamedParameter($messageIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$out = [];
		foreach ($q->executeQuery()->fetchAll() as $r) {
			$out[(int)$r['message_id']] = [
				'id' => (int)$r['id'],
				'mime' => $r['mime'],
				'size' => self::intOrNull($r['size']),
				'fileName' => DisplayText::name($r['file_name'] ?? ($r['src_path'] ? basename($r['src_path']) : null)),
				'caption' => $r['caption'],
				'srcPath' => DisplayText::name($r['src_path']),
				'fileId' => self::intOrNull($r['file_id']),
				'suspicious' => ((int)($r['suspicious'] ?? 0)) > 0,
			];
		}
		return $out;
	}

	/**
	 * The newest calls of a source, newest first. The client holds them all and
	 * does its own filtering, so the cap is high enough to be the whole history.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function calls(int $sourceId, int $limit = 5000): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('chatarchive_call')
			->where($q->expr()->eq('source_id', $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->orderBy('started_at', 'DESC')->setMaxResults($limit);
		return array_map(static fn ($r) => [
			'id' => (int)$r['id'], 'peer' => $r['peer'], 'startedAt' => (int)$r['started_at'],
			'fromMe' => (bool)$r['from_me'], 'video' => (bool)$r['video'],
			'durationS' => self::intOrNull($r['duration_s']), 'result' => $r['result'],
		], $q->executeQuery()->fetchAll());
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	private function messageRow(array $r): array {
		return [
			'id' => (int)$r['id'],
			'sentAt' => (int)$r['sent_at'],
			'fromMe' => (bool)$r['from_me'],
			'sender' => $r['sender'],
			'kind' => $r['kind'],
			'body' => $r['body'],
			'deleted' => $r['deleted'],
			'deletedAt' => self::intOrNull($r['deleted_at']),
			'editedAt' => self::intOrNull($r['edited_at']),
			'call' => $r['call_result'] === null ? null : [
				'video' => (bool)$r['call_video'],
				'result' => $r['call_result'],
				'durationS' => self::intOrNull($r['call_duration']),
			],
		];
	}
}
