<template>
	<div class="ca-calls">
		<div class="ca-calls__head">
			<h2>{{ t('chat_archive', 'Call Log') }}</h2>
			<label class="ca-calls__jump">
				{{ t('chat_archive', 'Jump to date') }}
				<input type="date" @change="jumpDate($event.target.value)">
			</label>
		</div>

		<div class="ca-calls__filters">
			<NcTextField v-model="f.who" :label="t('chat_archive', 'Contact')" />
			<NcSelect
				v-model="f.result"
				:options="results"
				label="name"
				:clearable="false"
				:inputLabel="t('chat_archive', 'Result')" />
			<NcSelect
				v-model="f.direction"
				:options="directions"
				label="name"
				:clearable="false"
				:inputLabel="t('chat_archive', 'Direction')" />
			<NcButton v-if="active" variant="tertiary" @click="clear">
				{{ t('chat_archive', 'Clear filters') }}
			</NcButton>
		</div>
		<p class="ca-calls__count" aria-live="polite">
			{{ countLabel }}
		</p>

		<div ref="scroller" class="ca-calls__scroll">
			<NcEmptyContent v-if="calls.length === 0" :name="t('chat_archive', 'No calls')" />
			<NcEmptyContent
				v-else-if="rows.length === 0"
				:name="t('chat_archive', 'No call matches')"
				:description="t('chat_archive', 'Change the filters to see more calls.')" />
			<template v-else>
				<template v-for="(c, i) in rows" :key="c.id">
					<div
						v-if="i === 0 || dayKey(c.startedAt) !== dayKey(rows[i - 1].startedAt)"
						class="ca-day"
						:data-day="dayKey(c.startedAt)">
						{{ fmtRelDate(c.startedAt) }}
					</div>
					<component
						:is="c.chat ? 'button' : 'div'"
						class="ca-call"
						:class="{ 'ca-call--open': !!c.chat, 'ca-call--missed': missed(c) }"
						:data-id="c.id"
						@click="open(c)">
						<component :is="icon(c)" :size="20" class="ca-call__icon" />
						<span class="ca-call__peer" dir="auto">{{ peerName(c) }}</span>
						<span class="ca-call__label">{{ callLabel({ call: c, fromMe: c.fromMe }) }}</span>
						<time
							class="ca-call__time"
							:datetime="new Date(c.startedAt).toISOString()"
							:title="fmtDateTime(c.startedAt)">
							{{ fmtTime(c.startedAt) }}
						</time>
						<span v-if="c.chat" class="ca-call__action">{{ t('chat_archive', 'Open the chat') }}</span>
					</component>
				</template>
			</template>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcSelect, NcTextField } from '@nextcloud/vue'
import PhoneIncoming from 'vue-material-design-icons/PhoneIncoming.vue'
import PhoneMissed from 'vue-material-design-icons/PhoneMissed.vue'
import PhoneOutgoing from 'vue-material-design-icons/PhoneOutgoing.vue'
import { callLabel, dayKey, fmtDateTime, fmtRelDate, fmtTime } from '../util.js'

const RESULTS = [
	{ id: '', name: t('chat_archive', 'Any result') },
	{ id: 'missed', name: t('chat_archive', 'Missed') },
	{ id: 'connected', name: t('chat_archive', 'Connected') },
	{ id: 'rejected', name: t('chat_archive', 'Declined') },
	{ id: 'failed', name: t('chat_archive', 'Failed') },
]
const DIRECTIONS = [
	{ id: '', name: t('chat_archive', 'Any direction') },
	{ id: 'in', name: t('chat_archive', 'Incoming') },
	{ id: 'out', name: t('chat_archive', 'Outgoing') },
]

