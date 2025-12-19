// tests/e2e/african-conditions.spec.js
import { test, expect } from '@playwright/test';

test.describe('African Deployment Conditions', () => {
  
  test('Runtime error detection under 2G conditions', async ({ page }) => {
    const errors = [];
    
    // Capture runtime errors
    page.on('console', msg => {
      if (msg.type() === 'error' || msg.text().includes('[STARMUS RUNTIME]')) {
        errors.push(msg.text());
      }
    });
    
    await page.goto('/recorder-test-page/');
    
    // Simulate fast retry behavior
    await page.click('[data-starmus-action="setup-mic"]');
    await page.waitForTimeout(100);
    await page.click('[data-starmus-action="setup-mic"]'); // Fast retry
    
    // Check for runtime errors
    expect(errors.length).toBeGreaterThan(0);
    expect(errors.some(e => e.includes('[STARMUS RUNTIME]'))).toBe(true);
  });

  test('Background tab simulation', async ({ page }) => {
    await page.goto('/recorder-test-page/');
    
    // Start recording
    await page.click('[data-starmus-action="setup-mic"]');
    await page.click('[data-starmus-action="record"]');
    
    // Simulate background tab (visibility change)
    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { value: true, writable: true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    
    await page.waitForTimeout(2000);
    
    // Return to foreground
    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { value: false, writable: true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    
    // Should still be recording
    await expect(page.locator('[data-starmus-action="stop"]')).toBeVisible();
  });

  test('Permission dialog handling', async ({ context, page }) => {
    // Grant microphone permission
    await context.grantPermissions(['microphone']);
    
    await page.goto('/recorder-test-page/');
    
    // Should work without permission dialog
    await page.click('[data-starmus-action="setup-mic"]');
    await expect(page.locator('.starmus-timer')).toBeVisible({ timeout: 10000 });
  });

  test('Network interruption during upload', async ({ page }) => {
    await page.goto('/recorder-test-page/');
    
    // Complete recording
    await page.click('[data-starmus-action="setup-mic"]');
    await page.waitForTimeout(5000);
    await page.click('[data-starmus-action="record"]');
    await page.waitForTimeout(2000);
    await page.click('[data-starmus-action="stop"]');
    
    // Simulate network failure during upload
    await page.route('**/wp-json/**', route => route.abort());
    
    await page.click('[data-starmus-action="submit"]');
    
    // Should show offline queue message
    await expect(page.locator('text=queued')).toBeVisible({ timeout: 5000 });
  });
});