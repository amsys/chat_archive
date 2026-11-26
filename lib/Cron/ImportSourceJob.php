<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Cron;

use OCA\ChatArchive\Db\Source;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\SourceBusyException;
use OCA\ChatArchive\Service\ImportService;
use OCA\ChatArchive\Service\SyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * "Import now" from the settings page, for one source: a forced import in
 * mode backup, then a sync when the source has a WAHA session.
 */
class ImportSourceJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private SourceMapper $sources,
		private ImportService $import,
		private SyncService $sync,
	) {
		parent::__construct($time);
	}

	/** @param array{sourceId: int} $argument */
	protected function run($argument): void {
		try {
			$source = $this->sources->find((int)$argument['sourceId']);
		} catch (DoesNotExistException) {
			return;
		}
		// A live-only source has no backup file: only the sync below runs for it.
		if ($source->getMode() === Source::MODE_BACKUP) {
			try {
				$this->import->run($source, true);
			} catch (SourceBusyException) {
				// lost the claim to another worker; that one owns the source row
			} catch (\Throwable) {
				// recorded on the source row by ImportService
			}
		}
		$syncs = $source->getSyncSession() !== null;
		if ($syncs) {
			try {
				$this->sync->run($source);
			} catch (SourceBusyException) {
				// lost the claim to another worker; that one owns the source row
			}
		}
		if ($source->getMode() !== Source::MODE_BACKUP && !$syncs && $this->sources->releaseQueued($source)) {
			// no import and no sync ran: free the source for the next request
			$source->setImportState(null);
			$source->setImportSince(null);
		}
	}
}
