<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Source\WhatsApp;

use RuntimeException;

/**
 * Decrypts a WhatsApp msgstore.db.crypt15 backup with the 64-hex end-to-end
 * backup key. Streams in 1 MiB chunks, so memory stays flat for a 1 GB backup.
 *
 * File layout (single-file backup):
 *   [1]  protobuf length
 *   [1]  0x01 feature flag (optional)
 *   [n]  protobuf BackupPrefix; field 3 = C15_IV { field 1 = IV (16 bytes) }
 *   [..] AES-256-GCM ciphertext of a zlib stream
 *   [16] GCM tag
 *   [16] md5 of everything before it
 *
 * ponytail: the GCM tag is not verified (PHP has no streaming AEAD). Corruption
 * is caught by the md5 trailer, a wrong key by the zlib/SQLite header check.
 * Upgrade path: verify the tag with a one-shot openssl_decrypt when the file
 * fits in memory.
 */
final class Crypt15 {
	private const CHUNK = 1048576;

	/** @throws RuntimeException */
	public static function decryptFile(string $src, string $dst, #[\SensitiveParameter] string $keyHex): void {
		$in = fopen($src, 'rb');
		if ($in === false) {
			throw new RuntimeException("cannot open $src");
		}
		try {
			self::decrypt($in, $dst, $keyHex, (int)filesize($src));
		} finally {
			fclose($in);
		}
	}

	/**
	 * Decrypt a readable stream of $size bytes into $dst. The stream is read once, in order;
	 * no seek is needed, so a Nextcloud file handle works as-is.
	 * @param resource $in
	 * @throws RuntimeException
	 */
	public static function decrypt($in, string $dst, #[\SensitiveParameter] string $keyHex, int $size): void {
		$out = fopen($dst, 'wb');
		if ($out === false) {
			throw new RuntimeException("cannot write $dst");
		}
		$sink = static function (#[\SensitiveParameter] string $data) use ($out): void {
			if (fwrite($out, $data) === false) {
				throw new RuntimeException('write failed');
			}
		};
		try {
			self::decryptTo($in, $sink, $keyHex, $size);
		} catch (\Throwable $e) {
			fclose($out);
			@unlink($dst);
			throw $e;
		}
		fclose($out);
	}

	/**
	 * Decrypt a readable stream of $size bytes and give the plaintext to $sink,
	 * in order, in parts of any size. The import uses this form with a MemDb
	 * sink, so no plaintext goes to disk. The md5 trailer check runs before
	 * this returns.
	 * @param resource $in
	 * @param \Closure(string): void $sink
	 * @throws RuntimeException
	 */
	public static function decryptTo($in, \Closure $sink, #[\SensitiveParameter] string $keyHex, int $size): void {
		self::decryptStream($in, $sink, self::backupKey($keyHex), $size);
	}

	/**
	 * fread() on a stream wrapper returns after one short read; loop until $n bytes or EOF.
	 * @param resource $in
	 */
	private static function readN($in, int $n): string {
		$buf = '';
		while (strlen($buf) < $n) {
			$b = fread($in, $n - strlen($buf));
			if ($b === false || $b === '') {
				break;
			}
			$buf .= $b;
		}
		return $buf;
	}

	/** Root key -> AES key: HMAC-SHA256 loop with "backup encryption", one iteration. */
	public static function backupKey(#[\SensitiveParameter] string $keyHex): string {
		$root = @hex2bin(trim($keyHex));
		if ($root === false || strlen($root) !== 32) {
			throw new RuntimeException('backup key must be 64 hex characters');
		}
		$private = hash_hmac('sha256', $root, str_repeat("\0", 32), true);
		return hash_hmac('sha256', 'backup encryption' . "\x01", $private, true);
	}

	/**
	 * @param resource $in
	 * @param \Closure(string): void $out
	 */
	private static function decryptStream($in, \Closure $out, #[\SensitiveParameter] string $key, int $size): void {
		$md5 = hash_init('md5');
		[$iv, $left] = self::readHeader($in, $md5, $size);
		$counter = self::firstCounter($key, $iv);
		$inflate = null; // set by the first chunk: a zlib context, or false for a raw SQLite stream
		while ($left > 0) {
			$n = self::chunkSize(min(self::CHUNK, $left), $counter);
			$chunk = self::readN($in, $n);
			if (strlen($chunk) !== $n) {
				throw new RuntimeException('truncated crypt15 body');
			}
			hash_update($md5, $chunk);
			$plain = openssl_decrypt($chunk, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $counter);
			if ($plain === false) {
				throw new RuntimeException('AES-CTR failed: ' . openssl_error_string());
			}
			$counter = self::advance($counter, $n);
			$left -= $n;
			$inflate ??= self::detectStream($plain);
			// @: a corrupt stream warns before it returns false; the throw in write() is the report
			self::write($out, $inflate === false ? $plain : @inflate_add($inflate, $plain));
		}
		if ($inflate !== null && $inflate !== false) {
			self::write($out, inflate_add($inflate, '', \ZLIB_FINISH));
		}
		$tag = self::readN($in, 16);
		$checksum = self::readN($in, 16);
		hash_update($md5, $tag);
		if (!hash_equals(hash_final($md5, true), $checksum)) {
			throw new RuntimeException('md5 trailer mismatch: corrupt or multi-file backup');
		}
	}

