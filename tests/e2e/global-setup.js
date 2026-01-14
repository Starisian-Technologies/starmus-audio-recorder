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

  console.log('Configuring test environment...');
  try {
    // Activate plugin
    console.log('Activating plugin...');
    execSync('npx wp-env run tests-cli wp plugin activate starmus-audio-recorder', { stdio: 'inherit' });

    // Create recorder page
    console.log('Creating recorder page...');
    execSync(
      'npx wp-env run tests-cli wp post create --post_type=page --post_title="Recorder Test" --post_status=publish --post_content="[starmus_audio_recorder]" --post_name="recorder-test"',
      { stdio: 'inherit' }
    );

    // Set recorder page option
    console.log('Setting recorder page option...');
    execSync(
      `npx wp-env run tests-cli wp eval '$page = get_page_by_path("recorder-test"); $options = get_option("starmus_options", []); $options["recorder_page_id"] = $page->ID; update_option("starmus_options", $options); echo "Recorder page option set to ID: " . $page->ID;'`,
      { stdio: 'inherit' }
    );

  } catch (e) {
    console.warn("Setup warning (ignoring if just 'already exists'): " + e.message);
  }

  console.log('Global setup complete');
}

export default globalSetup;

