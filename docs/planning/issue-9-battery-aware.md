**Title:** Battery-Aware Processing for Mobile Devices

**Labels:** `optimization`, `battery`, `mobile`, `week-3`

**Body:**

## Problem

No battery monitoring causes excessive drain on mobile devices.

## Solution

```javascript
optimizeForBattery() {
  if ('getBattery' in navigator) {
    navigator.getBattery().then(battery => {
      if (battery.level < 0.2) {
        this.enablePowerSaving = true;
      }
    });
  }
}
```

## Requirements

- Use navigator.getBattery() API
- Enable power saving mode at <20% battery
- Reduce processing intensity when low battery

## Files to Modify

- `src/js/starmus-recorder.js` - Add battery monitoring
- `src/js/starmus-enhanced-calibration.js` - Reduce calibration time

## Acceptance Criteria

- [ ] Battery level detection
- [ ] Power saving mode at <20% battery
- [ ] Reduced processing when low battery
- [ ] Non-blocking, async battery checks

**Priority:** Week 3
