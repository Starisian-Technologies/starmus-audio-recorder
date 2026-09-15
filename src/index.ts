/**
 * The Spoken Audio Node.
 *
 * This repository is the Spoken Audio Node under ADR-034 and ADR-038: the
 * platform audio asset lifecycle, as a Node/TypeScript service. It owns ingest
 * acceptance and integrity, storage references, server-side derivative
 * processing, acoustic measurement, bulk import, and authorized consumption.
 *
 * What it does not own, and why, is in `.github/instructions/starmus-boundary.md`.
 * The short version: the microphone and the upload client are the capture UI
 * package's; transcript, translation and linguistic interpretation are ESU's
 * records; transport and temporary-URL issuance are the media ingest service's.
 *
 * The composition root takes every port as an argument and provides no
 * defaults. That is deliberate. The capture→ingestion contract records four
 * terms as still owed — the consumer's endpoint path and auth model, the upload
 * metadata key set, the acknowledgement and error envelope, and confirmation of
 * the checksum algorithm — and states that no repository implements a guess at
 * them. A default here would be that guess, shipped.
 */

export * from './domain/asset.js';
export * from './domain/captureProfile.js';
export * from './domain/ids.js';
export * from './domain/manifest.js';
export * from './domain/measurement.js';
export * from './domain/quality.js';
export * from './domain/renditions.js';
export * from './domain/segments.js';
export * from './domain/timeline.js';

export * from './ports/index.js';

export * from './analysis/derivative.js';
export * from './analysis/praatWorker.js';
export * from './analysis/textgrid.js';
export * from './analysis/tools.js';

export * from './ingest/acceptance.js';
export * from './access/authorization.js';
export * from './preservation/immutability.js';
export * from './release/rendering.js';

import { grantTemporaryAccess } from './access/authorization.js';
import type { GrantRequest } from './access/authorization.js';
import { prepareAnalysisDerivative } from './analysis/derivative.js';
import type { DerivativeOptions } from './analysis/derivative.js';
import {
    DEFAULT_MEASURE_PARAMETERS,
    DEFAULT_SEGMENT_PARAMETERS,
    measureWithPraat,
    segmentWithPraat,
} from './analysis/praatWorker.js';
import { loadPinnedToolset, resolveTool } from './analysis/tools.js';
import type { PinnedToolset } from './analysis/tools.js';
import { acceptUpload } from './ingest/acceptance.js';
import type { IncomingUpload } from './ingest/acceptance.js';
import type { AudioAsset, Rendition } from './domain/asset.js';
import { analysisSource, storageRef } from './domain/asset.js';
import { newDerivativeId } from './domain/ids.js';
import { assertUsableForAnalysis } from './preservation/immutability.js';
import { candidatesFromSegments, speechRatio, withExplicitSilence } from './domain/segments.js';
import { originalTimeRange } from './domain/timeline.js';
import type { AnnotationCandidate, VadSegment } from './domain/segments.js';
import type { Measurement } from './domain/measurement.js';
import type {
    AccessPolicy,
    AssetRepository,
    Clock,
    JobQueue,
    IntakePublisher,
    MediaTransport,
    TemporaryAccessGrant,
} from './ports/index.js';

/** Everything the service needs, all of it injected. */
export interface SpokenAudioNodeDependencies {
    readonly transport: MediaTransport;
    readonly assets: AssetRepository;
    readonly queue: JobQueue;
    readonly intake: IntakePublisher;
    readonly accessPolicy: AccessPolicy;
    readonly clock: Clock;
    /** Path to the pinned toolset, read once at construction. */
    readonly pinnedToolsetPath: string;
    /** Directory holding `measure.praat` and `segment.praat`. */
    readonly praatScriptDirectory: string;
    /** Directory for intermediate files. Nothing durable is written here. */
    readonly workDirectory: string;
}

export interface AnalysisOutcome {
    readonly measurements: readonly Measurement[];
    readonly segments: readonly VadSegment[];
    readonly candidates: readonly AnnotationCandidate[];
    readonly speechRatio: number;
    /** The Praat version that actually ran, from Praat's own output. */
    readonly praatVersion: string;
}

/**
 * The service.
 *
 * Thin by design: the rules live in `domain/` and `preservation/` where they can
 * be tested without a filesystem, and this class wires them to the ports.
 */
export class SpokenAudioNode {
    private toolset: PinnedToolset | null = null;

    public constructor(private readonly deps: SpokenAudioNodeDependencies) {}

    /**
     * Load and hold the pinned toolset.
     *
     * Called once, and every tool resolution re-probes the version, so a tool
     * that changes under a long-running process is caught at the next job
     * rather than at the next restart.
     */
    private async pinned(): Promise<PinnedToolset> {
        this.toolset ??= await loadPinnedToolset(this.deps.pinnedToolsetPath);
        return this.toolset;
    }

