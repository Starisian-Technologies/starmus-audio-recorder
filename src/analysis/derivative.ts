/**
 * The standard analysis derivative, and the probe that describes a source.
 *
 * ADR-038 defines this rung as "deterministic, timeline-preserving preparation
 * (container/codec decode, channel-preserving extraction, resampling,
 * normalization) whose transformation manifest, source hash, tool version, and
 * measurements are retained".
 *
 * Three words in that sentence do the work:
 *
 *   - **deterministic** — the same source and the same steps produce the same
 *     bytes. Container metadata and encoder timestamps are stripped, so a
 *     re-run months later is byte-identical and the manifest's output hash
 *     still means something.
 *   - **timeline-preserving** — no step shortens, lengthens or reorders the
 *     signal. There is no trim and no silence removal, because "prepared" means
 *     decoded, measured, segmented and reproducibly normalized, never cleaned
 *     or cut.
 *   - **channel-preserving** — a stereo source stays stereo. Folding down to
 *     mono is a fail condition for the `documentation` and `import` profiles,
 *     and discarding a channel here would discard it for every measurement
 *     taken afterwards.
 */

import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { promisify } from 'node:util';

import type { SourceFormat } from '../domain/asset.js';
import type { AppliedStep, TransformationManifest, ToolRecord } from '../domain/manifest.js';
import { transformationManifest } from '../domain/manifest.js';
import type { ResolvedTool } from './tools.js';

const execFileAsync = promisify(execFile);

export class DerivativeError extends Error {
    public constructor(message: string, options?: ErrorOptions) {
        super(message, options);
        this.name = 'DerivativeError';
    }
}

interface FfprobeStream {
    readonly codec_type?: string;
    readonly codec_name?: string;
    readonly sample_rate?: string;
    readonly channels?: number;
    readonly bits_per_sample?: number;
    readonly bits_per_raw_sample?: string;
    readonly duration?: string;
}

interface FfprobeFormat {
    readonly format_name?: string;
    readonly duration?: string;
}

/**
 * Describe a source file.
 *
 * Every field is what the probe reported or `null`. A source whose sample rate
 * the probe could not read is described as unknown, not as a default — the
 * capture UI applies the same rule on its side, and a constant substituted here
 * would end up in an event claiming the device delivered something it did not.
 */
export async function probeSource(
    ffprobe: ResolvedTool,
    path: string,
): Promise<{ format: SourceFormat; raw: unknown }> {
    let stdout: string;
    try {
        const result = await execFileAsync(
            ffprobe.executable,
            ['-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', path],
            { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 },
        );
        stdout = result.stdout;
    } catch (cause) {
        throw new DerivativeError(`Could not probe ${path}`, { cause });
    }

    const parsed = JSON.parse(stdout) as {
        streams?: readonly FfprobeStream[];
        format?: FfprobeFormat;
    };
    const audio = (parsed.streams ?? []).find((stream) => stream.codec_type === 'audio');

    const numberOrNull = (value: string | number | undefined): number | null => {
        if (value === undefined) {
            return null;
        }
        const parsedValue = Number(value);
        return Number.isFinite(parsedValue) ? parsedValue : null;
    };

    const durationSeconds =
        numberOrNull(audio?.duration) ?? numberOrNull(parsed.format?.duration);

    return {
        format: {
            container: parsed.format?.format_name ?? null,
            codec: audio?.codec_name ?? null,
            sampleRateHz: numberOrNull(audio?.sample_rate),
            bitDepth:
                numberOrNull(audio?.bits_per_raw_sample) ?? numberOrNull(audio?.bits_per_sample),
            channels: audio?.channels ?? null,
            durationMs: durationSeconds === null ? null : Math.round(durationSeconds * 1000),
        },
        raw: parsed,
    };
}

export interface DerivativeOptions {
    /**
     * Resample to this rate. Upsampling only — see
     * {@link prepareAnalysisDerivative} for why downsampling is refused here.
     */
    readonly resampleHz?: number | null;
    /** Apply a fixed, deterministic gain in dB. Not a dynamic normaliser. */
    readonly gainDb?: number | null;
}

export interface PreparedDerivative {
    readonly outputPath: string;
    readonly manifest: TransformationManifest;
    readonly format: SourceFormat;
}

/** PCM sample format wide enough not to lose the source's depth. */
function pcmCodecFor(bitDepth: number | null): { codec: string; bitDepth: number } {
    if (bitDepth !== null && bitDepth > 24) {
        return { codec: 'pcm_s32le', bitDepth: 32 };
    }
    if (bitDepth !== null && bitDepth > 16) {
        return { codec: 'pcm_s24le', bitDepth: 24 };
    }
    return { codec: 'pcm_s16le', bitDepth: 16 };
}

