# Re-Recorder Two-Step Flow Implementation

## Overview

The re-recorder has been refactored to use the **same two-step JavaScript flow** as the regular recorder, ensuring consistency and reducing code duplication.

## Key Changes

### 1. Template Structure (`starmus-audio-re-recorder-ui.php`)

**Before:** Single-step form with auto-submit on consent checkbox
**After:** Two-step form identical to regular recorder with pre-filled metadata

#### Step 1: Consent + Pre-filled Details

- Shows notice: "You are creating a new recording to replace: [Title]"
- Consent checkbox (same as regular recorder)
- **Hidden fields** with existing data:
  - `starmus_title` (from original post)
  - `starmus_language` (from original post taxonomy)
  - `starmus_recording_type` (from original post taxonomy)
  - `artifact_id` (links to original recording)
- Optional audio file type selector
- "Continue to Recording" button

#### Step 2: Recording Interface

- Identical to regular recorder
- Microphone setup/calibration
- Recording controls (Record, Pause, Resume, Stop)
- Timer, volume meter, waveform
- Live transcript (if enabled)
- Tier C fallback (file upload)
- Submit button labeled "Submit Re-recording"

### 2. JavaScript Integration

The template uses `data-starmus="recorder"` to initialize the same JavaScript flow:

```php
<form
    data-starmus="recorder"
    data-starmus-rerecord="true"
    data-starmus-instance="<?php echo esc_attr($instance_id); ?>">
```

The `data-starmus-rerecord="true"` attribute allows the JS to detect re-recording mode if needed.

### 3. PHP Changes (`StarmusAudioRecorderUI.php`)

#### Updated `render_re_recorder_shortcode()`

- Fetches existing post metadata:
  - Post title
  - Language taxonomy (term_id)
  - Recording type taxonomy (term_id)
- Passes all data to template including:
  - `existing_title`
  - `existing_language`
  - `existing_type`
  - `artifact_id` (same as `post_id`)
  - `languages` array (for selector if needed)
  - `recording_types` array (for selector if needed)

#### Removed

- `maybe_handle_rerecorder_autostep()` method (no longer needed)
- Hook registration for `template_redirect` autostep handler

### 4. Data Flow

```
┌─────────────────────────────────────────────────────────┐
│ User visits /re-record/?recording_id=123               │
└──────────────────────┬──────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────┐
│ StarmusAudioRecorderUI::render_re_recorder_shortcode() │
│ - Gets post_id from URL param                          │
│ - Fetches existing post (title, language, type)        │
│ - Passes to template as hidden fields                  │
└──────────────────────┬──────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────┐
│ starmus-audio-re-recorder-ui.php Template              │
│ - Step 1: Consent + hidden metadata fields             │
│ - Step 2: Recording interface (same as recorder)       │
│ - data-starmus="recorder" triggers JS init              │
└──────────────────────┬──────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────┐
│ starmus-integrator.js (existing code)                  │
│ - Detects form with data-starmus="recorder"            │
│ - Initializes two-step flow                            │
│ - Handles Step 1 → Step 2 transition                   │
│ - Manages recording, calibration, submission           │
└──────────────────────┬──────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────┐
│ Form Submission (existing handler)                     │
│ - Receives hidden fields:                              │
│   * starmus_title                                       │
│   * starmus_language                                    │
│   * starmus_recording_type                              │
│   * artifact_id (links to original)                     │
│ - Creates NEW post with recorded audio                  │
│ - Links to original via artifact_id                     │
└─────────────────────────────────────────────────────────┘
```

## Hidden Fields Passed to Submission

When the form is submitted, these fields are sent:

```php
<input type="hidden" name="starmus_title" value="[existing title]">
<input type="hidden" name="starmus_language" value="[term_id]">
<input type="hidden" name="starmus_recording_type" value="[term_id]">
<input type="hidden" name="artifact_id" value="[original post_id]">
```

This ensures:

- The new recording inherits the same metadata
- The submission handler knows it's a re-recording
- The `artifact_id` links the new post to the original

## Benefits

1. **Code Reuse**: Same JavaScript, CSS, and UI flow as regular recorder
2. **Consistency**: Users get familiar two-step experience
3. **Maintainability**: No separate JS code path for re-recording
4. **Data Integrity**: Original post stays intact, new post created with link
5. **Flexibility**: Can still customize via `data-starmus-rerecord="true"` attribute if needed

## Testing

1. **Access re-recorder page:**

   ```
   /re-record/?recording_id=123
   ```

2. **Verify Step 1 shows:**
   - Notice with original recording title
   - Consent checkbox
   - "Continue to Recording" button

3. **Click Continue, verify Step 2 shows:**
   - Same recording interface as regular recorder
   - All controls (Setup Mic, Record, Pause, Stop)
   - Timer, volume meter, waveform

4. **Record and submit, verify:**
   - New post created (different post_id)
   - Same title as original
   - Same language and recording type
   - Has `artifact_id` meta pointing to original

5. **Check browser console:**

   ```javascript
   // Should show same bootstrap as regular recorder
   console.log(window.STARMUS_BOOTSTRAP);
   ```

## Files Changed

- `/src/frontend/StarmusAudioRecorderUI.php` - Fetch and pass existing metadata
- `/src/templates/starmus-audio-re-recorder-ui.php` - Complete two-step template rewrite
- No changes needed to JavaScript - uses existing recorder flow!

## No Changes Needed To

- `/src/js/starmus-integrator.js` - Already handles `data-starmus="recorder"`
- `/src/js/starmus-recorder.js` - Already handles recording flow
- `/src/js/starmus-ui.js` - Already handles two-step UI updates
- `/src/core/StarmusAssetLoader.php` - Already enqueues scripts for recorder

The beauty of this approach is that **no JavaScript changes are required** - the re-recorder piggybacks on the existing, battle-tested recorder flow.
