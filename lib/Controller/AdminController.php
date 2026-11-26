<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Controller;

use OCA\ChatArchive\AppInfo\Application;
use OCA\ChatArchive\Db\Source;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Waha\WahaClient;
use OCA\ChatArchive\Waha\WahaConfig;
use OCA\ChatArchive\Waha\WahaException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Admin status and WAHA settings. Admin only: every route needs an admin.
 * No response holds the API key or a body of the WAHA server.
 */
class AdminController extends OCSController {
	/** English text of each error code of WahaConfig::write(). */
	private const ERROR_TEXTS = [
		'bad_url' => 'the WAHA URL is not valid',
		'bad_media_per_run' => 'media files per run must be between 0 and 200',
		'unknown_user' => 'a session is assigned to a user that does not exist',
		'bad_session' => 'a session name is not valid',
	];

	public function __construct(
		IRequest $request,
		private SourceMapper $sources,
		private WahaConfig $waha,
		private WahaClient $client,
		private IAppConfig $systemConfig,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Status data of the admin page; also the initial state of Settings\Admin.
	 *
	 * @return array{jobs: array{mode: string, lastRun: int}, sources: array{total: int, live: int, failing: list<array{uid: string, label: string, error: string}>}, waha: array<string, mixed>}
	 */
	public function statusData(): array {
		$all = $this->sources->findAll();
		$live = 0;
		$failing = [];
		foreach ($all as $source) {
			if ($source->getSyncSession() !== null) {
				$live++;
			}
			$error = $source->getSyncError() ?? $source->getLastError();
			if ($error !== null && $error !== '') {
				$failing[] = [
					'uid' => $source->getUserId(),
					'label' => $source->getLabel(),
					'error' => $source->getSyncError() ?? mb_substr($error, 0, 120),
				];
			}
		}
		return [
			'jobs' => [
				'mode' => $this->systemConfig->getValueString('core', 'backgroundjobs_mode', 'ajax'),
				'lastRun' => $this->systemConfig->getValueInt('core', 'lastcron', 0),
			],
			'sources' => ['total' => count($all), 'live' => $live, 'failing' => $failing],
			'waha' => $this->waha->describe(),
		];
	}

	#[ApiRoute(verb: 'GET', url: '/admin/status')]
	public function status(): DataResponse {
		return new DataResponse($this->statusData());
	}

	/**
	 * A request that leaves out the key, the media limit, the sessions or the
	 * realtime switch keeps the stored value of that field.
	 * @param ?array<string, string> $sessions session name => user id
	 */
	#[ApiRoute(verb: 'PUT', url: '/admin/waha')]
	public function saveWaha(bool $enabled, string $url, #[\SensitiveParameter] ?string $apiKey = null,
		?int $mediaPerRun = null, ?array $sessions = null, ?bool $realtime = null): DataResponse {
		try {
			$this->waha->write($enabled, $url, $apiKey, $mediaPerRun ?? $this->waha->mediaPerRun(),
				$sessions ?? $this->waha->sessions(), $realtime ?? $this->waha->describe()['realtime']);
		} catch (\InvalidArgumentException $e) {
			$code = $e->getMessage();
			return new DataResponse(['error' => self::ERROR_TEXTS[$code] ?? $code, 'code' => $code], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->waha->describe());
	}

	#[ApiRoute(verb: 'POST', url: '/admin/waha/test')]
	public function testWaha(): DataResponse {
		try {
			$version = $this->client->version();
			$sessions = array_map(
				static fn (array $s) => ['name' => (string)$s['name'], 'status' => (string)$s['status']],
				$this->client->sessions(),
			);
		} catch (WahaException $e) {
			return new DataResponse(['ok' => false, 'code' => $e->errorCode]);
		} catch (\Throwable) {
			return new DataResponse(['ok' => false, 'code' => 'waha_bad_response']);
		}
		return new DataResponse([
			'ok' => true,
			'version' => $version['version'],
			'engine' => $version['engine'],
			'tier' => $version['tier'],
			'sessions' => $sessions,
		]);
	}
}
