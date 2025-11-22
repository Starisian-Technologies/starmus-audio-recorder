# Starmus Development Tools - Complete Setup

## 🎯 Overview

This repository includes a complete development toolchain for code quality, testing, and documentation.

## 📦 Installed Tools

### Code Quality (PHP)
- ✅ **Rector** - Structural refactoring and syntax upgrades
- ✅ **PHP CS Fixer** - Advanced code formatting
- ✅ **PHPCS/PHPCBF** - WordPress coding standards
- ✅ **PHPStan** - Static analysis

### Code Quality (JavaScript/CSS)
- ✅ **ESLint** - JavaScript linting
- ✅ **Prettier** - Code formatting
- ✅ **Stylelint** - CSS linting
- ✅ **Markdownlint** - Documentation linting

### Documentation
- ✅ **PHP Documentation Generator** - Auto-generates class docs
- ✅ **JSDoc + jsdoc-to-markdown** - JavaScript API docs

### Testing
- ✅ **PHPUnit** - PHP unit tests
- ✅ **Playwright** - E2E and accessibility tests

## 🚀 Quick Commands

### Fix All Code Issues
```bash
./cleanup.sh                    # Fix everything (PHP + JS + CSS)
```

### Generate Documentation
```bash
npm run docs                    # Generate all documentation
```

### Run Tests
```bash
npm test                        # E2E + accessibility tests
composer test                   # PHP unit tests + static analysis
```

### Build Production Assets
```bash
npm run build                   # Build CSS + JS bundles
```

## 📝 Common Workflows

### Before Committing
```bash
# 1. Fix all code issues
./cleanup.sh

# 2. Run tests
npm test
composer test

# 3. Generate docs
npm run docs

# 4. Build assets
npm run build
```

### Code Review Prep
```bash
# Check what would change (dry-run)
./cleanup.sh --dry-run
composer rector:dry
composer phpfix:dry
```

### Adding New Features
```bash
# 1. Write code with proper docblocks
# 2. Run cleanup
./cleanup.sh

# 3. Generate docs
npm run docs

# 4. Run tests
npm test
composer test
```

## 📚 Documentation

- **[CLEANUP-TOOLS.md](./CLEANUP-TOOLS.md)** - Code quality tools guide
- **[DOCUMENTATION.md](./DOCUMENTATION.md)** - Documentation system guide
- **[TESTING.md](./TESTING.md)** - Testing framework guide
- **[ARCHITECTURE.md](./ARCHITECTURE.md)** - System architecture

## 🔧 Configuration Files

### Code Quality
- `rector.php` - Rector configuration
- `.php-cs-fixer.dist.php` - PHP CS Fixer rules
- `phpcs.xml.dist` - PHPCS standards
- `phpstan.neon.dist` - PHPStan settings
- `eslint.config.js` - ESLint rules
- `.prettierrc` - Prettier formatting
- `stylelint.config.js` - Stylelint rules

### Documentation
- `jsdoc.json` - JSDoc configuration
- `bin/generate-docs.php` - PHP doc generator
- `bin/generate-js-docs.js` - JS doc generator
- `bin/generate-all-docs.sh` - Unified generator

### Build
- `roll-up.config.mjs` - Rollup bundler
- `postcss.config.cjs` - PostCSS processor
- `package.json` - npm scripts
- `composer.json` - Composer scripts

## 🎨 Code Style Standards

### PHP
- **PSR-12** base standard
- **WordPress Coding Standards** for hooks/filters
- **PHP 8.2+** syntax (strict types, typed properties)
- **Tabs** for indentation (WordPress convention)

### JavaScript
- **ES2022** modules
- **2 spaces** indentation
- **Single quotes** for strings
- **100 char** line length

### CSS
- **Standard config** (stylelint-config-standard)
- **2 spaces** indentation
- **Logical property ordering**

## 🔄 CI/CD Integration

These tools are designed to run in CI pipelines:

```yaml
# GitHub Actions example
- name: Install Dependencies
  run: |
    composer install
    npm install

- name: Code Quality Checks
  run: |
    ./cleanup.sh --dry-run
    composer phpstan
    npm run lint

- name: Generate Docs
  run: npm run docs

- name: Run Tests
  run: |
    composer test
    npm test
```

## 💡 Tips

1. **Run cleanup before committing** - Catches issues early
2. **Use dry-run mode** - Preview changes before applying
3. **Keep docs updated** - Run `npm run docs` regularly
4. **Check build size** - Use `npm run size-check`
5. **Validate before pushing** - Run `npm run lint && composer test`

## 🆘 Troubleshooting

### Rector Memory Issues
```bash
php -d memory_limit=2G vendor/bin/rector
```

### Permission Errors
```bash
chmod +x bin/*.php bin/*.sh bin/*.js cleanup.sh
```

### Build Failures
```bash
npm run clean
npm run build
```

### Doc Generation Errors
```bash
# Ensure scripts are executable
chmod +x bin/*

# Install JS doc dependencies
npm install jsdoc jsdoc-to-markdown --save-dev
```

## 📞 Support

See project documentation:
- [GitHub Issues](https://github.com/Starisian-Technologies/starmus-audio-recorder/issues)
- [SUPPORT.md](./SUPPORT.md)

---

**Starisian Technologies** | [starisian.com](https://starisian.com)
