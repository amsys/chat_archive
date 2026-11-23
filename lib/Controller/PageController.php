<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Controller;

use OCA\ChatArchive\AppInfo\Application;
use OCA\ChatArchive\Db\SourceMapper;
use OCA\ChatArchive\Service\ClickAction;
use OCA\ChatArchive\Service\ViewPrefs;
use OCA\ChatArchive\Source\ProviderRegistry;
use OCA\ChatArchive\Waha\WahaConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $state,
		private SourceMapper $sources,
		private ProviderRegistry $providers,
		private ClickAction $click,
		private ViewPrefs $viewPrefs,
		private WahaConfig $waha,
		private IEventDispatcher $dispatcher,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		$this->state->provideInitialState('sources', array_map(
			static fn ($s) => $s->toArray(), $this->sources->findByUser((string)$this->userId)));
		$this->state->provideInitialState('providers', $this->providers->describe());
		$this->state->provideInitialState('clickAction', $this->click->read((string)$this->userId));
		$this->state->provideInitialState('viewPrefs', $this->viewPrefs->read((string)$this->userId));
		$this->state->provideInitialState('realtime', $this->waha->realtime());
		if (class_exists(\OCA\Viewer\Event\LoadViewer::class)) {
			$this->dispatcher->dispatchTyped(new \OCA\Viewer\Event\LoadViewer()); // the viewer app registers its script
		}
		Util::addScript(Application::APP_ID, 'chat_archive-main');
		return new TemplateResponse(Application::APP_ID, 'main');
	}
}
