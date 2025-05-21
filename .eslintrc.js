// .eslintrc.js
module.exports = {
  env: { browser: true },
  parserOptions: {
    ecmaVersion: 2017,
    sourceType: 'script'
  },
  globals: {
    window: 'readonly',
    document: 'readonly'
  },
  ignorePatterns: ['**/*.min.js', '**/*.js.map'],
  rules: {
    'no-unused-vars': 'warn',
    'no-console': 'off',
    'semi': ['error', 'always'],
    'quotes': ['error', 'single']
  }
};
