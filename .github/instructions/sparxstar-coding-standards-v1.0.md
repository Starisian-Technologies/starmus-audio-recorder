**SPARXSTAR**

**Coding Standards Handbook**

Underserved Communities Edition

v1.0  —  Starisian Technologies

This is an engineering document. It defines measurable, testable, enforceable standards for code built to serve communities operating under real constraints — low bandwidth, limited hardware, intermittent connectivity, battery-powered devices, and high cost of failure.

**These are not guidelines. They are law. If it cannot fail a build, it is not a standard.**

| WHY THIS IS NOT JUST CODING STANDARDS | Traditional coding standards govern naming, formatting, and basic implementation rules. This document governs more than that — and deliberately so. In systems serving constrained environments, you cannot separate code correctness from runtime behavior, system interaction, and failure handling. Bad code does not just fail — it consumes bandwidth that costs money, drains batteries, and degrades service for real users who have no alternative. Code, runtime limits, concurrency rules, cache behavior, failure handling, governance, and infrastructure boundaries are inseparable. This document enforces all of them together because separating them produces an incomplete standard that breaks in production. |
| :---- | :---- |

| SCOPE | Stack: WordPress (latest stable), PHP (latest stable with active support), JavaScript, GraphQL, TUS, Edge layer (provider-agnostic), Apache or equivalent, PHP-FPM, MariaDB or equivalent, Redis (Predis), OPcache. Provider selection follows Section 0.7. Sirus is the cross-repo authority layer referenced throughout. |
| :---- | :---- |

| SIRUS | Sirus is not a helper library. It is not an optional service. It is a required dependency — a control plane that every governed repository must integrate. No repo may independently determine authority, context, or applicable rules. All such resolution is delegated to Sirus. If Sirus is unavailable: fail closed. No fallback. No guessing. |
| :---- | :---- |

# **0\.  Global System Rules — Non-Negotiable**

| APPLIES TO | Every layer of the stack. PHP, JavaScript, GraphQL, TUS, Edge. No exceptions. |
| :---- | :---- |

## **0.1  System Mode Declaration**

Every system MUST declare its operating mode. Mode governs limits, logging verbosity, and enforcement sensitivity.

| Mode | Context | Enforcement |
| :---- | :---- | :---- |
| draft | Exploration and scaffolding. High churn expected. | Limits enforced. Failures logged. CI warns, does not block. |
| development | Active development. Patterns forming. | Limits enforced. CI blocks on hard violations. |
| production | Live system. Real users. Real cost of failure. | All limits enforced at maximum sensitivity. CI blocks on all violations. |

## **0.2  Determinism Rule**

Same input produces the same output with no hidden side effects. Applies to PHP functions, REST handlers, GraphQL resolvers, and all Sirus-governed actions.

## **0.3  No Silent Failure**

Every failure must return a defined error, log internally with full context, and never fallback silently. The client receives a generic error. The server logs the full context. Stack traces never reach the client.

## **0.4  Bounded Execution — Hard Caps**

| Limit | Value | Applies To |
| :---- | :---- | :---- |
| Max request CPU time | 2 seconds | All PHP requests, GraphQL resolvers |
| Max request size | 5 MB | All inbound requests |
| Max API response | 100 KB | All REST and GraphQL responses |
| Max concurrent ops | 1 per user | Mutations, uploads, governed actions |
| Max JS bundle | 150 KB gzipped | All JavaScript bundles |
| Max CSS size | 50 KB | All stylesheet bundles |

## **0.5  Idempotency — Mandatory**

All endpoints must be safe to retry without duplication. Especially TUS uploads, REST writes, and GraphQL mutations. All writes require an idempotency key. Duplicate requests return the same result and produce no additional DB write.

## **0.7  Infrastructure Provider Selection**

| PRINCIPLE | This document does not name infrastructure providers. Provider selection is an operational decision that changes as the business grows and better options emerge. The standard governs how code is written, not where it runs. |
| :---- | :---- |

