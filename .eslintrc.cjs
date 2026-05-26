module.exports = {
	extends: [
		'@nextcloud',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
	overrides: [
		{
			// @nextcloud/eslint-config uses @babel/eslint-parser for .vue files,
			// which cannot parse TypeScript in <script setup lang="ts">.
			// Override to use @typescript-eslint/parser as the inner parser.
			files: ['**/*.vue'],
			parser: 'vue-eslint-parser',
			parserOptions: {
				parser: '@typescript-eslint/parser',
			},
		},
	],
}
