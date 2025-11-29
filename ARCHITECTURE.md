Starmus Audio Recorder — Architecture & Separation of Concerns (2025 Edition)
=============================================================================

Purpose
-------

The Starmus Audio Recorder is the **frontline acquisition system** in the Starisian stack.It captures audio, enforces consent, normalizes metadata, and produces artifacts that later graduate into the **AiWA corpus**.No data flows into AiWA until Starmus certifies it.

Runtime Bootstrap Contract
--------------------------

Starmus never initializes unless one of these globals exists:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   window.STARMUS_BOOTSTRAP = {    pageType: 'recorder' | 'rerecorder' | 'editor',    postId: number | null,    restUrl: string,    mode: string,    canCommit: boolean,    transcript: array|null,    audioUrl: string|null  }   `

This object **must** be present before JS bundles run.It defines **what this page is allowed to do**.

Module Responsibilities
-----------------------

### 1\. starmus-audio-recorder-module.js — Core Recording Engine

**Responsibility**: Capture and process live audio

*   MediaRecorder lifecycle (start/pause/resume/stop)
    
*   Gain analysis + meter updates
    
*   Timer and progress bar state
    
*   Audio blob normalization
    
*   Tier detection influences these controls
    
*   No knowledge of UI, WordPress, or uploads
    

**Public API**

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   RecorderEngine.init(options)  RecorderEngine.getSubmissionData(instanceId)  RecorderEngine.cleanup(instanceId)   `

### 2\. starmus-audio-recorder-ui-controller.js — Interface Orchestration

**Responsibility**: State machine for the two-step recorder UI

*   Validates **Step 1** fields (title, language, type, consent)
    
*   Advances or blocks recording stage
    
*   Shows Tier A/B recorder or Tier C upload fallback
    
*   Sanitizes text input for safety
    
*   Delegates save operations to Submission Handler
    

**NOTES**

*   Never touches blobs
    
*   Never uploads anything
    
*   Never talks to REST directly
    

### 3\. starmus-audio-recorder-submissions-handler.js — Persistence Layer

**Responsibility**: Network, uploads, and offline queue

*   TUS chunked uploads (resume-safe)
    
*   Offline IndexedDB FIFO queue
    
*   REST calls with capability + nonce
    
*   Metadata composition from UI + Bootstrap
    
*   Delete/rollback capabilities
    

**Public API**

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   StarmusSubmissionsHandler.handleSubmit(instanceId, form)  StarmusSubmissionsHandler.initRecorder(instanceId)  StarmusSubmissionsHandler.revealTierC(instanceId)   `

Everything here is **idempotent**.

### 4\. starmus-audio-recorder-submissions.js — Tier C / Legacy Layer

**Responsibility**: Browser salvage operations

*   ES5 safety net
    
*   Polyfills for .forEach, .trim, Blob support
    
*   Fallback file upload path
    
*   Enhanced error messaging for low-capability devices
    

If Tier C runs, no modern modules execute.

Editor & Re-Recorder Extensions
-------------------------------

### starmus-audio-editor.js _(not previously documented)_

**Responsibility**: Review, segment, annotate, sync transcript

*   Peaks.js waveform rendering
    
*   Region/annotation persistence
    
*   Loads via STARMUS\_BOOTSTRAP.pageType === 'editor'
    
*   Transcript scroll sync
    

### starmus-re-recorder.js

**Responsibility**: Replace a previous recording

*   Preloads reference audio + transcript
    
*   Maintains original metadata linkage
    
*   Uses same submission handler
    

Data Flow
---------

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   USER → UI Controller → Recorder Engine → Submissions Handler                │               │              │                ▼               ▼              ▼           Bootstrap      Audio Blobs    TUS / REST / Queue                                           ↓                                        WordPress   `

Graduation into **AiWA** happens only after a human or AI approves the transcript.

Security Model
--------------

1.  **Nonce + capability checks** for every POST/PUT
    
2.  **Sanitized inputs** before DOM insertion
    
3.  **Escaped outputs** before rendering
    
4.  **Upload MIME checks** + allowed types enforcement
    
5.  **Offline queue sealed** against replay attacks
    
6.  **Recorder never stores PII** without explicit consent
    

Performance Standards
---------------------

*   No blocking scripts
    
*   DOM cached before mutations
    
*   Single RecorderEngine instance per form
    
*   Avoid heap growth by releasing blob URLs
    
*   IndexedDB batching to reduce write thrash
    
*   Payload ceilings enforced by CI
    

Architectural Principles
------------------------

1.  **Bootstrap-first**Nothing initializes without a pageType contract.
    
2.  **Separation of duties**UI is not allowed to upload; engine is not allowed to touch the DOM.
    
3.  **Replaceable layers**Each module can fail without collapsing the system.
    
4.  **Offline-first**Queue always wins over network optimism.
    
5.  **No shared mutable globals** except the bootstrap
    

What Changed From Your Old Version
----------------------------------

AreaOld DocUpdated RealityEditor lifecycleMissingFully describedBootstrap ContractNot referencedNow mandatoryTranscript syncNot mentionedCore requirementRe-Recorder flowImplicitExplicitUnified SchemaNot appliedAiWA constraints enforced