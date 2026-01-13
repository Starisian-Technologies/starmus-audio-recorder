# Playwright E2E Test Implementation Plan

## Phase 1: Configuration Updates

- [x] Update `playwright.config.js` with device emulation
- [x] Add network throttling profiles
- [x] Configure explicit timeouts
- [x] CI-compatible settings

## Phase 2: Bootstrap Enforcement Tests (NEW)

- [x] Test JS doesn't initialize without window.STARMUS_BOOTSTRAP
- [x] Test JS initializes only after bootstrap is present
- [x] Test no DOM access before bootstrap detection

## Phase 3: Recorder Workflow Tests (EXPAND)

- [x] Start recording while offline
- [x] Reload page → recording persists locally
- [x] Submit while offline → queued, not lost
- [x] Reconnect → upload resumes
- [x] Network drop mid-upload → TUS resumes
- [x] Completion state appears exactly once

## Phase 4: Editor Workflow Tests (NEW)

- [ ] Editor loads only on editor pages
- [ ] Annotations persist across reloads
- [ ] Slow REST responses don't double-apply edits
- [ ] UI doesn't initialize twice

## Phase 5: Storage & Offline Validation

- [ ] IndexedDB contains queued submissions when offline
- [ ] Local data survives reloads
- [ ] Queue drains after reconnect
- [ ] No duplicate submissions

## Phase 6: Fix Test Setup

- [ ] Fix shortcode name in setup script
- [ ] Create proper test pages
- [ ] Verify test environment wiring

## Acceptance Criteria

- [ ] Tests fail under throttled/offline conditions when bugs exist
- [ ] Tests pass only when resume + queue logic is correct
- [ ] Bootstrap violations are caught deterministically
- [ ] No production code altered solely to satisfy tests
