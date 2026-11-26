<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Source\WhatsApp;

use OCA\ChatArchive\Service\ImportException;
use OCA\ChatArchive\Source\IBackupReader;
use OCA\ChatArchive\Source\IProvider;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * WhatsApp and WhatsApp Business share this provider: same backup format,
 * a different folder. The source folder mirrors the phone's WhatsApp/ folder
 * (Databases/msgstore.db.crypt15, Media/WhatsApp Images/...), but the backup
 * is also accepted at the folder root, which is where a flat auto-upload puts it.
 */
class WhatsAppProvider implements IProvider {
	public const ID = 'whatsapp';
	private const BACKUP = 'msgstore.db.crypt15';
	/** zlib's largest possible deflate ratio: bounds the decrypted size a given encrypted size can expand to. */
	private const ZLIB_MAX_RATIO = 1032;

	public function id(): string {
		return self::ID;
	}

	public function name(): string {
		return 'WhatsApp';
	}

	public function needsSecret(): bool {
		return true;
	}

	public function findBackup(Folder $folder): ?File {
		foreach ([self::BACKUP, 'Databases/' . self::BACKUP] as $rel) {
			try {
				$node = $folder->get($rel);
			} catch (NotFoundException) {
				continue;
			}
			if ($node instanceof File) {
				return $node;
			}
		}
		return null;
	}

	public function open(File $backup, #[\SensitiveParameter] ?string $secret): IBackupReader {
		if ($secret === null || $secret === '') {
			throw new ImportException('no_key', 'no backup key set for this source');
		}
		$in = $backup->fopen('rb');
		if ($in === false) {
			throw new ImportException('backup_unreadable', 'cannot read the backup file');
		}
		$mem = new MemDb($backup->getSize() * self::ZLIB_MAX_RATIO);
		try {
			Crypt15::decryptTo($in, $mem->write(...), $secret, $backup->getSize());
			$pdo = $mem->finish();
			try {
				return new MsgstoreReader($pdo);
			} catch (\Throwable $e) {
				// Reader construction guard: finish()
				// already handed the only connection to $pdo; MemDb owns
				// nothing here for abort() to drop. PHP keeps a frame's
				// arguments in an exception's trace (zend.exception_ignore_args
				// is 0 unless php.ini sets it), so $pdo would stay
				// referenced, and the whole memdb alive, for as long as $e
				// lives. Keep only the error code and the message and drop $e
				// and $pdo before anyone outside can hold either.
				$code = $e instanceof ImportException ? $e->errorCode : 'import_failed';
				$msg = $e->getMessage();
				unset($e, $pdo);
				throw new ImportException($code, $msg);
			}
		} catch (\Throwable $e) {
			$mem->abort();
			throw $e;
		} finally {
			fclose($in);
		}
	}
}
