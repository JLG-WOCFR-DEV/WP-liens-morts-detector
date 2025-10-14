import { defineConfig, devices } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const baseURL = process.env.WP_E2E_BASE_URL ?? 'http://localhost:8889';
const storageStatePath = process.env.WP_E2E_STORAGE_STATE ?? path.resolve(__dirname, '.playwright', 'wp-admin-state.json');
const hasStorageState = fs.existsSync(storageStatePath);

const reporters = process.env.CI
  ? [['github'], ['html', { open: 'never' }], ['list']]
  : [['list'], ['html', { open: 'never' }]];

export default defineConfig({
  testDir: path.resolve(__dirname, 'tests/e2e'),
  timeout: 120_000,
  expect: {
    timeout: 15_000,
  },
  reporter: reporters,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  use: {
    baseURL,
    headless: true,
    storageState: hasStorageState ? storageStatePath : undefined,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  globalSetup: require.resolve('./tests/e2e/utils/global-setup'),
});
