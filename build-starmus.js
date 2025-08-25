// build-starmus.js
import { readFileSync, writeFileSync } from 'fs';
import { createHash } from 'crypto';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const SRC = process.argv[2] || path.join(__dirname, 'assets/js/starmus-audio-recorder-module.js');
const MIN = SRC.replace(/\.js$/, '.min.js');
const HEADER_TAG = '// ==== starmus-audio-recorder-module.js ====';

function stableHashBytes(s) { return Buffer.from(s.replace(/^\/\/.*(\r?\n)?/gm, ''), 'utf8'); }

function hash(content) {
  const b = stableHashBytes(content);
  return {
    sha1:   createHash('sha1').update(b).digest('hex'),
    sha256: createHash('sha256').update(b).digest('hex'),
  };
}

function applyHeaderAndProp(content, sha1, sha256) {
  const header = `${HEADER_TAG}\n// Build Hash (SHA-1):   ${sha1}\n// Build Hash (SHA-256): ${sha256}\n\n`;
  let out = content.replace(/buildHash:\s*['"][a-f0-9]*['"]/, `buildHash: '${sha1}'`);
  if (out === content) {
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

/**
 * Reads, hashes, updates, and writes a file.
 * This function is now resilient to race conditions.
 * @param {string} fp The file path to process.
 * @returns {object|null} An object with file info on success, or null on failure.
 */
function processFile(fp) {
  try {
    // We REMOVED the `existsSync` check.
    // Instead, we immediately TRY to read the file.
    const original = readFileSync(fp, 'utf8');
    const { sha1, sha256 } = hash(original);
    const updated = applyHeaderAndProp(original, sha1, sha256);
    writeFileSync(fp, updated);
    
    // This code only runs if the above lines succeed.
    return { fp, sha1, sha256 };
  } catch (error) {
    // If readFileSync or writeFileSync fails (e.g., file not found, no permissions),
    // we CATCH the error here and handle it gracefully.
    if (error.code === 'ENOENT') {
      // ENOENT is the error code for "File Not Found".
      // We can return null to indicate the file was skipped, which is cleaner than throwing.
      console.warn(`Skipping: File not found at ${fp}`);
      return null;
    }
    // For other errors (like permissions), we should re-throw to fail the build.
    console.error(`An error occurred while processing ${fp}:`, error);
    throw error;
  }
}

// --- Main Execution ---
console.log(`Processing main file: ${SRC}`);
const res1 = processFile(SRC);
// If res1 is null (because the main file was missing), we should probably stop.
if (!res1) {
  console.error("Main source file could not be processed. Aborting.");
  process.exit(1); // Exit with an error code.
}
console.log(`Updated: ${res1.fp}\n  sha1=${res1.sha1}\n  sha256=${res1.sha256}`);

// Process the minified file, which is optional.
console.log(`Processing minified file: ${MIN}`);
const res2 = processFile(MIN);
if (res2) {
  console.log(`Updated: ${res2.fp}`);
}
