<?php

declare(strict_types=1);

namespace OCA\ChatArchive\FullTextSearch;

use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OC\FullTextSearch\Model\SearchTemplate;
use OCA\ChatArchive\AppInfo\Application;
use OCA\ChatArchive\Db\ArchiveStore;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\FullTextSearchService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\IFullTextSearchProvider;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IIndexOptions;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\FullTextSearch\Model\ISearchTemplate;
use OCP\IURLGenerator;

/**
 * Offers messages to the Nextcloud fulltextsearch app. Chunked by source,
 * INDEX_PAGE messages at a time, so one chat with 80k messages cannot pull
 * the whole archive into memory in one call.
 *
 * loadProvider/unloadProvider/setRunner/setIndexOptions/onInitializingIndex/
 * onResettingIndex/improveSearchRequest/improveSearchResult/fillIndexDocument
 * are no-ops below: nothing here needs per-run state, request tuning, or
 * deferred content loading (a chat message is under 1 KB).
 */
class ChatProvider implements IFullTextSearchProvider {
	public function __construct(
		private IAppConfig $config,
		private SourceMapper $sources,
		private ArchiveStore $store,
		private IURLGenerator $url,
	) {
	}

	public function getId(): string {
		return FullTextSearchService::PROVIDER_ID;
	}

	public function getName(): string {
		return 'Chat Archive';
	}

	/** @return array<string, mixed> */
	public function getConfiguration(): array {
		return [];
	}

	public function getSearchTemplate(): ISearchTemplate {
		return new SearchTemplate();
	}

	public function loadProvider(): void {
	}

	public function setRunner(IRunner $runner): void {
	}

	public function setIndexOptions(IIndexOptions $options): void {
	}

	/**
	 * One chunk per (source, 1000-message page). Empty when the admin toggle
	 * is off: no chunks means fulltextsearch indexes nothing for this provider,
	 * which keeps isProviderIndexed() honest with isEnabled().
	 * @return string[]
	 */
	public function generateChunks(string $userId): array {
		if (!$this->config->getAppValueBool(FullTextSearchService::CONFIG_KEY, false)) {
			return [];
		}
		$sourceIds = array_map(static fn ($s) => $s->getId(), $this->sources->findByUser($userId));
		$counts = $this->store->messageCounts($sourceIds);
		$chunks = [];
		foreach ($counts as $sourceId => $count) {
			// ponytail: OFFSET paging, keyset by id if a source ever passes ~1M messages
			for ($page = 0; $page * ArchiveStore::INDEX_PAGE < $count; $page++) {
				$chunks[] = $sourceId . ':' . $page;
			}
		}
		return $chunks;
	}

	/**
	 * @return IIndexDocument[]
	 */
	public function generateIndexableDocuments(string $userId, string $chunk): array {
		[$sourceId, $page] = array_map('intval', explode(':', $chunk, 2));
		try {
			// a chunk string is untrusted input at this boundary; re-verify ownership
			$this->sources->findForUser($sourceId, $userId);
		} catch (DoesNotExistException) {
			return [];
		}
		$rows = $this->store->messagesForIndex($sourceId, ArchiveStore::INDEX_PAGE, $page * ArchiveStore::INDEX_PAGE);
		$documents = [];
		foreach ($rows as $row) {
			if (trim($this->content($row)) === '') {
				continue;
			}
			$documents[] = $this->toDocument($row, $userId, $sourceId);
		}
		return $documents;
	}

	/** Archived messages are immutable; only a contact-name refresh can stale a title. */
	public function isDocumentUpToDate(IIndexDocument $document): bool {
		return true;
	}

	public function fillIndexDocument(IIndexDocument $document): void {
	}

	public function updateDocument(IIndex $index): IIndexDocument {
		$messageId = (int)$index->getDocumentId();
		$row = $this->store->messageForIndex($messageId);
		if ($row === null) {
			$index->setStatus(IIndex::INDEX_REMOVE);
			$orphan = new IndexDocument(FullTextSearchService::PROVIDER_ID, (string)$messageId);
			$orphan->setIndex($index); // the remove status travels on the index, not the document
			return $orphan;
		}
		$document = $this->toDocument($row, $row['userId'], $row['sourceId']);
		$document->setIndex($index);
		return $document;
	}

	public function onInitializingIndex(IFullTextSearchPlatform $platform): void {
	}

	public function onResettingIndex(IFullTextSearchPlatform $platform): void {
	}

	public function improveSearchRequest(ISearchRequest $searchRequest): void {
	}

	public function improveSearchResult(ISearchResult $searchResult): void {
	}

	public function unloadProvider(): void {
	}

	/**
	 * Body, media caption and media file name, one per line.
	 * arch: D29.4
	 * @param array<string, mixed> $row
	 */
	private function content(array $row): string {
		$parts = [];
		foreach (['body', 'caption', 'fileName'] as $key) {
			$value = trim((string)($row[$key] ?? ''));
			if ($value !== '') {
				$parts[] = $value;
			}
		}
		return implode("\n", $parts);
	}

	/** @param array<string, mixed> $row */
	private function toDocument(array $row, string $userId, int $sourceId): IIndexDocument {
		$document = new IndexDocument(FullTextSearchService::PROVIDER_ID, (string)$row['id']);
		$document->setAccess(new DocumentAccess($userId));
		$document->setTitle($row['chatName']);
		$document->setContent($this->content($row));
		$document->setModifiedTime(intdiv($row['sentAt'], 1000)); // sent_at is stored in unix ms
		$document->setLink($this->url->linkToRoute(Application::APP_ID . '.page.index'));
		$document->addTag('src_' . $sourceId);
		$document->initHash();
		return $document;
	}
}
