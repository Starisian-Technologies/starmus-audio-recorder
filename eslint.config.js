export default [
  {
    files: ['assets/js/**/*.js', '!assets/js/**/*.min.js', '!assets/js/**/*.js.map'],
    languageOptions: {
      ecmaVersion: 2017,
      sourceType: 'script',
      globals: { window: 'readonly', document: 'readonly' }
    },
    env: { browser: true },
    rules: {
      'no-unused-vars': 'warn',
      'no-console': 'off',
      'semi': ['error', 'always'],
      'quotes': ['error', 'single']
    }
  }
];
