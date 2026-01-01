# Transcript Panel Integration

**Version:** 0.9.0
**Status:** ✅ Production Ready (Mock Data)
**AiWA Integration:** 🔜 Pending

## Overview

The Starmus audio editor now includes a **bidirectional transcript panel** that synchronizes audio playback with time-stamped text tokens. This enables "karaoke-style" reading experiences, click-to-seek navigation, and confidence-based review workflows.

## Architecture

### Components

```
starmus-audio-editor-ui.php          ← Template (split-pane layout)
starmus-transcript-controller.js     ← Controller (sync engine)
starmus-audio-editor.js              ← Integration (initialization)
StarmusAudioEditorUI.php             ← Enqueue logic
starmus-audio-recorder-style.css     ← Styles (transcript panel + tokens)
```

### Layout Structure

**Mobile (< 1024px):**
```
┌─────────────────────┐
│   Waveform          │
│   + Transport       │
├─────────────────────┤
│   Transcript        │
│   Panel             │
├─────────────────────┤
│   Regions Table     │
└─────────────────────┘
```

**Desktop (≥ 1024px):**
```
┌───────────────┬───────────┐
│  Waveform     │ Transcript│
│  + Transport  │  Panel    │
│               ├───────────┤
│               │  Regions  │
│               │   Table   │
└───────────────┴───────────┘
     60%            40%
```

## Data Format

### Transcript Token Schema

```javascript
{
  start: number,      // Start time in seconds (e.g., 1.2)
  end: number,        // End time in seconds (e.g., 1.8)
  text: string,       // Word or phrase (e.g., "Welcome")
  confidence: number  // Optional: 0-1 score (e.g., 0.95)
}
```

### Example Dataset

```javascript
const transcript = [
  { start: 0.5, end: 1.2, text: "Welcome", confidence: 0.95 },
  { start: 1.2, end: 1.8, text: "to", confidence: 0.99 },
  { start: 1.8, end: 2.5, text: "the", confidence: 0.98 },
  { start: 2.5, end: 3.1, text: "Starmus", confidence: 0.92 },
  { start: 3.1, end: 3.8, text: "editor.", confidence: 0.94 }
];
```

## Integration Points

### 1. Template (PHP)

**File:** `src/templates/starmus-audio-editor-ui.php`

```php
<aside class="starmus-editor__side">
  <section class="starmus-editor__transcript">
    <h2>Transcript</h2>
    <div id="starmus-transcript-panel"
         class="starmus-transcript-panel"
         role="list"
         aria-live="polite">
      <!-- JS renders tokens here -->
    </div>
  </section>
</aside>
```

### 2. Controller (JavaScript)

**File:** `src/js/starmus-transcript-controller.js`

```javascript
class StarmusTranscript {
  constructor(peaksInstance, containerId, transcriptData) {
    this.peaks = peaksInstance;
    this.data = transcriptData;
    this.init();
  }

  render() { /* Converts JSON → HTML spans */ }
  bindEvents() { /* Click + scroll + timeupdate */ }
  syncHighlight(time) { /* Find active token */ }
  updateDOM(index) { /* Toggle .is-active */ }
  scrollToWord(element) { /* Smart auto-scroll */ }
}
```

### 3. Initialization

**File:** `src/js/starmus-audio-editor.js`

```javascript
Peaks.init(options, function (err, peaks) {
  if (err) return;

  // Initialize transcript controller
  const transcriptController = new StarmusTranscript(
    peaks,
    'starmus-transcript-panel',
    mockTranscriptData  // TODO: Replace with STARMUS_EDITOR_DATA.transcript
  );
});
```

### 4. Enqueue Scripts

**File:** `src/frontend/StarmusAudioEditorUI.php`

