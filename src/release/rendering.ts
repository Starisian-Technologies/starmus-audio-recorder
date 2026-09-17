/**
 * Release rendering — the walled-off job class.
 *
 * ADR-039: "A cleaned, spliced, or mastered copy for publication is a
 * production artifact with editorial intent. The Spoken Audio Node renders it
 * as a distinct job class with provenance to its source originals, and its
 * outputs are walled off from the linguistic pipeline: nothing release-rendered
 * ever feeds transcription, alignment, or the corpus."
 *
 * This module is where trimming and splicing are *allowed*, and it is the only
 * one. Two things make the wall real rather than aspirational:
 *
 *   1. Outputs are `publication` renditions, and `domain/renditions.ts` refuses
 *      that rung to every linguistic consumer at the point of consumption.
 *   2. A release is described by an edit plan that names its source originals.
 *      The plan is provenance: a publication artifact always knows what it was
 *      made from, and the originals it was made from are untouched.
 *
 * Commissioning and rights for releases sit with the rights module, not with
 * intake or preservation. Nothing here decides whether a release may be made —
 * only how one is rendered once someone with that authority has said so.
 */

import type { AudioAssetId } from '../domain/ids.js';
import type { OriginalTimeRange } from '../domain/timeline.js';

/** One stretch of one original, as it appears in a release. */
export interface ReleaseSegment {
    readonly sourceAssetId: AudioAssetId;
    /** On the source original's timeline — the only one there is. */
    readonly sourceRange: OriginalTimeRange;
}

/**
 * An edit plan.
 *
 * Deliberately expressive: a release may cut, reorder and join. That is what a
 * release is. The expressiveness is safe because a plan can only be executed by
 * {@link renderRelease}, which produces a `publication` rendition, and because
 * nothing in the preservation path accepts a plan at all.
 */
export interface EditPlan {
    readonly segments: readonly ReleaseSegment[];
    /** Who commissioned this, recorded with the artifact. */
    readonly commissionedBy: string;
    /** The editorial intent, in the commissioner's words. */
    readonly intent: string;
}

export interface ReleaseProvenance {
    readonly sourceAssetIds: readonly AudioAssetId[];
    readonly commissionedBy: string;
    readonly intent: string;
    readonly renderedAt: string;
    /**
     * Always true, and recorded rather than assumed. A consumer reading a
     * publication artifact sees in the artifact itself that it is not evidence
     * and not an analysis input.
     */
    readonly walledOffFromLinguisticPipeline: true;
}

export class ReleaseError extends Error {
    public constructor(message: string) {
        super(message);
        this.name = 'ReleaseError';
    }
}

/**
 * Validate an edit plan.
 *
 * The checks are about provenance rather than taste: a release must name its
 * sources, and every segment must lie on a source original's timeline, because
 * the artifact's whole claim to legitimacy is that it can be traced back to
 * recordings that still exist unchanged.
 */
export function validateEditPlan(plan: EditPlan): void {
    if (plan.segments.length === 0) {
        throw new ReleaseError('A release edit plan must name at least one source segment.');
    }
    if (plan.commissionedBy.trim() === '') {
        throw new ReleaseError(
            'A release must record who commissioned it. Commissioning and rights sit with the rights module (ADR-039).',
        );
    }
    if (plan.intent.trim() === '') {
        throw new ReleaseError(
            'A release must record its editorial intent. That intent is exactly what distinguishes it from a preservation rendition.',
        );
    }
    for (const segment of plan.segments) {
        if (segment.sourceRange.endMs <= segment.sourceRange.startMs) {
            throw new ReleaseError(
                `Release segment from asset ${segment.sourceAssetId} is empty or runs backwards.`,
            );
        }
    }
}

/** Provenance for the artifact a plan produces. */
export function releaseProvenance(plan: EditPlan, renderedAt: string): ReleaseProvenance {
    validateEditPlan(plan);
    return {
        sourceAssetIds: [...new Set(plan.segments.map((segment) => segment.sourceAssetId))],
        commissionedBy: plan.commissionedBy,
        intent: plan.intent,
        renderedAt,
        walledOffFromLinguisticPipeline: true,
    };
}

/**
 * Refuse a release artifact as input to anything linguistic.
 *
 * Belt and braces alongside the rendition-ladder check: a caller that has a
 * release artifact in hand and reaches for it as a transcription source gets
 * told here, by name, rather than discovering it downstream when a corpus
 * already contains an edited recording.
 */
export function assertNotForLinguisticUse(provenance: ReleaseProvenance, consumer: string): never {
    throw new ReleaseError(
        `${consumer} may not consume a release rendering (commissioned by ${provenance.commissionedBy}, intent: ${provenance.intent}). ` +
            'ADR-039: nothing release-rendered ever feeds transcription, alignment, or the corpus. ' +
            'Use the original or the standard analysis derivative of the source assets.',
    );
}
