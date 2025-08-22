module.exports = {
  extends: ['stylelint-config-standard'],
  ignoreFiles: ['**/*.min.css'],
  rules: {
    'selector-class-pattern': null,
    'selector-id-pattern': null,
    'string-quotes': 'single',
    'block-no-empty': true,
    'color-no-invalid-hex': true
  } 
};
