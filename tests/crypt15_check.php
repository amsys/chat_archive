<?php
// Checks Crypt15: the fixture decrypts to the plain database, and a wrong
// key, a short key and a corrupt file each fail with an exception.
declare(strict_types=1);
require __DIR__ . '/../lib/Service/ImportException.php';
require __DIR__ . '/../lib/Source/WhatsApp/Crypt15.php';
require __DIR__ . '/../lib/Source/WhatsApp/MemDb.php';
use OCA\ChatArchive\Source\WhatsApp\Crypt15;
use OCA\ChatArchive\Source\WhatsApp\MemDb;
$fx = __DIR__ . '/fixtures';
$out = sys_get_temp_dir() . '/ca_check_' . getmypid() . '.db';
Crypt15::decryptFile("$fx/msgstore.db.crypt15", $out, trim(file_get_contents("$fx/key.hex")));
assert(hash_file('sha256', $out) === hash_file('sha256', "$fx/msgstore.db"), 'decrypted db differs from plaintext');
$bad = false;
try { Crypt15::decryptFile("$fx/msgstore.db.crypt15", $out . '.bad', str_repeat('ab', 32)); } catch (RuntimeException $e) { $bad = true; }
assert($bad, 'wrong key must throw');
assert(!file_exists($out . '.bad'), 'failed decrypt must leave no file');
$bad = false;
try { Crypt15::decryptFile("$fx/msgstore.db.crypt15", $out . '.bad', str_repeat('ab', 31)); } catch (RuntimeException $e) { $bad = true; }
assert($bad, 'a key that is not 64 hex characters must throw');
// flip one byte in the middle of the ciphertext: the stream must fail (zlib or md5), never write a broken database
$blob = file_get_contents("$fx/msgstore.db.crypt15");
$blob[intdiv(strlen($blob), 2)] = chr(ord($blob[intdiv(strlen($blob), 2)]) ^ 0xFF);
file_put_contents($out . '.corrupt', $blob);
$bad = false;
try { Crypt15::decryptFile($out . '.corrupt', $out . '.bad', trim(file_get_contents("$fx/key.hex"))); } catch (RuntimeException $e) { $bad = true; }
assert($bad, 'a corrupt backup must throw');
assert(!file_exists($out . '.bad'), 'corrupt input must leave no file');
@unlink($out . '.corrupt');
// a stream wrapper that returns short reads, like a Nextcloud file handle over S3: same output, no seek
final class ShortReads {
	public static string $data = '';
	private int $pos = 0;
	/** @var resource|null */
	public $context;
	public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool { return true; }
	public function stream_read(int $n): string { $b = substr(self::$data, $this->pos, min($n, 100)); $this->pos += strlen($b); return $b; }
	public function stream_eof(): bool { return $this->pos >= strlen(self::$data); }
}
ShortReads::$data = file_get_contents("$fx/msgstore.db.crypt15");
stream_wrapper_register('short', ShortReads::class);
$in = fopen('short://x', 'r');
Crypt15::decrypt($in, $out, trim(file_get_contents("$fx/key.hex")), strlen(ShortReads::$data));
fclose($in);
assert(hash_file('sha256', $out) === hash_file('sha256', "$fx/msgstore.db"), 'short-read stream decrypts to the same db');
@unlink($out);
/**
 * MemDb case: decrypt into SQLite memory, read every page back and compare
 * with the plaintext fixture. Page 1 changes in bytes 18/19 (set to 1)
 * and in the header fields that SQLite writes on COMMIT (24-27, 92-99).
 */
$encSize = filesize("$fx/msgstore.db.crypt15");
$mem = new MemDb($encSize * 1032); // the same bound that WhatsAppProvider::open() uses
$in = fopen("$fx/msgstore.db.crypt15", 'rb');
Crypt15::decryptTo($in, $mem->write(...), trim(file_get_contents("$fx/key.hex")), $encSize);
fclose($in);
$db = $mem->finish();
$back = implode('', $db->query('SELECT data FROM sqlite_dbpage ORDER BY pgno')->fetchAll(PDO::FETCH_COLUMN));
$want = file_get_contents("$fx/msgstore.db");
$want[18] = "\x01";
$want[19] = "\x01";
assert(substr($back, 24, 4) === substr($back, 92, 4), 'memdb header page count stays valid');
$want = substr_replace($want, substr($back, 24, 4), 24, 4);
$want = substr_replace($want, substr($back, 92, 8), 92, 8);
assert(hash('sha256', $back) === hash('sha256', $want), 'memdb pages differ from the plaintext');
assert((int)$db->query('SELECT count(*) FROM sqlite_master')->fetchColumn() > 0, 'memdb database has a schema');
unset($db);
// a wrong key must throw from decryptTo, before the sink gets a database
$mem = new MemDb($encSize * 1032);
$in = fopen("$fx/msgstore.db.crypt15", 'rb');
$bad = false;
try { Crypt15::decryptTo($in, $mem->write(...), str_repeat('ab', 32), filesize("$fx/msgstore.db.crypt15")); } catch (RuntimeException $e) { $bad = true; }
fclose($in);
$mem->abort();
assert($bad, 'wrong key must throw from decryptTo');
echo "crypt15 ok\n";