```php
wp_enqueue_script(
  'starmus-transcript-controller',
  STARMUS_URL . 'src/js/starmus-transcript-controller.js',
  [],
  STARMUS_VERSION,
  true
);

wp_enqueue_script(
  'starmus-audio-editor',
  STARMUS_URL . 'src/js/starmus-audio-editor.js',
  ['jquery', 'peaks-js', 'starmus-transcript-controller'],
  STARMUS_VERSION,
  true
);
```

## Features

### ✅ Bidirectional Sync

**Audio → Text:**
- `timeupdate` event triggers `syncHighlight(currentTime)`
- Finds matching token based on `start` and `end` times
- Adds `.is-active` class to current word
- Auto-scrolls panel to keep active word centered

**Text → Audio:**
- Click any word
- Reads `data-start` attribute
- Calls `peaks.player.seek(startTime)`
- Playback jumps instantly

### ✅ User Scroll Detection

**Problem:** Auto-scroll fights with manual scrolling
**Solution:** 1-second pause after user scrolls

```javascript
this.container.addEventListener('scroll', () => {
  this.isUserScrolling = true;
  clearTimeout(this.scrollTimeout);
  this.scrollTimeout = setTimeout(() => {
    this.isUserScrolling = false;
  }, 1000);
});
```

### ✅ Confidence Indicators

**Low Confidence (< 80%):**
```css
.starmus-word[data-confidence="low"] {
  border-bottom: 2px dotted #d63638; /* Red underline */
}
```

**Tooltip:**
```javascript
span.title = `Low confidence: ${Math.round(token.confidence * 100)}%`;
```

## Styling

### Key CSS Classes

```css
/* Container */
.starmus-transcript-panel {
  height: 400px;
  overflow-y: auto;
  scroll-behavior: smooth;
  font-family: 'Inter', system-ui, sans-serif;
  line-height: 1.8;
}

/* Interactive Token */
.starmus-word {
  display: inline-block;
  padding: 1px 2px;
  border-radius: 3px;
  cursor: pointer;
  transition: all 0.1s ease;
}

/* Hover State */
.starmus-word:hover {
  background-color: #f0f0f1;
  color: #1d2327;
}

/* Active During Playback */
.starmus-word.is-active {
  background-color: #2271b1; /* WP Blue */
  color: white;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

/* Low Confidence Flag */
.starmus-word[data-confidence="low"] {
  border-bottom: 2px dotted #d63638;
}
```

## Production Setup

### Step 1: Add Transcript Field to Post Meta

```php
// In StarmusAudioDAL.php or equivalent
update_post_meta(
  $post_id,
  'star_transcript_json',
  wp_json_encode($transcript_tokens)
);
```

### Step 2: Fetch Transcript in Editor Context

**File:** `src/frontend/StarmusAudioEditorUI.php`

```php
private function get_editor_context(): array
{
  // ... existing code ...

  $transcript_json = get_post_meta($post_id, 'star_transcript_json', true);
  $transcript_data = $this->parse_transcript_json($transcript_json);

  return [
    // ... existing fields ...
    'transcript' => $transcript_data,
  ];
}

private function parse_transcript_json(string $json): array
{
  if (empty($json)) {
    return [];
  }

  $data = json_decode($json, true);
  return is_array($data) ? $data : [];
}
```

### Step 3: Pass to JavaScript

```php
wp_localize_script(
  'starmus-audio-editor',
  'STARMUS_EDITOR_DATA',
  [
    // ... existing fields ...
    'transcript' => $transcript_data,
  ]
);
```

### Step 4: Replace Mock Data

**File:** `src/js/starmus-audio-editor.js`

```javascript
// Remove mock data
const transcriptData = STARMUS_EDITOR_DATA.transcript || [];

transcriptController = new StarmusTranscript(
  peaks,
  'starmus-transcript-panel',
  transcriptData  // Production data from post meta
);
```

## AiWA Integration Roadmap

### Phase 1: Basic Transcript (Current)
- ✅ Mock data rendering
- ✅ Click-to-seek
- ✅ Auto-scroll with user detection
- ✅ Confidence indicators

