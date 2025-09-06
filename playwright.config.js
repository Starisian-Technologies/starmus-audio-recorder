import { defineConfig, devices } from '@playwright/test';

export default defineConfig(
	{
		testDir: './tests/e2e',
		timeout: 30000,
		expect: { timeout: 5000 },
		fullyParallel: true,
		forbidOnly: ! ! process.env.CI,
		retries: process.env.CI ? 2 : 0,
		workers: process.env.CI ? 1 : undefined,
		reporter: 'html',
		use: {
			baseURL: 'http://localhost:8080',
			trace: 'on-first-retry',
		},
		projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
		{ name: 'slow-3g', use: { ...devices['Desktop Chrome'], launchOptions: { args: ['--throttling.cpuSlowdownMultiplier=4'] } } },
		],
	}
);