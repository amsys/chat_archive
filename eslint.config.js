import { recommendedJavascript } from '@nextcloud/eslint-config'
import sonarjs from 'eslint-plugin-sonarjs'

export default [
	...recommendedJavascript,
	{
		files: ['src/**/*.{js,vue}', 'scripts/**/*.mjs'],
		plugins: { sonarjs },
		rules: {
			// same threshold as SonarCloud
			'sonarjs/cognitive-complexity': ['error', 15],
			'sonarjs/no-nested-conditional': 'error',
			'sonarjs/no-identical-functions': 'error',
			'sonarjs/no-collapsible-if': 'error',
			'sonarjs/prefer-immediate-return': 'error',
			'sonarjs/no-redundant-boolean': 'error',
			'sonarjs/no-duplicate-string': 'off', // t('chat_archive', ...) repeats the app id by design
			complexity: ['error', 15],
			'max-depth': ['error', 4],
			'max-params': ['error', 5],
			'no-console': ['error', { allow: ['warn', 'error'] }],
		},
	},
	{
		files: ['scripts/**/*.mjs'],
		rules: { 'no-console': 'off' },
	},
	{ ignores: ['js/', 'vendor/', 'l10n/', 'node_modules/'] },
]
