<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Search;

use OCA\ChatArchive\AppInfo\Application;
use OCA\ChatArchive\Db\ArchiveStore;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\ContactNames;
use OCA\ChatArchive\Service\DisplayText;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\FilterDefinition;
use OCP\Search\IFilteringProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified search provider for chat messages. It offers the caller's own
 * archived messages to the Nextcloud global search box and, through the
 * same OCS route, to agent tools.
 *
 * This provider uses the SQL scan only (ArchiveStore::search()), not the
 * full text search path. It does not change ArchiveStore or ApiController.
 */
class MessageSearchProvider implements IFilteringProvider {
	public function __construct(
		private SourceMapper $sources,
		private ArchiveStore $store,
		private ContactNames $names,
		private IL10N $l,
		private IURLGenerator $url,
		private IDateTimeFormatter $dates,
	) {
	}

	public function getId(): string {
		return 'chat_archive_messages';
	}

	public function getName(): string {
		return $this->l->t('Chat Archive');
	}

	/**
	 * Rank the provider higher when the user already views the app.
	 * @param array<string, mixed> $routeParameters
	 */
	public function getOrder(string $route, array $routeParameters): ?int {
		return str_starts_with($route, Application::APP_ID . '.') ? 60 : 45;
	}

	/** @return string[] */
	public function getSupportedFilters(): array {
		return ['term', 'since', 'until', 'chat', 'source', 'kind', 'who'];
	}

	/** @return string[] */
	public function getAlternateIds(): array {
		return [];
	}

	/** @return list<FilterDefinition> */
	public function getCustomFilters(): array {
		return [
			new FilterDefinition('chat', FilterDefinition::TYPE_STRING),
			new FilterDefinition('source', FilterDefinition::TYPE_STRING),
			new FilterDefinition('kind', FilterDefinition::TYPE_STRING),
			new FilterDefinition('who', FilterDefinition::TYPE_STRING),
		];
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$name = $this->getName();
		$sourceIds = array_map(static fn ($s) => $s->getId(), $this->sources->findByUser($user->getUID()));
		$wantedSource = $this->intFilter($query, 'source');
		if ($wantedSource !== null) {
			// A source filter that the caller does not own must find nothing, not another user's messages.
			$sourceIds = in_array($wantedSource, $sourceIds, true) ? [$wantedSource] : [];
		}
		$term = trim($query->getTerm());
		$filters = array_filter([
			'chat' => $this->intFilter($query, 'chat'),
			'from' => $this->msFilter($query, 'since'),
			'to' => $this->msFilter($query, 'until'),
			'kind' => $this->stringFilter($query, 'kind'),
			'who' => $this->stringFilter($query, 'who'),
		]);
		if ($sourceIds === [] || ($term === '' && $filters === [])) {
			return SearchResult::complete($name, []);
		}
		$limit = $query->getLimit();
		$offset = (int)($query->getCursor() ?? 0);
		// ArchiveStore::search() fetches one row past $limit to signal more pages;
		// keep only $limit rows here, the same way ApiController::search() does.
		$hits = $this->store->search($sourceIds, $term, $filters, $limit, $offset);
		$more = count($hits) > $limit;
		$hits = array_slice($hits, 0, $limit);
		$chats = $this->store->chatsByIds(array_unique(array_column($hits, 'chatId')));
		$entries = array_map(fn (array $hit) => $this->toEntry($hit, $chats[$hit['chatId']]), $hits);
		return $more
			? SearchResult::paginated($name, $entries, $offset + count($entries))
			: SearchResult::complete($name, $entries);
	}

	/**
	 * @param array<string, mixed> $hit an ArchiveStore::search() row
	 * @param array<string, mixed> $chat an ArchiveStore::chatsByIds() row
	 */
	private function toEntry(array $hit, array $chat): SearchResultEntry {
		$time = $this->dates->formatDateTime(intdiv((int)$hit['sentAt'], 1000));
		$body = trim((string)($hit['body'] ?? ''));
		$text = $body !== '' ? mb_substr($body, 0, 200, 'UTF-8') : (string)$hit['kind'];
		return new SearchResultEntry(
			'',
			$this->chatName($chat),
			$time . ': ' . $text,
			$this->url->linkToRouteAbsolute(Application::APP_ID . '.page.index'),
			'icon-comment',
		);
	}

	/**
	 * Copy of ApiController::chatName(), kept private here so ApiController
	 * stays unchanged. Private chats resolve from the jid, not the stored
	 * name, and the resolved name is cached on the row.
	 * @param array<string, mixed> $chat
	 */
	private function chatName(array $chat): string {
		if ($chat['isGroup']) {
			return (string)DisplayText::name($chat['name'] ?: $chat['platformChatId']);
		}
		if ($chat['displayName'] === null) {
			$resolved = $this->names->nameFor($chat['platformChatId']);
			$this->store->setChatDisplayName($chat['id'], $resolved ?? '');
			$chat['displayName'] = $resolved ?? '';
		}
		return (string)DisplayText::name($chat['displayName'] !== '' ? $chat['displayName'] : ContactNames::fallback($chat['platformChatId']));
	}

	/** Int value of a custom string filter, or null when the caller did not set it. */
	private function intFilter(ISearchQuery $query, string $name): ?int {
		$value = $query->getFilter($name)?->get();
		return $value === null ? null : (int)$value;
	}

	/** String value of a custom string filter, or '' when the caller did not set it. */
	private function stringFilter(ISearchQuery $query, string $name): string {
		$value = $query->getFilter($name)?->get();
		return $value === null ? '' : (string)$value;
	}

	/** Unix ms of a built-in since/until filter, or null when the caller did not set it. */
	private function msFilter(ISearchQuery $query, string $name): ?int {
		$value = $query->getFilter($name)?->get();
		return $value instanceof \DateTimeImmutable ? $value->getTimestamp() * 1000 : null;
	}
}
