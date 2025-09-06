# Testing Strategy

This project uses a divided testing approach to maximize coverage while minimizing redundancy.

## Test Division

### NPM Tests (Frontend/Integration/E2E)
**Purpose:** Test user-facing functionality, browser behavior, and WordPress integration

**Commands:**
```bash
npm test                    # Run all frontend tests
npm run test:e2e           # End-to-end tests with Playwright
npm run test:a11y          # Accessibility tests (WCAG compliance)
npm run test:integration   # WordPress plugin activation/integration
npm run test:wp-env        # Alias for integration tests
```

**What's tested:**
- Audio recording functionality in browsers
- User interface interactions
- Accessibility compliance
- WordPress plugin activation
- REST API endpoints (from frontend perspective)
- Offline queue functionality

### Composer Tests (Backend/Unit/Quality)
**Purpose:** Test PHP code quality, logic, and WordPress backend functionality

**Commands:**
```bash
composer test              # Run all PHP tests and quality checks
composer run test:unit     # PHP unit tests only
composer run lint:php      # Code style checks
composer run analyze:php   # Static analysis with PHPStan
composer run fix:php       # Auto-fix code style issues
```

**What's tested:**
- PHP class instantiation and methods
- WordPress hooks and filters
- Custom post type registration
- Plugin activation/deactivation
- Code quality and standards compliance
- Static analysis for potential bugs

## Test Environment Setup

### For NPM Tests
```bash
npm run env:start          # Start WordPress test environment
npm run env:stop           # Stop WordPress test environment
```

### For Composer Tests
```bash
composer install           # Install PHP dependencies
```

## File Structure

```
tests/
├── e2e/                   # Playwright E2E tests (NPM)
├── integration/           # WordPress integration tests (Composer)
├── unit/                  # PHP unit tests (Composer)
├── bootstrap.php          # WordPress test bootstrap
└── index.php             # Security file
```

## Configuration Files

- `phpunit.xml.dist` - Main PHPUnit configuration
- `phpunit-unit.xml.dist` - Unit tests only
- `phpunit-integration.xml.dist` - Integration tests only
- `playwright.config.js` - Playwright E2E configuration

## CI/CD Integration

**GitHub Actions should run:**
1. `npm test` - Frontend/E2E tests
2. `composer test` - Backend/Quality tests
3. Both environments provide complementary coverage

## Best Practices

1. **Unit Tests (Composer):** Test individual PHP classes and methods
2. **Integration Tests (Composer):** Test WordPress-specific functionality
3. **E2E Tests (NPM):** Test complete user workflows
4. **Accessibility Tests (NPM):** Ensure WCAG compliance

This division ensures comprehensive testing while avoiding redundant test execution across both environments.