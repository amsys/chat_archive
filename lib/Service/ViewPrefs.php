<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

use OCA\ChatArchive\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * Per-user toggles for the in-app message page and in-app search: show
 * deleted messages and gone chats, show chats archived on the phone. Both
 * default ON, so a user with no stored value sees everything (D6.I9).
 *
 * These toggles do not reach unified search or full text search (D6.I3):
 * only ApiController reads them.
 *
 * arch: D7.1, D7.2
 */
class ViewPrefs {
	public function __construct(
		private IUserConfig $config,
	) {
	}

	/** @return array{showDeleted: bool, showArchived: bool} */
	public function read(string $uid): array {
		return [
			'showDeleted' => $this->config->getValueBool($uid, Application::APP_ID, 'show_deleted', true),
			'showArchived' => $this->config->getValueBool($uid, Application::APP_ID, 'show_archived', true),
		];
	}

	/** A null leaves that toggle as it is. */
	public function write(string $uid, ?bool $showDeleted, ?bool $showArchived): void {
		if ($showDeleted !== null) {
			$this->config->setValueBool($uid, Application::APP_ID, 'show_deleted', $showDeleted);
		}
		if ($showArchived !== null) {
			$this->config->setValueBool($uid, Application::APP_ID, 'show_archived', $showArchived);
		}
	}
}
