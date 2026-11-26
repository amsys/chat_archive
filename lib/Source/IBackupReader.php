<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Source;

/**
 * Rows in a platform-neutral shape.
 *
 * chat:    ['platform_chat_id' => string, 'name' => ?string, 'is_group' => bool,
 *           'archived' => ?bool (archived on the phone; null when the backup cannot tell)]
 * message: ['platform_msg_id' => string, 'sent_at' => int (unix ms), 'from_me' => bool,
 *           'sender' => ?string (null when from me), 'kind' => string, 'body' => ?string,
 *           'media' => ?array{file_hash:?string, mime:?string, size:?int, file_name:?string,
 *                             caption:?string, src_path:?string, suspicious:?int},
 *           'call' => ?array{video:bool, result:?string, duration_s:?int} (call rows only)]
 * call:    ['platform_call_id' => string, 'peer' => ?string, 'started_at' => int (unix ms),
 *           'from_me' => bool, 'video' => bool, 'duration_s' => ?int, 'result' => ?string]
 *
 * media.suspicious: the platform's own verdict on the file; any value > 0 means
 * flagged; the meanings of the values are not known.
 */
interface IBackupReader {
	/** @return iterable<array<string, mixed>> */
	public function chats(): iterable;

	/** @return iterable<array<string, mixed>> messages of one chat, oldest first */
	public function messages(string $platformChatId): iterable;

	/** @return iterable<array<string, mixed>> */
	public function calls(): iterable;

	public function close(): void;
}
