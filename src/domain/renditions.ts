/**
 * The rendition ladder (ADR-038) and the rules that hang off each rung.
 *
 * The ladder is not a naming convention. Which rung a rendition sits on decides
 * three things that the rest of the service reads rather than re-deciding:
 * whether it may stand as evidence, whether it may feed the linguistic
 * pipeline, and whether it may be produced at all inside the preservation path.
 */

/** The five rungs, in the order ADR-038 states them. */
export const RENDITION_KINDS = [
    'original',
    'analysis-standard',
    'analysis-enhanced',
    'playback',
    'publication',
] as const;

export type RenditionKind = (typeof RENDITION_KINDS)[number];

interface RenditionRule {
    /** May this rendition stand as evidence of what was recorded? */
    readonly isEvidence: boolean;
    /**
     * May transcription, alignment, phonetic analysis, pronunciation
     * evaluation or model training consume this rendition?
     */
    readonly feedsLinguisticPipeline: boolean;
    /** Is this produced inside the preservation path, or by a walled-off job? */
    readonly producedInPreservationPath: boolean;
    /** One line on why, for error messages that have to explain themselves. */
    readonly reason: string;
}

const RULES: Readonly<Record<RenditionKind, RenditionRule>> = Object.freeze({
    /** The registered original: evidence, and the timeline authority. */
    original: {
        isEvidence: true,
        feedsLinguisticPipeline: true,
        producedInPreservationPath: true,
        reason: 'the immutable original is the evidence and the timeline authority',
    },

    /**
     * Deterministic, timeline-preserving preparation — decode, channel-
     * preserving extraction, resampling, normalization — carrying its
     * transformation manifest, source hash and tool versions.
     */
    'analysis-standard': {
        isEvidence: true,
        feedsLinguisticPipeline: true,
        producedInPreservationPath: true,
        reason:
            'a deterministic, timeline-preserving derivative with a retained manifest is reproducible from the original',
    },

    /**
     * Enhanced or denoised. Usable for comparison; never a replacement for the
     * original or the standard derivative as evidence, and never an input to
     * the linguistic pipeline — denoising removes signal that tone, prosody and
     * speaker work depend on.
     */
    'analysis-enhanced': {
        isEvidence: false,
        feedsLinguisticPipeline: false,
        producedInPreservationPath: true,
        reason:
            'enhancement discards signal; it is a comparison aid, never evidence and never analysis input',
    },

    /** Low-bandwidth playback, including precomputed waveform data. */
    playback: {
        isEvidence: false,
        feedsLinguisticPipeline: false,
        producedInPreservationPath: true,
        reason: 'a playback derivative is shaped for a listener, not for measurement',
    },

    /**
     * Release rendering. A production artifact with editorial intent, produced
     * by a separate job class, walled off from everything linguistic (ADR-039).
     */
    publication: {
        isEvidence: false,
        feedsLinguisticPipeline: false,
        producedInPreservationPath: false,
        reason:
            'a release rendering carries editorial intent and is walled off from the linguistic pipeline (ADR-039)',
    },
});

/** Look up the rules for a rendition kind. */
export function renditionRule(kind: RenditionKind): RenditionRule {
    return RULES[kind];
}

/** Whether a rendition may stand as evidence of what was recorded. */
export function isEvidence(kind: RenditionKind): boolean {
    return RULES[kind].isEvidence;
}

/**
 * Refuse a rendition as an input to transcription, alignment, phonetic
 * analysis, pronunciation evaluation or model training.
 *
 * Call this at the point of consumption, not at the point of production: the
 * rule ADR-038 and ADR-039 state is about what feeds the pipeline, and a
 * denoised derivative is perfectly legitimate right up until something tries to
 * transcribe it.
 */
export function assertMayFeedLinguisticPipeline(kind: RenditionKind, consumer: string): void {
    if (!RULES[kind].feedsLinguisticPipeline) {
        throw new Error(
            `${consumer} may not consume a '${kind}' rendition: ${RULES[kind].reason}. ` +
                "Use the original or the 'analysis-standard' derivative.",
        );
    }
}

/** Refuse to produce a rendition that does not belong in the preservation path. */
export function assertProducibleInPreservationPath(kind: RenditionKind): void {
    if (!RULES[kind].producedInPreservationPath) {
        throw new Error(
            `A '${kind}' rendition is not produced in the preservation path: ${RULES[kind].reason}. ` +
                'Route it through the release-rendering job class.',
        );
    }
}