export default {
	name: 'CallList',
	components: { NcButton, NcEmptyContent, NcSelect, NcTextField },
	props: {
		calls: { type: Array, required: true },
		chats: { type: Array, required: true }, // chats of the same source, for the peer to chat map
	},

	emits: ['open'],
	data: () => ({
		results: RESULTS,
		directions: DIRECTIONS,
		f: { who: '', result: RESULTS[0], direction: DIRECTIONS[0] },
	}),

	computed: {
		/** The peer of a call is the platform chat id of its one-to-one or group chat. */
		peerChats() {
			return new Map(this.chats.filter((c) => c.platformChatId).map((c) => [c.platformChatId, c]))
		},

		/*
		 * ponytail: every matching call renders; there is no window. At the
		 * 5000-row cap of ArchiveStore::calls() that is about 25 000 nodes. If a
		 * large archive paints slowly, cap the rows with the limit plus
		 * IntersectionObserver sentinel that App.vue uses for the chat list, and
		 * make jumpDate() raise the limit past the target row.
		 */
		rows() {
			const who = this.f.who.trim().toLowerCase()
			const result = this.f.result.id
			const direction = this.f.direction.id
			return this.calls
				.filter((c) => {
					if (who && !this.peerName(c).toLowerCase().includes(who)) {
						return false
					}
					if (result && c.result !== result) {
						return false
					}
					return !((direction === 'in' && c.fromMe) || (direction === 'out' && !c.fromMe))
				})
				.map((c) => ({ ...c, chat: (c.peer && this.peerChats.get(c.peer)) || null }))
		},

		active() {
			return this.f.who.trim() !== '' || this.f.result.id !== '' || this.f.direction.id !== ''
		},

		countLabel() {
			return this.active
				? t('chat_archive', '{n} of {total} calls', { n: this.rows.length, total: this.calls.length }, { escape: false, sanitize: false })
				: t('chat_archive', '{n} calls', { n: this.calls.length }, { escape: false, sanitize: false })
		},
	},

	methods: {
		t,
		callLabel,
		dayKey,
		fmtDateTime,
		fmtRelDate,
		fmtTime,

		/**
		 * @param {object} c a call row
		 * @return {string} the contact name, or the raw peer id when no name is known
		 */
		peerName(c) {
			return c.peerName || c.peer || ''
		},

		/**
		 * An outgoing call nobody answered is not a missed call; it is "no answer".
		 *
		 * @param {object} c a call row
		 * @return {boolean} true if the user missed this call
		 */
		missed(c) {
			return c.result === 'missed' && !c.fromMe
		},

		/**
		 * @param {object} c a call row
		 * @return {object} the icon component for its direction and result
		 */
		icon(c) {
			if (this.missed(c)) {
				return PhoneMissed
			}
			return c.fromMe ? PhoneOutgoing : PhoneIncoming
		},

		open(c) {
			if (c.chat) {
				this.$emit('open', { chat: c.chat, at: c.startedAt })
			}
		},

		/**
		 * Scroll to the day band of the chosen date, or of the next older day
		 * that has a call. The rows are newest first and all of them render, so
		 * this needs no request.
		 *
		 * @param {string} value the date input value, "YYYY-MM-DD"
		 */
		jumpDate(value) {
			if (!value) {
				return
			}
			const end = new Date(value + 'T23:59:59.999').getTime()
			// Nothing that old: the oldest call is the nearest the archive has.
			const target = this.rows.find((c) => c.startedAt <= end) ?? this.rows[this.rows.length - 1]
			if (!target) {
				return
			}
			// The target is the newest visible call of its day, so its band is right above it.
			const el = this.$refs.scroller.querySelector(`[data-day="${dayKey(target.startedAt)}"]`)
			el?.scrollIntoView({ block: 'start' })
		},

		clear() {
			this.f = { who: '', result: RESULTS[0], direction: DIRECTIONS[0] }
		},
	},
}
</script>

<style scoped>
.ca-calls {
	display: flex;
	flex-direction: column;
	height: 100%;
}
.ca-calls__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	/* Same box as the navigation toggle, so the two share a centre line. */
	box-sizing: border-box;
	min-height: var(--default-clickable-area, 44px);
	padding: var(--app-navigation-padding, 4px) 16px;
	padding-left: calc(var(--default-clickable-area, 44px) + 2 * var(--app-navigation-padding, 4px));
	border-bottom: 1px solid var(--color-border);
}
.ca-calls__head h2 {
	margin: 0;
	font-size: 1.1em;
}
.ca-calls__jump {
	display: flex;
	gap: 8px;
	align-items: center;
	color: var(--color-text-maxcontrast);
}
.ca-calls__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 12px;
	align-items: flex-end;
	padding: 8px 16px;
}
.ca-calls__filters > * {
	min-width: 160px;
	flex: 1 1 160px;
	max-width: 260px;
}
.ca-calls__filters > button {
	flex: 0 0 auto;
	min-width: 0;
}
.ca-calls__count {
	margin: 0;
	padding: 0 16px 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	border-bottom: 1px solid var(--color-border);
}
.ca-calls__scroll {
	flex: 1;
	overflow-y: auto;
	padding: 8px 16px;
}
.ca-day {
	position: sticky;
	top: 4px;
	z-index: 1;
	/* A pill, not a band: the pattern and the messages stay visible beside it. */
	width: fit-content;
	margin: 12px auto 8px;
	padding: 4px 12px;
	border-radius: var(--border-radius-pill, 100px);
	background: var(--color-background-dark);
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	text-align: center;
}
.ca-call {
	display: grid;
	grid-template-columns: auto 1fr auto;
	grid-template-areas:
		'icon peer time'
		'icon label time';
	align-items: center;
	column-gap: 12px;
	width: 100%;
	box-sizing: border-box;
	margin: 2px 0;
	padding: 6px 8px;
	border: none;
	border-radius: var(--border-radius-large);
	background: transparent;
	color: inherit;
	font: inherit;
	text-align: left;
}
.ca-call--open {
	cursor: pointer;
}
.ca-call--open:hover {
	background: var(--color-background-hover);
}
.ca-call--open:focus-visible {
	outline: 2px solid var(--color-primary-element);
}
.ca-call__icon {
	grid-area: icon;
	color: var(--color-text-maxcontrast);
}
.ca-call__peer {
	grid-area: peer;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.ca-call__label {
	grid-area: label;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
.ca-call__time {
	grid-area: time;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
.ca-call__action {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}
.ca-call--missed .ca-call__icon,
.ca-call--missed .ca-call__label {
	color: var(--color-error);
}
</style>
