<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Source\WhatsApp;

use OCA\ChatArchive\Source\IBackupReader;
use PDO;

/** Reads a decrypted msgstore.db. Queries verified against a real 2026 backup. */
class MsgstoreReader implements IBackupReader {
	// message_type -> kind
	private const KIND = [
		0 => 'text', 1 => 'image', 2 => 'audio', 3 => 'video', 4 => 'vcard', 5 => 'location', 7 => 'system',
		9 => 'document', 10 => 'missed_call', 11 => 'unsupported', 13 => 'gif', 14 => 'vcard_array',
		15 => 'revoked', 16 => 'live_location', 20 => 'sticker', 24 => 'group_invite', 25 => 'template_image',
		27 => 'template_text', 28 => 'template_video', 32 => 'button_reply', 36 => 'ephemeral_setting_change',
		42 => 'view_once_image', 43 => 'view_once_video', 49 => 'bot_response', 57 => 'interactive',
		62 => 'interactive', 64 => 'revoked', 66 => 'poll', 81 => 'video_note', 87 => 'unknown',
		90 => 'call_log', 99 => 'album',
	];
	private const CALL_RESULT = [5 => 'connected', 2 => 'missed', 4 => 'rejected', 3 => 'failed'];

	// Tables and columns the reader selects. A backup without one of them cannot import.
	private const CORE = [
		'jid' => ['_id', 'user', 'server', 'raw_string'],
		'chat' => ['_id', 'jid_row_id', 'subject'],
		'message' => ['_id', 'chat_row_id', 'key_id', 'timestamp', 'from_me', 'message_type', 'text_data', 'sender_jid_row_id'],
	];
	// Old backups can lack these. A feature runs only when all its tables and columns are present.
	private const OPTIONAL = [
		'lid_map' => ['jid_map' => ['lid_row_id', 'jid_row_id']],
		'media' => ['message_media' => ['message_row_id', 'file_hash', 'mime_type', 'file_size', 'media_name', 'media_caption', 'file_path']],
		// own feature (not folded into 'media'), so an old backup without this column still gets its media rows. arch: D2.1
		'suspicious' => ['message_media' => ['suspicious_content']],
		'call_facts' => ['message_call_log' => ['message_row_id', 'call_log_row_id'], 'call_log' => ['_id', 'video_call', 'duration', 'call_result']],
		'missed_facts' => ['missed_call_logs' => ['message_row_id', 'video_call']],
		'calls' => ['call_log' => ['call_id', 'timestamp', 'from_me', 'video_call', 'duration', 'call_result', 'jid_row_id', 'group_jid_row_id']],
		'archived' => ['chat' => ['archived']],
	];

	/** @var array<string, bool> feature name -> all its tables and columns are present */
	private array $has = [];
	/** @var array<string, string> lid jid -> phone jid */
	private array $lidToPhone = [];
	/** @var array<string, int[]> canonical jid -> sqlite chat _ids (a lid chat and its phone chat share one) */
	private array $chatRows = [];
	/** @var array<string, bool> canonical jid -> one of its sqlite chat rows is archived on the phone */
	private array $phoneArchived = [];

	/** arch: D1.4, D6.2 -- the caller (WhatsAppProvider) opens the connection; this reader only sets it safe. */
	public function __construct(private PDO $db) {
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		// Order matters: trusted_schema first (query_only alone does not stop a
		// schema-trigger side effect), then temp_store so a big ORDER BY spills
		// to memory and not to a file, then query_only to block every write.
		$this->db->exec('PRAGMA trusted_schema = OFF');
		$this->db->exec('PRAGMA temp_store = MEMORY');
		$this->db->exec('PRAGMA query_only = 1');
		$missing = [];
		foreach (self::CORE as $table => $cols) {
			$missing[] = $this->missing($table, $cols);
		}
		$missing = array_filter($missing);
		if ($missing !== []) {
			throw new \RuntimeException('unsupported WhatsApp backup: the database has no ' . implode(', no ', $missing));
		}
		foreach (self::OPTIONAL as $feature => $tables) {
			$this->has[$feature] = true;
			foreach ($tables as $table => $cols) {
				if ($this->missing($table, $cols) !== null) {
					$this->has[$feature] = false;
				}
			}
		}
		if ($this->has['lid_map']) {
			foreach ($this->db->query('SELECT l.raw_string AS lid, p.raw_string AS phone FROM jid_map m '
				. 'JOIN jid l ON l._id = m.lid_row_id JOIN jid p ON p._id = m.jid_row_id') as $r) {
				$this->lidToPhone[$r['lid']] = $r['phone'];
			}
		}
		$archived = $this->has['archived'] ? ', c.archived' : '';
		foreach ($this->db->query("SELECT c._id, j.raw_string AS jid$archived FROM chat c JOIN jid j ON j._id = c.jid_row_id") as $c) {
			$jid = (string)$this->canon($c['jid']);
			$this->chatRows[$jid][] = (int)$c['_id'];
			if ($this->has['archived']) {
				// It is not known which folded row WhatsApp flags. ANY row set gives true, so no archive is missed.
				$this->phoneArchived[$jid] = ($this->phoneArchived[$jid] ?? false) || (int)$c['archived'] !== 0;
			}
		}
	}

