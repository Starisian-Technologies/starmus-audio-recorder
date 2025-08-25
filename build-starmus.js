// build-starmus.js
import { readFileSync, writeFileSync, existsSync } from 'fs';
import { createHash } from 'crypto';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const SRC = process.argv[2] || path.join(__dirname, 'assets/js/starmus-audio-recorder-module.js');
const MIN = SRC.replace(/\.js$/, '.min.js');
const HEADER_TAG = '// ==== starmus-audio-recorder-module.js ====';

function stableHashBytes(s) { return Buffer.from(s.replace(/^\/\/.*(\r?\n)?/gm, ''), 'utf8'); } // strip comment lines before hashing

function hash(content) {
  const b = stableHashBytes(content);
  return {
    sha1:   createHash('sha1').update(b).digest('hex'),
    sha256: createHash('sha256').update(b).digest('hex'),
  };
}

function applyHeaderAndProp(content, sha1, sha256) {
  const header = `${HEADER_TAG}\n// Build Hash (SHA-1):   ${sha1}\n// Build Hash (SHA-256): ${sha256}\n\n`;
  let out = content.replace(/buildHash:\s*['"][a-f0-9]*['"]/, m => `buildHash: '${sha1}'`);
  if (out === content) {
    // insert buildHash near top-level object/const if not present
    out = out.replace(/(\{)([^]*?)(\})/, (m, a, mid, c) =>
      `${a}\n  buildHash: '${sha1}',${mid}\n${c}`
    );
  }
  if (out.startsWith(HEADER_TAG)) {
    out = out
      .replace(/\/\/ Build Hash \(SHA-1\):[^\n]*/i, `// Build Hash (SHA-1):   ${sha1}`)
      .replace(/\/\/ Build Hash \(SHA-256\):[^\n]*/i, `// Build Hash (SHA-256): ${sha256}`);
  } else {
    out = header + out;
  }
  return out;
}

function processFile(fp) {
  if (!existsSync(fp)) throw new Error(`File not found: ${fp}`);
  const original = readFileSync(fp, 'utf8');
  const { sha1, sha256 } = hash(original);
  const updated = applyHeaderAndProp(original, sha1, sha256);
  writeFileSync(fp, updated);
  return { fp, sha1, sha256 };
}

const res1 = processFile(SRC);
const res2 = existsSync(MIN) ? processFile(MIN) : null;

console.log(`Updated: ${res1.fp}\n  sha1=${res1.sha1}\n  sha256=${res1.sha256}`);
if (res2) console.log(`Updated: ${res2.fp}`);
