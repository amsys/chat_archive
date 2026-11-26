<!--
  Search over all chats of a source: the filters (chat, type, sender,
  time) and the list of hits. A click on a hit opens its chat at that
  message.
-->
<template>
	<div class="ca-search">
		<div class="ca-search__filters">
			<NcSelect
				v-if="sources.length > 1"
				v-model="f.source"
				:options="sourceOptions"
				label="label"
				:clearable="false"
				:inputLabel="t('chat_archive', 'Source')" />
			<NcSelect
				v-if="chatOptions.length"
				v-model="f.chat"
				:options="chatOptions"
				label="name"
				:placeholder="t('chat_archive', 'Any chat')"
				:inputLabel="t('chat_archive', 'Chat')" />
			<NcSelect
				v-model="f.kind"
				:options="kinds"
				label="name"
				:clearable="false"
				:inputLabel="t('chat_archive', 'Type')" />
			<NcSelect
				v-model="f.who"
				:options="whos"
				label="name"
				:clearable="false"
				:inputLabel="t('chat_archive', 'From')" />
			<NcSelect
				v-model="f.when"
				:options="whens"
				label="name"
				:clearable="false"
				:inputLabel="t('chat_archive', 'When')" />
			<template v-if="f.when.id === 'custom'">
				<label class="ca-search__date">
					{{ t('chat_archive', 'After') }}
					<input v-model.lazy="f.from" type="date">
				</label>
				<label class="ca-search__date">
					{{ t('chat_archive', 'Before') }}
					<input v-model.lazy="f.to" type="date">
				</label>
			</template>
			<NcButton v-if="active" variant="tertiary" @click="clear">
				{{ t('chat_archive', 'Clear filters') }}
			</NcButton>
		</div>

		<h2 v-if="query" aria-live="polite">
			{{ t('chat_archive', 'Results for "{q}"', { q: query }, { escape: false, sanitize: false }) }}
			<span v-if="!loading && hits.length" class="ca-search__count">{{ t('chat_archive', '({n} shown)', { n: hits.length }, { escape: false, sanitize: false }) }}</span>
		</h2>
		<h2 v-else-if="active" aria-live="polite">
			{{ t('chat_archive', 'Filtered messages') }}
			<span v-if="!loading && hits.length" class="ca-search__count">{{ t('chat_archive', '({n} shown)', { n: hits.length }, { escape: false, sanitize: false }) }}</span>
		</h2>
		<NcEmptyContent
			v-if="!query && !active"
			:name="t('chat_archive', 'Advanced search')"
			:description="t('chat_archive', 'Type in the search box, or set a filter to list photos, files, calls or a time span.')">
			<template #icon>
				<Magnify />
			</template>
		</NcEmptyContent>
		<NcLoadingIcon v-else-if="loading && hits.length === 0" :size="20" class="ca-search__loading" />
		<NcEmptyContent
			v-else-if="!loading && hits.length === 0"
			:name="t('chat_archive', 'Nothing found')"
			:description="t('chat_archive', 'Try different words, or clear a filter above.')">
			<template #icon>
				<Magnify />
			</template>
		</NcEmptyContent>
		<ul v-else>
			<NcListItem
				v-for="h in hits"
				:key="h.id"
				:name="chatName(h)"
				:details="fmtDateTime(h.sentAt)"
				:oneLine="false"
				@click="$emit('open', h)">
				<template #name>
					<span dir="auto">{{ chatName(h) }}</span>
				</template>
				<template #subname>
					<span class="ca-search__subname" dir="auto">
						<span v-if="h.sender">{{ senders[h.sender] }}: </span>
						<span v-if="h.deleted" class="ca-search__chip" :title="deletedLabel(h)">{{ t('chat_archive', 'Deleted') }}</span>
						<span v-if="h.editedAt" class="ca-search__chip">{{ t('chat_archive', 'Edited') }}</span>
						<!-- The flag stays: no request goes out for a suspicious file
						     from this row — no thumbnail, and no
						     `open` handler that could reach it either. -->
						<span v-if="h.media && h.media.suspicious" class="ca-search__chip">{{ t('chat_archive', 'Suspicious file') }}</span>
						<!-- A still preview, not MediaView: that is a link plus a <video
						     controls>, and the NcListItem already wraps this slot in a
						     link. Nested links cannot be reached by keyboard. The row
						     opens the hit; the chat view offers the media itself. -->
						<span v-if="h.media" class="ca-search__media">
							<img
								v-if="h.media.exists && h.media.hasPreview && !h.media.suspicious"
								class="ca-search__thumb"
								:src="previewUrl(h.media.fileId, 256)"
								alt=""
								loading="lazy">
							<template v-if="!mediaText(h)">
								{{ mediaName(h.media) }}
							</template>
							<template v-for="(p, i) in previewParts(h)" v-else :key="i">
								<mark v-if="p.hit">{{ p.text }}</mark>
								<template v-else>
									{{ p.text }}
								</template>
							</template>
						</span>
						<template v-for="(p, i) in previewParts(h)" v-else :key="i">
							<mark v-if="p.hit">{{ p.text }}</mark>
							<template v-else>
								{{ p.text }}
							</template>
						</template>
					</span>
				</template>
			</NcListItem>
			<li v-if="more" class="ca-search__more">
				<NcButton
					variant="tertiary"
					wide
					:disabled="loading"
					@click="run(true)">
					{{ t('chat_archive', 'Show more') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcListItem, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import { api, previewUrl } from '../api.js'
import { callLabel, fmtDateTime, kindLabel } from '../util.js'

const DAY = 86400000
const ALL = { id: null, label: t('chat_archive', 'All sources') }
const KINDS = [
	{ id: '', name: t('chat_archive', 'Any type') },
	{ id: 'text', name: t('chat_archive', 'Text') },
	{ id: 'media', name: t('chat_archive', 'Any file') },
	{ id: 'image', name: t('chat_archive', 'Photos') },
	{ id: 'video', name: t('chat_archive', 'Videos') },
	{ id: 'audio', name: t('chat_archive', 'Voice and audio') },
	{ id: 'document', name: t('chat_archive', 'Documents') },
	{ id: 'link', name: t('chat_archive', 'Links') },
	{ id: 'call', name: t('chat_archive', 'Calls') },
]
const WHOS = [
	{ id: '', name: t('chat_archive', 'Anyone') },
	{ id: 'me', name: t('chat_archive', 'Me') },
	{ id: 'others', name: t('chat_archive', 'Others') },
]
const WHENS = [
	{ id: '', name: t('chat_archive', 'Any time'), days: 0 },
	{ id: '1d', name: t('chat_archive', 'Last 24 hours'), days: 1 },
	{ id: '7d', name: t('chat_archive', 'Last 7 days'), days: 7 },
	{ id: '30d', name: t('chat_archive', 'Last 30 days'), days: 30 },
	{ id: '365d', name: t('chat_archive', 'Last year'), days: 365 },
	{ id: 'custom', name: t('chat_archive', 'Custom range'), days: 0 },
]

export default {
	name: 'SearchResults',
	components: { Magnify, NcButton, NcEmptyContent, NcListItem, NcLoadingIcon, NcSelect },
	props: {
		sources: { type: Array, required: true },
		source: { type: Object, default: null }, // the source open in the navigation
		chat: { type: Object, default: null }, // the chat open in the navigation when the filter opened
		chats: { type: Array, required: true }, // chats of that source
		query: { type: String, default: '' },
	},

	emits: ['open'],
	data() {
		return {
			f: { source: this.source ?? ALL, chat: this.chat && this.chats.find((c) => c.id === this.chat.id) ? this.chat : null, kind: KINDS[0], who: WHOS[0], when: WHENS[0], from: '', to: '' },
			kinds: KINDS,
			whos: WHOS,
			whens: WHENS,
			hits: [],
			senders: {},
			chatNames: {},
			more: false,
			loading: false,
			seq: 0, // only the newest search may write to hits
		}
	},

	computed: {
		sourceOptions() {
			return [ALL, ...this.sources]
		},

		/** The chat picker lists the navigation's chats; it is offered while the search covers that source. */
		chatOptions() {
			const sid = this.f.source?.id ?? null
			return sid === null || sid === this.source?.id ? this.chats : []
		},

		active() {
			return this.f.chat !== null || this.f.kind.id !== '' || this.f.who.id !== '' || this.f.when.id !== ''
		},

		params() {
			const p = { q: this.query, source: this.f.source?.id ?? null, chat: this.f.chat?.id ?? null, kind: this.f.kind.id, who: this.f.who.id }
			if (this.f.when.days) {
				p.from = Date.now() - this.f.when.days * DAY
			} else if (this.f.when.id === 'custom') {
				p.from = this.f.from ? new Date(this.f.from + 'T00:00:00').getTime() : null
				p.to = this.f.to ? new Date(this.f.to + 'T23:59:59.999').getTime() : null
			}
			return p
		},
	},

	watch: {
		params: { handler: 'run', immediate: true },
		chatOptions(list) {
			if (this.f.chat && !list.some((c) => c.id === this.f.chat.id)) {
				this.f.chat = null
			}
		},
	},

	methods: {
		t,
		fmtDateTime,
		previewUrl,
		clear() {
			Object.assign(this.f, { chat: null, kind: KINDS[0], who: WHOS[0], when: WHENS[0], from: '', to: '' })
		},

		async run(append = false) {
			const p = { ...this.params, offset: append === true ? this.hits.length : 0 }
			if (!p.q && !this.active) {
				this.hits = []
				this.more = false
				return
			}
			const mine = ++this.seq
			this.loading = true
			try {
				const r = await api.search(p)
				if (mine !== this.seq) {
					return // a newer search took over while this one was in flight
				}
				if (p.offset) {
					const seen = new Set(this.hits.map((h) => h.id))
					this.hits = this.hits.concat(r.messages.filter((h) => !seen.has(h.id)))
				} else {
					this.hits = r.messages
				}
				Object.assign(this.senders, r.senders)
				Object.assign(this.chatNames, r.chats)
				this.more = r.more
			} catch {
				if (mine === this.seq) {
					showError(t('chat_archive', 'Could not run the search'))
				}
			} finally {
				if (mine === this.seq) {
					this.loading = false
				}
			}
		},

		chatName(h) {
			const c = this.chatNames[h.chatId]
			const name = c?.name ?? '?'
			if (this.sources.length > 1 && this.f.source?.id === null) {
				const s = this.sources.find((x) => x.id === h.sourceId)
				return s ? `${name} · ${s.label}` : name
			}
			return name
		},

		previewText(h) {
			return (h.call && callLabel(h)) || (h.latestBody ?? h.body) || h.media?.caption || kindLabel(h.kind)
		},

		/**
		 * @param {object} h a search hit with media
		 * @return {string} the text of the hit or the caption of its file, or an empty string
		 */
		mediaText(h) {
			return (h.latestBody ?? h.body) || h.media.caption || ''
		},

		/**
		 * @param {object} m a media row
		 * @return {string} the name shown: the live file's own name first, so a spoofed backup name never wins
		 */
		mediaName(m) {
			return m.realName || m.fileName || m.path
		},

		/**
		 * @param {object} h a search hit with a `deleted` mark
		 * @return {string} the reason label shown next to a deleted hit
		 */
		deletedLabel(h) {
			return h.deleted === 'revoked'
				? t('chat_archive', 'Deleted for everyone')
				: t('chat_archive', 'Deleted from the phone')
		},

		/**
		 * Splits the preview into plain and matched parts, windowed around the
		 * first hit so a long message does not push the match off-screen.
		 *
		 * @param {object} h a search hit
		 * @return {Array<{ text: string, hit: boolean }>}
		 */
		previewParts(h) {
			const text = this.previewText(h)
			const q = this.query.trim()
			const i = q ? text.toLowerCase().indexOf(q.toLowerCase()) : -1
			if (i === -1) {
				return [{ text, hit: false }]
			}
			const start = Math.max(0, i - 40)
			const end = Math.min(text.length, i + q.length + 120)
			return [
				{ text: (start > 0 ? '…' : '') + text.slice(start, i), hit: false },
				{ text: text.slice(i, i + q.length), hit: true },
				{ text: text.slice(i + q.length, end) + (end < text.length ? '…' : ''), hit: false },
			]
		},
	},
}
</script>

<style scoped>
.ca-search {
	padding: 8px 16px;
	overflow-y: auto;
	height: 100%;
}
.ca-search__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 12px;
	align-items: flex-end;
	padding: 0 0 8px 56px; /* room for the navigation toggle */
	border-bottom: 1px solid var(--color-border);
}
.ca-search__filters > * {
	min-width: 160px;
	flex: 1 1 160px;
	max-width: 260px;
}
.ca-search__filters > button {
	flex: 0 0 auto;
	min-width: 0;
}
.ca-search__date {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}
.ca-search__date input {
	margin: 0;
	height: var(--default-clickable-area);
}
.ca-search ul {
	list-style: none;
}
/* A row with a photo is higher than two text lines: the library gives the
   row a fixed height, so the next rows covered it. */
.ca-search :deep(.list-item__anchor) {
	height: auto;
	min-height: var(--list-item-height);
}
.ca-search__more {
	padding: 8px 0;
}
.ca-search__count {
	margin-left: 4px;
	font-weight: normal;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
.ca-search__chip {
	display: inline-flex;
	align-items: center;
	margin-right: 4px;
	padding: 0 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	line-height: 18px;
}
.ca-search__loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}
.ca-search mark {
	background: var(--color-primary-element-light);
	color: inherit;
}
.ca-search__media {
	margin: 4px 0;
	max-width: 300px;
	display: block;
}
/* Compact still preview in search results */
.ca-search__thumb {
	max-height: 80px;
	vertical-align: middle;
	margin-right: 8px;
	border-radius: var(--border-radius, 3px);
}
</style>
