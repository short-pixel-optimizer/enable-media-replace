/**
 * The Black Friday banner (bundled promo-banner module, EMR's
 * EnableMediaReplacePlugin::promoBanner()): on EMR's four pages only, below
 * the header bar, and dismissible for good. The campaign window is forced
 * open with the support option `emr_e2e_promo_window`; outside it the
 * banner never shows.
 */
import { test, expect } from '../fixtures';
import { KNOWN_JS_DEFECTS, emrUrls, expectOnlyKnownErrors } from '../helpers/emr';

const BANNER = '.shortpixel-bf';

test.describe('Promo banner', () => {
	test.use({ allowConsoleErrors: true });

	test('outside the campaign window it shows nowhere', async ({ page }) => {
		await page.goto(emrUrls.bulk);
		await expect(page.locator('.emr-page-header')).toBeVisible();
		await expect(page.locator(BANNER)).toHaveCount(0);
	});

	test('inside the window: on the bulk, replace, remove background and upscale pages, below the header bar, not elsewhere', async ({ page, emr, consoleErrors }) => {
		await emr.setOption('emr_e2e_promo_window', 1);
		const id = emr.seed.attachment_id;

		for (const url of [emrUrls.bulk, emrUrls.replace(id), emrUrls.removeBackground(id), emrUrls.upscale(id)]) {
			await page.goto(url);
			const banner = page.locator(BANNER);
			await expect(banner, url).toBeVisible();
			await expect(banner).toContainText('25% OFF any yearly plan for life');
			await expect(banner).toContainText('Offer ends November 30, 2026');
			await expect(banner.getByRole('link', { name: 'Claim Deal' })).toHaveAttribute('href', /utm_medium=emr/);
			// WordPress moved it below the header bar.
			const below = await page.evaluate(() => {
				const header = document.querySelector('.emr-page-header');
				const node = document.querySelector('.shortpixel-bf');
				return !!header && !!node && !!(header.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING);
			});
			expect(below, url).toBe(true);
		}

		await page.goto('/wp-admin/upload.php?mode=list');
		await expect(page.locator(BANNER)).toHaveCount(0);
		await page.goto('/wp-admin/index.php');
		await expect(page.locator(BANNER)).toHaveCount(0);

		expectOnlyKnownErrors(consoleErrors, [KNOWN_JS_DEFECTS.removeBackgroundLocationOption]);
	});

	test('the server prints it right after the header bar (no script moves it, no flash) and it has no inline code', async ({ page, emr }) => {
		await emr.setOption('emr_e2e_promo_window', 1);
		for (const url of [emrUrls.bulk, emrUrls.replace(emr.seed.attachment_id)]) {
			// The HTML as sent, before any script runs.
			const html = await (await page.request.get(url)).text();
			const header = html.indexOf('class="wp-header-end"');
			const banner = html.indexOf('class="shortpixel-bf"');
			expect(header, url).toBeGreaterThan(-1);
			expect(banner, `${url}: after the header in the page source`).toBeGreaterThan(header);
			expect(html).not.toContain('class="notice shortpixel-bf"');

			await page.goto(url);
			const markup = await page.locator(BANNER).evaluate((el) => el.outerHTML);
			expect(markup).not.toMatch(/\sstyle=|<script|\son[a-z]+=/i);
		}
	});

	test('the close button hides it and it stays hidden after a reload', async ({ page, emr }) => {
		await emr.setOption('emr_e2e_promo_window', 1);
		await page.goto(emrUrls.bulk);
		const banner = page.locator(BANNER);
		await expect(banner).toBeVisible();

		const stored = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData()?.includes('emr_promo_banner_dismiss') === true);
		await banner.getByRole('button', { name: 'Dismiss' }).click();
		await expect(banner).toBeHidden();
		expect((await stored).status(), 'the dismissal is stored (admin-ajax answers 200)').toBe(200);

		await page.reload();
		await expect(page.locator('.emr-page-header')).toBeVisible();
		await expect(page.locator(BANNER)).toHaveCount(0);
	});
});
