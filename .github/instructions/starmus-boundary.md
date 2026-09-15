# Repository role — ADR-034 / ADR-035 / ADR-036 / ADR-038 / ADR-039

**This repository is the Spoken Audio Node** under
[ADR-034](https://github.com/Starisian-Technologies/sparxstar-architecture-governance-registry/blob/main/standards/decisions/ADR-034-capture-experience-vs-audio-lifecycle-split.md)
and
[ADR-038](https://github.com/Starisian-Technologies/sparxstar-architecture-governance-registry/blob/main/standards/decisions/ADR-038-spoken-audio-node-is-a-node-service.md).

Role assignment lives here; the reason lives in the ADRs. Do not restate the
rationale in this repository — cite the ADR numbers.

**Name:** this repository is `starmus-audio-recorder` on GitHub; the ADRs name
the role's repository `sparxstar-starmus-audio`. The rename has not happened and
is not made by this file. Read the remote, not the ADR, for the current name.

## Decision status

ADR-034, ADR-035, ADR-036, ADR-038 and ADR-039 were **ratified by the owner on
2026-09-12** and carry Status `Accepted` in the registry. The restructure hold
that stood while they were `Proposed` is lifted, and the restructure they govern
is carried out here.

If any of them is later superseded, this repository's role changes with it. A
superseding ADR is the only thing that moves this boundary — never a decision
taken inside this repository.

## What this repository owns

The platform audio asset lifecycle, as a Node/TypeScript service: ingest
acceptance and integrity, storage references, server-side derivative processing,
acoustic measurement, bulk import from physical media and institutional
archives, delay-tolerant processing queues, authorized consumption (regional
access controls, short-lived URLs), and per-collection / per-project cost
visibility.

## What it does not own

| Concern | Owner |
| --- | --- |
| Microphone capture, local/offline handling, the chunked-upload client, capture UX | capture UI package (`sparxstar-starmus-ui`) |
| Transport, storage operations, temporary-URL issuance | media ingest service (`files.sparxstar.com`) |
| Canonical transcription, translation, linguistic interpretation, human correction | ESU |
| Word-level alignment of record | ESU (Yahura) |
| The annotation/review surface and its records | `sparxstar-esu-ui` |
| Elicitation script presentation, pacing, reader position | elicitation pacing package |
| Commissioning and rights for releases | rights module |

## Removals carried out on acceptance

Each was removed in the restructure, and `scripts/validate-node-boundary.cjs`
fails the build if any returns:

- WordPress templates, shortcodes and admin screens
- CPT/attachment persistence and SCF as the primary database (the DVE alignment
  field map remains authoritative for the records ESU/DVE hold, not for this
  service's store)
- Browser recorder code — the capture path has one home, the UI repo (ADR-034)
- CSS and frontend rendering
- Transcript review (ESU's record, ADR-034)
- Prosodic interpretation (ESU's record; acoustic measurement stays here,
  ADR-036)

The CMS host product's own home is **not decided by ADR-034** and is not created
here. Its code remains in this repository's history and on `main` until the
repository that carries it exists.

## Seams this repository honors

- **ESU:** audio is consumed under authorization granted here; this service
  never holds transcript, translation, or linguistic-interpretation records.
- **Capture UI:** `sparxstar-starmus-ui` uploads the original once at source
  quality; adaptation is chunk size and scheduling only (ADR-035). Visible upload
  recovery — no silent restarts of large transfers, and no full-file path.
- **Media ingest service:** this service **authorizes access**; the standalone
  media-type-agnostic transport service performs storage operations and issues
  the requested temporary URL. Transport is not implemented here. Assets are
  referenced by immutable `audio_asset_id` and derivative identifiers; no durable
  storage URL is stored or emitted anywhere, including intake events.
- **Renditions:** immutable original (evidence + timeline authority) / standard
  analysis derivative (deterministic, timeline-preserving, retaining its
  manifest, source hash and tool version) / optional enhanced-denoised (never replacement
  evidence, never analysis input) / playback incl. waveform data / publication
  (release rendering, walled off). Pitch-corrected audio never feeds
  transcription, phonetic analysis, pronunciation evaluation, or model training.
- **Analysis worker (ADR-038):** Praat runs here as a **pinned, server-side
  analysis worker** — a separate process, never linked; version pinned per job
  class; its licence read from the upstream distribution and recorded in
  `tools.pinned.json` before deployment. It produces the timeline-preserving
  analysis derivative, VAD segments, acoustic measurements, quality flags, and
  TextGrid-compatible annotation candidates that enter ESU as lowest-authority
  machine candidates. Every result retains its Praat version, script, parameters,
  hashes, and original-timeline coordinates. **"Prepared" means decoded,
  measured, segmented, and reproducibly normalized — never cleaned, cut,
  pitch-corrected, or linguistically interpreted.** Measurement classes whose
  published reliability floors the recording does not meet are marked unreliable,
  never silently reported.
- **Waveform review:** the annotation editor and its cue-events module belong to
  `sparxstar-esu-ui`; annotation records are ESU records. This service produces
  and serves the precomputed waveform-data derivative. Peaks.js and audiowaveform
  licences must be read from the upstream BBC repositories and recorded in
  `tools.pinned.json` before either is bundled or shipped.
- **Editing (ADR-039):** the preservation path never edits audio. Originals are
  immutable evidence and the timeline authority; transcription and analysis
  consume the original or the standard analysis derivative; one timeline (the
  original's) for all timestamps. Dead air and false starts are handled by
  segmentation/annotation in `sparxstar-esu-ui`, never by cutting. Release
  rendering is a separate, walled-off job class whose outputs never enter the
  linguistic pipeline; pre-submission retake stays in `sparxstar-starmus-ui`.
- **Profiles:** `conversation`, `documentation`, `import` per ADR-035.

## Open questions this repository must not answer on its own

- **OQ-021** — numeric floors and permitted codecs per capture profile. AIWA and
  the acoustic-analysis owner. `src/domain/captureProfile.ts` carries the
  machinery and no values; an unruled profile reports `gated: false`, which is
  not a pass. `prepareAnalysisDerivative` refuses to downsample for the same
  reason: a rate chosen here would answer the question by discarding the material
  it is about.
- **OQ-022** — which repository is the single home for the Starmus↔ESU intake
  contract. ADR-034 filed the seam in the governance registry as
  `contracts/spoken-audio-asset-to-records.md`; the ADR-038 ruling names a
  separate platform-contracts repository. Until it is settled, `IntakeEvent` in
  `src/ports/index.ts` is a **domain** shape, not a wire schema, and no adapter
  maps it to a wire format.
- **The four terms the capture→ingestion contract records as owed** — the
  consumer's endpoint path and auth model, the upload metadata key set, the
  acknowledgement and error envelope, and confirmation of the checksum
  algorithm. This is why every port in `src/ports/index.ts` is an interface with
  no default implementation: a default would be a guess at one of them, shipped.
