<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

use OCA\ChatArchive\Db\ArchiveStore;
use OCA\ChatArchive\Db\Source;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * Gives media rows a Nextcloud file id. The id is the pointer: it survives
 * renames and moves anywhere inside the user's storage, so the viewer can
 * open a file wherever it went. Matching order: the exact path the phone
 * used, that path under WhatsApp/, then the bare file name (a flat auto-upload
 * folder), also without a trailing dot or space, which Nextcloud refuses in a
 * name. Only rows without a pointer, or whose pointer no longer resolves, are
 * touched. The walk skips a node owned by someone other than the source
 * folder's owner (a share received inside the source folder), subtree and
 * all, so linking never reaches a file that is not the source owner's own.
 * A storage that reports no owner for the source folder itself falls back
 * to the source's configured user id as the expected owner; a node that
 * itself reports no owner is accepted only inside such an owner-less
 * folder, never inside a folder that does have an owner (see isForeign()).
 */
class MediaLinker {
	public function __construct(
		private ArchiveStore $store,
		private IRootFolder $root,
	) {
	}

	/** @return int rows that got a (new) pointer */
	public function link(Source $source, Folder $folder): int {
		[$byPath, $byName, $byId] = $this->index($folder, $source->getUserId());
		$userFolder = $this->root->getUserFolder($source->getUserId());
		$linked = 0;
		foreach ($this->store->mediaOfSource($source->getId()) as $row) {
			if ($row['file_id'] !== null) {
				if (isset($byId[$row['file_id']]) || $userFolder->getFirstNodeById($row['file_id']) !== null) {
					continue; // pointer still resolves
				}
			}
			$id = $this->match($row['src_path'], $row['file_name'], $byPath, $byName);
			if ($id !== null && $id !== $row['file_id']) {
				$this->store->setMediaFile($row['id'], $id);
				$linked++;
			}
		}
		return $linked;
	}

	/**
	 * Paths, names and ids are kept apart: a file named "12345" at the folder
	 * root must not answer the "is file id 12345 in this folder?" lookup.
	 * @return array{0: array<string, int>, 1: array<string, int>, 2: array<int, true>}
	 */
	private function index(Folder $folder, string $sourceUserId): array {
		$byPath = [];
		$byName = [];
		$byId = [];
		$base = $folder->getPath();
		$owner = $folder->getOwner()?->getUID();
		$walk = function (Folder $dir) use (&$walk, &$byPath, &$byName, &$byId, $base, $owner, $sourceUserId): void {
			foreach ($dir->getDirectoryListing() as $node) {
				if (self::isForeign($node->getOwner()?->getUID(), $owner, $sourceUserId)) {
					continue; // a share received inside the source folder: not the owner's file
				}
				if ($node instanceof Folder) {
					$walk($node);
					continue;
				}
				if (!$node instanceof File) {
					continue;
				}
				$id = $node->getId();
				$rel = ltrim(substr($node->getPath(), strlen($base)), '/');
				$byPath[$rel] = $id;
				$byId[$id] = true; // "is this id inside the source folder?" lookups
				$byName[$node->getName()] ??= $id;
			}
		};
		$walk($folder);
		return [$byPath, $byName, $byId];
	}

	/**
	 * @param array<string, int> $byPath
	 * @param array<string, int> $byName
	 */
	private function match(?string $srcPath, ?string $fileName, array $byPath, array $byName): ?int {
		if ($srcPath !== null && $srcPath !== '') {
			foreach ([$srcPath, 'WhatsApp/' . $srcPath] as $p) {
				if (isset($byPath[$p])) {
					return $byPath[$p];
				}
			}
			$id = $this->byName(basename($srcPath), $byName);
			if ($id !== null) {
				return $id;
			}
		}
		return $fileName !== null ? $this->byName($fileName, $byName) : null;
	}

	/** @param array<string, int> $byName */
	private function byName(string $name, array $byName): ?int {
		foreach (self::nameCandidates($name) as $n) {
			if (isset($byName[$n])) {
				return $byName[$n];
			}
		}
		return null;
	}

	/**
	 * The owner rule, as one pure function so a unit check covers it
	 * without a Folder/Node mock. $folderOwner is the source folder's owner
	 * id, or null when the storage reports none. $sourceUserId is the
	 * source's configured user, the fallback expected owner used only when
	 * the folder itself has no owner. A node with no owner is foreign unless
	 * the folder has no owner either, in which case it is accepted; a node
	 * that does report an owner is foreign unless that owner matches the
	 * folder's owner (or, lacking one, the source's user id).
	 */
	public static function isForeign(?string $nodeOwner, ?string $folderOwner, string $sourceUserId): bool {
		if ($nodeOwner === null) {
			return $folderOwner !== null;
		}
		return $nodeOwner !== ($folderOwner ?? $sourceUserId);
	}

	/**
	 * The names a backup name can have in Nextcloud: as it is, then without
	 * the trailing dots and spaces that Nextcloud refuses in a file name.
	 * WhatsApp documents without an extension end in a dot.
	 * @return list<string>
	 */
	public static function nameCandidates(string $name): array {
		$stripped = rtrim($name, '. ');
		return ($stripped === '' || $stripped === $name) ? [$name] : [$name, $stripped];
	}
}
