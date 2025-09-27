#!/usr/bin/env node
// validate-build.js - Quick validation script

import { readFileSync, existsSync } from "fs";

console.log("🔍 Validating build configuration...");

try {
  // Test package.json
  const pkg = JSON.parse(readFileSync("package.json", "utf8"));
  console.log("✅ package.json is valid JSON");
  console.log(`📦 Version: ${pkg.version}`);

  // Test required files exist
  const requiredFiles = [
    "src/js/starmus-audio-recorder-hooks.js",
    "src/js/starmus-audio-recorder-module.js",
    "src/js/starmus-audio-recorder-submissions-handler.js",
    "src/js/starmus-audio-recorder-ui-controller.js",
    "src/css/starmus-audio-recorder-style.css",
  ];

  for (const file of requiredFiles) {
    if (existsSync(file)) {
      console.log(`✅ ${file}`);
    } else {
      console.log(`❌ Missing: ${file}`);
    }
  }

  console.log("🎉 Validation complete!");
} catch (error) {
  console.error("❌ Validation failed:", error.message);
  process.exit(1);
}
