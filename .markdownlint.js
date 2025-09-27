// .markdownlint.js

module.exports = {
  // Use all default rules as a base.
  default: true,

  // Disable line-length rule (MD013).
  MD013: false,

  // Set indentation for unordered lists to 4 spaces.
  MD007: {
    indent: 4,
  },

  // Allow specific HTML elements for things like collapsible sections.
  MD033: {
    allowed_elements: ["details", "summary", "p"],
  },

  // Enforce that ordered lists are sequential (1. 2. 3.).
  MD029: {
    style: "ordered",
  },

  // Allow duplicate headings if they are not nested under each other.
  MD024: {
    allow_different_nesting: true,
  },

  // Enforce that the first line is a top-level heading (good for titles).
  MD041: true,

  // Enforce a consistent horizontal rule style.
  MD035: {
    style: "---",
  },

  // Enforce no hard tabs (alias for MD010).
  "no-hard-tabs": true,

  // Disable a group of rules related to whitespace.
  // This is a "tag", not a single rule.
  whitespace: false,

  // This is redundant as you already have "MD013": false.
  // It's good practice to remove one for clarity.
  "line-length": false,
};
