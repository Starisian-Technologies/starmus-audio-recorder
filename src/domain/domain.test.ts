/**
 * Domain rules. These are the ADR invariants, tested without a filesystem.
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import { analysisSource, storageRef } from './asset.js';
import type { AudioAsset, Rendition } from './asset.js';
import { admissibility, admissibilityFloor, isCaptureProfileName } from './captureProfile.js';
import { audioAssetId, newAudioAssetId, newDerivativeId } from './ids.js';
import { manifestFingerprint, transformationManifest } from './manifest.js';
import { measurement, reliabilityOf } from './measurement.js';
import { assess, keepsMaterial } from './quality.js';
import {
    assertMayFeedLinguisticPipeline,
    assertProducibleInPreservationPath,
    isEvidence,
} from './renditions.js';
import {
    candidatesFromSegments,
    longGaps,
    speechRatio,
    withExplicitSilence,
} from './segments.js';
import type { VadSegment } from './segments.js';
import { originalTimeRange } from './timeline.js';

const HASH_A = 'a'.repeat(64);
const HASH_B = 'b'.repeat(64);

/* ---- identifiers ---- */

void test('an asset id refuses a URL smuggled in as an identifier', () => {
    assert.throws(() => audioAssetId('https://files.example/audio/1.wav'), /identifier, not a locator/);
    assert.throws(() => audioAssetId('bucket/key.wav'), /identifier, not a locator/);
});

void test('a storage ref refuses a URL', () => {
    assert.throws(() => storageRef('https://files.example/x'), /must not be a URL/);
    assert.doesNotThrow(() => storageRef('opaque-handle-123'));
});

/* ---- rendition ladder ---- */

void test('only the original and the standard derivative are evidence', () => {
    assert.equal(isEvidence('original'), true);
    assert.equal(isEvidence('analysis-standard'), true);
    assert.equal(isEvidence('analysis-enhanced'), false);
    assert.equal(isEvidence('playback'), false);
    assert.equal(isEvidence('publication'), false);
});

void test('a denoised derivative may not feed the linguistic pipeline', () => {
    assert.throws(
        () => assertMayFeedLinguisticPipeline('analysis-enhanced', 'transcription'),
        /enhancement discards signal/,
    );
});

void test('a release rendering may not feed the linguistic pipeline', () => {
    assert.throws(
        () => assertMayFeedLinguisticPipeline('publication', 'alignment'),
        /walled off from the linguistic pipeline/,
    );
});

void test('release rendering is not produced in the preservation path', () => {
    assert.throws(() => assertProducibleInPreservationPath('publication'), /release-rendering job class/);
    assert.doesNotThrow(() => assertProducibleInPreservationPath('analysis-standard'));
});

/* ---- manifests ---- */

void test('a manifest with only reversible steps is deterministic', () => {
    const manifest = transformationManifest({
        sourceSha256: HASH_A,
        outputSha256: HASH_B,
        steps: [{ step: 'decode', parameters: { to: 'pcm_s16le' } }],
        tools: [{ name: 'ffmpeg', version: '6.1.1' }],
        producedAt: '2026-09-12T00:00:00.000Z',
    });
    assert.equal(manifest.deterministic, true);
    assert.equal(manifest.timelinePreserving, true);
});

void test('a denoise step makes a manifest non-deterministic, and the caller cannot say otherwise', () => {
    const manifest = transformationManifest({
        sourceSha256: HASH_A,
        outputSha256: HASH_B,
        steps: [{ step: 'denoise', parameters: { strength: 0.5 } }],
        tools: [{ name: 'ffmpeg', version: '6.1.1' }],
        producedAt: '2026-09-12T00:00:00.000Z',
    });
    assert.equal(manifest.deterministic, false);
});

void test('a manifest refuses a step that is a content edit', () => {
    assert.throws(
        () =>
            transformationManifest({
                sourceSha256: HASH_A,
                outputSha256: HASH_B,
                // A trim is not on the permitted list; the cast is what a
                // caller reaching for one would have to write.
                steps: [{ step: 'trim' as never, parameters: { fromMs: 0 } }],
                tools: [{ name: 'ffmpeg', version: '6.1.1' }],
                producedAt: '2026-09-12T00:00:00.000Z',
            }),
        /Content edits — trim, splice, cut — do not exist in this path/,
    );
});

