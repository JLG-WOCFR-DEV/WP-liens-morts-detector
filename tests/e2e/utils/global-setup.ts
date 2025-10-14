import { chromium, FullConfig } from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';

export default async function globalSetup(_config: FullConfig) {
  const baseURL = process.env.WP_E2E_BASE_URL;
  const username = process.env.WP_E2E_USERNAME;
  const password = process.env.WP_E2E_PASSWORD;

  if (!baseURL || !username || !password) {
    console.warn(
      '[playwright] Skipping authenticated storage state because WP_E2E_BASE_URL, WP_E2E_USERNAME or WP_E2E_PASSWORD is not set.'
    );
    return;
  }

  const storageStatePath = process.env.WP_E2E_STORAGE_STATE ?? path.resolve(__dirname, '../../../.playwright/wp-admin-state.json');
  await fs.mkdir(path.dirname(storageStatePath), { recursive: true });

  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('input#user_login', username);
  await page.fill('input#user_pass', password);

  await Promise.all([
    page.waitForURL(/\/wp-admin\//, { timeout: 60_000 }),
    page.click('input#wp-submit'),
  ]);

  await page.goto('/wp-admin/index.php');

  await context.storageState({ path: storageStatePath });
  await browser.close();
}
