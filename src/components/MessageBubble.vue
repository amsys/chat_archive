<!--
  One message in a chat: sender, text or call line, media, time, and the
  marks for a deleted or edited message.
-->
<template>
	<div
		class="ca-msg"
		tabindex="-1"
		:class="{ 'ca-msg--me': message.fromMe, 'ca-msg--grouped': sameAsPrevious, 'ca-msg--hl': highlight, 'ca-msg--system': message.kind === 'system', 'ca-msg--deleted': message.deleted }">
		<div class="ca-msg__bubble">
			<div v-if="showSender && !message.fromMe && !sameAsPrevious && senderName" class="ca-msg__sender" dir="auto">
				{{ senderName }}
			</div>
			<MediaView v-if="message.media" :media="message.media" :kind="message.kind" />
			<div v-if="isCall" class="ca-msg__call">
				<component :is="callIcon" :size="18" />
				<span>{{ callLabel(message) }}</span>
			</div>
			<div v-else-if="message.latestBody ?? message.body" class="ca-msg__body" dir="auto">
				{{ message.latestBody ?? message.body }}
			</div>
			<div v-else-if="!message.media && message.kind !== 'text'" class="ca-msg__kind">
				{{ kindLabel(message.kind) }}
			</div>
			<div class="ca-msg__meta">
				<span v-if="message.deleted" class="ca-msg__chip" :title="deletedTitle">
					<TrashCanOutline :size="14" />
					{{ t('chat_archive', 'Deleted') }}
				</span>
				<NcPopover v-if="message.editedAt" @update:shown="onHistoryShown">
					<template #trigger="{ attrs }">
						<button type="button" class="ca-msg__chip ca-msg__chip--button" v-bind="attrs">
							{{ t('chat_archive', 'Edited') }}
						</button>
					</template>
					<div class="ca-msg__edit-panel">
						<h4 class="ca-msg__edit-heading">
							{{ t('chat_archive', 'Edit history') }}
						</h4>
						<NcLoadingIcon v-if="loadingHistory" :size="16" />
						<template v-else>
							<div class="ca-msg__version">
								<span class="ca-msg__version-label">{{ t('chat_archive', 'Original') }}</span>
								<span class="ca-msg__version-body" dir="auto">{{ message.body }}</span>
							</div>
							<div v-for="(v, i) in history" :key="i" class="ca-msg__version">
								<span class="ca-msg__version-label">{{ t('chat_archive', 'Seen in backup of {date}', { date: fmtDate(v.seenAt) }) }}</span>
								<span class="ca-msg__version-body" dir="auto">{{ v.body }}</span>
							</div>
						</template>
					</div>
				</NcPopover>
				<time class="ca-msg__time" :datetime="new Date(message.sentAt).toISOString()" :title="fmtDateTime(message.sentAt)">
					{{ fmtTime(message.sentAt) }}
				</time>
			</div>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcPopover } from '@nextcloud/vue'
import Phone from 'vue-material-design-icons/Phone.vue'
import PhoneMissed from 'vue-material-design-icons/PhoneMissed.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Video from 'vue-material-design-icons/Video.vue'
import MediaView from './MediaView.vue'
import { api } from '../api.js'
import { callLabel, fmtDate, fmtDateTime, fmtTime, kindLabel } from '../util.js'

export default {
	name: 'MessageBubble',
	components: { MediaView, NcLoadingIcon, NcPopover, TrashCanOutline },
	props: {
		message: { type: Object, required: true },
		senderName: { type: String, default: null },
		showSender: { type: Boolean, default: false },
		sameAsPrevious: { type: Boolean, default: false },
		highlight: { type: Boolean, default: false },
	},

	data() {
		return {
			loadingHistory: false,
			history: null, // cached after the first load, so a second popover open does not refetch
		}
	},

	computed: {
		isCall() {
			return this.message.kind === 'call_log' || this.message.kind === 'missed_call'
		},

		callIcon() {
			const c = this.message.call
			if (c?.result === 'missed' && !this.message.fromMe) {
				return PhoneMissed
			}
			return c?.video ? Video : Phone
		},

		deletedTitle() {
			return this.message.deleted === 'revoked'
				? t('chat_archive', 'Deleted for everyone')
				: t('chat_archive', 'Deleted from the phone')
		},
	},

	methods: {
		t,
		callLabel,
		fmtDate,
		fmtDateTime,
		fmtTime,
		kindLabel,

		async onHistoryShown(shown) {
			if (!shown || this.history !== null) {
				return
			}
			this.loadingHistory = true
			try {
				const r = await api.edits(this.message.id)
				this.history = r.edits
			} catch {
				showError(t('chat_archive', 'Could not load the edit history'))
				this.history = []
			} finally {
				this.loadingHistory = false
			}
		},
	},
}
</script>

<style scoped>
.ca-msg {
	display: flex;
	margin: 2px 0;
}
.ca-msg--grouped {
	margin-top: 0;
}
.ca-msg--me {
	justify-content: flex-end;
}
.ca-msg--system {
	justify-content: center;
	opacity: 0.7;
	font-size: 0.9em;
}
.ca-msg__bubble {
	max-width: min(70%, 640px);
	padding: 6px 10px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
	outline: 2px solid transparent;
	transition: outline-color 0.3s ease;
	white-space: pre-wrap;
	word-break: break-word;
}
.ca-msg--me .ca-msg__bubble {
	background: var(--color-primary-element-light);
}
.ca-msg--hl .ca-msg__bubble {
	outline-color: var(--color-primary-element);
}
.ca-msg__sender {
	font-weight: bold;
	font-size: 0.9em;
	color: var(--color-primary-element);
}
.ca-msg__kind {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
.ca-msg__call {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
}
.ca-msg--deleted .ca-msg__body,
.ca-msg--deleted .ca-media {
	opacity: 0.7;
}
.ca-msg__meta {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 4px;
}
.ca-msg__chip {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	padding: 0 6px;
	border-radius: var(--border-radius-pill);
	/* A tint of the text colour shows on incoming and outgoing bubbles. */
	background: color-mix(in srgb, currentColor 14%, transparent);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	line-height: 18px;
}
/* Reset the Nextcloud button style, so the edited chip looks like the deleted chip. */
button.ca-msg__chip--button {
	min-height: 0;
	margin: 0;
	padding: 0 6px;
	border: none;
	background: color-mix(in srgb, currentColor 14%, transparent);
	font-weight: normal;
	cursor: pointer;
}
.ca-msg__time {
	display: block;
	text-align: right;
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
}
.ca-msg__edit-panel {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.85em;
	padding: 12px;
	max-width: 320px;
	max-height: 50vh;
	overflow-y: auto;
}
.ca-msg__edit-heading {
	margin: 0;
	font-size: 1em;
}
.ca-msg__version-label {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
.ca-msg__version-body {
	white-space: pre-wrap;
	word-break: break-word;
}
</style>