void test('a manifest refuses a tool with no version', () => {
    assert.throws(
        () =>
            transformationManifest({
                sourceSha256: HASH_A,
                outputSha256: HASH_B,
                steps: [{ step: 'decode', parameters: { to: 'pcm_s16le' } }],
                tools: [{ name: 'ffmpeg', version: '  ' }],
                producedAt: '2026-09-12T00:00:00.000Z',
            }),
        /records the version it reported/,
    );
});

void test('the fingerprint ignores when the work happened, not what it was', () => {
    const base = {
        sourceSha256: HASH_A,
        outputSha256: HASH_B,
        steps: [{ step: 'decode' as const, parameters: { to: 'pcm_s16le' } }],
        tools: [{ name: 'ffmpeg', version: '6.1.1' }],
    };
    const monday = transformationManifest({ ...base, producedAt: '2026-09-12T00:00:00.000Z' });
    const friday = transformationManifest({ ...base, producedAt: '2027-01-01T00:00:00.000Z' });
    const other = transformationManifest({
        ...base,
        steps: [{ step: 'resample', parameters: { toHz: 48000 } }],
        producedAt: '2026-09-12T00:00:00.000Z',
    });

    assert.equal(manifestFingerprint(monday), manifestFingerprint(friday));
    assert.notEqual(manifestFingerprint(monday), manifestFingerprint(other));
});

/* ---- measurements ---- */

const RANGE = originalTimeRange(0, 2000);

const PROVENANCE = {
    tool: { name: 'praat', version: '6.4.06' },
    script: 'praat/measure.praat',
    scriptSha256: HASH_A,
    parameters: { paramPitchFloor: 75 },
    measuredSha256: HASH_B,
    measuredRendition: 'analysis-standard' as const,
};

void test('a measurement without extraction parameters is refused', () => {
    assert.throws(
        () =>
            measurement({
                measurementClass: 'pitch-f0',
                value: 220,
                unit: 'Hz',
                range: RANGE,
                conditions: { signalToNoiseDb: 40, hasVoicedSpeech: true },
                provenance: { ...PROVENANCE, parameters: {} },
            }),
        /not reproducible/,
    );
});

void test('a measurement without a tool version is refused', () => {
    assert.throws(
        () =>
            measurement({
                measurementClass: 'pitch-f0',
                value: 220,
                unit: 'Hz',
                range: RANGE,
                conditions: { signalToNoiseDb: 40, hasVoicedSpeech: true },
                provenance: { ...PROVENANCE, tool: { name: 'praat', version: '' } },
            }),
        /not reproducible/,
    );
});

void test('perturbation measures are reported but never certified while their floor is unruled', () => {
    for (const measurementClass of ['jitter', 'shimmer', 'harmonicity'] as const) {
        const verdict = reliabilityOf(measurementClass, {
            signalToNoiseDb: 60,
            hasVoicedSpeech: true,
        });
        assert.equal(verdict.reliable, false, `${measurementClass} should not be certified`);
        assert.match(
            verdict.reliable ? '' : verdict.reason,
            /has not been ruled on/,
            `${measurementClass} should say why`,
        );
    }
});

void test('a class needing voiced speech is marked unreliable when there is none', () => {
    const verdict = reliabilityOf('pitch-f0', { signalToNoiseDb: 60, hasVoicedSpeech: false });
    assert.equal(verdict.reliable, false);
});

void test('a null value survives as null — it is never turned into a number', () => {
    const result = measurement({
        measurementClass: 'pitch-f0',
        value: null,
        unit: 'Hz',
        range: RANGE,
        conditions: { signalToNoiseDb: null, hasVoicedSpeech: false },
        provenance: PROVENANCE,
    });
    assert.equal(result.value, null);
});

/* ---- capture profiles ---- */

void test('every profile is recognised and none carries an invented floor', () => {
    for (const name of ['conversation', 'documentation', 'import'] as const) {
        assert.equal(isCaptureProfileName(name), true);
        const floor = admissibilityFloor(name);
        assert.equal(floor.minSampleRateHz, null, `${name} must not carry an invented sample rate`);
        assert.equal(floor.permittedCodecs, null, `${name} must not carry an invented codec set`);
    }
});

