# Tier Detection Testing Checklist

**Purpose**: Validate tier classification accuracy on real West African hardware before implementing Permission State Sync (P1.2).

**Status**: Ready for Testing  
**Date**: November 20, 2025  
**Version**: 0.8.5

---

## 🎯 Testing Objectives

1. **Tier C devices correctly show file upload fallback**
2. **Tier B devices load recorder with degraded features**
3. **Tier A devices get full functionality**
4. **No misclassification across device categories**
5. **Telemetry accurately reports device capabilities**

---

## 📱 Test Devices Required

### **Tier C Expected Devices**

- [ ] Infinix Smart series (Android Go)
- [ ] Tecno Spark 2 or older
- [ ] Itel A-series (A16, A23)
- [ ] Samsung Galaxy J2/J4
- [ ] Old Firefox Mobile (< v68)
- [ ] School computer (512MB-1GB RAM)
- [ ] WebView inside third-party app

### **Tier B Expected Devices**

- [ ] Infinix Note 5/6 (2GB RAM)
- [ ] Tecno Spark 7/8
- [ ] Samsung A10/A20
- [ ] Devices on 2G/3G network
- [ ] Battery saver mode enabled

### **Tier A Expected Devices**

- [ ] Modern Android (4GB+ RAM)
- [ ] iPhone 11 or newer
- [ ] Desktop Chrome/Firefox
- [ ] 4G/WiFi connection
- [ ] Recent Samsung flagships

---

## ✅ Tier C Validation Tests

### **Test 1: UI Fallback Activation**

- [ ] Open recorder on Tier C device
- [ ] **Expected**: File upload UI visible immediately
- [ ] **Expected**: No recorder controls visible
- [ ] **Expected**: Message: "Record on your device, then upload here"
- [ ] **Verify**: No "Initializing microphone..." message
- [ ] **Verify**: No mic permission popup

### **Test 2: File Upload Works**

- [ ] Select audio file from device
- [ ] **Expected**: File attaches successfully
- [ ] **Expected**: Submit button enabled
- [ ] **Expected**: Upload uses TUS if > 1MB
- [ ] **Verify**: Offline queue works if upload fails

### **Test 3: No Recorder Initialization**

- [ ] Check browser console logs
- [ ] **Expected**: `[Tier Detection] Tier C: <reason>`
- [ ] **Expected**: `[Starmus] Tier C mode: Revealing file upload fallback`
- [ ] **Expected**: No MediaRecorder initialization
- [ ] **Expected**: No calibration attempts
- [ ] **Expected**: No speech recognition init

### **Test 4: Telemetry Dispatch**

- [ ] Check for `starmus_tier_c_revealed` hook dispatch
- [ ] **Verify**: Hook includes instanceId and env data
- [ ] **Verify**: Telemetry shows correct detection reason:
  - No MediaRecorder support
  - Low RAM (<1GB)
  - Low CPU (<2 threads)
  - WebView detected
  - Storage quota <80MB
  - Permission denied

### **Test 5: Edge Cases**

- [ ] **Reload page**: Still shows Tier C
- [ ] **Switch tabs**: Remains stable
- [ ] **Submit without file**: Shows error
- [ ] **Network offline**: Can still attach file
- [ ] **Offline queue**: Saves submission when offline

---

## ✅ Tier B Validation Tests

### **Test 1: Degraded Recorder Mode**

- [ ] Open recorder on Tier B device (2G network or 1-2GB RAM)
- [ ] **Expected**: Recorder UI loads
- [ ] **Expected**: Record/Stop buttons visible
- [ ] **Expected**: No waveform visualization
- [ ] **Verify**: Speech recognition disabled
- [ ] **Verify**: Low-bitrate audio (16kHz, 24kbps)

### **Test 2: Network Quality Adaptation**

- [ ] Test on 2G network
- [ ] **Expected**: Tier B classification
- [ ] **Expected**: Message about network quality
- [ ] **Verify**: Audio encoding: 16kHz, mono, 24kbps
- [ ] **Verify**: Recorder still functional

### **Test 3: Marginal RAM Handling**

- [ ] Test on device with 1-2GB RAM
- [ ] **Expected**: Tier B classification
- [ ] **Expected**: Recorder works but minimal UI
- [ ] **Verify**: No browser freeze
- [ ] **Verify**: No out-of-memory crashes

### **Test 4: Recording Functionality**

