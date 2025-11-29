Testing Strategy
================

This project uses a dual-toolchain testing model. JavaScript-based tests validate user experience and browser integration, while PHP-based tests enforce backend correctness, security, and architectural quality. Each layer has a different purpose, and no test suite duplicates the responsibilities of another.

Test Division
-------------

### **NPM Test Suite**

**Scope:** Frontend, browser integration, user experience, and client-side workflows

**Commands**

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   npm test                   # Full frontend test suite  npm run test:e2e           # Playwright E2E browser tests  npm run test:a11y          # WCAG accessibility audit  npm run test:integration   # WordPress integration via WP-Env  npm run test:wp-env        # Alias for integration tests   `

**Validated Behaviors**

*   MediaRecorder initialization and audio UX
    
*   Recorder controls, speech features, and calibration flow
    
*   Tier-based degradation paths
    
*   Offline queue persistence and retry logic
    
*   WordPress frontend integration and REST responses
    
*   Accessibility compliance (WCAG)
    

Use this suite to confirm the software works in real browsers, under real constraints, with real users.

### **Composer Test Suite**

**Scope:** Backend logic, business rules, WordPress hooks, and code correctness

**Commands**

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   composer test              # Full backend test suite  composer run test:unit     # Unit tests only  composer run lint:php      # Code style (PHPCS)  composer run analyze:php   # Static analysis (PHPStan)  composer run fix:php       # Auto-fixes where possible   `

**Validated Behaviors**

*   Constructor logic and service dependencies
    
*   CPT and taxonomy registration
    
*   Activation/deactivation routines
    
*   REST endpoints (server-side)
    
*   Security and input validation
    
*   Standards compliance and future-proofing
    

This suite prevents regressions that can brick a WordPress network.

Test Environment Bootstrapping
------------------------------

### NPM Side (browser + WP sandbox)

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   npm run env:start  npm run env:stop   `

### Composer Side (PHP toolchain)

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   composer install   `

Both environments operate independently. Breaking one must not break the other.

Test Directory Layout
---------------------

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   tests/  ├── e2e/                   # Full user journeys (Playwright)  ├── integration/           # WP-Env integration tests (Composer)  ├── unit/                  # Pure PHP logic tests  ├── bootstrap.php          # Test environment bootstrap  └── index.php              # Filesystem guard   `

Configuration Files
-------------------

*   phpunit.xml.dist – Full Composer stack
    
*   phpunit-unit.xml.dist – Pure backend logic
    
*   phpunit-integration.xml.dist – WordPress bootstrap tests
    
*   playwright.config.js – Browser/E2E behavior
    

Each config has one responsibility, no overlap.

CI/CD Requirements
------------------

GitHub Actions must run:

1.  npm test — validates UI, browser workflows, accessibility, and WP-Env integration
    
2.  composer test — validates all PHP logic and architectural boundaries
    

A build is **invalid** unless both pass.

Testing Doctrine
----------------

LayerTechnologyPurposeUnitComposerValidate individual PHP methodsIntegrationComposerValidate WordPress lifecycle and plugin glueE2ENPM/PlaywrightValidate real workflows from user action to server responseAccessibilityNPMValidate ethical and legal UX compliance

If a test does not reveal new information, it does not belong in this suite.