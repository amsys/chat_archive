<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Waha;

/**
 * A WAHA call failed. The message is one error code of the sync error list.
 * The exception never holds a previous exception: the HTTP client trace
 * carries the API key.
 */
class WahaException extends \RuntimeException {
	public function __construct(
		public readonly string $errorCode,
	) {
		parent::__construct($errorCode);
	}
}
