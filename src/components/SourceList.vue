<!--
  List of the user's sources on the personal settings page: state, status
  lines, the import or sync button and a menu per source.
-->
<template>
	<div class="ca-list">
		<ul v-if="sources.length" class="ca-list__rows">
			<li v-for="s in sources" :key="s.id" class="ca-row">
				<div class="ca-row__head">
					<span class="ca-chip" :class="`ca-chip--${state(s)}`">
						<NcLoadingIcon v-if="state(s) === 'importing'" :size="16" />
						<CheckCircle v-else-if="state(s) === 'ok'" :size="16" />
						<AlertCircle v-else-if="state(s) === 'error'" :size="16" />
						<CircleOutline v-else :size="16" />
						{{ stateLabel(s) }}
					</span>
					<strong class="ca-row__label">{{ s.label }}</strong>
					<span class="ca-row__folder">{{ s.folder }}</span>
					<span class="ca-row__actions">
						<NcButton
							variant="primary"
							:disabled="!!busy[s.id] || !!s.importState || (s.mode === 'live' && !s.syncSession)"
							@click="importNow(s)">
							{{ s.mode === 'live' ? t('chat_archive', 'Sync now') : t('chat_archive', 'Import now') }}
						</NcButton>
						<NcActions :ariaLabel="t('chat_archive', 'Actions for {label}', { label: s.label }, { escape: false, sanitize: false })">
							<NcActionButton :closeAfterClick="true" @click="$emit('edit', s, false)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('chat_archive', 'Edit') }}
							</NcActionButton>
							<NcActionButton
								v-if="!s.syncSession && canTurnOn(s)"
								:closeAfterClick="true"
								@click="$emit('edit', s, true)">
								<template #icon>
									<Sync :size="20" />
								</template>
								{{ t('chat_archive', 'Turn on live sync…') }}
							</NcActionButton>
							<NcActionButton
								v-if="s.syncSession"
								:closeAfterClick="true"
								:disabled="!!busy[s.id]"
								@click="turnOff(s)">
								<template #icon>
									<SyncOff :size="20" />
								</template>
								{{ t('chat_archive', 'Turn off live sync') }}
							</NcActionButton>
							<NcActionButton
								:closeAfterClick="true"
								:disabled="preserve || !!busy[s.id]"
								:description="preserve ? removeHint : ''"
								@click="remove(s)">
								<template #icon>
									<Delete :size="20" />
								</template>
								{{ t('chat_archive', 'Remove') }}
							</NcActionButton>
						</NcActions>
					</span>
				</div>

				<p class="ca-row__line">
					<template v-if="s.importState === 'queued'">
						{{ t('chat_archive', 'Import queued, it starts with the next background job.') }}
					</template>
					<template v-else-if="s.importState">
						{{ t('chat_archive', 'Import running since {when}.', { when: fmtDateTime(s.importSince * 1000) }, { escape: false, sanitize: false }) }}
					</template>
					<template v-else>
						{{ statusLine(s) }}
					</template>
				</p>
				<p v-if="s.lastError" class="ca-row__line ca-row__line--error">
					{{ t('chat_archive', 'Import failed: {error}', { error: errorText(s.lastError) }, { escape: false, sanitize: false }) }}
					<NcButton variant="tertiary" @click="$emit('edit', s, false)">
						{{ t('chat_archive', 'Edit') }}
					</NcButton>
				</p>

				<p v-if="live.enabled || s.syncSession" class="ca-row__line">
					<template v-if="s.syncSession">
						{{ syncLine(s) }}
					</template>
					<template v-else>
						{{ t('chat_archive', 'Live sync: off') }}
						<NcButton
							v-if="canTurnOn(s)"
							variant="tertiary"
							@click="$emit('edit', s, true)">
							{{ t('chat_archive', 'Turn on…') }}
						</NcButton>
						<span v-else-if="s.mode === 'backup' && !s.lastBackup && live.sessions.length"> · {{ errorText('backup_first') }}</span>
					</template>
				</p>
				<p v-if="s.syncSession && s.syncError" class="ca-row__line ca-row__line--error">
					{{ t('chat_archive', 'Live sync failed: {error}', { error: errorText(s.syncError) }, { escape: false, sanitize: false }) }}
				</p>
				<p v-if="errors[s.id]" class="ca-row__line ca-row__line--error" role="alert">
					{{ errors[s.id] }}
				</p>
			</li>
		</ul>
		<p v-else class="ca-row__line">
			{{ t('chat_archive', 'No sources yet.') }}
		</p>
		<NcButton variant="secondary" @click="$emit('add')">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('chat_archive', 'Add a source') }}
		</NcButton>
	</div>
</template>

<script>
import { showConfirmation } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActions, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import SyncOff from 'vue-material-design-icons/SyncOff.vue'
import { api } from '../api.js'
import { errorText } from '../errors.js'
import { fmtDate, fmtDateTime } from '../util.js'

