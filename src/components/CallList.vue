<!--
  Call history of one source: filters, a jump to a date, and one row per
  call. A click on a row opens the chat at the time of the call.
-->
<template>
	<div class="ca-calls">
		<div class="ca-calls__head">
			<h2>{{ t('chat_archive', 'Calls') }}</h2>
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
				<template v-for="(c, i) in shown" :key="c.id">
					<div
						v-if="i === 0 || dayKey(c.startedAt) !== dayKey(shown[i - 1].startedAt)"
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
				<div v-if="rows.length > limit" :ref="observeSentinel" class="ca-calls__more" />
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
	{ id: 'no_answer', name: t('chat_archive', 'No answer') },
	{ id: 'connected', name: t('chat_archive', 'Connected') },
	{ id: 'rejected', name: t('chat_archive', 'Declined') },
	{ id: 'failed', name: t('chat_archive', 'Failed') },
]
// Rows in the page at the start, and rows that each step adds.
const PAGE = 200

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
		limit: PAGE, // number of rows in the page; it grows when the reader gets near the end
	}),

	computed: {
		/** The peer of a call is the platform chat id of its one-to-one or group chat. */
		peerChats() {
			return new Map(this.chats.filter((c) => c.platformChatId).map((c) => [c.platformChatId, c]))
		},

		/** Every call that matches the filters, newest first. */
		rows() {
			const who = this.f.who.trim().toLowerCase()
			const result = this.f.result.id
			const direction = this.f.direction.id
			return this.calls
				.filter((c) => {
					if (who && !this.peerName(c).toLowerCase().includes(who)) {
						return false
					}
					if (result && this.resultOf(c) !== result) {
						return false
					}
					return !((direction === 'in' && c.fromMe) || (direction === 'out' && !c.fromMe))
				})
				.map((c) => ({ ...c, chat: (c.peer && this.peerChats.get(c.peer)) || null }))
		},

		/**
		 * The rows in the page. A large archive has thousands of calls, and
		 * a row is about five nodes, so the page holds a part of them.
		 */
		shown() {
			return this.rows.slice(0, this.limit)
		},

		active() {
			return this.f.who.trim() !== '' || this.f.result.id !== '' || this.f.direction.id !== ''
		},

		countLabel() {
			return this.active
				? t('chat_archive', 'Calls: {n} of {total}', { n: this.rows.length, total: this.calls.length }, { escape: false, sanitize: false })
				: t('chat_archive', 'Calls: {n}', { n: this.calls.length }, { escape: false, sanitize: false })
		},
	},

	watch: {
		// A new filter starts at the first page again.
		f: {
			deep: true,
			handler() {
				this.limit = PAGE
			},
		},
	},

	created() {
		// Adds a page when the last rows come near the visible part.
		this.observer = new IntersectionObserver((entries) => {
			if (entries[0]?.isIntersecting) {
				this.limit += PAGE
			}
		}, { rootMargin: '600px' })
	},

	beforeUnmount() {
		this.observer.disconnect()
	},

	methods: {
		t,
		callLabel,

		/**
		 * Function ref of the row after the last call. Vue calls it with
		 * the element on each render and with null when the row goes.
		 *
		 * @param {?HTMLElement} el the row, or null
		 */
		observeSentinel(el) {
			this.observer.disconnect()
			if (el) {
				this.observer.observe(el)
			}
		},

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
		 * The filter value of a call. An outgoing call that nobody answered
		 * has the value "no_answer", so the "Missed" filter does not show it.
		 *
		 * @param {object} c a call row
		 * @return {string} the result id that the result filter compares
		 */
		resultOf(c) {
			return c.result === 'missed' && c.fromMe ? 'no_answer' : c.result
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
		 * that has a call. The rows are newest first and all of them are in
		 * memory, so this needs no request. The page grows to hold the target.
		 *
		 * @param {string} value the date input value, "YYYY-MM-DD"
		 */
		async jumpDate(value) {
			if (!value) {
				return
			}
			const end = new Date(value + 'T23:59:59.999').getTime()
			// Nothing that old: the oldest call is the nearest the archive has.
			const found = this.rows.findIndex((c) => c.startedAt <= end)
			const index = found === -1 ? this.rows.length - 1 : found
			const target = this.rows[index]
			if (!target) {
				return
			}
			if (index >= this.limit) {
				this.limit = index + PAGE
				await this.$nextTick()
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
/* Firefox cuts the year off in a narrower date field. */
.ca-calls__jump input {
	min-width: 11em;
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
/* An empty row: when it comes near the visible part, the next page loads. */
.ca-calls__more {
	height: 1px;
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
	color: var(--color-error-text);
}
</style>
