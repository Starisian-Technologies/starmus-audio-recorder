**Title:** Implement Centralized Fallback Decision Manager

**Labels:** `architecture`, `reliability`, `week-2`

**Body:**
## Problem
Subsystems make independent fallback decisions causing:
- Double fallbacks
- Contradictory states  
- Impossible debugging

## Solution
Create centralized managers:
- NetworkManager
- StorageManager
- TierManager

## Invariant Rule
No subsystem may decide "fallback" independently. All fallback decisions must route through managers.

## Files to Modify
- `src/js/starmus-network-manager.js` - Create NetworkManager
- `src/js/starmus-storage-manager.js` - Create StorageManager  
- `src/js/starmus-tier-manager.js` - Create TierManager

## Acceptance Criteria
- [ ] All network fallbacks go through NetworkManager
- [ ] All storage fallbacks go through StorageManager
- [ ] All tier decisions go through TierManager
- [ ] No direct fallback decisions in subsystems

**Priority:** Week 2