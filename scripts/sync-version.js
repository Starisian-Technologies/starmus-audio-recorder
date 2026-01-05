#!/usr/bin/env node
// sync-version.js - Sync package.json version to PHP files

import { readFileSync, writeFileSync } from "fs";

try {
  const pkg = JSON.parse(readFileSync("package.json", "utf8"));
  const version = pkg.version;

  console.log(`🔄 Syncing version ${version} to PHP files...`);

  // Update main plugin file
  let php = readFileSync("starmus-audio-recorder.php", "utf8");

  // Update plugin header version
  php = php.replace(/(\* Version:\s+)[0-9.]+/, `$1${version}`);

  // Update STARMUS_VERSION constant
  php = php.replace(
    /(define\(\s*'STARMUS_VERSION',\s*')[0-9.]+('.*\);)/,
    `$1${version}$2`,
  );

  writeFileSync("starmus-audio-recorder.php", php, "utf8");

  console.log("✅ Version sync complete!");
} catch (error) {
  console.error("❌ Version sync failed:", error.message);
  process.exit(1);
}
