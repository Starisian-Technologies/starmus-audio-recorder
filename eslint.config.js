// eslint.config.js
export default [
  {
    files: ['**/*.js'],
    languageOptions: {
      ecmaVersion: 2017,
      sourceType: 'module',
      globals: { window: 'readonly', document: 'readonly' }
    },
    rules: {
      'no-unused-vars': 'warn',
      'no-console': 'off',
      'semi': ['error', 'always'],
      'quotes': ['error', 'single']
    }
  }
];
