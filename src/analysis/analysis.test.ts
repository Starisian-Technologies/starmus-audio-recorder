/**
 * The analysis worker, against the real pinned tools.
 *
 * These are integration tests on purpose. A mocked Praat would prove the parser
 * matches the mock; what has to be true is that the scripts in `praat/` produce
 * the values this service records, from audio, at the pinned version.
 *
 * Fixtures are synthesised with ffmpeg so the expected values are known rather
 * than asserted from a golden file: a 220 Hz tone has an F0 of 220 Hz, and
 * silence has no pitch at all.
 */

import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test, { after, before } from 'node:test';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

import { candidatesFromSegments, withExplicitSilence } from '../domain/segments.js';
import { originalTimeRange } from '../domain/timeline.js';
import { prepareAnalysisDerivative, probeSource } from './derivative.js';
import { measureWithPraat, parseKeyValueBlock, readNumber, segmentWithPraat } from './praatWorker.js';
import { toTextGrid } from './textgrid.js';
import { loadPinnedToolset, resolveTool } from './tools.js';
import type { PinnedToolset, ResolvedTool } from './tools.js';

const execFileAsync = promisify(execFile);

const repoRoot = fileURLToPath(new URL('../../', import.meta.url));
const toolsetPath = join(repoRoot, 'tools.pinned.json');
const praatDir = join(repoRoot, 'praat');

let work = '';
let toolset: PinnedToolset;
let praat: ResolvedTool;
let ffmpeg: ResolvedTool;
let ffprobe: ResolvedTool;

/** A mono 220 Hz tone: voiced, with a pitch we already know. */
let tonePath = '';
/** Digital silence: nothing for a pitch tracker to find. */
let silencePath = '';
/** Tone, silence, tone — so the segmenter has a boundary to find. */
let gappedPath = '';
/** Stereo, to prove the derivative does not fold down. */
let stereoPath = '';

async function ffmpegFixture(args: readonly string[], name: string): Promise<string> {
    const path = join(work, name);
    await execFileAsync('ffmpeg', ['-nostdin', '-v', 'error', '-y', ...args, path]);
    return path;
}

before(async () => {
    work = await mkdtemp(join(tmpdir(), 'starmus-analysis-'));
    toolset = await loadPinnedToolset(toolsetPath);
    praat = await resolveTool(toolset, 'acoustic-measure', 'praat');
    ffmpeg = await resolveTool(toolset, 'prepare-analysis-derivative', 'ffmpeg');
    ffprobe = await resolveTool(toolset, 'prepare-analysis-derivative', 'ffprobe');

    tonePath = await ffmpegFixture(
        ['-f', 'lavfi', '-i', 'sine=frequency=220:duration=2:sample_rate=44100', '-ac', '1'],
        'tone.wav',
    );
    silencePath = await ffmpegFixture(
        ['-f', 'lavfi', '-i', 'anullsrc=r=16000:cl=mono', '-t', '1.5'],
        'silence.wav',
    );
    gappedPath = await ffmpegFixture(
        [
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=180:duration=1:sample_rate=16000',
            '-f',
            'lavfi',
            '-i',
            'anullsrc=r=16000:cl=mono',
            '-filter_complex',
            '[0:a]atrim=0:1[a];[1:a]atrim=0:0.8[s];[a][s][a]concat=n=3:v=0:a=1',
            '-ac',
            '1',
        ],
        'gapped.wav',
    );
    stereoPath = await ffmpegFixture(
        [
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=220:duration=1:sample_rate=48000',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=330:duration=1:sample_rate=48000',
            '-filter_complex',
            '[0:a][1:a]join=inputs=2:channel_layout=stereo',
        ],
        'stereo.wav',
    );
});

after(async () => {
    if (work !== '') {
        await rm(work, { recursive: true, force: true });
    }
});

/* ---- parsing ---- */

void test("Praat's --undefined-- becomes null, and nothing else does", () => {
    const block = parseKeyValueBlock('meanF0=--undefined--\nmeanIntensity=72.9\nnoValue=\n');
    assert.equal(readNumber(block, 'meanF0'), null);
    assert.equal(readNumber(block, 'meanIntensity'), 72.9);
    assert.equal(readNumber(block, 'noValue'), null);
    assert.equal(readNumber(block, 'absent'), null);
});

void test('an unparseable value is an error, not a quiet null', () => {
    const block = parseKeyValueBlock('meanF0=not-a-number\n');
    assert.throws(() => readNumber(block, 'meanF0'), /neither a number nor --undefined--/);
});

/* ---- pinning ---- */

void test('a tool not pinned for a job class does not resolve', async () => {
    await assert.rejects(
        () => resolveTool(toolset, 'acoustic-measure', 'ffmpeg'),
        /is not pinned to 'ffmpeg'/,
    );
});

