<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Source\WhatsApp;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Loads a decrypted SQLite database into SQLite memory, page by page. No byte
 * of the database goes to disk.
 *
 * Use it as the Crypt15 sink: `Crypt15::decryptTo($in, $mem->write(...), ...)`,
 * then `$mem->finish()` gives a read connection.
 *
 * How it works: the database lives in the memdb VFS under a unique name that
 * starts with "/", so a second connection of this process can open it. The
 * loader connection grows an empty database to the page count of the input
 * header, then writes each input page over it through sqlite_dbpage. The
 * sqlite_dbpage table of SQLite 3.46 refuses INSERT, so the loader grows the
 * file first and then uses UPDATE. Page 1 gets bytes 18 and 19 set to 1: a WAL
 * database in memdb does not open. Page 1 is written last, just before the
 * COMMIT: it holds the schema cookie, and a new cookie makes SQLite read the
 * schema of a database that is only partly loaded.
 *
 * The COMMIT also writes three header fields of page 1: the change counter
 * (bytes 24-27), the version-valid-for number (92-95) and the SQLite version
 * (96-99). SQLite does this on each commit, in each locking and journal mode.
 * The header stays valid, because bytes 24-27 and 92-95 are equal. All other
 * bytes are the input bytes.
 *
 * Limit: MAX_BYTES, the default maximum size of a memdb database, and
 * $maxPlainBytes, a second cap the caller passes through the constructor
 * (WhatsAppProvider passes the encrypted backup's size times 1032, zlib's
 * largest deflate ratio, so a small encrypted file cannot force a large
 * allocation). open() refuses before any growth -- before it opens the
 * loader connection -- when the header's page count times page size is
 * above either limit.
 */
final class MemDb {
	public const MAX_BYTES = 1073741824;

	private const HEADER = 100;
	private const MIB = 1048576;
	/** Pages that the grow loop leaves for single CREATE TABLE steps. */
	private const SMALL = 6;
	/** Largest zeroblob of one grow step: SQLite refuses a value above 1e9 bytes. */
	private const STEP = 268435456;

	private int $maxPlainBytes;
	private string $name;
	private ?PDO $loader = null;
	private ?PDOStatement $update = null;
	private string $buf = '';
	/** Page 1, patched, kept until finish(). */
	private string $first = '';
	private int $pageSize = 0;
	private int $pages = 0;
	/** 1-based number of the next page to write. */
	private int $next = 1;

	/** @param int $maxPlainBytes the second cap; see the class doc. */
	public function __construct(int $maxPlainBytes) {
		$this->maxPlainBytes = $maxPlainBytes;
		$this->name = '/chat_archive-' . bin2hex(random_bytes(16));
	}

	/**
	 * Takes the next bytes of the database, in order. Writes each whole page
	 * and keeps at most one partial page.
	 * @throws RuntimeException
	 */
	public function write(#[\SensitiveParameter] string $bytes): void {
		try {
			$this->buf .= $bytes;
			if ($this->loader === null) {
				if (strlen($this->buf) < self::HEADER) {
					return;
				}
				$this->open(substr($this->buf, 0, self::HEADER));
			}
			$this->flushPages();
		} catch (\Throwable $e) {
			$this->abort();
			throw $e;
		}
	}

	/**
	 * Ends the load and returns a new connection to the database. The caller
	 * frees the memory when it drops that connection.
	 * @throws RuntimeException
	 */
	public function finish(): PDO {
		try {
			if ($this->loader === null || $this->buf !== '' || $this->next - 1 !== $this->pages) {
				throw new RuntimeException('truncated backup');
			}
			$this->put(1, $this->first);
			$this->loader->exec('COMMIT');
			// open the reader before the loader closes: the last connection frees the memdb
			$reader = new PDO($this->dsn());
			$this->abort();
			return $reader;
		} catch (\Throwable $e) {
			$this->abort();
			throw $e;
		}
	}

	/** Drops every connection that this object holds. Safe to call again. */
	public function abort(): void {
		$this->update = null;
		$this->loader = null;
		$this->buf = '';
		$this->first = '';
	}

	private function dsn(): string {
		return 'sqlite:file:' . $this->name . '?vfs=memdb';
	}

