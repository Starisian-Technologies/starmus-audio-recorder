/**
 * Authorized consumption.
 *
 * ADR-038 splits transport from authorization: **this service authorizes
 * access; the standalone media-type-agnostic ingest service performs storage
 * operations and issues the requested temporary URL.** Nothing here opens a
 * bucket, and nothing here keeps the URL that comes back.
 *
 * ADR-039 adds the rule that makes access control load-bearing rather than
 * administrative: **redaction restricts access; it does not cut.** Material
 * that cannot be widely heard stays intact behind this module. There is no code
 * path in this repository that removes audio to make it safe to publish,
 * because the safe-to-publish artifact is a release rendering and the original
 * stays whole.
 */

import type { AudioAsset } from '../domain/asset.js';
import type { RenditionKind } from '../domain/renditions.js';
import { renditionOfKind } from '../domain/asset.js';
import type {
    AccessPolicy,
    AccessRequest,
    MediaTransport,
    TemporaryAccessGrant,
} from '../ports/index.js';

export class AccessDenied extends Error {
    public constructor(message: string) {
        super(message);
        this.name = 'AccessDenied';
    }
}

export interface GrantRequest extends AccessRequest {
    readonly rendition: RenditionKind;
}

/**
 * Authorize a consumer and ask the transport for a short-lived URL.
 *
 * The grant is returned and never stored. That is the whole of ADR-038's rule
 * in one function: assets are referenced by `audio_asset_id` and derivative
 * identifiers everywhere, and a URL exists only inside the call that needed
 * bytes. A caller that persists the return value has put a storage URL in a
 * record, and no type can stop it — but nothing in this service does.
 */
export async function grantTemporaryAccess(
    policy: AccessPolicy,
    transport: MediaTransport,
    asset: AudioAsset,
    request: GrantRequest,
): Promise<TemporaryAccessGrant> {
    const decision = await policy.decide(request);
    if (!decision.granted) {
        throw new AccessDenied(
            `Access to asset ${asset.id} denied for '${request.consumer}': ${decision.reason}`,
        );
    }

    const rendition = renditionOfKind(asset, request.rendition);
    if (rendition === undefined) {
        throw new AccessDenied(
            `Asset ${asset.id} has no '${request.rendition}' rendition. ` +
                'Renditions are produced by job classes, not conjured at request time.',
        );
    }

    return transport.requestTemporaryUrl(rendition.storage, {
        consumer: request.consumer,
        reason: request.purpose,
    });
}

/**
 * A policy that denies everything, with a reason saying why it is here.
 *
 * The composition root requires a policy; a service standing up before its real
 * policy exists gets this one rather than an implicit allow. Regional access
 * controls and the rest are the real policy's business, and guessing at them
 * would be guessing at sovereignty rules that are not this repository's to set.
 */
export const DENY_ALL: AccessPolicy = {
    decide: () =>
        Promise.resolve({
            granted: false,
            reason:
                'No access policy is configured. This service fails closed: regional access controls and consumer ' +
                'authorization are sovereignty decisions, and an implicit allow would make one by omission.',
        }),
};
