**Title:** 🚨 CRITICAL: Fix TUS Webhook Security Vulnerability - Hard Stop

**Labels:** `critical`, `security`, `vulnerability`, `week-1`

**Body:**
## Problem
Empty webhook secret fallback is an exploit invitation:
```javascript
'x-starmus-secret': cfg.webhookSecret || '' // SECURITY RISK
```

## Solution
Block uploads when webhook secret missing:
- Throw error if secret empty/missing
- Add secure headers with timestamp
- Report security violations to SPARXSTAR

## Files to Modify
- `src/js/starmus-tus.js` - Add webhook secret validation

## Acceptance Criteria
- [ ] Zero uploads allowed without webhook secrets
- [ ] Security violations reported to SPARXSTAR
- [ ] Enhanced headers include timestamp and payload hash
- [ ] Clear error message when secret missing

**Priority:** 🔥 CRITICAL - Week 1