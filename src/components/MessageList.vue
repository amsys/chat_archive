<template>
	<div class="ca-chat">
		<div class="ca-chat__head">
			<div class="ca-chat__title">
				<NcButton
					v-if="showBackToResults"
					variant="tertiary"
					:aria-label="t('chat_archive', 'Back to results')"
					@click="$emit('back')">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
				</NcButton>
				<h2 dir="auto">
					{{ chat.name }}
				</h2>
			</div>
			<label class="ca-chat__jump">
				{{ t('chat_archive', 'Jump to date') }}
				<input type="date" @change="jumpDate($event.target.value)">
			</label>
		</div>
		<p v-if="statusLine" class="ca-chat__status">
			{{ statusLine }}
		</p>
		<div
			ref="scroller"
			class="ca-chat__scroll"
			tabindex="0"
			:aria-label="t('chat_archive', 'Messages')"
			@scroll="onScroll">
			<div v-if="loading && messages.length === 0" class="ca-chat__note">
				<NcLoadingIcon :size="20" />
			</div>
			<div v-if="loading && loadingMode === 'prepend'" class="ca-chat__spinner">
				<NcLoadingIcon :size="20" />
			</div>
			<template v-for="(m, i) in messages" :key="m.id">
				<div v-if="i === 0 || dayKey(m.sentAt) !== dayKey(messages[i - 1].sentAt)" class="ca-day">
					{{ fmtRelDate(m.sentAt) }}
				</div>
				<MessageBubble
					:message="m"
					:senderName="m.sender ? senders[m.sender] : null"
					:showSender="chat.isGroup"
					:sameAsPrevious="i > 0 && sameGroup(m, messages[i - 1])"
					:highlight="highlightId === m.id"
					:data-id="m.id" />
			</template>
			<div v-if="loading && loadingMode === 'append'" class="ca-chat__spinner">
				<NcLoadingIcon :size="20" />
			</div>
			<NcEmptyContent v-if="messages.length === 0 && !loading" :name="t('chat_archive', 'No messages')" />
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import MessageBubble from './MessageBubble.vue'
import { api } from '../api.js'
import { dayKey, fmtDate, fmtRelDate } from '../util.js'

const PAGE = 100
/** Consecutive messages from the same sender within this gap are grouped, hiding the repeated sender name. */
const GROUP_GAP_MS = 5 * 60 * 1000
const HIGHLIGHT_MS = 2000
/** An open chat of a live source asks for new messages at this interval. */
const LIVE_MS = 8000

