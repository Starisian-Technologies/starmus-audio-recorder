# Starmus Audio Recorder - Architecture & Separation of Concerns

## File Responsibilities

### 1. `starmus-audio-recorder-module.js` - Core Recording Engine
**Responsibility**: Pure audio recording functionality
- MediaRecorder API management
- Audio stream handling
- Recording state management (start/stop/pause/resume)
- Audio blob validation and preparation
- Timer functionality
- UI element creation and updates for recorder controls
- Resource cleanup

**Public API**:
- `init(options)` - Initialize recorder instance
- `getSubmissionData(instanceId)` - Get recorded audio data
- `cleanup(instanceId)` - Clean up resources

### 2. `starmus-audio-recorder-ui-controller.js` - Form UI Management
**Responsibility**: Form interaction and validation coordination
- Two-step form flow management
- Field validation (Step 1)
- Event binding for continue/submit buttons
- Delegation to appropriate handlers
- User message display

**Public API**:
- Auto-initializes all `.starmus-audio-form` forms
- Delegates to `StarmusSubmissionsHandler.handleSubmit()`

### 3. `starmus-audio-recorder-submissions-handler.js` - Submission Logic
**Responsibility**: Audio upload and submission processing
- TUS resumable uploads
- Offline queue management (IndexedDB)
- Form data collection
- Metadata building
- WordPress REST API integration
- Fallback file upload handling

**Public API**:
- `StarmusSubmissionsHandler.handleSubmit(instanceId, form)`
- `StarmusSubmissionsHandler.initRecorder(instanceId)`
- `StarmusSubmissionsHandler.revealTierC(instanceId)`

### 4. `starmus-audio-recorder-submissions.js` - Legacy Browser Support
**Responsibility**: Backward compatibility and polyfills
- Legacy browser polyfills (forEach, trim)
- IE compatibility layer
- Geolocation handling
- Alternative submission flow for older devices
- Enhanced error handling for legacy environments

## Data Flow

```
User Interaction → UI Controller → Submissions Handler → Recording Module
                                      ↓
                              Offline Queue ← TUS Upload → WordPress REST API
```

## Security Improvements Made

1. **XSS Prevention**: All user input sanitized before DOM insertion
2. **Open Redirect Protection**: URL validation for redirects
3. **Input Validation**: Enhanced field validation with error handling
4. **Memory Management**: Proper cleanup of timeouts and blob URLs
5. **CSRF Protection**: WordPress nonces used for all API calls

## Performance Optimizations

1. **Early Exit Loops**: Validation stops on first error
2. **DOM Caching**: Elements cached to reduce queries
3. **Rate Limiting**: Offline queue processing with delays
4. **Memory Cleanup**: Proper resource disposal
5. **Efficient Logging**: Reduced overhead in logging functions

## Missing Methods Added

1. **Global Submission Handler**: `window.StarmusSubmissionsHandler`
2. **URL Validation**: `isValidRedirectUrl()` function
3. **Text Sanitization**: `sanitizeText()` in UI Controller
4. **Enhanced Error Handling**: Try-catch blocks around critical operations

## Architectural Principles Followed

1. **Single Responsibility**: Each file has one clear purpose
2. **Dependency Injection**: Modules communicate through well-defined interfaces
3. **Error Isolation**: Failures in one module don't crash others
4. **Progressive Enhancement**: Graceful degradation for older browsers
5. **Security by Design**: Input validation and output encoding throughout