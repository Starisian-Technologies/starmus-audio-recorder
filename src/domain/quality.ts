/**
 * Quality flags and the processing route they recommend.
 *
 * ADR-038's intake measurement scope ends with "quality flags and a recommended
 * processing route". Two things this module will not do:
 *
 *   - **Reject.** ADR-011's unconditional capture rule holds without exception.
 *     Every route below keeps the material. The worst outcome is `quarantine`,
 *     which means held and marked, never discarded.
 *   - **Judge.** A flag describes the signal. It never says a word,
 *     pronunciation or grammar form is wrong.
 */

export const QUALITY_FLAGS = [
    'truncated',
    'corrupt-container',
    'clipping',
    'dropout',
    'dc-offset',
    'low-signal-to-noise',
    'silent',
    'mostly-silence',
    'long-gaps',
    'overlapping-speakers',
    'music-or-background',
    'integrity-unverified',
    'no-capture-profile',
] as const;

export type QualityFlag = (typeof QUALITY_FLAGS)[number];

/**
 * What happens to the asset next.
 *
 * `quarantine` is a holding state, not a bin: the bytes are kept, the flags are
 * kept, and a human or a later pass decides. ADR-011 filed asynchronous
 * governance for exactly this — deny nothing, quarantine instead.
 */
export type ProcessingRoute = 'standard' | 'review-before-analysis' | 'quarantine';

/** Flags that mean the bytes themselves cannot be trusted as a clean source. */
const INTEGRITY_FLAGS: ReadonlySet<QualityFlag> = new Set([
    'truncated',
    'corrupt-container',
    'integrity-unverified',
]);

/** Flags that mean measurement is likely to mislead without a human look. */
const REVIEW_FLAGS: ReadonlySet<QualityFlag> = new Set([
    'clipping',
    'dropout',
    'low-signal-to-noise',
    'silent',
    'mostly-silence',
    'overlapping-speakers',
    'music-or-background',
]);

export interface QualityAssessment {
    readonly flags: readonly QualityFlag[];
    readonly route: ProcessingRoute;
    /** One line per flag, saying what it means for this asset. */
    readonly notes: readonly string[];
}

const EXPLANATIONS: Readonly<Record<QualityFlag, string>> = Object.freeze({
    truncated: 'The stream ends before its declared duration; the tail of the recording is missing.',
    'corrupt-container': 'The container could not be parsed cleanly.',
    clipping: 'Samples reach full scale; peaks are lost and level measures will understate range.',
    dropout: 'One or more runs of zero samples interrupt the signal.',
    'dc-offset': 'The waveform is not centred on zero; level and energy measures are biased.',
    'low-signal-to-noise':
        'Noise is close to the speech level; perturbation measures in particular will be unreliable.',
    silent: 'No signal above the noise floor was found anywhere in the recording.',
    'mostly-silence': 'Speech occupies a small fraction of the recording.',
    'long-gaps': 'The recording contains long non-speech stretches.',
    'overlapping-speakers': 'More than one voice appears to be active at the same time.',
    'music-or-background': 'Sustained non-speech content is present alongside the speech.',
    'integrity-unverified':
        'The bytes received did not verify against the checksum that accompanied them.',
    'no-capture-profile':
        'The asset arrived without a capture profile, so a later reader cannot tell whether a measurement taken from it is admissible.',
});

/**
 * Turn a set of flags into a route and an explanation of each.
 *
 * The precedence is deliberate: an integrity problem outranks everything,
 * because a measurement taken from bytes that failed their checksum describes
 * something other than what was recorded.
 */
export function assess(flags: readonly QualityFlag[]): QualityAssessment {
    const unique = [...new Set(flags)];
    const notes = unique.map((flag) => `${flag}: ${EXPLANATIONS[flag]}`);

    let route: ProcessingRoute = 'standard';
    if (unique.some((flag) => INTEGRITY_FLAGS.has(flag))) {
        route = 'quarantine';
    } else if (unique.some((flag) => REVIEW_FLAGS.has(flag))) {
        route = 'review-before-analysis';
    }

    return { flags: unique, route, notes };
}

/**
 * Whether a route still permits the asset to be kept.
 *
 * Always true. The function exists so that a future route which did not keep
 * the material would have to change this line, and changing it would be
 * visible as the ADR-011 violation it is.
 */
export function keepsMaterial(_route: ProcessingRoute): true {
    return true;
}
