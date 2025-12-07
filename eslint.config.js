// eslint.config.js
import js from "@eslint/js";
import globals from "globals";

export default [
  {
    ignores: [
      "node_modules/**",
      "vendor/**",
      "dist/**",
      "build/**",
      "assets/js/**/*.min.js",
      "docs/**",
      "tests/**",
      "*.config.js",
      ".*rc.js",
      "build-*.js",
      "sync-*.js",
      "validate-*.js",
      "test.js",
      "sync-version.js",
      "validate-build.js",
      "playwright.config.js",
      "stylelint.config.js",
      ".markdownlint.js",
      ".commitlintrc.js",
    ],
  },
  js.configs.recommended,
  {
    files: ["src/js/**/*.js", "assets/js/**/*.js"],
    languageOptions: {
      ecmaVersion: 2020,
      sourceType: "module",
      globals: {
        ...globals.browser,
        ...globals.node,
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
        StarmusTranscript: "readonly",
      },
    },
    rules: {
      "no-console": "off",
      "no-unused-vars": [
        "error",
        { argsIgnorePattern: "^_", varsIgnorePattern: "^_" },
      ],
      "no-undef": "error",
      "no-redeclare": "error",
      "prefer-const": "error",
      "no-var": "error",
      eqeqeq: ["error", "always"],
      curly: ["error", "all"],
    },
  },
];
