<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Call facts on the message row, so the UI can word a call in the user's language. */
class Version0102Date20260905000000 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('chatarchive_message');
		if (!$t->hasColumn('call_result')) {
			$t->addColumn('call_video', Types::BOOLEAN, ['notnull' => false]);
			$t->addColumn('call_result', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('call_duration', Types::INTEGER, ['notnull' => false]);
		}
		return $schema;
	}
}