	/**
	 * PRAGMA table_info gives no rows for an absent table.
	 * @param string[] $cols
	 * @return string|null "table <t>" or "column <t>.<c>" for the first absent part, null if all are present
	 */
	private function missing(string $table, array $cols): ?string {
		$present = [];
		foreach ($this->db->query('PRAGMA table_info(' . $this->db->quote($table) . ')') as $col) {
			$present[strtolower((string)$col['name'])] = true;
		}
		if ($present === []) {
			return "table $table";
		}
		foreach ($cols as $c) {
			if (!isset($present[$c])) {
				return "column $table.$c";
			}
		}
		return null;
	}

	/** WhatsApp's newer anonymous @lid ids map back to the phone jid, so one person stays one chat. */
	private function canon(?string $jid): ?string {
		return $jid === null ? null : ($this->lidToPhone[$jid] ?? $jid);
	}

	/** @return iterable<array<string, mixed>> */
	public function chats(): iterable {
		// hidden, left or never-used chats have no messages and often no subject; WhatsApp does not list them either
		$q = $this->db->query('SELECT c._id, j.raw_string AS jid, j.user, j.server, c.subject '
			. 'FROM chat c JOIN jid j ON j._id = c.jid_row_id '
			. 'WHERE EXISTS (SELECT 1 FROM message m WHERE m.chat_row_id = c._id AND m.key_id IS NOT NULL) ORDER BY c._id');
		$seen = [];
		foreach ($q as $c) {
			$jid = $this->canon($c['jid']);
			if ($jid === null || isset($seen[$jid])) {
				continue; // a lid chat and its phone chat collapse into one
			}
			$seen[$jid] = true;
			yield [
				'platform_chat_id' => $jid,
				'name' => $c['subject'] ?: $c['user'],
				'is_group' => $c['server'] === 'g.us',
				'archived' => $this->has['archived'] ? ($this->phoneArchived[$jid] ?? false) : null,
			];
		}
	}

