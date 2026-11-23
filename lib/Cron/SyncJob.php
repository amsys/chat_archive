<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Cron;

use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\ImportRunningException;
use OCA\ChatArchive\Service\SyncService;
use OCA\ChatArchive\Waha\WahaConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Every 15 minutes: run the live sync of every source that has a WAHA session. */
class SyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private SourceMapper $sources,
		private SyncService $sync,
		private WahaConfig $config,
	) {
		parent::__construct($time);
		// arch: D25.2
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		if (!$this->config->enabled()) {
			return;
		}
		foreach ($this->sources->findAll() as $source) {
			if ($source->getSyncSession() === null || $source->isImporting()) {
				continue;
			}
			try {
				$this->sync->run($source);
			} catch (ImportRunningException) {
				// lost the claim to another worker; that one owns the source row
			}
		}
	}
}
