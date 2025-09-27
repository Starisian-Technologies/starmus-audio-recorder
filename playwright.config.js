// playwright.config.js
import { defineConfig, devices } from "@playwright/test";

const baseURL = process.env.PLAYWRIGHT_BASE_URL || "http://localhost:8081";

export default defineConfig({
  testDir: "./tests/e2e",
  timeout: 30000,
  expect: { timeout: 5000 },
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: "html",
  use: {
    baseURL,
    trace: "on-first-retry",
  },
  projects: [
    { name: "chromium", use: { ...devices["Desktop Chrome"] } },
    {
      name: "slow-3g",
      use: {
        ...devices["Desktop Chrome"],
        launchOptions: { args: ["--throttling.cpuSlowdownMultiplier=4"] },
      },
    },
  ],

  /*
   * ===================================================================
   *   FINAL WEBSERVER CONFIGURATION
   * ===================================================================
   * This block now contains the correct command to start your server.
   */
  webServer: {
    command:
      baseURL === "http://localhost:8081" ? "npm run env:start" : undefined,
    url: baseURL,

    /**
     * If you already have a server running in another terminal, Playwright
     * will just use it instead of trying to start a new one.
     */
    reuseExistingServer: !process.env.CI,

    /**
     * Increased timeout to give the WordPress environment time to start up.
     */
    timeout: 120000,
  },
});
