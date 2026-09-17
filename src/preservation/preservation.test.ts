/**
 * The preservation guarantees and the release wall.
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import { storageRef } from '../domain/asset.js';
import type { AudioAsset } from '../domain/asset.js';
import { newAudioAssetId, newDerivativeId } from '../domain/ids.js';
import { originalTimeRange } from '../domain/timeline.js';
import { releaseProvenance, validateEditPlan, assertNotForLinguisticUse } from '../release/rendering.js';
import {
    assertNotAnEditIntent,
    assertOnOriginalTimeline,
    assertOriginalNotDeleted,
    assertOriginalNotMutated,
    assertUsableAsEvidence,
    assertUsableForAnalysis,
} from './immutability.js';

const HASH = 'c'.repeat(64);

const asset: AudioAsset = {
    id: newAudioAssetId(),
    original: {
        id: newDerivativeId(),
        kind: 'original',
        storage: storageRef('opaque-original'),
        sha256: HASH,
        format: {
            container: 'wav',
            codec: 'pcm_s16le',
            sampleRateHz: 48000,
            bitDepth: 24,
            channels: 1,
            durationMs: 5000,
        },
        manifest: null,
    },
    derivatives: [],
    captureProfile: 'documentation',
    integrity: 'verified',
    qualityFlags: [],
    route: 'standard',
    measurements: [],
    segments: [],
    registeredAt: '2026-09-12T00:00:00.000Z',
};

void test('writing to a registered original is refused, whatever the intent', () => {
    assert.throws(
        () => assertOriginalNotMutated(asset, 'write ID3 tags into'),
        /immutable evidence and the timeline authority/,
    );
});

void test('deleting a registered original is refused', () => {
    assert.throws(() => assertOriginalNotDeleted(asset), /redaction restricts access rather than cutting/);
});

void test('an enhanced derivative is neither evidence nor analysis input', () => {
    const enhanced = { ...asset.original, kind: 'analysis-enhanced' as const };
    assert.throws(() => assertUsableAsEvidence(enhanced), /may not stand as evidence/);
    assert.throws(
        () => assertUsableForAnalysis(enhanced, 'transcription'),
        /enhancement discards signal/,
    );
});

void test('a timestamp outside the recording is refused', () => {
    assert.doesNotThrow(() => assertOnOriginalTimeline(asset, originalTimeRange(0, 5000)));
    assert.throws(
        () => assertOnOriginalTimeline(asset, originalTimeRange(0, 9000)),
        /lies outside asset/,
    );
});

void test('an edit intent is refused in the preservation path', () => {
    for (const intent of ['trim dead air', 'splice takes', 'apply EDL', 'pitch-correct the vowel']) {
        assert.throws(
            () => assertNotAnEditIntent(intent, 'the analysis worker'),
            /no trim, splice or cut capability exists here/,
            `'${intent}' should be refused`,
        );
    }
    assert.doesNotThrow(() => assertNotAnEditIntent('resample to 48 kHz', 'the derivative job'));
});

void test('a release must name its sources, its commissioner and its intent', () => {
    const segments = [
        { sourceAssetId: asset.id, sourceRange: originalTimeRange(1000, 2000) },
    ];
    assert.throws(
        () => validateEditPlan({ segments: [], commissionedBy: 'rights', intent: 'broadcast' }),
        /at least one source segment/,
    );
    assert.throws(
        () => validateEditPlan({ segments, commissionedBy: '', intent: 'broadcast' }),
        /who commissioned it/,
    );
    assert.throws(
        () => validateEditPlan({ segments, commissionedBy: 'rights', intent: '' }),
        /editorial intent/,
    );
    assert.doesNotThrow(() =>
        validateEditPlan({ segments, commissionedBy: 'rights', intent: 'broadcast' }),
    );
});

void test('a release artifact records that it is walled off, and refuses linguistic use', () => {
    const provenance = releaseProvenance(
        {
            segments: [{ sourceAssetId: asset.id, sourceRange: originalTimeRange(0, 1000) }],
            commissionedBy: 'rights module',
            intent: 'radio broadcast',
        },
        '2026-09-12T00:00:00.000Z',
    );

    assert.equal(provenance.walledOffFromLinguisticPipeline, true);
    assert.deepEqual(provenance.sourceAssetIds, [asset.id]);
    assert.throws(
        () => assertNotForLinguisticUse(provenance, 'the corpus builder'),
        /ever feeds transcription, alignment, or the corpus/,
    );
});
