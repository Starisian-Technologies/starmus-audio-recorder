**Title:** Add Storage Quota Monitoring and Cleanup

**Labels:** `performance`, `storage`, `week-2`

**Body:**
## Problem
No quota monitoring leads to storage exhaustion and failed submissions.

## Solution
```javascript
async checkStorageQuota() {
  const estimate = await navigator.storage.estimate();
  const usagePercent = (estimate.usage / estimate.quota) * 100;
  
  if (usagePercent > 80) {
    await this.cleanup();
    // warn + degrade in background, don't throw
  }
}
```

## Files to Modify
- `src/js/starmus-offline.js` - Add quota monitoring

## Acceptance Criteria
- [ ] Quota checked before adding submissions
- [ ] Automatic cleanup at 80% usage
- [ ] Background degradation, not user-facing errors

**Priority:** Week 2