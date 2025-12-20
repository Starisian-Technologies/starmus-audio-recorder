**Title:** Real Network Speed Measurement for African Markets

**Labels:** `optimization`, `network`, `african-markets`, `week-3`

**Body:**
## Problem
Need accurate network speed detection for tier optimization.

## Solution
```javascript
async measureRealNetworkSpeed() {
  const start = performance.now();
  try {
    await fetch('/wp-content/plugins/starmus-audio-recorder/test-1kb.txt?t=' + Date.now());
    const duration = performance.now() - start;
    const kbps = (8 / duration) * 1000;
    
    if (kbps < 50) return 'very_low';
    if (kbps < 500) return 'low'; 
    return 'high';
  } catch {
    return 'very_low';
  }
}
```

## Requirements
- 1KB test file (cache-busted, uncompressed, same-origin)
- Absolute thresholds: <50kbps=very_low, <500kbps=low, else=high
- Non-blocking, async measurement

## Files to Modify
- `src/js/starmus-sparxstar-integration.js` - Add speed measurement
- Create `assets/test-1kb.txt` file

## Acceptance Criteria
- [ ] 1KB test file created and served uncompressed
- [ ] Cache-busted requests with timestamp
- [ ] Accurate speed classification (very_low/low/high)

**Priority:** Week 3