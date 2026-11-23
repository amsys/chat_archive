<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Command;

use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\ImportRunningException;
use OCA\ChatArchive\Service\SyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** arch: D25.2 - run the live sync by hand; one JSON line per source. */
class Sync extends Command {
	public function __construct(
		private SourceMapper $sources,
		private SyncService $sync,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('chat_archive:sync')
			->setDescription('Run the live sync (all sources with a WAHA session, or one source)')
			->addOption('source', 's', InputOption::VALUE_REQUIRED, 'only this source id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$only = $input->getOption('source');
		$rc = 0;
		foreach ($this->sources->findAll() as $source) {
			if ($only !== null && (int)$only !== $source->getId()) {
				continue;
			}
			if ($source->getSyncSession() === null) {
				continue;
			}
			try {
				$stats = $this->sync->run($source);
				$error = $source->getSyncError();
				if ($error !== null) {
					$output->writeln(json_encode(['error' => $error]));
					$rc = 1;
				} else {
					$output->writeln(json_encode($stats));
				}
			} catch (ImportRunningException) {
				$output->writeln(json_encode(['error' => 'running']));
				$rc = 1;
			}
		}
		return $rc;
	}
}
