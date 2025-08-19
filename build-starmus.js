import { readFileSync, writeFileSync } from 'fs';
import { createHash } from 'crypto';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const filePath = path.join(__dirname, 'assets/js/starmus-audio-recorder-module.js');
let content = readFileSync(filePath, 'utf8');

// Compute SHA-1 and SHA-256 hashes of the file
const sha1 = createHash('sha1').update(content).digest('hex');
const sha256 = createHash('sha256').update(content).digest('hex');

// Replace or insert the buildHash property
content = content.replace(/buildHash:\s*['"][a-f0-9]*['"]/, `buildHash: '${sha1}'`);

// Ensure hash comments appear at the top of the file
const header = '// ==== starmus-audio-recorder-module.js ====\n';
const sha1Line = `// Build Hash (SHA-1):   ${sha1}`;
const sha256Line = `// Build Hash (SHA-256): ${sha256}`;

if (content.startsWith(header)) {
  content = content
    .replace(/\/\/ Build Hash \(SHA-1\):[^\n]*/i, sha1Line)
    .replace(/\/\/ Build Hash \(SHA-256\):[^\n]*/i, sha256Line);
} else {
  content = `${header}${sha1Line}\n${sha256Line}\n\n${content}`;
}

writeFileSync(filePath, content);
