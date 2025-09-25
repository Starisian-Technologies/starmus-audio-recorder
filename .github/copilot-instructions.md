# Copilot Instructions for Starmus Audio Recorder

## Project Overview
Mobile-first WordPress audio recorder designed for West Africa's constrained networks. Built with offline-first architecture, progressive enhancement (Tier A/B/C browsers), and strict payload budgets (≤60KB JS, ≤25KB CSS gzipped).

## Key Components & Patterns
**Core Architecture:**
- `src/StarmusPlugin.php`: Main plugin controller, singleton pattern, hooks registration
- `src/frontend/StarmusAudioRecorderUI.php`: Two-step UI, chunked uploads, tus.io integration
- `src/frontend/StarmusAudioEditorUI.php`: Peaks.js-based editor, REST API endpoints
- Custom post types: `audio-recording`, `consent-agreement`
- Taxonomies: `language`, `recording_type`

**JavaScript Modules (Separation of Concerns):**
- `src/js/starmus-audio-recorder-module.js`: Pure recording engine (MediaRecorder, calibration)
- `src/js/starmus-audio-recorder-submissions-handler.js`: Upload logic, IndexedDB queue, tus.io
- `src/js/starmus-audio-recorder-ui-controller.js`: Form validation, two-step flow delegation
- `src/js/starmus-offline-sync.js`: Legacy fallback, geolocation, polyfills

## Developer Workflows
**Build & Test Commands:**
```bash
# Frontend/E2E testing
npm test                    # Runs Playwright E2E + accessibility tests
npm run test:e2e           # End-to-end tests
npm run test:a11y          # WCAG compliance tests

# PHP/Backend testing  
composer test              # PHPUnit + quality checks (requires vendor install)
composer run test:unit     # Unit tests only
composer run lint:php      # PHPCS code style
composer run analyze:php   # PHPStan static analysis

# Build pipeline
npm run build              # Full build: clean → vendor → CSS/JS → hash → version sync
```

**Key Config Files:** `phpunit.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist`, `eslint.config.js`, `playwright.config.js`

## Naming Conventions & Standards
- **Namespace:** `Starmus\\ComponentName\\` (PSR-4 autoload)
- **Handles/Routes:** `star-<slug>-*` (e.g., `star-audio-recorder-upload`)
- **REST Namespace:** `star-<slug>/v1` (e.g., `/wp-json/star-audio-recorder/v1/upload`)
- **Hook Prefix:** `starmus_` (e.g., `starmus_before_recorder_render`)
- **Error Handling:** Internals throw exceptions, boundaries return `WP_Error`
- **JS Responses:** `{ ok: boolean, code: string, message: string, data: object }`

## Security & Offline Patterns
- **Capabilities + nonces** for privileged actions
- **IndexedDB-first** with localStorage fallback for offline queue
- **Chunked uploads** with resume capability (tus.io protocol)
- **Input sanitization:** `sanitize_text_field()`, `absint()`, `$wpdb->prepare()`
- **Output escaping:** `esc_html()`, `esc_attr()`, `wp_kses()`

## Integration Points & Hooks
```php
// Core recorder hooks
do_action('starmus_before_recorder_render', $instance_id);
do_action('starmus_after_audio_upload', $post_id, $file_path, $metadata);
$response = apply_filters('starmus_audio_upload_success_response', $response, $post_id, $form_data);

// Editor hooks  
do_action('starmus_before_editor_render', $post_id);
do_action('starmus_before_annotations_save', $post_id, $annotations);
```

## Mobile-First Requirements
- **Progressive enhancement:** Tier A (modern), Tier B (legacy), Tier C (file upload fallback)
- **Offline queue resilience:** FIFO IndexedDB queue with retry logic
- **Bundle constraints:** Validate with `npm run size-check`
- **Accessibility:** WCAG 2.1 AA compliance, keyboard navigation
- **Legacy browser support:** Avoid ES2020+ features, include polyfills

Refer to `AGENTS.md`, `INSTRUCTIONS.md`, and `TESTING.md` for detailed conventions. When in doubt, prioritize offline-first, secure, and minimal solutions.