| Criterion | Standard |
| :---- | :---- |
| Stability | Established providers with documented SLAs and active support communities. |
| Portability | No code written against a provider-specific proprietary API without an abstraction layer that can be swapped. S3-compatible storage interfaces, not named-provider SDKs directly. |
| Scalability | Architecture must support moving providers without rewriting application code. |
| Cost honesty | Cost per user in constrained environments is a first-class engineering consideration, not an afterthought. |
| Rising stars | Newer providers evaluated on documented capability and SLA, not on marketing. |

*The abstraction layer requirement is the enforceable consequence of this policy. If a direct provider SDK call appears in application code without an abstraction layer, it is a violation — not a preference.*

| FAIL | direct provider-specific API call without abstraction layer in application code |
| :---- | :---- |

| Layer | Trust Level | Rule |
| :---- | :---- | :---- |
| Client | Untrusted | Validate everything. Assume nothing. |
| API layer | Validated | Validates all upstream input before acting. |
| Sirus output | Authoritative | Must not be modified, merged, or overridden downstream. |
| Cache (Redis) | Disposable | Never treat as source of truth. Always verify. |
| DB (MariaDB) | Authoritative | Single source of truth. Never trusts upstream. |
| Edge cache | Disposable | TTL-bounded. Invalidated on write. |

# **1\.  Sirus — Cross-Repo Authority Layer**

| STATUS | Sirus is the only repository named specifically in this document. It is named because it is a required dependency, not because it is the most complex. It is the control plane. Everything else defers to it. |
| :---- | :---- |

## **1.1  What Sirus Resolves**

No repository may independently determine authority, context, or applicable rules. Sirus is the only system permitted to answer the question: what rules apply right now to this action for this caller?

| Question | Who Answers |
| :---- | :---- |
| What authority does this caller have? | Sirus — resolveAuthority() |
| What rules apply to this action? | Sirus — resolveContext() |
| Is this action permitted? | Sirus — governed action check |
| What consent has been given? | Sirus — consent resolution |

## **1.2  Mandatory Integration Pattern**

Every governed action must call Sirus before execution. No exceptions.

Before any governed action:

  context   \= Sirus::resolveContext(request)

  authority \= Sirus::resolveAuthority(caller)

  if context is null OR authority is null:

    FAIL CLOSED

    return error

    do NOT execute action

    do NOT guess

    do NOT fallback

## **1.3  Hard Rules**

* Sirus MUST be called before any governed action in every repo

* Sirus output is authoritative and must not be modified downstream

* If Sirus is unavailable: fail closed. No fallback. No default permissive state

* Absence of Sirus metadata means most restrictive state applies

* No repo may hardcode roles, infer permissions locally, or assume context from request shape

* Sirus decisions must not be merged with local assumptions

## **1.4  Performance Constraint**

| Metric | Limit |
| :---- | :---- |
| Sirus calls per request | 1 preferred / 2 hard cap |
| Sirus response cache TTL | 30 seconds maximum |
| Cross-user context reuse | Forbidden |
| Long-lived authority caching | Forbidden — authority is dynamic and revocable |

## **1.5  CI Enforcement**

| FAIL | governed action exists without preceding Sirus call |
| :---- | :---- |

| FAIL | Sirus output modified or overridden downstream |
| :---- | :---- |

| FAIL | local permission check without Sirus delegation |
| :---- | :---- |

# **2\.  PHP and WordPress Standards**

## **2.1  Version Policy — Minimum Supported, Not Pinned**

| PRINCIPLE | This document does not pin to specific version numbers. Version pins in a standards document create maintenance debt — the document becomes wrong the moment a new release ships, and nobody updates it. We build at the front of the supported window. We test against the supported minimum. We never write for the unsupported minimum. |
| :---- | :---- |

| Component | Policy | Rule |
| :---- | :---- | :---- |
| WordPress | Latest stable release | No deprecated WP APIs. The community's backwards compatibility culture is a feature for adoption, not a target for development. |
| PHP | Latest stable with active support | Not security-only. Not end-of-life. Strict types required. No dynamic properties. |
| MariaDB | Latest stable — provider-agnostic | No direct SQL interpolation. All queries parameterized. No provider-specific extensions without abstraction layer. |

