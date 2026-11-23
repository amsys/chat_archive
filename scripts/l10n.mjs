// Collects every t('chat_archive', '...') string from src/ and $this->l->t('...') from lib/,
// adds missing keys to each l10n/<lang>.json with an empty translation, drops keys that no
// longer exist, and writes the l10n/<lang>.js file Nextcloud loads in the browser.
// Usage: node scripts/l10n.mjs [--check]   (--check fails when a translation is missing)
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import process from 'node:process'

/**
 * @param {string} dir a directory
 * @return {string[]} every file below it
 */
function walk(dir) {
	return readdirSync(dir).flatMap((f) => {
		const p = join(dir, f)
		return statSync(p).isDirectory() ? walk(p) : [p]
	})
}
const unquote = (s) => s.slice(1, -1).replace(/\\(['"\\])/g, '$1')
const strings = new Set()
for (const file of [...walk('src'), ...walk('lib')]) {
	const re = file.endsWith('.php')
		? /->t\(\s*('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")/g
		: /\bt\(\s*'chat_archive',\s*('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")/g
	for (const m of readFileSync(file, 'utf8').matchAll(re)) {
		strings.add(unquote(m[1]))
	}
}

let missing = 0
for (const file of readdirSync('l10n').filter((f) => f.endsWith('.json'))) {
	const lang = file.slice(0, -5)
	const old = JSON.parse(readFileSync(join('l10n', file), 'utf8'))
	const translations = {}
	for (const s of [...strings].sort()) {
		translations[s] = old.translations?.[s] ?? ''
		if (translations[s] === '') {
			missing++
			console.log(`${lang}: missing "${s}"`)
		}
	}
	const out = { translations, pluralForm: old.pluralForm ?? 'nplurals=2; plural=(n != 1);' }
	writeFileSync(join('l10n', file), JSON.stringify(out, null, 4) + '\n')
	const done = Object.fromEntries(Object.entries(translations).filter(([, v]) => v !== ''))
	writeFileSync(join('l10n', `${lang}.js`), `OC.L10N.register(\n    "chat_archive",\n    ${JSON.stringify(done, null, 4).replace(/\n/g, '\n    ')},\n"${out.pluralForm}");\n`)
}
console.log(`${strings.size} strings`)
if (process.argv.includes('--check') && missing > 0) {
	process.exit(1)
}
