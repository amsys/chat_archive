<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Migration;

use Closure;
use OCA\ChatArchive\Cron\ImportJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * An older release stored ImportJob as not time-sensitive. Remove the job and
 * add it again, so that it gets the default: time-sensitive.
 */
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