*The WordPress community supports environments going back years. We respect that users run older environments. We do not write older code to match them. A plugin can support WordPress 6.x while being written in modern PHP with strict types. These are separable concerns.*

## **2.2  Strict Typing — Mandatory**

// Required at top of every PHP file

declare(strict\_types=1);

// All functions must have typed parameters and return types

function process\_audio(string $path, int $duration): array { ... }

// Forbidden

function process($path, $duration) { ... }  // no types

| FAIL | PHP file missing declare(strict\_types=1) |
| :---- | :---- |
| **FAIL** | function missing typed parameters or return type |

## **2.3  Input Discipline**

All input must be sanitized before use. No raw superglobals. No implicit casting.

// Required

$text  \= sanitize\_text\_field($\_POST\['text'\] ?? '');

$key   \= sanitize\_key($\_GET\['key'\] ?? '');

$email \= filter\_var($\_POST\['email'\], FILTER\_VALIDATE\_EMAIL);

// Forbidden

$text \= $\_POST\['text'\];    // raw superglobal

$id   \= (int)$\_GET\['id'\]; // implicit cast without validation

## **2.4  Database Rules**

* All writes require explicit schema mapping and validation before write

* Transactions required wherever atomicity is needed

* No direct SQL string interpolation — prepared statements only

* No unbounded queries — all queries must have explicit LIMIT

* No SELECT \* — specify required columns explicitly

* Row-level locking or optimistic versioning required for conflicting writes

| FAIL | direct SQL string interpolation |
| :---- | :---- |
| **FAIL** | SELECT \* in production queries |
| **FAIL** | unbounded query without LIMIT |

## **2.5  Object Caching — Redis / Predis**

* All cache entries must have defined TTL. No infinite TTL.

* Cache keys must be namespaced to prevent collision

* User-specific data must never enter shared cache

* Write operations must invalidate related cache entries immediately

* Redis is a cache only. Never the source of truth.

## **2.6  OPcache — Production Configuration**

; Production only

opcache.validate\_timestamps \= 0

opcache.memory\_consumption  \= 128

opcache.max\_accelerated\_files \= 10000

## **2.7  WordPress Plugin Rules**

* All plugins must be namespaced — no global namespace pollution

* Scripts and styles loaded conditionally — never globally

* All fields defined in schema and validated before use

* No implicit field access — no dynamic schema mutation at runtime

  // Required pattern

  if (\!is\_page('media-record')) return;

  wp\_enqueue\_script('sparxstar-recorder', ...);


  // Forbidden

  wp\_enqueue\_script('sparxstar-recorder', ...); // global, no guard

## **2.8  Abilities and Consent API**

Every action must check ability and verify consent before execution. Bypassing ability checks is forbidden. Assuming consent is forbidden.

// Required

if (\!current\_user\_can('sparxstar\_record')) {

    return new WP\_Error('forbidden', 'Insufficient ability');

}

if (\!sparxstar\_has\_consent($user\_id, 'audio\_record')) {

    return new WP\_Error('consent\_required', 'Consent not given');

}

| FAIL | governed action without ability check |
| :---- | :---- |
| **FAIL** | governed action without consent verification |

# **3\.  JavaScript Standards**

## **3.1  Execution Budget**

| Metric | Limit | Mode |
| :---- | :---- | :---- |
| Max main-thread block | 50ms | All modes |
| Max event handler rate | 10 Hz | Production |
| Max event handler rate | 20 Hz | Development |
| Sensor active window | 5000ms then auto-disable | All modes |
| Concurrent media streams | 1 | All modes |
| Blob in memory | 5 MB max | All modes |
| Media buffers | 2 max | All modes |

## **3.2  Event Throttling — Mandatory**

All event listeners must be throttled or debounced. Continuous loops are forbidden.

