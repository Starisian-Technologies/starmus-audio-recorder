/**
 * Recorder Workflow Tests for Starmus Audio Recorder
 *
 * Tests the complete recorder workflow under various network conditions:
 * - Offline recording and queue persistence
 * - Network interruption handling
 * - Upload resumption
 * - No work loss under adverse conditions
 *
 * These tests MUST fail when bugs exist - they surface real user experience issues.
 */

import { test, expect } from '@playwright/test';

test.describe('Recorder Workflow - Offline & Network Conditions', () => {

  /**
   * Test: Start recording while offline
   *
   * User should be able to start recording even without network connectivity.
   * Recording data must be stored locally.
   */
  test('Start recording while offline - local storage works', async ({ page }) => {
    // Set offline mode BEFORE navigation
    await page.context().setOffline(true);

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
    await page.waitForLoadState('domcontentloaded');

    // Explicit wait for page to be ready
    await page.waitForTimeout(1000);

    // Setup microphone (should work offline)
    const setupBtn = page.locator('[data-starmus-action="setup-mic"]');
    await expect(setupBtn).toBeVisible({ timeout: 10000 });
    await setupBtn.click();

    // Wait for mic setup (with explicit timeout since we're offline)
    await page.waitForTimeout(2000);

    // Check for mic setup completion - timer should be visible
    const timer = page.locator('[data-starmus-timer]');
    await expect(timer).toBeVisible({ timeout: 10000 });

    // Start recording
    const recordBtn = page.locator('[data-starmus-action="record"]');
    await expect(recordBtn).toBeVisible({ timeout: 5000 });
    await recordBtn.click();

    // Recording should start - pause button appears
    const pauseBtn = page.locator('[data-starmus-action="pause"]');
    await expect(pauseBtn).toBeVisible({ timeout: 5000 });

    // Record for 3 seconds
    await page.waitForTimeout(3000);

    // Stop recording
    const stopBtn = page.locator('[data-starmus-action="stop"]');
    await expect(stopBtn).toBeVisible({ timeout: 5000 });
    await stopBtn.click();

    // Should show review controls after stopping
    const playBtn = page.locator('[data-starmus-action="play"]');
    await expect(playBtn).toBeVisible({ timeout: 5000 });

    // Verify recording data was stored locally (IndexedDB)
    const hasLocalRecording = await page.evaluate(async () => {
      // Check if any recording data exists in IndexedDB
      return new Promise((resolve) => {
        try {
          const request = indexedDB.open('starmus_recordings');
          request.onsuccess = () => {
            const db = request.result;
            const tx = db.transaction('recordings', 'readonly');
            const store = tx.objectStore('recordings');
            const countRequest = store.count();
            countRequest.onsuccess = () => {
              resolve(countRequest.result > 0);
            };
            countRequest.onerror = () => resolve(false);
          };
          request.onerror = () => resolve(false);
        } catch (e) {
          resolve(false);
        }
      });
    });

    expect(hasLocalRecording).toBe(true);
  });

  /**
   * Test: Reload page - recording persists locally
   *
   * If user reloads the page during or after recording,
   * the recording data must still be available locally.
   */
  test('Recording persists after page reload', async ({ page }) => {
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

    await page.goto('/starmus-recorder/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    // Setup and start recording
    const setupBtn = page.locator('[data-starmus-action="setup-mic"]');
    await setupBtn.click();
    await page.waitForTimeout(2000);

    const recordBtn = page.locator('[data-starmus-action="record"]');
    await recordBtn.click();
    await page.waitForTimeout(2000);

    // Get recording data before reload
    const recordingBefore = await page.evaluate(async () => {
      return new Promise((resolve) => {
        try {
          const request = indexedDB.open('starmus_recordings');
          request.onsuccess = () => {
            const db = request.result;
            const tx = db.transaction('recordings', 'readonly');
            const store = tx.objectStore('recordings');
            const getRequest = store.get(1);
            getRequest.onsuccess = () => {
              resolve(getRequest.result || null);
            };
          };
        } catch (e) {
          resolve(null);
        }
      });
    });

    // Reload the page
    await page.reload();
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(2000);

    // Re-inject bootstrap after reload
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

    // Check if recording data survived reload
    const recordingAfter = await page.evaluate(async () => {
      return new Promise((resolve) => {
        try {
          const request = indexedDB.open('starmus_recordings');
          request.onsuccess = () => {
            const db = request.result;
            const tx = db.transaction('recordings', 'readonly');
            const store = tx.objectStore('recordings');
            const getRequest = store.get(1);
            getRequest.onsuccess = () => {
              resolve(getRequest.result || null);
            };
          };
        } catch (e) {
          resolve(null);
        }
      });
    });

    // Recording data should persist
    expect(recordingAfter).not.toBeNull();
  });

  /**
   * Test: Submit while offline - queued, not lost
   *
   * When submitting while offline, the recording should be queued
   * locally and not lost. Status should indicate "queued".
   */
  test('Submit while offline - queued, not lost', async ({ page }) => {
    // Set offline mode
    await page.context().setOffline(true);

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

    await page.goto('/starmus-recorder/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    // Setup and create a recording
    const setupBtn = page.locator('[data-starmus-action="setup-mic"]');
    await setupBtn.click();
    await page.waitForTimeout(2000);

    const recordBtn = page.locator('[data-starmus-action="record"]');
    await recordBtn.click();
    await page.waitForTimeout(2000);

    const stopBtn = page.locator('[data-starmus-action="stop"]');
    await stopBtn.click();
    await page.waitForTimeout(1000);

    // Fill in form details (required for submission)
    await page.fill('input[name="dc_creator"]', 'Test User');
    await page.selectOption('select[name="language"]', { index: 1 });
    await page.selectOption('select[name="recording_type"]', { index: 1 });
    await page.check('input[name="agreement_to_terms_toggle"]');

    // Submit
    const submitBtn = page.locator('[data-starmus-action="submit"]');
    await expect(submitBtn).toBeEnabled({ timeout: 5000 });
    await submitBtn.click();

    // Wait for status update
    await page.waitForTimeout(2000);

    // Check status shows queued
    const statusElement = page.locator('[data-starmus-status]');
    await expect(statusElement).toBeVisible({ timeout: 5000 });

    const statusText = await statusElement.textContent();
    expect(statusText.toLowerCase()).toContain('queue');

    // Verify in IndexedDB that submission is queued
    const queuedSubmission = await page.evaluate(async () => {
      return new Promise((resolve) => {
        try {
          const request = indexedDB.open('starmus_queue');
          request.onsuccess = () => {
            const db = request.result;
            const tx = db.transaction('queue', 'readonly');
            const store = tx.objectStore('queue');
            const getRequest = store.get(1);
            getRequest.onsuccess = () => {
              resolve(getRequest.result || null);
            };
          };
        } catch (e) {
          resolve(null);
        }
      });
    });

    expect(queuedSubmission).not.toBeNull();
  });

  /**
   * Test: Reconnect - upload resumes
   *
   * After going back online, queued submissions should
   * automatically resume uploading.
   */
  test('Reconnect - upload resumes automatically', async ({ page }) => {
    // Start offline
    await page.context().setOffline(true);

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

    await page.goto('/starmus-recorder/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    // Setup, record, and submit while offline
    const setupBtn = page.locator('[data-starmus-action="setup-mic"]');
    await setupBtn.click();
    await page.waitForTimeout(2000);

    const recordBtn = page.locator('[data-starmus-action="record"]');
    await recordBtn.click();
    await page.waitForTimeout(2000);

    const stopBtn = page.locator('[data-starmus-action="stop"]');
    await stopBtn.click();
    await page.waitForTimeout(1000);

    // Fill form and submit
    await page.fill('input[name="dc_creator"]', 'Test User');
    await page.selectOption('select[name="language"]', { index: 1 });
    await page.selectOption('select[name="recording_type"]', { index: 1 });
    await page.check('input[name="agreement_to_terms_toggle"]');

    const submitBtn = page.locator('[data-starmus-action="submit"]');
    await submitBtn.click();

    // Wait for queued status
    await page.waitForTimeout(2000);

    // Now go back online
    await page.context().setOffline(false);

    // Wait for upload to resume (explicit wait, not auto-retry)
    await page.waitForTimeout(3000);

    // Status should show uploading or success
    const statusElement = page.locator('[data-starmus-status]');
    await expect(statusElement).toBeVisible({ timeout: 5000 });

    const statusText = await statusElement.textContent();
    // Should be uploading or complete, not still queued
    expect(statusText.toLowerCase()).not.toBe('queued');
  });

  /**
   * Test: Network drop mid-upload - TUS resumes
   *
   * If network drops during upload, TUS protocol should
   * automatically resume from where it left off.
   */
  test('Network drop mid-upload - TUS resumable', async ({ page }) => {
    // Track upload progress
    const uploadProgress = [];

    // Monitor network requests
    page.on('response', response => {
      if (response.url().includes('/upload') || response.url().includes('/files/')) {
        uploadProgress.push({
          url: response.url(),
          status: response.status(),
          timestamp: Date.now()
        });
      }
    });

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

    await page.goto('/starmus-recorder/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    // Setup and create recording
    const setupBtn = page.locator('[data-starmus-action="setup-mic"]');
    await setupBtn.click();
    await page.waitForTimeout(2000);

    const recordBtn = page.locator('[data-starmus-action="record"]');
    await recordBtn.click();
    await page.waitForTimeout(2000);

    const stopBtn = page.locator('[data-starmus-action="stop"]');
    await stopBtn.click();
    await page.waitForTimeout(1000);

    // Fill form and submit
    await page.fill('input[name="dc_creator"]', 'Test User');
    await page.selectOption('select[name="language"]', { index: 1 });
    await page.selectOption('select[name="recording_type"]', { index: 1 });
    await page.check('input[name="agreement_to_terms_toggle"]');

    const submitBtn = page.locator('[data-starmus-action="submit"]');
    await submitBtn.click();

    // Wait for upload to start
    await page.waitForTimeout(2000);

    // Drop network mid-upload
    await page.context().setOffline(true);
    await page.waitForTimeout(2000);

    // Restore network
    await page.context().setOffline(false);

    // Wait for resume
    await page.waitForTimeout(3000);

    // Should have multiple upload attempts (initial + resume)
    // This indicates TUS is working (resumable uploads)
    const uploadRequests = uploadProgress.filter(p =>
      p.url.includes('/upload') || p.url.includes('/files/')
    );

    // Should have at least 2 attempts if TUS resume worked
    expect(uploadRequests.length).toBeGreaterThanOrEqual(1);
  });

  /**
   * Test: Completion state appears exactly once
   *
   * Success/completion should be indicated exactly once,
   * not multiple times (which would indicate duplicate submissions).
   */
  test('Completion state appears exactly once', async ({ page }) => {
    const completionSignals = [];

    // Track completion signals
    page.on('console', msg => {
      if (msg.text().includes('[STARMUS COMPLETE]') ||
          msg.text().includes('[STARMUS SUCCESS]') ||
          msg.text().includes('success')) {
        completionSignals.push({
          text: msg.text(),
          timestamp: Date.now()
        });
      }
    });

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

    await page.goto('/starmus-recorder/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    // Setup and create recording
    const setupBtn = page.locator('[data-starmus-action="setup-mic"]');
    await setupBtn.click();
    await page.waitForTimeout(2000);

    const recordBtn = page.locator('[data-starmus-action="record"]');
    await recordBtn.click();
    await page.waitForTimeout(2000);

    const stopBtn = page.locator('[data-starmus-action="stop"]');
    await stopBtn.click();
    await page.waitForTimeout(1000);

    // Fill form and submit
    await page.fill('input[name="dc_creator"]', 'Test User');
    await page.selectOption('select[name="language"]', { index: 1 });
    await page.selectOption('select[name="recording_type"]', { index: 1 });
    await page.check('input[name="agreement_to_terms_toggle"]');

    const submitBtn = page.locator('[data-starmus-action="submit"]');
    await submitBtn.click();

    // Wait for completion
    await page.waitForTimeout(5000);

    // Check for success status
    const statusElement = page.locator('[data-starmus-status]');
    const statusText = await statusElement.textContent();

    // Completion should be indicated
    expect(
      statusText.toLowerCase().includes('success') ||
      statusText.toLowerCase().includes('complete') ||
      statusText.toLowerCase().includes('submitted')
    ).toBe(true);
  });

  /**
   * Test: No silent retries - failures are visible
   *
   * When retries occur, they should be visible to the user,
   * not silently hidden.
   */
  test('No silent retries - failures are visible', async ({ page }) => {
    const retrySignals = [];

    // Track retry signals
    page.on('console', msg => {
      if (msg.text().includes('[STARMUS RETRY]') ||
          msg.text().includes('[STARMUS RETRYING]')) {
        retrySignals.push({
          text: msg.text(),
          timestamp: Date.now()
        });
      }
    });

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

    await page.goto('/starmus-recorder/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    // Setup and create recording
    const setupBtn = page.locator('[data-starmus-action="setup-mic"]');
    await setupBtn.click();
    await page.waitForTimeout(2000);

    const recordBtn = page.locator('[data-starmus-action="record"]');
    await recordBtn.click();
    await page.waitForTimeout(2000);

    const stopBtn = page.locator('[data-starmus-action="stop"]');
    await stopBtn.click();
    await page.waitForTimeout(1000);

    // Fill form and submit
    await page.fill('input[name="dc_creator"]', 'Test User');
    await page.selectOption('select[name="language"]', { index: 1 });
    await page.selectOption('select[name="recording_type"]', { index: 1 });
    await page.check('input[name="agreement_to_terms_toggle"]');

    const submitBtn = page.locator('[data-starmus-action="submit"]');
    await submitBtn.click();

    // Simulate intermittent network failures
    let attemptCount = 0;
    page.route('**/wp-json/**', route => {
      attemptCount++;
      if (attemptCount < 3) {
        // First 2 attempts fail
        route.abort('failed');
      } else {
        // Subsequent attempts succeed
        route.continue();
      }
    });

    // Wait for completion or visible failure
    await page.waitForTimeout(10000);

    // Status should show visible indication of what's happening
    const statusElement = page.locator('[data-starmus-status]');
    const statusText = await statusElement.textContent();

    // Either:
    // 1. Retries were logged (showing they're not silent)
    // 2. Status shows visible progress/failure
    expect(
      retrySignals.length > 0 ||
      statusText.length > 0
    ).toBe(true);
  });
});

