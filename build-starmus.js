// SPDX-FileCopyrightText: 2023-2025 Starisian Technologies
// SPDX-License-Identifier: MIT

// Patch: Use child_process.execSync for git commands
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Get git hash safely
let gitHash = "";
try {
  gitHash = execSync("git rev-parse --short HEAD").toString().trim();
} catch (e) {
  gitHash = "nogit";
}

// Example: write hash to a file (customize as needed)
const args = process.argv.slice(2);
const outFile = path.join(__dirname, "assets", "build-hash.txt");
fs.writeFileSync(outFile, gitHash + "\n");

// If you need to update asset filenames or manifest, do it here
// (This is a placeholder for your actual build logic)