// Required — throttle pattern

let lastRun \= 0;

function handleSensorEvent(data) {

  if (Date.now() \- lastRun \< 100\) return; // 10 Hz max

  lastRun \= Date.now();

  processData(data);

}

// Forbidden

setInterval(() \=\> doWork(), 10); // unbounded loop

sensor.addEventListener('data', handler); // no throttle

| FAIL | event listener without throttle or debounce |
| :---- | :---- |
| **FAIL** | continuous interval without bounded execution |

## **3.3  API Call Discipline**

// Required

const response \= await fetch(url, {

  signal: AbortSignal.timeout(5000), // 5s max

});

// Retry with exponential backoff — max 3 attempts

async function fetchWithRetry(url, maxRetries=3) {

  for (let i \= 0; i \< maxRetries; i++) {

    try { return await fetch(url, { signal: AbortSignal.timeout(5000) }); }

    catch { await new Promise(r \=\> setTimeout(r, 1000 \* Math.pow(2, i))); }

  }

  throw new Error('Max retries exceeded');

}

| FAIL | API call without timeout |
| :---- | :---- |
| **FAIL** | infinite retry loop |
| **FAIL** | UI blocked during network operation |

## **3.4  Memory Management**

// Release media references when done

video.srcObject \= null;

stream.getTracks().forEach(track \=\> track.stop());

// Stream blobs — never buffer full media in memory

// Wrong: blob \= await response.blob(); // full file in memory

// Right: stream progressively via ReadableStream

## **3.5  Network Awareness**

// Check before attempting upload

if (\!navigator.onLine) {

  queueUpload(file); // IndexedDB queue

  return;

}

// Queue uploads locally — never assume stable connection

# **4\.  Audio and Video — Hard Limits**

| PRINCIPLE | These limits exist because bandwidth is a financial cost to users in constrained environments. Exceeding them is not a quality tradeoff — it is a billing impact on people who cannot absorb it. |
| :---- | :---- |

## **4.1  Audio Capture Limits**

| Parameter | Limit | Notes |
| :---- | :---- | :---- |
| Sample rate | 16,000 Hz maximum | Sufficient for voice. Higher rates waste bandwidth. |
| Channels | 1 (mono) | Stereo doubles the data for no voice quality gain. |
| Bitrate | 24 kbps maximum | Hard cap 32 kbps. Opus/AAC-LC only. |
| Format | Opus or AAC-LC | No raw PCM. No WAV. Compressed only. |

| Mode | Max Duration |
| :---- | :---- |
| draft | 300 seconds |
| development | 180 seconds |
| production | 120 seconds |

| FAIL | sampleRate \> 16000 |
| :---- | :---- |
| **FAIL** | channels \> 1 |
| **FAIL** | bitrate \> 32000 |
| **FAIL** | format is WAV or uncompressed PCM |

## **4.2  Video Capture Limits**

| Parameter | Limit | Notes |
| :---- | :---- | :---- |
| Resolution | 640x480 maximum (VGA) | No HD. No 720p. No 1080p. |
| Frame rate | 15 fps maximum | Sufficient for documentation. Higher wastes bandwidth. |
| Bitrate | 500 kbps maximum | Hard cap 800 kbps. |
| Codec | H.264 Baseline only | HEVC and AV1 forbidden unless fallback exists. |

| Mode | Max Duration |
| :---- | :---- |
| draft | 180 seconds |
| development | 120 seconds |
| production | 60 seconds |

| FAIL | width \> 640 or height \> 480 |
| :---- | :---- |
| **FAIL** | fps \> 15 |
| **FAIL** | bitrate \> 800 kbps |
| **FAIL** | codec is not H.264 Baseline |

## **4.3  JavaScript Capture Constraints**

const constraints \= {

  audio: {

    sampleRate: 16000,

    channelCount: 1

  },

  video: {

    width:     { ideal: 640,  max: 640 },

    height:    { ideal: 480,  max: 480 },

    frameRate: { ideal: 15,   max: 15  }

  }

};

