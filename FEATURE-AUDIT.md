# Feature Audit: Old vs New Architecture

## Executive Summary

Analysis of features from the old monolithic JavaScript architecture (`starmus-audio-recorder-module.js`, `starmus-audio-recorder-submissions-handler.js`) compared to the new ES Module architecture.

**Date**: November 19, 2025  
**Status**: In Progress  
**Architecture Version**: 4.0.0 (ES Modules)

---

## ✅ Features PRESENT in New Architecture

### 1. **Adaptive Audio Quality** ✅

- **Status**: FULLY IMPLEMENTED
- **Location**: `src/js/starmus-recorder.js` → `getOptimalAudioSettings()`
- **Capabilities**:
  - Network-aware (2G/3G/4G detection via Network Information API)
  - Device-aware (mobile vs desktop)
  - Admin-configured MIME type selection
  - Adaptive bitrate (16kbps → 192kbps)
  - Adaptive sample rate (16kHz → 48kHz)
  - Data saver mode respect

### 2. **Speech Recognition** ✅

- **Status**: FULLY IMPLEMENTED  
- **Location**: `src/js/starmus-recorder.js` → `initRecorder()` MediaRecorder initialization
- **Capabilities**:
  - Continuous recognition during recording
  - Interim results handling
  - Language-specific (reads from form `starmus_language` field)
  - Transcript stored in state: `state.source.transcript`
  - Attached to upload as `_starmus_transcript`

### 3. **3-Phase Mic Calibration** ✅

- **Status**: FULLY IMPLEMENTED (restored from git history)
- **Location**: `src/js/starmus-recorder.js` → `calibrateAudioLevels()`
- **Capabilities**:
  - 15-second calibration (5s quiet → 5s speech → 5s quiet)
  - SNR (signal-to-noise ratio) calculation
  - Adaptive gain (1.0x - 6.0x)
  - Volume meter (0-100%)
  - UI callback for progress updates
  - Stored in state: `state.calibration`
  - Attached to upload as `_starmus_calibration`

### 4. **Environment Awareness Bridge (UEC → Starmus JS)** ✅

- **Status**: FULLY IMPLEMENTED with fallback
- **Location**: `src/js/starmus-integrator.js`
- **Capabilities**:
  - Listens for `sparxstar:environment-ready` event
  - 2-second timeout fallback with Network Information API detection
  - Passes environment to all modules via store initialization
  - Detects: browser, device, capabilities (WebRTC, MediaRecorder, IndexedDB, ServiceWorker)
  - Network info: effectiveType, downlink, rtt, saveData

### 5. **Hooks System** ✅

- **Status**: FULLY IMPLEMENTED (enhanced)
- **Location**: `src/js/starmus-hooks.js`
- **Capabilities**:
  - `window.StarmusHooks` global with `addAction()`, `doAction()`, `addFilter()`, `applyFilters()`
  - CommandBus for module-to-module communication
  - Subscribe/dispatch pattern
  - Debug logging toggle

### 6. **State Management** ✅

- **Status**: FULLY IMPLEMENTED (Redux-style)
- **Location**: `src/js/starmus-state-store.js`
- **Capabilities**:
  - Centralized store with `createStore()`
  - Immutable state updates
  - Subscribe pattern for reactive UI
  - Actions: init, calibration-start/update/complete, mic-start/stop/complete, file-attached, transcript-update, submit-start/complete, error, reset
  - State includes: instanceId, env, status, error, source, calibration, submission

### 7. **Two-Step Form Flow** ✅

- **Status**: FULLY IMPLEMENTED
- **Location**: `src/js/starmus-integrator.js` → `wireInstance()`
- **Capabilities**:
  - Step 1: Form validation (title, language, type, consent)
  - Step 2: Recording/upload UI
  - Single-step re-recorder mode (`data-starmus-rerecord="true"`)
  - Dynamic step visibility

### 8. **Fallback Codec Selection** ✅

- **Status**: FULLY IMPLEMENTED
- **Location**: `src/js/starmus-recorder.js` → `getOptimalAudioSettings()`
- **Capabilities**:
  - Checks admin `allowedMimeTypes` config
  - Priority: webm/opus → mp4 → ogg/opus → wav
  - `MediaRecorder.isTypeSupported()` check before use
  - Falls back to browser default if unsupported

### 9. **Device Telemetry** ✅ (PARTIAL)

- **Status**: PARTIALLY IMPLEMENTED
- **Location**: `src/js/starmus-integrator.js` → `initWithFallback()`
- **Present**:
  - Browser detection (Chrome, Firefox, Safari)
  - Device type (mobile vs desktop)
  - OS (navigator.platform)
  - Network info (effectiveType, downlink, rtt, saveData)
  - Capabilities (webrtc, mediaRecorder, indexedDB, serviceWorker)
- **Missing** (from old code):
  - `navigator.deviceMemory` (device RAM)
  - `navigator.hardwareConcurrency` (CPU cores)
  - Screen dimensions (`screen.width x screen.height`)

---

