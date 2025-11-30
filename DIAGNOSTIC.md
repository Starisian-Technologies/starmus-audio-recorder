# Starmus Audio Recorder - Diagnostic Guide

## Current Issues Being Debugged

### 1. Re-Recorder Template Not Loading

- **Status**: FIXED ✓
- **Changes**:
  - Fixed template filename (`starmus-audio-re-recorder-ui.php`)
  - Added URL parameter reading (`recording_id`)
  - Added debug logging and console output

### 2. Audio Editor Missing Data

- **Status**: FIXED ✓
- **Changes**:
  - Moved data localization to `StarmusShortcodeLoader`
  - Added complete data object (audio, waveform, transcript, annotations)
  - Removed duplicate localization from `StarmusAssetLoader`

### 3. Recording Detail Admin View Blank

- **Status**: IN PROGRESS 🔧
- **Changes**:
  - Added comprehensive error handling
  - Added debug logging
  - **NEEDS TESTING**

## How to Test Each Component

### Re-Recorder

1. Access: `/record/?recording_id=123` (replace 123 with actual post ID)
2. Check console: `window.STARMUS_RERECORDER_POST_ID`
3. Should see form with "Re-recording for Post ID: X"

### Audio Editor

1. Access: `/edit-recording/?post_id=123&nonce=...`
2. Check console: `window.STARMUS_EDITOR_DATA`
3. Should see waveform, audio player, and transcript panel

### Recording Detail (Admin View)

1. Access a single audio-recording post while logged in as admin
2. Open terminal and run: `tail -f wp-content/debug.log`
3. Reload page
4. Look for:

   ```
   [StarmusTemplateLoader] Attempting to load template: starmus-recording-detail-admin.php
   [StarmusDetailAdmin] Loading detail for post_id: X
   ```

## Debug Commands

### Check if template exists

```bash
ls -la /workspaces/starmus-audio-recorder/src/templates/starmus-recording-detail-admin.php
```

### Watch debug log live

```bash
tail -f /workspaces/starmus-audio-recorder/wp-content/debug.log
```

### Search for errors

```bash
grep -i "error\|warning\|fatal" /workspaces/starmus-audio-recorder/wp-content/debug.log | tail -20
```

### Check recent Starmus logs

```bash
grep "Starmus\|STARMUS" /workspaces/starmus-audio-recorder/wp-content/debug.log | tail -50
```

## Browser Console Checks

### For ANY Starmus Page

```javascript
// Check bootstrap
console.log('Bootstrap:', window.STARMUS_BOOTSTRAP);

// Check editor data
console.log('Editor Data:', window.STARMUS_EDITOR_DATA);

// Check re-recorder
console.log('Re-recorder ID:', window.STARMUS_RERECORDER_POST_ID);

// Check admin status
console.log('Is Admin:', window.isStarmusAdmin);
```

### Network Tab

1. Open DevTools → Network tab
2. Reload page
3. Check for:
   - Failed requests (red)
   - Starmus asset files loading
   - REST API calls

## Common Problems & Solutions

### Problem: Template not found

**Solution**: Check STARMUS_PATH constant is defined in main plugin file

### Problem: Permission denied

**Solution**: Log in as administrator or super_admin

### Problem: No data in JavaScript

**Solution**: Check that shortcode is being rendered and localization happens

### Problem: Blank page

**Solution**: Check debug.log for PHP errors, check browser console for JS errors

## Files Modified in This Session

1. `/src/frontend/StarmusAudioRecorderUI.php` - Re-recorder fixes
2. `/src/templates/starmus-audio-re-recorder-ui.php` - Template enhancements
3. `/src/frontend/StarmusShortcodeLoader.php` - Editor data localization
4. `/src/frontend/StarmusAudioEditorUI.php` - Context passing
5. `/src/core/StarmusAssetLoader.php` - Removed duplicate localization
6. `/src/templates/starmus-audio-editor-ui.php` - Added debug attributes
7. `/src/helpers/StarmusTemplateLoaderHelper.php` - Added error logging
8. `/src/templates/starmus-recording-detail-admin.php` - Added error handling

## Next Steps

1. Test recording detail page with debug log open
2. Share any error messages found
3. Check browser console for JavaScript data
4. Verify all three components work correctly
