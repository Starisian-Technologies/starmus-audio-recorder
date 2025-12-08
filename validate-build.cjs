#!/usr/bin/env node
const fs = require('fs');

console.log("🔍 Validating build configuration...\n");

const requiredFiles = [
  'src/css/starmus-audio-recorder-style.css',
  'src/js/starmus-audio-editor.js',
  'src/js/starmus-core.js',
  'src/js/starmus-hooks.js',
  'src/js/starmus-integrator.js',
  'src/js/starmus-main.js',
  'src/js/starmus-recorder.js',
  'src/js/starmus-state-store.js',
  'src/js/starmus-transcript-controller.js',
  'src/js/starmus-tus.js',
  'src/js/starmus-ui.js'
];

let ok = true;

// ---- FIXED TEST: VALIDATE PEAKS GLOBAL BRIDGE EXISTENCE ----
try {
  const integrator = fs.readFileSync('src/js/starmus-integrator.js', 'utf8');

  const hasExposeFn = integrator.includes('exposePeaksBridge');
  const hasStarmusPeaks = integrator.includes('Starmus.Peaks');

  if (!hasExposeFn || !hasStarmusPeaks) {
    console.log("❌ Peaks.js global bridge missing in starmus-integrator.js");
    console.log("   ➤ Ensure `exposePeaksBridge` is defined and sets `Starmus.Peaks = Peaks`.");
    ok = false;
  }

  const directPeaksAccess = integrator.includes('Peaks.init(') && !integrator.includes('Starmus.Peaks');
  if (directPeaksAccess) {
    console.log("⚠️ Direct use of global `Peaks` detected. Prefer `Starmus.Peaks` for bridge consistency.");
  }
} catch (err) {
  console.error("❌ Failed to read starmus-integrator.js for inspection:", err.message);
  ok = false;
}

// ---- CHECK FILE PRESENCE ----
console.log("\n📦 Checking required files:");
for (const file of requiredFiles) {
  if (!fs.existsSync(file)) {
    console.log(`❌ Missing: ${file}`);
    ok = false;
  } else {
    console.log(`✅ Found: ${file}`);
  }
}

// ---- FINAL EXIT ----
if (!ok) {
  console.log("\n⚠️ Validation failed. Fix the issues above before bundling.");
  process.exit(1);
}

console.log("\n🎉 Validation complete! All checks passed.\n");
process.exit(0);
