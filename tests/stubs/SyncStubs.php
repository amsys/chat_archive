<?php
declare(strict_types=1);
// Test-only stand-ins for tests/sync_resume_check.php. They let the real
// SyncService and WahaMapper run against an in-memory archive, an in-memory
// folder and a fake WAHA client, with no Nextcloud code loaded.
//
// This file stays out of phpstan's scan (excludePaths in phpstan.neon, same
// as tests/stubs/OcpFilesFile.php). Its classes use the names of real OCP and
// app classes; in the scanned paths they would shadow the real ones.
//
// The file returns a function: fn(int $chats): ChatArchiveTest\SyncWorld.
// arch: D25.1

namespace OCP\Files {
	class NotFoundException extends \Exception {
	}
	class NotPermittedException extends \Exception {
	}
	interface Node {
	}
	interface Folder extends Node {
	}
	interface IRootFolder {
	}
}

namespace Psr\Log {
	interface LoggerInterface {
	}
}

namespace OCA\ChatArchive\Db {
	/** The source fields that SyncService reads and writes. */
	class Source {
		public ?string $syncMe = null;
		public ?int $syncAt = null;
		public ?string $syncError = null;
		public ?string $importState = null;

		public function getId(): int {
			return 1;
		}
		public function getUserId(): string {
			return 'alice';
		}
		public function getFolder(): string {
			return '/WhatsApp';
		}
		public function getSyncSession(): ?string {
			return 'default';
		}
		public function getSyncMe(): ?string {
			return $this->syncMe;
		}
		public function setSyncMe(?string $v): void {
			$this->syncMe = $v;
		}
		public function setSyncAt(?int $v): void {
			$this->syncAt = $v;
		}
		public function setSyncError(?string $v): void {
			$this->syncError = $v;
		}
		public function setImportState(?string $v): void {
			$this->importState = $v;
		}
		public function setImportSince(?int $v): void {
		}
	}

	class SourceMapper {
		public function claim(Source $s): bool {
			$s->setImportState('running');
			return true;
		}
		public function update(Source $s): Source {
			return $s;
		}
	}

	/** A connection with transactions only. */
	class StubDb {
		public function beginTransaction(): void {
		}
		public function commit(): void {
		}
		public function rollBack(): void {
		}
	}

	/** The archive in memory, with the store methods that SyncService calls. */
	class ArchiveStore {
		/** @var array<string, array{id:int, last_at:?int}> by jid */
		public array $chats = [];
		/** @var array<int, array{chat_id:int, row:array<string, mixed>}> by message id */
		public array $messages = [];
		/** @var array<int, array{message_id:int, mime:?string, file_name:?string, tries:int, file_id:?int, src_path:?string}> by media id */
		public array $media = [];

		public function db(): StubDb {
			return new StubDb();
		}

		/** @return array{id:int, last_at:?int} */
		public function syncChat(int $sid, string $jid, ?string $name, bool $isGroup): array {
			$this->chats[$jid] ??= ['id' => count($this->chats) + 1, 'last_at' => null];
			return $this->chats[$jid];
		}

		/** @return array<string, array{id:int}> */
		public function existingMessages(int $chatId): array {
			$out = [];
			foreach ($this->messages as $id => $m) {
				if ($m['chat_id'] === $chatId) {
					$out[(string)$m['row']['platform_msg_id']] = ['id' => $id];
				}
			}
			return $out;
		}

		/** @param array<string, mixed> $m */
		public function insertMessage(int $sid, int $chatId, array $m, bool $inBackup): int {
			$id = count($this->messages) + 1;
			$this->messages[$id] = ['chat_id' => $chatId, 'row' => $m];
			return $id;
		}

		/** @param array<string, mixed> $md */
		public function insertMedia(int $sid, int $messageId, array $md): void {
			$this->media[count($this->media) + 1] = ['message_id' => $messageId, 'mime' => $md['mime'],
				'file_name' => $md['file_name'], 'tries' => 0, 'file_id' => null, 'src_path' => null];
		}

		public function refreshChatStats(int $chatId): void {
			foreach ($this->chats as $jid => $c) {
				if ($c['id'] !== $chatId) {
					continue;
				}
				$at = [];
				foreach ($this->messages as $m) {
					if ($m['chat_id'] === $chatId) {
						$at[] = (int)$m['row']['sent_at'];
					}
				}
				$this->chats[$jid]['last_at'] = $at === [] ? null : max($at);
			}
		}