export default {
	name: 'MessageList',
	components: { MessageBubble, NcButton, NcEmptyContent, NcLoadingIcon, ArrowLeft },
	props: {
		chat: { type: Object, required: true },
		jumpTo: { type: Object, default: null }, // { at: ms, id }
		reloadSeq: { type: Number, default: 0 }, // bumped by App.vue when a view toggle changes
		live: { type: Boolean, default: false }, // realtime is on and the source has a session
		showBackToResults: { type: Boolean, default: false },
	},

	emits: ['back'],

	data() {
		return {
			messages: [],
			senders: {},
			loading: false,
			loadingMode: null,
			olderDone: false,
			newerDone: false,
			highlightId: null,
			seq: 0, // only the newest request may write to messages
		}
	},

	computed: {
		/**
		 * The header note under the chat name: archived, gone, or both.
		 * Empty when neither fact holds.
		 *
		 * @return {string}
		 */
		statusLine() {
			const parts = []
			if (this.chat.phoneArchived) {
				parts.push(t('chat_archive', 'Archived on the phone'))
			}
			if (this.chat.goneAt !== null) {
				parts.push(t('chat_archive', 'Deleted from the phone on {date}', { date: fmtDate(this.chat.goneAt) }, { escape: false, sanitize: false }))
			}
			return parts.join(' · ')
		},
	},

	watch: {
		jumpTo: 'reset',
		reloadSeq: 'reset',
	},

	mounted() {
		this.reset()
		if (this.live) {
			this.liveTimer = setTimeout(this.liveTick, LIVE_MS)
		}
	},

	beforeUnmount() {
		clearTimeout(this.highlightTimer)
		clearTimeout(this.liveTimer)
		this.liveTimer = null
	},

	methods: {
		t,
		dayKey,
		fmtRelDate,
		/**
		 * @param {object} m a message
		 * @param {object} prev the one before it
		 * @return {boolean} true if they should render as one group
		 */
		sameGroup(m, prev) {
			return m.fromMe === prev.fromMe
				&& m.sender === prev.sender
				&& dayKey(m.sentAt) === dayKey(prev.sentAt)
				&& m.sentAt - prev.sentAt < GROUP_GAP_MS
		},

		async reset() {
			this.messages = []
			this.olderDone = this.newerDone = false
			if (this.jumpTo) {
				if (!await this.load({ at: this.jumpTo.at }, 'replace')) {
					return // a newer request superseded this one
				}
				this.newerDone = this.messages.length < PAGE
				// Load older context before the target message for centering
				if (this.messages.length > 0) {
					const first = this.messages[0]
					if (!await this.load({ beforeAt: first.sentAt, beforeId: first.id }, 'prepend')) {
						return // a newer request superseded this one
					}
				}
				this.$nextTick(() => this.scrollToId(this.jumpTo.id))
			} else {
				if (!await this.load({}, 'replace')) {
					return // a newer request superseded this one
				}
				this.newerDone = true
				this.$nextTick(() => {
					this.$refs.scroller.scrollTop = this.$refs.scroller.scrollHeight
				})
			}
		},

		async jumpDate(value) {
			if (!value) {
				return
			}
			const at = new Date(value + 'T00:00:00').getTime()
			this.messages = []
			this.olderDone = this.newerDone = false
			if (!await this.load({ at }, 'replace')) {
				return // a newer request superseded this one
			}
			this.newerDone = this.messages.length < PAGE
			this.$nextTick(() => {
				this.$refs.scroller.scrollTop = 0
			})
		},

		/**
		 * @param {object} params query params for the messages endpoint
		 * @param {string} mode 'replace', 'prepend' or 'append'
		 * @return {boolean} true once messages/olderDone/newerDone reflect
		 *   this call; false when a newer request superseded it (the
		 *   caller must not act on state this call would have set)
		 */
		async load(params, mode) {
			// A replace (reset, jump to date) supersedes a page that is still in
			// flight; without this the early return left the chat empty, because the
			// caller had already cleared messages.
			if (this.loading && mode !== 'replace') {
				return false
			}
			const mine = ++this.seq
			this.loading = true
			this.loadingMode = mode
			try {
				const r = await api.messages(this.chat.id, params)
				if (mine !== this.seq) {
					return false // a newer request took over while this one was in flight
				}
				Object.assign(this.senders, r.senders ?? {})
				const rows = r.messages ?? []
				if (mode === 'replace') {
					this.messages = rows
				} else if (mode === 'prepend') {
					const el = this.$refs.scroller
					const before = el.scrollHeight
					this.messages = rows.concat(this.messages)
					this.olderDone = rows.length < PAGE
					this.$nextTick(() => {
						el.scrollTop += el.scrollHeight - before
					})
				} else {
					this.messages = this.messages.concat(rows)
					this.newerDone = rows.length < PAGE
				}
				return true
			} catch {
				if (mine === this.seq) {
					showError(t('chat_archive', 'Could not load messages'))
				}
				return false
			} finally {
				if (mine === this.seq) {
					this.loading = false
				}
			}
		},

		/**
		 * One realtime step: the server adds the new messages of this chat
		 * from WAHA, and the page loads them when it shows the newest end of
		 * the chat. A hidden tab and a view of older messages ask nothing. A
		 * failed request does not end the cycle.
		 */
		async liveTick() {
			let again = true
			try {
				if (!document.hidden && this.newerDone && !this.loading) {
					const r = await api.liveChat(this.chat.id)
					again = r.live !== false
					if (again && this.liveTimer !== null && r.lastAt !== this.liveAt) {
						// the first step loads too: the timed sync can have added rows since the page opened
						this.liveAt = r.lastAt
						await this.loadNew()
					}
				}
			} catch {
				// the next step asks again
			}
			if (again && this.liveTimer !== null) {
				this.liveTimer = setTimeout(this.liveTick, LIVE_MS)
			}
		},

		/** Append the messages after the last one shown. A reader at the bottom stays at the bottom. */
		async loadNew() {
			const el = this.$refs.scroller
			const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80
			const last = this.messages[this.messages.length - 1]
			const done = last
				? await this.load({ afterAt: last.sentAt, afterId: last.id }, 'append')
				: await this.load({}, 'replace')
			if (done && atBottom) {
				this.$nextTick(() => {
					el.scrollTop = el.scrollHeight
				})
			}
		},

		onScroll() {
			const el = this.$refs.scroller
			if (this.messages.length === 0) {
				return
			}
			if (el.scrollTop < 200 && !this.olderDone) {
				const first = this.messages[0]
				this.load({ beforeAt: first.sentAt, beforeId: first.id }, 'prepend')
			} else if (el.scrollHeight - el.scrollTop - el.clientHeight < 200 && !this.newerDone) {
				const last = this.messages[this.messages.length - 1]
				this.load({ afterAt: last.sentAt, afterId: last.id }, 'append')
			}
		},

		scrollToId(id) {
			const el = this.$refs.scroller.querySelector(`[data-id="${id}"]`)
			if (!el) {
				return
			}
			el.scrollIntoView({ block: 'center' })
			el.focus()
			this.highlightId = id
			clearTimeout(this.highlightTimer)
			this.highlightTimer = setTimeout(() => {
				this.highlightId = null
			}, HIGHLIGHT_MS)
		},
	},
}
</script>

