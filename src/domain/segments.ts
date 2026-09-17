/**
 * Segmentation and annotation candidates.
 *
 * ADR-038 splits segmentation authority cleanly:
 *
 *   - **Speech/silence boundaries of record are computed here**, server-side,
 *     for every recording, language and device tier, on the original timeline.
 *   - **Word-level alignment of record is ESU's**, from Yahura.
 *   - The capture UI's live transcript is a lowest-authority machine draft and
 *     is never the boundary mechanism of record.
 *
 * ADR-039 adds the rule that gives segments their purpose: dead air and false
 * starts are handled by segmentation and annotation — data *about* the audio,
 * never instructions to change it. A segment marks a region. It never causes
 * one to be removed, and nothing downstream may read it as a cut list.
 */

import type { OriginalTimeRange } from './timeline.js';
import { durationMs } from './timeline.js';

export type SegmentKind = 'speech' | 'silence';

/** A speech or silence region, on the original timeline. */
export interface VadSegment {
    readonly kind: SegmentKind;
    readonly range: OriginalTimeRange;
    /** Detector confidence in [0, 1], or null where the detector reports none. */
    readonly confidence: number | null;
}

/**
 * Authority levels for anything that proposes linguistic content.
 *
 * `machine-candidate` is the floor, and everything this service emits sits on
 * it. The Node never declares linguistic truth (ADR-038); ESU holds the
 * reviewed record and a human correction outranks anything from here.
 */
export type AnnotationAuthority = 'machine-candidate';

/**
 * A TextGrid-compatible annotation candidate.
 *
 * "TextGrid-compatible" means exportable in TextGrid shape, not that this
 * service holds TextGrids as records. It is an acoustic segmentation offered to
 * ESU as a starting point — the lowest-authority input to a record ESU owns.
 */
export interface AnnotationCandidate {
    /** Tier name as it appears in the exported TextGrid. */
    readonly tier: string;
    readonly range: OriginalTimeRange;
    /**
     * The label. Acoustic, never linguistic: `speech`, `silence`, a detected
     * condition. This service does not propose words.
     */
    readonly label: string;
    readonly authority: AnnotationAuthority;
}

/**
 * Total speech time as a fraction of the recording.
 *
 * Reported as a signal property. It is not a quality judgement on the speaker
 * and nothing may read it as one.
 */
export function speechRatio(segments: readonly VadSegment[], recordingDurationMs: number): number {
    if (recordingDurationMs <= 0) {
        return 0;
    }
    const speech = segments
        .filter((segment) => segment.kind === 'speech')
        .reduce((total, segment) => total + durationMs(segment.range), 0);
    return Math.min(1, speech / recordingDurationMs);
}

/** Silence regions at least `thresholdMs` long. */
export function longGaps(
    segments: readonly VadSegment[],
    thresholdMs: number,
): readonly VadSegment[] {
    return segments.filter(
        (segment) => segment.kind === 'silence' && durationMs(segment.range) >= thresholdMs,
    );
}

/**
 * Turn VAD segments into annotation candidates.
 *
 * Every segment becomes a candidate, silence included. Dropping silence here
 * would quietly turn a description of the recording into a list of the parts
 * worth keeping, which is the "effective audio" idea ADR-039 rejected.
 */
export function candidatesFromSegments(
    segments: readonly VadSegment[],
    tier = 'starmus-vad',
): readonly AnnotationCandidate[] {
    return segments.map((segment) => ({
        tier,
        range: segment.range,
        label: segment.kind,
        authority: 'machine-candidate' as const,
    }));
}

/**
 * Fill the gaps between speech regions with explicit silence segments.
 *
 * A detector reports where speech is; the record has to say what the rest is,
 * or a reader is free to assume the unmarked stretches are not part of the
 * recording. They are.
 */
export function withExplicitSilence(
    speechSegments: readonly VadSegment[],
    recordingDurationMs: number,
    makeRange: (startMs: number, endMs: number) => OriginalTimeRange,
): readonly VadSegment[] {
    const speech = [...speechSegments]
        .filter((segment) => segment.kind === 'speech')
        .sort((a, b) => a.range.startMs - b.range.startMs);

    const out: VadSegment[] = [];
    let cursor = 0;

    for (const segment of speech) {
        if (segment.range.startMs > cursor) {
            out.push({
                kind: 'silence',
                range: makeRange(cursor, segment.range.startMs),
                confidence: null,
            });
        }
        out.push(segment);
        cursor = Math.max(cursor, segment.range.endMs);
    }

    if (cursor < recordingDurationMs) {
        out.push({
            kind: 'silence',
            range: makeRange(cursor, recordingDurationMs),
            confidence: null,
        });
    }

    return out;
}
