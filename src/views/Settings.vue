<!--
  Personal settings page: the sources, then the preferences.
  arch: D27.1
-->
<template>
	<NcSettingsSection
		:name="t('chat_archive', 'Chat Archive')"
		:description="t('chat_archive', 'WhatsApp writes the full backup about once a week. The archive imports it when it arrives.')">
		<h3>{{ t('chat_archive', 'Sources') }}</h3>
		<NcNoteCard v-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<SourceList
			:sources="sources"
			:live="live"
			:preserve="preserve"
			@edit="openEdit"
			@add="openAdd"
			@changed="reload"
			@queued="kickPoll" />

		<h3>{{ t('chat_archive', 'Preferences') }}</h3>
		<div class="ca-pref">
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="preserve"
				:loading="preserveBusy"
				@update:modelValue="savePreserve">
				{{ t('chat_archive', 'Preserve the archive (copy a moved file back when it is deleted; never remove a source)') }}
			</NcCheckboxRadioSwitch>
			<p v-if="preserveError" class="ca-pref__error" role="alert">
				{{ preserveError }}
			</p>
		</div>
		<fieldset class="ca-pref">
			<legend>{{ t('chat_archive', 'Left click on a file in a chat') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="o in clickOptions"
				:key="o.id"
				type="radio"
				name="ca-click"
				:value="o.id"
				:modelValue="click.action"
				@update:modelValue="saveClick({ action: $event })">
				{{ o.name }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-if="click.action === 'custom'"
				:modelValue="click.template"
				:label="t('chat_archive', 'URL template')"
				placeholder="myhandler://{path}"
				:helperText="t('chat_archive', '{path} becomes the URL-encoded path of the file, for example WhatsApp/Media/IMG.jpg. Your computer must know the scheme.')"
				:error="!!clickError"
				@update:modelValue="click.template = $event"
				@change="saveClick({})" />
			<p v-if="clickError" class="ca-pref__error" role="alert">
				{{ clickError }}
			</p>
		</fieldset>

		<SourceDialog
			v-if="dialog"
			:source="dialog.source"
			:providers="providers"
			:live="live"
			:preserve="preserve"
			:turnOnSync="dialog.turnOnSync"
			@saved="onSaved"
			@close="onClose" />
	</NcSettingsSection>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import SourceDialog from '../components/SourceDialog.vue'
import SourceList from '../components/SourceList.vue'
import { api } from '../api.js'
import { errorText } from '../errors.js'
import { pollImports } from '../util.js'

export default {
	name: 'PersonalSettings',
	components: { NcCheckboxRadioSwitch, NcNoteCard, NcSettingsSection, NcTextField, SourceDialog, SourceList },
	data() {
		return {
			sources: loadState('chat_archive', 'sources', []),
			providers: loadState('chat_archive', 'providers', []),
			preserve: loadState('chat_archive', 'preserve', true),
			live: loadState('chat_archive', 'live', { enabled: false, sessions: [] }),
			click: loadState('chat_archive', 'clickAction', { action: 'view', template: '' }),
			clickError: '',
			preserveBusy: false,
			preserveError: '',
			loadError: '',
			dialog: null, // {source, turnOnSync} while the dialog is open
			clickOptions: [
				{ id: 'view', name: t('chat_archive', 'Open in Nextcloud (viewer, or download when the viewer cannot show the file)') },
				{ id: 'nc', name: t('chat_archive', 'Open the local copy with the Nextcloud desktop client') },
				{ id: 'custom', name: t('chat_archive', 'Open with a URL template (for a WebDAV mount or a custom handler)') },
			],
		}
	},

	mounted() {
		// This view mounts only on the personal page, so the admin page never opens the dialog.
		if (this.sources.length === 0) {
			this.openAdd()
		}
		this.poll = pollImports(async () => {
			const ok = await this.reload()
			return !ok || this.sources.some((s) => s.importState)
		})
	},

	beforeUnmount() {
		this.poll.stop()
	},

	methods: {
		t,
		kickPoll() {
			this.poll.kick()
		},

		openAdd() {
			this.dialog = { source: null, turnOnSync: false }
		},

		/**
		 * @param {object} source the source to edit
		 * @param {boolean} turnOnSync true for the "Turn on live sync" page
		 */
		openEdit(source, turnOnSync = false) {
			this.dialog = { source, turnOnSync }
		},

		onSaved() {
			this.dialog = null
			this.kickPoll()
		},

		onClose() {
			// a source can exist although its first import could not be queued
			this.dialog = null
			this.reload()
		},

		async savePreserve(on) {
			this.preserveBusy = true
			try {
				this.preserve = (await api.setPreserve(on)).preserve
				this.preserveError = ''
			} catch (e) {
				this.preserveError = errorText(e)
			} finally {
				this.preserveBusy = false
			}
		},

		async saveClick(change) {
			try {
				this.click = await api.setClickAction({ ...this.click, ...change })
				this.clickError = ''
			} catch (e) {
				this.clickError = errorText(e)
			}
		},

		/**
		 * Loads the sources and the user's switches. An error shows a note.
		 *
		 * @return {Promise<boolean>} true when the request succeeded
		 */
		async reload() {
			try {
				const r = await api.settings()
				this.sources = r.sources
				this.providers = r.providers
				this.preserve = r.preserve
				this.live = r.live
				this.loadError = ''
				return true
			} catch (e) {
				this.loadError = t('chat_archive', 'Could not load the sources: {error}', { error: errorText(e) }, { escape: false, sanitize: false })
				return false
			}
		},
	},
}
</script>

<style scoped>
.ca-pref {
	max-width: 720px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}
.ca-pref legend {
	font-weight: bold;
}
.ca-pref__error {
	margin: 4px 0 0;
	color: var(--color-error-text);
}
</style>
