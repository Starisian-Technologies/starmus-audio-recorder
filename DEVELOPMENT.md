# Development Guide

## Local Toolchain

- PHP `8.2+`
- Composer `2.x`
- Node.js `18.17+`
- pnpm `10.29.2`
- Docker (for `@wordpress/env` workflows)

## Install

```bash
pnpm install --frozen-lockfile
composer install --no-interaction
```

## Common Commands

### Lint

```bash
pnpm run lint
composer run lint
```

### Static analysis

```bash
composer run analyze
```

### Build

```bash
pnpm run build
```

### Tests

```bash
composer run test:unit
pnpm run test
pnpm run test:wp-env
```

## Environment Notes

- E2E requires Playwright browser binaries (`pnpm exec playwright install --with-deps chromium`).
- WordPress integration tests require `wp-env` and Docker availability.
- Composer install can fail in restricted networks if WordPress package mirrors are unreachable.

## Release Hygiene

Before opening PR:

1. Confirm changed files are intentional
2. Re-run lint/build/tests for touched layers
3. Update changelog entries as needed
4. Ensure docs reflect behavior/contract changes

## Architectural Safety Checklist

- Bootstrap object present before module init
- Recorder/editor mode gates unchanged
- Event listeners remain single-attach
- Offline queue semantics preserved
- Capability and nonce checks preserved
