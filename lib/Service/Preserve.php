<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

use OCA\ChatArchive\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * Per-user preserve switch. With the switch on, the app never removes
 * archive rows on request and always copies a deleted linked file back:
 * the delete policy of every source of the user acts as "restore".
 * The switch defaults ON, so a user with no stored value is protected.
 * This is invariant I20.8 of docs/DEVELOPER-GUIDE.md.
 */
class Preserve {
	private const KEY = 'preserve';

	public function __construct(
		private IUserConfig $config,
	) {
	}

	public function read(string $uid): bool {
		return $this->config->getValueBool($uid, Application::APP_ID, self::KEY, true);
	}

	public function write(string $uid, bool $on): void {
		$this->config->setValueBool($uid, Application::APP_ID, self::KEY, $on);
	}
}
