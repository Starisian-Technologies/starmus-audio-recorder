#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Universal build script for Starisian / Sparxstar plugins.
 *
 * - Concatenates ALL JS in src/js into:
 *     assets/js/<PLUGIN_SLUG>-script.js
 *     assets/js/<PLUGIN_SLUG>-script.min.js
 * - Concatenates ALL CSS in src/css into:
 *     assets/css/<PLUGIN_SLUG>-styles.css
 *     assets/css/<PLUGIN_SLUG>-styles.min.css
 *
 * Vendor JS/CSS is NOT touched (composer / your own process manages that).
 */

const fs = require('fs');
const path = require('path');
const { minify } = require('terser');
const csso = require('csso');

// ---- PLUGIN CONFIG ----
// For this plugin:
const PLUGIN_SLUG = 'sparxstar-user-environment-check';
// If you reuse this script in another repo, just change PLUGIN_SLUG there.

const SRC_JS_DIR  = path.join(__dirname, 'src', 'js');
const SRC_CSS_DIR = path.join(__dirname, 'src', 'css');
const OUT_JS_DIR  = path.join(__dirname, 'assets', 'js');
const OUT_CSS_DIR = path.join(__dirname, 'assets', 'css');

const JS_OUT_BASENAME  = `${PLUGIN_SLUG}-script`;
const CSS_OUT_BASENAME = `${PLUGIN_SLUG}-styles`;

function ensureDir(dir) {
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

function readAllFiles(dir, ext) {
  if (!fs.existsSync(dir)) {
    console.warn(`[build] Directory not found, skipping: ${dir}`);
    return [];
  }
  return fs
    .readdirSync(dir)
    .filter((f) => f.toLowerCase().endsWith(ext))
    .sort()
    .map((f) => path.join(dir, f));
}

function concatFiles(files, banner) {
  const parts = [];
  if (banner) {
    parts.push(banner);
  }
  for (const file of files) {
    const content = fs.readFileSync(file, 'utf8');
    parts.push(`\n/* ===== ${path.basename(file)} ===== */\n`);
    parts.push(content.trimEnd());
    parts.push('\n');
  }
  return parts.join('');
}

(async () => {
  try {
    console.log('[build] Starting build for', PLUGIN_SLUG);

    ensureDir(OUT_JS_DIR);
    ensureDir(OUT_CSS_DIR);

    // ---------- JS ----------
    const jsFiles = readAllFiles(SRC_JS_DIR, '.js');
    if (jsFiles.length === 0) {
      console.warn('[build] No JS files found in src/js; skipping JS bundle.');
    } else {
      const jsBanner =
        `/**\n` +
        ` * Auto-generated bundle for ${PLUGIN_SLUG} (do not edit directly).\n` +
        ` * Source: /src/js/*.js\n` +
        ` */\n`;

      const jsBundle = concatFiles(jsFiles, jsBanner);

      const jsOutPath      = path.join(OUT_JS_DIR, `${JS_OUT_BASENAME}.js`);
      const jsOutMinPath   = path.join(OUT_JS_DIR, `${JS_OUT_BASENAME}.min.js`);

      fs.writeFileSync(jsOutPath, jsBundle, 'utf8');
      console.log('[build] Wrote JS bundle:', jsOutPath);

      const minified = await minify(jsBundle, {
        compress: true,
        mangle: true,
        output: { comments: /^!/ } // keep /*! ... */ if you ever add license banners
      });

      if (!minified || typeof minified.code !== 'string') {
        throw new Error('Terser failed to produce minified JS code.');
      }

      fs.writeFileSync(jsOutMinPath, minified.code, 'utf8');
      console.log('[build] Wrote JS minified bundle:', jsOutMinPath);
    }

    // ---------- CSS ----------
    const cssFiles = readAllFiles(SRC_CSS_DIR, '.css');
    if (cssFiles.length === 0) {
      console.warn('[build] No CSS files found in src/css; skipping CSS bundle.');
    } else {
      const cssBanner =
        `/**\n` +
        ` * Auto-generated styles for ${PLUGIN_SLUG} (do not edit directly).\n` +
        ` * Source: /src/css/*.css\n` +
        ` */\n`;

      const cssBundle = concatFiles(cssFiles, cssBanner);

      const cssOutPath    = path.join(OUT_CSS_DIR, `${CSS_OUT_BASENAME}.css`);
      const cssOutMinPath = path.join(OUT_CSS_DIR, `${CSS_OUT_BASENAME}.min.css`);

      fs.writeFileSync(cssOutPath, cssBundle, 'utf8');
      console.log('[build] Wrote CSS bundle:', cssOutPath);

      const minifiedCss = csso.minify(cssBundle, { restructure: true });
      fs.writeFileSync(cssOutMinPath, minifiedCss.css, 'utf8');
      console.log('[build] Wrote CSS minified bundle:', cssOutMinPath);
    }

    // ---------- Git hash stamp (optional but useful) ----------
    try {
      const { execSync } = require('child_process');
      const hash = execSync('git rev-parse --short HEAD').toString().trim();
      const outFile = path.join(__dirname, 'assets', 'build-hash.txt');
      fs.writeFileSync(outFile, `${hash}\n`, 'utf8');
      console.log('[build] Wrote build hash:', hash);
    } catch (e) {
      console.warn('[build] Unable to read git hash (not a git repo or git not installed).');
    }

    console.log('[build] Done.');
  } catch (err) {
    console.error('[build] Build failed:', err.message);
    process.exit(1);
  }
})();
