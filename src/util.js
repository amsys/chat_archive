// Shared helpers: date, time and size formats, call and kind labels, media type tests and the import poll.
import { translate as t } from '@nextcloud/l10n'

/** @param {number} ms unix ms; @return {string} short local time */
export const fmtTime = (ms) => new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
/** @param {number} ms unix ms; @return {string} short local date */
export const fmtDate = (ms) => new Date(ms).toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' })
/** @param {number} ms unix ms; @return {string} short local date and time */
export const fmtDateTime = (ms) => `${fmtDate(ms)} ${fmtTime(ms)}`
/** @param {number} ms unix ms; @return {string} one key per local day */
export const dayKey = (ms) => new Date(ms).toDateString()

/**
 * @param {?number} ms unix ms, or null for "no messages yet"
 * @return {string} "Today", "Yesterday", a weekday within the last week, or fmtDate beyond that
 */
export function fmtRelDate(ms) {
	if (ms === null) {
		return ''
	}
	const days = Math.round((new Date().setHours(0, 0, 0, 0) - new Date(ms).setHours(0, 0, 0, 0)) / 86400000)
	if (days === 0) {
		return t('chat_archive', 'Today')
	}
	if (days === 1) {
		return t('chat_archive', 'Yesterday')
	}
	if (days > 1 && days < 7) {
		return new Date(ms).toLocaleDateString([], { weekday: 'short' })
	}
	return fmtDate(ms)
}

/**
 * The date of a chat row in the navigation. A day label cannot order the
 * chats of one day, so a time of today shows as the clock time.
 *
 * @param {?number} ms unix ms, or null for "no messages yet"
 * @return {string} the local time for today, else the fmtRelDate label
 */
export function fmtListDate(ms) {
	if (ms !== null && dayKey(ms) === dayKey(Date.now())) {
		return fmtTime(ms)
	}
	return fmtRelDate(ms)
}

/**
 * @param {number} s seconds
 * @return {string} "m:ss" or "h:mm:ss"
 */
function fmtDuration(s) {
	const pad = (n) => String(n).padStart(2, '0')
	const h = Math.floor(s / 3600)
	const rest = `${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`
	return h > 0 ? `${h}:${rest}` : rest.replace(/^0/, '')
}

const CALL_LABELS = {
	voice: t('chat_archive', 'Voice call'),
	video: t('chat_archive', 'Video call'),
	'voice:connected': t('chat_archive', 'Voice call · {duration}'),
	'video:connected': t('chat_archive', 'Video call · {duration}'),
	'voice:missed': t('chat_archive', 'Missed voice call'),
	'video:missed': t('chat_archive', 'Missed video call'),
	'voice:no_answer': t('chat_archive', 'Voice call · no answer'),
	'video:no_answer': t('chat_archive', 'Video call · no answer'),
	'voice:rejected': t('chat_archive', 'Voice call · declined'),
	'video:rejected': t('chat_archive', 'Video call · declined'),
	'voice:failed': t('chat_archive', 'Voice call · failed'),
	'video:failed': t('chat_archive', 'Video call · failed'),
}

/**
 * Words a call message in the user's language; legacy rows keep the body the import wrote.
 *
 * @param {object} message a message row with `call`, `fromMe` and `body`
 * @return {string}
 */
export function callLabel(message) {
	const c = message.call
	if (!c) {
		return message.body || t('chat_archive', 'Call')
	}
	const kind = c.video ? 'video' : 'voice'
	const result = c.result === 'missed' && message.fromMe ? 'no_answer' : c.result
	const label = CALL_LABELS[`${kind}:${result}`] ?? CALL_LABELS[kind]
	return label.replace('{duration}', fmtDuration(c.durationS ?? 0))
}

const KIND_LABELS = {
	system: t('chat_archive', 'System message'),
	revoked: t('chat_archive', 'This message was deleted'),
	sticker: t('chat_archive', 'Sticker'),
	vcard: t('chat_archive', 'Contact card'),
	vcard_array: t('chat_archive', 'Contact cards'),
	location: t('chat_archive', 'Location'),
	live_location: t('chat_archive', 'Live location'),
	poll: t('chat_archive', 'Poll'),
	group_invite: t('chat_archive', 'Group invite'),
	view_once_image: t('chat_archive', 'View once photo'),
	view_once_video: t('chat_archive', 'View once video'),
	unsupported: t('chat_archive', 'Unsupported message'),
	image: t('chat_archive', 'Photo'),
	video: t('chat_archive', 'Video'),
	audio: t('chat_archive', 'Audio'),
	document: t('chat_archive', 'Document'),
	gif: t('chat_archive', 'GIF'),
	video_note: t('chat_archive', 'Video message'),
}

/**
 * Gives the label of a message kind in the user's language. A kind with no
 * label, such as `button_reply` or `type_42`, gets the general label "Message".
 *
 * @param {?string} kind the kind of a message row
 * @return {string}
 */
export function kindLabel(kind) {
	return KIND_LABELS[kind] ?? t('chat_archive', 'Message')
}

/** @param {?string} mime a mime type; @return {boolean} */
export const isImage = (mime) => /^image\//.test(mime ?? '')
/** @param {?string} mime a mime type; @return {boolean} */
export const isVideo = (mime) => /^video\//.test(mime ?? '')
/** @param {?string} mime a mime type; @return {boolean} */
export const isAudio = (mime) => /^audio\//.test(mime ?? '')

/**
 * Calls refresh() now, again whenever the tab comes to the foreground, and
 * every 15 s for as long as it returns true (an import is queued or running).
 * kick() restarts the cycle after "Import now"; stop() on unmount.
 * A kick() while a tick is in flight is not lost: one more tick runs as
 * soon as that tick ends.
 *
 * @param {() => Promise<boolean>} refresh reloads the sources; resolves true while an import is pending
 * @return {{ kick: () => void, stop: () => void }}
 */
export function pollImports(refresh) {
	let timer = null
	let busy = false
	let again = false
	let stopped = false
	const tick = async () => {
		if (busy || stopped) {
			return
		}
		busy = true
		clearTimeout(timer)
		timer = null
		try {
			// a failed request must not end the cycle, or the page never polls again
			const pending = await refresh().then(Boolean, () => true)
			if (!stopped) {
				timer = pending ? setTimeout(tick, 15000) : null
			}
		} finally {
			busy = false
		}
		if (again && !stopped) {
			again = false
			tick()
		}
	}
	const kick = () => {
		if (busy) {
			again = true
		} else {
			tick()
		}
	}
	const onVisible = () => document.visibilityState === 'visible' && tick()
	document.addEventListener('visibilitychange', onVisible)
	tick()
	const stop = () => {
		stopped = true // a tick already in flight must not re-arm after unmount
		clearTimeout(timer)
		timer = null
		document.removeEventListener('visibilitychange', onVisible)
	}
	return { kick, stop }
}

/**
 * @param {number} bytes a file size
 * @return {string} the size with one unit, for example "12.4 MB"
 */
export function fmtSize(bytes) {
	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let n = bytes
	let i = 0
	while (n >= 1000 && i < units.length - 1) {
		n /= 1000
		i++
	}
	return `${n.toLocaleString([], { maximumFractionDigits: i === 0 ? 0 : 1 })} ${units[i]}`
}