## ❌ Features MISSING from New Architecture

### 1. **Offline-First IndexedDB Queue** ❌ CRITICAL

- **Status**: NOT IMPLEMENTED
- **Old Location**: `starmus-audio-recorder-submissions-handler.js` → `Offline` object
- **Missing Capabilities**:
  - IndexedDB database: `StarmusSubmissions`
  - Object store: `pendingSubmissions`
  - `Offline.add()` - Save failed uploads for retry
  - `Offline.processQueue()` - Auto-retry when online
  - `Offline.remove()` - Clear completed uploads
  - User message: "You are offline. Submission saved and will auto-send when you reconnect."
- **Impact**: HIGH - Users on intermittent connections lose recordings

### 2. **TUS Resumable Uploads** ❌ CRITICAL

- **Status**: NOT IMPLEMENTED
- **Old Location**: `starmus-audio-recorder-submissions-handler.js` → `resumableTusUpload()`
- **Missing Capabilities**:
  - Chunked upload (default 5MB chunks)
  - `uploader.findPreviousUploads()` - Resume interrupted uploads
  - `uploader.resumeFromPreviousUpload()` - Continue from last chunk
  - Retry delays: `[0, 3000, 5000, 10000, 20000]`
  - Progress callback: "Uploading… X%"
  - Fallback to standard REST upload if TUS unavailable
- **Current State**: Uses simple `fetch()` POST - no resume, no chunking
- **Impact**: VERY HIGH - Large files fail on poor networks

### 3. **Background Sync / PWA Scaffolding** ❌

- **Status**: NOT IMPLEMENTED
- **Old Location**: Not in old code, but mentioned as requirement
- **Missing Capabilities**:
  - Service Worker registration
  - Background sync registration for failed uploads
  - Retry queue when app closed
  - Cache API for offline assets
- **Impact**: MEDIUM - Users must keep tab open for uploads

### 4. **Permission State Sync** ❌