// Never start recording automatically

// Never assume camera or microphone availability

# **5\.  TUS Upload Server Standards**

## **5.1  Chunk Limits**

| Parameter | Limit |
| :---- | :---- |
| Max chunk size | 512 KB |
| Max total upload | 5 MB (hard cap at PHP layer) |
| Retry attempts | 3 minimum with exponential backoff |
| Checksum verification | Required per chunk |
| UUID assignment | Required per upload |
| Final size validation | Required before write committed |

## **5.2  Resume Guarantee**

All uploads must support resume. A failed upload at any chunk must be resumable without restarting from zero. No full-file upload endpoints are permitted.

| FAIL | upload endpoint without chunking support |
| :---- | :---- |
| **FAIL** | chunk \> 512 KB |
| **FAIL** | upload without checksum verification |
| **FAIL** | upload without UUID |

## **5.3  Atomicity**

Either the upload completes fully and the DB write succeeds, or both are rolled back. No partial success states. If the upload succeeds and the DB write fails, the upload file must be removed and the error reported.

# **6\.  GraphQL Standards**

## **6.1  Query Complexity Limits**

| Metric | Limit |
| :---- | :---- |
| Max query depth | 5 levels |
| Max query cost | Defined per resolver — not open-ended |
| Max list response | Bounded — no unbounded list queries |
| Concurrent mutations | 1 per user |

## **6.2  Resolver Rules**

* Resolvers must be stateless

* No nested DB calls inside resolvers — use DataLoader or batch loading

* N+1 queries are forbidden

* All resolvers must check Sirus authority before executing governed actions

| FAIL | N+1 query pattern in resolver |
| :---- | :---- |
| **FAIL** | unbounded list query without explicit limit |
| **FAIL** | governed resolver without Sirus authority check |

# **7\.  Edge Layer — Cloudflare, Nginx, Varnish**

## **7.1  Request Filtering — Hard Fail Conditions**

Machines do not oops. Invalid request behavior is treated as intent, not error.

| FAIL | User-Agent header empty or absent |
| :---- | :---- |
| **FAIL** | User-Agent is generic bot signature without identification |
| **FAIL** | request headers inconsistent with request type |
| **FAIL** | GET request with body |
| **FAIL** | Content-Type mismatched to payload |
| **FAIL** | invalid Accept headers |

| Violation | HTTP Response |
| :---- | :---- |
| Empty or absent User-Agent | 400 Bad Request |
| Malformed headers | 400 Bad Request |
| Suspicious pattern | 403 Forbidden |
| Resource not found | 404 (no hinting — no suggestion of valid paths) |
| Rate limit exceeded | 429 Too Many Requests |

## **7.2  Rate Limits — Baseline**

| Metric | Limit |
| :---- | :---- |
| Requests per second per IP | 5 maximum |
| Requests per minute per IP | 100 maximum |
| Concurrent connections per IP | 2 maximum |
| Burst tolerance | 10 req/sec — hard ceiling |

| Violation Count | Penalty |
| :---- | :---- |
| 1 to 3 | Throttle — reduced rate limit |
| 4 to 10 | Temporary block — 5 to 30 minutes |
| Over 10 | Extended block — duration at operator discretion |

## **7.3  Geographic and Behavioral Filtering**

| PRINCIPLE | Filtering is behavior-based, not identity-based. Regions with high abuse patterns receive reduced rate limits and increased verification requirements. This is not a blanket block — it is cost discipline. Legitimate users from any region are served. Bad actors from any region are filtered. |
| :---- | :---- |

* Apply selective throttling based on traffic patterns and abuse signals

* Regions with documented high abuse rates: lower rate limits, higher verification threshold

* Do not blanket-block countries without behavior signal

* Do not rely only on IP origin — use behavior pattern as primary signal

## **7.4  Cache Rules**

* Cache GET requests only — never cache POST, PUT, DELETE

* TTL defined per route — no default open-ended TTL