- [ ] Start recording
- [ ] **Expected**: MediaRecorder initializes
- [ ] **Expected**: Calibration runs (simplified)
- [ ] **Expected**: Recording completes successfully
- [ ] **Verify**: Audio quality matches network tier
- [ ] **Verify**: Upload uses TUS if available

### **Test 5: UI Responsiveness**

- [ ] **Verify**: No lag during recording
- [ ] **Verify**: Stop button responds immediately
- [ ] **Verify**: Form submission works
- [ ] **Verify**: Offline queue activates on failure

---

## ✅ Tier A Validation Tests

### **Test 1: Full Feature Activation**

- [ ] Open recorder on modern device
- [ ] **Expected**: Tier A classification
- [ ] **Expected**: All features enabled
- [ ] **Verify**: Speech recognition active
- [ ] **Verify**: Waveform visualization (if implemented)
- [ ] **Verify**: High-quality audio (48kHz, 128kbps+)

### **Test 2: Calibration Process**

- [ ] Start recording
- [ ] **Expected**: 3-phase calibration (15 seconds)
- [ ] **Expected**: Volume meter visible
- [ ] **Expected**: SNR calculation
- [ ] **Verify**: Calibration data stored
- [ ] **Verify**: Adaptive gain applied

### **Test 3: Speech Recognition**

- [ ] Record audio with speech
- [ ] **Expected**: Live transcription visible
- [ ] **Expected**: Transcript updates during recording
- [ ] **Verify**: Transcript attached to upload
- [ ] **Verify**: Language matches form selection

### **Test 4: Upload Performance**

- [ ] Record large file (> 5MB)
- [ ] **Expected**: TUS resumable upload used
- [ ] **Expected**: Progress bar updates smoothly
- [ ] **Verify**: Can resume after interruption
- [ ] **Verify**: Chunked upload (5MB chunks)

### **Test 5: Offline Recovery**

- [ ] Start upload, then disconnect network
- [ ] **Expected**: Upload fails gracefully
- [ ] **Expected**: Saved to offline queue
- [ ] **Expected**: Message: "Saved offline. Will submit automatically when online."
- [ ] Reconnect network
- [ ] **Expected**: Auto-retry within 60 seconds
- [ ] **Verify**: Upload completes successfully

---

## 🔄 Dynamic Tier Switching Tests

### **Test 1: Network Quality Changes**

- [ ] Start on 4G (Tier A)
- [ ] Switch to 2G mid-session
- [ ] **Expected**: Tier remains A (no mid-session downgrade)
- [ ] **Alternative**: Show warning about network quality

### **Test 2: Battery Saver Mode**

- [ ] Enable battery saver during recording
- [ ] **Expected**: Recording continues
- [ ] **Verify**: No performance degradation
- [ ] **Verify**: Upload still works

### **Test 3: Low Storage Warning**

- [ ] Fill device storage to <100MB
- [ ] **Expected**: Warning before recording
- [ ] **Alternative**: Tier C fallback if <80MB quota

---

## 📊 Telemetry Validation

### **Check Console Logs**

For each tier, verify console output:

**Tier A:**

```
[Tier Detection] Tier A: Full features enabled
[Starmus] Instance starmus_xxx detected as Tier A
```

**Tier B:**

```
[Tier Detection] Tier B: Slow network (2G)
[Starmus] Instance starmus_xxx detected as Tier B
```

**Tier C:**

```
[Tier Detection] Tier C: Low RAM (<1GB)
[Starmus] Instance starmus_xxx detected as Tier C
[Starmus] Tier C mode: Revealing file upload fallback
```

### **Check State Store**

Open browser DevTools → Console:

```javascript
window.STARMUS.instances.get('starmus_xxx').store.getState()
```

**Verify:**

- [ ] `tier` field matches expected ('A', 'B', or 'C')
- [ ] `env.device.memory` correctly reported (or null)
- [ ] `env.device.concurrency` correctly reported (or null)
- [ ] `env.network.effectiveType` matches network
- [ ] `env.capabilities` reflects browser support

### **Check Network Tab**

- [ ] TUS endpoint called for Tier A large files
- [ ] Direct upload for Tier B/C or small files
- [ ] Retry attempts visible on failure
- [ ] Offline queue processes when online

---

## 🚨 Known Failure Modes to Test

### **1. Android Low Memory Kill**

