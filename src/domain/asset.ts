/**
 * The registered audio asset.
 *
 * An asset is an original plus everything derived from and measured about it.
 * The original is immutable evidence and the timeline authority (ADR-039); the
 * record below is append-only in the same spirit — renditions and measurements
 * accumulate, and nothing already registered is replaced.
 *
 * Note what an asset does **not** carry: a storage URL. ADR-038 keeps durable
 * storage URLs out of records entirely. An asset holds a `storageRef`, which is
 * an opaque handle the media transport understands and nobody else needs to.
 */

import type { AudioAssetId, DerivativeId } from './ids.js';
import type { CaptureProfileName } from './captureProfile.js';
import type { TransformationManifest } from './manifest.js';
import type { Measurement } from './measurement.js';
import type { QualityFlag, ProcessingRoute } from './quality.js';
import type { RenditionKind } from './renditions.js';
import type { VadSegment } from './segments.js';

/**
 * An opaque handle to stored bytes.
 *
 * The media transport service resolves it; this service never parses it, never
 * stores a URL alongside it, and never hands it to a consumer in place of an
 * id. Short-lived access URLs are requested on demand, from the transport,
 * under this service's authorization.
 */
export interface StorageRef {
    readonly kind: 'opaque-storage-ref';
    readonly value: string;
}

/** Build a storage ref, refusing a URL smuggled in as one. */
export function storageRef(value: string): StorageRef {
    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(value)) {
        throw new TypeError(
            'A storage ref must not be a URL (ADR-038). Ask the media transport for a short-lived URL when bytes are needed.',
        );
    }
    if (value.trim() === '') {
        throw new TypeError('A storage ref must not be empty.');
    }
    return { kind: 'opaque-storage-ref', value };
}

/** What the container and stream actually turned out to be, measured at intake. */
export interface SourceFormat {
    readonly container: string | null;
    readonly codec: string | null;
    readonly sampleRateHz: number | null;
    readonly bitDepth: number | null;
    readonly channels: number | null;
    readonly durationMs: number | null;
}

/** One rung of the ladder, as actually produced. */
export interface Rendition {
    readonly id: DerivativeId;
    readonly kind: RenditionKind;
    readonly storage: StorageRef;
    readonly sha256: string;
    readonly format: SourceFormat;
    /** Null only for the original, which is not derived from anything. */
    readonly manifest: TransformationManifest | null;
}

/** Whether the bytes received matched the checksum that accompanied them. */
export type IntegrityState = 'verified' | 'failed' | 'unverified';

export interface AudioAsset {
    readonly id: AudioAssetId;
    readonly original: Rendition;
    readonly derivatives: readonly Rendition[];
    /** Null when the asset arrived without one — stored, and flagged, never rejected. */
    readonly captureProfile: CaptureProfileName | null;
    readonly integrity: IntegrityState;
    readonly qualityFlags: readonly QualityFlag[];
    readonly route: ProcessingRoute;
    readonly measurements: readonly Measurement[];
    readonly segments: readonly VadSegment[];
    readonly registeredAt: string;
}

/** Find a rendition by rung. Returns the first, which is the only one per rung. */
export function renditionOfKind(asset: AudioAsset, kind: RenditionKind): Rendition | undefined {
    if (kind === 'original') {
        return asset.original;
    }
    return asset.derivatives.find((rendition) => rendition.kind === kind);
}

/**
 * The rendition analysis should consume.
 *
 * The standard analysis derivative when one exists, otherwise the original.
 * Never the enhanced derivative, and never a playback or publication rendition
 * — `renditions.ts` refuses those at the point of consumption, and this
 * function never offers them in the first place.
 */
export function analysisSource(asset: AudioAsset): Rendition {
    return renditionOfKind(asset, 'analysis-standard') ?? asset.original;
}
