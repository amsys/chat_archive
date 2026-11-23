<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Settings;

use OCA\ChatArchive\AppInfo\Application;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\ClickAction;
use OCA\ChatArchive\Service\Preserve;
use OCA\ChatArchive\Source\ProviderRegistry;
use OCA\ChatArchive\Waha\WahaConfig;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Personal settings page. The initial state has the keys of
 * GET /settings/sources. The sources have no `backupAt` here: only
 * that route adds it.
 *
 * arch: D26.1
 */
class Personal implements ISettings {
	public function __construct(
		private IInitialState $state,
		private SourceMapper $sources,
		private ProviderRegistry $providers,
		private ClickAction $click,
		private IUserSession $session,
		private Preserve $preserve,
		private WahaConfig $waha,
	) {
	}

	public function getForm(): TemplateResponse {
		$uid = $this->session->getUser()?->getUID() ?? '';
		$this->state->provideInitialState('sources', array_map(
			static fn ($s) => $s->toArray(), $this->sources->findByUser($uid)));
		$this->state->provideInitialState('providers', $this->providers->describe());
		$this->state->provideInitialState('clickAction', $this->click->read($uid));
		$this->state->provideInitialState('preserve', $this->preserve->read($uid));
		$this->state->provideInitialState('live', [
			'enabled' => $this->waha->enabled(),
			'sessions' => $this->waha->sessionsOf($uid),
		]);
		Util::addScript(Application::APP_ID, 'chat_archive-settings');
		return new TemplateResponse(Application::APP_ID, 'settings', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
