/**
 * Acoustic measurements and the settings that produced them.
 *
 * ADR-038 states two rules this module enforces rather than documents:
 *
 *   1. "Every stored measurement retains its extraction parameters and tool
 *      version — a measurement without its settings is not reproducible."
 *      A measurement here cannot be constructed without them.
 *
 *   2. "Measurement classes whose published reliability floors are not met by
 *      the recording are marked unreliable rather than silently reported."
 *      Reliability is part of the value, not a separate table someone may
 *      forget to join.
 *
 * And one rule it refuses to break: acoustic measurements describe the signal.
 * They never decide whether a word, pronunciation or grammar form is correct.
 * Nothing in this module carries a judgement, and nothing that reads it may
 * derive one.
 */

import type { OriginalTimeRange } from './timeline.js';
import type { RenditionKind } from './renditions.js';

/**
 * Classes of measurement this service produces.
 *
 * The list is what the service can currently compute and record. Adding a class
 * means adding its reliability precondition below — an unflagged class would
 * report through conditions in which it is meaningless.
 */
export const MEASUREMENT_CLASSES = [
    'duration',
    'pitch-f0',
    'intensity',
    'formant',
    'harmonicity',
    'jitter',
    'shimmer',
    'loudness',
    'true-peak',
    'signal-to-noise',
    'dc-offset',
    'speech-ratio',
] as const;

export type MeasurementClass = (typeof MEASUREMENT_CLASSES)[number];

/** Why a measurement class is or is not reliable on a given recording. */
export type ReliabilityVerdict =
    | { readonly reliable: true }
    | { readonly reliable: false; readonly reason: string };

/**
 * Preconditions a recording must meet for a measurement class to mean anything.
 *
 * `requiresSnrDb` is left `null` for every class. The perturbation measures
 * (jitter, shimmer, harmonicity) are the ones with published SNR-dependent
 * reliability floors, and the floor that applies to this platform's material is
 * not this service's to choose — it belongs with the acoustic-analysis owner
 * alongside OQ-021. Until it is set, those classes are marked
 * `reliable: false` with the reason stated, which is the behaviour ADR-038
 * asks for: marked unreliable, not silently reported.
 */
interface ReliabilityPrecondition {
    /** Minimum signal-to-noise ratio in dB, or null while unruled. */
    readonly requiresSnrDb: number | null;
    /** Whether the class is meaningless without voiced speech present. */
    readonly requiresVoicedSpeech: boolean;
    /** Set when the class is known to need a floor that has not been ruled on. */
    readonly unruledFloorNote: string | null;
}

const PERTURBATION_NOTE =
    'a published SNR-dependent reliability floor applies to this class, and the floor for this ' +
    "platform's material has not been ruled on (routes to the acoustic-analysis owner with OQ-021)";

const PRECONDITIONS: Readonly<Record<MeasurementClass, ReliabilityPrecondition>> = Object.freeze({
    duration: { requiresSnrDb: null, requiresVoicedSpeech: false, unruledFloorNote: null },
    'pitch-f0': { requiresSnrDb: null, requiresVoicedSpeech: true, unruledFloorNote: null },
    intensity: { requiresSnrDb: null, requiresVoicedSpeech: false, unruledFloorNote: null },
    formant: { requiresSnrDb: null, requiresVoicedSpeech: true, unruledFloorNote: null },
    harmonicity: {
        requiresSnrDb: null,
        requiresVoicedSpeech: true,
        unruledFloorNote: PERTURBATION_NOTE,
    },
    jitter: {
        requiresSnrDb: null,
        requiresVoicedSpeech: true,
        unruledFloorNote: PERTURBATION_NOTE,
    },
    shimmer: {
        requiresSnrDb: null,
        requiresVoicedSpeech: true,
        unruledFloorNote: PERTURBATION_NOTE,
    },
    loudness: { requiresSnrDb: null, requiresVoicedSpeech: false, unruledFloorNote: null },
    'true-peak': { requiresSnrDb: null, requiresVoicedSpeech: false, unruledFloorNote: null },
    'signal-to-noise': {
        requiresSnrDb: null,
        requiresVoicedSpeech: false,
        unruledFloorNote: null,
    },
    'dc-offset': { requiresSnrDb: null, requiresVoicedSpeech: false, unruledFloorNote: null },
    'speech-ratio': { requiresSnrDb: null, requiresVoicedSpeech: false, unruledFloorNote: null },
});

