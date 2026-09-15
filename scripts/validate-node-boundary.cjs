#!/usr/bin/env node
'use strict';

/**
 * Boundary checks for the Spoken Audio Node.
 *
 * ADR-034 lists what this repository no longer owns and ADR-038 lists what it
 * may never do. A list in a document is a list somebody refactors past, so the
 * ones that can be checked mechanically are checked here and fail the build.
 *
 * Checks:
 *   1. No PHP anywhere — this is a Node/TypeScript service (ADR-038).
 *   2. No WordPress surfaces: templates, shortcodes, admin screens, CPT or
 *      custom-field persistence (ADR-034).
 *   3. No browser capture code — the microphone belongs to the capture UI.
 *   4. Tools are spawned, never linked: no native bindings (ADR-038, and a
 *      licensing requirement given Praat is GPL).
 *   5. Every pinned tool carries a version and a licence attestation.
 *   6. Durable storage URLs are not modelled anywhere.
 *   7. Transcript and translation records are not modelled anywhere — they are
 *      ESU's (ADR-034).
 *   8. Content-edit capability exists only in the release pipeline (ADR-039).
 */

const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');
const SKIP_DIRS = new Set(['node_modules', '.git', 'dist']);

let ok = true;

function fail(message) {
    console.log(`FAIL ${message}`);
    ok = false;
}

function pass(message) {
    console.log(`ok   ${message}`);
}

function walk(dir, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (SKIP_DIRS.has(entry.name)) {
            continue;
        }
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            walk(full, out);
        } else if (entry.isFile()) {
            out.push(path.relative(ROOT, full));
        }
    }
    return out;
}

const files = walk(ROOT);
const sourceFiles = files.filter((file) => file.startsWith(`src${path.sep}`) && file.endsWith('.ts'));
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');

/* ---- 1. no PHP ---- */
{
    const php = files.filter((file) => file.endsWith('.php'));
    if (php.length > 0) {
        fail(
            `${php.length} PHP file(s) remain (${php.slice(0, 3).join(', ')}…). ADR-038: this repository is a Node/TypeScript service.`,
        );
    } else {
        pass('No PHP — the service is Node/TypeScript (ADR-038)');
    }
}

/* ---- 2. no WordPress surfaces ---- */
{
    const markers = [
        [/\badd_shortcode\b/, 'shortcode registration'],
        [/\bregister_post_type\b/, 'custom post type registration'],
        [/\bwp_enqueue_(script|style)\b/, 'CMS asset enqueue'],
        [/\bget_post_meta\b|\bupdate_post_meta\b/, 'CMS post-meta persistence'],
        [/\badd_menu_page\b|\badd_submenu_page\b/, 'CMS admin screen'],
        [/\bwp-json\b/, 'CMS REST route'],
    ];
    let clean = true;
    for (const file of files) {
        if (file.startsWith('.github') || file.endsWith('.md')) {
            continue;
        }
        const content = read(file);
        for (const [pattern, label] of markers) {
            if (pattern.test(content)) {
                fail(`${file}: ${label}. ADR-034 moved CMS surfaces out of this repository.`);
                clean = false;
            }
        }
    }
    if (clean) {
        pass('No WordPress templates, shortcodes, admin screens or CPT persistence (ADR-034)');
    }
}

/* ---- 3. no browser capture code ---- */
{
    const markers = [/\bMediaRecorder\b/, /getUserMedia/, /\bnavigator\.mediaDevices\b/];
    let clean = true;
    for (const file of sourceFiles) {
        const content = read(file);
        for (const pattern of markers) {
            if (pattern.test(content)) {
                fail(
                    `${file}: browser capture API. The microphone and the upload client belong to the capture UI package (ADR-034).`,
                );
                clean = false;
            }
        }
    }
    if (clean) {
        pass('No browser capture code — the microphone is the capture UI package’s (ADR-034)');
    }
}

