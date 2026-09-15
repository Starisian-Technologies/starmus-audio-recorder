# Copilot instructions — the Spoken Audio Node

**Read `.github/instructions/starmus-boundary.md` and `AGENTS.md` before
proposing anything.** The boundary file assigns this repository's role; AGENTS.md
is how to work inside it. Everything below is a summary for review, not a second
home for those rules.

This repository was a WordPress plugin. It is not one now. ADR-034 and ADR-038
were ratified on 2026-09-12 and the restructure was carried out: the plugin
surfaces are gone and the repository is a Node/TypeScript service owning the
platform audio asset lifecycle.

## Reject a change that does any of these

- Adds PHP, a WordPress surface (shortcode, CPT, admin screen, post meta, CMS
  REST route), browser capture code, or CSS/frontend rendering.
- Links a native library, or adds a native-binding dependency. Analysis tools are
  spawned as separate processes. For Praat this is a licensing requirement: it is
  GPL-2+ and this service is BUSL-1.1.
- Adds a tool without a pinned version and a licence attestation read from that
  tool's own distribution.
- Writes to, deletes, trims, splices or cuts a registered original — or adds any
  edit capability outside `src/release/`.
- Models a durable storage URL in a record, an event, or an evidence field.
- Declares a `transcript` or `translation` field. Those are ESU's records.
- Turns Praat's `--undefined--` into a number, or reports a measurement without
  its extraction parameters and tool version.
- Reports a perturbation measure (jitter, shimmer, harmonicity) as reliable while
  its floor is unruled.
- Puts a numeric floor on a capture profile, or downsamples in the standard
  analysis derivative. That is OQ-021, and it belongs to AIWA and the
  acoustic-analysis owner.
- Folds a stereo source down to mono.
- Gives any port in `src/ports/` a default implementation, or invents a wire
  shape for the intake seam. Four terms of the capture→ingestion contract are
  recorded as owed, and OQ-022 has not named the intake contract's home.
- Adds a timestamp that is not an offset into the original recording.

## Check before approving

```
pnpm run validate      # boundary checks — most of the list above
pnpm run typecheck
pnpm run lint
pnpm run verify:tools  # pinned versions and licence attestations
pnpm test
```

## Two habits worth keeping

**Prefer a refusal to a guess.** Where a value has not been ruled on, this
codebase says so — `gated: false` rather than a pass, `null` rather than a
plausible number, a thrown error rather than a default endpoint. A change that
fills one of those in has usually answered someone else's question.

**Describe, never judge.** Acoustic measurements describe the signal. Nothing
here decides whether a word, pronunciation or grammar form is correct.
