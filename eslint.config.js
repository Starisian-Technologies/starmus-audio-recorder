// eslint.config.js
import js from "@eslint/js";
import globals from "globals";

export default [
  { ignores: ["node_modules/**", "dist/**", "assets/js/**/*.min.js", "assets/js/vendor/**"] },
  js.configs.recommended,
  {
    files: ["assets/js/**/*.js"],
    languageOptions: {
      ecmaVersion: 2022,
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
        StarmusAudioRecorder: "readonly",
      },
    },
    rules: {
      "no-console": "off",
      "no-unused-vars": ["error", { argsIgnorePattern: "^_", varsIgnorePattern: "^_" }],
    },
  },
];