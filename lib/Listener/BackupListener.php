<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Listener;

use OCA\ChatArchive\Cron\ImportSourceJob;
use OCA\ChatArchive\Db\ArchiveStore;
use OCA\ChatArchive\Db\Source;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Source\ProviderRegistry;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Queues an import the moment a source's backup file is written, instead of
 * waiting for the next cron tick. Never blocks the upload: a failure here is
 * logged and the write goes on.
 *
 * @template-implements IEventListener<NodeCreatedEvent|NodeWrittenEvent>
 */
class BackupListener implements IEventListener {
	public function __construct(
		private SourceMapper $sources,
		private ArchiveStore $store,
		private ProviderRegistry $providers,
		private IRootFolder $root,
		private IJobList $jobs,
		private LoggerInterface $log,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof NodeCreatedEvent && !$event instanceof NodeWrittenEvent) {
			return;
		}
		try {
			$node = $event->getNode();
			if (!$node instanceof File) {
				return;
			}
			$owner = $node->getOwner();
			if ($owner !== null) {
				$this->checkSources($node, $owner->getUID());
			}
		} catch (\Throwable $e) {
			$this->log->warning('chat_archive backup listener failed', ['exception' => $e]);
		}
	}

	private function checkSources(File $node, string $userId): void {
		if (!$this->store->hasSources()) {
			return; // this runs on every write on the instance; most hosts have no archive
		}
		$path = $node->getPath();
		foreach ($this->sources->findByUser($userId) as $source) {
			$sourcePath = rtrim($this->root->getUserFolder($userId)->getPath(), '/') . '/' . trim($source->getFolder(), '/');
			if (!str_starts_with($path, $sourcePath . '/')) {
				continue; // cheap prefix test before touching the provider
			}
			$this->queueIfBackup($source, $sourcePath, $node);
		}
	}

	private function queueIfBackup(Source $source, string $sourcePath, File $node): void {
		try {
			$folder = $this->root->get($sourcePath);
		} catch (NotFoundException) {
			return;
		}
		if (!$folder instanceof Folder) {
			return;
		}
		$backup = $this->providers->get($source->getProvider())->findBackup($folder);
		if ($backup === null || $backup->getId() !== $node->getId()) {
			return;
		}
		// Only the caller that changed the row adds the job: two writes of one
		// backup add one job.
		if ($this->sources->queue($source)) {
			$this->jobs->add(ImportSourceJob::class, ['sourceId' => $source->getId()]);
		}
	}
}