/** What the recording offers, for deciding reliability. */
export interface RecordingConditions {
    readonly signalToNoiseDb: number | null;
    readonly hasVoicedSpeech: boolean;
}

/**
 * Decide whether a measurement class is reliable on this recording.
 *
 * Exported so callers can ask before spending the compute, and called
 * unconditionally by {@link measurement} so the answer cannot be skipped.
 */
export function reliabilityOf(
    measurementClass: MeasurementClass,
    conditions: RecordingConditions,
): ReliabilityVerdict {
    const precondition = PRECONDITIONS[measurementClass];

    if (precondition.requiresVoicedSpeech && !conditions.hasVoicedSpeech) {
        return {
            reliable: false,
            reason: `${measurementClass} requires voiced speech, and none was detected in this range`,
        };
    }

    if (precondition.requiresSnrDb !== null) {
        if (conditions.signalToNoiseDb === null) {
            return {
                reliable: false,
                reason: `${measurementClass} has a signal-to-noise floor of ${precondition.requiresSnrDb} dB and the recording's SNR was not measured`,
            };
        }
        if (conditions.signalToNoiseDb < precondition.requiresSnrDb) {
            return {
                reliable: false,
                reason: `${measurementClass} requires at least ${precondition.requiresSnrDb} dB SNR; this recording measured ${conditions.signalToNoiseDb} dB`,
            };
        }
    }

    if (precondition.unruledFloorNote !== null) {
        return {
            reliable: false,
            reason: `${measurementClass} is reported but not certified: ${precondition.unruledFloorNote}`,
        };
    }

    return { reliable: true };
}

/** The provenance every stored measurement carries. */
export interface MeasurementProvenance {
    /** The tool that produced it and the version it reported. */
    readonly tool: { readonly name: string; readonly version: string };
    /** The analysis script, where one was run. */
    readonly script: string | null;
    /** SHA-256 of that script, so a changed script is a different provenance. */
    readonly scriptSha256: string | null;
    /** Every parameter the extraction was run with. */
    readonly parameters: Readonly<Record<string, string | number | boolean>>;
    /** SHA-256 of the bytes measured. */
    readonly measuredSha256: string;
    /** Which rung of the ladder was measured. */
    readonly measuredRendition: RenditionKind;
}

export interface Measurement {
    readonly measurementClass: MeasurementClass;
    /** The value, in `unit`. `null` when the tool could not compute one. */
    readonly value: number | null;
    readonly unit: string;
    /** The stretch of the original timeline this describes. */
    readonly range: OriginalTimeRange;
    readonly reliability: ReliabilityVerdict;
    readonly provenance: MeasurementProvenance;
}

export interface MeasurementInput {
    readonly measurementClass: MeasurementClass;
    readonly value: number | null;
    readonly unit: string;
    readonly range: OriginalTimeRange;
    readonly conditions: RecordingConditions;
    readonly provenance: MeasurementProvenance;
}

/**
 * Construct a measurement.
 *
 * Refuses provenance that would make the result irreproducible, and computes
 * reliability rather than accepting it, so no caller can report a perturbation
 * measure as sound by declaring it so.
 */
export function measurement(input: MeasurementInput): Measurement {
    if (input.provenance.tool.version.trim() === '') {
        throw new Error(
            `Measurement '${input.measurementClass}' has no tool version. ADR-038: a measurement without its settings is not reproducible.`,
        );
    }
    if (Object.keys(input.provenance.parameters).length === 0) {
        throw new Error(
            `Measurement '${input.measurementClass}' has no extraction parameters. ADR-038: a measurement without its settings is not reproducible.`,
        );
    }
    if (input.value !== null && !Number.isFinite(input.value)) {
        throw new RangeError(
            `Measurement '${input.measurementClass}' value must be finite or null: received ${input.value}. ` +
                'Praat reports --undefined-- where it could not compute one; that is null, not NaN.',
        );
    }

    return {
        measurementClass: input.measurementClass,
        value: input.value,
        unit: input.unit,
        range: input.range,
        reliability: reliabilityOf(input.measurementClass, input.conditions),
        provenance: input.provenance,
    };
}
