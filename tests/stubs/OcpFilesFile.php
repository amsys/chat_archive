<?php
declare(strict_types=1);
// Test-only stand-in for OCP\Files\File. tests/reader_check.php requires this
// file so WhatsAppProvider::open() can get the backup as a memory stream,
// with no Nextcloud code loaded.
//
// This file stays out of phpstan's scan (excludePaths in phpstan.neon, same
// as tests/phpstan). Kept in the paths phpstan scans, this second top-level
// declaration of OCP\Files\File would shadow the real interface from
// vendor/nextcloud/ocp and break every real call site (FAIL-T4).
namespace OCP\Files {
	// Only the two methods that open() calls. This script loads no Nextcloud
	// code, so the stand-in does not collide with the real interface.
	interface File {
		/**
		 * @param string $mode
		 * @return resource|false
		 */
		public function fopen($mode);
		/**
		 * @param bool $includeMounts
		 * @return int|float
		 */
		public function getSize($includeMounts = true);
	}
}
