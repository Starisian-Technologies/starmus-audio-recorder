import { defineConfig } from '@playwright/test';
import { existsSync } from 'fs';

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.js',
  timeout: 60000,
    use: {
        baseURL: process.env.WP_BASE_URL || 'http://localhost:8081',
        // headless: true,
        // viewport: { width: 390, height: 844 }, // low-end phone profile
        storageState: existsSync('tests/e2e/state.json') ? 'tests/e2e/state.json' : undefined,
    },
});