* Never cache authenticated responses

* Write operations must invalidate edge cache immediately

* Varnish and Cloudflare are disposable caches — never sources of truth

## **7.5  Access Tiers**

| Tier | Allowed | Restricted |
| :---- | :---- | :---- |
| Public read | GET requests. Public resources. No authentication required. | POST, PUT, DELETE require authentication. Bulk scraping rate-limited. |
| Authenticated write | All methods. Scoped to caller's authorized coordinates. | Must pass Sirus authority check before any write action. |

# **8\.  Concurrency and State Consistency**

## **8.1  Concurrency Rules**

| Scope | Rule |
| :---- | :---- |
| Per user | Max 1 active mutation. Max 1 active upload. Concurrent requests rejected with 429\. |
| Per resource | Writes must be locked (row-level) or versioned (optimistic) before commit. |
| DB writes | All conflicting writes must use row-level locking or optimistic versioning. |
| TUS uploads | Parallel chunk uploads for same upload ID must be serialized. |

## **8.2  Source of Truth**

| Layer | Role | Rule |
| :---- | :---- | :---- |
| MariaDB | Authoritative | The source of truth. Never trusts upstream. |
| Redis | Cache only | Never source of truth. Short TTL. Invalidated on write. |
| Edge cache | Disposable | TTL-bounded. Purged on write. Never queried for authority. |
| GraphQL cache | Disposable | Must not serve stale data beyond defined TTL. |

## **8.3  Cache Invalidation**

TTL alone is not a cache invalidation strategy. Write operations must actively invalidate.

// Required on every write

DB::write($data);

Redis::delete($cache\_key);

EdgeCache::purge($route);

// Forbidden

// Relying on TTL expiry to propagate write changes

## **8.4  Backpressure and Load Shedding**

When the system is saturated, reject early rather than queue unbounded.

// Priority order when under load

if (system\_load \> THRESHOLD) {

  // 1\. Protect active authenticated sessions

  // 2\. Accept writes from established connections

  // 3\. Reject new unauthenticated requests with 503

  // 4\. Drop lowest-priority traffic first

  return new WP\_Error('overloaded', '', \['status' \=\> 503\]);

}

## **8.5  Partial Failure Handling**

There are no partial success states. An operation either succeeds completely or is rolled back completely.

// Required — wrap multi-step operations in transaction

$db-\>begin\_transaction();

try {

  $upload\_result \= store\_file($path);

  $db\_result     \= write\_record($upload\_result);

  $db-\>commit();

} catch (Exception $e) {

  $db-\>rollback();

  if ($upload\_result) { delete\_file($path); }

  return new WP\_Error('operation\_failed', 'Rolled back.');

}

# **9\.  Async Processing and Queue Rules**

| RULE | Async without defined failure handling is silent data loss. Every async job must have a retry policy, a timeout, and be idempotent. |
| :---- | :---- |

| Rule | Value |
| :---- | :---- |
| Max retries per job | 3 |
| Retry strategy | Exponential backoff |
| Job timeout | Defined per job type — never unbounded |
| Idempotency | Required — same job ID produces same result, no duplicate writes |
| Failed jobs | Logged, visible, retryable — never silently discarded |
| Sync media processing | Forbidden if \> 2 seconds |
| Transcoding | Async only — never in request lifecycle |

| FAIL | async job without retry policy |
| :---- | :---- |
| **FAIL** | async job without timeout |
| **FAIL** | synchronous media processing \> 2 seconds |
| **FAIL** | failed job silently discarded without log |

# **10\.  Data Lifecycle**

| Data Type | Lifecycle Rule |
| :---- | :---- |
| Temporary uploads | Expire after 24 hours if not committed to DB record |
| Orphaned files | Cleaned by scheduled job — any file without a DB record \> 24h |
| Application logs | Rotated on defined schedule — never unbounded growth |
| Old media | Archived or deleted per retention policy — no accumulation by default |
| Session data | Expires with session TTL — no persistent session without consent |
| Cache entries | TTL defined. Purged on invalidation. Never accumulates stale entries. |

