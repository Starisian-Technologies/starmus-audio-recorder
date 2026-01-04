**Title:** 🚨 CRITICAL: Fix IndexedDB Silent Failure - Zero Tolerance Policy

**Labels:** `critical`, `storage`, `debugging`, `week-1`

**Body:**

## Problem

Silent IndexedDB failures hide:

- Quota exhaustion
- Private browsing restrictions  
- OEM browser bugs (Tecno/Infinix/Itel)
- Corrupt object stores

## Solution

Replace all silent failures with explicit error handling:

- Throw errors instead of resolve()
- Detailed error reporting to SPARXSTAR
- User-friendly error messages
- Private browsing detection

## Files to Modify

- `src/js/starmus-offline.js` - Replace silent failures
- Add _reportStorageFailure method
- Add _detectPrivateBrowsing method

## Acceptance Criteria

- [ ] Zero silent storage failures
- [ ] All errors reported to SPARXSTAR with context
- [ ] User-friendly error messages shown
- [ ] Private browsing mode detected and handled

**Priority:** 🔥 CRITICAL - Week 1
