module.exports = {
  extends: [
    "stylelint-config-standard"
  ],
  rules: {
    // Example: allow BEM and underscores in class selectors
    "selector-class-pattern": [
      "^[a-zA-Z0-9_-]+$",
      {
        "message": "Class selectors should only contain letters, numbers, underscores, or hyphens."
      }
    ]
  }
};
