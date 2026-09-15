/**
 * Transformation manifests.
 *
 * ADR-038: no analysis derivative exists without a retained transformation
 * manifest, source hash and tool version. The manifest is what makes a
 * derivative reproducible from the original rather than merely plausible — and
 * a derivative that cannot be reproduced is not evidence of anything.
 *
 * A manifest is also the boundary between a *signal-processing rendition* and a
 * *content edit* (ADR-039). Every step a manifest may record is one that
 * preserves the timeline. There is no step for trim, splice or cut, and adding
 * one is the change this type exists to make visible.
 */

import { createHash } from 'node:crypto';

/**
 * The only transformations a preservation-path derivative may record.
 *
 * Deliberately closed. Each preserves the original timeline: the same offset
 * means the same instant before and after. A step that removes or reorders
 * audio does not belong on this list, and a release rendering — which does
 * exactly that — is not a preservation-path derivative at all.
 */
export const TRANSFORMATION_STEPS = [
    'decode',
    'channel-extract',
    'resample',
    'normalize-gain',
    'denoise',
] as const;

export type TransformationStep = (typeof TRANSFORMATION_STEPS)[number];

/** Steps that change the signal's content rather than its container or level. */
const NON_DETERMINISTIC_STEPS: ReadonlySet<TransformationStep> = new Set(['denoise']);

/** One applied step and the parameters it was applied with. */
export interface AppliedStep {
    readonly step: TransformationStep;
    /** Every parameter the step was given. A step without them is not reproducible. */
    readonly parameters: Readonly<Record<string, string | number | boolean>>;
}

/** A pinned external tool, as it was actually invoked. */
export interface ToolRecord {
    readonly name: string;
    /** The version string the tool itself reported, never one assumed. */
    readonly version: string;
}

export interface TransformationManifest {
    /** SHA-256 of the bytes this derivative was produced from. */
    readonly sourceSha256: string;
    /** SHA-256 of the bytes produced. */
    readonly outputSha256: string;
    readonly steps: readonly AppliedStep[];
    readonly tools: readonly ToolRecord[];
    /** Whether re-running these steps on the same source yields the same bytes. */
    readonly deterministic: boolean;
    /** Whether every offset means the same instant as in the original. */
    readonly timelinePreserving: boolean;
    readonly producedAt: string;
}

export interface ManifestInput {
    readonly sourceSha256: string;
    readonly outputSha256: string;
    readonly steps: readonly AppliedStep[];
    readonly tools: readonly ToolRecord[];
    readonly producedAt: string;
}

const SHA256_PATTERN = /^[0-9a-f]{64}$/;

function assertSha256(value: string, label: string): void {
    if (!SHA256_PATTERN.test(value)) {
        throw new TypeError(`${label} must be a lowercase hex SHA-256 digest: received ${value}`);
    }
}

/**
 * Build a manifest, refusing an input that cannot describe a reproducible
 * derivative.
 *
 * `deterministic` is derived, not asserted by the caller. A caller that could
 * claim determinism would eventually claim it for a denoise pass, and the
 * claim is precisely what a later reader relies on.
 */
export function transformationManifest(input: ManifestInput): TransformationManifest {
    assertSha256(input.sourceSha256, 'sourceSha256');
    assertSha256(input.outputSha256, 'outputSha256');

    if (input.tools.length === 0) {
        throw new Error(
            'A transformation manifest must record the tools that produced it (ADR-038): a derivative without a tool version is not reproducible.',
        );
    }
    for (const tool of input.tools) {
        if (tool.version.trim() === '') {
            throw new Error(
                `Tool '${tool.name}' reported no version. A pinned tool records the version it reported, never one assumed.`,
            );
        }
    }
    for (const applied of input.steps) {
        if (!(TRANSFORMATION_STEPS as readonly string[]).includes(applied.step)) {
            throw new Error(
                `'${applied.step}' is not a permitted preservation-path transformation. ` +
                    'Content edits — trim, splice, cut — do not exist in this path (ADR-039).',
            );
        }
    }

    const deterministic = !input.steps.some((applied) =>
        NON_DETERMINISTIC_STEPS.has(applied.step),
    );

    return {
        sourceSha256: input.sourceSha256,
        outputSha256: input.outputSha256,
        steps: [...input.steps],
        tools: [...input.tools],
        deterministic,
        // Every permitted step preserves the timeline by construction. This
        // field is not a promise the caller makes; it is a property of the
        // closed step list above.
        timelinePreserving: true,
        producedAt: input.producedAt,
    };
}

/**
 * A stable digest of a manifest, for deduplicating identical preparation work.
 *
 * Excludes `producedAt`: two runs of the same steps on the same source are the
 * same preparation whether they happened a second or a year apart.
 */
export function manifestFingerprint(manifest: TransformationManifest): string {
    const canonical = JSON.stringify({
        sourceSha256: manifest.sourceSha256,
        steps: manifest.steps.map((applied) => ({
            step: applied.step,
            parameters: Object.fromEntries(
                Object.entries(applied.parameters).sort(([a], [b]) => (a < b ? -1 : 1)),
            ),
        })),
        tools: [...manifest.tools]
            .sort((a, b) => (a.name < b.name ? -1 : 1))
            .map((tool) => `${tool.name}@${tool.version}`),
    });
    return createHash('sha256').update(canonical).digest('hex');
}