export default {
	name: 'SourceList',
	components: {
		AlertCircle,
		CheckCircle,
		CircleOutline,
		Delete,
		NcActionButton,
		NcActions,
		NcButton,
		NcLoadingIcon,
		Pencil,
		Plus,
		Sync,
		SyncOff,
	},

	props: {
		sources: { type: Array, required: true },
		/** `{enabled, sessions}` of GET /settings/sources */
		live: { type: Object, required: true },
		preserve: { type: Boolean, required: true },
	},

	/** `edit` gets the source and true for the "Turn on live sync" page. */
	emits: ['edit', 'add', 'changed', 'queued'],
	data() {
		return {
			busy: {},
			errors: {},
		}
	},

	computed: {
		removeHint() {
			return t('chat_archive', 'Turn off "Preserve the archive" first.')
		},
	},

	methods: {
		t,
		errorText,
		fmtDateTime,

		/**
		 * @param {object} s a source
		 * @return {'ok'|'importing'|'error'|'never'} the state of the chip
		 */
		state(s) {
			if (s.importState) {
				return 'importing'
			}
			if (s.lastError || (s.syncSession && s.syncError)) {
				return 'error'
			}
			return s.lastSynced || s.syncAt ? 'ok' : 'never'
		},

		/**
		 * The chip carries this text, so the state does not depend on colour.
		 *
		 * @param {object} s a source
		 * @return {string}
		 */
		stateLabel(s) {
			switch (this.state(s)) {
				case 'importing':
					return s.importState === 'queued' ? t('chat_archive', 'Queued') : t('chat_archive', 'Importing')
				case 'error':
					return t('chat_archive', 'Error')
				case 'ok':
					return s.backupAt && s.lastSynced && s.backupAt > s.lastSynced
						? t('chat_archive', 'New backup waiting')
						: t('chat_archive', 'Up to date')
				default:
					return s.mode === 'live' ? t('chat_archive', 'Not synced yet') : t('chat_archive', 'Not imported yet')
			}
		},

		/**
		 * @param {object} s a source
		 * @return {string} when the backup was written and when it was imported
		 */
		statusLine(s) {
			const parts = []
			if (s.backupAt) {
				parts.push(t('chat_archive', 'Backup written {date}', { date: fmtDate(s.backupAt * 1000) }, { escape: false, sanitize: false }))
			}
			if (s.lastSynced) {
				parts.push(t('chat_archive', 'imported {when}', { when: fmtDateTime(s.lastSynced * 1000) }, { escape: false, sanitize: false }))
			} else if (s.mode !== 'live') {
				parts.push(t('chat_archive', 'Never imported'))
			}
			if (s.mode === 'live') {
				parts.push(t('chat_archive', 'Live sync only, no backup file'))
			}
			return parts.join(' · ')
		},

		/**
		 * @param {object} s a source with a session
		 * @return {string}
		 */
		syncLine(s) {
			const on = t('chat_archive', 'Live sync: on (session {session})', { session: s.syncSession }, { escape: false, sanitize: false })
			const when = s.syncAt
				? t('chat_archive', 'synced {when}', { when: fmtDateTime(s.syncAt * 1000) }, { escape: false, sanitize: false })
				: t('chat_archive', 'not synced yet')
			return `${on} · ${when}`
		},

		/**
		 * A backup source needs one imported backup first (code backup_first).
		 *
		 * @param {object} s a source
		 * @return {boolean}
		 */
		canTurnOn(s) {
			return this.live.enabled && this.live.sessions.length > 0 && (s.mode === 'live' || !!s.lastBackup)
		},

		async importNow(s) {
			if (await this.call(s, () => api.importSource(s.id))) {
				this.$emit('queued')
			}
		},

		async turnOff(s) {
			// The empty string turns the live sync off.
			const payload = { provider: s.provider, label: s.label, folder: s.folder, onDelete: s.onDelete, syncSession: '' }
			if (await this.call(s, () => api.updateSource(s.id, payload))) {
				this.$emit('changed')
			}
		},

		async remove(s) {
			const ok = await showConfirmation({
				name: t('chat_archive', 'Remove source'),
				text: t('chat_archive', 'Remove "{label}"? The archive deletes all chats and messages of this source. The files in your Nextcloud folders stay.', { label: s.label }, { escape: false, sanitize: false }),
				labelConfirm: t('chat_archive', 'Remove'),
				labelReject: t('chat_archive', 'Cancel'),
				severity: 'error',
			})
			if (ok && await this.call(s, () => api.deleteSource(s.id))) {
				this.$emit('changed')
			}
		},

		/**
		 * Runs one request for a row and shows its error in the row.
		 *
		 * @param {object} s a source
		 * @param {() => Promise} fn the request
		 * @return {Promise<boolean>} true on success
		 */
		async call(s, fn) {
			this.busy[s.id] = true
			this.errors[s.id] = ''
			try {
				await fn()
				return true
			} catch (e) {
				this.errors[s.id] = errorText(e)
				return false
			} finally {
				this.busy[s.id] = false
			}
		},
	},
}
</script>

<style scoped>
.ca-list {
	max-width: 720px;
}
.ca-list__rows {
	margin: 0 0 12px;
	padding: 0;
	list-style: none;
}
.ca-row {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}
.ca-row__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 12px;
}
.ca-row__folder {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}
.ca-row__actions {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-inline-start: auto;
}
.ca-row__line {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 8px;
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}
.ca-row__line--error {
	color: var(--color-error-text);
}
.ca-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border: 1px solid currentColor;
	border-radius: var(--border-radius-pill);
	font-size: 0.9em;
}
.ca-chip--ok {
	color: var(--color-success-text);
}
.ca-chip--error {
	color: var(--color-error-text);
}
.ca-chip--importing {
	color: var(--color-primary-element-text-dark, var(--color-primary-element));
}
.ca-chip--never {
	color: var(--color-text-maxcontrast);
}
</style>
