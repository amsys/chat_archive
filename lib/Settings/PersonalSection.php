<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Settings;

use OCA\ChatArchive\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {
	public function __construct(
		private IURLGenerator $url,
		private IL10N $l,
	) {
	}

	public function getIcon(): string {
		return $this->url->imagePath(Application::APP_ID, 'app-dark.svg');
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Chat Archive');
	}

	public function getPriority(): int {
		return 80;
	}
}