	/**
	 * Reads the prefix up to the ciphertext and feeds it to the md5.
	 * @param resource $in
	 * @param \HashContext $md5
	 * @return array{string, int} IV and ciphertext length
	 */
	private static function readHeader($in, \HashContext $md5, int $size): array {
		$read = static function (int $n) use ($in, $md5): string {
			$b = self::readN($in, $n);
			if (strlen($b) !== $n) {
				throw new RuntimeException('truncated crypt15 header');
			}
			hash_update($md5, $b);
			return $b;
		};
		$protoLen = ord($read(1));
		if ($protoLen < 1) {
			throw new RuntimeException('crypt15 header has no prefix');
		}
		// an optional 0x01 flag sits before the proto; without it, the byte is the proto's first byte
		$flag = $read(1);
		$proto = $flag === "\x01" ? $read($protoLen) : $flag . $read($protoLen - 1);
		$iv = self::ivFromPrefix($proto);
		$cipherLen = $size - 2 - ($protoLen - ($flag === "\x01" ? 0 : 1)) - 32;
		if ($cipherLen <= 0) {
			throw new RuntimeException('crypt15 file too short');
		}
		return [$iv, $cipherLen];
	}

	/**
	 * GCM increments only the low 32 bits of the counter; CTR mode increments
	 * all 128. Cut the chunk where the low word would wrap.
	 */
	private static function chunkSize(int $n, string $counter): int {
		$low = unpack('N', substr($counter, 12))[1];
		$blocks = intdiv($n + 15, 16);
		return $low + $blocks > 0x100000000 ? (0x100000000 - $low) * 16 : $n;
	}

	private static function advance(string $counter, int $n): string {
		$low = unpack('N', substr($counter, 12))[1];
		return substr($counter, 0, 12) . pack('N', ($low + intdiv($n + 15, 16)) & 0xFFFFFFFF);
	}

	/** @return \InflateContext|false false for a raw SQLite stream */
	private static function detectStream(#[\SensitiveParameter] string $plain): \InflateContext|false {
		if ($plain[0] === "\x78") {
			return inflate_init(15 /* ZLIB_ENCODING_ZLIB */);
		}
		if (str_starts_with($plain, 'SQLite format 3')) {
			return false;
		}
		throw new RuntimeException('decrypted data is neither zlib nor SQLite: wrong key?');
	}

	/** @param \Closure(string): void $out */
	private static function write(\Closure $out, #[\SensitiveParameter] string|false $data): void {
		if ($data === false) {
			throw new RuntimeException('zlib error: corrupt backup or wrong key');
		}
		if ($data !== '') {
			$out($data);
		}
	}

	/** Minimal protobuf walk: BackupPrefix.c15_iv (3) -> C15_IV.IV (1). */
	private static function ivFromPrefix(string $buf): string {
		$sub = self::protoField($buf, 3);
		$iv = $sub === null ? null : self::protoField($sub, 1);
		if ($iv === null || strlen($iv) !== 16) {
			throw new RuntimeException('no 16-byte IV in crypt15 header (crypt14 file?)');
		}
		return $iv;
	}

	private static function protoField(string $buf, int $want): ?string {
		$i = 0;
		$len = strlen($buf);
		$varint = static function () use ($buf, &$i, $len): int {
			$v = 0;
			$shift = 0;
			do {
				if ($i >= $len) {
					throw new RuntimeException('bad protobuf');
				}
				$b = ord($buf[$i++]);
				$v |= ($b & 0x7F) << $shift;
				$shift += 7;
			} while ($b & 0x80);
			return $v;
		};
		while ($i < $len) {
			$key = $varint();
			$field = $key >> 3;
			switch ($key & 7) {
				case 0:
					$varint();
					break;
				case 1:
					$i += 8;
					break;
				case 2:
					$l = $varint();
					if ($field === $want) {
						return substr($buf, $i, $l);
					}
					$i += $l;
					break;
				case 5:
					$i += 4;
					break;
				default:
					throw new RuntimeException('bad protobuf wire type');
			}
		}
		return null;
	}

	/**
	 * GCM keystream block 0 is E(inc32(J0)). openssl computes J0 for the
	 * 16-byte IV; decrypting that block with AES-ECB gives the CTR start.
	 */
	private static function firstCounter(#[\SensitiveParameter] string $key, string $iv): string {
		$tag = '';
		$ks = openssl_encrypt(str_repeat("\0", 16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		if ($ks === false) {
			throw new RuntimeException('AES-GCM setup failed: ' . openssl_error_string());
		}
		$ctr = openssl_decrypt($ks, 'aes-256-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
		if ($ctr === false || strlen($ctr) !== 16) {
			throw new RuntimeException('AES-ECB failed: ' . openssl_error_string());
		}
		return $ctr;
	}
}
