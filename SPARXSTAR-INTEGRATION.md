# SPARXSTAR Integration Guide

## Overview
The Starmus Environment Optimizer integrates with the SPARXSTAR plugin to dynamically adjust audio recording settings based on network conditions and device capabilities.

## Integration Steps

### 1. Starmus Changes (✅ Complete)
- Added `applyFilters` calls in `starmus-audio-recorder-module.js`:
  - `starmus_audio_constraints` filter in `init()` before `getUserMedia`
  - `starmus_media_recorder_options` filter in `startRecording()` before `MediaRecorder`
- Added optimizer script enqueue in `StarmusAssetLoader.php`
- Updated build script to compile `starmus-environment-optimizer.js`

### 2. SPARXSTAR Plugin Changes (⚠️ Required)
You need to modify your SPARXSTAR plugin's main JavaScript file to dispatch the state-ready event.

**Location:** Find where `SPARXSTAR.initializeState()` is called in your SPARXSTAR plugin.

**Add this line immediately after:**
```javascript
// In your SPARXSTAR plugin's main.js file
SPARXSTAR.initializeState();

// Add this line right here:
document.dispatchEvent(new CustomEvent('sparxstar-state-ready'));
```

This event triggers the optimizer to read environment data and apply the appropriate filters.

### 3. Script Loading Order
The scripts must load in this order (handled automatically by WordPress dependencies):
1. `starmus-audio-recorder-hooks.js` (part of starmus-app.min.js)
2. SPARXSTAR plugin scripts (including SPARXSTAR.Utils)
3. `starmus-environment-optimizer.min.js` (enqueued when recorder shortcode present)
4. Rest of Starmus scripts (part of starmus-app.min.js)

## How It Works

### Environment Detection
The optimizer uses SPARXSTAR.Utils to detect:
- Network bandwidth (`getNetworkBandwidth()`)
- Device type (`getDeviceType()`)

### Optimization Profiles
Based on detected conditions, one of three profiles is applied:

**1. Very Low Spec** (2G/slow-2G networks)
- Sample rate: 8000 Hz
- Bitrate: 16 kbps

**2. Low Spec** (3G networks or mobile devices)
- Sample rate: 16000 Hz
- Bitrate: 32 kbps (default)

**3. Default** (4G+ and desktop)
- Sample rate: 16000 Hz
- Bitrate: 32 kbps

### Filters Applied
```javascript
// Audio constraints filter (affects microphone input)
audioConstraints = applyFilters("starmus_audio_constraints", audioConstraints, instanceId);

// MediaRecorder options filter (affects encoding)
mediaRecorderOptions = applyFilters("starmus_media_recorder_options", mediaRecorderOptions, instanceId);
```

## Testing

### 1. Build the Assets
```bash
npm run build
```

### 2. Verify Files Exist
- Check `/assets/js/starmus-environment-optimizer.min.js` exists
- Check `/assets/js/starmus-app.min.js` exists

### 3. Test Integration
1. Ensure SPARXSTAR plugin is active and dispatches the event
2. Load a page with `[starmus_audio_recorder]` shortcode
3. Open browser console
4. Look for optimizer messages:
   - `[Optimizer] Environment analyzed. Applying profile: low_spec (Network: 3g, Device: mobile)`
   - `[Optimizer] Setting sampleRate to 16000 Hz.`
   - `[Optimizer] Setting bitrate to 32 kbps.`

### 4. Fallback Behavior
If SPARXSTAR is not available:
- The optimizer has a 1-second timeout fallback
- It also triggers on `DOMContentLoaded` with a 500ms delay
- If SPARXSTAR.Utils is never available, default settings are used

## Troubleshooting

### Optimizer not running
- Check that SPARXSTAR plugin is active
- Verify `sparxstar-state-ready` event is being dispatched
- Check browser console for SPARXSTAR.Utils availability

### Settings not changing
- Verify the optimizer is loaded (check Network tab in DevTools)
- Check console for filter application messages
- Ensure StarmusHooks is available globally

### Build issues
```bash
# Clean and rebuild
npm run clean
npm run build
```

## Files Modified
- `src/js/starmus-audio-recorder-module.js` - Added filter hooks
- `src/js/starmus-environment-optimizer.js` - New optimizer script
- `src/core/StarmusAssetLoader.php` - Enqueue optimizer
- `package.json` - Updated build script

## Next Steps
1. Update SPARXSTAR plugin to dispatch `sparxstar-state-ready` event
2. Run `npm run build` to compile assets
3. Test on pages with recorder shortcode
4. Monitor console logs for optimization messages
