<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One configured backup: a provider (whatsapp, ...) reading one Nextcloud folder.
 *
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method string getProvider()
 * @method void setProvider(string $v)
 * @method string getLabel()
 * @method void setLabel(string $v)
 * @method string getFolder()
 * @method void setFolder(string $v)
 * @method ?string getSecret()
 * @method void setSecret(?string $v)
 * @method string getOnDelete()
 * @method void setOnDelete(string $v)
 * @method ?string getLastBackup()
 * @method void setLastBackup(?string $v)
 * @method ?int getLastSynced()
 * @method void setLastSynced(?int $v)
 * @method ?string getLastError()
 * @method void setLastError(?string $v)
 * @method ?string getImportState()
 * @method void setImportState(?string $v)
 * @method ?int getImportSince()
 * @method void setImportSince(?int $v)
 * @method string getMode()
 * @method void setMode(string $v)
 * @method ?string getSyncSession()
 * @method void setSyncSession(?string $v)
 * @method ?string getSyncMe()
 * @method void setSyncMe(?string $v)
 * @method ?int getSyncAt()
 * @method void setSyncAt(?int $v)
 * @method ?string getSyncError()
 * @method void setSyncError(?string $v)
 * @method ?int getTosAt()
 * @method void setTosAt(?int $v)
 */
class Source extends Entity {
	public const ON_DELETE_RESTORE = 'restore'; // copy the file back to its source path, then let the delete go on
	public const ON_DELETE_UNLINK = 'unlink';   // forget the pointer; the message shows "file removed"
	public const ON_DELETE_KEEP = 'keep';       // keep the pointer; valid again after a trash restore
	public const ON_DELETE = [self::ON_DELETE_RESTORE, self::ON_DELETE_UNLINK, self::ON_DELETE_KEEP];
	public const MODE_BACKUP = 'backup'; // reads a backup folder
	public const MODE_LIVE = 'live';     // no backup file; filled by the live sync

	protected string $userId = '';
	protected string $provider = '';
	protected string $label = '';
	protected string $folder = '';
	protected ?string $secret = null;
	protected string $onDelete = self::ON_DELETE_RESTORE;
	protected ?string $lastBackup = null;
	protected ?int $lastSynced = null;
	protected ?string $lastError = null;
	protected ?string $importState = null; // 'queued' | 'running' | null (idle)
	protected ?int $importSince = null;    // unix s, when the state was set
	protected string $mode = self::MODE_BACKUP;
	protected ?string $syncSession = null; // WAHA session name; null = live sync off
	protected ?string $syncMe = null;      // me.id seen at the first sync
	protected ?int $syncAt = null;         // unix s of the last sync without error
	protected ?string $syncError = null;   // error code of the last sync
	protected ?int $tosAt = null;          // unix s when the user accepted the warning

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('lastSynced', 'integer');
		$this->addType('importSince', 'integer');
		$this->addType('syncAt', 'integer');
		$this->addType('tosAt', 'integer');
	}

	/** A queued or running import; a state older than 6 h is a crashed run and counts as idle. */
	public function isImporting(): bool {
		return $this->importState !== null && ($this->importSince ?? 0) > time() - 6 * 3600;
	}

	/** A running import whose state is older than 6 h: a crashed run. */
	public function isStale(): bool {
		return $this->importState === 'running' && ($this->importSince ?? 0) <= time() - 6 * 3600;
	}

	/**
	 * An import that is executing now. A queued one has not started, so an
	 * operator who asks for an import on the command line may still drain it.
	 */
	public function isRunning(): bool {
		return $this->importState === 'running' && ($this->importSince ?? 0) > time() - 6 * 3600;
	}

	/** Public view: never the secret itself. */
	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'id' => $this->getId(),
			'provider' => $this->provider,
			'label' => $this->label,
			'folder' => $this->folder,
			'hasSecret' => $this->secret !== null && $this->secret !== '',
			'onDelete' => $this->onDelete,
			'lastBackup' => $this->lastBackup,
			'lastSynced' => $this->lastSynced,
			'lastError' => $this->lastError,
			'importState' => $this->isImporting() ? $this->importState : null,
			'importSince' => $this->isImporting() ? $this->importSince : null,
			'mode' => $this->mode,
			'syncSession' => $this->syncSession,
			'syncAt' => $this->syncAt,
			'syncError' => $this->syncError,
			'tosAt' => $this->tosAt,
		];
	}
}
