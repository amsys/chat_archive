<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Import progress on the source row, so the UI can show "import running". */
class Version0101Date20260904120000 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('chatarchive_source');
		if (!$t->hasColumn('import_state')) {
			$t->addColumn('import_state', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('import_since', Types::BIGINT, ['notnull' => false]);
		}
		return $schema;
	}
}
