<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Platform-neutral chat schema. Every row hangs off a source
 * (one configured backup: provider + folder). Table names stay under the
 * 23-character limit that Oracle imposes with the oc_ prefix.
 */
class Version0100Date20260904000000 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chatarchive_source')) {
			$t = $schema->createTable('chatarchive_source');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('provider', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('folder', Types::STRING, ['notnull' => true, 'length' => 4000]);
			$t->addColumn('secret', Types::TEXT, ['notnull' => false]);
			$t->addColumn('on_delete', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'restore']);
			$t->addColumn('last_backup', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('last_synced', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id'], 'chatarchive_source_user');
		}

		if (!$schema->hasTable('chatarchive_chat')) {
			$t = $schema->createTable('chatarchive_chat');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('source_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('platform_chat_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('display_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('is_group', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('last_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('msg_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['source_id', 'platform_chat_id'], 'chatarchive_chat_key');
		}

		if (!$schema->hasTable('chatarchive_message')) {
			$t = $schema->createTable('chatarchive_message');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('source_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('chat_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('platform_msg_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('sent_at', Types::BIGINT, ['notnull' => true]); // unix ms
			$t->addColumn('from_me', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('sender', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('body', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['chat_id', 'platform_msg_id'], 'chatarchive_msg_key');
			$t->addIndex(['chat_id', 'sent_at'], 'chatarchive_msg_chat_at');
			$t->addIndex(['source_id', 'sent_at'], 'chatarchive_msg_src_at');
		}

		if (!$schema->hasTable('chatarchive_media')) {
			$t = $schema->createTable('chatarchive_media');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('source_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('message_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('file_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('mime', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('file_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('caption', Types::TEXT, ['notnull' => false]);
			$t->addColumn('src_path', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => false]); // Nextcloud file id, survives moves
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['message_id'], 'chatarchive_media_msg');
			$t->addIndex(['file_id'], 'chatarchive_media_file');
			$t->addIndex(['source_id', 'file_id'], 'chatarchive_media_src');
		}

		if (!$schema->hasTable('chatarchive_call')) {
			$t = $schema->createTable('chatarchive_call');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('source_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('platform_call_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('peer', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('started_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('from_me', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('video', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('duration_s', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('result', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['source_id', 'platform_call_id'], 'chatarchive_call_key');
		}

		return $schema;
	}
}
