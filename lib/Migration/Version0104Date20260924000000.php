<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phone-archived chats, gone chats, deleted messages and an edit history.
 * arch: D1.1, D1.2, D1.3
 */
class Version0104Date20260924000000 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$chat = $schema->getTable('chatarchive_chat');
		if (!$chat->hasColumn('phone_archived')) {
			$chat->addColumn('phone_archived', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$chat->addColumn('gone_at', Types::BIGINT, ['notnull' => false]);
		}

		$message = $schema->getTable('chatarchive_message');
		if (!$message->hasColumn('deleted')) {
			$message->addColumn('deleted', Types::STRING, ['notnull' => false, 'length' => 8]);
			$message->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
			$message->addColumn('seen_hash', Types::STRING, ['notnull' => false, 'length' => 40]);
			$message->addColumn('edited_at', Types::BIGINT, ['notnull' => false]);
		}

		if (!$schema->hasTable('chatarchive_edit')) {
			$edit = $schema->createTable('chatarchive_edit');
			$edit->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$edit->addColumn('source_id', Types::BIGINT, ['notnull' => true]);
			$edit->addColumn('message_id', Types::BIGINT, ['notnull' => true]);
			$edit->addColumn('seen_at', Types::BIGINT, ['notnull' => true]);
			$edit->addColumn('body', Types::TEXT, ['notnull' => false]);
			$edit->setPrimaryKey(['id']);
			$edit->addIndex(['message_id', 'seen_at'], 'chatarchive_edit_msg');
		}

		return $schema;
	}
}