- [ ] Record audio on low-RAM device
- [ ] Switch to another app
- [ ] Return to browser
- [ ] **Expected**: Recording lost (unavoidable)
- [ ] **Expected**: Form data preserved
- [ ] **Alternative**: Offline queue saved partial recording

### **2. iOS Background Tab Freeze**

- [ ] Start recording on iPhone
- [ ] Switch to home screen
- [ ] Return after 30 seconds
- [ ] **Expected**: Recording stopped
- [ ] **Expected**: Partial audio saved (if possible)

### **3. Unstable Network Switching**

- [ ] Upload while switching 2G ↔ 3G ↔ 4G
- [ ] **Expected**: TUS handles reconnection
- [ ] **Expected**: Upload resumes from last chunk
- [ ] **Verify**: No data corruption

### **4. Browser Crash During Upload**

- [ ] Start large upload
- [ ] Force-close browser
- [ ] Reopen page
- [ ] **Expected**: Upload in offline queue
- [ ] **Expected**: Auto-retry when online

### **5. Quota Exceeded**

- [ ] Fill IndexedDB quota
- [ ] Attempt offline save
- [ ] **Expected**: Falls back to localStorage
- [ ] **Alternative**: Shows error, keeps recording

---

## 📝 Test Results Template

```markdown
## Device: [Device Name]
**OS**: [Android 10 / iOS 15 / etc.]
**Browser**: [Chrome 120 / Safari 17 / etc.]
**RAM**: [X GB]
**Network**: [4G / 3G / 2G / WiFi]

### Tier Classification
- **Detected Tier**: [A / B / C]
- **Expected Tier**: [A / B / C]
- **Match**: [✅ / ❌]

### Test Results
- Tier C Validation: [Pass / Fail / N/A]
- Tier B Validation: [Pass / Fail / N/A]
- Tier A Validation: [Pass / Fail / N/A]
- Telemetry Accuracy: [Pass / Fail]
- Offline Queue: [Pass / Fail]
- TUS Upload: [Pass / Fail / N/A]

### Issues Found
[List any bugs, misclassifications, or unexpected behavior]

### Console Logs
```

[Paste relevant console output]

```
```

---

## 🎯 Success Criteria

**Before proceeding to P1.2, ALL of these must pass:**

- [ ] **Zero Tier C misclassifications** (no false positives)
- [ ] **Zero Tier A misclassifications** (no weak devices getting full features)
- [ ] **Tier B correctly handles 2G networks**
- [ ] **File upload fallback works on all Tier C devices**
- [ ] **Recorder loads without errors on Tier A/B**
- [ ] **Telemetry accurately reports all device metrics**
- [ ] **Offline queue works across all tiers**
- [ ] **TUS upload resumes correctly on Tier A**
- [ ] **No browser crashes on low-RAM devices**
- [ ] **Console logs show correct tier detection reasons**

---

## 🔧 Debugging Tools

### **Force Tier for Testing**

Add to browser console:

```javascript
localStorage.setItem('starmus_force_tier', 'C'); // Force Tier C
location.reload();
```

### **View Current Tier**

```javascript
const instances = window.STARMUS.instances;
instances.forEach((inst, id) => {
    console.log(`Instance ${id}: Tier ${inst.store.getState().tier}`);
});
```

### **Check Telemetry**

```javascript
const state = window.STARMUS.instances.values().next().value.store.getState();
console.table(state.env.device);
console.table(state.env.network);
console.table(state.env.capabilities);
```

### **Simulate Low RAM**

Chrome DevTools → Performance → CPU throttling (6x slowdown simulates low-end device)

### **Simulate 2G Network**

Chrome DevTools → Network → Throttling → Slow 2G

---

## 📞 Contact for Test Results

**Testing Lead**: Muhammed (Gambia field testing)  
**Devices Available**: Infinix, Tecno, Itel, Samsung J-series  
**Network Conditions**: Banjul 4G, Brikama 3G, rural 2G/EDGE

**Report Issues To**: GitHub Issues with:

- Device model
- Network type
- Screenshot of console logs
- State store dump
- Steps to reproduce

---

## 🚀 Next Steps After Testing

1. ✅ Validate Tier A/B/C on 10+ devices
2. ✅ Fix any misclassification bugs
3. ✅ Confirm telemetry accuracy
4. ✅ Document edge cases found
5. ➡️ **THEN** proceed to P1.2 (Permission State Sync)

---

**Last Updated**: November 20, 2025  
**Build Version**: 0.8.5  
**Ready for Field Testing**: ✅
