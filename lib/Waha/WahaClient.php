<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Waha;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;

/**
 * Read-only HTTP client for the WAHA REST API. It sends GET requests only
 * and turns every failure into a WahaException without a previous exception.
 */
class WahaClient {
	public function __construct(
		private IClientService $clients,
		private WahaConfig $config,
	) {
	}

	/** @return array{version:string,engine:string,tier:string} */
	public function version(): array {
		$d = $this->json('/api/server/version', [], false);
		return [
			'version' => (string)($d['version'] ?? ''),
			'engine' => (string)($d['engine'] ?? ''),
			'tier' => (string)($d['tier'] ?? ''),
		];
	}

	/** @return list<array{name:string,status:string}> */
	public function sessions(): array {
		$out = [];
		foreach ($this->list('/api/sessions', ['all' => 'true'], false) as $s) {
			$out[] = ['name' => (string)($s['name'] ?? ''), 'status' => (string)($s['status'] ?? '')];
		}
		return $out;
	}

	/** @return array{status:string, me:?string} me = raw id or null */
	public function session(string $name): array {
		$d = $this->json('/api/sessions/' . rawurlencode($name), [], true);
		$me = $d['me']['id'] ?? null;
		return ['status' => (string)($d['status'] ?? ''), 'me' => is_string($me) && $me !== '' ? $me : null];
	}

	/** @return list<array<string,mixed>> */
	public function chatsOverview(string $session, int $limit, int $offset): array {
		return $this->list('/api/' . rawurlencode($session) . '/chats/overview', ['limit' => $limit, 'offset' => $offset], true);
	}

	/** @return list<array<string,mixed>> */
	public function messages(string $session, string $chatId, int $gteSec, int $limit, int $offset): array {
		return $this->list(
			'/api/' . rawurlencode($session) . '/chats/' . rawurlencode($chatId) . '/messages',
			[
				'limit' => $limit,
				'offset' => $offset,
				'sortBy' => 'timestamp',
				'sortOrder' => 'asc',
				'filter.timestamp.gte' => $gteSec,
				'downloadMedia' => 'false',
			],
			true,
		);
	}

	/** @return array<string,mixed> */
	public function message(string $session, string $chatId, string $keyId): array {
		return $this->json(
			'/api/' . rawurlencode($session) . '/chats/' . rawurlencode($chatId) . '/messages/' . rawurlencode($keyId),
			['downloadMedia' => 'true'],
			true,
		);
	}

	/**
	 * Keeps only path and query of the media URL and fetches it from the configured WAHA URL.
	 * The path must start with a slash. The joined URL must have the scheme, user info, host
	 * and port of the configured URL, else the key could go to another host.
	 *
	 * @return resource
	 */
	public function download(string $mediaUrl) {
		$parts = parse_url($mediaUrl);
		$path = is_array($parts) ? ($parts['path'] ?? '') : '';
		$query = is_array($parts) ? ($parts['query'] ?? '') : '';
		if (!str_starts_with($path, '/')) {
			throw new WahaException('waha_bad_response');
		}
		$base = $this->config->url();
		$url = $base . $path . ($query !== '' ? '?' . $query : '');
		$origin = self::origin($url);
		if ($origin === null || $origin !== self::origin($base)) {
			throw new WahaException('waha_bad_response');
		}
		$body = $this->get($url, false, true);
		if (!is_resource($body)) {
			throw new WahaException('waha_bad_response');
		}
		return $body;
	}

	/** @return ?string the phone-number id ('<digits>@c.us') of a lid, or null when WAHA has none */
	public function lid(string $session, string $lid): ?string {
		$d = $this->json('/api/' . rawurlencode($session) . '/lids/' . rawurlencode($lid), [], true);
		$pn = $d['pn'] ?? null;
		return is_string($pn) && $pn !== '' ? $pn : null;
	}

	/**
	 * Scheme, user info, host and port of a URL, or null when it has no host.
	 *
	 * @return ?list<string|int|null>
	 */
	private static function origin(string $url): ?array {
		$p = parse_url($url);
		if (!is_array($p) || ($p['host'] ?? '') === '') {
			return null;
		}
		return [$p['scheme'] ?? null, $p['user'] ?? null, $p['pass'] ?? null, $p['host'], $p['port'] ?? null];
	}

	/**
	 * @param array<string,scalar> $query
	 * @return array<string,mixed>
	 */
	private function json(string $path, array $query, bool $sessionPath): array {
		$d = $this->decode($this->get($this->build($path, $query), $sessionPath, false));
		if (!is_array($d)) {
			throw new WahaException('waha_bad_response');
		}
		return $d;
	}

	/**
	 * @param array<string,scalar> $query
	 * @return list<array<string,mixed>>
	 */
	private function list(string $path, array $query, bool $sessionPath): array {
		$d = $this->decode($this->get($this->build($path, $query), $sessionPath, false));
		if (!is_array($d) || !array_is_list($d)) {
			throw new WahaException('waha_bad_response');
		}
		foreach ($d as $row) {
			if (!is_array($row)) {
				throw new WahaException('waha_bad_response');
			}
		}
		return $d;
	}

	/** @param array<string,scalar> $query */
	private function build(string $path, array $query): string {
		$url = $this->config->url() . $path;
		return $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	private function decode(mixed $body): mixed {
		if (!is_string($body)) {
			throw new WahaException('waha_bad_response');
		}
		try {
			return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new WahaException('waha_bad_response');
		}
	}

	/** @return string|resource */
	private function get(string $url, bool $sessionPath, bool $stream): mixed {
		$options = [
			'timeout' => 20,
			'nextcloud' => ['allow_local_address' => true],
			'headers' => ['X-Api-Key' => $this->config->apiKey()],
		];
		if ($stream) {
			$options['stream'] = true;
		}
		$code = null;
		try {
			$response = $this->clients->newClient()->get($url, $options);
			$body = $response->getBody();
			$status = $response->getStatusCode();
		} catch (\Throwable $e) {
			$code = $this->codeOf($e, $sessionPath);
		}
		if ($code !== null) {
			// The exception is dropped here: its trace holds the key (I20.6).
			throw new WahaException($code);
		}
		if ($status >= 500) {
			throw new WahaException('waha_down');
		}
		if ($status >= 400) {
			$failure = $this->statusCode($status, $sessionPath);
			throw new WahaException($failure);
		}
		return $body ?? '';
	}

	private function codeOf(\Throwable $e, bool $sessionPath): string {
		for ($t = $e; $t !== null; $t = $t->getPrevious()) {
			if ($t instanceof LocalServerException) {
				return 'waha_local_blocked';
			}
		}
		if (method_exists($e, 'getResponse')) {
			$r = $e->getResponse();
			if (is_object($r) && method_exists($r, 'getStatusCode')) {
				$status = (int)$r->getStatusCode();
				return $status >= 500 ? 'waha_down' : $this->statusCode($status, $sessionPath);
			}
		}
		return 'waha_down';
	}

	private function statusCode(int $status, bool $sessionPath): string {
		if ($status === 401 || $status === 403) {
			return 'waha_auth';
		}
		if ($status === 404 && $sessionPath) {
			return 'waha_session_missing';
		}
		return 'waha_bad_response';
	}
}
