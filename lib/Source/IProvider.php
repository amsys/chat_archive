<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Source;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * One chat platform. A provider finds the backup in the user's folder and
 * opens it as a reader that yields platform-neutral rows.
 */
interface IProvider {
	public function id(): string;

	public function name(): string;

	/** True when the backup needs a secret (WhatsApp: the 64-hex backup key). */
	public function needsSecret(): bool;

	/** The backup file inside the source folder, or null when there is none. */
	public function findBackup(Folder $folder): ?File;

	/**
	 * Open the backup. The provider keeps the decrypted data in memory. It
	 * must not write it to disk.
	 * @throws \RuntimeException with a message safe to show the user
	 */
	public function open(File $backup, #[\SensitiveParameter] ?string $secret): IBackupReader;
}
