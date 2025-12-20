**Title:** Add Memory Pressure Detection for African Devices

**Labels:** `performance`, `memory`, `african-markets`, `week-2`

**Body:**
## Problem
No memory monitoring on low-RAM devices causes crashes.

## Solution
```javascript
detectMemoryPressure() {
  const memory = performance.memory;
  if (memory && memory.usedJSHeapSize > memory.jsHeapSizeLimit * 0.9) {
    return true; // Advisory only - OEM browsers lie
  }
  return false;
}
```

## Files to Modify
- `src/js/starmus-sparxstar-integration.js` - Add memory detection

## Acceptance Criteria
- [ ] Memory pressure detection (advisory only)
- [ ] Graceful degradation on high memory usage
- [ ] Browser support guards included

**Priority:** Week 2