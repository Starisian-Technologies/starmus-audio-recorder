# Development Guide

## Local Toolchain

- PHP `8.2+`
- Composer `2.x`
- Node.js `20+`
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
node --test tests/*.test.js
pnpm run test
pnpm run test:wp-env
```

## Bundle Size Budget

Engineering target: **≤ 60 kB gzipped** per bundle (Sparxstar performance standard).

| Bundle | Current (brotlied) | CI limit | Status |
|---|---|---|---|
| `starmus-audio-recorder-script.bundle.min.js` | ~105 kB | 105 kB | ⚠️ over target |
| `starmus-prosody-engine.min.js` | ~18 kB | 20 kB | ✅ |
| `sparxstar-app-mode.min.js` | ~10 kB | 12 kB | ✅ |
| `starmus-legal.min.js` | ~7 kB | 10 kB | ✅ |

The main bundle exceeds the 60 kB target because it bundles `peaks.js` (waveform editor, used only on editor pages) and IE-11/Android-4.4 `core-js` polyfills inline. To reach the budget, lazy-load `peaks.js` for editor mode only and update Babel targets to drop IE 11 support.

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
