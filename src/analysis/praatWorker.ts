/**
 * The pinned Praat analysis worker.
 *
 * Praat runs as a separate process, never linked (ADR-038). This module spawns
 * it, hands it a script, and parses the key=value block it writes back. What it
 * refuses to do is as important as what it does:
 *
 *   - It does not invent a number where Praat printed `--undefined--`. An
 *     undefined pitch on an unvoiced stretch is information; a zero in its
 *     place is a lie that survives into the record.
 *   - It does not certify a perturbation measure. Reliability is computed by
 *     `domain/measurement.ts` from the recording's conditions, and the classes
 *     whose published floors have not been ruled on come back marked.
 *   - It does not interpret. Nothing here says a pronunciation is right, a tone
 *     is correct, or a speaker is a speaker of anything.
 *
 * Every result carries its Praat version, the script, the script's hash, the
 * parameters, the hash of the bytes measured, and original-timeline
 * coordinates — the provenance ADR-038 requires.
 */

import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { promisify } from 'node:util';

import type { RenditionKind } from '../domain/renditions.js';
import type {
    Measurement,
    MeasurementClass,
    MeasurementProvenance,
    RecordingConditions,
} from '../domain/measurement.js';
import { measurement } from '../domain/measurement.js';
import type { VadSegment } from '../domain/segments.js';
import type { OriginalTimeRange } from '../domain/timeline.js';
import { offsetFromSeconds, originalTimeRange } from '../domain/timeline.js';
import type { ResolvedTool } from './tools.js';

const execFileAsync = promisify(execFile);

/** Praat's marker for a value it could not compute. */
const UNDEFINED = '--undefined--';

/**
 * Parameters for `measure.praat`.
 *
 * Named rather than positional at the call site, positional on the way to
 * Praat, and echoed back by the script so the values recorded in provenance are
 * the ones Praat actually used.
 */
export interface MeasureParameters {
    readonly fromSeconds: number;
    readonly toSeconds: number;
    readonly timeStep: number;
    readonly pitchFloor: number;
    readonly pitchCeiling: number;
    readonly maxFormants: number;
    readonly formantCeiling: number;
    readonly harmonicityTimeStep: number;
}

/**
 * Defaults for the measure script.
 *
 * These are analysis-window settings, not audio admissibility limits: they say
 * how the measurement is taken, not what material is acceptable. They answer
 * nothing in OQ-021, which is about the floors material must meet. A caller
 * tuning a pitch floor for a particular speaker overrides them, and whatever it
 * passes is what gets recorded.
 */
export const DEFAULT_MEASURE_PARAMETERS: MeasureParameters = Object.freeze({
    fromSeconds: 0,
    toSeconds: 0,
    timeStep: 0,
    pitchFloor: 75,
    pitchCeiling: 600,
    maxFormants: 5,
    formantCeiling: 5500,
    harmonicityTimeStep: 0.01,
});

/** Parameters for `segment.praat`. */
export interface SegmentParameters {
    readonly minimumPitch: number;
    readonly timeStep: number;
    readonly silenceThresholdDb: number;
    readonly minSilentInterval: number;
    readonly minSoundingInterval: number;
}

export const DEFAULT_SEGMENT_PARAMETERS: SegmentParameters = Object.freeze({
    minimumPitch: 100,
    timeStep: 0,
    silenceThresholdDb: -25,
    minSilentInterval: 0.1,
    minSoundingInterval: 0.1,
});

export class PraatWorkerError extends Error {
    public constructor(message: string, options?: ErrorOptions) {
        super(message, options);
        this.name = 'PraatWorkerError';
    }
}

/** Parse Praat's `key=value` block. Values stay strings; conversion is separate. */
export function parseKeyValueBlock(stdout: string): Map<string, string[]> {
    const out = new Map<string, string[]>();
    for (const line of stdout.split('\n')) {
        const trimmed = line.trim();
        if (trimmed === '') {
            continue;
        }
        const separator = trimmed.indexOf('=');
        if (separator <= 0) {
            continue;
        }
        const key = trimmed.slice(0, separator);
        const value = trimmed.slice(separator + 1);
        const existing = out.get(key);
        if (existing === undefined) {
            out.set(key, [value]);
        } else {
            existing.push(value);
        }
    }
    return out;
}

/**
 * Read one numeric value.
 *
 * `--undefined--` becomes `null` and stays null all the way into the record.
 * Anything else unparseable is an error rather than a null, because a null that
 * means "Praat could not compute this" and a null that means "the worker could
 * not read the output" are different facts.
 */
export function readNumber(block: Map<string, string[]>, key: string): number | null {
    const values = block.get(key);
    if (values === undefined || values[0] === undefined) {
        return null;
    }
    const raw = values[0].trim();
    if (raw === UNDEFINED || raw === '') {
        return null;
    }
    const parsed = Number(raw);
    if (!Number.isFinite(parsed)) {
        throw new PraatWorkerError(
            `Praat reported '${raw}' for ${key}, which is neither a number nor ${UNDEFINED}.`,
        );
    }
    return parsed;
}