    /**
     * Accept an upload, register the original, and queue the work that follows.
     *
     * Registration is the moment the recording stops being a draft: before it,
     * retake and discard belong to the capture experience; after it, the
     * original is immutable evidence (ADR-039).
     */
    public async registerUpload(upload: IncomingUpload): Promise<AudioAsset> {
        const { asset } = await acceptUpload(upload, this.deps.clock.nowIso());

        // Registered and enqueued as one atomic unit, not two calls.
        //
        // Doing it in two stranded an asset permanently whenever the second
        // failed: the original was accepted and immutable, no work was queued
        // for it, and nothing retried — the recording reached the platform and
        // then stopped existing as far as processing was concerned, with
        // nothing recording that it was owed a job. `JobQueue.registerAndEnqueue`
        // makes that the adapter's problem to solve transactionally, and
        // requires it to throw rather than approximate.
        //
        // Queued rather than run inline. Processing tolerates day-scale delay,
        // and holding an upload open while ffmpeg and Praat run would put the
        // contributor's connection on the critical path of work they are not
        // waiting for.
        await this.deps.queue.registerAndEnqueue(asset, {
            jobClass: 'prepare-analysis-derivative',
            assetId: asset.id,
            payload: {},
        });

        return asset;
    }

    /**
     * Produce the standard analysis derivative and record it against the asset.
     */
    public async prepareDerivative(
        asset: AudioAsset,
        options: DerivativeOptions = {},
    ): Promise<Rendition> {
        const toolset = await this.pinned();
        const jobClass = 'prepare-analysis-derivative';
        const [ffmpeg, ffprobe] = await Promise.all([
            resolveTool(toolset, jobClass, 'ffmpeg'),
            resolveTool(toolset, jobClass, 'ffprobe'),
        ]);

        const sourcePath = `${this.deps.workDirectory}/${asset.id}.source`;
        const outputPath = `${this.deps.workDirectory}/${asset.id}.analysis.wav`;
        await this.deps.transport.fetchToLocalPath(asset.original.storage, sourcePath);

        const prepared = await prepareAnalysisDerivative(
            ffmpeg,
            ffprobe,
            sourcePath,
            outputPath,
            options,
            this.deps.clock.nowIso(),
        );

        const stored = await this.deps.transport.store(prepared.outputPath, 'audio/wav');
        const rendition: Rendition = {
            id: newDerivativeId(),
            kind: 'analysis-standard',
            storage: storageRef(stored.value),
            sha256: prepared.manifest.outputSha256,
            format: prepared.format,
            manifest: prepared.manifest,
        };

        await this.deps.assets.appendRendition(asset.id, rendition);
        return rendition;
    }

    /**
     * Measure and segment an asset.
     *
     * The rendition consumed is the standard analysis derivative when one
     * exists and the original otherwise — never the enhanced derivative, which
     * `assertUsableForAnalysis` refuses by name so the refusal is legible in a
     * stack trace rather than silent in a branch.
     */
    public async analyse(
        asset: AudioAsset,
        localAudioPath: string,
        signalToNoiseDb: number | null = null,
    ): Promise<AnalysisOutcome> {
        const toolset = await this.pinned();
        const source = analysisSource(asset);
        assertUsableForAnalysis(source, 'acoustic measurement');

        const measurePraat = await resolveTool(toolset, 'acoustic-measure', 'praat');
        const segmentPraat = await resolveTool(toolset, 'segment', 'praat');

        const measured = await measureWithPraat(
            measurePraat,
            `${this.deps.praatScriptDirectory}/measure.praat`,
            localAudioPath,
            source.kind,
            DEFAULT_MEASURE_PARAMETERS,
            signalToNoiseDb,
        );

        const segmented = await segmentWithPraat(
            segmentPraat,
            `${this.deps.praatScriptDirectory}/segment.praat`,
            localAudioPath,
            DEFAULT_SEGMENT_PARAMETERS,
        );

        const durationMs = Math.round(segmented.totalDurationSeconds * 1000);
        const segments = withExplicitSilence(segmented.segments, durationMs, (start, end) =>
            originalTimeRange(start, end),
        );

        await this.deps.assets.appendMeasurements(asset.id, measured.measurements);
        await this.deps.assets.recordSegments(asset.id, segments);

        return {
            measurements: measured.measurements,
            segments,
            candidates: candidatesFromSegments(segments),
            speechRatio: speechRatio(segments, durationMs),
            praatVersion: measured.praatVersion,
        };
    }

    /** Authorize a consumer and obtain a short-lived URL from the transport. */
    public async accessAsset(
        asset: AudioAsset,
        request: GrantRequest,
    ): Promise<TemporaryAccessGrant> {
        return grantTemporaryAccess(this.deps.accessPolicy, this.deps.transport, asset, request);
    }
}
