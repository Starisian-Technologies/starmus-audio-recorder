# Cleanup & Code Quality Tools

Complete suite for maintaining code quality across PHP, JavaScript, CSS, and documentation.

## Quick Start

### One-Command Cleanup

```bash
# Fix everything
./bin/cleanup.sh

# Preview changes (dry-run)
./bin/cleanup.sh --dry-run
```

### Generate Documentation

```bash
# Generate all API docs (PHP + JS)
npm run docs

# PHP only
composer docs

# JS only
npm run docs:js
```

## Individual Tools

### PHP

#### Rector (Structural Refactoring)

```bash
# Auto-upgrade PHP syntax to 8.2+, improve code quality
composer rector

# Preview changes
composer rector:dry
```

#### PHP CS Fixer (Advanced Formatting)

```bash
# Fix code style (PSR-12, alignment, imports, etc.)
composer phpfix

# Preview changes
composer phpfix:dry
```

#### PHPCBF (WordPress Standards)

```bash
# Fix WordPress coding standards violations
composer phpcbf
```

#### PHPStan (Static Analysis)

```bash
# Analyze code for type errors
composer phpstan
```

### JavaScript

```bash
# Fix all JS issues
npm run lint:js:fix

# Check only
npm run lint:js
```

### CSS

```bash
# Fix all CSS issues
npm run lint:css:fix

# Check only
npm run lint:css
```

### Markdown

```bash
# Fix documentation
npm run lint:md:fix

# Check only
npm run lint:md
```

### All Files (Prettier)

```bash
# Format JS, CSS, JSON, HTML
npm run format
```

## Workflow

### Before Commit

```bash
# Fix all issues
npm run fix:all      # JS/CSS/Markdown
composer fix:all     # PHP (all 3 tools)
```

### Full Validation

```bash
# Check everything
npm run lint
composer test
```

## What Each Tool Does

| Tool             | Purpose                                                | Examples                                              |
| ---------------- | ------------------------------------------------------ | ----------------------------------------------------- |
| **Rector**       | Upgrades syntax, removes dead code, improves structure | `array()` → `[]`, add type hints, use null coalescing |
| **PHP CS Fixer** | Code style, alignment, ordering                        | Align `=` operators, order imports, format phpdoc     |
| **PHPCBF**       | WordPress standards                                    | Nonce checks, sanitization, escaping                  |
| **PHPStan**      | Type safety                                            | Find bugs before runtime                              |
| **ESLint**       | JS linting                                             | Unused vars, undefined functions                      |
| **Prettier**     | Consistent formatting                                  | Quotes, spacing, line length                          |
| **Stylelint**    | CSS standards                                          | Property order, vendor prefixes                       |
| **Markdownlint** | Doc quality                                            | Heading hierarchy, list formatting                    |

## Configuration Files

- `rector.php` - Rector rules
- `.php-cs-fixer.dist.php` - PHP CS Fixer config
- `phpcs.xml.dist` - PHPCS/PHPCBF rules
- `phpstan.neon.dist` - PHPStan analysis
- `eslint.config.js` - ESLint rules
- `.prettierrc` - Prettier formatting
- `stylelint.config.js` - CSS linting
- `markdownlint.json` - Markdown rules

## CI/CD Integration

Add to GitHub Actions:

```yaml
- name: PHP Quality
  run: |
    composer rector:dry
    composer phpfix:dry
    composer phpstan

- name: JS/CSS Quality
  run: |
    npm run lint
```

## Troubleshooting

**Rector fails:**

```bash
# Clear cache
vendor/bin/rector clear-cache
composer rector
```

**PHP CS Fixer conflicts with PHPCS:**

- Run Rector first (structural)
- Then PHP CS Fixer (formatting)
- Then PHPCBF (WordPress standards)
- Order matters!

**Process timeout errors:**

The cleanup scripts now have extended timeouts (20 minutes) for large codebases:

```bash
# These commands have built-in extended timeouts
composer fix:all
composer phpfix
composer phpcbf
```

**Memory limit:**

```bash
# Increase for Rector/PHPStan
php -d memory_limit=2G vendor/bin/rector
```

**Configuration conflicts:**

If you see "Rule contains conflicting fixers", the `.php-cs-fixer.dist.php` config has been fixed to use compatible rules.
