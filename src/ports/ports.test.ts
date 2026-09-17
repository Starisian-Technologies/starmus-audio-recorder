/**
 * @file Tests for the guarantees the port contracts make.
 *
 * These are source-level assertions rather than behavioural ones because the
 * adapters do not exist yet — ADR-038's seam contracts have to bind before they
 * can be written. What can be pinned now is the shape those adapters will be
 * written against, and both assertions here cover a defect that reached review.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Read from the repository root, not relative to this module: the suite runs
// the compiled output under `dist/`, where the TypeScript source does not exist.
// `scripts/run-tests.cjs` sets the working directory to the repository root.
const ports = readFileSync('src/ports/index.ts', 'utf8');

/**
 * Comment prose with its line wrapping and `*` prefixes removed.
 *
 * A requirement stated in a doc comment wraps wherever the line ends, so a
 * literal phrase match against the raw file fails for reasons that have nothing
 * to do with whether the requirement is there.
 */
function prose(text: string): string {
    return text.replace(/^\s*\*/gm, ' ').replace(/\s+/g, ' ');
}

void test('an intake event declares no ordering field', () => {
    // The asset-to-records contract leaves the ordering mechanism unresolved —
    // it was proposed there and withdrawn back to owed. A `sequence` field here
    // would answer an open seam question by implementation, from the repository
    // that is only one of its two parties, and a consumer built against it
    // looks correct right up until ESU chooses differently.
    const event = ports.slice(
        ports.indexOf('export interface IntakeEvent'),
        ports.indexOf('export interface IntakePublisher'),
    );

    assert.doesNotMatch(event, /readonly\s+sequence\s*:/, 'no sequence field');
    assert.doesNotMatch(event, /readonly\s+ordinal\s*:/, 'nor a renamed one');
    assert.doesNotMatch(event, /readonly\s+offset\s*:/, 'nor another spelling of it');

    // What the event can honestly offer instead.
    assert.match(event, /readonly\s+occurredAt\s*:/);
    assert.match(event, /readonly\s+idempotencyKey\s*:/);
});

void test('registering an asset and queueing its first job is one atomic unit', () => {
    // Two separate calls strand an asset permanently when the second fails: the
    // original is accepted and immutable, no work is queued, and nothing
    // retries — with nothing recording that a job was owed, no reconciliation
    // pass can find it.
    const queue = ports.slice(
        ports.indexOf('export interface JobQueue'),
        ports.indexOf('export interface', ports.indexOf('export interface JobQueue') + 10),
    );

    assert.match(queue, /registerAndEnqueue\(/, 'the port offers the combined operation');
    assert.match(
        prose(queue),
        /must throw rather than approximate/i,
        'and requires an adapter that cannot be atomic to fail loudly',
    );

    // And the entry point uses it rather than the two-step it replaced.
    const index = readFileSync('src/index.ts', 'utf8');
    const register = index.slice(index.indexOf('public async registerUpload'));
    const body = register.slice(0, register.indexOf('\n    /**'));

    assert.match(body, /this\.deps\.queue\.registerAndEnqueue\(/, 'the atomic call is used');
    assert.doesNotMatch(
        body,
        /this\.deps\.assets\.register\(/,
        'and the separate register call is gone',
    );
});
