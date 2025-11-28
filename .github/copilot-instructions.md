Copilot Instructions: Starmus Audio Recorder
============================================

Mission
-------

Maintain a WordPress-based audio-recording system optimized for **mobile, weak networks, and offline submission**. All changes must preserve:

*   **Offline-first behavior**
    
*   **Small payloads** (≤60KB JS, ≤25KB CSS gzipped)
    
*   **Progressive enhancement** across browser tiers
    

Do not introduce abstractions that increase complexity or break compatibility.

System Boundaries
-----------------

### PHP Kernel

Main components live under PSR-4 namespaces:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   Starmus\    StarmusPlugin.php                // Entry point: registers hooks, loads services    frontend/      StarmusAudioRecorderUI.php     // Recorder UI + bootstrap      StarmusAudioEditorUI.php       // Editor UI + Peaks bootstrap   `

### Storage Model

Custom post types:

*   audio-recording (primary artifact)
    
*   consent-agreement (legal metadata)
    

Taxonomies:

*   language
    
*   recording\_type
    

All metadata must be created, retrieved, or mutated via WordPress APIs. No direct DB writes.

JavaScript Execution Model
--------------------------

The JS layer is modular. Do not collapse responsibilities.

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   starmus-audio-recorder-module.js       // MediaRecorder, audio graph, calibration  starmus-audio-recorder-ui-controller.js// Form wizard (step1/step2), UI state  starmus-audio-recorder-submissions.js  // Upload, IndexedDB queue, tus.io support  starmus-offline-sync.js                // Polyfills + legacy queue   `

Each module assumes:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   window.STARMUS_BOOTSTRAP exists before any init   `

If this object is missing or late-loaded, **the system will not initialize**.

Runtime Invariants
------------------

Claude must respect these rules at all times:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   1. The bootstrap object is created by PHP before JS runs:     window.STARMUS_BOOTSTRAP = { postId, restUrl, mode, canCommit, ... }  2. Recorder pages initialize only when:   `

   `3. Editor pages initialize only when:`

   `4. No JS module fetches DOM nodes until bootstrap is detected.`

Any change that breaks these invariants is rejected.

Naming Rules (strict)
---------------------

*   PHP Namespace: Starmus\\\*
    
*   REST Namespace: star-/v1
    
*   Actions/filters: starmus\_\*
    
*   Frontend handles: starmus-audio-\*
    
*   Error objects: WP\_Error only at boundaries
    
*   No globals except the bootstrap object
    

Security & Offline Constraints
------------------------------

Claude must maintain:

*   **IndexedDB offline queue** for uploads
    
*   **Chunked uploads** (tus.io) with resume
    
*   **Nonces + capabilities** on REST endpoints
    
*   **Sanitization** of input, **escaping** of output
    

If offline behavior regresses, reject the change.

Testing & Validation Commands
-----------------------------

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   npm run build         // Full asset pipeline  npm run test:e2e      // Frontend recorder/editor tests  composer test         // PHPUnit + static analysis  composer run lint:php // Code style   `

Nothing is considered "done" unless it passes both JS and PHP checks.

Claude’s Job
------------

When modifying code:

1.  Identify the boundary (PHP → JS → REST → queue)
    
2.  Do not move logic across boundaries
    
3.  Never remove or rename the bootstrap
    
4.  Keep code minimal and mobile-safe
    
5.  Prioritize reliability over abstractions
    

If uncertain, ask:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   What is the canonical source of truth?  Does this run offline?  Does this maintain bootstrap invariants?   `

If any answer is "no," stop and propose an alternative.