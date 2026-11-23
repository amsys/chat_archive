<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Settings;

use OCA\ChatArchive\AppInfo\Application;
use OCA\ChatArchive\Controller\AdminController;
use OCA\ChatArchive\Service\FullTextSearchService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private IInitialState $state,
		private FullTextSearchService $fts,
		private AdminController $admin,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->state->provideInitialState('fulltextSearch', $this->fts->status());
		$this->state->provideInitialState('adminStatus', $this->admin->statusData());
		Util::addScript(Application::APP_ID, 'chat_archive-settings');
		return new TemplateResponse(Application::APP_ID, 'admin', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
