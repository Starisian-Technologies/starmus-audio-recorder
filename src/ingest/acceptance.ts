/**
 * Ingest acceptance and integrity.
 *
 * The one rule that governs everything here is ADR-011's: **deny nothing,
 * quarantine instead.** Every path through this module ends with the material
 * kept. A checksum that does not verify, a missing capture profile, a container
 * that will not parse — each is recorded as a flag on the asset, and none is
 * grounds to discard a contributor's recording. The capture-to-ingestion
 * contract says the same thing in its own words: "the speaker does not lose
 * their recording because a byte range disagreed."
 *
 * The second rule is that the original arrives once and is never re-sent. The
 * capture UI uploads at source quality; nothing here transcodes on the
 * ingestion path, and the received bytes are the immutable original.
 */

import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';

import type { AudioAsset, Rendition, SourceFormat, StorageRef } from '../domain/asset.js';
import type { CaptureProfileName } from '../domain/captureProfile.js';
import { isCaptureProfileName } from '../domain/captureProfile.js';
import type { AudioAssetId } from '../domain/ids.js';
import { newDerivativeId } from '../domain/ids.js';
import type { QualityFlag } from '../domain/quality.js';
import { assess } from '../domain/quality.js';
import type { IntegrityState } from '../domain/asset.js';

/** SHA-256 of a file, streamed so a long recording does not have to fit in memory. */
export async function sha256OfFile(path: string): Promise<string> {
    const hash = createHash('sha256');
    for await (const chunk of createReadStream(path)) {
        hash.update(chunk as Buffer);
    }
    return hash.digest('hex');
}

/** What arrives with an upload, as the capture UI sends it. */
export interface IncomingUpload {
    readonly assetId: AudioAssetId;
    readonly localPath: string;
    readonly storage: StorageRef;
    /**
     * The checksum the producer sent. `sha256` is what the capture UI sends
     * today; the contract still owes a confirmation that the consumer verifies
     * the same algorithm, so the field names the algorithm rather than assuming.
     */
    readonly declaredChecksum: { readonly algorithm: string; readonly value: string } | null;
    /** The capture profile, when the producer sent one. */
    readonly captureProfile: string | null;
    readonly format: SourceFormat;
}

export interface AcceptanceResult {
    readonly asset: AudioAsset;
    /** True when the profile was absent — stored, and flagged, never rejected. */
    readonly profileMissing: boolean;
}

/**
 * Verify integrity without rejecting.
 *
 * Returns the state rather than throwing. Throwing here would put the decision
 * to keep or discard in a `catch` block, where it is one refactor away from
 * becoming a discard.
 */
export async function verifyIntegrity(upload: IncomingUpload): Promise<{
    state: IntegrityState;
    computedSha256: string;
}> {
    const computedSha256 = await sha256OfFile(upload.localPath);

    if (upload.declaredChecksum === null) {
        return { state: 'unverified', computedSha256 };
    }
    if (upload.declaredChecksum.algorithm.toLowerCase() !== 'sha256') {
        // A checksum this service cannot verify is not a failed checksum. It is
        // an unverified one, and the difference decides whether the material is
        // quarantined as damaged or merely marked as unchecked.
        return { state: 'unverified', computedSha256 };
    }
    return {
        state:
            upload.declaredChecksum.value.toLowerCase() === computedSha256 ? 'verified' : 'failed',
        computedSha256,
    };
}

/**
 * Accept an upload and register the original.
 *
 * No transcode happens on this path. The received bytes become the original
 * rendition exactly as they arrived, and every derivative is an additional
 * object hanging off it.
 */
export async function acceptUpload(
    upload: IncomingUpload,
    registeredAt: string,
    extraFlags: readonly QualityFlag[] = [],
): Promise<AcceptanceResult> {
    const { state, computedSha256 } = await verifyIntegrity(upload);

    const flags: QualityFlag[] = [...extraFlags];
    if (state === 'failed') {
        // Persist what was received and mark the asset's integrity as failed;
        // do not treat it as a clean source. The contract spells this out.
        flags.push('integrity-unverified');
    }
    if (state === 'unverified') {
        flags.push('integrity-unverified');
    }

    const captureProfile: CaptureProfileName | null = isCaptureProfileName(upload.captureProfile)
        ? upload.captureProfile
        : null;
    const profileMissing = captureProfile === null;
    if (profileMissing) {
        flags.push('no-capture-profile');
    }
    if (upload.format.durationMs === null) {
        flags.push('corrupt-container');
    }

    const assessment = assess(flags);

    const original: Rendition = {
        id: newDerivativeId(),
        kind: 'original',
        storage: upload.storage,
        sha256: computedSha256,
        format: upload.format,
        manifest: null,
    };

    return {
        asset: {
            id: upload.assetId,
            original,
            derivatives: [],
            captureProfile,
            integrity: state,
            qualityFlags: assessment.flags,
            route: assessment.route,
            measurements: [],
            segments: [],
            registeredAt,
        },
        profileMissing,
    };
}

/**
 * Whether an asset may be used as a source for acoustic measurement.
 *
 * Separate from acceptance on purpose. Acceptance decides whether the material
 * is kept, which is always yes. This decides whether a measurement taken from
 * it can be relied on, which is a different question with a different owner —
 * the floors themselves are OQ-021.
 */
export function admissibleAsMeasurementSource(asset: AudioAsset): {
    admissible: boolean;
    reason: string;
} {
    if (asset.captureProfile === null) {
        return {
            admissible: false,
            reason:
                'The asset arrived with no capture profile, so a reader cannot tell whether a measurement taken from it is admissible. It is stored; it is not a measurement source.',
        };
    }
    if (asset.integrity === 'failed') {
        return {
            admissible: false,
            reason:
                'The received bytes did not verify against the checksum that accompanied them. They are kept, and they are not a clean source.',
        };
    }
    return { admissible: true, reason: '' };
}
