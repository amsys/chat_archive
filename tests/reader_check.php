<?php
declare(strict_types=1);
// Decrypt the fixture and read it back through the provider-neutral reader.
// The reader takes an open PDO, not a path; the
// caller (WhatsAppProvider, or this check) owns the connection.
// The open() checks below call
// the real WhatsAppProvider::open(). tests/stubs/OcpFilesFile.php gives it
// the backup as a memory stream, so no file of the backup goes to disk. That
// stand-in lives in its own file, excluded from phpstan's scan, so it does
// not shadow the real OCP\Files\File from vendor/nextcloud/ocp.
namespace OCA\ChatArchive\Source\WhatsApp {
	// Test-only. MemDb.php calls random_bytes(16) unqualified with no `use
	// function` import, so PHP checks this namespace for the name before it
	// falls back to the global function. Armed only around the case below of
	// a backup with no message table, so this check learns MemDb's memdb name
	// in advance without touching MemDb.php; every other case keeps the real
	// generator.
	function random_bytes(int $length): string {
		if ($length === 16 && ($GLOBALS['ca_fixed_rand'] ?? null) !== null) {
			return $GLOBALS['ca_fixed_rand'];
		}
		return \random_bytes($length);
	}
}

namespace {
require __DIR__ . '/stubs/OcpFilesFile.php';
require __DIR__ . '/../lib/Service/ImportException.php';
require __DIR__ . '/../lib/Source/WhatsApp/Crypt15.php';
require __DIR__ . '/../lib/Source/IBackupReader.php';
require __DIR__ . '/../lib/Source/IProvider.php';
require __DIR__ . '/../lib/Source/WhatsApp/MsgstoreReader.php';
require __DIR__ . '/../lib/Source/WhatsApp/MemDb.php';
require __DIR__ . '/../lib/Source/WhatsApp/WhatsAppProvider.php';
use OCA\ChatArchive\Source\WhatsApp\Crypt15;
use OCA\ChatArchive\Source\WhatsApp\MemDb;
use OCA\ChatArchive\Source\WhatsApp\MsgstoreReader;
use OCA\ChatArchive\Source\WhatsApp\WhatsAppProvider;

/** A backup file that lives only in memory. */
function memFile(string $bytes): OCP\Files\File {
	return new class($bytes) implements OCP\Files\File {
		public function __construct(private string $bytes) {
		}
		/** @param string $mode */
		public function fopen($mode) {
			$h = fopen('php://memory', 'w+b');
			fwrite($h, $this->bytes);
			rewind($h);
			return $h;
		}
		/**
		 * @param bool $includeMounts
		 * @return int
		 */
		public function getSize($includeMounts = true) {
			return strlen($this->bytes);
		}
	};
}

$fx = __DIR__ . '/fixtures';
$db = sys_get_temp_dir() . '/ca_reader_' . getmypid() . '.db';
Crypt15::decryptFile("$fx/msgstore.db.crypt15", $db, trim(file_get_contents("$fx/key.hex")));
$r = new MsgstoreReader(new PDO('sqlite:' . $db));

// Read the PRAGMAs back on the reader's own connection, so the
// check fails if a PRAGMA is ever dropped, not only if its comment is.
$rdb = (new ReflectionProperty(MsgstoreReader::class, 'db'))->getValue($r);
assert((int)$rdb->query('PRAGMA trusted_schema')->fetchColumn() === 0, 'trusted_schema is OFF');
assert((int)$rdb->query('PRAGMA temp_store')->fetchColumn() === 2, 'temp_store is MEMORY');
assert((int)$rdb->query('PRAGMA query_only')->fetchColumn() === 1, 'query_only is set');
unset($rdb);

$chats = iterator_to_array($r->chats(), false);
assert(count($chats) === 2, 'two chats; the empty one is skipped');
assert($chats[0]['platform_chat_id'] === '23000001@s.whatsapp.net', 'lid chat canonicalised to phone jid');
assert($chats[0]['is_group'] === false && $chats[1]['is_group'] === true, 'group flag');
assert($chats[1]['name'] === 'Family group', 'group subject');
// The chat row carries the phone archive flag as a bool
assert($chats[0]['archived'] === false && $chats[1]['archived'] === true, 'archived flag per chat');
assert(array_keys($chats[0]) === ['platform_chat_id', 'name', 'is_group', 'archived'], 'chat row keys');

$m = iterator_to_array($r->messages('23000001@s.whatsapp.net'), false);
assert(count($m) === 5, 'five messages in the private chat');
assert($m[0]['body'] === 'hello from alice' && $m[0]['sender'] === '23000001@s.whatsapp.net', 'sender canonicalised');
assert($m[1]['from_me'] === true && $m[1]['sender'] === null, 'from me has no sender');
assert($m[2]['kind'] === 'image' && $m[2]['media']['src_path'] === 'Media/WhatsApp Images/IMG-20240830-WA0001.jpg', 'media row');
assert($m[2]['media']['caption'] === 'a photo', 'caption');
// WhatsApp's own flag reaches the media row raw (not a bool); K3 was built with suspicious_content=3.
assert($m[2]['media']['suspicious'] === 3, 'suspicious flag reaches the media row, got ' . var_export($m[2]['media']['suspicious'], true));
assert($m[0]['media'] === null, 'a text message with no media row has no media array');
assert($m[0]['sent_at'] === 1725000000000, 'ms timestamp');
assert($m[0]['call'] === null, 'text rows carry no call facts');
assert($m[3]['kind'] === 'call_log' && $m[3]['body'] === null, 'call rows have no English body');
assert($m[3]['call'] === ['video' => false, 'result' => 'connected', 'duration_s' => 65], 'call facts');
assert($m[4]['kind'] === 'missed_call' && $m[4]['call'] === ['video' => true, 'result' => 'missed', 'duration_s' => null], 'missed call facts');

$g = iterator_to_array($r->messages('23000002-1600000000@g.us'), false);
assert(count($g) === 3, 'null key_id row skipped');
assert($g[0]['kind'] === 'document' && $g[0]['media']['file_name'] === 'invoice.pdf', 'document');
assert($g[1]['kind'] === 'system', 'system kind');
// the reader passes hostile bytes through untouched; the store cleans them (see nc_integration.sh)
assert($g[2]['body'] === "caf\xc3\xa9 \0bad \xffbytes", 'raw body bytes');
assert($g[2]['media']['src_path'] === '../../outside/evil.jpg' && strlen($g[2]['media']['file_name']) === 300, 'raw media row');

$calls = iterator_to_array($r->calls(), false);
assert(count($calls) === 2, 'two calls');
assert($calls[0]['result'] === 'connected' && $calls[0]['duration_s'] === 65, 'call result map');
assert($calls[1]['peer'] === '23000002-1600000000@g.us' && $calls[1]['video'] === true, 'group call peer');

$r->close();
// Close() only drops the connection now; the file is the
// caller's, not the reader's, so it is still on disk.
assert(file_exists($db), 'close() does not touch the file it was given');
@unlink($db);

// The real WhatsAppProvider::open():
// the backup goes through MemDb into a reader with the safe PRAGMAs, and no
// file is made.
$key = trim(file_get_contents("$fx/key.hex"));
$backupBytes = file_get_contents("$fx/msgstore.db.crypt15");
$tmpBefore = glob(sys_get_temp_dir() . '/*') ?: [];
$pr = (new WhatsAppProvider())->open(memFile($backupBytes), $key);
assert($pr instanceof MsgstoreReader, 'open() gives a MsgstoreReader');
$prdb = (new ReflectionProperty(MsgstoreReader::class, 'db'))->getValue($pr);
assert($prdb->query('PRAGMA database_list')->fetch()['file'] === '', 'the reader database has no file (memdb)');
assert((int)$prdb->query('PRAGMA query_only')->fetchColumn() === 1, 'open() gives a query_only reader');
unset($prdb);
assert(count(iterator_to_array($pr->chats(), false)) === 2, 'open() reads the two chats');
$pr->close();
unset($pr);
assert((glob(sys_get_temp_dir() . '/*') ?: []) === $tmpBefore, 'open() makes no file');

// A throw inside open() after
// MemDb has loaded every page (here: a bad md5 trailer, which Crypt15 checks
// last) must free the memdb at once. The exception keeps its trace
// arguments, and they hold the MemDb through the $mem->write(...) sink. So
// the memdb stays alive while the exception lives, unless open() calls
// abort(). The check keeps the exception alive on purpose.
ini_set('zend.exception_ignore_args', '0');
$bad = $backupBytes;
$bad[strlen($bad) - 1] = chr(ord($bad[strlen($bad) - 1]) ^ 0xff);
$tmpBefore = glob(sys_get_temp_dir() . '/*') ?: [];
$thrown = null;
try {
	(new WhatsAppProvider())->open(memFile($bad), $key);
} catch (\RuntimeException $e) {
	$thrown = $e;
}
assert($thrown !== null && str_contains($thrown->getMessage(), 'md5 trailer'), 'a bad trailer throws, got ' . var_export($thrown?->getMessage(), true));
// The open() frame holds the key as $secret; #[\SensitiveParameter] must hide it.
assert(!str_contains(var_export($thrown->getTrace(), true), $key), 'the trace must not hold the key hex (WhatsAppProvider::open $secret)');
$heldMem = null;
foreach ($thrown->getTrace() as $frame) {
	foreach ($frame['args'] ?? [] as $arg) {
		if (!$arg instanceof \Closure) {
			continue;
		}
		// the sink holds the MemDb as $this ($mem->write(...)) or as a use() variable
		$fn = new ReflectionFunction($arg);
		foreach ([$fn->getClosureThis(), ...array_values($fn->getStaticVariables())] as $v) {
			if ($v instanceof MemDb) {
				$heldMem = $v;
			}
		}
	}
}
assert($heldMem !== null, 'the trace holds the MemDb, so the check below can see a leak');
$heldName = (new ReflectionProperty(MemDb::class, 'name'))->getValue($heldMem);
// A live loader still holds its write transaction: the probe then sees pages,
// or "database is locked". Both mean the memdb is alive.
$probe = new PDO('sqlite:file:' . $heldName . '?vfs=memdb', null, null, [PDO::ATTR_TIMEOUT => 1]);
try {
	$leftOpen = (int)$probe->query('PRAGMA page_count')->fetchColumn() . ' pages';
} catch (\PDOException $pe) {
	$leftOpen = $pe->getMessage();
}
unset($probe, $pe);
assert($leftOpen === '0 pages', "a throw in open() leaves no memdb alive, got: $leftOpen");
assert((glob(sys_get_temp_dir() . '/*') ?: []) === $tmpBefore, 'a throw in open() leaves no file');
unset($thrown, $heldMem, $e);

// The OTHER throw point inside open(),
// after MemDb has already handed the reader connection to open() (finish()
// returned, MemDb owns nothing to abort()). A backup that loads cleanly into
// memdb but has no `message` table makes `new MsgstoreReader($pdo)` throw
// inside its own constructor; the reader-construction guard must free that
// connection before the caller ever sees the throw. The memdb name is
// unpredictable (random per MemDb), and the guard deliberately makes the
// original exception -- the only thing that would reveal it -- unreachable
// from outside open(), so this check fixes random_bytes() for the span of
// the open() call below to learn the name in advance.
$fixedRand = str_repeat("\x11", 16);
$knownMemName = '/chat_archive-' . bin2hex($fixedRand);

// A minimal, validly-committed SQLite database with jid and chat but no
// message table: $mem->finish() succeeds; MsgstoreReader's constructor does not.
$plainTmp = sys_get_temp_dir() . '/ca_reader_noMsg_' . getmypid() . '.db';
@unlink($plainTmp);
$plainPdo = new PDO('sqlite:' . $plainTmp);
$plainPdo->exec('PRAGMA page_size=4096');
$plainPdo->exec('CREATE TABLE jid (_id INTEGER PRIMARY KEY, user TEXT, server TEXT, raw_string TEXT)');
$plainPdo->exec('CREATE TABLE chat (_id INTEGER PRIMARY KEY, jid_row_id INTEGER, subject TEXT)');
unset($plainPdo);
$plainNoMessage = file_get_contents($plainTmp);
@unlink($plainTmp);

// Wrap it in a minimal, valid crypt15 stream by hand: a 16-byte IV in the
// BackupPrefix.c15_iv protobuf field (field 3 -> field 1), AES-256-CTR body
// (no zlib: $plainNoMessage already starts with the SQLite magic, so
// detectStream() passes it through raw), a GCM tag decryptTo() never checks,
// and the md5 trailer it does check.
$ivNoMessage = str_repeat("\x22", 16);
$protoNoMessage = "\x1a\x12\x0a\x10" . $ivNoMessage; // field3{len 18}: field1{len 16} = iv
$headerNoMessage = chr(strlen($protoNoMessage)) . $protoNoMessage;
$aesKeyNoMessage = Crypt15::backupKey($key);
$firstCounter = new ReflectionMethod(Crypt15::class, 'firstCounter');
$firstCounter->setAccessible(true);
$ctr0NoMessage = $firstCounter->invoke(null, $aesKeyNoMessage, $ivNoMessage);
$cipherNoMessage = openssl_encrypt($plainNoMessage, 'aes-256-ctr', $aesKeyNoMessage, OPENSSL_RAW_DATA, $ctr0NoMessage);
$tagNoMessage = str_repeat("\0", 16);
$md5NoMessage = hash_init('md5');
hash_update($md5NoMessage, $headerNoMessage);
hash_update($md5NoMessage, $cipherNoMessage);
hash_update($md5NoMessage, $tagNoMessage);
$noMessageBackup = $headerNoMessage . $cipherNoMessage . $tagNoMessage . hash_final($md5NoMessage, true);

ini_set('zend.exception_ignore_args', '0');
$GLOBALS['ca_fixed_rand'] = $fixedRand;
$tmpBefore2 = glob(sys_get_temp_dir() . '/*') ?: [];
$thrown2 = null;
try {
	(new WhatsAppProvider())->open(memFile($noMessageBackup), $key);
} catch (\RuntimeException $e) {
	$thrown2 = $e;
} finally {
	$GLOBALS['ca_fixed_rand'] = null;
}
assert($thrown2 !== null, 'a backup with no message table throws');
assert(str_contains($thrown2->getMessage(), 'message'), 'the message names the missing table, got ' . var_export($thrown2->getMessage(), true));
assert($thrown2->getPrevious() === null, 'the reader-construction guard rethrows with no previous exception');
assert((glob(sys_get_temp_dir() . '/*') ?: []) === $tmpBefore2, 'a constructor throw in open() leaves no file');
// Probe before dropping $thrown2/$e: the check must not free the leak itself
// by unsetting the only reference before it looks -- that would pass against
// a mutant that skips the guard just as easily as against the fix.
$probe2 = new PDO('sqlite:file:' . $knownMemName . '?vfs=memdb', null, null, [PDO::ATTR_TIMEOUT => 1]);
try {
	$leftOpen2 = (int)$probe2->query('PRAGMA page_count')->fetchColumn() . ' pages';
} catch (\PDOException $pe2) {
	$leftOpen2 = $pe2->getMessage();
}
unset($probe2, $pe2);
assert($leftOpen2 === '0 pages', "a throw inside new MsgstoreReader(\$pdo) leaves no memdb alive, got: $leftOpen2");
unset($thrown2, $e);

// Old schema without jid_map and the call tables: read from a copy (the
// fixtures directory is mounted read-only in the check image).
$db_plain = sys_get_temp_dir() . '/ca_reader_plain_' . getmypid() . '.db';
copy("$fx/msgstore_old.db", $db_plain);
$r_plain = new MsgstoreReader(new PDO('sqlite:' . $db_plain));
$chats_plain = iterator_to_array($r_plain->chats(), false);
assert(count($chats_plain) === 2, 'no jid_map: two chats');
// No chat.archived column: the flag is null, the row shape stays the same
assert($chats_plain[0]['archived'] === null && $chats_plain[1]['archived'] === null, 'no chat.archived: archived is null');
assert(array_keys($chats_plain[0]) === array_keys($chats[0]), 'no chat.archived: same chat row shape');
assert($chats_plain[0]['platform_chat_id'] === '123456789@lid', 'no jid_map: the lid chat keeps its lid');
$m_plain = iterator_to_array($r_plain->messages('123456789@lid'), false);
assert(count($m_plain) === 5, 'no call tables: all five messages still read');
assert($m_plain[0]['body'] === 'hello from alice' && $m_plain[0]['sender'] === '123456789@lid', 'no jid_map: sender stays lid');
assert($m_plain[2]['media']['src_path'] === 'Media/WhatsApp Images/IMG-20240830-WA0001.jpg', 'no call tables: media still read');
// Msgstore_old.db has no suspicious_content column, so the flag stays null, not 0 or missing.
assert(array_key_exists('suspicious', $m_plain[2]['media']), 'no suspicious_content column: the media row still carries the key');
assert($m_plain[2]['media']['suspicious'] === null, 'no suspicious_content column: the flag reads null, got ' . var_export($m_plain[2]['media']['suspicious'], true));
assert($m_plain[3]['kind'] === 'call_log' && $m_plain[3]['call'] === null, 'no call tables: call facts are null');
assert($m_plain[4]['kind'] === 'missed_call' && $m_plain[4]['call'] === null, 'no missed_call_logs: missed call facts are null');
assert(array_keys($m_plain[0]) === array_keys($m[0]), 'no call tables: same row shape');
assert(iterator_to_array($r_plain->calls(), false) === [], 'no call_log: calls() yields nothing');
$r_plain->close();
@unlink($db_plain);

// The later backup (v2) as the reader sees it.
$db_v2 = sys_get_temp_dir() . '/ca_reader_v2_' . getmypid() . '.db';
Crypt15::decryptFile("$fx/msgstore_v2.db.crypt15", $db_v2, trim(file_get_contents("$fx/key.hex")));
$r_v2 = new MsgstoreReader(new PDO('sqlite:' . $db_v2));
$chats_v2 = iterator_to_array($r_v2->chats(), false);
assert(count($chats_v2) === 1 && $chats_v2[0]['platform_chat_id'] === '23000001@s.whatsapp.net', 'v2: the group chat is gone');
assert($chats_v2[0]['archived'] === true, 'v2: chat A is archived on the phone');
$m_v2 = iterator_to_array($r_v2->messages('23000001@s.whatsapp.net'), false);
$by_key = array_column($m_v2, null, 'platform_msg_id');
assert(array_keys($by_key) === ['K1', 'K2', 'K3', 'K7'], 'v2: K8 removed, got ' . implode(',', array_keys($by_key)));
assert($by_key['K1']['kind'] === 'text' && $by_key['K1']['body'] === 'hello from alice, edited', 'v2: K1 edited');
assert($by_key['K2']['kind'] === 'revoked' && $by_key['K2']['body'] === null, 'v2: K2 revoked');
// The flag survives into the later backup unchanged (make_fixture.py sets it on both builds).
assert($by_key['K3']['media']['suspicious'] === 3, 'v2: K3 still carries the flag, got ' . var_export($by_key['K3']['media']['suspicious'], true));
$r_v2->close();
@unlink($db_v2);

// ANY rule: a lid chat and its phone chat fold into one chat.
// Only the phone row (no messages) is archived. The folded chat reads archived, and keeps its key.
$db_fold = sys_get_temp_dir() . '/ca_reader_fold_' . getmypid() . '.db';
copy("$fx/msgstore.db", $db_fold);
$pdo_fold = new PDO('sqlite:' . $db_fold);
$pdo_fold->exec('UPDATE chat SET archived = 0');
$pdo_fold->exec('INSERT INTO chat (_id, jid_row_id, subject, archived) VALUES (4, 1, NULL, 1)');
unset($pdo_fold);
$r_fold = new MsgstoreReader(new PDO('sqlite:' . $db_fold));
$chats_fold = iterator_to_array($r_fold->chats(), false);
assert(count($chats_fold) === 2 && $chats_fold[0]['platform_chat_id'] === '23000001@s.whatsapp.net', 'fold: one chat per jid');
assert($chats_fold[0]['archived'] === true, 'fold: ANY folded row archived gives true');
assert($chats_fold[1]['archived'] === false, 'fold: the group chat is not archived');
$r_fold->close();
// The other order: only the first folded row (the lid chat) is archived.
copy("$fx/msgstore.db", $db_fold);
$pdo_fold = new PDO('sqlite:' . $db_fold);
$pdo_fold->exec('UPDATE chat SET archived = CASE _id WHEN 1 THEN 1 ELSE 0 END');
$pdo_fold->exec('INSERT INTO chat (_id, jid_row_id, subject, archived) VALUES (4, 1, NULL, 0)');
unset($pdo_fold);
$r_fold = new MsgstoreReader(new PDO('sqlite:' . $db_fold));
assert(iterator_to_array($r_fold->chats(), false)[0]['archived'] === true, 'fold: an archived first row gives true');
$r_fold->close();
@unlink($db_fold);

// No core table message: the reader fails, and the message names the table.
$db_core = sys_get_temp_dir() . '/ca_reader_core_' . getmypid() . '.db';
@unlink($db_core);
(new PDO('sqlite:' . $db_core))->exec('CREATE TABLE jid (_id INTEGER PRIMARY KEY, user TEXT, server TEXT, raw_string TEXT)');
$err = null;
try {
	iterator_to_array((new MsgstoreReader(new PDO('sqlite:' . $db_core)))->chats(), false);
} catch (\RuntimeException $e) {
	$err = $e->getMessage();
}
@unlink($db_core);
assert($err !== null && preg_match('/\bmessage\b/', $err) === 1, 'no message table: error names it, got ' . var_export($err, true));

echo "reader ok\n";
}
