// playwright.config.js
/**
 * Playwright E2E Test Configuration for Starmus Audio Recorder
 *
 * Configures browser testing with:
 * - Device emulation for low-end Android devices
 * - Network throttling profiles (3G, offline, intermittent)
 * - Explicit timeout handling
 * - CI-compatible headless execution
 */

import { defineConfig, devices } from "@playwright/test";

const baseURL = process.env.PLAYWRIGHT_BASE_URL || "http://localhost:8081";

export default defineConfig({
  testDir: "./tests/e2e",

  /**
   * Global timeout for test operations.
   * Tests must complete within this time or fail.
   */
  timeout: 60000,

  /**
   * Assertion timeout - explicit waits for expectations.
   * Must be short to catch timing issues.
   */
  expect: { timeout: 5000 },

  /**
   * Run tests in parallel for speed, but forbid exclusive mode in CI.
   */
  fullyParallel: true,
  forbidOnly: !!process.env.CI,

  /**
   * No retries in local dev - we want to see failures immediately.
   * CI may enable retries for flake detection.
   */
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,

  /**
   * HTML reporter for local dev, CI gets line reporter.
   */
  reporter: process.env.CI ? "line" : "html",

  use: {
    baseURL,
    trace: "on-first-retry",
    /**
     * Disable automatic waiting - tests must use explicit waits.
     * This ensures timing issues are surfaced, not hidden.
     */
    waitUntil: "domcontentloaded",
    /**
     * Capture screenshots on failure for debugging.
     */
    screenshot: "only-on-failure",
  },

  /**
   * Test projects for different device/network conditions.
   */
  projects: [
    /**
     * Primary: Chromium on desktop for fast local testing.
     */
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },

    /**
     * Low-end Android device emulation.
     * Small viewport, touch enabled, limited CPU.
     */
    {
      name: "android-low-end",
      use: {
        ...devices["Galaxy S8"],
        /**
         * Emulate slower CPU for realistic performance testing.
         */
        launchOptions: {
          args: [
            "--throttling.cpuSlowdownMultiplier=4",
            "--disable-dev-shm-usage",
          ],
        },
      },
    },

    /**
     * Slow 3G network conditions.
     * Tests behavior under high latency.
     */
    {
      name: "slow-3g",
      use: {
        ...devices["Desktop Chrome"],
        /**
         * Playwright doesn't have built-in network throttling for all browsers,
         * so we use Chrome's command-line flags.
         */
        launchOptions: {
          args: [
            "--force-effective-connection-type=3g",
            "--throttling.cpuSlowdownMultiplier=2",
          ],
        },
      },
    },

    /**
     * Thermal throttling simulation.
     * Tests behavior when device is under stress.
     */
    {
      name: "thermal-throttle",
      use: {
        ...devices["Desktop Chrome"],
        launchOptions: {
          args: ["--throttling.cpuSlowdownMultiplier=12"],
        },
      },
    },

    /**
     * Network intermittent testing.
     * Uses custom network conditions set per-test.
     */
    {
      name: "network-intermittent",
      use: { ...devices["Desktop Chrome"] },
    },
  ],

  /**
   * Web server configuration for WordPress environment.
   */
  webServer: {
    command:
      baseURL === "http://localhost:8081" ? "npm run env:start" : undefined,
    url: baseURL,
    reuseExistingServer: !process.env.CI,
    /**
     * Extended timeout for WordPress bootstrap.
     */
    timeout: 180000,
  },

  /**
   * Global test hooks.
   */
  globalSetup: "./tests/e2e/global-setup.js",

  /**
   * Paths to ignore during test discovery.
   */
  testIgnore: [
    "**/node_modules/**",
    "**/vendor/**",
  ],

  /**
   * Output directory for test artifacts (traces, screenshots).
   */
  outputDir: "./test-results/",
});
