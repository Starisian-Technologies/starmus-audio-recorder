/**
 * The ports this service consumes.
 *
 * Every one is an interface with no default implementation, and the composition
 * root requires all of them. That is not architectural taste: ADR-034's
 * contracts record that the consumer's endpoint path and auth model, the upload
 * metadata key set, the acknowledgement and error envelope, and the checksum
 * algorithm are all **owed** rather than agreed — and that "no repository
 * implements a guess at items 1–4". A port with a default would be that guess,
 * shipped.
 *
 * The same holds for the intake seam. ADR-038 lists what the intake event must
 * provide; OQ-022 has not settled which repository holds the schema. So
 * {@link IntakePublisher} carries the domain event and the adapter that
 * eventually maps it to the wire lives behind the contract, once there is one.
 */

import type { AudioAsset, Rendition, StorageRef } from '../domain/asset.js';
import type { AudioAssetId, IntakeEventId, JobId } from '../domain/ids.js';
import type { Measurement } from '../domain/measurement.js';
import type { ProcessingRoute, QualityFlag } from '../domain/quality.js';
import type { VadSegment } from '../domain/segments.js';

/* ------------------------------------------------------------------ *
 * Media transport
 * ------------------------------------------------------------------ */

/** Why a short-lived URL was requested, recorded for audit. */
export interface AccessPurpose {
    readonly consumer: string;
    readonly reason: string;
}

/** A grant, as issued by the transport under this service's authorization. */
export interface TemporaryAccessGrant {
    readonly url: string;
    readonly expiresAt: string;
}

/**
 * The standalone, media-type-agnostic ingest service.
 *
 * ADR-038's split: **this service authorizes access; the transport performs
 * storage operations and issues the requested temporary URL.** Nothing in this
 * repository implements transport, and nothing stores the URL that comes back.
 */
export interface MediaTransport {
    /** Fetch stored bytes to a local path for processing. */
    fetchToLocalPath(ref: StorageRef, localPath: string): Promise<void>;

    /** Store bytes produced here and return the opaque handle for them. */
    store(localPath: string, contentType: string): Promise<StorageRef>;

    /**
     * Ask the transport for a short-lived URL.
     *
     * The grant is returned to the caller and never persisted. A caller that
     * stores it has put a storage URL in a record, which ADR-038 forbids.
     */
    requestTemporaryUrl(ref: StorageRef, purpose: AccessPurpose): Promise<TemporaryAccessGrant>;
}

/* ------------------------------------------------------------------ *
 * Persistence
 * ------------------------------------------------------------------ */

export interface AssetRepository {
    get(id: AudioAssetId): Promise<AudioAsset | null>;
    /** Register a new asset. Fails if the id already exists — originals are never replaced. */
    register(asset: AudioAsset): Promise<void>;
    /** Append a derived rendition. Never replaces one already recorded. */
    appendRendition(id: AudioAssetId, rendition: Rendition): Promise<void>;
    appendMeasurements(id: AudioAssetId, measurements: readonly Measurement[]): Promise<void>;
    recordSegments(id: AudioAssetId, segments: readonly VadSegment[]): Promise<void>;
    recordQuality(
        id: AudioAssetId,
        flags: readonly QualityFlag[],
        route: ProcessingRoute,
    ): Promise<void>;
}

/* ------------------------------------------------------------------ *
 * Delay-tolerant processing
 * ------------------------------------------------------------------ */

/**
 * Job classes.
 *
 * `release-render` is listed alongside the others and handled nowhere near
 * them: it is the walled-off class from ADR-039, and the queue keeps it
 * distinguishable so its outputs can be refused entry to the linguistic
 * pipeline by class rather than by inspection.
 */
export type JobClass =
    | 'intake-measure'
    | 'prepare-analysis-derivative'
    | 'acoustic-measure'
    | 'segment'
    | 'playback-derivative'
    | 'waveform-data'
    | 'bulk-import'
    | 'release-render';

export interface Job {
    readonly id: JobId;
    readonly jobClass: JobClass;
    readonly assetId: AudioAssetId;
    readonly attempt: number;
    readonly payload: Readonly<Record<string, unknown>>;
}

/**
 * A queue that tolerates day-scale delays.
 *
 * The platform's material arrives over links that are down for hours and
 * processing that waits a day is normal. Nothing here has a deadline; failures
 * are retried or held, never dropped.
 */
export interface JobQueue {
    enqueue(job: Omit<Job, 'id' | 'attempt'>): Promise<JobId>;
    /** Hold a job for later without consuming an attempt. */
    defer(id: JobId, reason: string): Promise<void>;
    /** Mark a job as needing a human, keeping its payload. */
    quarantine(id: JobId, reason: string): Promise<void>;
    complete(id: JobId): Promise<void>;
}

/* ------------------------------------------------------------------ *
 * Intake seam
 * ------------------------------------------------------------------ */

/**
 * The intake event, as a domain object.
 *
 * ADR-038 lists what the event contract must provide: stable event id, asset
 * id, idempotency key, event schema version, processing-profile version, source
 * and derivative hashes, retry/replay behaviour, ordering rules, duplicate
 * detection, and a failure/quarantine state. Those are **requirements, not
 * field names** — the concrete schema is authored once, with both builders, in
 * whichever repository OQ-022 names.
 *
 * So this is the shape the Node reasons in. Mapping it to the wire is the
 * adapter's job, and the adapter does not exist until the contract does.
 */
export interface IntakeEvent {
    readonly eventId: IntakeEventId;
    readonly assetId: AudioAssetId;
    /** Stable across retries of the same intake, so a replay is detectable. */
    readonly idempotencyKey: string;
    /** Version of the processing profile that produced this result. */
    readonly processingProfileVersion: string;
    readonly sourceSha256: string;
    readonly derivativeHashes: Readonly<Record<string, string>>;
    readonly qualityFlags: readonly QualityFlag[];
    readonly route: ProcessingRoute;
    readonly measurements: readonly Measurement[];
    readonly segments: readonly VadSegment[];
    /** Monotonic per asset, so a consumer can order events without a clock. */
    readonly sequence: number;
    readonly occurredAt: string;
}

/**
 * Publishes intake events to ESU.
 *
 * Carries no URL, by construction: there is no field for one above. ESU
 * requests temporary authorized access when it needs bytes.
 */
export interface IntakePublisher {
    publish(event: IntakeEvent): Promise<void>;
}

/* ------------------------------------------------------------------ *
 * Authorization
 * ------------------------------------------------------------------ */

export interface AccessRequest {
    readonly assetId: AudioAssetId;
    readonly consumer: string;
    readonly purpose: string;
    /** Region the request is served from, for regional access controls. */
    readonly region: string | null;
}

export type AccessDecision =
    | { readonly granted: true }
    | { readonly granted: false; readonly reason: string };

/**
 * Decides who may hear what.
 *
 * ADR-039: redaction restricts access; it does not cut. Material that cannot be
 * widely heard stays intact behind this.
 */
export interface AccessPolicy {
    decide(request: AccessRequest): Promise<AccessDecision>;
}

/* ------------------------------------------------------------------ *
 * Clock
 * ------------------------------------------------------------------ */

export interface Clock {
    /** ISO-8601, for record timestamps. Never used to order distributed events. */
    nowIso(): string;
}
