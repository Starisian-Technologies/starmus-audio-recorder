/**
 * Identifiers for assets and their renditions.
 *
 * ADR-038: assets are referenced everywhere by an immutable `audio_asset_id`
 * and derivative identifiers. No durable storage URL is stored or emitted
 * anywhere — not in events, not in records, not in evidence fields. Making the
 * identifier a branded type is how that rule survives contact with a codebase:
 * a URL string cannot be passed where an asset id is expected.
 */

import { randomUUID } from 'node:crypto';

declare const brand: unique symbol;

/** A value that is nominally distinct from a bare string. */
type Branded<T, B extends string> = T & { readonly [brand]: B };

/** The immutable identity of a registered original. */
export type AudioAssetId = Branded<string, 'AudioAssetId'>;

/** The identity of one rendition derived from an asset. */
export type DerivativeId = Branded<string, 'DerivativeId'>;

/** The identity of one processing job. */
export type JobId = Branded<string, 'JobId'>;

/** The identity of one intake event. */
export type IntakeEventId = Branded<string, 'IntakeEventId'>;

const UUID_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

/**
 * Reject anything that looks like a locator rather than an identifier.
 *
 * The failure this guards against is concrete: a storage URL assigned into a
 * field typed as an id, which then travels into an event or an evidence record
 * and outlives the short-lived grant it came from.
 */
function assertNotALocator(value: string, kind: string): void {
    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(value) || value.includes('/')) {
        throw new TypeError(
            `${kind} must be an identifier, not a locator: received something URL-shaped. ` +
                'ADR-038 keeps durable storage URLs out of ids, events and records.',
        );
    }
}

function assertUuid(value: string, kind: string): void {
    assertNotALocator(value, kind);
    if (!UUID_PATTERN.test(value)) {
        throw new TypeError(`${kind} must be a UUID: received ${JSON.stringify(value)}`);
    }
}

/** Mint a new asset id. */
export function newAudioAssetId(): AudioAssetId {
    return randomUUID() as AudioAssetId;
}

/** Accept an asset id that arrived from outside this service. */
export function audioAssetId(value: string): AudioAssetId {
    assertUuid(value, 'audio_asset_id');
    return value as AudioAssetId;
}

/** Mint a new derivative id. */
export function newDerivativeId(): DerivativeId {
    return randomUUID() as DerivativeId;
}

/** Accept a derivative id that arrived from outside this service. */
export function derivativeId(value: string): DerivativeId {
    assertUuid(value, 'derivative_id');
    return value as DerivativeId;
}

/** Mint a new job id. */
export function newJobId(): JobId {
    return randomUUID() as JobId;
}

/** Mint a new intake event id. */
export function newIntakeEventId(): IntakeEventId {
    return randomUUID() as IntakeEventId;
}
