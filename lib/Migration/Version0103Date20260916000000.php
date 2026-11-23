<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Migration;

use Closure;
use OCA\ChatArchive\Cron\ImportJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Re-add the ImportJob row, so upgrade resets it to time_sensitive=1. */
class Version0103Date20260916000000 extends SimpleMigrationStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->jobList->remove(ImportJob::class);
		$this->jobList->add(ImportJob::class);
	}
}