### Phase 2: Live Transcript Generation
- 🔜 Whisper API integration
- 🔜 Real-time transcription during upload
- 🔜 Progress indicator in UI
- 🔜 Retry logic for failed jobs

### Phase 3: Speaker Diarization
- 🔜 Identify multiple speakers
- 🔜 Color-code by speaker
- 🔜 Speaker labels (Elder 1, Elder 2, etc.)

### Phase 4: Editing & Correction
- 🔜 Click-to-edit low-confidence words
- 🔜 Inline editing (contenteditable)
- 🔜 Auto-save edits to post meta
- 🔜 Revision history

### Phase 5: Export & Search
- 🔜 Export as SRT/VTT for video captions
- 🔜 Full-text search in transcript
- 🔜 Keyword highlighting
- 🔜 Jump to search matches

## Performance Considerations

### Optimization Strategies

1. **Early Exit:** Don't update DOM if still within current token
2. **Fragment Rendering:** Batch DOM insertions to minimize reflows
3. **Binary Search:** For transcripts > 1 hour (currently linear search)
4. **Debounced Scroll:** User scroll detection with 1s timeout

### Payload Budget

| Asset | Size (Unminified) | Status |
|-------|-------------------|--------|
| starmus-transcript-controller.js | ~7KB | ✅ Under budget |
| CSS additions | ~3KB | ✅ Under budget |
| **Total Impact** | **~10KB** | **✅ Acceptable** |

### Browser Support

- **Tier A (Modern):** Full features, smooth animations
- **Tier B (Legacy):** Functional, no smooth scroll
- **Tier C (JS-off):** Transcript hidden (progressive enhancement)

## Testing Checklist

### Manual Testing

- [ ] Load editor with mock data
- [ ] Play audio → words highlight in sync
- [ ] Click any word → audio seeks correctly
- [ ] Scroll while playing → auto-scroll pauses
- [ ] Stop scrolling → auto-scroll resumes after 1s
- [ ] Hover words → background changes
- [ ] Hover low-confidence word → tooltip appears
- [ ] Resize to mobile → layout stacks correctly
- [ ] Resize to desktop → split-pane activates

### Integration Testing

- [ ] Editor loads without errors
- [ ] StarmusTranscript class available globally
- [ ] Peaks.js integration works
- [ ] Console shows "Linguistic Engine: Online"
- [ ] No JavaScript errors in console
- [ ] CSS applies correctly (no FOUC)

### Accessibility Testing

- [ ] Screen reader announces transcript updates
- [ ] Keyboard navigation works (Tab through words)
- [ ] ARIA labels present on sections
- [ ] Color contrast meets WCAG 2.2 AA
- [ ] Focus indicators visible

## Troubleshooting

### Issue: Words don't highlight during playback

**Cause:** `timeupdate` event not firing
**Fix:** Check that Peaks.js media element is valid

```javascript
const mediaElement = this.peaks.player.getMediaElement();
console.log('Media element:', mediaElement);
```

### Issue: Click-to-seek doesn't work

**Cause:** `data-start` attribute missing
**Fix:** Verify render() adds attributes

```javascript
console.log('Token data:', this.data);
console.log('Rendered spans:', this.container.children);
```

### Issue: Auto-scroll is jerky

**Cause:** Rapid DOM updates
**Fix:** Ensure early exit in `syncHighlight()`

```javascript
if (currentToken && time >= currentToken.start && time <= currentToken.end) {
  return; // Don't update if still within current token
}
```

## Credits

**Architecture:** Based on Soundscape/Otter.ai transcript sync patterns
**UX:** Inspired by YouTube auto-scrolling captions
**Implementation:** Starisian Technologies © 2025

---

**Next Steps:**
1. Replace mock data with AiWA Whisper transcripts
2. Add speaker diarization support
3. Implement inline editing for corrections
4. Export to SRT/VTT formats
