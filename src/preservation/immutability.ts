/**
 * The preservation guarantees, as executable guards.
 *
 * ADR-039 forbids, in the preservation path:
 *
 *   - any modification or deletion of a registered original;
 *   - any content-editorial rendition — splice, trim, cut, "effective
 *     audio"/EDL;
 *   - any release-pipeline output entering transcription, alignment, or the
 *     corpus;
 *   - pitch-corrected audio feeding transcription, phonetic analysis,
 *     pronunciation evaluation, or model training;
 *   - enhanced or denoised derivatives standing as evidence;
 *   - timestamps referencing anything but the original timeline.
 *
 * A rule that lives only in a document is a rule someone will refactor past.
 * These are the ones that can fail a call.
 */

import type { AudioAsset, Rendition } from '../domain/asset.js';
import { assertMayFeedLinguisticPipeline, isEvidence } from '../domain/renditions.js';
import type { OriginalTimeRange } from '../domain/timeline.js';
import { isWithinRecording } from '../domain/timeline.js';

/** Raised when something tries to change or remove a registered original. */
export class PreservationViolation extends Error {
    public constructor(message: string) {
        super(message);
        this.name = 'PreservationViolation';
    }
}

/**
 * Refuse any write to a registered original.
 *
 * Call this from every code path that could open the original for writing. The
 * first-generation pipeline wrote ID3 tags into the uploaded file in place;
 * that is the concrete failure this guard exists to make impossible rather than
 * merely discouraged.
 */
export function assertOriginalNotMutated(asset: AudioAsset, intent: string): never {
    throw new PreservationViolation(
        `Refusing to ${intent} the registered original of asset ${asset.id}. ` +
            'ADR-039: the original is immutable evidence and the timeline authority. ' +
            'Metadata belongs on the asset record; a changed rendering belongs on a new rung of the ladder.',
    );
}

/** Refuse deletion of a registered original. */
export function assertOriginalNotDeleted(asset: AudioAsset): never {
    throw new PreservationViolation(
        `Refusing to delete the registered original of asset ${asset.id}. ` +
            'ADR-039 and the ESU no-deletion invariant both hold: material that cannot be widely ' +
            'heard stays intact under access control, and redaction restricts access rather than cutting.',
    );
}

/**
 * Refuse a rendition as evidence when its rung does not permit it.
 *
 * The failure mode is quiet: an enhanced derivative is easier to listen to, so
 * it drifts into the place the original should hold.
 */
export function assertUsableAsEvidence(rendition: Rendition): void {
    if (!isEvidence(rendition.kind)) {
        throw new PreservationViolation(
            `A '${rendition.kind}' rendition may not stand as evidence (ADR-038). ` +
                'Use the original or the standard analysis derivative.',
        );
    }
}

/**
 * Refuse a rendition as input to anything linguistic.
 *
 * Named consumers so the error says which pipeline stage was about to read the
 * wrong bytes.
 */
export function assertUsableForAnalysis(rendition: Rendition, consumer: string): void {
    assertMayFeedLinguisticPipeline(rendition.kind, consumer);
}

/**
 * Refuse a timestamp that cannot belong to this asset.
 *
 * The check is cheap and catches the expensive mistake: a result computed
 * against a different file, or against a derivative whose duration drifted,
 * landing in the record as if it described this recording.
 */
export function assertOnOriginalTimeline(asset: AudioAsset, range: OriginalTimeRange): void {
    const duration = asset.original.format.durationMs;
    if (duration === null) {
        // Duration was not measurable, so the range cannot be checked against
        // it. Refusing here would discard a real result over a missing field;
        // the range is still an original-timeline offset by construction.
        return;
    }
    if (!isWithinRecording(range, duration)) {
        throw new PreservationViolation(
            `Range ${range.startMs}–${range.endMs}ms lies outside asset ${asset.id}, whose original runs ${duration}ms. ` +
                "ADR-039: every result maps to the original's timeline, and there is only one.",
        );
    }
}

/**
 * Words that name a content edit, for guarding job definitions.
 *
 * A job class is data, and a release job can be commissioned through the same
 * API as a preservation job. This is the check that stops the two being
 * confused where it matters — before the job runs.
 */
const EDIT_INTENTS = ['trim', 'splice', 'cut', 'edl', 'effective-audio', 'pitch-correct'];

/**
 * Refuse a preservation-path job whose stated intent is a content edit.
 *
 * The release pipeline does these things legitimately. This guard is about
 * where they are allowed to happen, not whether they may happen at all.
 */
export function assertNotAnEditIntent(intent: string, context: string): void {
    const normalized = intent.toLowerCase();
    const match = EDIT_INTENTS.find((word) => normalized.includes(word));
    if (match !== undefined) {
        throw new PreservationViolation(
            `'${intent}' names a content edit ('${match}') and ${context} is in the preservation path. ` +
                'ADR-039: no trim, splice or cut capability exists here. Release rendering is a separate, walled-off job class.',
        );
    }
}
