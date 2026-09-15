/**
 * The original timeline.
 *
 * ADR-039: there is exactly one timeline, the original's, and every result —
 * transcription, alignment, measurement, annotation — maps to it. A derivative
 * may be resampled or channel-extracted, but it is timeline-preserving by
 * construction, so an offset means the same instant in the original and in the
 * derivative it was measured from.
 *
 * The type exists so that "which timeline is this in?" is answered by the
 * signature rather than by a comment. There is no second timeline type to
 * convert to, and no mapping contract, because none is permitted to exist.
 */

declare const brand: unique symbol;

/**
 * An offset in milliseconds from the start of the registered original.
 *
 * Never a wall-clock time: ADR-008's ordering rules and the platform's
 * distributed-system rules both refuse a client-supplied absolute timestamp as
 * an ordering authority, and a recording's own start is the only origin every
 * consumer agrees on.
 */
export type OriginalOffsetMs = number & { readonly [brand]: 'OriginalOffsetMs' };

/** A closed interval on the original timeline. */
export interface OriginalTimeRange {
    readonly startMs: OriginalOffsetMs;
    readonly endMs: OriginalOffsetMs;
}

/** Construct an offset, rejecting values that cannot be one. */
export function originalOffsetMs(value: number): OriginalOffsetMs {
    if (!Number.isFinite(value) || value < 0) {
        throw new RangeError(
            `An original-timeline offset must be a finite, non-negative number of milliseconds: received ${value}`,
        );
    }
    return value as OriginalOffsetMs;
}

/** Construct an offset from a Praat-style seconds value. */
export function offsetFromSeconds(seconds: number): OriginalOffsetMs {
    return originalOffsetMs(Math.round(seconds * 1000));
}

/** Construct a range, rejecting one that runs backwards. */
export function originalTimeRange(startMs: number, endMs: number): OriginalTimeRange {
    const start = originalOffsetMs(startMs);
    const end = originalOffsetMs(endMs);
    if (end < start) {
        throw new RangeError(
            `An original-timeline range must not run backwards: ${startMs}ms → ${endMs}ms`,
        );
    }
    return { startMs: start, endMs: end };
}

/** Duration of a range, in milliseconds. */
export function durationMs(range: OriginalTimeRange): number {
    return range.endMs - range.startMs;
}

/**
 * Whether a range lies entirely within a recording of the given duration.
 *
 * Used to refuse a measurement or segment whose coordinates cannot belong to
 * the asset it claims to describe — the cheapest way to catch a result that was
 * computed against the wrong file.
 */
export function isWithinRecording(range: OriginalTimeRange, recordingDurationMs: number): boolean {
    return range.startMs <= recordingDurationMs && range.endMs <= recordingDurationMs;
}