# **11\.  Observability — Required Metrics**

| Metric | Alert Condition |
| :---- | :---- |
| Request latency | \> 2 seconds average over 5 minutes |
| Error rate | \> 1% of requests in production |
| Invalid header rate | \> 5% of requests |
| Rate limit triggers | Spike \> baseline \+ 200% |
| Cache hit ratio | \< 80% in production |
| Upload failure rate | \> 2% of uploads |
| Retry rate | \> 10% of requests |
| Sirus call failures | Any failure in production |
| Blocked requests by geo | Tracked — reviewed weekly |

# **12\.  Distributed System Maturity**

| NOTE | The rules in this section govern edge conditions in distributed systems. They are not theoretical — they are failure modes that appear in production systems at scale. They are included here because they cannot be separated from the code that causes them. |
| :---- | :---- |

## **12.1  Write Order Guarantee**

No downstream system may observe a write before the DB commit is confirmed. The order is fixed and non-negotiable.

Required order on every write:

  1\. DB commit confirmed

  2\. Cache invalidation (Redis)

  3\. Edge cache purge

  4\. Event emission to downstream systems

Forbidden:

  cache invalidation before DB commit confirmed

  event emission before DB commit confirmed

  any downstream notification before write is durable

| FAIL | cache invalidation or event emission before DB commit confirmed |
| :---- | :---- |

## **12.2  Time Authority**

Server time is authoritative. Client timestamps are advisory only and must never be used for ordering decisions, session authority, or conflict resolution.

| Source | Role | Rule |
| :---- | :---- | :---- |
| Server time | Authoritative | All ordering, TTL, retry windows, and session decisions use server-side time. |
| Client time | Advisory only | Never used for ordering. Never trusted for conflict resolution. May be logged for diagnostics. |
| DB timestamp | Authoritative | Generated server-side. Never accepted from client payload. |

| FAIL | client-supplied timestamp used for ordering or conflict resolution |
| :---- | :---- |
| **FAIL** | DB timestamp accepted from client payload |

## **12.3  Dead-Letter Queue**

Jobs that exhaust their retry budget must not be silently dropped. They move to a dead-letter queue where they remain queryable and manually retryable.

| Rule | Requirement |
| :---- | :---- |
| After max retries | Job moves to dead-letter queue — never silently deleted |
| Dead-letter visibility | All dead-letter jobs must be queryable by operators |
| Manual retry | Dead-letter jobs must be individually retryable without code change |
| Alert condition | Dead-letter queue depth \> 0 triggers alert in production |

| FAIL | job silently discarded after max retries without dead-letter entry |
| :---- | :---- |

## **12.4  Deployment Safety**

| Rule | Requirement |
| :---- | :---- |
| Schema changes | Must be backward compatible. No breaking change without migration path. |
| Breaking changes | Must be behind a feature flag. Never deployed directly to production. |
| Rollback | Must be possible within one deploy cycle. No one-way deployments. |
| DB migrations | Must be additive first (add column) before destructive (drop column). Two-phase migration. |

| FAIL | breaking schema change deployed without feature flag |
| :---- | :---- |
| **FAIL** | DB migration that is not rollback-safe |

## **12.5  Schema Versioning**

Schema changes must be versioned. Clients must tolerate the previous schema version during the transition window. No destructive schema change without a defined migration path.

// Required — additive first, destructive second

// Phase 1: add new field, keep old field

// Phase 2: migrate data to new field

// Phase 3: remove old field (separate deploy, after client update confirmed)

// Forbidden

// single deploy that removes a field clients still depend on

## **12.6  Client-Side Storage Limits**

| Storage Type | Limit | Eviction Policy |
| :---- | :---- | :---- |
| IndexedDB | 20 MB maximum | LRU — oldest entries removed when limit approached |
| localStorage | 5 MB maximum | Explicit TTL — no indefinite persistence |
| sessionStorage | Session only | Cleared on session end — no cross-session persistence |
| Service Worker cache | Defined per route | TTL or version-based invalidation — never unbounded |

