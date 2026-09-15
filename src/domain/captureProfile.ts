/**
 * Capture profiles (ADR-035).
 *
 * Three named profiles. **No numeric floors are set here.** The sample rate,
 * bit depth and channel floor for `documentation`, and the permitted container
 * and codec set for every profile, are OQ-021 — owned by AIWA and the
 * acoustic-analysis owner. A floor set from architecture rather than from what
 * the measurements require is how the platform-wide 16 kHz cap arose in the
 * first place, and this service does not repeat it.
 *
 * What this module does provide is the machinery to apply a floor the moment
 * one is ruled on, and the honest default until then: a profile with no floor
 * admits everything and records that no floor was applied.
 */

export const CAPTURE_PROFILES = ['conversation', 'documentation', 'import'] as const;

export type CaptureProfileName = (typeof CAPTURE_PROFILES)[number];

/** Whether a value names a capture profile. */
export function isCaptureProfileName(value: unknown): value is CaptureProfileName {
    return typeof value === 'string' && (CAPTURE_PROFILES as readonly string[]).includes(value);
}

/**
 * The admissibility floor for a profile, once OQ-021 is ruled on.
 *
 * `null` on a field means "not ruled on", not "no limit" — the difference
 * matters, because a measurement taken from material below an unstated floor
 * must be reported as ungated rather than as passing.
 */
export interface AdmissibilityFloor {
    readonly minSampleRateHz: number | null;
    readonly minBitDepth: number | null;
    readonly minChannels: number | null;
    /** Permitted containers/codecs, or `null` while OQ-021 is open. */
    readonly permittedCodecs: readonly string[] | null;
    /** The ADR or ruling that set these values. Empty while none exists. */
    readonly rulingReference: string;
}

const UNRULED: AdmissibilityFloor = Object.freeze({
    minSampleRateHz: null,
    minBitDepth: null,
    minChannels: null,
    permittedCodecs: null,
    rulingReference: '',
});

/**
 * Floors per profile.
 *
 * Every entry is deliberately unruled. Populating one is a governance act: it
 * requires the OQ-021 ruling and a reference to it, and `admissibility()` will
 * report `gated: false` until then rather than pretending material passed a
 * check that was never run.
 */
const FLOORS: Readonly<Record<CaptureProfileName, AdmissibilityFloor>> = Object.freeze({
    conversation: UNRULED,
    documentation: UNRULED,
    import: UNRULED,
});

/** The recorded floor for a profile. */
export function admissibilityFloor(profile: CaptureProfileName): AdmissibilityFloor {
    return FLOORS[profile];
}

/** What the source actually delivered, as measured at intake. */
export interface MeasuredSourceFormat {
    readonly sampleRateHz: number | null;
    readonly bitDepth: number | null;
    readonly channels: number | null;
    readonly codec: string | null;
}

export interface AdmissibilityVerdict {
    /** Was a floor actually applied? False while OQ-021 is open. */
    readonly gated: boolean;
    /** True only when a floor existed and the material met it. */
    readonly admissible: boolean;
    /** Human-readable reasons, one per failing or ungated dimension. */
    readonly notes: readonly string[];
}

/**
 * Decide whether material is admissible as a source for acoustic measurement.
 *
 * The three-state result is the point. `gated: false` says the question has not
 * been ruled on — which is different from a pass, and a consumer that treats it
 * as one is reading a floor into the record that nobody set.
 *
 * ADR-011 holds throughout: nothing here rejects intake. Material that fails a
 * floor is stored and marked, never discarded. A contributor does not lose a
 * recording because a dimension disagreed.
 */
export function admissibility(
    profile: CaptureProfileName,
    measured: MeasuredSourceFormat,
): AdmissibilityVerdict {
    const floor = FLOORS[profile];
    const notes: string[] = [];

    if (
        floor.minSampleRateHz === null &&
        floor.minBitDepth === null &&
        floor.minChannels === null &&
        floor.permittedCodecs === null
    ) {
        return {
            gated: false,
            admissible: false,
            notes: [
                `No admissibility floor is recorded for the '${profile}' profile (OQ-021 is open). ` +
                    'Material is stored and described; no floor was applied, and this is not a pass.',
            ],
        };
    }

    let admissible = true;

    if (floor.minSampleRateHz !== null) {
        if (measured.sampleRateHz === null) {
            admissible = false;
            notes.push('Sample rate was not reported, so the floor could not be applied.');
        } else if (measured.sampleRateHz < floor.minSampleRateHz) {
            admissible = false;
            notes.push(
                `Sample rate ${measured.sampleRateHz} Hz is below the '${profile}' floor of ${floor.minSampleRateHz} Hz (${floor.rulingReference}).`,
            );
        }
    }

    if (floor.minBitDepth !== null) {
        if (measured.bitDepth === null) {
            admissible = false;
            notes.push('Bit depth was not reported, so the floor could not be applied.');
        } else if (measured.bitDepth < floor.minBitDepth) {
            admissible = false;
            notes.push(
                `Bit depth ${measured.bitDepth} is below the '${profile}' floor of ${floor.minBitDepth} (${floor.rulingReference}).`,
            );
        }
    }

    if (floor.minChannels !== null) {
        if (measured.channels === null) {
            admissible = false;
            notes.push('Channel count was not reported, so the floor could not be applied.');
        } else if (measured.channels < floor.minChannels) {
            admissible = false;
            notes.push(
                `Channel count ${measured.channels} is below the '${profile}' floor of ${floor.minChannels} (${floor.rulingReference}).`,
            );
        }
    }

    if (floor.permittedCodecs !== null) {
        if (measured.codec === null) {
            admissible = false;
            notes.push('Codec was not identified, so the permitted set could not be applied.');
        } else if (!floor.permittedCodecs.includes(measured.codec)) {
            admissible = false;
            notes.push(
                `Codec '${measured.codec}' is not in the permitted set for '${profile}' (${floor.rulingReference}).`,
            );
        }
    }

    return { gated: true, admissible, notes };
}
