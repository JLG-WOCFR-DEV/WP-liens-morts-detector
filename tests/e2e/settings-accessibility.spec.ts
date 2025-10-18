import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const baseURL = process.env.WP_E2E_BASE_URL;
const username = process.env.WP_E2E_USERNAME;
const password = process.env.WP_E2E_PASSWORD;

const shouldSkip = !baseURL || !username || !password;

const SERIOUS_IMPACTS = new Set(['serious', 'critical']);

test.describe('Accessibilité des réglages Liens Morts Detector @a11y', () => {
  test.skip(shouldSkip, 'Les variables WP_E2E_* doivent être définies pour exécuter les tests d\'accessibilité.');

  test('ne présente pas de violations d\'impact sérieux ou critique sur la page des réglages', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=blc-settings', { waitUntil: 'domcontentloaded' });

    if (page.url().includes('wp-login.php')) {
      await page.fill('input#user_login', username!);
      await page.fill('input#user_pass', password!);
      await Promise.all([
        page.waitForURL(/\/wp-admin\//, { timeout: 60_000 }),
        page.click('input#wp-submit'),
      ]);

      await page.goto('/wp-admin/admin.php?page=blc-settings', { waitUntil: 'domcontentloaded' });
    }

    const settingsForm = page.locator('.blc-settings-form');
    await expect(settingsForm, 'Le formulaire de réglages doit être visible avant l\'audit axe.').toBeVisible();

    const helpButton = settingsForm.locator('.blc-field-help').first();
    if (await helpButton.count()) {
      await helpButton.click();
    }

    const axe = new AxeBuilder({ page })
      .include('.blc-settings-form')
      .withTags(['wcag2a', 'wcag2aa', 'section508']);

    const results = await axe.analyze();

    const seriousViolations = results.violations.filter((violation) =>
      violation.impact ? SERIOUS_IMPACTS.has(violation.impact) : false
    );

    expect(seriousViolations, 'La page des réglages ne doit pas comporter de violations critiques détectées par axe-core.').toEqual([]);
  });
});
