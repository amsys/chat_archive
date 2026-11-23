<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

use RuntimeException;

/** Thrown by ImportService::run() when the source is already claimed by another import. */
class ImportRunningException extends RuntimeException {
}
