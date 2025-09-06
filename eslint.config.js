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
	{ ignores: ["node_modules/**", "dist/**", "assets/js/**/*.min.js", "assets/js/vendor/**"] },
	js.configs.recommended,
	security.configs.recommended,
	a11y.flatConfigs.recommended,
	{
		files: ["assets/js/**/*.js"],
		languageOptions: {
			ecmaVersion: 2015,
			sourceType: "script",
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
				STARMUS_EDITOR_DATA: "readonly",
				STARMUS_RECORDER_DATA: "readonly",
				StarmusAudioRecorder: "readonly",
			},
		},
		rules: {
			"no-console": "off",
			"no-unused-vars": ["error", { argsIgnorePattern: "^_", varsIgnorePattern: "^_" }],
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