- **Status**: NOT IMPLEMENTED
- **Old Location**: Not explicitly in old code
- **Missing Capabilities**:
  - `navigator.permissions.query({ name: 'microphone' })`
  - Permission state monitoring: 'granted', 'denied', 'prompt'
  - Proactive permission request before recording
  - UI updates based on permission state
  - Error prevention (don't show record button if denied)
- **Impact**: MEDIUM - Users see errors instead of permission prompts

### 5. **Tier C Fallback UI Revelation** ❌

- **Status**: NOT IMPLEMENTED
- **Old Location**: `starmus-audio-recorder-submissions-handler.js` → `revealTierC()`
- **Missing Capabilities**:
  - Hide `#starmus_recorder_container_{instanceId}` when MediaRecorder unavailable
  - Show `#starmus_fallback_container_{instanceId}` (file upload fallback)
  - Dispatch hook: `starmus_tier_c_revealed`
  - Automatic degradation for legacy browsers
- **Impact**: HIGH - Users on old browsers see broken recorder instead of file upload

### 6. **"Three Mode" Recorder UI** ❌

- **Status**: NOT CLEAR - Need clarification
- **Old Location**: Not found in old code
- **Possible Meanings**:
  - Mode 1: Record from microphone
  - Mode 2: Upload existing file
  - Mode 3: Re-record existing audio
- **Current State**: Has mic recording + file upload, has re-recorder template
- **Impact**: UNKNOWN - Need user to clarify what "three mode" means

### 7. **Editor State Transitions** ❌

- **Status**: SEPARATE MODULE (not audited yet)
- **Location**: `src/js/starmus-audio-editor.js` (exists but not part of recorder flow)
- **Capabilities**: Uses Peaks.js for waveform editing
- **Gap**: No integration with recorder state machine
- **Impact**: MEDIUM - Editor and recorder are disconnected

### 8. **Cut/Export Tools** ❌

- **Status**: SEPARATE MODULE
- **Location**: `src/js/starmus-audio-editor.js`
- **Gap**: Not integrated with recorder; standalone editor page
- **Impact**: LOW - Users must navigate to separate editor page

### 9. **Media Device Warnings** ❌

- **Status**: PARTIALLY IMPLEMENTED
- **Location**: `src/js/starmus-recorder.js` → `initRecorder()` catch block
- **Present**: Error when `getUserMedia()` fails: "Microphone permission is required."
- **Missing**:
  - Device enumeration (`navigator.mediaDevices.enumerateDevices()`)
  - No microphone detected warning
  - Multiple microphone selection
  - Device change monitoring (`navigator.mediaDevices.addEventListener('devicechange')`)
- **Impact**: MEDIUM - Users don't know if no mic is connected

### 10. **Box Office Sync** ❌

- **Status**: NOT FOUND
- **Old Location**: Not in old code
- **Details**: User mentioned this feature - need clarification on what it does
- **Impact**: UNKNOWN

### 11. **QR Workflows** ❌

- **Status**: NOT FOUND
- **Old Location**: Not in old code
- **Details**: User mentioned this feature - need clarification (QR code for what?)
- **Impact**: UNKNOWN

### 12. **Partial Upload Recovery** ❌

- **Status**: NOT IMPLEMENTED (subsumed by TUS)
- **Old Location**: Part of TUS in `resumableTusUpload()`
- **Missing**: Without TUS, no way to resume interrupted uploads
- **Impact**: VERY HIGH - Same as TUS missing

### 13. **Dynamic Partial UI Rendering** ❌

- **Status**: NOT CLEAR
- **Details**: Current UI renders all elements, shows/hides via `display` CSS
- **Possible Meaning**: Render only visible step to reduce DOM size?
- **Impact**: UNKNOWN - Need clarification

---

## 📊 Priority Matrix

| Feature | Impact | Effort | Priority |
|---------|--------|--------|----------|
| **TUS Resumable Uploads** | VERY HIGH | HIGH | 🔴 P0 - CRITICAL |
| **Offline IndexedDB Queue** | HIGH | MEDIUM | 🔴 P0 - CRITICAL |
| **Tier C Fallback UI** | HIGH | LOW | 🟡 P1 - HIGH |
| **Device Telemetry (complete)** | MEDIUM | LOW | 🟡 P1 - HIGH |
| **Permission State Sync** | MEDIUM | MEDIUM | 🟡 P1 - HIGH |
| **Media Device Warnings** | MEDIUM | MEDIUM | 🟢 P2 - MEDIUM |
| **Background Sync / PWA** | MEDIUM | HIGH | 🟢 P2 - MEDIUM |
| **Editor Integration** | MEDIUM | HIGH | 🟢 P2 - MEDIUM |
| **Box Office Sync** | UNKNOWN | UNKNOWN | ⚪ P3 - CLARIFY |
| **QR Workflows** | UNKNOWN | UNKNOWN | ⚪ P3 - CLARIFY |
| **Three Mode UI** | UNKNOWN | UNKNOWN | ⚪ P3 - CLARIFY |
| **Dynamic UI Rendering** | UNKNOWN | UNKNOWN | ⚪ P3 - CLARIFY |

---

## 📝 Implementation Recommendations

### Phase 1: Critical Offline Support (P0)

1. **Create `src/js/starmus-offline.js` module**
   - IndexedDB queue implementation
   - `navigator.onLine` event listeners
   - Auto-retry logic with exponential backoff
   - Integrate with state store: `submission.isQueued` flag

2. **Integrate TUS.js into `starmus-core.js`**
   - Add `tus-js-client` (already in `vendor/js/tus.min.js`)
   - Wrap `handleSubmit()` to use TUS for large files (> 1MB)
   - Fallback to direct POST for small files
   - Pass `window.starmusTus` config from PHP

### Phase 2: Progressive Enhancement (P1)

3. **Add Tier C fallback**
   - Check `hasMediaRecorder` in `starmus-integrator.js`
   - If false, dispatch action to show file upload UI
   - Hide recorder controls

4. **Enhance device telemetry**
   - Add `deviceMemory`, `hardwareConcurrency`, `screen` to `initWithFallback()`
   - Pass to `_starmus_env` in uploads

5. **Permission state monitoring**
   - Create `checkMicPermission()` helper
   - Call before showing record button
   - Update UI based on state

### Phase 3: Enhanced UX (P2)

6. **Device enumeration**
   - List available microphones
   - Let user select preferred device
   - Monitor `devicechange` events

7. **PWA scaffolding**
   - Register Service Worker
   - Implement background sync for uploads
   - Cache static assets

### Phase 4: Clarification Required (P3)

8. **User to clarify**:
   - What is "Box Office Sync"?
   - What are "QR Workflows"? (QR code for what purpose?)
   - What does "Three Mode" mean? (already have mic, file, re-record)
   - What is "Dynamic Partial UI Rendering"? (lazy load components?)

---

## 🔧 Technical Debt

### Old Code Patterns to Avoid

1. **Global State Pollution**: Old code used `window.StarmusAudioRecorder._instances` - new code uses module-scoped `Map`
2. **Callback Hell**: Old code had nested promises - new code uses async/await
3. **jQuery Dependency**: Old code assumed jQuery - new code is vanilla JS
4. **Mixed Concerns**: Old code had UI + logic + upload in one file - new code separates via modules

### Modern Patterns to Maintain

1. **ES Modules**: Keep using `import/export` instead of IIFE
2. **Redux-style State**: Immutable updates via reducer
3. **CommandBus**: Decoupled module communication
4. **Hooks System**: WordPress-style action/filter architecture

---

## 📚 Next Steps

1. **User Input Required**: Clarify P3 features (Box Office, QR, Three Mode, Dynamic UI)
2. **Begin P0 Implementation**: TUS + IndexedDB offline queue
3. **Update ARCHITECTURE.md**: Document new modular architecture
4. **Add Integration Tests**: Test offline scenarios, TUS resume, Tier C fallback

---

**Last Updated**: November 19, 2025  
**Auditor**: GitHub Copilot  
**Review Status**: Awaiting user clarification on P3 features
