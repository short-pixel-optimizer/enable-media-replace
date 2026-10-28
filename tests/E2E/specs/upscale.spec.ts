/**
 * The upscale journeys (views/prepare-upscale.php, the shared js/remove_bg.js,
 * the emr_remove_background AJAX handler with an `upscale` factor, Api and
 * UpscaleViewController::loadPost()), against the mock API:
 *   - the entry points: row action, meta box button, the links on the
 *     replace and remove-background screens;
 *   - the screen: previews, the factor radios and how the image width
 *     disables them, the account box with the upscale wording;
 *   - Preview: the AJAX call carries the factor, the /f/ result renders
 *     with its dimensions;
 *   - Replace: the success screen and the replaced file;
 *   - the quota-exceeded (free) notice with the upscale wording;
 *   - the `upscale` feature off: no entry points, the URL refused, the
 *     remove-background entry points untouched.
 *
 * The screen enqueues js/emr_admin.js like the remove-background screen
 * does, so it may throw the same known TypeError
 * (KNOWN_JS_DEFECTS.removeBackgroundLocationOption, pinned in
 * smoke.spec.ts); the screen tests accept that error and nothing else.
 */
import { test, expect } from '../fixtures';
import { KNOWN_JS_DEFECTS, RemoveBackgroundScreen, ReplaceScreen, UpscaleScreen, emrUrls, expectOnlyKnownErrors } from '../helpers/emr';
import { expectSuccessThenRedirect, fetchBytes } from '../helpers/journeys';
import { EditAttachment, MediaList } from '../helpers/media-list';
import type { Page } from '@playwright/test';

const KNOWN = [KNOWN_JS_DEFECTS.removeBackgroundLocationOption];

/** Click Preview and wait for the emr_remove_background answer. */
async function preview(page: Page, screen: UpscaleScreen): Promise<Record<string, unknown>> {
	const answer = page.waitForResponse(
		(r) => r.url().includes('admin-ajax.php') && r.request().postData()?.includes('action=emr_remove_background') === true,
	);
	await screen.previewButton.click();
	const response = await answer;
	expect(response.status()).toBe(200);
	return (await response.json()) as Record<string, unknown>;
}