async function runPraat(
    praat: ResolvedTool,
    scriptPath: string,
    args: readonly (string | number)[],
): Promise<string> {
    try {
        const { stdout } = await execFileAsync(
            praat.executable,
            ['--run', scriptPath, ...args.map(String)],
            { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 },
        );
        return stdout;
    } catch (cause) {
        const failure = cause as { stderr?: string; stdout?: string };
        throw new PraatWorkerError(
            `Praat script ${scriptPath} did not complete: ${(failure.stderr ?? '').trim() || String(cause)}`,
            { cause },
        );
    }
}

async function sha256OfFile(path: string): Promise<string> {
    const bytes = await readFile(path);
    return createHash('sha256').update(bytes).digest('hex');
}

async function sha256OfText(path: string): Promise<string> {
    const text = await readFile(path, 'utf8');
    return createHash('sha256').update(text).digest('hex');
}

/** What the measure script reports about the signal it read. */
export interface SignalReport {
    readonly totalDurationSeconds: number;
    readonly samplingFrequencyHz: number;
    readonly channels: number;
    readonly voicedFrames: number;
    readonly totalFrames: number;
    readonly rootMeanSquare: number | null;
    readonly dcOffset: number | null;
    readonly absolutePeak: number | null;
}

export interface MeasureResult {
    readonly signal: SignalReport;
    readonly measurements: readonly Measurement[];
    /** The version Praat reported for this run, not the one configured. */
    readonly praatVersion: string;
}

interface MeasurementSpec {
    readonly key: string;
    readonly measurementClass: MeasurementClass;
    readonly unit: string;
}

/**
 * Which reported keys become stored measurements, and as what.
 *
 * Several Praat outputs — `stdevF0`, `minF0`, `maxF0`, the per-frame counts —
 * are reported in {@link SignalReport} instead. They describe the analysis
 * rather than measuring the signal at a point, and mixing the two would put
 * values in the measurement record that have no range of their own.
 */
const MEASUREMENT_SPECS: readonly MeasurementSpec[] = [
    { key: 'meanF0', measurementClass: 'pitch-f0', unit: 'Hz' },
    { key: 'meanIntensity', measurementClass: 'intensity', unit: 'dB' },
    { key: 'meanHarmonicity', measurementClass: 'harmonicity', unit: 'dB' },
    { key: 'meanF1', measurementClass: 'formant', unit: 'Hz' },
    { key: 'meanF2', measurementClass: 'formant', unit: 'Hz' },
    { key: 'meanF3', measurementClass: 'formant', unit: 'Hz' },
    { key: 'jitterLocal', measurementClass: 'jitter', unit: 'ratio' },
    { key: 'shimmerLocal', measurementClass: 'shimmer', unit: 'ratio' },
    { key: 'dcOffset', measurementClass: 'dc-offset', unit: 'amplitude' },
    { key: 'absolutePeak', measurementClass: 'true-peak', unit: 'amplitude' },
];

/**
 * Measure one recording, or one range of it.
 *
 * @param praat A tool resolved for a job class — pinned, version-checked and
 *              licence-attested. Resolution is not done here so that a caller
 *              cannot skip it.
 * @param scriptPath Path to `praat/measure.praat`.
 * @param audioPath The decoded, timeline-preserving rendition to measure.
 * @param measuredRendition Which rung of the ladder that path is, recorded in
 *                          provenance so a reader knows what was measured.
 * @param signalToNoiseDb The recording's SNR where it is known, for the
 *                        reliability verdict. Null means it was not measured,
 *                        which is itself a reason to mark a class unreliable.
 */
