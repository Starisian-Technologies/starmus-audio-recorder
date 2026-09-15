# Contributing to the Spoken Audio Node

## Scope

This repository accepts production-focused improvements that preserve the
boundaries in `.github/instructions/starmus-boundary.md` and the rules in
`AGENTS.md`. Avoid major refactors unless explicitly requested.

The boundaries are not style preferences: they are ratified platform decisions
(ADR-034, ADR-035, ADR-036, ADR-038, ADR-039), and a change that crosses one is
a governance change, not a code change. If you believe a boundary is wrong, the
route is a superseding ADR in the governance registry — never a decision taken
inside this repository.

## Engineering principles

- The registered original is immutable evidence and the only timeline.
- Deny nothing; quarantine instead. Every path keeps the contributor's material.
- A measurement without its settings is not reproducible, so it is not stored.
- Describe, never judge: measurements describe the signal and never rule on a
  word, pronunciation or grammar form.
- Prefer a refusal to a guess. Where a value has not been ruled on, say so.
- Analysis tools are spawned, never linked.
- Prefer incremental, reviewable changes. Do not introduce new global state.

## Branch and PR process

1. Create a branch from `main`.
2. Keep commit scope focused.
3. Run the validations below locally.
4. Open a PR with clear rationale and risk notes.
5. Address review feedback before merge.

## Required validation

```sh
pnpm run validate      # boundary checks
pnpm run typecheck
pnpm run lint
pnpm run verify:tools  # pinned versions and licence attestations
pnpm test
```

`verify:tools` needs Praat and ffmpeg at the pinned versions. If your machine
ships different builds the check fails by design — see `DEVELOPMENT.md`. Include
explicit evidence in the PR if an environment constraint blocked a check.

## Documentation expectations

When behaviour or a public integration point changes, update:

- `README.md` (setup or usage)
- `ARCHITECTURE.md` (execution boundaries or layering)
- `.github/instructions/starmus-boundary.md` (only when an ADR moved the
  boundary — never to record a local decision)
- `SECURITY.md` (trust boundaries)
- `CHANGELOG.md` (release-facing changes)
- `ai_manifest.json` (any symbol added, removed or renamed)

## Code standards

- TypeScript 5, `strict`. Named exports only — no default export.
- Node 20 LTS, pnpm. `pnpm-lock.yaml` is the lockfile.
- Namespace convention for PHP-side siblings:
  `Starisian\Sparxstar\{ProductName}\…`; repositories are
  `sparxstar-{product-name}`.
- NFC normalization is canonical for language data — never NFKC or NFKD. African
  orthography distinctions must survive.
- Validate at the boundary, narrow rather than cast, and let the domain types
  carry the rules inward.

## Security reporting

Do not open public issues for vulnerabilities. Use `security@starisian.com` —
see `SECURITY.md`.
