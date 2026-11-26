<!--
  Admin settings page: the status of jobs and sources, the WAHA live sync
  settings with the session table, and the full text search switch.
-->
<template>
	<div>
		<!-- Section 1: status -->
		<NcSettingsSection
			:name="t('chat_archive', 'Status')"
			:description="t('chat_archive', 'Background jobs import the backups and run the live sync.')">
			<p>{{ jobsLine }}</p>
			<NcNoteCard v-if="jobs.mode !== 'cron'" type="warning">
				{{ t('chat_archive', 'Imports and live sync need the system cron.') }}
			</NcNoteCard>
			<p>{{ sourcesLine }}</p>
			<template v-if="failing.length > 0">
				<h4>{{ t('chat_archive', 'Sources with an error') }}</h4>
				<ul class="ca-admin__failing">
					<li v-for="(f, i) in failing" :key="i">
						<strong>{{ f.uid }}</strong> · {{ f.label }}:
						{{ errorText(f.error) }}
					</li>
				</ul>
			</template>
		</NcSettingsSection>

		<!-- Section 2: live sync (WAHA) -->
		<NcSettingsSection
			:name="t('chat_archive', 'Live sync (WAHA)')"
			:description="t('chat_archive', 'The archive reads new messages from a WAHA server. It sends only read requests. Each session supplies messages to one source of the user that you assign to it.')">
			<form class="ca-admin__form" @submit.prevent="save">
				<NcCheckboxRadioSwitch v-model="waha.enabled" type="switch">
					{{ t('chat_archive', 'Allow live sync through WAHA') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-model="waha.url"
					type="url"
					:label="t('chat_archive', 'Server URL')"
					placeholder="https://waha.example.com" />
				<NcPasswordField
					v-model="apiKey"
					autocomplete="new-password"
					:label="t('chat_archive', 'API key')"
					:placeholder="hasKey ? t('chat_archive', 'A key is stored. Leave empty to keep it.') : ''" />
				<NcTextField
					v-model="mediaPerRun"
					type="number"
					min="0"
					max="200"
					:label="t('chat_archive', 'Media files per sync run')"
					:helperText="t('chat_archive', 'With 0, the sync loads no media files. The maximum is 200.')" />
				<NcCheckboxRadioSwitch v-model="realtime" type="switch">
					{{ t('chat_archive', 'Realtime updates for an open chat') }}
				</NcCheckboxRadioSwitch>
				<p class="ca-admin__hint">
					{{ t('chat_archive', 'An open chat asks the WAHA server for its new messages every 8 seconds. Without this, new messages come with the sync every 15 minutes.') }}
				</p>

				<div class="ca-admin__test">
					<NcButton variant="secondary" :disabled="testing" @click="test">
						<template #icon>
							<NcLoadingIcon v-if="testing" :size="20" />
						</template>
						{{ t('chat_archive', 'Test connection') }}
					</NcButton>
					<span class="ca-admin__hint">
						{{ t('chat_archive', 'The test uses the saved server URL and API key.') }}
					</span>
				</div>
				<NcNoteCard v-if="testNote" :type="testOk ? 'success' : 'error'">
					{{ testNote }}
				</NcNoteCard>

				<h4>{{ t('chat_archive', 'Sessions') }}</h4>
				<p v-if="rows.length === 0" class="ca-admin__hint">
					{{ t('chat_archive', 'No sessions yet. Save the server URL and the API key, then test the connection to list the sessions.') }}
				</p>
				<table v-else class="ca-admin__sessions">
					<thead>
						<tr>
							<th scope="col">
								{{ t('chat_archive', 'Session') }}
							</th>
							<th scope="col">
								{{ t('chat_archive', 'Status') }}
							</th>
							<th scope="col">
								{{ t('chat_archive', 'User ID. Leave it empty when the session is not assigned.') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="r in rows" :key="r.name">
							<td>{{ r.name }}</td>
							<td>{{ r.status || t('chat_archive', 'Not tested') }}</td>
							<td>
								<NcTextField
									v-model="r.uid"
									:aria-label="t('chat_archive', 'User ID for session {name}', { name: r.name }, undefined, { escape: false, sanitize: false })"
									labelOutside />
							</td>
						</tr>
					</tbody>
				</table>

				<!-- The same translated string as in the source dialog -->
				<NcNoteCard type="warning">
					{{ t('chat_archive', 'WAHA is an unofficial WhatsApp client. Its use is against the WhatsApp Terms of Service, and WhatsApp can restrict or ban a number that uses one. Chat Archive only reads; it never sends. In daily use it reads the few messages since the last sync. The first sync of a live-only source loads the history and, when the administrator allows it, its media in a short time, which can look like automation. Start from a phone backup when you can.') }}
				</NcNoteCard>

				<NcNoteCard v-if="saveNote" :type="saveOk ? 'success' : 'error'">
					{{ saveNote }}
				</NcNoteCard>
				<NcButton variant="primary" type="submit" :disabled="saving">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
					</template>
					{{ t('chat_archive', 'Save') }}
				</NcButton>
			</form>
		</NcSettingsSection>

		<!-- Section 3: full text search -->
		<NcSettingsSection
			:name="t('chat_archive', 'Full text search')"
			:description="t('chat_archive', 'Index chat messages with the Nextcloud full text search app for ranked, fast search. Turning this off stops new indexing but leaves already-indexed messages in the search platform; run \'occ fulltextsearch:reset chat_archive\' to remove them.')">
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="fulltextSearch.enabled"
				:disabled="busy"
				@update:modelValue="toggle">
				{{ t('chat_archive', 'Enable full text search') }}
			</NcCheckboxRadioSwitch>
			<NcNoteCard v-if="!fulltextSearch.available" type="warning">
				{{ t('chat_archive', 'The Full text search app is not installed, or no platform (e.g. Elasticsearch) is configured.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="fulltextSearch.enabled && !fulltextSearch.indexed">
				{{ t('chat_archive', 'Not indexed yet. Run "occ fulltextsearch:index" on the server.') }}
			</NcNoteCard>
			<NcNoteCard v-if="notice" :type="noticeType">
				{{ notice }}
			</NcNoteCard>
		</NcSettingsSection>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { api } from '../api.js'
import { errorText } from '../errors.js'
import { fmtDateTime } from '../util.js'

const MODES = { cron: 'Cron', webcron: 'Webcron', ajax: 'AJAX' }

/**
 * Session rows of the table: every session the test returned and every
 * session that is already assigned, sorted by name.
 *
 * @param {Array<{name: string, status: string, uid: string}>} current the rows on screen
 * @param {object} assigned session name => user id; it sets the user id of its rows
 * @param {Array<{name: string, status: string}>} tested sessions of the last test; it sets the status of its rows
 * @return {Array<{name: string, status: string, uid: string}>}
 */
function sessionRows(current, assigned, tested) {
	const rows = new Map(current.map((r) => [r.name, { ...r }]))
	// an empty PHP array arrives as [], not {}: Object.entries handles both
	for (const [name, uid] of Object.entries(assigned ?? {})) {
		rows.set(name, { name, status: rows.get(name)?.status ?? '', uid })
	}
	for (const s of tested) {
		rows.set(s.name, { name: s.name, status: s.status, uid: rows.get(s.name)?.uid ?? '' })
	}
	return [...rows.values()].sort((a, b) => a.name.localeCompare(b.name))
}

export default {
	name: 'AdminSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSettingsSection, NcTextField },
	data() {
		// The same shape as GET /admin/status
		const status = loadState('chat_archive', 'adminStatus', {
			jobs: { mode: 'ajax', lastRun: 0 },
			sources: { total: 0, live: 0, failing: [] },
			waha: { enabled: false, url: '', hasKey: false, mediaPerRun: 0, sessions: {}, realtime: false },
		})
		return {
			jobs: status.jobs,
			sources: status.sources,
			failing: status.sources.failing ?? [],
			waha: { enabled: status.waha.enabled, url: status.waha.url },
			// arch: I20.6 the field starts empty; the server never sends the stored key
			apiKey: '',
			hasKey: status.waha.hasKey,
			mediaPerRun: String(status.waha.mediaPerRun),
			realtime: status.waha.realtime === true,
			rows: sessionRows([], status.waha.sessions, []),
			testing: false,
			testOk: false,
			testNote: '',
			saving: false,
			saveOk: false,
			saveNote: '',
			fulltextSearch: loadState('chat_archive', 'fulltextSearch', { enabled: false, available: false, indexed: false }),
			busy: false,
			notice: '',
			noticeType: 'success',
		}
	},

	computed: {
		jobsLine() {
			const mode = MODES[this.jobs.mode] ?? this.jobs.mode
			if (!this.jobs.lastRun) {
				return t('chat_archive', 'Background jobs: {mode}. They have not run yet.', { mode }, undefined, { escape: false, sanitize: false })
			}
			return t('chat_archive', 'Background jobs: {mode}. Last run: {when}.', { mode, when: fmtDateTime(this.jobs.lastRun * 1000) }, undefined, { escape: false, sanitize: false })
		},

		sourcesLine() {
			return t('chat_archive', 'Sources: {n}. With live sync: {m}.', { n: this.sources.total, m: this.sources.live }, undefined, { escape: false, sanitize: false })
		},
	},

	methods: {
		t,
		errorText,

		/** Asks the WAHA server for its version and its sessions (saved URL and key). */
		async test() {
			this.testing = true
			this.testNote = ''
			try {
				const r = await api.testWaha()
				this.testOk = r.ok
				if (r.ok) {
					this.testNote = t('chat_archive', 'Connected: WAHA {version}, engine {engine}, tier {tier}.', { version: r.version, engine: r.engine, tier: r.tier }, undefined, { escape: false, sanitize: false })
					this.rows = sessionRows(this.rows, {}, r.sessions)
				} else {
					this.testNote = errorText(r.code)
				}
			} catch (e) {
				this.testOk = false
				this.testNote = errorText(e)
			} finally {
				this.testing = false
			}
		},

		/** Saves the WAHA settings and the session assignments. */
		async save() {
			this.saveNote = ''
			const media = Number(this.mediaPerRun)
			if (!Number.isInteger(media) || media < 0 || media > 200) {
				this.saveOk = false
				this.saveNote = errorText('bad_media_per_run')
				return
			}
			const sessions = {}
			for (const r of this.rows) {
				const uid = r.uid.trim()
				if (uid !== '') {
					sessions[r.name] = uid
				}
			}
			this.saving = true
			try {
				const d = await api.saveWaha({
					enabled: this.waha.enabled,
					url: this.waha.url.trim(),
					apiKey: this.apiKey === '' ? null : this.apiKey,
					mediaPerRun: media,
					sessions,
					realtime: this.realtime,
				})
				this.waha = { enabled: d.enabled, url: d.url }
				this.hasKey = d.hasKey
				this.apiKey = ''
				this.mediaPerRun = String(d.mediaPerRun)
				this.realtime = d.realtime === true
				// the server holds the assignments now: a row it does not list is unassigned
				this.rows = sessionRows(this.rows.map((r) => ({ ...r, uid: '' })), d.sessions, [])
				this.saveOk = true
				this.saveNote = t('chat_archive', 'Saved')
			} catch (e) {
				this.saveOk = false
				this.saveNote = errorText(e)
			} finally {
				this.saving = false
			}
		},

		async toggle(checked) {
			this.busy = true
			this.notice = ''
			try {
				this.fulltextSearch = await api.setFullTextSearch(checked)
				this.noticeType = 'success'
				this.notice = checked ? t('chat_archive', 'Enabled') : t('chat_archive', 'Disabled')
			} catch (e) {
				this.notice = errorText(e)
				this.noticeType = 'error'
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.ca-admin__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 720px;
}
.ca-admin__test {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
}
.ca-admin__hint {
	color: var(--color-text-maxcontrast);
}
.ca-admin__failing {
	padding-inline-start: 20px;
	list-style: disc;
}
.ca-admin__sessions {
	width: 100%;
	border-collapse: collapse;
}
.ca-admin__sessions th,
.ca-admin__sessions td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: middle;
}
</style>
