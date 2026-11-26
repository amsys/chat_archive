<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

use RuntimeException;

/** Thrown by ImportService::run() and SyncService::run() when an import or a sync already holds the source. */
class SourceBusyException extends RuntimeException {
}
