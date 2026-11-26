<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndex;
use Psr\Log\LoggerInterface;

/**
 * Routes chat search through the Nextcloud fulltextsearch app when the admin
 * turned it on and a platform is actually indexing. The IFullTextSearchManager
 * is only ever touched here: if it were ever unbound, the blast radius stays
 * inside this class instead of 500ing every ApiController::search() call.
 */
class FullTextSearchService {
	public const PROVIDER_ID = 'chat_archive';
	public const CONFIG_KEY = 'fulltext_search';
	private const REMOVE_CHUNK = 1000;

	public function __construct(
		private IAppConfig $config,
		private IFullTextSearchManager $manager,
		private LoggerInterface $log,
	) {
	}

	/** @return array{enabled:bool, available:bool, indexed:bool} for the admin form */
	public function status(): array {
		$enabled = $this->config->getAppValueBool(self::CONFIG_KEY, false);
		$available = false;
		$indexed = false;
		try {
			$available = $this->manager->isAvailable();
			$indexed = $available && $this->manager->isProviderIndexed(self::PROVIDER_ID);
		} catch (\Throwable) {
			// fulltextsearch app absent or misconfigured; stays unavailable
		}
		return ['enabled' => $enabled, 'available' => $available, 'indexed' => $indexed];
	}

	public function isEnabled(): bool {
		$s = $this->status();
		return $s['enabled'] && $s['available'] && $s['indexed'];
	}

	/**
	 * Message ids, best match first: one source when $sourceId is set, else
	 * every source of the user (documents carry the owner as access). Empty on
	 * no match AND on any failure (stale index, platform down) - the caller
	 * falls back to LIKE either way, so a failure here must never look like
	 * "no results".
	 *
	 * The index has no offset, so it must return the ids of every earlier page
	 * as well. This method asks the index for $offset + $limit ids; the caller
	 * then skips $offset.
	 * @return int[]
	 */
	public function searchIds(string $userId, string $term, ?int $sourceId, int $limit, int $offset = 0): array {
		$request = ['providers' => self::PROVIDER_ID, 'search' => $term, 'size' => $offset + $limit];
		if ($sourceId !== null) {
			$request['tags'] = ['src_' . $sourceId];
		}
		try {
			$results = $this->manager->search($request, $userId);
		} catch (\Throwable) {
			return [];
		}
		$ids = [];
		foreach ($results as $result) {
			foreach ($result->getDocuments() as $document) {
				$id = $document->getId();
				if (is_numeric($id)) {
					$ids[] = (int)$id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Remove a deleted source's documents from the full-text index. Guarded by
	 * isAvailable() (the platform is present), not isEnabled() or the app
	 * toggle: documents stay indexed after the toggle goes off, so a source
	 * deleted while the toggle is off must still be forgotten. A platform
	 * failure is logged and swallowed - the delete itself must never fail
	 * because the search index could not be reached.
	 * @param int[] $messageIds
	 */
	public function forgetSource(int $sourceId, array $messageIds): void {
		if ($messageIds === []) {
			return;
		}
		try {
			if (!$this->manager->isAvailable()) {
				return;
			}
			foreach (array_chunk($messageIds, self::REMOVE_CHUNK) as $chunk) {
				$this->manager->updateIndexesStatus(
					self::PROVIDER_ID,
					array_map('strval', $chunk),
					IIndex::INDEX_REMOVE,
					true,
				);
			}
		} catch (\Throwable $e) {
			$this->log->warning('chat_archive: could not remove source {sourceId} from the full-text index', [
				'sourceId' => $sourceId,
				'exception' => $e,
			]);
		}
	}
}
