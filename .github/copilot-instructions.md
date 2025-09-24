# Copilot Instructions for Starmus Audio Recorder

## Project Overview

## Key Components & Patterns
  - `src/includes/StarmusPlugin.php`: Main plugin controller, hooks registration.
  - `src/frontend/StarmusAudioRecorderUI.php`: Handles UI, chunked uploads, metadata, and redirects.
  - `src/frontend/StarmusAudioEditorUI.php`: Annotation editor and REST API.
  - Custom post types: `audio-recording`, `consent-agreement`.
  - Taxonomies: `language`, `recording_type`.
  - `src/js/starmus-audio-recorder-module.js`: Recording engine (MediaRecorder, calibration, speech recognition).
  - `src/js/starmus-audio-recorder-submissions-handler.js`: Uploads, offline queue, tus.io, REST fallback.
  - `src/js/starmus-audio-recorder-ui-controller.js`: UI logic, validation, delegates to engine/uploader.
  - `src/js/starmus-audio-recorder-submissions.js`: Legacy fallback, geolocation, polyfills.
  - Payload budgets: ≤ 60KB JS, ≤ 25KB CSS (gzipped).

## Developer Workflows
  - PHP: PHPUnit (`phpunit.xml.dist`), integration/REST tests in `tests/integration/`.
  - JS: Playwright E2E (`tests/e2e/`), run via `npx playwright test`.
  - PHP: `phpcs.xml.dist`, `phpstan.neon.dist`.
  - JS/CSS: `eslint.config.js`, `stylelint.config.js`, `prettierrc.json`.

## Conventions & Guardrails

## Integration Points
  - `starmus_before_recorder_render`, `starmus_after_audio_upload`, `starmus_audio_upload_success_response` (see `README.md` for usage).
  - Editor: `starmus_before_editor_render`, `starmus_editor_template`, `starmus_before_annotations_save`, `starmus_after_annotations_save`.

## Examples

## Acceptance

For more, see `AGENTS.md` and `README.md`. When in doubt, prefer minimal, secure, and offline-first solutions.
