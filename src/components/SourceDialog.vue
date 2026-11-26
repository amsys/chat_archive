<!--
  Dialog to add a source (three steps), to edit one, or to turn on its
  live sync. All errors show in a note inside the dialog.
-->
<template>
	<NcDialog
		:name="title"
		size="normal"
		:open="true"
		@update:open="onOpen">
		<form class="ca-dlg" @submit.prevent="primary">
			<!-- add flow, step 1: what the source reads -->
			<template v-if="flow === 'add' && step === 1">
				<p class="ca-dlg__step">
					{{ stepText }}
				</p>
				<fieldset class="ca-dlg__group">
					<legend>{{ t('chat_archive', 'What does this source read?') }}</legend>
					<NcCheckboxRadioSwitch
						v-model="mode"
						type="radio"
						name="ca-mode"
						value="backup">
						{{ t('chat_archive', 'Phone backup: the phone uploads its encrypted WhatsApp backup to your Nextcloud') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-if="liveChoice"
						v-model="mode"
						type="radio"
						name="ca-mode"
						value="live">
						{{ t('chat_archive', 'Live sync only: the server reads new messages through WAHA, with no backup file') }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<template v-if="mode === 'live'">
					<NcNoteCard type="warning">
						{{ warning }}
					</NcNoteCard>
					<NcSelect
						v-model="session"
						:options="live.sessions"
						:clearable="false"
						:inputLabel="t('chat_archive', 'WhatsApp session for live sync')" />
					<NcCheckboxRadioSwitch v-model="tos" type="checkbox">
						{{ t('chat_archive', 'I understand the risk for my WhatsApp number.') }}
					</NcCheckboxRadioSwitch>
				</template>
			</template>

			<!-- add flow, step 2: backup instructions with key and folder, or name and folder -->
			<template v-if="flow === 'add' && step === 2">
				<p class="ca-dlg__step">
					{{ stepText }}
				</p>
				<div v-if="mode === 'backup'" class="ca-dlg__guide">
					<h4>{{ t('chat_archive', '1. Turn on the encrypted backup with a key (WhatsApp)') }}</h4>
					<ol>
						<li>{{ t('chat_archive', 'Open WhatsApp → Settings → Chats → Chat backup.') }}</li>
						<li>{{ t('chat_archive', 'Tap "End-to-end encrypted backup" → "Turn on".') }}</li>
						<li>{{ t('chat_archive', 'Choose "Use 64-digit encryption key instead" → "Generate your 64-digit key".') }}</li>
						<li>{{ t('chat_archive', 'Save the key in a safe place, for example a password manager. WhatsApp cannot recover it. You paste it below.') }}</li>
					</ol>
					<p>{{ t('chat_archive', 'From now on WhatsApp writes the nightly backup as msgstore.db.crypt15, encrypted with that key.') }}</p>
					<h4>{{ t('chat_archive', '2. Upload the backup and the media (Nextcloud app on the phone)') }}</h4>
					<p>{{ t('chat_archive', 'The WhatsApp folder is not a photo folder, so the Nextcloud app needs the "All files access" permission:') }}</p>
					<ol>
						<li>{{ t('chat_archive', 'Android Settings → Apps → Special app access → All files access → Nextcloud → Allow.') }}</li>
					</ol>
					<p>{{ t('chat_archive', 'Then add the WhatsApp folders to auto upload:') }}</p>
					<ol>
						<li>{{ t('chat_archive', 'Nextcloud app → menu → Settings → Auto upload → "+" (add custom folder).') }}</li>
						<li>{{ t('chat_archive', 'Pick Internal storage → Android → media → com.whatsapp → WhatsApp → Databases. On phones set up before 2021 the folder is Internal storage → WhatsApp.') }}</li>
						<li>{{ t('chat_archive', 'Set the remote folder, for example /WhatsApp/Databases.') }}</li>
						<li>{{ t('chat_archive', 'Tick "Also upload existing files". Leave the original file in place.') }}</li>
						<li>{{ t('chat_archive', 'Repeat for the media folders you want in the archive: Media → WhatsApp Images, WhatsApp Video, WhatsApp Documents, WhatsApp Audio, WhatsApp Voice Notes. Put them under the same parent, for example /WhatsApp/Media/WhatsApp Images. The layout does not have to match the phone; the archive also finds files by name.') }}</li>
					</ol>
					<p>{{ t('chat_archive', 'WhatsApp Business keeps its folder under Android → media → com.whatsapp.w4b. Add it as a second source.') }}</p>
				</div>
				<NcTextField v-if="mode === 'live'" v-model="label" :label="t('chat_archive', 'Name (e.g. WhatsApp Business)')" />
				<NcPasswordField
					v-if="mode === 'backup' && needsSecret"
					v-model="secret"
					:label="t('chat_archive', 'Backup key (64 characters)')" />
				<div class="ca-dlg__row">
					<NcTextField v-model="folder" :label="folderLabel" :helperText="folderHelp" />
					<NcButton variant="secondary" @click="pickFolder">
						{{ t('chat_archive', 'Choose…') }}
					</NcButton>
				</div>
			</template>

			<!-- add flow, step 3: check, name and delete policy -->
			<template v-if="flow === 'add' && step === 3">
				<p class="ca-dlg__step">
					{{ stepText }}
				</p>
				<template v-if="mode === 'backup'">
					<p v-if="probing" class="ca-dlg__probe">
						<NcLoadingIcon :size="16" />
						{{ t('chat_archive', 'Looking for the backup file…') }}
					</p>
					<NcNoteCard v-else-if="probe && probe.found" type="success">
						{{ probeFound }}
					</NcNoteCard>
					<NcNoteCard v-else-if="probe" type="info">
						{{ t('chat_archive', 'No backup file in this folder yet. You can add the source now; the import starts when the phone uploads the backup.') }}
					</NcNoteCard>
					<NcTextField v-model="label" :label="t('chat_archive', 'Name (e.g. WhatsApp Business)')" />
				</template>
				<p v-else>
					{{ t('chat_archive', 'The first sync starts with the next background job. It loads the chat history.') }}
				</p>
				<NcSelect
					v-if="!preserve"
					v-model="onDelete"
					:options="policies"
					label="name"
					:clearable="false"
					:inputLabel="t('chat_archive', 'When a linked file is deleted elsewhere in Nextcloud')" />
			</template>

			<!-- edit flow: one page -->
			<template v-if="flow === 'edit'">
				<NcTextField v-model="label" :label="t('chat_archive', 'Name (e.g. WhatsApp Business)')" />
				<div class="ca-dlg__row">
					<NcTextField v-model="folder" :label="folderLabel" :helperText="folderHelp" />
					<NcButton variant="secondary" @click="pickFolder">
						{{ t('chat_archive', 'Choose…') }}
					</NcButton>
				</div>
				<NcPasswordField
					v-if="mode === 'backup' && needsSecret"
					v-model="secret"
					:label="source.hasSecret ? t('chat_archive', 'Backup key (leave empty to keep the stored one)') : t('chat_archive', 'Backup key (64 characters)')" />
				<NcSelect
					v-if="!preserve"
					v-model="onDelete"
					:options="policies"
					label="name"
					:clearable="false"
					:inputLabel="t('chat_archive', 'When a linked file is deleted elsewhere in Nextcloud')" />
			</template>

			<!-- turn on live sync: one page -->
			<template v-if="flow === 'sync'">
				<NcNoteCard type="warning">
					{{ warning }}
				</NcNoteCard>
				<NcSelect
					v-model="session"
					:options="live.sessions"
					:clearable="false"
					:inputLabel="t('chat_archive', 'WhatsApp session for live sync')" />
				<NcCheckboxRadioSwitch v-model="tos" type="checkbox">
					{{ t('chat_archive', 'I understand the risk for my WhatsApp number.') }}
				</NcCheckboxRadioSwitch>
			</template>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</form>

		<template #actions>
			<NcButton
				v-if="flow === 'add' && step > 1"
				variant="secondary"
				:disabled="busy"
				@click="back">
				{{ t('chat_archive', 'Back') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canGo" @click="primary">
				{{ primaryText }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSelect, NcTextField } from '@nextcloud/vue'
import { api } from '../api.js'
import { errorText } from '../errors.js'
import { fmtDateTime, fmtSize } from '../util.js'

export default {
	name: 'SourceDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSelect, NcTextField },
	props: {
		/** The source to edit; null adds a new one. */
		source: { type: Object, default: null },
		providers: { type: Array, required: true },
		/** `{enabled, sessions}` of GET /settings/sources */
		live: { type: Object, required: true },
		/** The user's preserve switch: when on, the delete policy is fixed and hidden. */
		preserve: { type: Boolean, default: false },
		/** Show the "Turn on live sync" page for `source`. */
		turnOnSync: { type: Boolean, default: false },
	},

	emits: ['saved', 'close'],
	data() {
		const policies = [
			{ id: 'restore', name: t('chat_archive', 'Copy it back to the backup folder first') },
			{ id: 'unlink', name: t('chat_archive', 'Forget it; the message shows "not in Nextcloud"') },
			{ id: 'keep', name: t('chat_archive', 'Keep the link; a restore from trash makes it work again') },
		]
		const s = this.source
		const provider = this.providers.find((p) => p.id === (s?.provider ?? 'whatsapp')) ?? this.providers[0] ?? null
		return {
			policies,
			step: 1,
			mode: s?.mode ?? 'backup',
			provider,
			label: s?.label ?? provider?.name ?? '',
			folder: s?.folder ?? '',
			secret: '',
			onDelete: policies.find((p) => p.id === (s?.onDelete ?? 'restore')) ?? policies[0],
			session: this.live.sessions.length === 1 ? this.live.sessions[0] : null,
			tos: false,
			probe: null,
			probing: false,
			createdId: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @return {'add'|'edit'|'sync'} */
		flow() {
			if (!this.source) {
				return 'add'
			}
			return this.turnOnSync ? 'sync' : 'edit'
		},

		title() {
			if (this.flow === 'add') {
				return t('chat_archive', 'Add a source')
			}
			return this.flow === 'sync'
				? t('chat_archive', 'Turn on live sync')
				: t('chat_archive', 'Edit {label}', { label: this.source.label }, { escape: false, sanitize: false })
		},

		stepText() {
			return t('chat_archive', 'Step {n} of 3', { n: this.step }, { escape: false, sanitize: false })
		},

		/** The second mode exists only when the admin allows live sync and assigned a session to the user. */
		liveChoice() {
			return this.live.enabled && this.live.sessions.length > 0
		},

		needsSecret() {
			return !!this.provider?.needsSecret
		},

		// The same translated string as in the admin settings
		warning() {
			return t('chat_archive', 'WAHA is an unofficial WhatsApp client. Its use is against the WhatsApp Terms of Service, and WhatsApp can restrict or ban a number that uses one. Chat Archive only reads; it never sends. In daily use it reads the few messages since the last sync. The first sync of a live-only source loads the history and, when the administrator allows it, its media in a short time, which can look like automation. Start from a phone backup when you can.')
		},

		folderLabel() {
			return this.mode === 'live' ? t('chat_archive', 'Folder') : t('chat_archive', 'Backup folder')
		},

		folderHelp() {
			return this.mode === 'live'
				? t('chat_archive', 'Media files from the live sync go into the subfolder WAHA of this folder.')
				: t('chat_archive', 'The parent folder of the uploads, for example /WhatsApp.')
		},

		probeFound() {
			const p = this.probe
			return t('chat_archive', '{name} found, {size}, written {date}', {
				name: p.name,
				size: fmtSize(p.size ?? 0),
				date: fmtDateTime((p.mtime ?? 0) * 1000),
			}, { escape: false, sanitize: false })
		},

		primaryText() {
			if (this.flow === 'edit') {
				return t('chat_archive', 'Save')
			}
			if (this.flow === 'sync') {
				return t('chat_archive', 'Turn on live sync')
			}
			if (this.step < 3) {
				return t('chat_archive', 'Next')
			}
			return this.mode === 'live' ? t('chat_archive', 'Add and sync') : t('chat_archive', 'Add and import')
		},

		/** @return {boolean} the primary button is enabled */
		canGo() {
			if (this.busy || this.probing) {
				return false
			}
			if (this.flow === 'sync') {
				return !!this.session && this.tos
			}
			if (this.flow === 'edit' || this.step === 3) {
				return true
			}
			if (this.step === 1) {
				return this.mode === 'backup' || (!!this.session && this.tos)
			}
			return this.stepTwoDone
		},

		/** Step 2 needs a folder, and a key for a new backup source, or a name for a live one. */
		stepTwoDone() {
			if (this.folder.trim() === '') {
				return false
			}
			if (this.mode === 'live') {
				return this.label.trim() !== ''
			}
			return !this.needsSecret || this.secret.trim() !== ''
		},
	},

	methods: {
		t,

		onOpen(open) {
			if (!open) {
				this.$emit('close')
			}
		},

		back() {
			this.error = ''
			this.step--
		},

		async primary() {
			if (!this.canGo) {
				return
			}
			this.error = ''
			if (this.flow === 'add' && this.step < 3) {
				this.step++
				if (this.step === 3 && this.mode === 'backup') {
					await this.runProbe()
				}
				return
			}
			const save = { add: this.add, edit: this.edit, sync: this.turnOn }[this.flow]
			await this.run(save)
		},

		/** Looks for the backup file and format-checks the key. */
		async runProbe() {
			this.probing = true
			this.probe = null
			try {
				this.probe = await api.probe(this.folder, this.secret)
			} catch (e) {
				this.error = errorText(e)
			} finally {
				this.probing = false
			}
		},

		async pickFolder() {
			try {
				const path = await getFilePickerBuilder(t('chat_archive', 'Choose a folder'))
					.setMultiSelect(false)
					.allowDirectories(true)
					.setMimeTypeFilter(['httpd/unix-directory'])
					.addButton({ label: t('chat_archive', 'Choose'), variant: 'primary', callback: () => {} })
					.build()
					.pick()
				if (path) {
					this.folder = path
				}
			} catch (e) {
				if (!(e instanceof FilePickerClosed)) {
					this.error = errorText(e)
				}
			}
		},

		/**
		 * Creates the source once, then queues its first import or sync.
		 * A second click after a failed queue does not create a second source.
		 * On that second click it first saves the current fields to the
		 * source, so a change after Back takes effect. The server keeps the
		 * mode of the source as it was created.
		 */
		async add() {
			const live = this.mode === 'live'
			const fields = {
				provider: this.provider?.id,
				label: this.label,
				folder: this.folder,
				secret: live ? null : this.secret || null,
				onDelete: this.onDelete.id,
				mode: this.mode,
				syncSession: live ? this.session : null,
				tos: live && this.tos,
			}
			if (this.createdId === null) {
				this.createdId = (await api.createSource(fields)).id
			} else {
				await api.updateSource(this.createdId, fields)
			}
			await api.importSource(this.createdId)
		},

		async edit() {
			await api.updateSource(this.source.id, {
				provider: this.source.provider,
				label: this.label,
				folder: this.folder,
				secret: this.secret || null,
				onDelete: this.onDelete.id,
			})
		},

		async turnOn() {
			const s = this.source
			await api.updateSource(s.id, {
				provider: s.provider,
				label: s.label,
				folder: s.folder,
				onDelete: s.onDelete,
				syncSession: this.session,
				tos: this.tos,
			})
		},

		/**
		 * Runs one save request; closes the dialog on success.
		 *
		 * @param {() => Promise} fn the request
		 */
		async run(fn) {
			this.busy = true
			try {
				await fn()
				this.$emit('saved')
			} catch (e) {
				this.error = errorText(e)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.ca-dlg {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-bottom: 8px;
}
.ca-dlg__step {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
.ca-dlg__group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.ca-dlg__group legend {
	font-weight: bold;
}
.ca-dlg__row {
	display: flex;
	gap: 8px;
	align-items: flex-start;
}
.ca-dlg__row > :first-child {
	flex: 1;
}
.ca-dlg__row > button {
	flex: 0 0 auto;
	margin-top: 6px;
}
.ca-dlg__probe {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin: 0;
}
.ca-dlg__guide h4 {
	margin: 12px 0 4px;
	font-weight: bold;
}
.ca-dlg__guide ol {
	margin: 4px 0;
	padding-inline-start: 24px;
	list-style: decimal;
}
.ca-dlg__guide li,
.ca-dlg__guide p {
	margin: 4px 0;
}
</style>