/* ---- 4. tools are spawned, never linked ---- */
{
    const pkg = JSON.parse(read('package.json'));
    const deps = { ...(pkg.dependencies ?? {}), ...(pkg.devDependencies ?? {}) };
    const nativeDeps = ['bindings', 'node-gyp', 'ffi-napi', 'node-addon-api', 'nan'];
    const found = nativeDeps.filter((name) => name in deps);
    let clean = true;
    if (found.length > 0) {
        fail(
            `Native binding dependencies present (${found.join(', ')}). ADR-038 runs analysis tools as separate processes, never linked — and Praat is GPL, so the process boundary is what keeps this service’s licence intact.`,
        );
        clean = false;
    }
    for (const file of sourceFiles) {
        if (/require\(['"][^'"]+\.node['"]\)|from\s+['"][^'"]+\.node['"]/.test(read(file))) {
            fail(`${file}: loads a native addon. Tools are spawned, never linked (ADR-038).`);
            clean = false;
        }
    }
    if (clean) {
        pass('Tools are spawned, never linked (ADR-038)');
    }
}

/* ---- 5. every pinned tool is versioned and licence-attested ---- */
{
    const pinPath = 'tools.pinned.json';
    if (!fs.existsSync(path.join(ROOT, pinPath))) {
        fail(`${pinPath} is missing. The analysis worker does not run unpinned (ADR-038).`);
    } else {
        const toolset = JSON.parse(read(pinPath));
        let clean = true;
        const names = new Set();
        for (const tool of toolset.tools ?? []) {
            names.add(tool.name);
            if (typeof tool.pinnedVersion !== 'string' || tool.pinnedVersion.trim() === '') {
                fail(`${pinPath}: '${tool.name}' has no pinned version.`);
                clean = false;
            }
            const attestation = tool.licenseAttestation ?? {};
            if (!attestation.license || !attestation.readFrom) {
                fail(
                    `${pinPath}: '${tool.name}' has no licence attestation naming what was read and where from (ADR-038).`,
                );
                clean = false;
            }
        }
        for (const [jobClass, pins] of Object.entries(toolset.jobClassPins ?? {})) {
            if (pins.length === 0) {
                fail(`${pinPath}: job class '${jobClass}' pins no tools.`);
                clean = false;
            }
            for (const pin of pins) {
                if (!names.has(pin)) {
                    fail(`${pinPath}: job class '${jobClass}' pins unknown tool '${pin}'.`);
                    clean = false;
                }
            }
        }
        if (clean) {
            pass('Every pinned tool carries a version and a licence attestation (ADR-038)');
        }
    }
}

/* ---- 6. no durable storage URLs modelled ---- */
{
    const banned = /\b(storageUrl|downloadUrl|publicUrl|permanentUrl|assetUrl|fileUrl)\b/;
    let clean = true;
    for (const file of sourceFiles) {
        if (banned.test(read(file))) {
            fail(
                `${file}: models a durable storage URL. ADR-038 references assets by id; short-lived URLs are requested on demand and never stored.`,
            );
            clean = false;
        }
    }
    if (clean) {
        pass('No durable storage URL is modelled (ADR-038)');
    }
}

/* ---- 7. no transcript or translation records ---- */
{
    const declaration = /^\s*(readonly\s+)?(transcript|translation)\??\s*:/m;
    let clean = true;
    for (const file of sourceFiles) {
        if (declaration.test(read(file))) {
            fail(
                `${file}: declares a transcript or translation field. Those are ESU's records; this service owns the asset and its acoustic measurements (ADR-034, ADR-036).`,
            );
            clean = false;
        }
    }
    if (clean) {
        pass("No transcript or translation records — those are ESU's (ADR-034)");
    }
}

/* ---- 8. edit capability only in the release pipeline ---- */
{
    // The words appear in the guards and in prose that explains the rule, so
    // this looks for a callable edit: a function or method whose name says it
    // trims, splices or cuts audio.
    const editFunction =
        /\b(function\s+|const\s+|async\s+)?(trim|splice|cut)(Audio|Recording|Original|Segment)\b/;
    let clean = true;
    for (const file of sourceFiles) {
        if (file.includes(`${path.sep}release${path.sep}`)) {
            continue;
        }
        if (editFunction.test(read(file))) {
            fail(
                `${file}: defines an audio-edit operation outside the release pipeline. ADR-039: the preservation path never edits audio.`,
            );
            clean = false;
        }
    }
    if (clean) {
        pass('Edit capability exists only in the release pipeline (ADR-039)');
    }
}

if (!ok) {
    console.log('\nBoundary checks failed.');
    process.exit(1);
}

console.log('\nBoundary checks passed.');