| FAIL | IndexedDB usage without defined eviction policy |
| :---- | :---- |
| **FAIL** | localStorage used for data without TTL or explicit cleanup |

## **12.7  Abuse Escalation Automation**

Rate limit violations follow a defined escalation path. The system adapts to persistent bad actors without manual intervention.

| Violation Pattern | Automated Response |
| :---- | :---- |
| Burst traffic \> 10 req/sec | Immediate throttle — rate reduced to 1 req/sec for 60 seconds |
| Rate limit exceeded 1-3 times | Throttle — reduced rate for 5 minutes |
| Rate limit exceeded 4-10 times | Temporary block — 5 to 30 minutes |
| Rate limit exceeded \> 10 times | Extended block — 24 hours minimum |
| Pattern detection — scraping signals | Adaptive throttling — stricter limits applied |
| Header spoofing detected | Immediate block — no escalation path |

# **13\.  CI Enforcement Summary**

These conditions must cause CI to fail in development and production modes. In draft mode CI warns but does not block.

## **13.1  PHP**

| FAIL | PHP file missing declare(strict\_types=1) |
| :---- | :---- |
| **FAIL** | function missing typed parameters or return type |
| **FAIL** | raw superglobal access without sanitization |
| **FAIL** | direct SQL string interpolation |
| **FAIL** | SELECT \* in any query |
| **FAIL** | governed action without Sirus call |
| **FAIL** | governed action without ability check |
| **FAIL** | governed action without consent verification |

## **13.2  JavaScript**

| FAIL | event listener without throttle or debounce |
| :---- | :---- |
| **FAIL** | JS bundle exceeds 150 KB gzipped |
| **FAIL** | API call without timeout |
| **FAIL** | sensor active beyond 5 seconds without auto-disable |
| **FAIL** | blob in memory exceeds 5 MB |

## **13.3  Media**

| FAIL | audio sampleRate \> 16000 |
| :---- | :---- |
| **FAIL** | audio channels \> 1 |
| **FAIL** | audio bitrate \> 32000 |
| **FAIL** | video width \> 640 or height \> 480 |
| **FAIL** | video fps \> 15 |
| **FAIL** | video codec is not H.264 Baseline |

## **13.4  TUS**

| FAIL | upload chunk \> 512 KB |
| :---- | :---- |
| **FAIL** | upload without chunk checksum |
| **FAIL** | upload without UUID |
| **FAIL** | full-file upload endpoint present |

## **13.5  GraphQL**

| FAIL | query depth \> 5 |
| :---- | :---- |
| **FAIL** | N+1 query pattern in resolver |
| **FAIL** | governed resolver without Sirus call |

## **13.6  CSS**

| FAIL | CSS bundle exceeds 50 KB |
| :---- | :---- |
| **FAIL** | blur filter or heavy shadow in production CSS |

## **13.7  Distributed System Rules**

| FAIL | cache invalidation or event emission before DB commit confirmed |
| :---- | :---- |
| **FAIL** | client-supplied timestamp used for ordering or conflict resolution |
| **FAIL** | job silently discarded after max retries without dead-letter entry |
| **FAIL** | breaking schema change deployed without feature flag |
| **FAIL** | DB migration that is not rollback-safe |
| **FAIL** | IndexedDB usage without defined eviction policy |
| **FAIL** | direct provider-specific API call without abstraction layer |

# **Final Engineering Statement**

***Engineering for underserved environments is not about removing features. It is about designing systems that survive reality.***

***If it cannot fail a build, it is not a standard. It is a suggestion.***

***Bandwidth is a financial cost. Battery is a finite resource. Connectivity is not guaranteed. Build accordingly.***

Version: 1.0  |  Starisian Technologies  |  April 2026

Applies to: WordPress (latest stable), PHP (latest stable active support), JavaScript, GraphQL, TUS, provider-agnostic edge and infrastructure
