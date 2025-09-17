// playwright.config.js
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30000,
    expect: { timeout: 5000 },
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: 'html',
    use: {
        baseURL: 'http://localhost:8081',
        trace: 'on-first-retry',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'slow-3g', use: { ...devices['Desktop Chrome'], launchOptions: { args: ['--throttling.cpuSlowdownMultiplier=4'] } } },
    ],

    /*
     * ===================================================================
     *   FINAL WEBSERVER CONFIGURATION
     * ===================================================================
     * This block now contains the correct command to start your server.
     */
    webServer: {
        /**
         * The command to start your local WordPress development server.
         */
        command: 'npm run env:start', // <-- CORRECTED COMMAND

        /**
         * The URL that Playwright will poll to see if the server is ready.
         * This MUST match the `baseURL`.
         */
        url: 'http://localhost:8081',

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
