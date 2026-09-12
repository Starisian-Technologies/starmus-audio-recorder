/**
 * Deployment gate for the pinned analysis toolchain.
 *
 * Run before the service accepts work, and in CI. It answers one question for
 * every tool: is the thing on this machine the thing this deployment was pinned
 * to, and has its licence been read from its own distribution and recorded?
 *
 * A drifted tool is not a startup inconvenience. Measurements carry the version
 * that produced them, and a version that silently moved makes every result
 * recorded afterwards a claim nobody can check.
 */

import { realpath } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { loadPinnedToolset, resolveTool, ToolPinError } from './tools.js';

export interface VerificationLine {
    readonly jobClass: string;
    readonly tool: string;
    readonly ok: boolean;
    readonly detail: string;
}

/** Resolve every tool pinned for every job class. */
export async function verifyPinnedToolset(path: string): Promise<readonly VerificationLine[]> {
    const toolset = await loadPinnedToolset(path);
    const lines: VerificationLine[] = [];

    for (const [jobClass, toolNames] of Object.entries(toolset.jobClassPins)) {
        for (const toolName of toolNames) {
            try {
                const resolved = await resolveTool(toolset, jobClass, toolName);
                lines.push({
                    jobClass,
                    tool: toolName,
                    ok: true,
                    detail: `${resolved.reportedVersion} — ${resolved.licenseAttestation.license} (read from ${resolved.licenseAttestation.readFrom})`,
                });
            } catch (error) {
                lines.push({
                    jobClass,
                    tool: toolName,
                    ok: false,
                    detail: error instanceof Error ? error.message : String(error),
                });
            }
        }
    }

    return lines;
}

async function main(): Promise<void> {
    const path = process.argv[2] ?? resolve(process.cwd(), 'tools.pinned.json');
    let lines: readonly VerificationLine[];

    try {
        lines = await verifyPinnedToolset(path);
    } catch (error) {
        if (error instanceof ToolPinError) {
            process.stderr.write(`${error.message}\n`);
            process.exitCode = 1;
            return;
        }
        throw error;
    }

    let failed = 0;
    for (const line of lines) {
        const mark = line.ok ? 'ok  ' : 'FAIL';
        process.stdout.write(`${mark} ${line.jobClass} / ${line.tool}: ${line.detail}\n`);
        if (!line.ok) {
            failed += 1;
        }
    }

    if (failed > 0) {
        process.stderr.write(
            `\n${failed} pinned tool(s) did not verify. The analysis worker does not run unpinned (ADR-038).\n`,
        );
        process.exitCode = 1;
        return;
    }

    process.stdout.write(`\nAll ${lines.length} pinned tool(s) verified.\n`);
}

/**
 * Whether this module is the process entry point.
 *
 * Compared through `realpath` so a symlinked bin wrapper still matches. The
 * check exists so importing this module from a test does not start a
 * verification pass and set the process exit code.
 */
async function isEntryPoint(): Promise<boolean> {
    const invoked = process.argv[1];
    if (invoked === undefined) {
        return false;
    }
    try {
        const [self, entry] = await Promise.all([
            realpath(fileURLToPath(import.meta.url)),
            realpath(invoked),
        ]);
        return self === entry;
    } catch {
        return false;
    }
}

if (await isEntryPoint()) {
    await main();
}
