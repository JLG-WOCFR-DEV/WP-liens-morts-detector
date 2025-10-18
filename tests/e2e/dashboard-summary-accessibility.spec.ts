import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const baseURL = process.env.WP_E2E_BASE_URL;
const username = process.env.WP_E2E_USERNAME;
const password = process.env.WP_E2E_PASSWORD;

const shouldSkip = !baseURL || !username || !password;

const SERIOUS_IMPACTS = new Set(['serious', 'critical']);

test.describe('Accessibilité de la synthèse du tableau de bord @a11y', () => {
  test.skip(
    shouldSkip,
    "Les variables WP_E2E_* doivent être définies pour exécuter les tests d'accessibilité."
  );

  test('ne présente pas de violations d\'impact sérieux ou critique sur la synthèse', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=blc-dashboard', { waitUntil: 'domcontentloaded' });

    if (page.url().includes('wp-login.php')) {
      await page.fill('input#user_login', username!);
      await page.fill('input#user_pass', password!);
      await Promise.all([
        page.waitForURL(/\/wp-admin\//, { timeout: 60_000 }),
        page.click('input#wp-submit'),
      ]);

      await page.goto('/wp-admin/admin.php?page=blc-dashboard', { waitUntil: 'domcontentloaded' });
    }

    const summarySection = page.locator('.blc-dashboard-summary');
    await expect(summarySection, 'La section synthèse doit être visible avant l\'audit axe.').toBeVisible();

    const axe = new AxeBuilder({ page })
      .include('.blc-dashboard-summary')
      .withTags(['wcag2a', 'wcag2aa', 'section508']);

    const results = await axe.analyze();

    const seriousViolations = results.violations.filter((violation) =>
      violation.impact ? SERIOUS_IMPACTS.has(violation.impact) : false
    );

    expect(
      seriousViolations,
      "La synthèse du tableau de bord ne doit pas comporter de violations critiques détectées par axe-core."
    ).toEqual([]);
  });
});
