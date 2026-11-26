<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the live sync columns: source mode and sync state, the in_backup flags and the media fetch counter.
 */
class Version0200Date20261117000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$source = $schema->getTable('chatarchive_source');
		if (!$source->hasColumn('mode')) {
			$source->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'backup']);
		}
		if (!$source->hasColumn('sync_session')) {
			$source->addColumn('sync_session', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		if (!$source->hasColumn('sync_me')) {
			$source->addColumn('sync_me', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		if (!$source->hasColumn('sync_at')) {
			$source->addColumn('sync_at', Types::BIGINT, ['notnull' => false]);
		}
		if (!$source->hasColumn('sync_error')) {
			$source->addColumn('sync_error', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		if (!$source->hasColumn('tos_at')) {
			$source->addColumn('tos_at', Types::BIGINT, ['notnull' => false]);
		}

		foreach (['chatarchive_chat', 'chatarchive_message'] as $name) {
			$table = $schema->getTable($name);
			if (!$table->hasColumn('in_backup')) {
				$table->addColumn('in_backup', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			}
		}

		$media = $schema->getTable('chatarchive_media');
		if (!$media->hasColumn('sync_tries')) {
			$media->addColumn('sync_tries', Types::SMALLINT, ['notnull' => false, 'default' => 0]);
		}

		return $schema;
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach (['chatarchive_chat', 'chatarchive_message'] as $name) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($name)
				->set('in_backup', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->where($qb->expr()->isNull('in_backup'))
				->executeStatement();
		}
	}
}
