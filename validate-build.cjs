const fs = require('fs');

console.log("🔍 Validating build configuration...");

const requiredFiles = [
  'src/js/starmus-hooks.js',
  'src/js/starmus-state-store.js',
  'src/js/starmus-recorder.js',
  'src/js/starmus-core.js',
  'src/js/starmus-ui.js',
  'src/js/starmus-integrator.js',
  'src/css/starmus-audio-recorder-style.css'
];

let ok = true;

for (const file of requiredFiles) {
  if (!fs.existsSync(file)) {
    console.log(`❌ Missing: ${file}`);
    ok = false;
  } else {
    console.log(`✅ ${file}`);
  }
}

if (!ok) {
  console.log("⚠️ Validation failed.");
  process.exit(1);
}

console.log("🎉 Validation complete!");
process.exit(0);