<style scoped>
.ca-chat {
	display: flex;
	flex-direction: column;
	height: 100%;
}
.ca-chat__head {
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
.ca-chat__title {
	display: flex;
	align-items: center;
	gap: 8px;
}
.ca-chat__head h2 {
	margin: 0;
	font-size: 1.1em;
}
.ca-chat__jump {
	display: flex;
	gap: 8px;
	align-items: center;
	color: var(--color-text-maxcontrast);
}
.ca-chat__status {
	margin: 0;
	padding: 4px 16px;
	border-bottom: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
.ca-chat__scroll {
	flex: 1;
	overflow-y: auto;
	padding: 8px 16px;
	/* Inline SVG tile: no request, no new asset. Outline glyphs only, one in
	   each cell of a 5 x 5 grid at a random place, size and turn. The stroke
	   is faint so the pattern stays behind the messages. */
	background-image: url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='420' height='420' viewBox='0 0 420 420' fill='none' stroke='%23888' stroke-opacity='.22' stroke-linecap='round' stroke-linejoin='round'><path d='M1.5 12.5l4 4 8.5-9.5M8 12.5l4 4 8.5-9.5' transform='translate(60 43) rotate(12) scale(1.2) translate(-12 -12)' stroke-width='1.25'/><path d='M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18zM8.5 14a4 4 0 0 0 7 0M9 9.5v.5M15 9.5v.5' transform='translate(135 20) rotate(12) scale(1.15) translate(-12 -12)' stroke-width='1.3'/><path d='M6 3h4l2 5-2.5 1.5a11 11 0 0 0 5 5L16 12l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2z' transform='translate(215 32) rotate(-23) scale(1.1) translate(-12 -12)' stroke-width='1.36'/><path d='M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 10c0 5.5-7 10-7 10z' transform='translate(308 41) rotate(11) scale(1.6) translate(-12 -12)' stroke-width='0.94'/><path d='M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z' transform='translate(389 48) rotate(20) scale(2.0) translate(-12 -12)' stroke-width='0.75'/><path d='M1.5 12.5l4 4 8.5-9.5M8 12.5l4 4 8.5-9.5' transform='translate(38 138) rotate(-3) scale(1.5) translate(-12 -12)' stroke-width='1.0'/><path d='M12 3a3 3 0 0 1 3 3v5a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3zM6 11a6 6 0 0 0 12 0M12 17v4' transform='translate(148 145) rotate(-19) scale(1.1) translate(-12 -12)' stroke-width='1.36'/><path d='M6 3h4l2 5-2.5 1.5a11 11 0 0 0 5 5L16 12l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2z' transform='translate(194 114) rotate(22) scale(1.4) translate(-12 -12)' stroke-width='1.07'/><path d='M3 5h18v14H3zM3 16l5-5 4 4 3-3 6 6' transform='translate(291 132) rotate(-10) scale(1.2) translate(-12 -12)' stroke-width='1.25'/><path d='M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 10c0 5.5-7 10-7 10z' transform='translate(378 120) rotate(-7) scale(1.2) translate(-12 -12)' stroke-width='1.25'/><path d='M6 11h12v9H6zM8 11V8a4 4 0 0 1 8 0v3' transform='translate(46 214) rotate(19) scale(1.1) translate(-12 -12)' stroke-width='1.36'/><path d='M12 3a3 3 0 0 1 3 3v5a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3zM6 11a6 6 0 0 0 12 0M12 17v4' transform='translate(134 229) rotate(17) scale(1.4) translate(-12 -12)' stroke-width='1.07'/><path d='M4 4h16v12H9l-5 4z' transform='translate(230 217) rotate(-16) scale(1.5) translate(-12 -12)' stroke-width='1.0'/><path d='M3 5h18v14H3zM3 16l5-5 4 4 3-3 6 6' transform='translate(308 228) rotate(19) scale(1.6) translate(-12 -12)' stroke-width='0.94'/><path d='M21 11l-9 9a5 5 0 0 1-7-7l9-9a3.5 3.5 0 0 1 5 5l-9 9a2 2 0 0 1-3-3l8-8' transform='translate(381 221) rotate(-14) scale(1.15) translate(-12 -12)' stroke-width='1.3'/><path d='M4 7h3l2-2h6l2 2h3v12H4zM12 10a3 3 0 1 0 0 6a3 3 0 1 0 0-6z' transform='translate(58 298) rotate(-10) scale(1.2) translate(-12 -12)' stroke-width='1.25'/><path d='M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z' transform='translate(122 297) rotate(24) scale(2.7) translate(-12 -12)' stroke-width='0.56'/><path d='M4 4h16v12H9l-5 4z' transform='translate(198 302) rotate(-4) scale(2.0) translate(-12 -12)' stroke-width='0.75'/><path d='M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18zM8.5 14a4 4 0 0 0 7 0M9 9.5v.5M15 9.5v.5' transform='translate(280 286) rotate(13) scale(1.5) translate(-12 -12)' stroke-width='1.0'/><path d='M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18zM12 7v5l3 2' transform='translate(394 274) rotate(5) scale(1.4) translate(-12 -12)' stroke-width='1.07'/><path d='M21 11l-9 9a5 5 0 0 1-7-7l9-9a3.5 3.5 0 0 1 5 5l-9 9a2 2 0 0 1-3-3l8-8' transform='translate(22 388) rotate(-8) scale(1.4) translate(-12 -12)' stroke-width='1.07'/><path d='M4 4h16v12H9l-5 4z' transform='translate(145 401) rotate(0) scale(1.2) translate(-12 -12)' stroke-width='1.25'/><path d='M4 7h3l2-2h6l2 2h3v12H4zM12 10a3 3 0 1 0 0 6a3 3 0 1 0 0-6z' transform='translate(230 370) rotate(-20) scale(1.5) translate(-12 -12)' stroke-width='1.0'/><path d='M6 11h12v9H6zM8 11V8a4 4 0 0 1 8 0v3' transform='translate(299 356) rotate(-15) scale(1.3) translate(-12 -12)' stroke-width='1.15'/><path d='M1.5 12.5l4 4 8.5-9.5M8 12.5l4 4 8.5-9.5' transform='translate(374 383) rotate(-17) scale(1.3) translate(-12 -12)' stroke-width='1.15'/></svg>");
	background-size: 420px 420px;
}
.ca-chat__note,
.ca-chat__spinner {
	display: flex;
	justify-content: center;
	padding: 16px;
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
</style>
