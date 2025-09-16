# Copilot Instructions for Starmus Audio Recorder

## Project Overview
- **Purpose:** Mobile-first, offline-capable WordPress plugin for audio recording, annotation, and consent capture, optimized for low-bandwidth and legacy devices.
- **Architecture:** Modular, progressive enhancement. Modern browsers get advanced features (calibration, speech-to-text); legacy browsers get a fallback. Core flows are chunked uploads (tus.io), offline queue (IndexedDB), and robust REST API integration.

## Key Components & Patterns
- **PHP:**
  - `src/includes/StarmusPlugin.php`: Main plugin controller, hooks registration.
  - `src/frontend/StarmusAudioRecorderUI.php`: Handles UI, chunked uploads, metadata, and redirects.
  - `src/frontend/StarmusAudioEditorUI.php`: Annotation editor and REST API.
  - Custom post types: `audio-recording`, `consent-agreement`.
  - Taxonomies: `language`, `recording_type`.
- **JS:**
  - `src/js/starmus-audio-recorder-module.js`: Recording engine (MediaRecorder, calibration, speech recognition).
  - `src/js/starmus-audio-recorder-submissions-handler.js`: Uploads, offline queue, tus.io, REST fallback.
  - `src/js/starmus-audio-recorder-ui-controller.js`: UI logic, validation, delegates to engine/uploader.
  - `src/js/starmus-audio-recorder-submissions.js`: Legacy fallback, geolocation, polyfills.
- **CSS:**
  - Payload budgets: ≤ 60KB JS, ≤ 25KB CSS (gzipped).

## Developer Workflows
- **Build:** Use `msbuild` (see VSCode tasks) for .NET components if present. For PHP/JS, standard Composer/NPM workflows.
- **Test:**
  - PHP: PHPUnit (`phpunit.xml.dist`), integration/REST tests in `tests/integration/`.
  - JS: Playwright E2E (`tests/e2e/`), run via `npx playwright test`.
- **Lint:**
  - PHP: `phpcs.xml.dist`, `phpstan.neon.dist`.
  - JS/CSS: `eslint.config.js`, `stylelint.config.js`, `prettierrc.json`.
- **Docs:** Update `README.md`, `CHANGELOG.md` for all major changes.

## Conventions & Guardrails
- **Naming:** Prefix all handles/routes with `star-<slug>`. REST: `star-<slug>/v1`.
- **Security:** Use nonces/capabilities for privileged actions. Sanitize input, escape output, use prepared SQL, strict MIME for uploads.
- **Privacy:** No PII in logs. Consent gating for recording/analytics. Explicit delete/opt-out flows.
- **Dependencies:** No heavy front-end frameworks. Use vanilla JS, progressive enhancement. Bundle Composer/NPM deps in `/vendor/js/`.
- **Licensing:** SPDX header, copyright Starisian Technologies.

## Integration Points
- **tus.io:** Resumable uploads. Configure endpoint in `StarmusAudioRecorderUI.php`.
- **audiowaveform:** Required for waveform generation in editor (must be on server PATH).
- **Hooks:**
  - `starmus_before_recorder_render`, `starmus_after_audio_upload`, `starmus_audio_upload_success_response` (see `README.md` for usage).
  - Editor: `starmus_before_editor_render`, `starmus_editor_template`, `starmus_before_annotations_save`, `starmus_after_annotations_save`.

## Examples
- See `README.md` for shortcode usage and hook examples.
- JS modules are decoupled: UI delegates to engine/uploader, never mixes concerns.

## Acceptance
- All code must pass lint/tests, respect payload budgets, and work offline (queue resumes after drop).
- i18n strings must be extractable.

---
For more, see `AGENTS.md` and `README.md`. When in doubt, prefer minimal, secure, and offline-first solutions.