export async function measureWithPraat(
    praat: ResolvedTool,
    scriptPath: string,
    audioPath: string,
    measuredRendition: RenditionKind,
    parameters: MeasureParameters = DEFAULT_MEASURE_PARAMETERS,
    signalToNoiseDb: number | null = null,
): Promise<MeasureResult> {
    const stdout = await runPraat(praat, scriptPath, [
        audioPath,
        parameters.fromSeconds,
        parameters.toSeconds,
        parameters.timeStep,
        parameters.pitchFloor,
        parameters.pitchCeiling,
        parameters.maxFormants,
        parameters.formantCeiling,
        parameters.harmonicityTimeStep,
    ]);

    const block = parseKeyValueBlock(stdout);
    const praatVersion = block.get('praatVersion')?.[0]?.trim() ?? '';
    if (praatVersion === '') {
        throw new PraatWorkerError(
            'The measure script reported no Praat version. Provenance without a tool version is not reproducible (ADR-038).',
        );
    }

    const totalDurationSeconds = readNumber(block, 'totalDuration');
    const analysisFrom = readNumber(block, 'analysisFrom') ?? 0;
    const analysisTo = readNumber(block, 'analysisTo') ?? totalDurationSeconds ?? 0;
    if (totalDurationSeconds === null) {
        throw new PraatWorkerError('The measure script reported no duration for the input.');
    }

    const voicedFrames = readNumber(block, 'voicedFrames') ?? 0;

    const signal: SignalReport = {
        totalDurationSeconds,
        samplingFrequencyHz: readNumber(block, 'samplingFrequency') ?? 0,
        channels: readNumber(block, 'channels') ?? 0,
        voicedFrames,
        totalFrames: readNumber(block, 'totalFrames') ?? 0,
        rootMeanSquare: readNumber(block, 'rootMeanSquare'),
        dcOffset: readNumber(block, 'dcOffset'),
        absolutePeak: readNumber(block, 'absolutePeak'),
    };

    const range: OriginalTimeRange = originalTimeRange(
        offsetFromSeconds(analysisFrom),
        offsetFromSeconds(analysisTo),
    );

    const conditions: RecordingConditions = {
        signalToNoiseDb,
        // Praat's own voiced-frame count, not an assumption. Where it is zero,
        // every class that needs voiced speech comes back marked unreliable
        // rather than reporting a number computed over silence.
        hasVoicedSpeech: voicedFrames > 0,
    };

    // Read the parameters back from the script's own echo. Recording what was
    // sent rather than what was used would hide a script that defaulted or
    // clamped a value.
    const echoed: Record<string, string | number | boolean> = {};
    for (const [key, values] of block) {
        if (key.startsWith('param') && values[0] !== undefined) {
            echoed[key] = values[0];
        }
    }

    const scriptSha256 = await sha256OfText(scriptPath);
    const measuredSha256 = await sha256OfFile(audioPath);

    const provenance: MeasurementProvenance = {
        tool: { name: praat.name, version: praatVersion },
        script: scriptPath,
        scriptSha256,
        parameters: echoed,
        measuredSha256,
        measuredRendition,
    };

    const measurements: Measurement[] = [
        measurement({
            measurementClass: 'duration',
            value: totalDurationSeconds * 1000,
            unit: 'ms',
            range,
            conditions,
            provenance,
        }),
    ];

    for (const spec of MEASUREMENT_SPECS) {
        const value = readNumber(block, spec.key);
        measurements.push(
            measurement({
                measurementClass: spec.measurementClass,
                value,
                unit: spec.unit,
                range,
                conditions,
                // The reported key travels with the measurement: three formant
                // means share one class, and without this a reader could not
                // tell F1 from F3.
                provenance: {
                    ...provenance,
                    parameters: { ...provenance.parameters, praatKey: spec.key },
                },
            }),
        );
    }

    return { signal, measurements, praatVersion };
}

export interface SegmentResult {
    readonly segments: readonly VadSegment[];
    readonly totalDurationSeconds: number;
    readonly praatVersion: string;
    readonly parameters: Readonly<Record<string, string>>;
}

/**
 * Compute speech/silence boundaries of record.
 *
 * Runs for every recording, language and device tier (ADR-038). It is not
 * conditional on a live transcript having been available in the browser, and no
 * capture-side provider substitutes for it.
 */
export async function segmentWithPraat(
    praat: ResolvedTool,
    scriptPath: string,
    audioPath: string,
    parameters: SegmentParameters = DEFAULT_SEGMENT_PARAMETERS,
): Promise<SegmentResult> {
    const stdout = await runPraat(praat, scriptPath, [
        audioPath,
        parameters.minimumPitch,
        parameters.timeStep,
        parameters.silenceThresholdDb,
        parameters.minSilentInterval,
        parameters.minSoundingInterval,
    ]);

    const block = parseKeyValueBlock(stdout);
    const praatVersion = block.get('praatVersion')?.[0]?.trim() ?? '';
    if (praatVersion === '') {
        throw new PraatWorkerError('The segment script reported no Praat version.');
    }

    const totalDurationSeconds = readNumber(block, 'totalDuration');
    if (totalDurationSeconds === null) {
        throw new PraatWorkerError('The segment script reported no duration for the input.');
    }

    const segments: VadSegment[] = [];
    for (const line of block.get('interval') ?? []) {
        const [label, start, end] = line.split('|');
        if (label === undefined || start === undefined || end === undefined) {
            throw new PraatWorkerError(`Malformed interval line from the segment script: ${line}`);
        }
        segments.push({
            kind: label.trim() === 'speech' ? 'speech' : 'silence',
            range: originalTimeRange(
                offsetFromSeconds(Number(start)),
                offsetFromSeconds(Number(end)),
            ),
            // Praat's silence detector reports boundaries, not a per-interval
            // confidence. Null says so; a fabricated 1.0 would read as certainty.
            confidence: null,
        });
    }

    const echoed: Record<string, string> = {};
    for (const [key, values] of block) {
        if (key.startsWith('param') && values[0] !== undefined) {
            echoed[key] = values[0];
        }
    }

    return { segments, totalDurationSeconds, praatVersion, parameters: echoed };
}
