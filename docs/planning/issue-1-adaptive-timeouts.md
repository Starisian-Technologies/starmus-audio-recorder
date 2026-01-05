**Title:** 🚨 CRITICAL: Replace Fixed 2s Timeout with Adaptive Network-Aware Timeouts

**Labels:** `critical`, `network`, `african-markets`, `week-1`

**Body:**

## Problem

Fixed 2-second timeout causes false fallbacks on African 2G/3G networks, leading users to blame "the app" instead of network conditions.

## Solution

Implement adaptive timeouts based on detected network quality:

- **2G/slow-2G**: 15 seconds
- **3G**: 8 seconds  
- **4G+**: 3 seconds
- **Unknown**: 10 seconds fallback

## Files to Modify

- `src/js/starmus-sparxstar-integration.js` - Replace fixed timeout logic

## Acceptance Criteria

- [ ] Network quality detected before setting timeout
- [ ] Timeout varies by network type (15s/8s/3s/10s)
- [ ] Console logs show timeout reasoning
- [ ] False fallback rate <5% on 2G networks

**Priority:** 🔥 CRITICAL - Week 1