void test('an unruled profile reports ungated rather than passing material', () => {
    const verdict = admissibility('documentation', {
        sampleRateHz: 8000,
        bitDepth: 16,
        channels: 1,
        codec: 'opus',
    });
    assert.equal(verdict.gated, false);
    assert.equal(verdict.admissible, false, 'ungated is not a pass');
    assert.match(verdict.notes.join(' '), /OQ-021 is open/);
});

/* ---- quality ---- */

void test('an integrity failure quarantines, and quarantine still keeps the material', () => {
    const assessment = assess(['integrity-unverified', 'clipping']);
    assert.equal(assessment.route, 'quarantine');
    assert.equal(keepsMaterial(assessment.route), true);
});

void test('a listening problem routes to review rather than quarantine', () => {
    assert.equal(assess(['low-signal-to-noise']).route, 'review-before-analysis');
    assert.equal(assess([]).route, 'standard');
});

void test('every route keeps the material', () => {
    for (const route of ['standard', 'review-before-analysis', 'quarantine'] as const) {
        assert.equal(keepsMaterial(route), true);
    }
});

/* ---- segments ---- */

const SPEECH: VadSegment[] = [
    { kind: 'speech', range: originalTimeRange(0, 1000), confidence: null },
    { kind: 'speech', range: originalTimeRange(1800, 2800), confidence: null },
];

void test('gaps between speech become explicit silence, never holes', () => {
    const filled = withExplicitSilence(SPEECH, 3200, (start, end) => originalTimeRange(start, end));
    assert.deepEqual(
        filled.map((segment) => [segment.kind, segment.range.startMs, segment.range.endMs]),
        [
            ['speech', 0, 1000],
            ['silence', 1000, 1800],
            ['speech', 1800, 2800],
            ['silence', 2800, 3200],
        ],
    );
});

void test('speech ratio describes the signal', () => {
    const filled = withExplicitSilence(SPEECH, 4000, (start, end) => originalTimeRange(start, end));
    assert.equal(speechRatio(filled, 4000), 0.5);
});

void test('long gaps are found without being removed', () => {
    const filled = withExplicitSilence(SPEECH, 3200, (start, end) => originalTimeRange(start, end));
    const gaps = longGaps(filled, 500);
    assert.equal(gaps.length, 1);
    assert.equal(gaps[0]?.range.startMs, 1000);
    assert.equal(filled.length, 4, 'finding a gap must not drop it from the record');
});

void test('candidates include silence, so they cannot be read as a cut list', () => {
    const filled = withExplicitSilence(SPEECH, 3200, (start, end) => originalTimeRange(start, end));
    const candidates = candidatesFromSegments(filled);
    assert.equal(candidates.length, filled.length);
    assert.ok(candidates.some((candidate) => candidate.label === 'silence'));
    for (const candidate of candidates) {
        assert.equal(candidate.authority, 'machine-candidate');
    }
});

/* ---- asset ---- */

function asset(derivatives: readonly Rendition[]): AudioAsset {
    return {
        id: newAudioAssetId(),
        original: {
            id: newDerivativeId(),
            kind: 'original',
            storage: storageRef('opaque-original'),
            sha256: HASH_A,
            format: {
                container: 'wav',
                codec: 'pcm_s16le',
                sampleRateHz: 44100,
                bitDepth: 16,
                channels: 2,
                durationMs: 2000,
            },
            manifest: null,
        },
        derivatives,
        captureProfile: 'documentation',
        integrity: 'verified',
        qualityFlags: [],
        route: 'standard',
        measurements: [],
        segments: [],
        registeredAt: '2026-09-12T00:00:00.000Z',
    };
}

void test('analysis reads the standard derivative when there is one, the original otherwise', () => {
    const bare = asset([]);
    assert.equal(analysisSource(bare).kind, 'original');

    const enhanced: Rendition = {
        id: newDerivativeId(),
        kind: 'analysis-enhanced',
        storage: storageRef('opaque-enhanced'),
        sha256: HASH_B,
        format: bare.original.format,
        manifest: null,
    };
    assert.equal(
        analysisSource(asset([enhanced])).kind,
        'original',
        'an enhanced derivative is never offered to analysis',
    );

    const standard: Rendition = { ...enhanced, kind: 'analysis-standard' };
    assert.equal(analysisSource(asset([enhanced, standard])).kind, 'analysis-standard');
});
