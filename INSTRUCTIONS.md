Operating Guidelines for AI Codex
=================================

_(Starisian Engineering Agent — 2025 Edition)_

1) Intake Checklist
-------------------

*   Identify **plugin role in the ecosystem**:
    
    *   **Starmus** = capture + drafts
        
    *   **AiWA** = approved corpus + linguistics
        
*   Confirm identifiers: PascalCase name, slug, text domain, REST namespace.
    
*   Capture CPTs/taxonomies, storage model (options/table), and capability requirements.
    
*   Determine **bootstrap contract** fields and pageType.
    
*   Specify offline/queue interactions, GDPR/CCPA sensitivity, retention, delete/opt-out.
    

2) Scaffolding
--------------

Generate:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   plugin/  ├─ plugin.php  ├─ src//{Core,Admin,Frontend,Rest,Services,Support}/...  ├─ assets/{js/{modern,legacy},css,img}  ├─ templates/  ├─ languages/  ├─ tests/{unit,integration,e2e}  ├─ config/{phpcs.xml,phpstan.neon,eslint,stylelint}  └─ README.md, CHANGELOG.md, CONTRIBUTING.md   `

*   Enforce bootstrap guards (PHP ≥ 8.2, WP ≥ 6.4).
    
*   PSR-4 autoload with Composer fallback.
    
*   load\_plugin\_textdomain on init.
    

3) Naming Conventions (Non-Negotiable)
--------------------------------------

*   Namespace: Starisian\\\\\\\\…
    
*   Classes: PascalCase, one per file; methods camelCase.
    
*   Constants SCREAMING\_SNAKE\_CASE.
    
*   Handles/routes/options/meta: star-\-\*
    
*   REST namespace: star-/v1
    

4) Error Handling
-----------------

*   Internals throw exceptions; **convert to WP\_Error** at all boundaries.
    
*   Network calls use capped exponential backoff + jitter, ≤ 10s timeout.
    
*   JS responses always { ok, code, message, data }.
    
*   Offline does not equal error — it equals **enqueue**.
    

5) Offline-First Mandate
------------------------

*   IndexedDB preferred; localStorage as fallback.
    
*   Queue entries keyed by UUID: pending|uploading|complete|failed.
    
*   Chunked resumable uploads + idempotency tokens.
    
*   Export-to-file fallback for constrained schools/devices.
    

6) Accessibility & i18n
-----------------------

*   WCAG 2.1 AA conformance, live regions for status updates.
    
*   No color-only meaning; maintain keyboard tab order.
    
*   All user text via \_\_() and translator comments.
    

7) Security
-----------

*   Capabilities + nonces; sanitize input, escape output.
    
*   Strict MIME enforcement and upload whitelists.
    
*   Rate-limit sensitive REST endpoints.
    
*   Never expose file paths, stack traces, or PII without consent.
    

8) REST API
-----------

*   Register under star-/v1.
    
*   Permission callbacks explicit and minimal.
    
*   Wrap ACF/SCF CRUD — **never rename vendor routes**.
    
*   Provide OpenAPI-style docs for every route.
    

9) Builds, Budgets & CI Gates _(Updated)_
-----------------------------------------

*   **Dual bundles**: modern (ESM) + legacy (ES5).
    
*   **Budgets (CI enforced)**:
    
    *   JS ≤ **60KB gz**
        
    *   CSS ≤ **25KB gz**
        
*   System fonts only; no heavy front-end frameworks.
    
*   PHPStan ≥ level 6, PHPCS WordPress+PSR12, ESLint, Stylelint.
    

10) Testing
-----------

*   **Unit** (PHP), **integration** (REST + permissions), **E2E** for JS-off baseline.
    
*   Offline queue tests at 2G/3G throttling.
    
*   Idempotency + replay-attack tests.
    

11) Documentation
-----------------

*   README explains purpose, constraints, bootstrap contract, offline model.
    
*   CHANGELOG = Keep-a-Changelog format.
    
*   CONTRIBUTING describes CI, branches, PR rules, and naming.
    

12) Release & Maintenance
-------------------------

*   SemVer versioning; DB version stored in options.
    
*   Migrations idempotent; safe uninstall path.
    
*   Deprecations provide deadlines and shim warnings.
    

13) Bootstrap Contract _(New Requirement)_
------------------------------------------

Every plugin page must expose one bootstrap object _before_ scripts load:

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   window._BOOTSTRAP = {    pageType: 'recorder'|'rerecorder'|'editor',    postId: number|null,    restUrl: string,    canCommit: boolean,    transcript: array|null,    audioUrl: string|null  };   `

### Rule

**If no bootstrap object exists, no code runs.**Modules must not inspect global state outside this contract.

14) The Bicameral Architecture _(New Requirement)_
--------------------------------------------------

Starisian plugins separate:

PartRole**UI**Empathy, validation, user flow**Kernel**Actions, uploads, REST, drafts, approvals

UI never uploads; Kernel never touches DOM.

15) Response Templates (Unchanged)
----------------------------------

Use the existing code blocks for REST route, bootstrap guard, and JS error envelopes without modification.

16) AiWA Interaction Rule
-------------------------

Starmus produces **draft artifacts**.AiWA **accepts approved transcripts** and integrates them into the corpus.No plugin may bypass this gate.

17) Immutable Decision
----------------------

Shared globals are forbidden except for the **bootstrap contract**.Everything else passes through modular interfaces.