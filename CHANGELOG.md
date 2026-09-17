# Changelog

All notable changes to this repository are documented in this file.

The format follows Keep a Changelog principles and uses semantic release intent where practical.

## [Unreleased]

### Changed — the ADR-038 restructure

This repository is no longer a WordPress plugin. ADR-034, ADR-035, ADR-036,
ADR-038 and ADR-039 were ratified on 2026-09-12, and the restructure they govern
was carried out: the repository is the **Spoken Audio Node**, a Node/TypeScript
service owning the platform audio asset lifecycle.

### Added

- The domain model: the rendition ladder, the original timeline, transformation
  manifests, measurements with their provenance and reliability, quality flags
  and processing routes, VAD segments and annotation candidates.
- A pinned, server-side **Praat analysis worker** — separate process, never
  linked, version pinned per job class, licence read from the tool's own
  distribution and recorded in `tools.pinned.json`. `praat/measure.praat` and
  `praat/segment.praat` echo back every parameter they were given.
- Deterministic, timeline-preserving analysis derivatives via ffmpeg, with a
  transformation manifest, source hash and tool versions retained.
- TextGrid export of annotation candidates, verified by having Praat read it back.
- Ingest acceptance and integrity that never rejects — a failed checksum or a
  missing capture profile is a flag, never grounds to discard material.
- Executable preservation guards for ADR-039, and a walled-off release-rendering
  job class that is the only place an edit may happen.
- `scripts/validate-node-boundary.cjs`, which fails the build on any of the
  boundary violations ADR-034 and ADR-038 forbid.
- `pnpm run verify:tools`, a deployment gate that refuses a tool whose reported
  version is not the pinned one.

### Removed

- The first-generation WordPress plugin in its entirety: templates, shortcodes,
  admin screens, CPT/SCF persistence, browser recorder code, CSS and frontend
  rendering, transcript review, prosodic interpretation, and the PHP toolchain.
  The capture path has one home, and it is the capture UI package (ADR-034).

### Added

- Production-readiness documentation set for onboarding, architecture, development, and governance.
- Contribution guidance and GitHub collaboration templates for issue/PR consistency.

### Changed

- Standardized repository-level docs for setup, validation commands, and security reporting.
- Clarified architectural invariants and trust-boundary expectations.

### Fixed

- Corrected security escalation contact references and clarified private reporting guidance.

## Historical Notes

Prior build-hash-only entries were generated operationally and are superseded by structured changelog tracking in this file.