test.describe('Upscale', () => {
	test.use({ allowConsoleErrors: true });

	test('the entry points: row action, meta box button and the links on the two other screens', async ({ page, emr, consoleErrors }) => {
		const id = emr.seed.attachment_id;
		const list = new MediaList(page);
		await list.goto();
		const action = list.rowActionLink(id, 'upscale_image');
		await expect(action).toHaveCount(1);
		await expect(action).toHaveAttribute('href', /action=emr_prepare_upscale.*attachment_id=\d+.*_wpnonce=/);
		await expect(action).toHaveText('Upscale image');

		const edit = new EditAttachment(page);
		await edit.goto(id);
		await expect(edit.upscaleButton).toBeVisible();
		await expect(edit.upscaleButton).toHaveAttribute('href', /action=emr_prepare_upscale.*_wpnonce=/);

		const replace = new ReplaceScreen(page);
		await replace.goto(id);
		await expect(replace.upscaleLink).toHaveAttribute('href', /action=emr_prepare_upscale.*_wpnonce=/);

		const remove = new RemoveBackgroundScreen(page);
		await remove.goto(id);
		await expect(remove.upscaleLink).toHaveAttribute('href', /action=emr_prepare_upscale.*_wpnonce=/);
		await remove.upscaleLink.click();
		await page.waitForURL(/action=emr_prepare_upscale/);
		await expect(new UpscaleScreen(page).form).toBeVisible();

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('the screen shows the previews, the factors and the upscale account wording', async ({ page, emr, consoleErrors }) => {
		const screen = new UpscaleScreen(page);
		await screen.goto(emr.seed.attachment_id);

		const original = screen.originalPreview.locator('img');
		await expect(original).toBeVisible();
		await expect(original).toHaveAttribute('src', /\/emr-seed(-\d+x\d+)?\.jpg/);
		await expect(screen.resultImage).toHaveCount(0);
		await expect(screen.resultPreview).toContainText('Upscaling image...');
		await expect(screen.form.locator('input[name="ID"]')).toHaveValue(String(emr.seed.attachment_id));
		await expect(screen.resultKey).toHaveValue('');
		await expect(screen.replaceButton).toBeDisabled();
		await expect(screen.previewButton).toBeEnabled();
		await expect(screen.replaceInsteadLink).toHaveAttribute('href', /action=media_replace.*_wpnonce=/);

		// The seed is 800x600: every factor fits, 2x is preselected.
		await expect(screen.factor(2)).toBeChecked();
		await expect(screen.factor(2)).toBeEnabled();
		await expect(screen.factor(3)).toBeEnabled();
		await expect(screen.factor(4)).toBeEnabled();
		await expect(screen.factorHelp).toContainText('Enlarges the image with the selected factor using AI upscaling.');
		await expect(page.locator('input[name="background_type"]')).toHaveCount(0);
		await expect(screen.badBackgroundLink).toHaveCount(0);

		await expect(screen.account).toContainText('Image Upscale is limited on the Free plan');
		await expect(screen.account).not.toContainText('Background Removal');
		await expect(screen.account).toHaveAttribute('data-confirm-remove', 'Remove the saved ShortPixel API Key? Image upscale will fall back to the Free plan.');
		await expect(screen.apiKeySave).toHaveText('Activate');

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('the image width disables the factors it is too wide for', async ({ page, emr, consoleErrors }) => {
		const wide = await emr.uploadGenerated(1100, 200, { as: 'emr-e2e-wide-1100.jpg', title: 'Wide 1100' });
		const screen = new UpscaleScreen(page);
		await screen.goto(wide.id);
		await expect(screen.factor(2)).toBeChecked();
		await expect(screen.factor(3)).toBeEnabled();
		await expect(screen.factor(4)).toBeDisabled();
		await expect(screen.previewButton).toBeEnabled();

		const limit = await emr.uploadGenerated(1200, 200, { as: 'emr-e2e-wide-1200.jpg', title: 'Wide 1200' });
		await screen.goto(limit.id);
		await expect(screen.factor(2)).toBeDisabled();
		await expect(screen.factor(3)).toBeDisabled();
		await expect(screen.factor(4)).toBeDisabled();
		await expect(page.locator('input[name="upscale"]:checked')).toHaveCount(0);
		await expect(screen.factorHelp).toHaveText('This image is too big to be upscaled.');
		await expect(screen.previewButton).toBeDisabled();

		// Above the largest limit there is no entry point at all.
		const huge = await emr.uploadGenerated(1300, 200, { as: 'emr-e2e-wide-1300.jpg', title: 'Wide 1300' });
		const list = new MediaList(page);
		await list.goto();
		await expect(list.rowActionLink(huge.id, 'media_replace')).toHaveCount(1);
		await expect(list.rowActionLink(huge.id, 'remove_background')).toHaveCount(1);
		await expect(list.rowActionLink(huge.id, 'upscale_image')).toHaveCount(0);

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('Preview sends the factor, shows the result with its dimensions and enables Replace', async ({ page, emr, consoleErrors }) => {
		const screen = new UpscaleScreen(page);
		await screen.goto(emr.seed.attachment_id);
		await screen.factor(3).check();

		const answer = await preview(page, screen);
		expect(answer.success).toBe(true);
		expect(answer.image).toMatch(/^https:\/\/api\.shortpixel\.com\/f\/[0-9a-f]+-lossless\.jpg\?ts=\d+$/);
		expect(answer.settings).toBe(' plugin_version:EMR4.2.0  lossy:0  upscale:3 ');

		await expect(screen.resultImage).toHaveAttribute('src', answer.image as string);
		await expect
			.poll(() => screen.resultImage.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth === 50), {
				message: 'the result image (test-image.jpg, 50x50) renders',
			})
			.toBe(true);
		await expect(screen.resultDimensions).toHaveText('50 x 50');
		await expect(screen.resultKey).not.toHaveValue('');
		await expect(screen.replaceButton).toBeEnabled();
		await expect(screen.previewButton).toBeEnabled();

		const calls = await emr.mockRequests('free-reducer.php');
		expect(calls.length).toBe(1);
		expect(calls[0].request).toMatchObject({ upscale: 3, lossy: 0 });
		expect(calls[0].request).not.toHaveProperty('bg_remove');
		expect(decodeURIComponent(String((calls[0].request?.urllist as string[])[0]))).toBe(emr.seed.attachment_url);
		// An upscale remembers no options.
		expect((await emr.getOption('enable_media_replace')).exists).toBe(false);

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('Replace after a preview replaces the image with the result', async ({ page, emr, consoleErrors }) => {
		const id = emr.seed.attachment_id;
		const before = await emr.attachment(id);
		const screen = new UpscaleScreen(page);
		await screen.goto(id);
		const answer = await preview(page, screen);
		expect(answer.success).toBe(true);
		await expect(screen.replaceButton).toBeEnabled();

		await Promise.all([page.waitForURL(/action=do_upscale_replace/), screen.replaceButton.click()]);
		await expectSuccessThenRedirect(page, id);

		const after = await emr.attachment(id);
		expect(after.file).toBe(before.file);
		expect(after.file_exists).toBe(true);
		expect(after.metadata && after.metadata.width).toBe(50);
		expect(after.metadata && after.metadata.height).toBe(50);
		const served = await fetchBytes(page.request, after.url);
		const result = await fetchBytes(page.request, `${new URL(after.url).origin}/wp-content/plugins/enable-media-replace/tests/fixtures/test-image.jpg`);
		expect(result.status).toBe(200);
		expect(served.body.equals(result.body), 'the attachment holds the downloaded result').toBe(true);
		expect((await emr.mockRequests('/f/')).length, 'the result was downloaded from the API').toBeGreaterThan(0);
		await expect(new EditAttachment(page).thumbnailsBox).toBeAttached();

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('quota exceeded on the free plan: the upscale wording, no preview', async ({ page, emr, consoleErrors }) => {
		await emr.mock({ quotaExceeded: true });
		const screen = new UpscaleScreen(page);
		await screen.goto(emr.seed.attachment_id);

		const answer = await preview(page, screen);
		expect(answer.success).toBe(false);
		expect(answer.quota_exceeded).toBe(true);

		await expect(screen.notice).toBeVisible();
		await expect(screen.notice).toContainText(
			'Enable Media Replace: Quota exceeded. Please purchase the Unlimited or Unlimited AI plan and add your API key to continue upscaling images.',
		);
		await expect(screen.resultImage).toHaveCount(0);
		await expect(screen.replaceButton).toBeDisabled();

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});
});

test.describe('Upscale: feature off', () => {
	test('no row action, no meta box button, no links, the URL refused; remove background untouched', async ({ page, emr }) => {
		const id = emr.seed.attachment_id;
		// Sentinel first: with the feature on, the entry point exists.
		const list = new MediaList(page);
		await list.goto();
		await expect(list.rowActionLink(id, 'upscale_image')).toHaveCount(1);

		await emr.setFeatures({ upscale: false });

		await list.goto();
		await expect(list.rowActionLink(id, 'media_replace')).toHaveCount(1);
		await expect(list.rowActionLink(id, 'remove_background')).toHaveCount(1);
		await expect(list.rowActionLink(id, 'upscale_image')).toHaveCount(0);

		const edit = new EditAttachment(page);
		await edit.goto(id);
		await expect(edit.replaceButton).toBeVisible();
		await expect(edit.removeBackgroundButton).toBeVisible();
		await expect(edit.upscaleButton).toHaveCount(0);

		const replace = new ReplaceScreen(page);
		await replace.goto(id);
		await expect(replace.removeBackgroundLink).toBeVisible();
		await expect(replace.upscaleLink).toHaveCount(0);

		await page.goto(emrUrls.upscale(id));
		await expect(page.locator('body')).toContainText('Something went wrong loading page, please try again');
		await expect(new UpscaleScreen(page).form).toHaveCount(0);
	});
});
