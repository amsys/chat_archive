<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Remove the false edits of media messages that the live sync stored first.
 * The sync keeps the text of a media message in the media row and no body.
 * Before 0.2.1 the first backup import then recorded the same text as an
 * edit. Such a message has no body, one edit row, and that edit is equal to
 * its caption or its file name.
 */
class Version0201Date20261126000000 extends SimpleMigrationStep {
	private const CHUNK = 500;

	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$q = $this->db->getQueryBuilder();
		$q->select('m.id', 'md.caption', 'md.file_name', 'e.body')
			->selectAlias('e.id', 'edit_id')
			->from('chatarchive_message', 'm')
			->join('m', 'chatarchive_media', 'md', $q->expr()->eq('md.message_id', 'm.id'))
			->join('m', 'chatarchive_edit', 'e', $q->expr()->eq('e.message_id', 'm.id'))
			->where($q->expr()->isNotNull('m.edited_at'))
			->andWhere($q->expr()->isNull('m.body'));
		$byMessage = [];
		$res = $q->executeQuery();
		while (($r = $res->fetch()) !== false) {
			$byMessage[(int)$r['id']][] = $r;
		}
		$res->closeCursor();

		$messages = [];
		$edits = [];
		foreach ($byMessage as $id => $rows) {
			$body = $rows[0]['body'];
			if (count($rows) === 1 && $body !== null && ($body === $rows[0]['caption'] || $body === $rows[0]['file_name'])) {
				$messages[] = $id;
				$edits[] = (int)$rows[0]['edit_id'];
			}
		}

		// One transaction: a stop between the two steps would leave an edit mark with no edit row.
		$this->db->beginTransaction();
		try {
			foreach (array_chunk($edits, self::CHUNK) as $chunk) {
				$d = $this->db->getQueryBuilder();
				$d->delete('chatarchive_edit')
					->where($d->expr()->in('id', $d->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			foreach (array_chunk($messages, self::CHUNK) as $chunk) {
				$u = $this->db->getQueryBuilder();
				$u->update('chatarchive_message')
					->set('edited_at', $u->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
					->where($u->expr()->in('id', $u->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		if ($messages !== []) {
			$output->info(sprintf('Removed the false edit mark of %d media messages.', count($messages)));
		}
	}
}