void test('an unknown job class does not resolve', async () => {
    await assert.rejects(
        () => resolveTool(toolset, 'not-a-job-class', 'praat'),
        /declares no pinned tools/,
    );
});

void test('every resolved tool carries a licence read from its own distribution', () => {
    for (const tool of [praat, ffmpeg, ffprobe]) {
        assert.notEqual(tool.licenseAttestation.license.trim(), '');
        assert.match(tool.licenseAttestation.readFrom, /copyright/);
    }
});

/* ---- measurement ---- */

void test('a 220 Hz tone measures as 220 Hz', async () => {
    const result = await measureWithPraat(
        praat,
        join(praatDir, 'measure.praat'),
        tonePath,
        'original',
    );

    const f0 = result.measurements.find(
        (candidate) => candidate.measurementClass === 'pitch-f0',
    );
    assert.ok(f0, 'pitch was measured');
    assert.ok(f0.value !== null, 'a voiced tone has a pitch');
    assert.ok(Math.abs(f0.value - 220) < 1, `expected ~220 Hz, measured ${f0.value}`);
    assert.equal(f0.unit, 'Hz');
    assert.equal(result.signal.voicedFrames > 0, true);
});

void test('every measurement carries the Praat version, the script, its hash and the parameters', async () => {
    const result = await measureWithPraat(
        praat,
        join(praatDir, 'measure.praat'),
        tonePath,
        'analysis-standard',
    );

    assert.equal(result.praatVersion, praat.reportedVersion);
    for (const candidate of result.measurements) {
        assert.equal(candidate.provenance.tool.name, 'praat');
        assert.equal(candidate.provenance.tool.version, praat.reportedVersion);
        assert.match(candidate.provenance.script ?? '', /measure\.praat$/);
        assert.match(candidate.provenance.scriptSha256 ?? '', /^[0-9a-f]{64}$/);
        assert.match(candidate.provenance.measuredSha256, /^[0-9a-f]{64}$/);
        assert.equal(candidate.provenance.measuredRendition, 'analysis-standard');
        assert.ok(
            Object.keys(candidate.provenance.parameters).length > 0,
            'parameters travel with the measurement',
        );
        assert.equal(
            candidate.provenance.parameters['paramPitchFloor'],
            '75',
            'the parameters recorded are the ones the script echoed back',
        );
    }
});

void test('silence yields null measurements, never zeros', async () => {
    const result = await measureWithPraat(
        praat,
        join(praatDir, 'measure.praat'),
        silencePath,
        'original',
    );

    const f0 = result.measurements.find(
        (candidate) => candidate.measurementClass === 'pitch-f0',
    );
    assert.equal(f0?.value, null, 'no pitch is null, not 0');
    assert.equal(result.signal.voicedFrames, 0);

    const duration = result.measurements.find(
        (candidate) => candidate.measurementClass === 'duration',
    );
    assert.ok(duration?.value !== null && duration !== undefined);
});

void test('a class needing voiced speech is marked unreliable over silence', async () => {
    const result = await measureWithPraat(
        praat,
        join(praatDir, 'measure.praat'),
        silencePath,
        'original',
    );
    const formant = result.measurements.find(
        (candidate) => candidate.measurementClass === 'formant',
    );
    assert.equal(formant?.reliability.reliable, false);
});

void test('perturbation measures come back marked even on a clean signal', async () => {
    const result = await measureWithPraat(
        praat,
        join(praatDir, 'measure.praat'),
        tonePath,
        'original',
        undefined,
        60,
    );
    for (const measurementClass of ['jitter', 'shimmer', 'harmonicity'] as const) {
        const found = result.measurements.find(
            (candidate) => candidate.measurementClass === measurementClass,
        );
        assert.equal(found?.reliability.reliable, false, `${measurementClass} must not be certified`);
    }
});

/* ---- segmentation ---- */

void test('tone / silence / tone segments into speech, silence, speech', async () => {
    const segmentTool = await resolveTool(toolset, 'segment', 'praat');
    const result = await segmentWithPraat(
        segmentTool,
        join(praatDir, 'segment.praat'),
        gappedPath,
    );

    assert.equal(result.praatVersion, praat.reportedVersion);
    assert.deepEqual(
        result.segments.map((segment) => segment.kind),
        ['speech', 'silence', 'speech'],
    );

    const silence = result.segments[1];
    assert.ok(silence);
    assert.ok(silence.range.startMs > 900 && silence.range.startMs < 1200);
    assert.ok(silence.range.endMs > 1600 && silence.range.endMs < 1900);
    assert.equal(silence.confidence, null, 'a boundary detector reports no confidence');
});

