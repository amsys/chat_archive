// Calls to the OCS API of the app, the view preferences, and the URLs and actions that open a file.
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateRemoteUrl, generateUrl } from '@nextcloud/router'

const url = (path) => generateOcsUrl('/apps/chat_archive' + path)
const data = (r) => r.data.ocs.data

export const api = {
	chats: (sourceId) => axios.get(url(`/api/sources/${sourceId}/chats`)).then(data),
	messages: (chatId, params) => axios.get(url(`/api/chats/${chatId}/messages`), { params }).then(data),
	search: (params) => axios.get(url('/api/search'), { params }).then(data),
	calls: (sourceId) => axios.get(url(`/api/sources/${sourceId}/calls`)).then(data),
	refreshNames: (sourceId) => axios.post(url(`/api/sources/${sourceId}/names/refresh`)).then(data),
	edits: (id) => axios.get(url(`/api/messages/${id}/edits`)).then(data),
	liveChat: (chatId) => axios.post(url(`/api/chats/${chatId}/live`)).then(data),

	settings: () => axios.get(url('/settings/sources')).then(data),
	createSource: (s) => axios.post(url('/settings/sources'), s).then(data),
	updateSource: (id, s) => axios.put(url(`/settings/sources/${id}`), s).then(data),
	deleteSource: (id) => axios.delete(url(`/settings/sources/${id}`)).then(data),
	importSource: (id) => axios.post(url(`/settings/sources/${id}/import`)).then(data),
	// An empty secret goes to the server as null.
	probe: (folder, secret) => axios.post(url('/settings/probe'), { folder, secret: secret || null }).then(data),
	setPreserve: (preserve) => axios.put(url('/settings/preserve'), { preserve }).then(data),

	// admin routes
	adminStatus: () => axios.get(url('/admin/status')).then(data),
	saveWaha: (cfg) => axios.put(url('/admin/waha'), cfg).then(data),
	testWaha: () => axios.post(url('/admin/waha/test')).then(data),

	setFullTextSearch: (enabled) => axios.put(url('/settings/fulltextsearch'), { enabled }).then(data),
	setClickAction: (c) => axios.put(url('/settings/click'), c).then(data),
	setViewPrefs: (p) => axios.put(url('/settings/view'), p).then(data),
}

/**
 * The user's view toggles at page load: show deleted messages and gone
 * chats, show chats archived on the phone. Both default on.
 *
 * @return {{showDeleted: boolean, showArchived: boolean}}
 */
export function viewPrefs() {
	return loadState('chat_archive', 'viewPrefs', { showDeleted: true, showArchived: true })
}

/** @param {string} path user-relative path; @return {string} the path with each segment URL-encoded */
const encodePath = (path) => path.split('/').map(encodeURIComponent).join('/')

/**
 * Thumbnail of a Nextcloud file by id (core preview endpoint).
 *
 * @param {number} fileId Nextcloud file id
 * @param {number} size longest edge in px
 * @return {string}
 */
export function previewUrl(fileId, size = 512) {
	return generateUrl('/core/preview?fileId={id}&x={s}&y={s}&a=1', { id: fileId, s: size })
}

/**
 * Direct WebDAV URL of a user-relative path; works with the session cookie.
 *
 * @param {string} path user-relative path
 * @return {string}
 */
export function davUrl(path) {
	const uid = getCurrentUser()?.uid ?? ''
	return generateRemoteUrl(`dav/files/${uid}`) + encodePath(path)
}

/**
 * Files app, with the file selected.
 *
 * @param {number} fileId Nextcloud file id
 * @param {?string} path user-relative path, for the folder to open
 * @return {string}
 */
export function filesUrl(fileId, path) {
	const dir = path ? path.slice(0, path.lastIndexOf('/')) || '/' : '/'
	return generateUrl('/apps/files/?dir={dir}&openfile={id}', { dir, id: fileId })
}

/**
 * Hand the file to the Nextcloud desktop client, which opens the local copy
 * (the Files app "Open locally" action does the same).
 *
 * @param {string} path user-relative path
 */
export async function openLocally(path) {
	const uid = getCurrentUser()?.uid ?? ''
	let token
	try {
		// the Files app registers this route under OCS only; an index.php path gives 404
		const r = await axios.post(generateOcsUrl('/apps/files/api/v1/openlocaleditor'), { path })
		token = r.data?.ocs?.data?.token
		if (!token) {
			throw new Error('no token in response')
		}
	} catch {
		showError(t('chat_archive', 'Could not open the file locally'))
		return
	}
	window.location.href = `nc://open/${uid}@${window.location.host}${encodePath(path)}?token=${token}`
}

/**
 * The user's left-click action for a file in a chat (personal settings).
 * view: the Nextcloud viewer, or a download when the viewer has no handler.
 * nc: the desktop client. custom: a URL template; {path} is the encoded path without its leading slash.
 * A file WhatsApp flagged as suspicious never opens this way: the caller in
 * MediaView already guards its own click handlers, and this is the second
 * guard for any other caller.
 *
 * @param {object} media a media row with `path`, `mime` and `suspicious`
 */
export function openMedia(media) {
	if (media.suspicious) {
		return
	}
	const { action, template } = loadState('chat_archive', 'clickAction', { action: 'view', template: '' })
	if (action === 'nc') {
		return openLocally(media.path)
	}
	if (action === 'custom' && template) {
		window.location.href = template.replaceAll('{path}', encodePath(media.path.replace(/^\//, '')))
		return
	}
	const viewer = window.OCA?.Viewer
	if (viewer?.mimetypes.includes(media.realMime ?? media.mime)) {
		viewer.open({ path: media.path }) // the API path starts with '/'
	} else {
		window.location.href = davUrl(media.path)
	}
}
