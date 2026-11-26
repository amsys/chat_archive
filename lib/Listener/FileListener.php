<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Listener;

use OCA\ChatArchive\Db\ArchiveStore;
use OCA\ChatArchive\Db\Source;
use OCA\ChatArchive\Service\Preserve;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Keeps media pointers true when the user touches files.
 *
 * Move or rename inside the same storage: the file id does not change, nothing
 * to do. Move across storages: the id changes, the pointer follows.
 * Delete: per-source policy (restore a copy to the source path, forget the
 * pointer, or keep it for a trash restore). The user's preserve switch makes
 * the policy "restore" for every source of that user. The listener never
 * blocks the user's operation; a failure is logged and the operation goes on.
 *
 * @template-implements IEventListener<BeforeNodeDeletedEvent|BeforeNodeRenamedEvent|NodeRenamedEvent>
 */
class FileListener implements IEventListener {
	/**
	 * File id before a rename, keyed by the target path. The id after the
	 * rename differs only when the move crosses storages.
	 * @var array<string,int>
	 */
	private array $renaming = [];

	public function __construct(
		private ArchiveStore $store,
		private IRootFolder $root,
		private Preserve $preserve,
		private LoggerInterface $log,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof BeforeNodeDeletedEvent) {
				$this->onDelete($event->getNode());
			} elseif ($event instanceof BeforeNodeRenamedEvent) {
				$this->beforeRename($event->getSource(), $event->getTarget());
			} elseif ($event instanceof NodeRenamedEvent) {
				$this->onRename($event->getTarget());
			}
		} catch (\Throwable $e) {
			$this->log->warning('chat_archive file listener failed', ['exception' => $e]);
		}
	}

	private function onDelete(Node $node): void {
		if (!$this->store->hasSources()) {
			return; // most deletes happen on hosts with no archive; skip the tree walk
		}
		$rows = $this->store->mediaByFileIds($this->idsUnder($node));
		if ($rows === []) {
			return;
		}
		$deletedPath = $node->getPath();
		$preserve = []; // uid => switch, read once per user for this delete
		foreach ($rows as $row) {
			$uid = $row['user_id'];
			$preserve[$uid] ??= $this->preserve->read($uid);
			// The preserve switch overrides the source's policy (I20.8).
			$policy = $preserve[$uid] ? Source::ON_DELETE_RESTORE : $row['on_delete'];
			switch ($policy) {
				case Source::ON_DELETE_UNLINK:
					$this->store->setMediaFile($row['id'], null);
					break;
				case Source::ON_DELETE_RESTORE:
					$this->restore($row, $deletedPath);
					break;
				default: // keep
					break;
			}
		}
	}

	/**
	 * Copy the file back to <source folder>/<src_path> before the delete goes on.
	 * A file WhatsApp flagged as suspicious stays deleted: the check runs
	 * first, so a flagged file never gets a copy back in the source folder.
	 * @param array{id:int, file_id:int, src_path:?string, source_id:int, user_id:string, folder:string, on_delete:string, suspicious:?int} $row
	 */
	private function restore(array $row, string $deletedPath): void {
		if ((int)($row['suspicious'] ?? 0) > 0) {
			return; // flagged: acts like "keep", not "restore"
		}
		if ($row['src_path'] === null || $row['src_path'] === '') {
			return;
		}
		$userFolder = $this->root->getUserFolder($row['user_id']);
		$sourcePath = $userFolder->getPath() . '/' . trim($row['folder'], '/');
		$target = $sourcePath . '/' . $row['src_path'];
		// A target under the deleted path would go away with it.
		// This covers a delete of the source folder itself: the user means it.
		if ($target === $deletedPath || str_starts_with($target, $deletedPath . '/')) {
			return;
		}
		try {
			$existing = $this->root->get($target);
			if ($existing->getId() !== $row['file_id']) {
				$this->store->setMediaFile($row['id'], $existing->getId()); // a copy is already home
			}
			return;
		} catch (NotFoundException) {
		}
		$file = $userFolder->getFirstNodeById($row['file_id']);
		if (!$file instanceof File) {
			return;
		}
		$this->ensureFolder($userFolder, dirname($target));
		$copy = $file->copy($target);
		$this->store->setMediaFile($row['id'], $copy->getId());
	}

	/**
	 * Remember the id of a file before it moves. After the move the source
	 * node no longer exists, so its id cannot be read then.
	 * A folder is not remembered: after a folder move across storages the
	 * next import relinks its files.
	 */
	private function beforeRename(Node $source, Node $target): void {
		if ($source instanceof File) {
			$this->renaming[$target->getPath()] = $source->getId();
		}
	}

	/** Move the pointer when a file move changed the file id. */
	private function onRename(Node $target): void {
		$path = $target->getPath();
		$oldId = $this->renaming[$path] ?? null;
		unset($this->renaming[$path]);
		if ($oldId === null || !$target instanceof File) {
			return;
		}
		$newId = $target->getId();
		if ($oldId === $newId) {
			return; // same storage: pointer unchanged
		}
		foreach ($this->store->mediaByFileIds([$oldId]) as $row) {
			$this->store->setMediaFile($row['id'], $newId);
		}
	}

	/** @return int[] */
	private function idsUnder(Node $node): array {
		if ($node instanceof File) {
			return [$node->getId()];
		}
		$ids = [];
		if ($node instanceof Folder) {
			foreach ($node->getDirectoryListing() as $child) {
				foreach ($this->idsUnder($child) as $id) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	private function ensureFolder(Folder $userFolder, string $absPath): void {
		$rel = $userFolder->getRelativePath($absPath);
		if ($rel === null) {
			throw new NotFoundException("outside the user folder: $absPath");
		}
		$dir = $userFolder;
		foreach (array_filter(explode('/', $rel)) as $part) {
			try {
				$next = $dir->get($part);
			} catch (NotFoundException) {
				$next = $dir->newFolder($part);
			}
			if (!$next instanceof Folder) {
				throw new NotFoundException("not a folder: $absPath");
			}
			$dir = $next;
		}
	}
}
