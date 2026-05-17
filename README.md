# Starmus Audio Recorder

> Mobile-first, offline-first WordPress audio acquisition for low-bandwidth and unstable network environments.

[![CI](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/ci.yml/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/ci.yml)
[![Security Checks](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/security.yml/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/security.yml)
[![Tests](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/test.yml/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/test.yml)

## Project Purpose

Starmus Audio Recorder is a proprietary WordPress plugin that captures, uploads, and manages audio recordings with resilient offline behavior.

It is optimized for:

- low-end Android devices
- unstable 2G/3G networks
- resumable upload flows
- consent-aware metadata capture

## Repository Role in the Architecture

This repository contains the plugin implementation for:

- recorder and editor front-end modules
- WordPress integration (shortcodes, hooks, REST endpoints)
- offline queue and resumable upload orchestration
- plugin service layer and governance/security policies

For architecture details, see [ARCHITECTURE.md](ARCHITECTURE.md).

## Requirements

- WordPress `6.8+`
- PHP `8.2+`
- Node.js `18.17+`
- pnpm `10.29.2`
- Composer `2.x`

## Installation / Setup

### 1) Clone and install dependencies

```bash
pnpm install --frozen-lockfile
composer install --no-interaction
```

> If Composer cannot download WordPress package dependencies in your environment, run JS workflows only and document the limitation in your PR.

### 2) Start local WP environment (optional for integration/e2e)

```bash
pnpm run env:start
```

### 3) Stop local WP environment

```bash
pnpm run env:stop
```

## Build and Test Commands

### JavaScript / CSS / Markdown

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

### Documentation

```bash
pnpm run docs
composer run docs
```

## Usage

Primary shortcodes:

- `[starmus_audio_recorder_form]` — recorder UI
- `[starmus_my_recordings]` — user recordings list
- `[starmus_audio_editor]` — annotation editor

Editor access requires valid post context and nonce enforcement where applicable.

## Development Workflow

1. Read [DEVELOPMENT.md](DEVELOPMENT.md) and [CONTRIBUTING.md](CONTRIBUTING.md)
2. Create a focused branch
3. Keep changes incremental and architecture-preserving
4. Run lint/build/test commands relevant to changed layers
5. Open PR using repository template

## Security and Governance

- Security policy: [SECURITY.md](SECURITY.md)
- Terms and ethics: [TERMS.md](TERMS.md)
- Maintainers and ownership: [MAINTAINERS.md](MAINTAINERS.md)

## License

This repository is proprietary and confidential.

See [LICENSE.md](LICENSE.md) for full terms.
