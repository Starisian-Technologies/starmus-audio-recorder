**Title:** 🚨 CRITICAL: Add Circuit Breaker to Prevent Upload Retry Storms

**Labels:** `critical`, `network`, `security`, `week-1`

**Body:**
## Problem
No circuit breaker means retry storms during network failures, causing server overload, battery drain, and impossible debugging.

## Solution
Implement upload circuit breaker:
- Open after 3 consecutive failures
- 1-minute timeout before retry
- Half-open state for testing recovery

## Files to Modify
- `src/js/starmus-tus.js` - Add UploadCircuitBreaker class
- Wrap uploadWithPriority in circuit breaker

## Acceptance Criteria
- [ ] Circuit breaker opens after 3 failures
- [ ] 1-minute timeout before retry attempts
- [ ] Prevents >90% of retry storms
- [ ] SPARXSTAR error reporting integration

**Priority:** 🔥 CRITICAL - Week 1