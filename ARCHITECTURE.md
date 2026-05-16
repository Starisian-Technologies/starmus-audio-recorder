# Starmus Audio Recorder Architecture

## Repository Responsibilities

This repository owns the Starmus recorder/editor plugin implementation and its runtime contracts across WordPress and browser clients.

## Layer Boundaries

### 1) Plugin bootstrap (`starmus-audio-recorder.php`, `src/StarmusAudioRecorder.php`)

- validates runtime requirements
- initializes core services and hooks
- wires component graph

### 2) Frontend orchestration (`src/frontend/*`, `src/js/*`)

- recorder and editor rendering
- state, UI, recording, transcript, and queue coordination
- progressive enhancement and fallback handling

### 3) API and data services (`src/api/*`, `src/services/*`, `src/data/*`)

- REST endpoints
- file/audio post-processing services
- persistence abstraction and repository operations

### 4) Admin and operational workflows (`src/admin/*`, `src/cron/*`, `src/cli/*`)

- admin jobs and maintenance controls
- scheduled processing
- WP-CLI hooks

## Namespace and Naming Conventions

- PHP namespace root: `Starisian\Sparxstar\Starmus`
- REST namespace constants must remain explicit per module
- Hook prefixes: `starmus_*`
- Front-end handles: `starmus-audio-*`

## Execution Flow

1. WordPress loads plugin bootstrap
2. Requirement checks run
3. Core singleton initializes settings + DAL + components
4. Frontend shortcodes/templates emit bootstrap data
5. JS initializes recorder/editor workflows in allowed page contexts
6. Submissions route via tus/REST/offline queue path

## Security Assumptions

- all mutation endpoints enforce capability and nonce checks
- all output paths escape user-originated content
- all user input follows sanitize → validate → escape
- offline queue stores operational payloads, not unrestricted arbitrary code

## Governance Assumptions

- repository is proprietary and confidential
- maintainers enforce production-quality validation before merge
- unresolved security concerns are disclosed privately

## Architectural Invariants (Do Not Break)

- bootstrap-first initialization contract
- offline queue remains durable and retry-aware
- recorder/editor responsibilities remain separated
- event handlers attach once per lifecycle path
- no uncontrolled global state beyond required bootstrap/exposed APIs

## Dependency Expectations

- WordPress core APIs for CMS integration
- ACF/SCF-compatible metadata workflows
- tus-compatible upload endpoints for resumable transfer paths
- vendorized/build-pipeline assets generated via existing scripts
