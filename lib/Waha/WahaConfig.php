<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Waha;

use OCA\ChatArchive\AppInfo\Application;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IUserManager;
use OCP\Security\ICrypto;

/**
 * Admin settings of the WAHA live sync, kept in app config. The API key is
 * stored encrypted and is never returned to the browser.
 *
 * arch: D23.1, D20.2
 */
class WahaConfig {
	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
		private IUserManager $users,
	) {
	}

	public function enabled(): bool {
		return $this->config->getAppValueBool('waha_enabled', false)
			&& $this->url() !== ''
			&& $this->hasKey();
	}

	public function url(): string {
		return rtrim($this->config->getAppValueString('waha_url', ''), '/');
	}

	public function apiKey(): string {
		$stored = $this->config->getAppValueString('waha_key', '');
		if ($stored === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (\Throwable) {
			return '';
		}
	}

	public function hasKey(): bool {
		return $this->apiKey() !== '';
	}

	public function mediaPerRun(): int {
		return $this->config->getAppValueInt('waha_media_per_run', 0);
	}

	/** True when an open chat can ask for its new messages every few seconds. Off by default. */
	public function realtime(): bool {
		return $this->enabled() && $this->config->getAppValueBool('waha_realtime', false);
	}

	/**
	 * PHP stores a digits-only session name as an int key. Cast a key to
	 * string before you compare it or give it to a string parameter.
	 *
	 * @return array<array-key,string> session => uid
	 */
	public function sessions(): array {
		$out = [];
		foreach ($this->config->getAppValueArray('waha_sessions', []) as $session => $uid) {
			if (is_string($uid)) {
				$out[$session] = $uid;
			}
		}
		return $out;
	}

	/** @return list<string> sessions assigned to this user, always strings */
	public function sessionsOf(string $uid): array {
		$out = [];
		foreach ($this->sessions() as $session => $owner) {
			if ($owner === $uid) {
				$out[] = (string)$session;
			}
		}
		return $out;
	}

	/**
	 * A null or empty key keeps the stored key.
	 *
	 * @param array<string,string> $sessions session => uid
	 * @throws \InvalidArgumentException code in the message: bad_url | bad_media_per_run | unknown_user | bad_session
	 */
	public function write(bool $enabled, string $url, #[\SensitiveParameter] ?string $apiKey, int $mediaPerRun, array $sessions,
		bool $realtime = false): void {
		if (preg_match('~^https?://[^\s/]+(:\d+)?(/.*)?$~', $url) !== 1) {
			throw new \InvalidArgumentException('bad_url');
		}
		if ($mediaPerRun < 0 || $mediaPerRun > 200) {
			throw new \InvalidArgumentException('bad_media_per_run');
		}
		foreach ($sessions as $session => $uid) {
			if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)$session) !== 1) {
				throw new \InvalidArgumentException('bad_session');
			}
			if (!is_string($uid) || !$this->users->userExists($uid)) {
				throw new \InvalidArgumentException('unknown_user');
			}
		}
		$this->config->setAppValueBool('waha_enabled', $enabled);
		$this->config->setAppValueString('waha_url', rtrim($url, '/'));
		if ($apiKey !== null && $apiKey !== '') {
			$this->config->setAppValueString('waha_key', $this->crypto->encrypt($apiKey), sensitive: true);
		}
		$this->config->setAppValueInt('waha_media_per_run', $mediaPerRun);
		$this->config->setAppValueArray('waha_sessions', $sessions);
		$this->config->setAppValueBool('waha_realtime', $realtime);
	}

	/** @return array{enabled:bool,url:string,hasKey:bool,mediaPerRun:int,sessions:array<array-key,string>,realtime:bool} never the key */
	public function describe(): array {
		return [
			'enabled' => $this->config->getAppValueBool('waha_enabled', false),
			'url' => $this->url(),
			'hasKey' => $this->hasKey(),
			'mediaPerRun' => $this->mediaPerRun(),
			'sessions' => $this->sessions(),
			'realtime' => $this->config->getAppValueBool('waha_realtime', false),
		];
	}
}
