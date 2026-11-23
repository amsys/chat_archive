<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Keep WhatsApp's own suspicious-file verdict on the media row.
 * arch: D2.2
 */
class Version0105Date20260926000000 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$media = $schema->getTable('chatarchive_media');
		if (!$media->hasColumn('suspicious')) {
			$media->addColumn('suspicious', Types::SMALLINT, ['notnull' => false, 'default' => null]);
		}

		return $schema;
	}
}
