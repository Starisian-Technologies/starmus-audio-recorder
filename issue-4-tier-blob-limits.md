**Title:** 🚨 CRITICAL: Implement Tier-Based Blob Size Limits for African Devices

**Labels:** `critical`, `performance`, `african-markets`, `week-1`

**Body:**

## Problem

40MB limit is unrealistic for:

- 2GB RAM phones
- Shared-memory browsers
- Thermal-throttled CPUs
- Poor network conditions

## Solution

Implement tier-based limits:

- **Tier A**: 20MB (high-end devices)
- **Tier B**: 10MB (mid-range devices)
- **Tier C**: 5MB (low-end devices)

## Files to Modify

- `src/js/starmus-offline.js` - Update CONFIG with tier-based limits

## Acceptance Criteria

- [ ] Tier-based size limits enforced (20MB/10MB/5MB)
- [ ] Default to Tier C (5MB) for safety
- [ ] Oversized file attempts reported to SPARXSTAR
- [ ] Clear error messages with tier context

**Priority:** 🔥 CRITICAL - Week 1
