# Starmus Audio Recorder - API Documentation Index

**Generated:** $(date)

## 📂 Documentation Structure

- **[PHP Markdown Docs](./php-md/)** - Auto-generated from PHP docblocks
- **[PHP HTML Docs](./php/)** - phpDocumentor output with full class diagrams
- **[JavaScript Docs](./js/)** - JSDoc-generated module documentation

---

## 🔷 PHP Classes

### Core System

- [StarmusAudioRecorder](./php-md/StarmusAudioRecorder.md) - Main plugin controller
- [StarmusAudioDAL](./php-md/StarmusAudioDAL.md) - Data access layer

### Frontend

- [StarmusAudioRecorderUI](./php-md/StarmusAudioRecorderUI.md) - Recording interface
- [StarmusAudioEditorUI](./php-md/StarmusAudioEditorUI.md) - Waveform editor interface

### Services

- [StarmusAudioProcessingService](./php-md/StarmusAudioProcessingService.md) - Audio conversion (WEBM→MP3/WAV)
- [StarmusFileService](./php-md/StarmusFileService.md) - File handling
- [StarmusWaveformService](./php-md/StarmusWaveformService.md) - Waveform data generation

### API

- [StarmusRESTHandler](./php-md/StarmusRESTHandler.md) - REST API endpoints
- [StarmusSubmissionHandler](./php-md/StarmusSubmissionHandler.md) - Form submission processing

### Assets

- [StarmusAssetLoader](./php-md/StarmusAssetLoader.md) - Frontend asset management

---

## 🟨 JavaScript Modules

### Core Modules

- **[starmus-integrator.js](./js/starmus-integrator.md)** - Main orchestrator & entry point
- **[starmus-core.js](./js/starmus-core.md)** - Submission engine
- **[starmus-recorder.js](./js/starmus-recorder.md)** - Audio recording logic
- **[starmus-ui.js](./js/starmus-ui.md)** - UI state controller
- **[starmus-state-store.js](./js/starmus-state-store.md)** - Redux-style state management

### Upload & Sync

- **[starmus-tus.js](./js/starmus-tus.md)** - TUS resumable upload protocol
- **[starmus-offline.js](./js/starmus-offline.md)** - IndexedDB offline queue

### Editor

- **[starmus-audio-editor.js](./js/starmus-audio-editor.md)** - Peaks.js waveform editor integration

### Infrastructure

- **[starmus-hooks.js](./js/starmus-hooks.md)** - Event bus & command dispatcher

---

## 📖 Additional Resources

- [README](../README.md) - Project overview
- [ARCHITECTURE](../ARCHITECTURE.md) - System design
- [TESTING](../TESTING.md) - Test suite guide
- [DOCUMENTATION](../DOCUMENTATION.md) - Documentation guide

---

_Generated automatically by Starmus Documentation System_
