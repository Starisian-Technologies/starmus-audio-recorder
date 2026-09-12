#!/usr/bin/env node
'use strict';

/**
 * Run the compiled test files under `dist/`.
 *
 * This exists because `node --test` reads its arguments differently across the
 * versions this repository has to work on. Node 20 — the platform's LTS floor —
 * treats an argument as a path and fails on a glob pattern; later versions
 * expand the glob but take a bare directory as a single entry. Passing explicit
 * file paths is the one form every version agrees on, so the walk happens here
 * rather than in a shell glob that works on the machine it was written on.
 */

const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');
const DIST = path.join(ROOT, 'dist');

if (!fs.existsSync(DIST)) {
    console.error('No dist/ directory. Run the build first.');
    process.exit(1);
}

/**
 * @param {string} dir
 * @param {string[]} out
 * @returns {string[]}
 */
function collect(dir, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            collect(full, out);
        } else if (entry.isFile() && entry.name.endsWith('.test.js')) {
            out.push(path.relative(ROOT, full));
        }
    }
    return out;
}

const files = collect(DIST).sort();

if (files.length === 0) {
    console.error(
        'No compiled test files found under dist/. The build emits them from src/**/*.test.ts; ' +
            'an empty set means the tests did not compile, which is a failure rather than a pass.',
    );
    process.exit(1);
}

console.log(`Running ${files.length} test file(s):`);
for (const file of files) {
    console.log(`  ${file}`);
}
console.log('');

const result = spawnSync(process.execPath, ['--test', ...files], {
    cwd: ROOT,
    stdio: 'inherit',
});

process.exit(result.status === null ? 1 : result.status);
