// Turns an error code of the server into a translated sentence with a hint.
// arch: D26.3
import { translate as t } from '@nextcloud/l10n'

// The sync codes, the save codes and the admin save codes.
// arch: D23.3, D26.1, D23.1
const TEXTS = {
	waha_off: t('chat_archive', 'Live sync is turned off on this server. Ask your administrator to turn it on.'),
	waha_unassigned: t('chat_archive', 'This live sync session is no longer assigned to you. Ask your administrator to assign it again, or turn off live sync for this source.'),
	waha_down: t('chat_archive', 'The WAHA server does not answer. Try again later. If the problem stays, tell your administrator.'),
	waha_auth: t('chat_archive', 'The WAHA server does not accept the API key. Your administrator must enter the correct key in the admin settings.'),
	waha_session_missing: t('chat_archive', 'The WAHA server does not know this session. Ask your administrator to check the session name.'),
	waha_session_state: t('chat_archive', 'The WhatsApp session on the WAHA server is not ready. After a network problem it connects again by itself. If this message stays, ask your administrator to check the session.'),
	waha_account_changed: t('chat_archive', 'This session now belongs to a different WhatsApp number. The sync stopped so that no foreign chats go into this archive. Ask your administrator to link the session to your number again.'),
	waha_bad_response: t('chat_archive', 'The WAHA server sent an answer that the archive cannot read. Tell your administrator; a WAHA update can cause this.'),
	waha_local_blocked: t('chat_archive', 'Nextcloud blocked the connection to the WAHA server because it has a local address. Your administrator must allow it in the server configuration.'),
	folder_missing: t('chat_archive', 'The folder of this source does not exist. Edit the source and choose a folder.'),
	unknown_provider: t('chat_archive', 'The server does not know this platform.'),
	bad_mode: t('chat_archive', 'The server does not know this type of source.'),
	folder_not_found: t('chat_archive', 'The folder does not exist in your files, or it is not yours. Choose a folder that you own.'),
	not_a_folder: t('chat_archive', 'This path is a file, not a folder. Choose a folder.'),
	bad_policy: t('chat_archive', 'Choose what happens when you delete a linked file.'),
	label_required: t('chat_archive', 'Enter a name for the source.'),
	bad_key: t('chat_archive', 'The backup key has 64 characters: digits 0-9 and letters a-f. Copy it again from the place where you saved it.'),
	live_not_allowed: t('chat_archive', 'Live sync is not allowed on this server. Ask your administrator.'),
	bad_session: t('chat_archive', 'The session name is not valid, or the session is not assigned to you. Ask your administrator.'),
	session_in_use: t('chat_archive', 'Another source already uses this session. One session feeds one source only.'),
	tos_required: t('chat_archive', 'Tick the box to confirm that you understand the risk.'),
	backup_first: t('chat_archive', 'Import a phone backup into this source first. Then turn on live sync.'),
	preserve_on: t('chat_archive', 'The archive is preserved. Turn off "Preserve the archive" before you remove a source.'),
	running: t('chat_archive', 'An import of this source is running. Try again when it is finished.'),
	bad_url: t('chat_archive', 'Enter the server URL with http:// or https://, for example https://waha.example.com.'),
	bad_media_per_run: t('chat_archive', 'Media files per sync run must be a number from 0 to 200.'),
	unknown_user: t('chat_archive', 'A user ID in the session table does not exist.'),
}

/**
 * Translated text of an error.
 *
 * @param {Error|string|null} e an axios error, an error code, or the
 *        English error text that the server stored (for example `lastError`)
 * @return {string} the sentence for a known code, else the server's own text
 */
export function errorText(e) {
	if (typeof e === 'string') {
		return TEXTS[e] ?? e
	}
	const body = e?.response?.data?.ocs?.data ?? {}
	const code = body.code ?? e?.code
	if (Object.hasOwn(TEXTS, code ?? '')) {
		return TEXTS[code]
	}
	return body.error || e?.message || t('chat_archive', 'Something went wrong. Try again.')
}
