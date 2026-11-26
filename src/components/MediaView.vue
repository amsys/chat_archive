<!--
  The file of one message: a preview or a link when the file is in
  Nextcloud, a note when it is not, and a warning when WhatsApp flagged it.
-->
<template>
	<div class="ca-media">
		<template v-if="media.exists && media.suspicious">
			<NcNoteCard type="warning" class="ca-media__warning">
				<p>{{ t('chat_archive', 'WhatsApp flagged this file as suspicious') }}</p>
				<p class="ca-media__warning-name" dir="auto">
					{{ displayName }}
				</p>
				<NcButton variant="secondary" @click="confirmDownload">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('chat_archive', 'Download') }}
				</NcButton>
			</NcNoteCard>
		</template>
		<template v-else-if="media.exists">
			<a
				v-if="isImage(effectiveMime)"
				:href="filesLink"
				@click.prevent="openMedia(media)">
				<img
					class="ca-media__img"
					:src="previewUrl(media.fileId)"
					:alt="displayName || ''"
					loading="lazy">
			</a>
			<video
				v-else-if="isVideo(effectiveMime)"
				class="ca-media__video"
				controls
				preload="none"
				:poster="media.hasPreview ? previewUrl(media.fileId) : undefined"
				:src="davUrl(media.path)" />
			<audio
				v-else-if="isAudio(effectiveMime)"
				class="ca-media__audio"
				controls
				preload="none"
				:src="davUrl(media.path)" />
			<a
				v-else
				class="ca-media__doc"
				:class="{ 'ca-media__doc--thumb': media.hasPreview }"
				:href="filesLink"
				@click.prevent="openMedia(media)">
				<img
					v-if="media.hasPreview"
					class="ca-media__thumb"
					:src="previewUrl(media.fileId, 256)"
					alt=""
					loading="lazy">
				<FileDocument v-else :size="20" />
				<span dir="auto">{{ displayName }}</span>
			</a>
			<div class="ca-media__bar">
				<span v-if="media.mimeMismatch" class="ca-media__chip" :title="mismatchTitle">
					{{ t('chat_archive', 'WhatsApp recorded a different file type') }}
				</span>
				<NcActions :aria-label="media.displayPath" :title="media.displayPath">
					<NcActionLink :href="filesLink" target="_blank">
						<template #icon>
							<Folder :size="20" />
						</template>
						{{ t('chat_archive', 'Show in Files') }}
					</NcActionLink>
					<NcActionLink :href="davUrl(media.path)" download>
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('chat_archive', 'Download') }}
					</NcActionLink>
					<NcActionButton @click="openLocally(media.path)">
						<template #icon>
							<Laptop :size="20" />
						</template>
						{{ t('chat_archive', 'Open locally') }}
					</NcActionButton>
				</NcActions>
			</div>
		</template>
		<div v-else class="ca-media__missing" dir="auto">
			<FileHidden :size="20" />
			<span>{{ media.fileName || media.srcPath || kindLabel(kind) }} — {{ t('chat_archive', 'not in Nextcloud') }}</span>
		</div>
		<div v-if="media.caption" class="ca-media__caption" dir="auto">
			{{ media.caption }}
		</div>
	</div>
</template>

<script>
import { getDialogBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActionLink, NcActions, NcButton, NcNoteCard } from '@nextcloud/vue'
import Download from 'vue-material-design-icons/Download.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileHidden from 'vue-material-design-icons/FileHidden.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Laptop from 'vue-material-design-icons/Laptop.vue'
import { davUrl, filesUrl, openLocally, openMedia, previewUrl } from '../api.js'
import { isAudio, isImage, isVideo, kindLabel } from '../util.js'

export default {
	name: 'MediaView',
	components: { NcActions, NcActionButton, NcActionLink, NcButton, NcNoteCard, Download, FileDocument, FileHidden, Folder, Laptop },
	props: {
		media: { type: Object, required: true },
		kind: { type: String, default: '' },
	},

	computed: {
		filesLink() {
			return filesUrl(this.media.fileId, this.media.path)
		},

		/** The mime every type branch keys on: the live file's own mime when known, else the backup's declared mime. */
		effectiveMime() {
			return this.media.realMime ?? this.media.mime
		},

		/** The name shown to the user: the live file's own name first, so a spoofed backup name never wins. */
		displayName() {
			return this.media.realName || this.media.fileName || this.media.path
		},

		mismatchTitle() {
			return t('chat_archive', 'WhatsApp recorded {declared}. Nextcloud sees {real}.', { declared: this.media.mime, real: this.media.realMime })
		},
	},

	methods: {
		t,
		davUrl,
		previewUrl,
		openLocally,
		openMedia,
		isImage,
		isVideo,
		isAudio,
		kindLabel,

		/**
		 * Asks before a flagged file leaves the server, with a dialog in the
		 * app's own style. Each button carries its own outcome; a
		 * close with neither button starts no download.
		 */
		confirmDownload() {
			getDialogBuilder(t('chat_archive', 'Download suspicious file'))
				.setText(t('chat_archive', 'WhatsApp flagged this file as suspicious. Download it only if you trust the sender.'))
				.setSeverity('warning')
				.addButton({ label: t('chat_archive', 'Cancel'), callback: () => {} })
				.addButton({
					label: t('chat_archive', 'Download'),
					variant: 'primary',
					callback: () => {
						window.location.href = davUrl(this.media.path)
					},
				})
				.build()
				.show()
				.catch(() => {}) // dismissed without a button: no download
		},
	},
}
</script>

<style scoped>
.ca-media__img {
	max-width: 100%;
	max-height: 360px;
	border-radius: var(--border-radius);
	display: block;
}
.ca-media__video {
	max-width: 100%;
	max-height: 360px;
}
.ca-media__audio {
	max-width: 100%;
	display: block;
}
.ca-media__doc,
.ca-media__missing {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}
.ca-media__doc--thumb {
	flex-direction: column;
	align-items: flex-start;
}
.ca-media__thumb {
	max-width: 100%;
	max-height: 200px;
	border-radius: var(--border-radius);
	display: block;
}
.ca-media__missing {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
.ca-media__warning {
	max-width: 360px;
}
.ca-media__warning-name {
	font-weight: bold;
	word-break: break-word;
	margin: 4px 0;
}
.ca-media__bar {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 8px;
}
.ca-media__chip {
	/* Pushes the actions to the right whether or not the chip is present. */
	margin-right: auto;
	display: inline-flex;
	align-items: center;
	padding: 0 6px;
	border-radius: var(--border-radius-pill);
	background: color-mix(in srgb, currentColor 14%, transparent);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	line-height: 18px;
}
.ca-media__caption {
	margin-top: 4px;
}
</style>