		/**
		 * Same filter and order as the real query: no file, fewer than 3 tries, fewest tries first, then newest first.
		 * @return list<array<string, mixed>>
		 */
		public function pendingSyncMedia(int $sid, int $limit): array {
			$jids = [];
			foreach ($this->chats as $jid => $c) {
				$jids[$c['id']] = $jid;
			}
			$out = [];
			foreach ($this->media as $id => $md) {
				if ($md['file_id'] !== null || $md['tries'] >= 3) {
					continue;
				}
				$m = $this->messages[$md['message_id']];
				$out[] = ['id' => $id, 'message_id' => $md['message_id'],
					'platform_msg_id' => (string)$m['row']['platform_msg_id'],
					'platform_chat_id' => $jids[$m['chat_id']],
					'from_me' => (bool)$m['row']['from_me'], 'mime' => $md['mime'], 'file_name' => $md['file_name'],
					'sent_at' => (int)$m['row']['sent_at'], 'tries' => $md['tries']];
			}
			usort($out, static fn (array $a, array $b) => [$a['tries'], $b['sent_at'], $b['id']] <=> [$b['tries'], $a['sent_at'], $a['id']]);
			$out = array_map(static fn (array $r) => array_diff_key($r, ['tries' => 0]), $out);
			return array_slice($out, 0, $limit);
		}

		public function bumpSyncTries(int $mediaId): void {
			$this->media[$mediaId]['tries']++;
		}

		public function setMediaStored(int $mediaId, int $fileId, string $srcPath, ?int $size): void {
			$this->media[$mediaId]['file_id'] = $fileId;
			$this->media[$mediaId]['src_path'] = $srcPath;
		}

		/** Number of chats that hold at least one message. */
		public function chatsWithMessages(): int {
			return count(array_unique(array_column($this->messages, 'chat_id')));
		}
	}
}

namespace OCA\ChatArchive\Waha {
	class WahaConfig {
		public int $mediaPerRun = 5;

		public function enabled(): bool {
			return true;
		}
		/** @return list<string> */
		public function sessionsOf(string $uid): array {
			return ['default'];
		}
		public function mediaPerRun(): int {
			return $this->mediaPerRun;
		}
	}

	/**
	 * Fake WAHA with $count chats, one message each. Chat $i has the time
	 * T0 - $i, so chat 1 is the newest. Chat 3 is a group whose message is
	 * an image from another member; chats 5 and 9 have an image too. The
	 * overview entry of the last chat has no lastMessage.
	 */
	class WahaClient {
		public const T0 = 1800000000;
		/** The messages() call of this chat waits $slowSeconds, once. */
		public int $slowChat = 0;
		public float $slowSeconds = 0.0;
		/** @var list<int> overview offsets asked */
		public array $overviewCalls = [];
		/** @var list<array{string, int}> chat id and gteSec of each messages() call */
		public array $messageCalls = [];
		/** @var list<array{string, string}> chat id and message id of each message() call */
		public array $mediaCalls = [];

		public function __construct(
			public int $count,
		) {
		}

		/** @return array{status:string, me:?string} */
		public function session(string $name): array {
			return ['status' => 'WORKING', 'me' => '23099999@c.us'];
		}

		private function chatId(int $i): string {
			return $i === 3 ? '3@g.us' : $i . '@c.us';
		}

		/** @return array<string, mixed> */
		private function raw(int $i): array {
			$chat = $this->chatId($i);
			$m = ['id' => 'false_' . $chat . '_K' . $i, 'timestamp' => self::T0 - $i, 'from' => $chat,
				'fromMe' => false, 'body' => 'm' . $i, 'hasMedia' => false];
			if ($i === 3) {
				$m['id'] .= '_555@c.us';
				$m['participant'] = '555@c.us';
			}
			if (in_array($i, [3, 5, 9], true)) {
				$m['hasMedia'] = true;
				$m['media'] = ['mimetype' => 'image/jpeg', 'url' => 'http://waha:3000/api/files/default/K' . $i . '.jpeg'];
			}
			return $m;
		}

		/** @return list<array<string, mixed>> */
		public function chatsOverview(string $session, int $limit, int $offset): array {
			$this->overviewCalls[] = $offset;
			$out = [];
			for ($i = $offset + 1; $i <= min($this->count, $offset + $limit); $i++) {
				$entry = ['id' => $this->chatId($i), 'name' => null];
				if ($i !== $this->count) {
					$entry['lastMessage'] = $this->raw($i);
				}
				$out[] = $entry;
			}
			return $out;
		}

		/** @return list<array<string, mixed>> */
		public function messages(string $session, string $chatId, int $gteSec, int $limit, int $offset): array {
			$this->messageCalls[] = [$chatId, $gteSec];
			$i = (int)$chatId;
			if ($i === $this->slowChat) {
				$this->slowChat = 0;
				usleep((int)($this->slowSeconds * 1000000));
			}
			$m = $this->raw($i);
			return $offset === 0 && $m['timestamp'] >= $gteSec ? [$m] : [];
		}

		/** @return array<string, mixed> */
		public function message(string $session, string $chatId, string $keyId): array {
			$this->mediaCalls[] = [$chatId, $keyId];
			$i = (int)substr($keyId, 1);
			return $keyId === 'K' . $i && $chatId === $this->chatId($i) ? $this->raw($i) : [];
		}

