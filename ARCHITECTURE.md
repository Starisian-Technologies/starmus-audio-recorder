# Architecture

The reasons for these boundaries are in the ADRs, not here. This document says
how the service is put together and why the layering is what it is.

## Layers

```
                    capture UI package            ESU
                          │                        ▲
                          │ upload (chunked,       │ intake event: ids, hashes,
                          │ resumable, once,       │ quality flags, measurements,
                          │ at source quality)     │ VAD segments — never URLs
                          ▼                        │
   ┌──────────────────────────────────────────────────────────────┐
   │                     Spoken Audio Node                        │
   │                                                              │
   │   ingest/        acceptance, integrity — deny nothing        │
   │   analysis/      pinned Praat + ffmpeg, as separate procs    │
   │   access/        authorization only                          │
   │   release/       walled-off editorial rendering              │
   │   preservation/  the guards that cannot be refactored past   │
   │                                                              │
   │   domain/  ◄── the rules live here, with no I/O              │
   │   ports/   ◄── interfaces only; no default implementations   │
   └──────────────────────────────────────────────────────────────┘
                          │ authorize
                          ▼
              media ingest service (files.sparxstar.com)
              transport, storage, temporary-URL issuance
```

## Why `domain/` has no I/O

Every ADR rule this service must honour is a statement about values: which
rendition may be evidence, whether a manifest is deterministic, whether a
measurement class is reliable under these conditions, whether a range lies on
the recording's timeline. Expressed as pure functions they are testable in
milliseconds and cannot be mocked away. Expressed next to an `await
transport.fetch(...)` they become the thing that gets skipped when the transport
is stubbed.

`src/domain/domain.test.ts` runs in about a tenth of a second and covers the
invariants. `src/analysis/analysis.test.ts` takes longer because it runs real
Praat over real audio, which is the only way to know the scripts work.

## Why `ports/` has no defaults

The capture→ingestion contract in the governance registry records four terms as
**owed** rather than agreed — the consumer's endpoint path and auth model, the
upload metadata key set, the acknowledgement and error envelope, and
confirmation that `sha256` remains the checksum algorithm — and states that no
repository implements a guess at them.

A default implementation of `MediaTransport` would have to invent an endpoint
shape. A default `IntakePublisher` would have to invent the wire schema that
OQ-022 has not yet assigned a home. Requiring both to be injected is how the
contract's rule survives contact with a composition root.

The same reasoning gives `DENY_ALL` as the access policy a service gets when
none is configured: regional access controls are sovereignty decisions, and an
implicit allow would make one by omission.

## Why the analysis tools are spawned

ADR-038 rules that Praat runs as a separate process, never linked. Two reasons,
and both matter:

1. **Licensing.** Praat is GPL-2+, read from its own distribution and recorded
   in `tools.pinned.json`. This service is BUSL-1.1. Linking would put the
   service under the GPL; the process boundary is what keeps them apart.
2. **Provenance.** A spawned tool can be asked what version it is, and the
   answer can be compared with the pin before any work is done. A linked library
   is whatever was compiled in.

`resolveTool()` therefore probes the version every time, and refuses to run when
what is installed is not what was pinned. A drifted tool does not just change a
number: it makes every measurement recorded afterwards name a version that never
computed it.

## Why there is exactly one timeline

Any second timeline needs a mapping, a mapping needs a contract, and a contract
that maps timestamps between renditions is a thing that can be subtly wrong for
years without anyone noticing. ADR-039 avoids the whole class by making the
original the only timeline: derivatives are timeline-preserving by construction,
so an offset means the same instant everywhere.

`domain/manifest.ts` enforces it from the other end. The list of permitted
transformation steps is closed, and every step on it preserves duration. A trim
or a splice is not on the list, so a preservation-path manifest cannot describe
one — and the release pipeline, which does cut, does not produce
preservation-path manifests.

## Delay tolerance

Nothing in this service has a deadline. Material arrives over links that are
down for hours, and processing that waits a day is normal rather than
exceptional. `registerUpload` queues the work that follows rather than running it
inline: holding a contributor's connection open while ffmpeg and Praat run would
put their bandwidth on the critical path of work they are not waiting for.

`JobQueue` has `defer` and `quarantine` alongside `complete`, and no `discard`.

## What is not here yet

- **Bulk import** from physical media and institutional archives: the job class
  is declared and the pins are recorded; the ingest path for it is not written.
- **Playback and waveform-data derivatives**: job classes declared, pins
  recorded, producers not written. Peaks.js and audiowaveform licences must be
  read from the upstream BBC repositories and recorded in `tools.pinned.json`
  before either is bundled.
- **Per-collection and per-project cost visibility.**
- **The intake publisher adapter**, which waits on OQ-022.
