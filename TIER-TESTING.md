**Starmus + Sparxstar UEC Cross-Validation Protocol**
=====================================================

**Purpose**Verify that the **Sparxstar User Environment Check (UEC)** tier classification is accurately consumed by **Starmus Audio Recorder** and produces correct UI and feature gating across real West African devices.

**Document Status**: Updated for Bootstrap Contract v2.1**Date**: March 2026**Applies To**: Build 0.9.0+**Scope**: Recorder / Re-Recorder / Editor pages

**System Under Test**
---------------------

### **Inputs to Tier Detection**

Tier = Function of:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`env.device.memory  env.device.concurrency  env.network.effectiveType  env.browser.supports.MediaRecorder  env.capabilities.speechRecognition  env.storage.quota  env.permissionState.microphone`

These are collected by **Sparxstar UEC**, stored in its snapshot, and passed into:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`window.STARMUS_BOOTSTRAP.env`

No recorder module may compute tier independently.

**Tier Roles**
--------------

TierDevice ClassRecorder UISpeech RecWaveformTUSOffline QueueFallbackAModern/4GB+/WiFiFullYesYesYesYesNoBMid-level/2GB/slow netBasicNoNoConditionalYesNoCLegacy/<1GB/WebViewNoneNoNoNoYes**File Upload Only**

**Test Objectives**
-------------------

1. **UEC and Starmus agree on tier**

2. **Recorder UI behavior matches tier contract**

3. **Bootstrap contract is respected**

4. **No recorder initialization on Tier C**

5. **No Tier A behavior on weak devices**

6. **Telemetry correctly logs reasons and bootstrap state**

**Required Devices**
--------------------

Use the same model list you already prepared. No changes required there.

**UPDATED VALIDATION STEPS**
----------------------------

### **Step 0 — Bootstrap Verification (NEW)**

Open DevTools before interacting:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`window.STARMUS_BOOTSTRAP`

Verify:

* pageType exists (recorder, rerecorder, or editor)

* env exists

* No other globals are read by recorder modules

If bootstrap missing → **FAIL AUTOMATICALLY**

**Tier C Checklist (Updated)**
------------------------------

Expected Logs:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`[SparxstarUEC] Tier C reason: memory < 1GB  [Starmus] Consuming Tier C — revealing fallback`

**Disallowed Logs**:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`Initializing MediaRecorder...  Calibrating microphone...  Starting speech recognition...`

**Pass Criteria**:

* Recorder JS never loads MediaRecorder

* Only file upload widgets visible

* Offline queue operates without recorder engine

**Tier B Checklist (Updated)**
------------------------------

Expected Logs:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`[SparxstarUEC] Tier B: network slow (2G)  [Starmus] Recorder enabled (no waveform)`

Must NOT display waveform or speech transcript.

**Tier A Checklist (Updated)**
------------------------------

Expected Logs:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`[SparxstarUEC] Tier A: full device capabilities  [Starmus] Waveform + Speech Rec enabled`

Speech recognition must match **language selected on Step 1**.

**Dynamic Behavior Rules (Updated)**
------------------------------------

* **Tier NEVER changes mid-session**

* Network downgrade triggers warning, _not_ tier change

* Battery saver mode does not demote tier

* iOS tab freeze stops recording but does not corrupt queue

**Telemetry Cross-Validation**
------------------------------

Check:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`window.STARMUS.instances.get(id).store.getState()`

Expect fields:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`tier           // 'A' | 'B' | 'C'  reason         // from UEC  bootstrapHash  // cache integrity`

If tier exists without a reason → **FAIL**

**Test Results Template (Updated)**
-----------------------------------

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`## Device: [Model]  **Detected Tier**: [A/B/C]  **Expected Tier**: [A/B/C]  **Match**: [Y/N]  **Bootstrap Present**: [Y/N]  **Reason Logged**: [Y/N]  **Recorder Behavior Correct**: [Y/N]  **TUS Behavior**: [Pass/Fail]  **Offline Queue**: [Pass/Fail]  **Notes**:`

**Success Criteria (Updated)**
------------------------------

All must be checked before enabling **Permission State Sync (P1.2)**:

* Bootstrap contract present on all recorder pages

* UEC tier matches recorder behavior on all tested devices

* No recorder initialization on Tier C

* No waveform on Tier B

* Speech recognition only on Tier A

* Offline queue persistent across reloads

* TUS resumable on Tier A only

* No mid-session tier switching
