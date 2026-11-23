import { createAppConfig } from '@nextcloud/vite-config'

// One entry per build: with several entries vite attaches the CSS of the
// shared @nextcloud/vue chunk to the first entry only, and the other page
// renders unstyled. `npm run build` runs this once per entry.
const entry = process.env.ENTRY || 'main'

export default createAppConfig({
	[entry]: `src/${entry}.js`,
}, {
	inlineCSS: true,
	emptyOutputDirectory: entry === 'main',
})