		/** @return resource */
		public function download(string $url) {
			$h = fopen('php://memory', 'r+');
			assert($h !== false);
			fwrite($h, 'jpeg bytes of ' . $url);
			rewind($h);
			return $h;
		}

		public function lid(string $session, string $lid): ?string {
			return null;
		}
	}
}

namespace ChatArchiveTest {
	use OCA\ChatArchive\Db\ArchiveStore;
	use OCA\ChatArchive\Db\Source;
	use OCA\ChatArchive\Db\SourceMapper;
	use OCA\ChatArchive\Service\SyncService;
	use OCA\ChatArchive\Waha\WahaClient;
	use OCA\ChatArchive\Waha\WahaConfig;
	use OCP\Files\Folder;
	use OCP\Files\IRootFolder;
	use OCP\Files\NotFoundException;
	use OCP\Files\NotPermittedException;
	use Psr\Log\LoggerInterface;

	require_once __DIR__ . '/../../lib/Service/ImportRunningException.php';
	require_once __DIR__ . '/../../lib/Waha/WahaException.php';
	require_once __DIR__ . '/../../lib/Waha/WahaMapper.php';
	require_once __DIR__ . '/../../lib/Service/SyncService.php';

	/** The real SyncService with a time box of 2 seconds; the message pass gets 1.5 seconds of it. */
	class FastSync extends SyncService {
		protected const BOX = 2;
	}

	class StubFile {
		public function __construct(
			private int $id,
			private int $size,
		) {
		}
		public function getId(): int {
			return $this->id;
		}
		public function getSize(): int {
			return $this->size;
		}
	}

	/** A folder tree in memory. A file name that starts with $failPrefix cannot be written. */
	class StubFolder implements Folder {
		/** @var array<string, StubFolder|StubFile> */
		public array $children = [];
		public static int $nextId = 100;
		public static string $failPrefix = '';

		public function get(string $name): StubFolder|StubFile {
			return $this->children[$name] ?? throw new NotFoundException($name);
		}
		public function newFolder(string $name): StubFolder {
			return $this->children[$name] = new StubFolder();
		}
		public function getNonExistingName(string $name): string {
			return $name;
		}
		/** @param resource $stream */
		public function newFile(string $name, $stream): StubFile {
			if (self::$failPrefix !== '' && str_starts_with($name, self::$failPrefix)) {
				throw new NotPermittedException('Could not create path');
			}
			$size = strlen((string)stream_get_contents($stream));
			fclose($stream);
			return $this->children[$name] = new StubFile(self::$nextId++, $size);
		}
	}

	class StubRoot implements IRootFolder {
		public function __construct(
			private StubFolder $folder,
		) {
		}
		public function getUserFolder(string $uid): StubRoot {
			return $this;
		}
		public function get(string $path): StubFolder {
			return $this->folder;
		}
	}

	class StubLog implements LoggerInterface {
		/** @var list<array{string, array<string, mixed>}> */
		public array $warnings = [];

		/** @param array<string, mixed> $context */
		public function warning(string $message, array $context = []): void {
			$this->warnings[] = [$message, $context];
		}
	}

	/** One source, its store, its folder, the fake WAHA and the service. */
	class SyncWorld {
		public ArchiveStore $store;
		public WahaClient $client;
		public WahaConfig $config;
		public StubFolder $folder;
		public StubLog $log;
		public Source $source;
		public SyncService $sync;

		public function __construct(int $chats) {
			$this->store = new ArchiveStore();
			$this->client = new WahaClient($chats);
			$this->config = new WahaConfig();
			$this->folder = new StubFolder();
			$this->log = new StubLog();
			$this->source = new Source();
			$this->sync = new FastSync(new SourceMapper(), $this->store, $this->config, $this->client,
				new StubRoot($this->folder), $this->log);
		}

		/**
		 * One run, and a snapshot of the state after it. The call lists of
		 * the fake WAHA hold this run only.
		 * @return array<string, mixed>
		 */
		public function run(): array {
			$this->client->overviewCalls = [];
			$this->client->messageCalls = [];
			$this->client->mediaCalls = [];
			$stats = $this->sync->run($this->source);
			$media = [];
			foreach ($this->store->media as $md) {
				$media[$this->store->messages[$md['message_id']]['row']['platform_msg_id']] = $md;
			}
			return [
				'stats' => $stats,
				'chats_with_messages' => $this->store->chatsWithMessages(),
				'messages' => count($this->store->messages),
				'overview' => $this->client->overviewCalls,
				'asked' => $this->client->messageCalls,
				'media_asked' => $this->client->mediaCalls,
				'media' => $media,
				'error' => $this->source->syncError,
				'state' => $this->source->importState,
				'warnings' => $this->log->warnings,
			];
		}
	}
}

namespace {
	return static fn (int $chats): ChatArchiveTest\SyncWorld => new ChatArchiveTest\SyncWorld($chats);
}
