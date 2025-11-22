# Documentation System

Unified documentation generation for PHP classes and JavaScript modules with automatic HTML hub.

## Quick Start

### Generate All Documentation (Recommended)

```bash
npm run docs          # Generates PHP + JS docs + unified HTML hub
```

This creates:

- PHP Markdown docs (`docs/php-md/`)
- PHP HTML docs (`docs/php/`) - if phpDocumentor available
- JavaScript docs (`docs/js/`)
- Unified hub page (`docs/index.html`)
- API index (`docs/API-INDEX.md`)

### Individual Generators

```bash
# PHP classes → /docs/php-md/*.md
npm run docs:php
composer docs

# JavaScript modules → /docs/js/*.md
npm run docs:js

# Everything with unified hub
npm run docs:unified
bash bin/generate-unified-docs.sh
```

## Output Structure

```
docs/
├── index.html            # Unified documentation hub (START HERE)
├── API-INDEX.md          # Complete API listing
├── php-md/               # PHP class documentation (Markdown)
│   ├── StarmusAudioRecorder.md
│   ├── StarmusAssetLoader.md
│   └── ...
├── php/                  # PHP HTML docs (phpDocumentor, if available)
│   └── index.html
└── js/                   # JavaScript module documentation
    ├── starmus-integrator.md
    ├── starmus-core.md
    └── ...
```

**View Documentation:** Open `docs/index.html` in your browser for the main hub.

## Writing Documentation

### PHP Classes

Use standard PHPDoc blocks:

```php
/**
 * Manages audio recording submissions.
 *
 * This service handles the complete submission lifecycle including
 * validation, file processing, and metadata storage.
 *
 * @package Starisian\Sparxstar\Starmus\services
 */
class StarmusSubmissionService
{
    /**
     * Process and validate a new submission.
     *
     * @param int $post_id The post ID to process.
     * @param array $metadata Submission metadata.
     * @return bool True on success, false on failure.
     */
    public function process_submission(int $post_id, array $metadata): bool
    {
        // ...
    }
}
```

### JavaScript Modules

Use JSDoc format:

```javascript
/**
 * Initialize the audio recorder for a form instance.
 *
 * @function initRecorder
 * @param {object} store - Redux-style state store
 * @param {string} instanceId - Unique form instance identifier
 * @returns {object} API for controlling the recorder
 *
 * @example
 * const recorder = initRecorder(store, 'starmus_123');
 * recorder.start();
 */
## Generator Scripts

### `/bin/generate-docs.php`
- Scans `src/` directory recursively
- Extracts class documentation, methods, and properties
- Outputs Markdown to `docs/php-md/`

### `/bin/generate-js-docs.js`
- Scans `src/js/` directory
- Uses jsdoc-to-markdown for parsing
- Outputs Markdown to `docs/js/`

### `/bin/generate-unified-docs.sh` (Recommended)
- Runs both PHP and JS generators
- Generates phpDocumentor HTML if available
- Creates unified HTML hub (`docs/index.html`)
- Creates API index
- Provides comprehensive summary

## Automated Documentation (CI/CD)

Documentation is automatically regenerated on every push to `main` via GitHub Actions.

**Workflow:** `.github/workflows/docs.yml`

The workflow:
1. Installs PHP and Node.js dependencies
2. Runs unified documentation generator
3. Commits generated docs back to the repository

Manual trigger: Go to Actions → "Generate Documentation" → "Run workflow"cs/js/`

### `/bin/generate-all-docs.sh`
- Runs both generators
- Creates unified API index
- Provides summary output

## CI/CD Integration

Add to GitHub Actions workflow:

```yaml
- name: Generate Documentation
  run: |
    npm install
    composer install --no-dev
    npm run docs

- name: Commit Documentation
  run: |
    git config user.name "GitHub Actions"
    git config user.email "actions@github.com"
    git add docs/
    git diff --staged --quiet || git commit -m "docs: auto-generate API documentation"
    git push
```

## Configuration

### JSDoc Config (`jsdoc.json`)

- Controls JS documentation output format
- Excludes node_modules and vendor
- Enables markdown plugin

### PHP Generator

- Excludes private methods by default
- Includes namespace and file location
- Supports @param, @return, @throws tags

## Best Practices

1. **Document public APIs first** - Focus on methods other developers will use
2. **Include examples** - Use @example tags in JSDoc
3. **Keep it current** - Run `npm run docs` before each release
4. **Link between files** - Use relative Markdown links in docs
5. **Automate in CI** - Generate docs on every merge to main

## Troubleshooting

**Missing docs for a class:**

- Check that the file has a namespace declaration
- Ensure PHPDoc block starts with `/**`

**Empty JS documentation:**

- Add JSDoc comments above functions
- Use @param and @returns tags

**Permission errors:**

```bash
chmod +x bin/*.php bin/*.sh bin/*.js
```

---

_Part of Starmus Audio Recorder documentation system_
