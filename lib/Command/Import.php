<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Command;

use OCA\ChatArchive\Db\Source;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\SourceBusyException;
use OCA\ChatArchive\Service\ImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Import extends Command {
	public function __construct(
		private SourceMapper $sources,
		private ImportService $import,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('chat_archive:import')
			->setDescription('Import chat backups (all sources, or one user or source)')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'only this user')
			->addOption('source', 's', InputOption::VALUE_REQUIRED, 'only this source id')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'import even when the backup is unchanged');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getOption('user');
		$only = $input->getOption('source');
		$list = $user ? $this->sources->findByUser($user) : $this->sources->findAll();
		$rc = 0;
		foreach ($list as $source) {
			if ($only !== null && (int)$only !== $source->getId()) {
				continue;
			}
			$label = sprintf('%s [%d] %s (%s)', $source->getUserId(), $source->getId(), $source->getLabel(), $source->getFolder());
			if ($source->getMode() === Source::MODE_LIVE) {
				$output->writeln("$label: skipped, live source");
				continue;
			}
			// Two imports of one source collide on the unique message index. Only an
			// executing import blocks: a queued source is work this run may drain.
			if ($source->isRunning()) {
				$output->writeln("$label: an import or a sync is running");
				continue;
			}
			try {
				$stats = $this->import->run($source, (bool)$input->getOption('force'));
				$output->writeln("$label: " . json_encode($stats));
			} catch (SourceBusyException) {
				$output->writeln("$label: an import or a sync is running");
			} catch (\Throwable $e) {
				$output->writeln("<error>$label: {$e->getMessage()}</error>");
				$rc = 1;
			}
		}
		return $rc;
	}
}
