# Week 1 - Critical Fixes GitHub Issues

## Issue 1: 🚨 CRITICAL: Replace Fixed 2s Timeout with Adaptive Network-Aware Timeouts

**Labels:** `critical`, `network`, `african-markets`, `week-1`

### Problem

Fixed 2-second timeout causes false fallbacks on African 2G/3G networks, leading users to blame "the app" instead of network conditions.

### Solution

Implement adaptive timeouts based on detected network quality:

- **2G/slow-2G**: 15 seconds
- **3G**: 8 seconds  
- **4G+**: 3 seconds
- **Unknown**: 10 seconds fallback

### Files to Modify

- `src/js/starmus-sparxstar-integration.js` - Replace fixed timeout logic
- Add network quality detection before timeout setting

### Acceptance Criteria

- [ ] Network quality detected before setting timeout
- [ ] Timeout varies by network type (15s/8s/3s/10s)
- [ ] Console logs show timeout reasoning
- [ ] False fallback rate <5% on 2G networks

---

## Issue 2: 🚨 CRITICAL: Add Circuit Breaker to Prevent Upload Retry Storms

**Labels:** `critical`, `network`, `security`, `week-1`

### Problem

No circuit breaker means retry storms during network failures, causing:

- Server overload
- Battery drain
- User frustration
- Impossible debugging

### Solution

Implement upload circuit breaker:

- Open after 3 consecutive failures
- 1-minute timeout before retry
- Half-open state for testing recovery

### Files to Modify

- `src/js/starmus-tus.js` - Add UploadCircuitBreaker class
- Wrap uploadWithPriority in circuit breaker

### Acceptance Criteria

- [ ] Circuit breaker opens after 3 failures
- [ ] 1-minute timeout before retry attempts
- [ ] Prevents >90% of retry storms
- [ ] SPARXSTAR error reporting integration

---

## Issue 3: 🚨 CRITICAL: Fix IndexedDB Silent Failure - Zero Tolerance Policy

**Labels:** `critical`, `storage`, `debugging`, `week-1`

### Problem

Silent IndexedDB failures hide:

- Quota exhaustion
- Private browsing restrictions  
- OEM browser bugs (Tecno/Infinix/Itel)
- Corrupt object stores

### Solution

Replace all silent failures with explicit error handling:

- Throw errors instead of resolve()
- Detailed error reporting to SPARXSTAR
- User-friendly error messages
- Private browsing detection

### Files to Modify

- `src/js/starmus-offline.js` - Replace silent failures
- Add _reportStorageFailure method
- Add _detectPrivateBrowsing method

### Acceptance Criteria

- [ ] Zero silent storage failures
- [ ] All errors reported to SPARXSTAR with context
- [ ] User-friendly error messages shown
- [ ] Private browsing mode detected and handled

---

## Issue 4: 🚨 CRITICAL: Implement Tier-Based Blob Size Limits for African Devices

**Labels:** `critical`, `performance`, `african-markets`, `week-1`

### Problem

40MB limit is unrealistic for:

- 2GB RAM phones
- Shared-memory browsers
- Thermal-throttled CPUs
- Poor network conditions

### Solution

Implement tier-based limits:

- **Tier A**: 20MB (high-end devices)
- **Tier B**: 10MB (mid-range devices)
- **Tier C**: 5MB (low-end devices)

### Files to Modify

- `src/js/starmus-offline.js` - Update CONFIG with tier-based limits
- Update add() method to check tier-appropriate limits

### Acceptance Criteria

- [ ] Tier-based size limits enforced (20MB/10MB/5MB)
- [ ] Default to Tier C (5MB) for safety
- [ ] Oversized file attempts reported to SPARXSTAR
- [ ] Clear error messages with tier context

---

## Issue 5: 🚨 CRITICAL: Fix TUS Webhook Security Vulnerability - Hard Stop

**Labels:** `critical`, `security`, `vulnerability`, `week-1`

### Problem

Empty webhook secret fallback is an exploit invitation:

```javascript
'x-starmus-secret': cfg.webhookSecret || '' // SECURITY RISK
```

### Solution

Block uploads when webhook secret missing:

- Throw error if secret empty/missing
- Add secure headers with timestamp
- Report security violations to SPARXSTAR

### Files to Modify

- `src/js/starmus-tus.js` - Add webhook secret validation
- Enhance headers with security context

### Acceptance Criteria

- [ ] Zero uploads allowed without webhook secrets
- [ ] Security violations reported to SPARXSTAR
- [ ] Enhanced headers include timestamp and payload hash
- [ ] Clear error message when secret missing

---

# Week 2 - Performance & Stability GitHub Issues

## Issue 6: Add Storage Quota Monitoring and Cleanup

**Labels:** `performance`, `storage`, `week-2`

### Problem

No quota monitoring leads to storage exhaustion and failed submissions.

### Solution

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

### Acceptance Criteria

- [ ] Quota checked before adding submissions
- [ ] Automatic cleanup at 80% usage
- [ ] Background degradation, not user-facing errors

---

## Issue 7: Add Memory Pressure Detection for African Devices

**Labels:** `performance`, `memory`, `african-markets`, `week-2`

### Problem

No memory monitoring on low-RAM devices causes crashes.

### Solution

```javascript
detectMemoryPressure() {
  const memory = performance.memory;
  if (memory && memory.usedJSHeapSize > memory.jsHeapSizeLimit * 0.9) {
    return true; // Advisory only - OEM browsers lie
  }
  return false;
}
```

### Acceptance Criteria

- [ ] Memory pressure detection (advisory only)
- [ ] Graceful degradation on high memory usage
- [ ] Browser support guards included

---

# Week 3 - African Market Optimizations

## Issue 8: Real Network Speed Measurement

**Labels:** `optimization`, `network`, `african-markets`, `week-3`

### Requirements

- 1KB test file (cache-busted, uncompressed, same-origin)
- Absolute thresholds: <50kbps=very_low, <500kbps=low, else=high
- Non-blocking, async measurement

---

## Issue 9: Battery-Aware Processing

**Labels:** `optimization`, `battery`, `mobile`, `week-3`

### Requirements

- Use navigator.getBattery() API
- Enable power saving mode at <20% battery
- Reduce processing intensity when low battery

---

# Implementation Guardrail

## Invariant Rule: Centralized Fallback Decisions

**All fallback decisions must route through:**

- NetworkManager
- StorageManager  
- TierManager

**Prevents:**

- Double fallbacks
- Contradictory states
- Impossible debugging

No subsystem may decide "fallback" independently.
