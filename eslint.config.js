// eslint.config.js
import js from "@eslint/js";
import globals from "globals";
import prettier from "eslint-config-prettier";
import security from "eslint-plugin-security";
import a11y from "eslint-plugin-jsx-a11y";
import perf from "eslint-plugin-perf-standard";
import jsdoc from "eslint-plugin-jsdoc";
import wordpress from "@wordpress/eslint-plugin";

export default [
	{ ignores: ["node_modules/**", "dist/**", "assets/js/**/*.min.js", "vendor/**", "docs/**", "tests/**", "*.config.js", ".*rc.js", "build-*.js", "sync-*.js", "validate-*.js", "test.js", "sync-version.js", "validate-build.js", "playwright.config.js", "stylelint.config.js", ".markdownlint.js", ".commitlintrc.js"] },
	js.configs.recommended,
	security.configs.recommended,
	a11y.flatConfigs.recommended,
	{
		files: ["src/js/**/*.js", "assets/js/**/*.js"],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: "module",
			globals: {
				...globals.browser,
				...globals.jquery,
				fetch: "readonly",
				Audio: "readonly",
				MediaRecorder: "readonly",
				MutationObserver: "readonly",
				CustomEvent: "readonly",
				indexedDB: "readonly",
				Peaks: "readonly",
				tus: "readonly",
				STARMUS_EDITOR_DATA: "readonly",
				STARMUS_RECORDER_DATA: "readonly",
				StarmusAudioRecorder: "readonly",
			},
		},
		rules: {
			"no-console": "warn",
			"no-unused-vars": ["error", { argsIgnorePattern: "^_", varsIgnorePattern: "^_" }],
			"no-undef": "error",
			"no-redeclare": "error",
			"prefer-const": "error",
			"no-var": "error",
			"eqeqeq": ["error", "always"],
			"curly": ["error", "all"],
			"jsdoc/require-description": "warn",
			"jsdoc/require-param-description": "warn",
			"jsdoc/require-returns-description": "warn",
			...prettier.rules,
		},
		plugins: {
			jsdoc,
			perf
		},
	},
	];