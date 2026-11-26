<?php

declare(strict_types=1);

namespace OCA\ChatArchive\AppInfo;

use OCA\ChatArchive\Listener\BackupListener;
use OCA\ChatArchive\Listener\FileListener;
use OCA\ChatArchive\Search\MessageSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'chat_archive';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BeforeNodeDeletedEvent::class, FileListener::class);
		// The id before a move is readable only before the move.
		$context->registerEventListener(BeforeNodeRenamedEvent::class, FileListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FileListener::class);
		$context->registerEventListener(NodeCreatedEvent::class, BackupListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, BackupListener::class);
		$context->registerSearchProvider(MessageSearchProvider::class);
	}

	public function boot(IBootContext $context): void {
	}
}
