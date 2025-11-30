# Audio Editor Permission Fix

## Problem

The audio editor was showing "Permission denied" error when it was previously displaying the UI but not loading data.

## Root Cause

The permission check in `StarmusAudioEditorUI::get_editor_context()` was using:

```php
current_user_can('edit_post', $post_id)
```

This is a WordPress core capability that doesn't properly map to the custom post type `audio-recording` and its custom capabilities (`starmus_edit_audio`).

## Solution

Changed the permission check to allow access if:

1. **User is the post author** (users can edit their own recordings), OR
2. **User has the `starmus_edit_audio` capability** (admins/editors with special permission)

### Code Changes

#### File: `/src/frontend/StarmusAudioEditorUI.php` (lines 277-297)

**Before:**

```php
// 4. Validate Post Existence and Permissions
if (! get_post($post_id)) {
    return new WP_Error('invalid_id', __('Invalid submission ID.', 'starmus-audio-recorder'));
}

if (! current_user_can('edit_post', $post_id)) {
    return new WP_Error('permission_denied', __('Permission denied.', 'starmus-audio-recorder'));
}
```

**After:**

```php
// 4. Validate Post Existence and Permissions
$post = get_post($post_id);
if (! $post) {
    return new WP_Error('invalid_id', __('Invalid submission ID.', 'starmus-audio-recorder'));
}

// Allow if user is post author OR has the starmus_edit_audio capability
$current_user_id = get_current_user_id();
$is_author = ($post->post_author == $current_user_id);
$has_cap = current_user_can('starmus_edit_audio');

if (\defined('WP_DEBUG') && WP_DEBUG) {
    error_log(sprintf(
        '[StarmusEditorUI] Permission check: user_id=%d, post_author=%d, is_author=%s, has_cap=%s',
        $current_user_id,
        $post->post_author,
        $is_author ? 'YES' : 'NO',
        $has_cap ? 'YES' : 'NO'
    ));
}

if (! $is_author && ! $has_cap) {
    return new WP_Error('permission_denied', __('Permission denied.', 'starmus-audio-recorder'));
}
```

#### File: `/src/frontend/StarmusShortcodeLoader.php` (lines 159-214)

Added comprehensive debug logging to track:

- When context loads successfully
- When WP_Error occurs (with error message)
- When JS data is localized
- Audio URL being passed to JS

## Debug Logging

When `WP_DEBUG` is enabled, you'll now see log entries like:

```
[StarmusEditorUI] Permission check: user_id=1, post_author=1, is_author=YES, has_cap=YES
[StarmusShortcodeLoader] Editor context loaded: post_id=123
[StarmusShortcodeLoader] JS data localized. Audio URL: https://example.com/wp-content/uploads/audio.webm
```

Or if there's an error:

```
[StarmusEditorUI] Permission check: user_id=2, post_author=1, is_author=NO, has_cap=NO
[StarmusShortcodeLoader] Editor context error: Permission denied.
```

## Testing

1. **Clear debug log:**

   ```bash
   echo "" > wp-content/debug.log
   ```

2. **Access the editor page:**
   - As the recording owner: `/edit-recording/?post_id=123&nonce=...`
   - As a different user with `starmus_edit_audio` capability
   - As a user without permission (should show error)

3. **Check debug log:**

   ```bash
   tail -50 wp-content/debug.log | grep -i "starmus\|editor"
   ```

4. **Check browser console:**

   ```javascript
   console.log(window.STARMUS_EDITOR_DATA);
   ```

## Expected Behavior

- **Post author**: Can always edit their own recordings
- **Users with `starmus_edit_audio` capability**: Can edit any recording
- **Other users**: See "Permission denied" error
- **JavaScript data**: Should load correctly in `window.STARMUS_EDITOR_DATA`
- **Template**: Should render with audio player, waveform, and transcript panel

## Related Files

- `/src/frontend/StarmusAudioEditorUI.php` - Permission logic
- `/src/frontend/StarmusShortcodeLoader.php` - Data localization
- `/src/templates/starmus-audio-editor-ui.php` - UI template
- `/src/StarmusAudioRecorder.php` - Custom capability constants

## Custom Capabilities

The plugin defines two custom capabilities:

- `STARMUS_CAP_EDIT_AUDIO = 'starmus_edit_audio'` - Edit uploaded audio
- `STARMUS_CAP_RECORD_AUDIO = 'starmus_record_audio'` - Create new recordings

These are assigned to appropriate roles during plugin activation.
