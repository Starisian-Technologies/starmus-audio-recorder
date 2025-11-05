module.exports = {
  root: true,
  env: {
    browser: true,
    es2022: true,
    node: true
  },
  parserOptions: {
    ecmaVersion: "latest",
    sourceType: "module"
  },
  plugins: [
    "import",
    "jsdoc"
  ],
  extends: [
    "eslint:recommended",
    "plugin:jsdoc/recommended"
  ],
  rules: {
    "no-undef": "error",
    "no-unused-vars": ["error", { argsIgnorePattern: "^_" }],
    "prefer-const": "error",
    "no-var": "error",

    // --- JSDoc Formatting Rules ---
    "jsdoc/require-description": "warn",
    "jsdoc/require-param-type": "warn",
    "jsdoc/require-returns-type": "warn",
    "jsdoc/check-alignment": "warn",
    "jsdoc/check-indentation": "warn",
    "jsdoc/newline-after-description": "warn"
  },
  settings: {
    jsdoc: {
      mode: "typescript"
    }
  },
  globals: {
    MwaiAPI: "readonly",
    fill_form_fields: "readonly"
  }
};
