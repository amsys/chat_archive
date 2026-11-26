<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Source> */
class SourceMapper extends QBMapper {
	/**
	 * The last_error code of a source whose previous import stopped before it
	 * finished.
	 */
	public const CRASH_NOTE = 'import_stopped';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chatarchive_source', Source::class);
	}

	/** @return Source[] */
	public function findAll(): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from($this->getTableName())->orderBy('id');
		return $this->findEntities($q);
	}

	/** @return Source[] */
	public function findByUser(string $userId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from($this->getTableName())
			->where($q->expr()->eq('user_id', $q->createNamedParameter($userId)))
			->orderBy('id');
		return $this->findEntities($q);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function find(int $id): Source {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from($this->getTableName())
			->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($q);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function findForUser(int $id, string $userId): Source {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from($this->getTableName())
			->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($userId)));
		return $this->findEntity($q);
	}

	/**
	 * Mark the source 'queued' for an ImportSourceJob. One UPDATE that only
	 * changes an idle row (no state) or a state older than 6 h. Two callers
	 * racing for the same source can both read "idle" first; only one of
	 * them changes the row, and only that one adds the job.
	 * @return bool true when this call changed the row
	 */
	public function queue(Source $source): bool {
		$now = time();
		$q = $this->db->getQueryBuilder();
		$q->update($this->getTableName())
			->set('import_state', $q->createNamedParameter('queued'))
			->set('import_since', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('id', $q->createNamedParameter($source->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->orX(
				$q->expr()->isNull('import_state'),
				$q->expr()->lt('import_since', $q->createNamedParameter($now - 6 * 3600, IQueryBuilder::PARAM_INT)),
			));
		$queued = $q->executeStatement() === 1;
		if ($queued) {
			$source->setImportState('queued');
			$source->setImportSince($now);
		}
		return $queued;
	}

	/**
	 * Clear a 'queued' state. A row that another worker holds as 'running'
	 * stays as it is.
	 * @return bool true when this call changed the row
	 */
	public function releaseQueued(Source $source): bool {
		$q = $this->db->getQueryBuilder();
		$q->update($this->getTableName())
			->set('import_state', $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('import_since', $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($q->expr()->eq('id', $q->createNamedParameter($source->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('import_state', $q->createNamedParameter('queued')));
		return $q->executeStatement() === 1;
	}

	/**
	 * One UPDATE that only succeeds when the source is idle: no state, a
	 * non-'running' state, or a 'running' state stale for more than 6h (a
	 * crashed worker). Two callers racing for the same source can both read
	 * "idle" first; only one of them changes a row here. On success the
	 * entity's own state is set to match what is now on disk.
	 *
	 * A claim over a stale 'running' state (as the entity shows it before
	 * the UPDATE) also writes a note to last_error. The next import that ends
	 * without error clears it.
	 */
	public function claim(Source $source): bool {
		$now = time();
		$cutoff = $now - 6 * 3600;
		$stale = $source->isStale();
		$q = $this->db->getQueryBuilder();
		$q->update($this->getTableName())
			->set('import_state', $q->createNamedParameter('running'))
			->set('import_since', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		$note = $stale && $source->getMode() === Source::MODE_BACKUP;
		if ($note) {
			$q->set('last_error', $q->createNamedParameter(self::CRASH_NOTE));
		}
		$q->where($q->expr()->eq('id', $q->createNamedParameter($source->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->orX(
				$q->expr()->isNull('import_state'),
				$q->expr()->neq('import_state', $q->createNamedParameter('running')),
				$q->expr()->lt('import_since', $q->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)),
			));
		$claimed = $q->executeStatement() === 1;
		if ($claimed) {
			$source->setImportState('running');
			$source->setImportSince($now);
			if ($note) {
				// Also on the entity: a later setLastError(null) is then a change and reaches the row.
				$source->setLastError(self::CRASH_NOTE);
			}
		}
		return $claimed;
	}
}
