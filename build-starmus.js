
const fs = require('fs');
const crypto = require('crypto');

const filePath = 'starmus-audio-recorder-module.js';
let content = fs.readFileSync(filePath, 'utf8');

// Compute SHA-1
const sha1 = crypto.createHash('sha1').update(content).digest('hex');

// Replace the placeholder in `buildHash`
content = content.replace(/buildHash:\s*['"]YOUR_BUILD_HASH_HERE['"]/, `buildHash: '${sha1}'`);

// Optionally insert hashes as comments at the top
const hashComment = `// SHA-1: ${sha1}\n`;
if (!content.startsWith('// SHA-1')) {
  content = hashComment + content;
}

fs.writeFileSync(filePath, content);