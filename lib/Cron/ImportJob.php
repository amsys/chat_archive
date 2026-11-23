<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Cron;

use OCA\ChatArchive\Db\Source;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\ImportRunningException;
use OCA\ChatArchive\Service\ImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Every 6 hours: import every backup source whose backup changed. */
class ImportJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private SourceMapper $sources,
		private ImportService $import,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 3600);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		foreach ($this->sources->findAll() as $source) {
			// arch: D22.2
			// A live-only source has no backup file: the live sync fills it.
			if ($source->getMode() === Source::MODE_LIVE) {
				continue;
			}
			// A queued ImportSourceJob or a second cron worker can already hold this
			// source. Two imports of one source read the known keys before either
			// commits, so both insert and the unique index fails one of them.
			if ($source->isImporting()) {
				continue;
			}
			try {
				$this->import->run($source);
			} catch (ImportRunningException) {
				// lost the claim to another worker; that one owns the source row
			} catch (\Throwable) {
				// recorded on the source row by ImportService
			}
		}
	}
}