	/** @return iterable<array<string, mixed>> */
	public function messages(string $platformChatId): iterable {
		$ids = $this->chatRows[$platformChatId] ?? [];
		if ($ids === []) {
			return;
		}
		$in = implode(',', $ids);
		$hasMedia = $this->has['media'];
		$hasSuspicious = $hasMedia && $this->has['suspicious'];
		$hasCallLog = $this->has['call_facts'];
		$hasMissedCalls = $this->has['missed_facts'];
		// Leave out the join of each absent feature. Its columns then read as null.
		$select = 'm._id, m.key_id, m.timestamp, m.from_me, m.message_type, m.text_data, s.raw_string AS sender';
		$from = 'message m LEFT JOIN jid s ON s._id = m.sender_jid_row_id';

		if ($hasMedia) {
			$select .= ', md.file_hash, md.mime_type, md.file_size, md.media_name, md.media_caption, md.file_path';
			$from .= ' LEFT JOIN message_media md ON md.message_row_id = m._id';
		}
		if ($hasSuspicious) {
			$select .= ', md.suspicious_content';
		}
		if ($hasCallLog) {
			$select .= ', cl.video_call, cl.duration, cl.call_result';
			$from .= ' LEFT JOIN message_call_log mcl ON mcl.message_row_id = m._id'
				. ' LEFT JOIN call_log cl ON cl._id = mcl.call_log_row_id';
		}
		if ($hasMissedCalls) {
			$select .= ', mc.video_call AS missed_video';
			$from .= ' LEFT JOIN missed_call_logs mc ON mc.message_row_id = m._id';
		}

		$st = $this->db->query("SELECT $select FROM $from WHERE m.chat_row_id IN ($in) AND m.key_id IS NOT NULL ORDER BY m.timestamp, m._id");
		foreach ($st as $m) {
			$fromMe = (bool)$m['from_me'];
			$media = null;
			if ($hasMedia && ($m['file_path'] !== null || $m['file_hash'] !== null)) {
				$media = [
					'file_hash' => $m['file_hash'],
					'mime' => $m['mime_type'],
					'size' => $m['file_size'] === null ? null : (int)$m['file_size'],
					'file_name' => $m['media_name'],
					'caption' => $m['media_caption'],
					'src_path' => $m['file_path'],
					'suspicious' => self::suspiciousValue($hasSuspicious, $m['suspicious_content'] ?? null),
				];
			}
			$kind = self::KIND[(int)$m['message_type']] ?? ('type_' . (int)$m['message_type']);
			yield [
				'platform_msg_id' => (string)$m['key_id'],
				'sent_at' => (int)$m['timestamp'],
				'from_me' => $fromMe,
				'sender' => $fromMe ? null : $this->canon($m['sender']),
				'kind' => $kind,
				'body' => $m['text_data'],
				'media' => $media,
				'call' => self::callFacts($kind, $m, $hasCallLog, $hasMissedCalls),
			];
		}
	}

	/**
	 * WhatsApp's own verdict, raw. Null when the column is absent or the row
	 * carries no value. A function of its own keeps messages() under the
	 * cognitive complexity limit. arch: D2.1
	 */
	private static function suspiciousValue(bool $hasSuspicious, int|string|null $raw): ?int {
		if (!$hasSuspicious || $raw === null) {
			return null;
		}
		return (int)$raw;
	}

	/**
	 * Call facts for call rows; the UI words them. Old-style missed calls carry only the video flag.
	 * @param array<string, mixed> $m
	 * @return array{video: bool, result: string, duration_s: ?int}|null
	 */
	private static function callFacts(string $kind, array $m, bool $hasCallLog, bool $hasMissedCalls): ?array {
		if ($kind === 'missed_call') {
			return $hasMissedCalls ? ['video' => (bool)$m['missed_video'], 'result' => 'missed', 'duration_s' => null] : null;
		}
		if ($kind !== 'call_log' || !$hasCallLog || $m['call_result'] === null) {
			return null;
		}
		return [
			'video' => (bool)$m['video_call'],
			'result' => self::CALL_RESULT[(int)$m['call_result']] ?? (string)$m['call_result'],
			'duration_s' => $m['duration'] === null ? null : (int)$m['duration'],
		];
	}

	/** @return iterable<array<string, mixed>> */
	public function calls(): iterable {
		if (!$this->has['calls']) {
			return;
		}
		$q = $this->db->query('SELECT c.call_id, c.timestamp, c.from_me, c.video_call, c.duration, c.call_result, '
			. 'j.raw_string AS jid, g.raw_string AS gjid FROM call_log c '
			. 'LEFT JOIN jid j ON j._id = c.jid_row_id LEFT JOIN jid g ON g._id = c.group_jid_row_id');
		foreach ($q as $c) {
			if ($c['call_id'] === null) {
				continue;
			}
			yield [
				'platform_call_id' => (string)$c['call_id'],
				'peer' => $this->canon($c['gjid'] ?? $c['jid']),
				'started_at' => (int)$c['timestamp'],
				'from_me' => (bool)$c['from_me'],
				'video' => (bool)$c['video_call'],
				'duration_s' => $c['duration'] === null ? null : (int)$c['duration'],
				'result' => self::CALL_RESULT[(int)$c['call_result']] ?? (string)$c['call_result'],
			];
		}
	}

	public function close(): void {
		unset($this->db);
	}
}
