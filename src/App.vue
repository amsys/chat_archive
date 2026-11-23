<template>
	<NcContent appName="chat_archive">
		<NcAppNavigation>
			<template v-if="source" #search>
				<NcAppNavigationSearch
					v-model="query"
					:label="t('chat_archive', 'Search chats')"
					@mousedown.capture="keepSearchFocus"
					@click.capture="onSearchClick"
					@keyup.enter="openFilter">
					<template #actions>
						<NcButton
							variant="tertiary"
							:aria-label="t('chat_archive', 'Advanced search')"
							:title="t('chat_archive', 'Advanced search')"
							:pressed="filterOpen && view === 'search'"
							@click="toggleFilter">
							<template #icon>
								<Tune :size="20" />
							</template>
						</NcButton>
						<NcActions :aria-label="filtersActive ? t('chat_archive', 'List options, filters on') : t('chat_archive', 'List options')">
							<template #icon>
								<FilterOutline v-if="filtersActive" :size="20" />
								<DotsHorizontal v-else :size="20" />
							</template>
							<template v-if="sources.length > 1">
								<NcActionCaption :name="t('chat_archive', 'Source')" />
								<NcActionRadio
									v-for="s in sources"
									:key="s.id"
									v-model="sourceId"
									name="ca-source"
									:value="s.id">
									{{ s.label }}
								</NcActionRadio>
								<NcActionSeparator />
							</template>
							<NcActionCaption :name="t('chat_archive', 'Sort')" />
							<NcActionRadio v-model="sort" name="ca-sort" value="recent">
								{{ t('chat_archive', 'Most recent first') }}
							</NcActionRadio>
							<NcActionRadio v-model="sort" name="ca-sort" value="name">
								{{ t('chat_archive', 'By name') }}
							</NcActionRadio>
							<NcActionSeparator />
							<NcActionCaption :name="t('chat_archive', 'Show')" />
							<NcActionCheckbox
								:modelValue="prefs.showDeleted"
								@update:modelValue="setPref('showDeleted', $event)">
								{{ t('chat_archive', 'Deleted') }}
							</NcActionCheckbox>
							<NcActionCheckbox
								:modelValue="prefs.showArchived"
								@update:modelValue="setPref('showArchived', $event)">
								{{ t('chat_archive', 'Archived on the phone') }}
							</NcActionCheckbox>
						</NcActions>
					</template>
				</NcAppNavigationSearch>
			</template>
			<template #list>
				<li v-for="s in importing" :key="'imp' + s.id" class="ca-nav-note ca-nav-importing">
					<NcLoadingIcon :size="16" />
					<span v-if="s.importState === 'queued'">{{ t('chat_archive', 'Import queued: {label}', { label: s.label }, { escape: false, sanitize: false }) }}</span>
					<span v-else>{{ t('chat_archive', 'Importing {label} since {when}', { label: s.label, when: fmtTime(s.importSince * 1000) }, { escape: false, sanitize: false }) }}</span>
				</li>
				<NcListItem
					v-if="query.trim()"
					:name="t('chat_archive', 'Search messages for “{q}”', { q: query.trim() }, { escape: false, sanitize: false })"
					:active="view === 'search'"
					:compact="true"
					@click="openFilter">
					<template #icon>
						<Magnify :size="20" />
					</template>
				</NcListItem>
				<NcListItem
					v-if="source && !query.trim()"
					:name="t('chat_archive', 'Calls')"
					:active="view === 'calls'"
					@click="showCalls">
					<template #icon>
						<Phone :size="20" />
					</template>
				</NcListItem>
				<NcAppNavigationCaption v-if="source" :name="chatsCaption" />
				<NcListItem
					v-for="c in visibleChats.slice(0, limit)"
					:key="c.id"
					:name="c.name"
					:oneLine="true"
					:class="{ 'ca-chat-muted': chatMuted(c) }"
					:details="fmtRelDate(c.lastAt)"
					:linkAriaLabel="chatRowLabel(c)"
					:active="view === 'chat' && chat && chat.id === c.id"
					@click="openChat(c)">
					<template #icon>
						<NcAvatar
							:displayName="c.name"
							:disableMenu="true"
							:disableTooltip="true"
							:size="32" />
					</template>
					<template #name>
						<span class="ca-chat-name-row">
							<span class="ca-chat-name-text" dir="auto" @mouseenter="nameHover($event, c.name)">{{ c.name }}</span>
							<span v-if="chatMuted(c)" class="ca-chat-icons">
								<TrashCanOutline
									v-if="c.goneAt !== null"
									:size="16"
									:title="t('chat_archive', 'Deleted from the phone')"
									aria-hidden="true" />
								<ArchiveOutline
									v-if="c.phoneArchived"
									:size="16"
									:title="t('chat_archive', 'Archived on the phone')"
									aria-hidden="true" />
							</span>
						</span>
					</template>
				</NcListItem>
				<li v-if="source && query.trim() && visibleChats.length === 0" class="ca-nav-note">
					{{ t('chat_archive', 'No chat matches') }}
				</li>
				<li v-if="visibleChats.length > limit" :ref="observeSentinel" class="ca-nav-more">
					<NcLoadingIcon :size="20" />
				</li>
			</template>
			<template #footer>
				<NcAppNavigationSettings :name="t('chat_archive', 'Archive settings')">
					<div class="ca-nav-settings">
						<NcButton :href="settingsUrl" wide>
							<template #icon>
								<Cog :size="20" />
							</template>
							{{ t('chat_archive', 'Manage sources') }}
						</NcButton>
						<template v-if="source">
							<NcButton wide :disabled="refreshing" @click="refreshNames">
								<template #icon>
									<NcLoadingIcon v-if="refreshing" :size="20" />
									<AccountSync v-else :size="20" />
								</template>
								{{ t('chat_archive', 'Refresh contact names') }}
							</NcButton>
							<p class="ca-nav-settings__hint">
								{{ t('chat_archive', 'Use after you change names in Contacts. Chat names are cached.') }}
							</p>
						</template>
					</div>
				</NcAppNavigationSettings>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<NcEmptyContent
				v-if="!source"
				class="ca-empty"
				:name="t('chat_archive', 'No source configured')"
				:description="t('chat_archive', 'Add a backup folder in your personal settings.')">
				<template #icon>
					<MessageText />
				</template>
				<template #action>
					<NcButton :href="settingsUrl">
						{{ t('chat_archive', 'Open settings') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<CallList
				v-else-if="view === 'calls'"
				:calls="calls"
				:chats="chats"
				@open="openCall" />
			<MessageList
				v-else-if="view === 'chat' && chat"
				:key="chat.id"
				:chat="chat"
				:jumpTo="jumpTo"
				:reloadSeq="prefsSeq"
				:live="realtime && !!source?.syncSession"
				:showBackToResults="fromSearch"
				@back="backToResults" />
			<NcEmptyContent
				v-else-if="view !== 'search'"
				class="ca-empty"
				:name="t('chat_archive', 'Pick a chat')"
				:description="t('chat_archive', 'Select a chat on the left to read it, or type to search.')">
				<template #icon>
					<MessageText />
				</template>
			</NcEmptyContent>
			<!-- Outside the chain above: it stays mounted, hidden, while a hit's chat is open. -->
			<SearchResults
				v-if="filterOpen"
				v-show="view === 'search'"
				:key="filterKey"
				:sources="sources"
				:source="source"
				:chats="chats"
				:query="searched"
				:chat="beforeFilter?.chat"
				@open="openHit" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcActionCaption,
	NcActionCheckbox,
	NcActionRadio,
	NcActions,
	NcActionSeparator,
	NcAppContent,
	NcAppNavigation,
	NcAppNavigationCaption,
	NcAppNavigationSearch,
	NcAppNavigationSettings,
	NcAvatar,
	NcButton,
	NcContent,
	NcEmptyContent,
	NcListItem,
	NcLoadingIcon,
} from '@nextcloud/vue'
import AccountSync from 'vue-material-design-icons/AccountSync.vue'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import Phone from 'vue-material-design-icons/Phone.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import CallList from './components/CallList.vue'
import MessageList from './components/MessageList.vue'
import SearchResults from './components/SearchResults.vue'
import { api, viewPrefs } from './api.js'
import { fmtDate, fmtRelDate, fmtTime, pollImports } from './util.js'

/* ponytail: the list caps rendered rows instead of virtual scrolling; the filter narrows it. */
const PAGE = 150

export default {
	name: 'App',
	components: {
		NcActionCaption,
		NcActionCheckbox,
		NcActionRadio,
		NcActions,
		NcActionSeparator,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationCaption,
		NcAppNavigationSearch,
		NcAppNavigationSettings,
		NcAvatar,
		NcButton,
		NcContent,
		NcEmptyContent,
		NcListItem,
		NcLoadingIcon,
		AccountSync,
		ArchiveOutline,
		Cog,
		DotsHorizontal,
		FilterOutline,
		Magnify,
		MessageText,
		Phone,
		TrashCanOutline,
		Tune,
		CallList,
		MessageList,
		SearchResults,
	},

	data() {
		const sources = loadState('chat_archive', 'sources', [])
		return {
			sources,
			// the admin switch for realtime updates of an open chat
			realtime: loadState('chat_archive', 'realtime', false),
			source: sources[0] ?? null,
			chats: [],
			chat: null,
			view: 'none',
			query: '',
			searched: '',
			sort: 'recent',
			limit: PAGE,
			calls: [],
			seq: 0, // only the newest chats request may write to state
			callsSeq: 0, // only the newest calls request may write to state
			jumpTo: null,
			fromSearch: false,
			refreshing: false,
			settingsUrl: generateUrl('/settings/user/chat_archive'),
			filterOpen: false,
			filterKey: 0,
			beforeFilter: null,
			skipSourceReset: false,
			prefs: viewPrefs(),
			prefsSeq: 0,
		}
	},

	computed: {
		importing() {
			return this.sources.filter((s) => s.importState)
		},

		/** The source picker, as a plain id for NcActionRadio's v-model. */
		sourceId: {
			get() {
				return this.source?.id ?? null
			},

			set(id) {
				this.source = this.sources.find((s) => s.id === id) ?? this.source
			},
		},

		/** Chats matching the text query, before the two Show prefs remove any. */
		queriedChats() {
			const q = this.query.trim().toLowerCase()
			return q ? this.chats.filter((c) => (c.name ?? '').toLowerCase().includes(q)) : this.chats.slice()
		},

		/** Chats filtered by the query, the two view toggles, and ordered by the sort choice. */
		visibleChats() {
			let list = this.queriedChats
			if (!this.prefs.showArchived) {
				list = list.filter((c) => !c.phoneArchived)
			}
			if (!this.prefs.showDeleted) {
				list = list.filter((c) => c.goneAt === null)
			}
			if (this.sort === 'name') {
				list.sort((a, b) => (a.name ?? '').localeCompare(b.name ?? '', undefined, { sensitivity: 'base' }))
			} else {
				// Chats without a last message go last; the database puts NULL first on DESC.
				list.sort((a, b) => (b.lastAt ?? 0) - (a.lastAt ?? 0))
			}
			return list
		},

		/** True when a Show pref hides part of the list, for the menu icon. */
		filtersActive() {
			return !this.prefs.showDeleted || !this.prefs.showArchived
		},

		chatsCaption() {
			const n = this.visibleChats.length
			const h = this.queriedChats.length - n
			const label = this.source?.label
			if (this.sources.length > 1) {
				return h > 0
					? t('chat_archive', '{label} · Chats: {n} · hidden: {h}', { label, n, h }, { escape: false, sanitize: false })
					: t('chat_archive', '{label} · Chats: {n}', { label, n }, { escape: false, sanitize: false })
			}
			return h > 0
				? t('chat_archive', 'Chats: {n} · hidden: {h}', { n, h }, { escape: false, sanitize: false })
				: t('chat_archive', 'Chats: {n}', { n }, { escape: false, sanitize: false })
		},
	},

	watch: {
		// the watcher passes (new, old); 'loadChats' would take the old source as
		// keepView and never reset the open chat when the source changes.
		// openHit sets skipSourceReset when it switches source itself and
		// reloads the chats (keeping the view) on its own; this run is skipped.
		source: {
			immediate: true,
			handler() {
				// A real source switch invalidates any calls fetch still in
				// flight for the old source; without this a stale response
				// could flip the view back to 'calls' after the switch.
				this.callsSeq++
				if (this.skipSourceReset) {
					this.skipSourceReset = false
					return
				}
				this.loadChats()
			},
		},

		query() {
			this.limit = PAGE
		},
	},

	mounted() {
		this.poll = pollImports(async () => {
			const was = this.importing.length > 0
			this.sources = (await api.settings()).sources
			// arch: D30.1 V4: the replaced source objects must not leave `source` stale
			const next = this.sources.find((s) => s.id === this.source?.id) ?? this.sources[0] ?? null
			if (next !== this.source) {
				// the same source as a new object must not reset the open view
				this.skipSourceReset = next?.id === this.source?.id
				this.source = next
			}
			if (was && this.importing.length === 0) {
				showSuccess(t('chat_archive', 'Import finished'))
				this.loadChats(true)
				this.prefsSeq++ // arch: D30.1 V5: the open chat loads what the import added
			}
			return this.importing.length > 0
		})
		// Loads the next PAGE of chats once the sentinel row scrolls into view;
		// the browser clips it through the nav's own scroll container even
		// though root stays the default (the top-level viewport).
		this.observer = new IntersectionObserver((entries) => {
			if (entries[0]?.isIntersecting) {
				this.limit += PAGE
			}
		})
		document.addEventListener('keydown', this.onKeydown)
	},

	beforeUnmount() {
		this.poll.stop()
		this.observer.disconnect()
		document.removeEventListener('keydown', this.onKeydown)
	},

	methods: {
		t,
		fmtRelDate,
		fmtTime,

		/**
		 * Keep the focus where it is when the user presses the clear button.
		 * NcAppNavigationSearch hides its actions and widens the input when
		 * the focus goes into the input. The button then moves before the
		 * mouse button comes up, and the click does not occur.
		 *
		 * @param {MouseEvent} e the mousedown event
		 */
		keepSearchFocus(e) {
			if (e.target.closest('.input-field__trailing-button')) {
				e.preventDefault()
			}
		},

		/**
		 * The clear button empties the search. Close the filter too, as
		 * `Escape` does.
		 *
		 * @param {MouseEvent} e the click event
		 */
		onSearchClick(e) {
			if (e.target.closest('.input-field__trailing-button')) {
				this.closeSearchFilter()
			}
		},

		/**
		 * Close the filter while its results show. When a hit is open, keep
		 * the filter, so that the user stays in the chat.
		 */
		closeSearchFilter() {
			if (this.view === 'search' && this.filterOpen) {
				this.closeFilter()
			}
		},

		/**
		 * Show the full chat name on hover, but only when the row cuts it.
		 *
		 * @param {MouseEvent} e the mouseenter event
		 * @param {string} name the full chat name
		 */
		nameHover(e, name) {
			const el = e.currentTarget
			el.title = el.scrollWidth > el.clientWidth ? name : ''
		},

		/**
		 * Open the filter and save the current view state.
		 */
		openFilter() {
			this.searched = this.query.trim()
			if (!this.filterOpen) {
				this.beforeFilter = {
					view: this.view,
					chat: this.chat,
					jumpTo: this.jumpTo,
					fromSearch: this.fromSearch,
				}
				this.filterOpen = true
				this.filterKey++
			}
			// Enter while a hit is open must come back to the results, not
			// refresh them out of sight.
			this.view = 'search'
		},

		/**
		 * Close the filter and restore the previous view state.
		 */
		closeFilter() {
			this.filterOpen = false
			if (this.beforeFilter) {
				this.view = this.beforeFilter.view
				this.chat = this.beforeFilter.chat
				this.jumpTo = this.beforeFilter.jumpTo
				this.fromSearch = this.beforeFilter.fromSearch
				this.beforeFilter = null
			}
		},

		/**
		 * Open the filter, close it, or return to its results from a hit.
		 */
		toggleFilter() {
			if (!this.filterOpen) {
				this.openFilter()
			} else if (this.view === 'search') {
				this.closeFilter()
			} else {
				this.backToResults()
			}
		},

		/**
		 * `/` focuses the chat search; `Escape` clears it. Ignored while a
		 * form field already has focus.
		 *
		 * @param {KeyboardEvent} e the keydown event
		 */
		onKeydown(e) {
			const el = e.target
			if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable) {
				return
			}
			if (e.key === '/') {
				e.preventDefault()
				this.$el.querySelector('.app-navigation-search__input input')?.focus()
			} else if (e.key === 'Escape') {
				this.query = ''
				this.closeSearchFilter()
			}
		},

		/**
		 * Function ref of the sentinel row. Vue calls it with the <li> on
		 * each render of the list and with null once the row goes. The list
		 * is slot content, so App itself does not re-render for it and an
		 * updated() hook here never sees the row.
		 *
		 * @param {?HTMLElement} el the sentinel row, or null
		 */
		observeSentinel(el) {
			this.observer.disconnect()
			if (el) {
				this.observer.observe(el)
			}
		},

		/**
		 * Screen-reader label for a chat row; the message count is dropped
		 * from the visible row, so it needs to live here instead.
		 *
		 * @param {object} c a chat row with name, msgCount and lastAt
		 * @return {string}
		 */
		chatRowLabel(c) {
			const base = c.lastAt
				? t('chat_archive', '{name}, {n} messages, last message {date}', { name: c.name, n: c.msgCount, date: fmtDate(c.lastAt) }, { escape: false, sanitize: false })
				: t('chat_archive', '{name}, {n} messages', { name: c.name, n: c.msgCount }, { escape: false, sanitize: false })
			const marker = this.chatMarkerText(c)
			return marker ? `${base}, ${marker}` : base
		},

		/**
		 * @param {object} c a chat row with phoneArchived and goneAt
		 * @return {boolean} true when the chat is gone from the phone, or
		 *   archived there, and so gets the muted row style
		 */
		chatMuted(c) {
			return c.goneAt !== null || !!c.phoneArchived
		},

		/**
		 * Words for a muted chat row and its screen-reader label.
		 *
		 * @param {object} c a chat row with phoneArchived and goneAt
		 * @return {string} empty when neither fact holds
		 */
		chatMarkerText(c) {
			const parts = []
			if (c.goneAt !== null) {
				parts.push(t('chat_archive', 'Deleted from the phone'))
			}
			if (c.phoneArchived) {
				parts.push(t('chat_archive', 'Archived on the phone'))
			}
			return parts.join(' · ')
		},

		/**
		 * Save one view toggle, then reload the open chat and any open
		 * search so they reflect it right away.
		 *
		 * @param {string} key 'showDeleted' or 'showArchived'
		 * @param {boolean} value the new switch state
		 */
		async setPref(key, value) {
			try {
				this.prefs = await api.setViewPrefs({ [key]: value })
			} catch {
				showError(t('chat_archive', 'Could not save the setting'))
				return
			}
			this.prefsSeq++
			if (this.filterOpen) {
				this.filterKey++
			}
		},

		/**
		 * Reload the chat list; with keepView the open chat or search stays.
		 *
		 * @param {boolean} keepView keep the open chat or search
		 * @return {Promise<boolean>} false when the request failed
		 */
		async loadChats(keepView = false) {
			if (!keepView) {
				this.chats = []
				this.chat = null
				this.limit = PAGE
				this.view = 'none'
				this.filterOpen = false
				this.beforeFilter = null
			}
			const mine = ++this.seq
			this.chatsReady = this.source
				? api.chats(this.source.id).then((c) => {
						if (mine === this.seq) {
							this.chats = c
						}
						return true
					}, () => {
						if (mine === this.seq) {
							showError(t('chat_archive', 'Could not load the chats'))
						}
						return false
					})
				: Promise.resolve(true)
			return await this.chatsReady
		},

		openChat(c) {
			this.filterOpen = false
			this.beforeFilter = null
			this.chat = c
			this.jumpTo = null
			this.fromSearch = false
			this.view = 'chat'
		},

		async showCalls() {
			const mine = ++this.callsSeq
			try {
				const calls = await api.calls(this.source.id)
				if (mine !== this.callsSeq) {
					return // a newer calls request took over while this one was in flight
				}
				this.calls = calls
				this.view = 'calls'
			} catch {
				if (mine === this.callsSeq) {
					showError(t('chat_archive', 'Could not load the call log'))
				}
			}
		},

		/**
		 * Open the chat of a call, around the time of the call. No message id
		 * is known, so MessageList centres on the timestamp and skips its
		 * highlight.
		 *
		 * @param {object} call the chat to open and the start time of the call
		 * @param {object} call.chat the chat row
		 * @param {number} call.at the call start, in unix ms
		 */
		openCall({ chat, at }) {
			this.chat = chat
			this.jumpTo = { at, id: null }
			this.fromSearch = false
			this.view = 'chat'
		},

		/**
		 * A hit from another source switches the navigation to it first.
		 * The source watcher normally resets the whole view (it must, for a
		 * source picked from the nav); here it must not, or it would unmount
		 * the search results out from under this hit. skipSourceReset tells
		 * it to leave the view alone this one time, since loadChats(true)
		 * below already reloads the chat list without resetting the view.
		 *
		 * @param {object} hit a search hit with sourceId, chatId, sentAt and id
		 */
		async openHit(hit) {
			const target = this.sources.find((s) => s.id === hit.sourceId)
			if (target && target.id !== this.source.id) {
				this.skipSourceReset = true
				this.source = target
				await this.loadChats(true)
			}
			const c = this.chats.find((x) => x.id === hit.chatId)
			if (!c) {
				return
			}
			this.chat = c
			this.jumpTo = { at: hit.sentAt, id: hit.id }
			this.fromSearch = true
			this.view = 'chat'
		},

		backToResults() {
			this.view = 'search'
			this.chat = null
			this.jumpTo = null
			this.fromSearch = false
		},

		async refreshNames() {
			this.refreshing = true
			try {
				await api.refreshNames(this.source.id)
				if (await this.loadChats(true)) {
					showSuccess(t('chat_archive', 'Contact names refreshed'))
				}
			} catch {
				showError(t('chat_archive', 'Could not refresh contact names'))
			} finally {
				this.refreshing = false
			}
		},
	},
}
</script>

<style scoped>
.ca-nav-more {
	display: flex;
	justify-content: center;
	padding: 8px var(--app-navigation-padding, 4px);
}
.ca-nav-note {
	padding: 12px 16px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
.ca-nav-importing {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	color: var(--color-primary-element);
}
.ca-nav-settings {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.ca-chat-muted :deep(.list-item-content__name) {
	color: var(--color-text-maxcontrast);
}
.ca-chat-name-row {
	display: flex;
	align-items: center;
	gap: 2px;
	min-width: 0;
	width: 100%;
}
.ca-chat-name-text {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}
.ca-chat-icons {
	display: flex;
	align-items: center;
	gap: 2px;
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}
.ca-nav-settings__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
/* NcEmptyContent centres itself only inside a stretched flex box; give it the full height. */
.ca-empty {
	height: 100%;
}
</style>
