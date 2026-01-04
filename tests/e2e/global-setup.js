/**
 * Global Playwright Setup for Starmus E2E Tests
 *
 * Handles:
 * - WordPress environment startup verification
 * - Browser installation check
 * - Test data initialization
 */

import { chromium } from '@playwright/test';
import { execSync } from 'child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'fs';
import { join } from 'path';

/**
 * Check if WordPress environment is running and healthy.
 */
async function verifyWordPressEnvironment(baseURL) {
  console.log(`Verifying WordPress environment at ${baseURL}...`);

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  try {
    const response = await page.goto(baseURL, {
      waitUntil: 'domcontentloaded',
      timeout: 30000
    });

    if (!response || response.status() >= 400) {
      throw new Error(`WordPress environment not responding: ${response?.status() || 'no response'}`);
    }

    console.log('WordPress environment is healthy');
    return true;
  } catch (error) {
    console.error('WordPress environment check failed:', error.message);
    throw error;
  } finally {
    await browser.close();
  }
}

/**
 * Ensure test data directories exist.
 */
function ensureTestDirectories() {
  const testResultsDir = './test-results';
  const e2eAssetsDir = './tests/e2e/assets';

  if (!existsSync(testResultsDir)) {
    mkdirSync(testResultsDir, { recursive: true });
  }

  if (!existsSync(e2eAssetsDir)) {
    mkdirSync(e2eAssetsDir, { recursive: true });
  }

  console.log('Test directories ready');
}

/**
 * Main global setup function.
 */
async function globalSetup() {
  const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8081';

  console.log('Starting Starmus Playwright global setup...');

  // Ensure directories exist
  ensureTestDirectories();

  // Verify WordPress environment is running
  await verifyWordPressEnvironment(baseURL);

  console.log('Global setup complete');
}

export default globalSetup;

