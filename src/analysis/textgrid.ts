/**
 * TextGrid export for annotation candidates.
 *
 * ADR-038: the analysis worker produces "TextGrid-compatible **annotation
 * candidates** (acoustic segmentations exportable in TextGrid shape, entering
 * ESU as lowest-authority machine candidates)".
 *
 * Read that precisely. This service does not hold TextGrids as records — ESU
 * holds the annotation record. What it produces is an export in a shape a
 * linguist's tooling already opens, offered as the lowest-authority input to a
 * record somebody else owns. A human correction in ESU outranks every line this
 * module writes.
 *
 * The format written is Praat's own long text TextGrid, so Praat and the tools
 * that read its output can open the export without a converter.
 */

import type { AnnotationCandidate } from '../domain/segments.js';

/**
 * Escape a label for Praat's long text format.
 *
 * Praat quotes interval text with `"` and doubles an embedded quote. A label
 * from this service is `speech`, `silence` or a detected condition, so this is
 * belt and braces — but the labels are data, and data eventually contains the
 * character that breaks the parser.
 */
function escapeLabel(label: string): string {
    return label.replace(/"/g, '""');
}

function seconds(ms: number): string {
    // Praat reads plain decimal seconds. Six places keeps sample-accurate
    // boundaries at any rate this platform will see without writing exponents,
    // which Praat's long-text parser does not accept.
    return (ms / 1000).toFixed(6);
}

export interface TextGridOptions {
    /** Total duration of the recording, in milliseconds. */
    readonly recordingDurationMs: number;
    /** Tier order in the output. Tiers not listed follow in first-seen order. */
    readonly tierOrder?: readonly string[];
}

/**
 * Write candidates as a Praat long text TextGrid.
 *
 * Intervals must tile their tier without gaps, which is why
 * `segments.withExplicitSilence` exists: a tier with holes is not a valid
 * TextGrid, and filling the holes silently here would hide a segmentation that
 * did not cover the recording.
 */
export function toTextGrid(
    candidates: readonly AnnotationCandidate[],
    options: TextGridOptions,
): string {
    const xmax = options.recordingDurationMs;

    const byTier = new Map<string, AnnotationCandidate[]>();
    for (const candidate of candidates) {
        const existing = byTier.get(candidate.tier);
        if (existing === undefined) {
            byTier.set(candidate.tier, [candidate]);
        } else {
            existing.push(candidate);
        }
    }

    const ordered: string[] = [];
    for (const tier of options.tierOrder ?? []) {
        if (byTier.has(tier)) {
            ordered.push(tier);
        }
    }
    for (const tier of byTier.keys()) {
        if (!ordered.includes(tier)) {
            ordered.push(tier);
        }
    }

    const lines: string[] = [
        'File type = "ooTextFile"',
        'Object class = "TextGrid"',
        '',
        'xmin = 0',
        `xmax = ${seconds(xmax)}`,
        '<exists>',
        `size = ${ordered.length}`,
        'item []:',
    ];

    ordered.forEach((tier, tierIndex) => {
        const intervals = [...(byTier.get(tier) ?? [])].sort(
            (a, b) => a.range.startMs - b.range.startMs,
        );

        for (let i = 1; i < intervals.length; i += 1) {
            const previous = intervals[i - 1];
            const current = intervals[i];
            if (previous === undefined || current === undefined) {
                continue;
            }
            if (current.range.startMs !== previous.range.endMs) {
                throw new Error(
                    `Tier '${tier}' has a gap or overlap between ${previous.range.endMs}ms and ${current.range.startMs}ms. ` +
                        'A TextGrid tier must tile the recording; fill non-speech explicitly rather than leaving holes.',
                );
            }
        }

        lines.push(
            `    item [${tierIndex + 1}]:`,
            '        class = "IntervalTier"',
            `        name = "${escapeLabel(tier)}"`,
            '        xmin = 0',
            `        xmax = ${seconds(xmax)}`,
            `        intervals: size = ${intervals.length}`,
        );

        intervals.forEach((interval, index) => {
            lines.push(
                `        intervals [${index + 1}]:`,
                `            xmin = ${seconds(interval.range.startMs)}`,
                `            xmax = ${seconds(interval.range.endMs)}`,
                `            text = "${escapeLabel(interval.label)}"`,
            );
        });
    });

    return `${lines.join('\n')}\n`;
}
