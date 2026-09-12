# Repository role and restructure hold — ADR-034 / ADR-035 / ADR-036 / ADR-038 / ADR-039

**This repository is the Spoken Audio Node** under
[ADR-034](https://github.com/Starisian-Technologies/sparxstar-architecture-governance-registry/blob/main/standards/decisions/ADR-034-capture-experience-vs-audio-lifecycle-split.md)
and
[ADR-038](https://github.com/Starisian-Technologies/sparxstar-architecture-governance-registry/blob/main/standards/decisions/ADR-038-spoken-audio-node-is-a-node-service.md)
(Spoken Audio Node implementation identity).

Role assignment lives here; the reason lives in the ADRs. Do not restate the
rationale in this repository — cite the ADR numbers.

**Name:** this repository is `starmus-audio-recorder` on GitHub; the ADRs name
the role's repository `sparxstar-starmus-audio`. The rename has not happened and
is not made by this file. Read the remote, not the ADR, for the current name.

## Hold

ADR-034, ADR-035, ADR-036, ADR-038 and ADR-039 are **Proposed**. **Do not
restructure this repository until they are Accepted.** Ratification is the
owner's act, made in the governance registry. The corresponding hold already
exists in `sparxstar-starmus-ui`; this file is its counterpart so that neither
coding agent moves first.

Nothing below is built, deleted, or migrated while this hold stands. Recording
the boundary is not the same as carrying it out.

## What this repository owns once the ADRs are Accepted

The platform audio asset lifecycle, as a Node/TypeScript service: ingest
acceptance and integrity, R2-backed storage references, server-side derivative
processing (originals uploaded once; low-bandwidth playback derivatives for Sky
and ESU created here), acoustic measurement, bulk import from physical media and
institutional archives, delay-tolerant processing queues, authorized consumption
(regional access controls, short-lived URLs), and per-collection / per-project
cost visibility.

## Required removals on acceptance

- WordPress templates and shortcodes (`src/templates/`, shortcode loaders)
- WordPress admin screens (`src/admin/`)
- CPT/attachment persistence and SCF as the primary database (`src/data/` DAL
  layers, `acf-json/` as datastore — the DVE alignment field map remains
  authoritative for the records that ESU/DVE hold, not for this service's store)
- Browser recorder code (`src/js/` — fifteen files duplicated with
  `sparxstar-starmus-ui`, all diverged; the UI repo is the single home for
  capture-path source per ADR-034 — where copies disagree, read both and decide
  from the code)
- CSS and frontend rendering (`src/css/`, `src/frontend/`)
- Transcript review (ESU's record, ADR-034)
- Prosodic interpretation (ESU's record; acoustic measurement stays here,
  ADR-036)

## Seams this repository must honor

- **ESU:** audio is consumed under authorization granted here; this service
  never holds transcript, translation, or linguistic-interpretation records.
- **Capture UI:** `sparxstar-starmus-ui` uploads the original once at source
  quality; adaptation is chunk size and scheduling only (ADR-035). Visible
  upload recovery — no silent restarts of large transfers.
- **Media ingest service:** ruled (ADR-038) — this service **authorizes
  access**; the standalone media-type-agnostic transport service
  (`files.sparxstar.com`) performs storage operations and issues the requested
  temporary URL. Do not implement transport here. Assets are referenced by
  immutable `audio_asset_id` and derivative identifiers; no durable storage URL
  is stored or emitted anywhere, including intake events.
- **Renditions:** immutable original (evidence + timeline authority) / standard
  analysis derivative (deterministic, timeline-preserving, manifest + source
  hash + tool version retained) / optional enhanced-denoised (never replacement
  evidence) / playback incl. waveform data / publication (release rendering,
  walled off). Pitch-corrected audio never feeds transcription, phonetic
  analysis, pronunciation evaluation, or model training.
- **Analysis worker (ADR-038):** Praat runs here as a **pinned, server-side
  analysis worker** — a separate process, never linked into the service; version
  pinned per job class; its license read from the upstream distribution and
  recorded before deployment. It produces the timeline-preserving analysis
  derivative, VAD segments, acoustic measurements, quality flags, and
  TextGrid-compatible annotation candidates that enter ESU as lowest-authority
  machine candidates. Every result retains its Praat version, script,
  parameters, hashes, and original-timeline coordinates. **"Prepared" means
  decoded, measured, segmented, and reproducibly normalized — never cleaned,
  cut, pitch-corrected, or linguistically interpreted.** Measurement classes
  whose published reliability floors the recording does not meet are marked
  unreliable, never silently reported.
- **Waveform review:** `starmus-audio-editor.js`, `starmus-cue-events.js`, and
  the editor PHP/templates migrate to `sparxstar-esu-ui`; annotation records are
  ESU records. This service produces and serves the precomputed waveform-data
  derivative. Verify Peaks.js and audiowaveform licenses from the upstream BBC
  repositories before bundling or shipping.
- **Editing (ADR-039):** the preservation path never edits audio. Originals are
  the immutable evidence and timeline authority; transcription and analysis
  consume the original or the standard analysis derivative; one timeline (the
  original's) for all timestamps. Dead air and false starts are handled by
  segmentation/annotation in `sparxstar-esu-ui`, never by cutting. Release
  rendering (cleaned/spliced copies for publication) is a separate, walled-off
  job class in this service whose outputs never enter the linguistic pipeline;
  pre-submission retake stays in `sparxstar-starmus-ui`.
- **Profiles:** `conversation`, `documentation`, `import` per ADR-035.
  `documentation` numeric floors are OQ-021 — open on AIWA and the
  acoustic-analysis owner; do not invent values.

## Open questions this repository must not answer on its own

- **OQ-021** — numeric floors and permitted codecs per capture profile. AIWA and
  the acoustic-analysis owner. No value is implemented here before it is ruled
  on.
- **OQ-022** — which repository is the single home for the Starmus↔ESU intake
  contract. ADR-034 filed the seam in the governance registry as
  `contracts/spoken-audio-asset-to-records.md`; the ADR-038 ruling names a
  separate platform-contracts repository. Until that is settled, no wire shape
  is implemented here and no second copy of the contract is written.
