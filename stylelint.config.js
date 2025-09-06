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
			'color-function-notation': 'legacy',
			'color-no-invalid-hex': true,
			'block-no-empty': true,
			'no-empty-source': true,
			'length-zero-no-unit': true,
			'declaration-block-no-duplicate-properties': [true, { ignore: ['consecutive-duplicates-with-different-values'] }],
			'property-no-vendor-prefix': null,
			'selector-class-pattern': null,
			'keyframes-name-pattern': null,
			'no-descending-specificity': null,
			'declaration-block-single-line-max-declarations': null,
			'media-feature-name-value-no-unknown': null
	},
	reportDescriptionlessDisables: false,
	reportInvalidScopeDisables: false,
	cache: true,
	fix: true
	}
