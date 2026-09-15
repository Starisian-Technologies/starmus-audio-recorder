/**
 * Pinned external tools, and the licence attestation that gates them.
 *
 * ADR-038 on Praat: "a **pinned, server-side analysis worker** (separate
 * process, never linked; version pinned per job class; license read from the
 * upstream distribution and recorded before deployment)."
 *
 * Each clause is enforced here rather than described:
 *
 *   - **Separate process, never linked.** Nothing in this repository binds a
 *     native library. Tools are located as executables and spawned. Praat is
 *     GPL-2+ (read from its distribution, recorded in `tools.pinned.json`) and
 *     this service is BUSL-1.1; the process boundary is what keeps those apart,
 *     so it is a licensing requirement and not a style preference.
 *   - **Version pinned per job class.** A job class names its pins, and the
 *     resolver refuses a tool whose reported version is not the pinned one. A
 *     measurement's provenance then names a version that was actually running.
 *   - **License read from the upstream distribution and recorded before
 *     deployment.** Every pin carries an attestation naming the file it was
 *     read from. A pin without one does not resolve, so a tool cannot reach
 *     production unread.
 */

import { execFile } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

/** What was read from the tool's own distribution, and where from. */
export interface LicenseAttestation {
    /** The licence as the distribution states it, verbatim where short. */
    readonly license: string;
    /** The file it was read from, on the machine it was read on. */
    readonly readFrom: string;
    /** ISO date the reading was recorded. */
    readonly recordedAt: string;
    /** Anything a reader needs to know about the licence's reach. */
    readonly note: string;
}

export interface ToolPin {
    readonly name: string;
    /** Executable name or absolute path. Resolved on PATH when not absolute. */
    readonly executable: string;
    /** The exact version this deployment is pinned to. */
    readonly pinnedVersion: string;
    /** Arguments that make the tool print its version. */
    readonly versionArgs: readonly string[];
    /** Regex with one capture group that extracts the version from that output. */
    readonly versionPattern: string;
    readonly licenseAttestation: LicenseAttestation;
}

/** Job classes that run external tools, and which pins each is allowed. */
export interface PinnedToolset {
    readonly version: string;
    readonly tools: readonly ToolPin[];
    readonly jobClassPins: Readonly<Record<string, readonly string[]>>;
}

export class ToolPinError extends Error {
    public constructor(message: string, options?: ErrorOptions) {
        super(message, options);
        this.name = 'ToolPinError';
    }
}

/** A tool that resolved: pinned, version-checked, and licence-attested. */
export interface ResolvedTool {
    readonly name: string;
    readonly executable: string;
    /** The version the tool reported, not the one configured. They matched. */
    readonly reportedVersion: string;
    readonly licenseAttestation: LicenseAttestation;
}

/** Read a pinned toolset from disk. */
export async function loadPinnedToolset(path: string): Promise<PinnedToolset> {
    let raw: string;
    try {
        raw = await readFile(path, 'utf8');
    } catch (cause) {
        throw new ToolPinError(
            `No pinned toolset at ${path}. The analysis worker does not run unpinned: ADR-038 pins tool versions per job class and requires each licence to be read and recorded before deployment.`,
            { cause },
        );
    }
    const parsed: unknown = JSON.parse(raw);
    assertToolset(parsed, path);
    return parsed;
}

