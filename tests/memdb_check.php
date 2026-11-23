<?php
/**
 * Checks for MemDb, the in-memory page loader of the WhatsApp import.
 * Needs pdo_sqlite with the memdb VFS and sqlite_dbpage; tests/run.sh runs it in a container.
 * arch: D1.1, D1.2, D7.2, D7.4
 */
declare(strict_types=1);
require __DIR__ . '/../lib/Source/WhatsApp/MemDb.php';
require __DIR__ . '/../lib/Source/WhatsApp/Crypt15.php';
use OCA\ChatArchive\Source\WhatsApp\Crypt15;
use OCA\ChatArchive\Source\WhatsApp\MemDb;

$fx = __DIR__ . '/fixtures';

/** A generous $maxPlainBytes for cases that are not about that cap. */
const GENEROUS = MemDb::MAX_BYTES * 2;

/** Feeds $bytes to a new MemDb in parts of $step bytes. */
function load(string $bytes, int $step, int $maxPlainBytes = GENEROUS): PDO {
	$mem = new MemDb($maxPlainBytes);
	foreach (str_split($bytes, $step) as $part) {
		$mem->write($part);
	}
	return $mem->finish();
}

/** All pages of the database behind $db, in order. */
function pages(PDO $db): string {
	$q = $db->query('SELECT data FROM sqlite_dbpage ORDER BY pgno');
	assert($q !== false);
	return implode('', $q->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The bytes that MemDb must hold for input $bytes: bytes 18/19 set to 1, and
 * the header fields that SQLite writes on COMMIT (24-27, 92-99) taken from
 * $got. The check also asserts that those fields keep the header valid.
 */
function patched(string $bytes, string $got): string {
	assert(substr($got, 24, 4) === substr($got, 92, 4), 'the loaded header page count stays valid');
	$bytes[18] = "\x01";
	$bytes[19] = "\x01";
	$bytes = substr_replace($bytes, substr($got, 24, 4), 24, 4);
	return substr_replace($bytes, substr($got, 92, 8), 92, 8);
}

/** Asserts that the database behind $db holds the input $src, as patched() states. */
function same(PDO $db, string $src, string $what): void {
	$got = pages($db);
	assert($got === patched($src, $got), $what);
}

/** Builds a database file with page size $ps and about $kib KiB of rows; returns its bytes. */
function build(int $ps, int $kib, string $tag): string {
	$path = sys_get_temp_dir() . "/ca_memdb_{$tag}_" . getmypid() . '.db';
	@unlink($path);
	$db = new PDO('sqlite:' . $path);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec("PRAGMA page_size=$ps");
	$db->exec('CREATE TABLE t(id INTEGER PRIMARY KEY, tag TEXT, v BLOB)');
	$db->exec('CREATE INDEX t_tag ON t(tag)');
	$ins = $db->prepare('INSERT INTO t(tag, v) VALUES (?, ?)');
	for ($i = 0; $i < $kib; $i++) {
		$ins->execute([$tag . $i, random_bytes(1024)]);
	}
	unset($ins, $db);
	$bytes = (string)file_get_contents($path);
	unlink($path);
	return $bytes;
}

/**
 * The result changes when the database changes.
 * @phpstan-impure
 * @return list<string> the names of the user tables
 */
function tables(PDO $db): array {
	$q = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
	assert($q !== false);
	return $q->fetchAll(PDO::FETCH_COLUMN);
}

/** Runs $f and returns the message of the RuntimeException it throws, or null. */
function throws(callable $f): ?string {
	try {
		$f();
	} catch (RuntimeException $e) {
		return $e->getMessage();
	}
	return null;
}

$plain = (string)file_get_contents("$fx/msgstore.db");

// the fixture loads, in odd part sizes that cut the header and the pages
foreach ([37, 4095, 5000, strlen($plain)] as $step) {
	$db = load($plain, $step);
	same($db, $plain, "fixture loads with parts of $step bytes");
	assert(tables($db) !== [], 'the loaded database has tables');
}

// a WAL header (bytes 18/19 = 2) loads; the reader sees the data, and page 1 has 1/1
$wal = $plain;
$wal[18] = "\x02";
$wal[19] = "\x02";
$db = load($wal, 1000);
same($db, $plain, 'a WAL input loads with bytes 18/19 set to 1');
assert(tables($db) === tables(load($plain, 1000)), 'a WAL input shows the same tables');

// the smallest and the largest page size, and a grow loop over many pages
foreach ([[512, 600], [65536, 300], [4096, 2000]] as [$ps, $kib]) {
	$src = build($ps, $kib, "p$ps");
	$db = load($src, 70000);
	same($db, $src, "page size $ps loads byte for byte");
	$q = $db->query('SELECT count(*) FROM t');
	assert($q !== false && (int)$q->fetchColumn() === $kib, "page size $ps: all rows read back");
}

// a header with N x page size above MAX_BYTES throws on the header, before any growth
$big = substr($plain, 0, 100);
$big[16] = "\x00";
$big[17] = "\x01"; // page size 65536
$big = substr_replace($big, pack('N', 16385), 28, 4); // 16385 x 64 KiB = 1024 MiB + 64 KiB
$mem = new MemDb(GENEROUS);
$msg = throws(fn () => $mem->write($big));
assert($msg === 'decrypted backup is 1025 MiB; the in-memory import supports up to 1024 MiB', "size cap message: $msg");
$mem->abort();

// finding 4: a forged header (N x ps = 900 MiB) with a small encryptedSize
// (1000 bytes, x 1032 = the zlib-ratio cap WhatsAppProvider::open() passes)
// must throw on the header, before the loader connection is even opened.
$loaderProp = new ReflectionProperty(MemDb::class, 'loader');
$forged = substr($plain, 0, 100);
$forged[16] = "\x00";
$forged[17] = "\x01"; // page size 65536
$forged = substr_replace($forged, pack('N', 14400), 28, 4); // 14400 x 64 KiB = 900 MiB
$memForged = new MemDb(1000 * 1032); // encryptedSize 1000
$msgForged = throws(fn () => $memForged->write($forged));
assert($msgForged === 'decrypted backup is 900 MiB; the encrypted source allows at most 1 MiB', "encrypted-size cap message: $msgForged");
assert($loaderProp->getValue($memForged) === null, 'finding 4: the loader connection was never opened');
$memForged->abort();

// a header count that is not valid (bytes 24-27 differ from 92-95) is refused
$old = $plain;
$old[95] = chr(ord($old[95]) ^ 1);
$msg = throws(fn () => load($old, 4096));
assert($msg !== null && str_starts_with($msg, 'unsupported WhatsApp backup'), "old header count: $msg");

// data that is not SQLite is refused
$msg = throws(fn () => load(str_repeat('x', 200), 200));
assert($msg !== null && str_starts_with($msg, 'unsupported WhatsApp backup'), "not SQLite: $msg");

// a truncated stream throws on finish(): a partial page, a missing page, a short header
foreach ([strlen($plain) - 100, strlen($plain) - 4096, 50] as $len) {
	$msg = throws(fn () => load(substr($plain, 0, $len), 1000));
	assert($msg === 'truncated backup', "truncated to $len bytes: $msg");
}

// more pages than the header states throws
$msg = throws(fn () => load($plain . str_repeat("\0", 4096), 4096));
assert($msg !== null && str_contains($msg, 'more data than the header'), "extra page: $msg");

// two MemDb objects loaded at the same time stay apart (I1.3)
$other = build(1024, 50, 'other');
$a = new MemDb(GENEROUS);
$b = new MemDb(GENEROUS);
$pa = str_split($plain, 3000);
$pb = str_split($other, 3000);
for ($i = 0; $i < max(count($pa), count($pb)); $i++) {
	if (isset($pa[$i])) {
		$a->write($pa[$i]);
	}
	if (isset($pb[$i])) {
		$b->write($pb[$i]);
	}
}
$dbA = $a->finish();
$dbB = $b->finish();
same($dbA, $plain, 'first MemDb keeps its own data');
same($dbB, $other, 'second MemDb keeps its own data');

// after abort() or after the reader is dropped, no connection keeps the memdb (I1.4)
$name = new ReflectionProperty(MemDb::class, 'name');
$reopen = static fn (MemDb $m): PDO => new PDO('sqlite:file:' . $name->getValue($m) . '?vfs=memdb');
$m = new MemDb(GENEROUS);
$m->write(substr($plain, 0, 9000));
$m->abort();
$m->abort(); // a second abort() is safe
assert(tables($reopen($m)) === [], 'abort() frees the memdb');
$m = new MemDb(GENEROUS);
$m->write($plain);
$db = $m->finish();
assert(tables($reopen($m)) !== [], 'the reader keeps the memdb alive');
unset($db);
assert(tables($reopen($m)) === [], 'dropping the reader frees the memdb');
$m->abort(); // abort() after finish() is safe
// a throw inside write() drops the loader at once
$m = new MemDb(GENEROUS);
throws(fn () => $m->write($plain . str_repeat("\0", 4096)));
assert(tables($reopen($m)) === [], 'a throw in write() frees the memdb');

// A mid-load throw must leave no decrypted page and no key hex in the trace
// (finding 1, I1.1): build a synthetic crypt15 stream, by hand, whose
// plaintext overflows its own header page count, and run it through the
// real Crypt15::decryptTo -> MemDb::write chain so every frame on the stack
// at throw time carries a #[\SensitiveParameter] argument.
$overflow = $plain . str_repeat("\0", 4096);
$keyHex = bin2hex(random_bytes(32));
$key = Crypt15::backupKey($keyHex);
$iv = random_bytes(16);
$firstCounter = new ReflectionMethod(Crypt15::class, 'firstCounter');
$firstCounter->setAccessible(true);
$counter = $firstCounter->invoke(null, $key, $iv);
$z = deflate_init(15); // ZLIB_ENCODING_DEFLATE: the zlib-wrapped format Crypt15::detectStream() expects
$compressed = (string)deflate_add($z, $overflow, ZLIB_FINISH);
$cipher = openssl_encrypt($compressed, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $counter);
assert($cipher !== false, 'synthetic ciphertext must encrypt');
// BackupPrefix { 3: C15_IV { 1: iv } }, the minimal protobuf Crypt15::ivFromPrefix() reads
$sub = "\x0A" . chr(strlen($iv)) . $iv;
$proto = "\x1A" . chr(strlen($sub)) . $sub;
$header = chr(strlen($proto)) . "\x01" . $proto;
$tag = random_bytes(16); // the GCM tag is not verified (Crypt15's own doc comment)
$trailer = md5($header . $cipher . $tag, true);
$stream = $header . $cipher . $tag . $trailer;

$mem = new MemDb(GENEROUS);
$in = fopen('php://memory', 'w+b');
assert($in !== false);
fwrite($in, $stream);
rewind($in);
$e = null;
try {
	Crypt15::decryptTo($in, $mem->write(...), $keyHex, strlen($stream));
} catch (\Throwable $caught) {
	$e = $caught;
}
fclose($in);
$mem->abort();
assert($e !== null && str_contains($e->getMessage(), 'more data than the header'), 'the synthetic overflow must throw mid-load: ' . ($e?->getMessage() ?? 'no throw'));
$dump = var_export($e->getTrace(), true);
assert(!str_contains($dump, $keyHex), 'the trace must not hold the key hex');
// offset 2460 sits inside the fixture's CREATE TABLE schema text: 64 bytes,
// none of them 0x00 (an all-zero slice would pass by accident), and none of
// them a quote or backslash (var_export() escapes those, so the needle
// would not match its own escaped copy in the dump).
$plainSlice = substr($plain, 2460, 64);
assert(strpbrk($plainSlice, "\0'\\") === false, 'the checked slice must hold no byte that var_export() escapes or hides');
assert(!str_contains($dump, $plainSlice), 'the trace must not hold a slice of the decrypted page bytes');
assert(str_contains($dump, 'SensitiveParameterValue'), 'the trace must show a SensitiveParameterValue in place of the redacted arguments');
// The binary key can hold a quote or 0x00, which var_export() escapes, so
// look for it in the raw trace arguments, not in the dump.
$argsHold = static function (array $trace, string $needle): bool {
	$found = false;
	foreach ($trace as $frame) {
		array_walk_recursive($frame['args'], static function ($arg) use ($needle, &$found): void {
			$found = $found || (is_string($arg) && str_contains($arg, $needle));
		});
	}
	return $found;
};
assert(!$argsHold($e->getTrace(), $key), 'the trace must not hold the binary backup key (decryptStream $key)');

// The mid-load overflow above never reaches Crypt15::detectStream() (MemDb
// throws first, once the header page count is exceeded), so it proves
// nothing about that frame's own #[\SensitiveParameter] on $plain. The real
// way detectStream() throws is a wrong key or corrupt data: the first
// decrypted chunk is then neither a zlib header nor "SQLite format 3", and
// that chunk is exactly the decrypted bytes finding 1 must keep off disk.
// Encrypt bogus plaintext with the same key/iv/counter as above and run it
// through the real decryptTo() -> decryptStream() -> detectStream() chain,
// so the frame on the stack at throw time is the real call, not a
// Reflection shim (ReflectionMethod::invoke() is not ours to mark, and
// calling detectStream() through it would leak the argument via invoke()'s
// own frame regardless of detectStream()'s attribute).
$bogus = 'not zlib and not sqlite: ' . bin2hex(random_bytes(24));
$bogusCipher = openssl_encrypt($bogus, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $counter);
assert($bogusCipher !== false, 'bogus ciphertext must encrypt');
$bogusTag = random_bytes(16);
$bogusTrailer = md5($header . $bogusCipher . $bogusTag, true);
$bogusStream = $header . $bogusCipher . $bogusTag . $bogusTrailer;

$in = fopen('php://memory', 'w+b');
assert($in !== false);
fwrite($in, $bogusStream);
rewind($in);
$e = null;
try {
	Crypt15::decryptTo($in, static function (): void {}, $keyHex, strlen($bogusStream));
} catch (\Throwable $caught) {
	$e = $caught;
}
fclose($in);
assert($e !== null && str_contains($e->getMessage(), 'neither zlib nor SQLite'), 'bogus plaintext must throw in detectStream(): ' . ($e?->getMessage() ?? 'no throw'));
$dump = var_export($e->getTrace(), true);
assert(!str_contains($dump, $keyHex), 'the detectStream trace must not hold the key hex');
assert(!str_contains($dump, $bogus), 'the detectStream trace must not hold the bogus decrypted chunk');
assert(str_contains($dump, 'SensitiveParameterValue'), 'the detectStream trace must show a SensitiveParameterValue in place of the redacted arguments');

echo "memdb ok\n";