void test('segments tile the recording once silence is made explicit', async () => {
    const segmentTool = await resolveTool(toolset, 'segment', 'praat');
    const result = await segmentWithPraat(
        segmentTool,
        join(praatDir, 'segment.praat'),
        gappedPath,
    );
    const durationMs = Math.round(result.totalDurationSeconds * 1000);
    const filled = withExplicitSilence(result.segments, durationMs, (start, end) =>
        originalTimeRange(start, end),
    );

    assert.equal(filled[0]?.range.startMs, 0);
    assert.equal(filled[filled.length - 1]?.range.endMs, durationMs);
    for (let i = 1; i < filled.length; i += 1) {
        assert.equal(filled[i]?.range.startMs, filled[i - 1]?.range.endMs, 'no holes');
    }
});

/* ---- derivative ---- */

void test('the analysis derivative preserves channels and duration, and is reproducible', async () => {
    const first = join(work, 'stereo.analysis.wav');
    const second = join(work, 'stereo.analysis.again.wav');

    const a = await prepareAnalysisDerivative(ffmpeg, ffprobe, stereoPath, first, {}, 'T1');
    const b = await prepareAnalysisDerivative(ffmpeg, ffprobe, stereoPath, second, {}, 'T2');

    assert.equal(a.format.channels, 2, 'a stereo source does not fold down to mono');
    assert.equal(a.manifest.timelinePreserving, true);
    assert.equal(a.manifest.deterministic, true);
    assert.equal(
        a.manifest.outputSha256,
        b.manifest.outputSha256,
        'two runs over the same source produce the same bytes',
    );
    assert.ok(a.manifest.tools.some((tool) => tool.name === 'ffmpeg' && tool.version !== ''));

    const source = await probeSource(ffprobe, stereoPath);
    assert.ok(source.format.durationMs !== null && a.format.durationMs !== null);
    assert.ok(Math.abs(a.format.durationMs - source.format.durationMs) <= 50);
});

void test('the derivative refuses to downsample', async () => {
    await assert.rejects(
        () =>
            prepareAnalysisDerivative(
                ffmpeg,
                ffprobe,
                tonePath,
                join(work, 'downsampled.wav'),
                { resampleHz: 8000 },
                'T',
            ),
        /Refusing to downsample/,
    );
});

void test('upsampling is permitted and recorded as a step', async () => {
    const prepared = await prepareAnalysisDerivative(
        ffmpeg,
        ffprobe,
        tonePath,
        join(work, 'upsampled.wav'),
        { resampleHz: 48000 },
        'T',
    );
    assert.equal(prepared.format.sampleRateHz, 48000);
    assert.ok(prepared.manifest.steps.some((step) => step.step === 'resample'));
});

/* ---- TextGrid export ---- */

void test('candidates export as a TextGrid Praat can read back', async () => {
    const segmentTool = await resolveTool(toolset, 'segment', 'praat');
    const result = await segmentWithPraat(
        segmentTool,
        join(praatDir, 'segment.praat'),
        gappedPath,
    );
    const durationMs = Math.round(result.totalDurationSeconds * 1000);
    const filled = withExplicitSilence(result.segments, durationMs, (start, end) =>
        originalTimeRange(start, end),
    );

    const textgrid = toTextGrid(candidatesFromSegments(filled), {
        recordingDurationMs: durationMs,
    });

    const path = join(work, 'candidates.TextGrid');
    await writeFile(path, textgrid, 'utf8');

    // Praat reading the file back is the only check that matters: a TextGrid
    // that only this repository can parse is not TextGrid-compatible.
    const script = join(work, 'readback.praat');
    await writeFile(
        script,
        [
            'form Readback',
            '  sentence gridPath',
            'endform',
            'Read from file: gridPath$',
            'tiers = Get number of tiers',
            'intervals = Get number of intervals: 1',
            'appendInfoLine: "tiers=", tiers',
            'appendInfoLine: "intervals=", intervals',
            '',
        ].join('\n'),
        'utf8',
    );

    const { stdout } = await execFileAsync('praat', ['--run', script, path], { encoding: 'utf8' });
    const block = parseKeyValueBlock(stdout);
    assert.equal(readNumber(block, 'tiers'), 1);
    assert.equal(readNumber(block, 'intervals'), filled.length);

    const written = await readFile(path, 'utf8');
    assert.match(written, /Object class = "TextGrid"/);
    assert.match(written, /text = "silence"/, 'silence is exported, not dropped');
});

void test('a tier with a hole is refused rather than silently patched', () => {
    assert.throws(
        () =>
            toTextGrid(
                [
                    {
                        tier: 't',
                        range: originalTimeRange(0, 1000),
                        label: 'speech',
                        authority: 'machine-candidate',
                    },
                    {
                        tier: 't',
                        range: originalTimeRange(1500, 2000),
                        label: 'speech',
                        authority: 'machine-candidate',
                    },
                ],
                { recordingDurationMs: 2000 },
            ),
        /gap or overlap/,
    );
});
