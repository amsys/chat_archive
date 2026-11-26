<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

/**
 * An import failure with an error code. ImportService::run() stores the code
 * in last_error, and the UI shows the text for that code. The message is for
 * the log only.
 */
class ImportException extends \RuntimeException {
	/** @param string $errorCode the code that the UI translates, for example 'wrong_key' */
	public function __construct(public readonly string $errorCode, string $message) {
		parent::__construct($message);
	}
}
