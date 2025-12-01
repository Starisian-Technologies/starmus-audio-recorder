const fs = require('fs');

console.log("🔍 Validating build configuration...");

const requiredFiles = [
  'src/js/starmus-hooks.js',
  'src/js/starmus-state-store.js',
  'src/js/starmus-recorder.js',
  'src/js/starmus-core.js',
  'src/js/starmus-ui.js',
  'src/js/starmus-transcript-controller.js',
  'src/js/starmus-tus.js',
  'src/js/starmus-integrator.js',
  'src/js/starmus-audio-editor.js', 
  'src/css/starmus-audio-recorder-style.css'
];

let ok = true;

if (!fs.readFileSync('src/js/starmus-integrator.js', 'utf8').includes('window.Peaks')) {
  console.log("❌ Peaks global bridge missing in starmus-integrator.js");
  ok = false;
  process.exit(1);
}

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
