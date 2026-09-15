# AGENTS.md — the Spoken Audio Node

**Read `.github/instructions/starmus-boundary.md` first.** It assigns this
repository's role and names the ADRs that set it. This file is how to work
inside that role.

This repository is **not** a WordPress plugin, and it is not the recorder. It is
a Node/TypeScript service that owns the platform audio asset lifecycle:
ingest acceptance and integrity, storage references, derivative processing,
acoustic measurement through a pinned Praat worker, bulk import, and authorized
consumption.

---

## What the code is shaped like

```
src/domain/        the rules, with no I/O — testable without a filesystem
src/ports/         interfaces this service consumes; no default implementations
src/analysis/      the pinned toolchain: Praat, ffmpeg, ffprobe
src/ingest/        acceptance and integrity
src/access/        authorization; short-lived URLs requested from the transport
src/preservation/  the ADR-039 guards, as executable assertions
src/release/       release rendering — the only place an edit may happen
praat/             the analysis scripts the worker runs
tools.pinned.json  pinned versions and licence attestations
```

`src/domain/` holds the rules because a rule enforced next to a network call is
a rule that gets skipped when the network call is mocked.

---

## The rules that fail a build

`pnpm run validate` runs `scripts/validate-node-boundary.cjs` on every build.

| FAIL | Condition |
| --- | --- |
| FAIL | Any PHP file in the repository |
| FAIL | A WordPress surface: shortcode, CPT registration, admin screen, post-meta persistence, CMS REST route |
| FAIL | Browser capture code — `MediaRecorder`, `getUserMedia`, `navigator.mediaDevices` |
| FAIL | A native binding dependency, or a `.node` addon load |
| FAIL | A pinned tool without a version or without a licence attestation |
| FAIL | A durable storage URL modelled anywhere (`storageUrl`, `downloadUrl`, `publicUrl`, …) |
| FAIL | A `transcript` or `translation` field declared in this service's types |
| FAIL | An audio-edit operation defined outside `src/release/` |

And these, enforced by the types and the tests rather than the script:

| FAIL | Condition |
| --- | --- |
| FAIL | A measurement constructed without extraction parameters or a tool version |
| FAIL | A perturbation measure reported as reliable while its floor is unruled |
| FAIL | `--undefined--` from Praat turned into a number |
| FAIL | An analysis derivative without a transformation manifest, source hash and tool version |
| FAIL | A transformation step that is not on the closed list in `domain/manifest.ts` |
| FAIL | A capture profile carrying a numeric floor (OQ-021 is not ours) |
| FAIL | A downsample in the standard analysis derivative |
| FAIL | A stereo source folded down to mono |
| FAIL | An enhanced or release rendition used as evidence or as analysis input |
| FAIL | A timestamp on anything but the original timeline |
| FAIL | A port with a default implementation |

---

## The five rules behind all of those

**1. The original is immutable evidence and the timeline authority.** Nothing
writes to it, nothing deletes it, and every timestamp anywhere in the platform
is an offset into it. The first-generation pipeline wrote ID3 tags into the
uploaded file; `preservation/immutability.ts` exists so that cannot recur.

**2. Deny nothing; quarantine instead.** A failed checksum, a missing profile, a
container that will not parse — each is a flag on the asset. None is grounds to
discard a contributor's recording. Every processing route keeps the material,
and `keepsMaterial()` returns `true` for all of them so that changing it would
be visible as the violation it is.

**3. A measurement without its settings is not reproducible.** Every stored
measurement carries the tool, the version the tool reported, the script, the
script's hash, the parameters the script echoed back, the hash of the bytes
measured, and which rung of the ladder they were. A class whose published
reliability floor the recording does not meet is marked, not silently reported.

**4. Describe, never judge.** Acoustic measurements describe the signal. They
never decide whether a word, pronunciation or grammar form is correct. The Node
does not declare linguistic truth; ESU holds the reviewed record, and everything
this service offers it enters as a lowest-authority machine candidate.

**5. Do not answer someone else's open question.** OQ-021 (numeric floors and
codecs) belongs to AIWA and the acoustic-analysis owner. OQ-022 (the intake
contract's home) is an owner call. The four owed terms of the capture→ingestion
contract belong to both builders. Where a value is missing, the code says so —
it does not fill it in.

---

## The analysis worker

Praat runs as a **separate process, never linked**. That is a licensing
requirement as much as an architectural one: Praat is GPL-2+ (read from
`/usr/share/doc/praat/copyright` and recorded in `tools.pinned.json`) and this
service is BUSL-1.1. The process boundary is what keeps those apart.

Versions are pinned per job class. `resolveTool()` runs the tool, reads the
version it reports, and refuses to proceed if it is not the pinned one — because
a measurement names the version that produced it, and a tool that moved
underneath a deployment makes every later result unverifiable.

`praat/measure.praat` and `praat/segment.praat` echo back every parameter they
were given, and the worker records what was echoed rather than what was sent. A
script that defaulted or clamped a value cannot hide it.

Run `pnpm run verify:tools` before deploying, and after any base-image change.

---

## Working here

```
pnpm install
pnpm run validate      # boundary checks
pnpm run typecheck
pnpm run lint
pnpm run verify:tools  # pinned versions + licence attestations
pnpm test              # builds, then runs node:test over dist/
```

The analysis tests are integration tests against real Praat and ffmpeg, with
fixtures synthesised by ffmpeg so expected values are known rather than golden:
a 220 Hz tone measures 220 Hz, and silence has no pitch at all. A mocked Praat
would only prove the parser matches the mock.

Install the tools with `apt-get install praat ffmpeg` on Ubuntu 24.04, which is
what CI does.

---

## Platform standards

- **Node** 20 LTS. **TypeScript** 5, `strict`, plus `exactOptionalPropertyTypes`
  and `noUncheckedIndexedAccess`.
- **pnpm**. `pnpm-lock.yaml` is the lockfile; `package-lock.json` or `yarn.lock`
  present is a failure.
- **Named exports only.** No default export anywhere (ESLint enforces it).
- **NFC normalization is canonical for language data** — never NFKC or NFKD.
  African orthography distinctions must survive.
- Namespace convention: `Starisian\Sparxstar\{ProductName}\…`; repositories are
  `sparxstar-{product-name}`.
- `ai_manifest.json` is checked before creating any symbol and updated when one
  is added, removed or renamed.
