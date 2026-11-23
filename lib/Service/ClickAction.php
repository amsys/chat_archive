<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

use OCA\ChatArchive\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * What a left click on a file in a chat does. One per user.
 *
 * - view: the Nextcloud viewer, or a download when the viewer has no handler
 * - nc: the desktop client (nc://open), for users with a synced folder
 * - custom: a URL template the user writes; {path} is the URL-encoded path
 */
class ClickAction {
	public const VIEW = 'view';
	public const ACTIONS = [self::VIEW, 'nc', 'custom'];

	public function __construct(
		private IUserConfig $config,
	) {
	}

	/** @return array{action: string, template: string} */
	public function read(string $uid): array {
		return [
			'action' => $this->config->getValueString($uid, Application::APP_ID, 'click_action', self::VIEW),
			'template' => $this->config->getValueString($uid, Application::APP_ID, 'click_template', ''),
		];
	}

	/** @throws \InvalidArgumentException on an unknown action or a template that is not a URL with a scheme */
	public function write(string $uid, string $action, string $template): void {
		if (!in_array($action, self::ACTIONS, true)) {
			throw new \InvalidArgumentException("unknown click action: $action");
		}
		$template = trim($template);
		if ($template !== '' && (strlen($template) > 1000 || !preg_match('#^(?!javascript:|data:)[a-z][a-z0-9+.-]*:#i', $template))) {
			throw new \InvalidArgumentException('the template must be a URL such as myhandler://{path}');
		}
		$this->config->setValueString($uid, Application::APP_ID, 'click_action', $action);
		$this->config->setValueString($uid, Application::APP_ID, 'click_template', $template);
	}
}