async function sha256(path: string): Promise<string> {
    return createHash('sha256')
        .update(await readFile(path))
        .digest('hex');
}

/**
 * Produce the standard analysis derivative.
 *
 * Downsampling is refused rather than offered as an option. The derivative is
 * what phonetic, tone and prosody work measures, and a rate chosen here would
 * be a numeric floor set from architecture — which is precisely what OQ-021
 * reserves for AIWA and the acoustic-analysis owner, and precisely how the
 * platform-wide 16 kHz cap arose. Upsampling is permitted because some
 * analysers require a minimum rate and it discards nothing.
 */
export async function prepareAnalysisDerivative(
    ffmpeg: ResolvedTool,
    ffprobe: ResolvedTool,
    sourcePath: string,
    outputPath: string,
    options: DerivativeOptions = {},
    now: string = new Date().toISOString(),
): Promise<PreparedDerivative> {
    const { format: sourceFormat } = await probeSource(ffprobe, sourcePath);

    const resampleHz = options.resampleHz ?? null;
    if (
        resampleHz !== null &&
        sourceFormat.sampleRateHz !== null &&
        resampleHz < sourceFormat.sampleRateHz
    ) {
        throw new DerivativeError(
            `Refusing to downsample ${sourceFormat.sampleRateHz} Hz to ${resampleHz} Hz for the standard analysis derivative. ` +
                'The numeric floors for capture profiles are OQ-021, owned by AIWA and the acoustic-analysis owner; ' +
                'a rate chosen here would answer that question by discarding the material it is about.',
        );
    }

    const pcm = pcmCodecFor(sourceFormat.bitDepth);
    const steps: AppliedStep[] = [
        {
            step: 'decode',
            parameters: {
                to: pcm.codec,
                container: 'wav',
                // Strip container metadata and encoder timestamps so two runs
                // over the same source are byte-identical.
                bitexact: true,
                mapMetadata: -1,
            },
        },
        {
            step: 'channel-extract',
            parameters: {
                // Channel-preserving by construction: every channel is carried
                // through. Recorded as a step so the manifest states it rather
                // than leaving a reader to infer it from an absence.
                channels: sourceFormat.channels ?? 0,
                foldDownToMono: false,
            },
        },
    ];

    const args = [
        '-nostdin',
        '-v',
        'error',
        '-i',
        sourcePath,
        '-map',
        '0:a:0',
        '-map_metadata',
        '-1',
        '-fflags',
        '+bitexact',
        '-flags',
        '+bitexact',
        '-c:a',
        pcm.codec,
    ];

    if (resampleHz !== null) {
        args.push('-ar', String(resampleHz));
        steps.push({ step: 'resample', parameters: { toHz: resampleHz, direction: 'up' } });
    }

    const gainDb = options.gainDb ?? null;
    if (gainDb !== null) {
        // A fixed gain, not a dynamic normaliser: `loudnorm` and friends are
        // program-dependent and would make the derivative non-reproducible.
        args.push('-af', `volume=${gainDb}dB`);
        steps.push({ step: 'normalize-gain', parameters: { gainDb, dynamic: false } });
    }

    args.push('-f', 'wav', '-y', outputPath);

    try {
        await execFileAsync(ffmpeg.executable, args, { encoding: 'utf8' });
    } catch (cause) {
        const failure = cause as { stderr?: string };
        throw new DerivativeError(
            `Could not prepare the analysis derivative: ${(failure.stderr ?? '').trim() || String(cause)}`,
            { cause },
        );
    }

    const tools: ToolRecord[] = [
        { name: ffmpeg.name, version: ffmpeg.reportedVersion },
        { name: ffprobe.name, version: ffprobe.reportedVersion },
    ];

    const manifest = transformationManifest({
        sourceSha256: await sha256(sourcePath),
        outputSha256: await sha256(outputPath),
        steps,
        tools,
        producedAt: now,
    });

    const { format } = await probeSource(ffprobe, outputPath);

    if (
        sourceFormat.durationMs !== null &&
        format.durationMs !== null &&
        Math.abs(format.durationMs - sourceFormat.durationMs) > 50
    ) {
        throw new DerivativeError(
            `The derivative runs ${format.durationMs}ms against a source of ${sourceFormat.durationMs}ms. ` +
                'A preparation step that changes duration is not timeline-preserving, and every downstream ' +
                "timestamp is on the original's timeline (ADR-039).",
        );
    }

    return { outputPath, manifest, format };
}
