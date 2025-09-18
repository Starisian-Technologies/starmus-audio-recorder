import { test, expect } from '@playwright/test';
import { injectAxe, checkA11y } from 'axe-playwright';

test.describe('Offline-first patterns', () => {
  test('audio recorder works without JS', async ({ page }) => {
    await page.goto('/test-page-with-recorder');
    await page.addInitScript(() => {
      window.navigator.serviceWorker = undefined;
      window.MediaRecorder = undefined;
    });

    // Should show fallback form
    await expect(page.locator('form')).toBeVisible();
    await expect(page.locator('input[type="file"]')).toBeVisible();
  });

  test('offline queue resumes after connection', async ({ page }) => {
    await page.goto('/test-page-with-recorder');

    // Simulate offline
    await page.context().setOffline(true);

    // Try to submit recording
  await page.fill('#starmus_title', 'Test Recording');
    await page.click('button[type="submit"]');

    // Should queue for later
    await expect(page.locator('.starmus-status')).toContainText('queued');

    // Go back online
    await page.context().setOffline(false);

    // Should auto-retry
    await expect(page.locator('.starmus-status')).toContainText('uploading');
  });

  test('meets WCAG 2.1 AA standards', async ({ page }) => {
    await page.goto('/test-page-with-recorder');
    await injectAxe(page);

    await checkA11y(page, null, {
      detailedReport: true,
      detailedReportOptions: { html: true },
    });
  });

  test('chunked upload with slow connection', async ({ page }) => {
    await page.route('**/starmus/v1/upload-chunk', route => {
      setTimeout(() => route.continue(), 2000); // 2s delay
    });

    await page.goto('/test-page-with-recorder');

    // Upload should work with delays
  await page.fill('#starmus_title', 'Slow Upload Test');
    // Simulate file upload and verify chunking
  });
});
