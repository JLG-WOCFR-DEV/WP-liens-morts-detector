import { expect, test } from '@playwright/test';

const baseURL = process.env.WP_E2E_BASE_URL;
const username = process.env.WP_E2E_USERNAME;
const password = process.env.WP_E2E_PASSWORD;
const targetLink = process.env.WP_E2E_SAMPLE_LINK;
const replacementFallback = targetLink ? `${targetLink}-corrige` : 'https://example.com/lien-corrige';
const replacementUrl = process.env.WP_E2E_REPLACEMENT_URL ?? replacementFallback;

const shouldSkip = !baseURL || !username || !password || !targetLink;

test.describe('Correction d\'un lot de liens cassés', () => {
  test.skip(shouldSkip, 'Les variables WP_E2E_* doivent être définies pour exécuter les tests end-to-end.');

  test('permet de modifier un lien cassé depuis le tableau des liens', async ({ page }) => {
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

    const tableRegion = page.locator('[data-blc-table-region]');
    await expect(tableRegion, 'Le tableau des liens cassés doit être visible.').toBeVisible();

    const targetRow = page.locator('#the-list tr', { hasText: targetLink! }).first();
    await expect(targetRow, 'Le lien attendu doit être présent avant la correction.').toBeVisible();

    await test.step('Ouvrir la modale d\'édition', async () => {
      await targetRow.locator('.blc-edit-link').click();
      await expect(page.locator('#blc-modal')).toBeVisible();
    });

    await test.step('Soumettre la nouvelle URL', async () => {
      const modal = page.locator('#blc-modal');
      await modal.locator('.blc-modal__input').fill(replacementUrl);
      await Promise.all([
        page.waitForResponse((response) =>
          response.url().includes('admin-ajax.php') && response.request().method() === 'POST'
        ),
        modal.getByRole('button', { name: 'Mettre à jour' }).click(),
      ]);
    });

    await test.step('Vérifier que la ligne est mise à jour', async () => {
      await expect(
        page.locator('#the-list tr', { hasText: targetLink! })
      ).toHaveCount(0, { timeout: 20_000 });

      const updatedRows = page.locator('#the-list tr', { hasText: replacementUrl });
      if (await updatedRows.count()) {
        const statusCell = updatedRows.first().locator('td.column-http_status');
        await expect(statusCell).not.toBeEmpty();
        await expect(statusCell).not.toContainText(/4\d\d|5\d\d/);
      } else {
        const successNotice = page.locator('#the-list tr.no-items, #wpbody-content');
        await expect(successNotice).toContainText(/Action effectuée|Aucun lien cassé/i);
      }
    });
  });
});
