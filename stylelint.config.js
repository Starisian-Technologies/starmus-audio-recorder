/**
 * @file stylelint.config.js
 * @description Stylelint configuration file.
 * @type {import('stylelint').Config}
 */
export default {
	extends: ['stylelint-config-standard'],
	ignoreFiles: [
		'node_modules/**',
		'dist/**',
		'build/**',
		'assets/css/**/*.min.css'
	],
	rules: {
		// Formatting rules disabled for Prettier compatibility
		'indentation': null,
		'string-quotes': null,
		'color-function-notation': 'legacy',
		'color-hex-case': 'lower',
		'color-no-invalid-hex': true,
		'block-no-empty': true,
		'no-empty-source': true,
		// 'declaration-block-trailing-semicolon': handled by Prettier,
		'length-zero-no-unit': true,
		'declaration-block-no-duplicate-properties': [true, { ignore: ['consecutive-duplicates-with-different-values'] }],
		'property-no-vendor-prefix': null
	},
	reportDescriptionlessDisables: true,
	reportInvalidScopeDisables: true,
	cache: true,
	fix: true
}