function assertToolset(value: unknown, path: string): asserts value is PinnedToolset {
    if (typeof value !== 'object' || value === null) {
        throw new ToolPinError(`${path} is not a pinned toolset object.`);
    }
    const candidate: Record<string, unknown> = value as Record<string, unknown>;
    const tools = candidate['tools'];
    if (!Array.isArray(tools) || tools.length === 0) {
        throw new ToolPinError(`${path} declares no tools.`);
    }

    // Narrowed field by field rather than cast. This file is the gate that
    // stops an unpinned or unread tool reaching production, so it does not get
    // to assume the shape of the thing it is checking.
    for (const entry of tools as readonly unknown[]) {
        if (typeof entry !== 'object' || entry === null) {
            throw new ToolPinError(`${path} contains a tool entry that is not an object.`);
        }
        const tool: Record<string, unknown> = entry as Record<string, unknown>;
        const name = typeof tool['name'] === 'string' ? tool['name'] : '(unnamed)';

        const pinnedVersion = tool['pinnedVersion'];
        if (typeof pinnedVersion !== 'string' || pinnedVersion.trim() === '') {
            throw new ToolPinError(
                `Tool '${name}' in ${path} has no pinned version. ADR-038 pins the version per job class.`,
            );
        }

        const attestation = tool['licenseAttestation'];
        const fields: Record<string, unknown> =
            typeof attestation === 'object' && attestation !== null
                ? (attestation as Record<string, unknown>)
                : {};
        const license = fields['license'];
        const readFrom = fields['readFrom'];
        if (
            typeof license !== 'string' ||
            license.trim() === '' ||
            typeof readFrom !== 'string' ||
            readFrom.trim() === ''
        ) {
            throw new ToolPinError(
                `Tool '${name}' in ${path} has no licence attestation naming what was read and where from. ` +
                    'ADR-038: the licence is read from the upstream distribution and recorded before deployment.',
            );
        }
    }
}

/**
 * Resolve a tool for a job class: check it is pinned for that class, run it,
 * and confirm the version it reports is the version pinned.
 *
 * The version check is the point. A tool that silently upgraded underneath a
 * deployment produces measurements whose provenance names a version that never
 * computed them, and nothing downstream could tell.
 */
export async function resolveTool(
    toolset: PinnedToolset,
    jobClass: string,
    toolName: string,
): Promise<ResolvedTool> {
    const allowed = toolset.jobClassPins[jobClass];
    if (allowed === undefined) {
        throw new ToolPinError(
            `Job class '${jobClass}' declares no pinned tools. ADR-038 pins versions per job class; a class with no pins does not run.`,
        );
    }
    if (!allowed.includes(toolName)) {
        throw new ToolPinError(
            `Job class '${jobClass}' is not pinned to '${toolName}'. Pinned for this class: ${allowed.join(', ') || '(none)'}.`,
        );
    }

    const pin = toolset.tools.find((tool) => tool.name === toolName);
    if (pin === undefined) {
        throw new ToolPinError(`No pin recorded for tool '${toolName}'.`);
    }

    const reportedVersion = await probeVersion(pin);
    if (reportedVersion !== pin.pinnedVersion) {
        throw new ToolPinError(
            `'${pin.name}' reports version ${reportedVersion} but this deployment is pinned to ${pin.pinnedVersion}. ` +
                'Refusing to run: a measurement must name the version that actually produced it.',
        );
    }

    return {
        name: pin.name,
        executable: pin.executable,
        reportedVersion,
        licenseAttestation: pin.licenseAttestation,
    };
}

/** Ask a tool what version it is. */
export async function probeVersion(pin: ToolPin): Promise<string> {
    let output: string;
    try {
        const result = await execFileAsync(pin.executable, [...pin.versionArgs], {
            encoding: 'utf8',
        });
        output = `${result.stdout}\n${result.stderr}`;
    } catch (cause) {
        // Several tools print their version and exit non-zero. The output is
        // what matters, so read it before treating this as a failure.
        const failure = cause as { stdout?: string; stderr?: string };
        output = `${failure.stdout ?? ''}\n${failure.stderr ?? ''}`;
        if (output.trim() === '') {
            throw new ToolPinError(
                `Could not run '${pin.executable}' to read its version. The analysis worker spawns tools as separate processes and does not link them; the executable must be present.`,
                { cause },
            );
        }
    }

    const match = new RegExp(pin.versionPattern).exec(output);
    if (match === null || match[1] === undefined) {
        throw new ToolPinError(
            `Could not read a version for '${pin.name}' from its output using /${pin.versionPattern}/.`,
        );
    }
    return match[1];
}
