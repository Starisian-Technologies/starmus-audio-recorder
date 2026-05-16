# Contributing to Starmus Audio Recorder

## Scope

This repository accepts production-focused improvements that preserve architecture and reliability. Avoid major refactors unless explicitly requested.

## Engineering Principles

- Preserve offline-first behavior
- Preserve bootstrap-driven initialization contracts
- Prefer incremental, reviewable changes
- Follow WordPress + PSR compatibility expectations
- Do not introduce new global state

## Branch and PR Process

1. Create a branch from `main`
2. Keep commit scope focused
3. Run relevant validations locally
4. Open PR with clear change rationale and risk notes
5. Address review feedback before merge

## Required Validation

### JavaScript/CSS/Markdown

```bash
pnpm run lint
pnpm run build
pnpm run test
```

### PHP

```bash
composer run lint
composer run analyze
composer run test:unit
```

If environment constraints block a check, include explicit evidence in the PR.

## Documentation Expectations

When changing behavior or public integration points, update:

- `README.md` (if setup/usage changed)
- `ARCHITECTURE.md` (if execution boundaries changed)
- `SECURITY.md` (if trust boundaries changed)
- `CHANGELOG.md` (for release-facing changes)

## Code Standards

- Namespace: `Starisian\Sparxstar\Starmus\*`
- Hooks/actions/filters: `starmus_*`
- Frontend handles: `starmus-audio-*`
- Sanitize → validate → escape
- Capability + nonce checks for mutations

## Security Reporting

Do not open public issues for vulnerabilities.

Use: `security@starisian.com` (see `SECURITY.md`).
