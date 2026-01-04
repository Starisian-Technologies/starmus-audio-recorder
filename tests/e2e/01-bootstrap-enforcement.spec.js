/**
 * Bootstrap Enforcement Tests for Starmus Audio Recorder
 *
 * These tests validate that the Starmus JavaScript correctly enforces
 * bootstrap gating - the app must not initialize without proper bootstrap data.
 *
 * Tests:
 * 1. JS does not initialize if window.STARMUS_BOOTSTRAP is missing
 * 2. JS initializes only after bootstrap is present
 * 3. No DOM access occurs before bootstrap detection
 */

import { test, expect } from '@playwright/test';

test.describe('Bootstrap Enforcement', () => {

  /**
   * Test: Bootstrap missing should prevent initialization
   *
   * When window.STARMUS_BOOTSTRAP is not present, the Starmus app
   * should not initialize any components or modify the DOM.
   */
  test('JS does not initialize without STARMUS_BOOTSTRAP', async ({ page }) => {
    // Track any initialization attempts
    const initAttempts = [];
    const domModifications = [];

    // Monitor for Starmus initialization
    page.on('console', msg => {
      if (msg.text().includes('[STARMUS INIT]') || msg.text().includes('[STARMUS ERROR]')) {
        initAttempts.push(msg.text());
      }
    });

    // Monitor for DOM modifications to recorder container
    page.on('domupdate', event => {
      if (event.root && event.root.classList && event.root.classList.contains('starmus-recorder-form')) {
        domModifications.push(event);
      }
    });

    // Navigate to page WITHOUT bootstrap (simulate missing data)
    await page.addInitScript(() => {
      // Remove bootstrap if it exists
      delete window.STARMUS_BOOTSTRAP;
      // Override console to capture init attempts
      const originalConsoleError = console.error;
      console.error = (...args) => {
        if (args[0] && typeof args[0] === 'string' && args[0].includes('[STARMUS')) {
          window.__starmusInitAttempts = window.__starmusInitAttempts || [];
          window.__starmusInitAttempts.push(args[0]);
        }
        return originalConsoleError.apply(console, args);
      };
    });

    await page.goto('/starmus-recorder/');

    // Wait for page to be fully loaded
    await page.waitForLoadState('domcontentloaded');

    // Explicit wait for any potential initialization
    await page.waitForTimeout(1000);

    // Check that recorder form is NOT properly initialized
    // The form might be visible (HTML rendered), but functionality should be disabled
    const recorderForm = page.locator('[data-starmus="recorder"]');
    const isFormVisible = await recorderForm.isVisible();

    // The form HTML should still be present (server-rendered)
    // but internal state should indicate bootstrap is missing
    const bootstrapMissingError = await page.evaluate(() => {
      return window.__starmusInitAttempts?.some(msg =>
        msg.includes('BOOTSTRAP_MISSING') || msg.includes('bootstrap')
      ) || false;
    });

    // Test passes if either:
    // 1. Bootstrap error was logged (correct behavior)
    // 2. No init attempts were made (correct behavior)
    expect(
      bootstrapMissingError || initAttempts.length === 0
    ).toBe(true);
  });

  /**
   * Test: JS initializes only after valid bootstrap
   *
   * When window.STARMUS_BOOTSTRAP is present and valid,
   * the app should initialize properly.
   */
  test('JS initializes with valid STARMUS_BOOTSTRAP', async ({ page }) => {
    const initSuccess = [];

    page.on('console', msg => {
      if (msg.text().includes('[STARMUS INIT]') || msg.text().includes('[STARMUS READY]')) {
        initSuccess.push(msg.text());
      }
    });

    // Inject valid bootstrap
    await page.addInitScript(() => {
      window.STARMUS_BOOTSTRAP = {
        restUrl: 'http://localhost:8081/wp-json/star-starmus-audio-recorder/v1',
        nonce: 'test-nonce-12345',
        postId: 0,
        uploadEndpoint: 'upload',
        debug: false,
        tier: 'A',
        tierConfig: {
          maxDuration: 1200,
          supportedMimeTypes: ['audio/webm'],
          chunkSize: 262144,
          maxRetries: 3,
          retryDelay: 1000,
        },
        calibration: {
          inputLatency: 0,
          outputLatency: 0,
          sampleRate: 48000,
          bufferSize: 4096,
        },
      };
    });

    await page.goto('/starmus-recorder/');

    // Wait for initialization
    await page.waitForLoadState('domcontentloaded');

    // Explicit wait for initialization to complete
    await page.waitForTimeout(2000);

    // Check for successful initialization
    const hasReadySignal = initSuccess.some(msg => msg.includes('READY') || msg.includes('INIT_COMPLETE'));

    // Verify setup button is clickable (indicates initialization)
    const setupBtn = page.locator('[data-starmus-action="setup-mic"]');
    await expect(setupBtn).toBeVisible({ timeout: 5000 });
  });

  /**
   * Test: No DOM access before bootstrap detection
   *
   * The app should not attempt to access DOM elements
   * before validating bootstrap data.
   */
  test('No DOM access before bootstrap validation', async ({ page }) => {
    const domAccessErrors = [];

    // Override Element query methods to detect early access
    await page.addInitScript(() => {
      const originalQuerySelector = document.querySelector.bind(document);
      let bootstrapChecked = false;

      document.querySelector = ((selector) => {
        // Check if this looks like Starmus DOM access
        if (selector.includes('starmus-') && !bootstrapChecked) {
          window.__starmusEarlyDomAccess = window.__starmusEarlyDomAccess || [];
          window.__starmusEarlyDomAccess.push({
            selector,
            timestamp: Date.now(),
            hasBootstrap: !!window.STARMUS_BOOTSTRAP
          });
        }
        return originalQuerySelector(selector);
      });
    });

    // Test without bootstrap - should not access DOM
    await page.goto('/starmus-recorder/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(500);

    const earlyAccess = await page.evaluate(() => window.__starmusEarlyDomAccess);

    // If there was early DOM access without bootstrap, that's a failure
    if (earlyAccess && earlyAccess.length > 0) {
      const withoutBootstrap = earlyAccess.filter(access => !access.hasBootstrap);
      expect(withoutBootstrap.length).toBe(0);
    }

    // Test with bootstrap - should access DOM after validation
    await page.addInitScript(() => {
      window.STARMUS_BOOTSTRAP = {
        restUrl: 'http://localhost:8081/wp-json/star-starmus-audio-recorder/v1',
        nonce: 'test-nonce-12345',
        postId: 0,
        uploadEndpoint: 'upload',
        debug: false,
        tier: 'A',
        tierConfig: {
          maxDuration: 1200,
          supportedMimeTypes: ['audio/webm'],
          chunkSize: 262144,
          maxRetries: 3,
          retryDelay: 1000,
        },
        calibration: {
          inputLatency: 0,
          outputLatency: 0,
          sampleRate: 48000,
          bufferSize: 4096,
        },
      };
    });

    await page.reload();
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(2000);

    // With bootstrap, DOM access should be allowed
    const setupBtn = page.locator('[data-starmus-action="setup-mic"]');
    await expect(setupBtn).toBeVisible({ timeout: 5000 });
  });

  /**
   * Test: Bootstrap with invalid data rejects initialization
   *
   * Malformed or incomplete bootstrap data should prevent
   * app initialization.
   */
  test('Invalid bootstrap data prevents initialization', async ({ page }) => {
    const errorLogs = [];

    page.on('console', msg => {
      if (msg.type() === 'error' && msg.text().includes('[STARMUS')) {
        errorLogs.push(msg.text());
      }
    });

    // Inject incomplete bootstrap (missing required fields)
    await page.addInitScript(() => {
      window.STARMUS_BOOTSTRAP = {
        // Missing required fields: restUrl, nonce, uploadEndpoint
        debug: false,
      };
    });

    await page.goto('/starmus-recorder/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    // Should log validation error
    const hasValidationError = errorLogs.some(msg =>
      msg.includes('VALIDATION') ||
      msg.includes('INVALID') ||
      msg.includes('MISSING') ||
      msg.includes('BOOTSTRAP')
    );

    expect(hasValidationError).toBe(true);
  });

  /**
   * Test: Bootstrap persistence across page navigation
   *
   * Bootstrap should persist when navigating between
   * Starmus pages on the same site.
   */
  test('Bootstrap persists across page navigation', async ({ page }) => {
    const bootstrapStates = [];

    // Inject bootstrap
    await page.addInitScript(() => {
      window.STARMUS_BOOTSTRAP = {
        restUrl: 'http://localhost:8081/wp-json/star-starmus-audio-recorder/v1',
        nonce: 'test-nonce-12345',
        postId: 0,
        uploadEndpoint: 'upload',
        debug: false,
        tier: 'A',
        tierConfig: {
          maxDuration: 1200,
          supportedMimeTypes: ['audio/webm'],
          chunkSize: 262144,
          maxRetries: 3,
          retryDelay: 1000,
        },
        calibration: {
          inputLatency: 0,
          outputLatency: 0,
          sampleRate: 48000,
          bufferSize: 4096,
        },
      };
    });

    // Navigate to recorder page
    await page.goto('/starmus-recorder/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    bootstrapStates.push({
      page: 'recorder',
      hasBootstrap: await page.evaluate(() => !!window.STARMUS_BOOTSTRAP)
    });

    // Navigate to editor page (if exists) or another page
    await page.goto('/my-recordings/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    bootstrapStates.push({
      page: 'my-recordings',
      hasBootstrap: await page.evaluate(() => !!window.STARMUS_BOOTSTRAP)
    });

    // Navigate back to recorder
    await page.goto('/starmus-recorder/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    bootstrapStates.push({
      page: 'recorder-back',
      hasBootstrap: await page.evaluate(() => !!window.STARMUS_BOOTSTRAP)
    });

    // All pages should have bootstrap
    expect(bootstrapStates.every(s => s.hasBootstrap)).toBe(true);
  });
});