	/** Reads the header, then makes an empty database of the same page size and page count. */
	private function open(#[\SensitiveParameter] string $head): void {
		if (!str_starts_with($head, "SQLite format 3\0")) {
			throw new RuntimeException('unsupported WhatsApp backup: not an SQLite database');
		}
		$ps = unpack('n', $head, 16)[1];
		$ps = $ps === 1 ? 65536 : $ps;
		if ($ps < 512 || $ps > 65536 || ($ps & ($ps - 1)) !== 0) {
			throw new RuntimeException("unsupported WhatsApp backup: page size $ps");
		}
		if (substr($head, 24, 4) !== substr($head, 92, 4)) {
			throw new RuntimeException('unsupported WhatsApp backup: the header page count is not valid');
		}
		$n = unpack('N', $head, 28)[1];
		if ($n < 2) {
			throw new RuntimeException("unsupported WhatsApp backup: $n pages");
		}
		$bytes = $n * $ps;
		if ($bytes > self::MAX_BYTES) {
			$mib = intdiv($bytes + self::MIB - 1, self::MIB);
			throw new RuntimeException("decrypted backup is $mib MiB; the in-memory import supports up to 1024 MiB");
		}
		if ($bytes > $this->maxPlainBytes) {
			$mib = intdiv($bytes + self::MIB - 1, self::MIB);
			$capMib = intdiv($this->maxPlainBytes + self::MIB - 1, self::MIB);
			throw new RuntimeException("decrypted backup is $mib MiB; the encrypted source allows at most $capMib MiB");
		}
		$this->pageSize = $ps;
		$this->pages = $n;

		$db = new PDO($this->dsn());
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->loader = $db;
		$db->exec('PRAGMA auto_vacuum=NONE');
		$db->exec("PRAGMA page_size=$ps");
		$db->exec('PRAGMA journal_mode=OFF');
		$db->exec('PRAGMA synchronous=OFF');
		$db->exec('CREATE TABLE pad(x)');
		$this->grow($db, $n, $ps);
		$db->exec('BEGIN');
		$this->update = $db->prepare('UPDATE sqlite_dbpage SET data = ? WHERE pgno = ?');
	}

	/**
	 * Adds pages until the page count is $n, and never more. One zeroblob of
	 * m pages needs at most m overflow pages and 2 pages for a b-tree split,
	 * so each large step stops 4 pages short or more. A step is at most STEP
 * bytes, so a large database needs some steps. The last pages come
	 * from CREATE TABLE, one root page each.
	 */
	private function grow(PDO $db, int $n, int $ps): void {
		$count = static fn (): int => (int)$db->query('PRAGMA page_count')->fetchColumn();
		$pad = $db->prepare('INSERT INTO pad VALUES (zeroblob(?))');
		$missing = $n - $count();
		while ($missing > self::SMALL) {
			$pad->execute([min(($missing - 4) * ($ps - 4), self::STEP)]);
			$missing = $n - $count();
		}
		for ($t = 0; $missing > 0; $t++, $missing--) {
			$db->exec("CREATE TABLE p$t(x)");
		}
		$got = $count();
		if ($got !== $n) {
			throw new RuntimeException("in-memory import: page count $got, expected $n");
		}
	}

	/** Writes each whole page in the buffer and keeps the rest. */
	private function flushPages(): void {
		$ps = $this->pageSize;
		$len = strlen($this->buf);
		$off = 0;
		while ($len - $off >= $ps) {
			if ($this->next > $this->pages) {
				throw new RuntimeException('unsupported WhatsApp backup: more data than the header page count');
			}
			$page = substr($this->buf, $off, $ps);
			if ($this->next === 1) {
				$page[18] = "\x01";
				$page[19] = "\x01";
				$this->first = $page;
			} else {
				$this->put($this->next, $page);
			}
			$this->next++;
			$off += $ps;
		}
		if ($off > 0) {
			$this->buf = substr($this->buf, $off);
		}
	}

	private function put(int $pgno, #[\SensitiveParameter] string $page): void {
		if ($this->update === null) {
			throw new RuntimeException('in-memory import: the loader is closed');
		}
		$this->update->bindValue(1, $page, PDO::PARAM_LOB);
		$this->update->bindValue(2, $pgno, PDO::PARAM_INT);
		$this->update->execute();
	}
}
