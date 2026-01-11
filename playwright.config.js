import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60000,
  use: {
    baseURL: 'http://localhost:8888', // <-- your local WP dev URL
    headless: true,
    viewport: { width: 390, height: 844 }, // low-end phone profile
  },
});
