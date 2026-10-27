/**
 * The remove-background journeys (views/prepare-remove-background.php,
 * js/remove_bg.js, the emr_* AJAX handlers, Api, ApiKeyManager and
 * RemoveBackGroundViewController::loadPost()), against the mock API:
 *   - the screen: previews, background type, colour, opacity;
 *   - the API key box: save, wrong key, non-Unlimited key, remove;
 *   - Preview: the AJAX call, the /f/ result rendered in the preview;
 *   - Replace: the success screen and the replaced file;
 *   - the replace screen's "remove the background after replacing" option;
 *   - the quota-exceeded (free) and plan-expired (keyed) notices;
 *   - the `background` feature off: no entry points, the URL refused.
 *
 * Every load of this screen throws the known emr_admin.js TypeError
 * (KNOWN_JS_DEFECTS.removeBackgroundLocationOption, pinned in
 * smoke.spec.ts), so the screen tests accept that error and nothing else.
 */
import { test, expect } from '../fixtures';
import { KNOWN_JS_DEFECTS, RemoveBackgroundScreen, ReplaceScreen, SuccessScreen, emrUrls, expectOnlyKnownErrors } from '../helpers/emr';
import { expectSuccessThenRedirect, fetchBytes, submitReplace } from '../helpers/journeys';
import { EditAttachment, MediaList } from '../helpers/media-list';
import type { Page } from '@playwright/test';

const KEY = 'E2EKEY0123456789ABCD';
const KNOWN = [KNOWN_JS_DEFECTS.removeBackgroundLocationOption];

/** Click Preview and wait for the emr_remove_background answer. */
async function preview(page: Page, screen: RemoveBackgroundScreen): Promise<Record<string, unknown>> {
	const answer = page.waitForResponse(
		(r) => r.url().includes('admin-ajax.php') && r.request().postData()?.includes('action=emr_remove_background') === true,
	);
	await screen.previewButton.click();
	const response = await answer;
	expect(response.status()).toBe(200);
	return (await response.json()) as Record<string, unknown>;
}

test.describe('Remove background', () => {
	test.use({ allowConsoleErrors: true });

	test('the screen shows the previews and the background options', async ({ page, emr, consoleErrors }) => {
		const screen = new RemoveBackgroundScreen(page);
		await screen.goto(emr.seed.attachment_id);

		const original = screen.originalPreview.locator('img');
		await expect(original).toBeVisible();
		await expect(original).toHaveAttribute('src', /\/emr-seed(-\d+x\d+)?\.jpg/);
		await expect
			.poll(() => original.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0), { message: 'the original preview loads' })
			.toBe(true);
		await expect(screen.resultImage).toHaveCount(0);
		await expect(screen.form.locator('input[name="ID"]')).toHaveValue(String(emr.seed.attachment_id));
		await expect(screen.resultKey).toHaveValue('');
		await expect(screen.replaceButton).toBeDisabled();
		await expect(screen.replaceInsteadLink).toHaveAttribute('href', /action=media_replace.*_wpnonce=/);

		// Defaults: transparent, white, fully opaque; the solid options hidden.
		await expect(screen.backgroundType('transparent')).toBeChecked();
		await expect(page.locator('#solid_selecter')).toBeHidden();
		await screen.backgroundType('solid').check();
		await expect(page.locator('#solid_selecter')).toBeVisible();
		await expect(screen.colorPicker).toHaveValue('#ffffff');
		await expect(screen.transparency).toHaveValue('100');

		await screen.colorPicker.fill('#ff0000');
		await expect(page.locator('#color_range')).toHaveText('#ff0000');
		await expect(screen.color).toHaveValue('#ff0000');
		await screen.transparency.fill('40');
		await expect(page.locator('#transparency_range')).toHaveText('40');

		await screen.backgroundType('transparent').check();
		await expect(page.locator('#solid_selecter')).toBeHidden();

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('API key: a valid Unlimited key is saved, then removed', async ({ page, emr, consoleErrors }) => {
		const screen = new RemoveBackgroundScreen(page);
		await screen.goto(emr.seed.attachment_id);
		await expect(screen.account).toContainText('limited to 100 images on the Free plan');

		await screen.apiKeyInput.fill(KEY);
		await screen.apiKeySave.click();
		await expect(screen.apiKeyNotice).toHaveText('API Key saved and verified successfully. You now have unlimited background removal.');
		await expect(screen.apiKeyNotice).toHaveClass(/notice-success/);
		// The page reloads itself; the connected-key card replaces the form.
		await expect(screen.maskedKey).toHaveText('E2EK************ABCD');
		await expect(screen.account).toContainText('Your ShortPixel API Key is connected');
		expect((await emr.getOption<string>('emr_shortpixel_api_key')).value).toBe(KEY);

		page.once('dialog', (dialog) => {
			expect(dialog.type()).toBe('confirm');
			expect(dialog.message()).toContain('Remove the saved ShortPixel API Key?');
			void dialog.accept();
		});
		await screen.removeKey.click();
		await expect(screen.apiKeyNotice).toHaveText('API Key removed.');
		// Reloaded: the form for a new key is back.
		await expect(screen.apiKeySave).toHaveText('Activate');
		await expect(screen.maskedKey).toHaveCount(0);
		expect((await emr.getOption('emr_shortpixel_api_key')).exists).toBe(false);

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('API key: a wrong key and a key without the Unlimited plan are refused with their messages', async ({ page, emr, consoleErrors }) => {
		const screen = new RemoveBackgroundScreen(page);
		await screen.goto(emr.seed.attachment_id);

		await emr.mock({ apiStatusCode: -401, apiStatusMessage: 'Wrong API Key' });
		await screen.apiKeyInput.fill(KEY);
		await screen.apiKeySave.click();
		await expect(screen.apiKeyNotice).toHaveText('The API Key is not correct. Please try again.');
		await expect(screen.apiKeyNotice).toHaveClass(/notice-error/);
		await expect(screen.apiKeySave).toBeEnabled();
		expect((await emr.getOption('emr_shortpixel_api_key')).exists).toBe(false);

		await emr.mock({ apiStatusCode: 2, unlimited: 'false' });
		await screen.apiKeySave.click();
		await expect(screen.apiKeyNotice).toContainText('to the Unlimited or Unlimited AI plan to unlock unlimited background removal.');
		await expect(screen.apiKeyNotice.getByRole('link', { name: 'upgrade' })).toHaveAttribute('href', 'https://shortpixel.com/pricing');
		expect((await emr.getOption('emr_shortpixel_api_key')).exists).toBe(false);

		// A too-short key never reaches the API.
		await screen.apiKeyInput.fill('SHORT');
		await screen.apiKeySave.click();
		await expect(screen.apiKeyNotice).toHaveText('The API Key must be exactly 20 characters.');
		expect((await emr.mockRequests('api-status.php')).length).toBe(2);

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('Preview shows the API result and enables Replace', async ({ page, emr, consoleErrors }) => {
		const screen = new RemoveBackgroundScreen(page);
		await screen.goto(emr.seed.attachment_id);
		await screen.backgroundType('solid').check();
		await screen.colorPicker.fill('#ff0000');
		await screen.transparency.fill('40');

		const answer = await preview(page, screen);
		expect(answer.success).toBe(true);
		expect(answer.image).toMatch(/^https:\/\/api\.shortpixel\.com\/f\/[0-9a-f]+-lossless\.jpg\?ts=\d+$/);

		await expect(screen.resultImage).toHaveAttribute('src', answer.image as string);
		await expect
			.poll(() => screen.resultImage.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth === 50), {
				message: 'the result image (test-image.jpg, 50x50) renders',
			})
			.toBe(true);
		await expect(screen.resultPreview).toHaveClass(/is_image/);
		await expect(screen.resultKey).not.toHaveValue('');
		await expect(screen.replaceButton).toBeEnabled();
		await expect(screen.previewButton).toBeEnabled();
		await expect(screen.badBackgroundLink).toHaveAttribute('href', /^mailto:support@shortpixel\.com\?.*bg_remove:#ff000040/);

		// Free path: the reducer got the source URL and the colour with the alpha suffix.
		const calls = await emr.mockRequests('free-reducer.php');
		expect(calls.length).toBe(1);
		expect(calls[0].request).toMatchObject({ bg_remove: '#ff000040', lossy: 0 });
		expect(decodeURIComponent(String((calls[0].request?.urllist as string[])[0]))).toBe(emr.seed.attachment_url);
		// The options are remembered for the next visit.
		await screen.goto(emr.seed.attachment_id);
		await expect(screen.backgroundType('solid')).toBeChecked();
		await expect(screen.colorPicker).toHaveValue('#ff0000');
		await expect(screen.transparency).toHaveValue('40');

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('Replace after a preview replaces the image with the result', async ({ page, emr, consoleErrors }) => {
		const id = emr.seed.attachment_id;
		const before = await emr.attachment(id);
		const screen = new RemoveBackgroundScreen(page);
		await screen.goto(id);
		const answer = await preview(page, screen);
		expect(answer.success).toBe(true);
		await expect(screen.replaceButton).toBeEnabled();

		await Promise.all([page.waitForURL(/action=do_background_replace/), screen.replaceButton.click()]);
		await expectSuccessThenRedirect(page, id);

		const after = await emr.attachment(id);
		expect(after.file).toBe(before.file);
		expect(after.file_exists).toBe(true);
		expect(after.metadata && after.metadata.width).toBe(50);
		expect(after.metadata && after.metadata.height).toBe(50);
		expect(after.metadata).not.toEqual(before.metadata);
		// The served file is the mock's result (tests/fixtures/test-image.jpg).
		const served = await fetchBytes(page.request, after.url);
		const result = await fetchBytes(page.request, `${new URL(after.url).origin}/wp-content/plugins/enable-media-replace/tests/fixtures/test-image.jpg`);
		expect(result.status).toBe(200);
		expect(served.body.equals(result.body), 'the attachment holds the downloaded result').toBe(true);
		expect((await emr.mockRequests('/f/')).length, 'the result was downloaded from the API').toBeGreaterThan(0);
		await expect(new EditAttachment(page).thumbnailsBox).toBeAttached();

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('quota exceeded on the free plan: a dismissible notice, no preview', async ({ page, emr, consoleErrors }) => {
		await emr.mock({ quotaExceeded: true });
		const screen = new RemoveBackgroundScreen(page);
		await screen.goto(emr.seed.attachment_id);

		const answer = await preview(page, screen);
		expect(answer.success).toBe(false);
		expect(answer.quota_exceeded).toBe(true);

		await expect(screen.notice).toBeVisible();
		await expect(screen.notice).toContainText(
			'Enable Media Replace: Quota exceeded. Please purchase the Unlimited or Unlimited AI plan and add your API key to continue removing backgrounds.',
		);
		await expect(screen.resultImage).toHaveCount(0);
		await expect(screen.resultError).toHaveCount(0);
		await expect(screen.replaceButton).toBeDisabled();
		await expect(screen.previewButton).toBeEnabled();

		await screen.notice.locator('.notice-dismiss').click();
		await expect(screen.notice).toBeHidden();

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('plan expired with a stored key: the renew notice, no reducer call', async ({ page, emr, consoleErrors }) => {
		await emr.setApiKey(KEY);
		await emr.mock({ unlimited: 'false' });
		const screen = new RemoveBackgroundScreen(page);
		await screen.goto(emr.seed.attachment_id);
		await expect(screen.maskedKey).toHaveText('E2EK************ABCD');

		const answer = await preview(page, screen);
		expect(answer.success).toBe(false);
		expect(answer.plan_expired).toBe(true);

		await expect(screen.notice).toBeVisible();
		await expect(screen.notice).toContainText('Your Unlimited plan is no longer active. Please renew at shortpixel.com/pricing or remove your API Key to use the Free plan.');
		await expect(screen.notice.getByRole('link', { name: 'shortpixel.com/pricing' })).toHaveAttribute('href', 'https://shortpixel.com/pricing');
		await expect(screen.resultImage).toHaveCount(0);
		await expect(screen.replaceButton).toBeDisabled();

		expect((await emr.mockRequests('api-status.php')).length).toBe(1);
		expect(await emr.mockRequests('reducer.php')).toEqual([]);

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});

	test('an API error message is shown in the result panel', async ({ page, emr, consoleErrors }) => {
		await emr.mock({ forceStatusCode: '-201', errorMessage: 'Invalid image (E2E)' });
		const screen = new RemoveBackgroundScreen(page);
		await screen.goto(emr.seed.attachment_id);

		const answer = await preview(page, screen);
		expect(answer.success).toBe(false);
		await expect(screen.resultError).toHaveText('Invalid image (E2E)');
		await expect(screen.notice).toBeHidden();
		await expect(screen.replaceButton).toBeDisabled();

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});
	test('"remove the background after replacing" sends the success redirect to this screen', async ({ page, emr, consoleErrors }) => {
		const id = emr.seed.attachment_id;
		const replace = new ReplaceScreen(page);
		await replace.goto(id);
		await expect(replace.removeBackgroundLink).toBeVisible();
		await replace.removeAfter.check();
		await replace.chooseFile(await emr.generatedFile(640, 480, { name: 'emr-e2e-then-remove.jpg' }));
		await submitReplace(replace);

		const success = new SuccessScreen(page);
		await expect(success.heading).toBeVisible();
		await expect(success.continueLink).toHaveAttribute('href', new RegExp(`action=emr_prepare_remove&(amp;)?attachment_id=${id}`));
		await page.waitForURL(/action=emr_prepare_remove/, { timeout: 20_000 });
		const screen = new RemoveBackgroundScreen(page);
		await expect(screen.form).toBeVisible();
		await expect(screen.form.locator('input[name="ID"]')).toHaveValue(String(id));
		expect((await emr.attachment(id)).metadata).toMatchObject({ width: 640, height: 480 });

		expectOnlyKnownErrors(consoleErrors, KNOWN);
	});
});

test.describe('Remove background: feature off', () => {
	test('no row action, no meta box button, no replace-screen link, and the URL is refused', async ({ page, emr }) => {
		// Sentinel first: with the feature on, the entry points exist.
		const list = new MediaList(page);
		await list.goto();
		await expect(list.rowActionLink(emr.seed.attachment_id, 'remove_background')).toHaveCount(1);

		await emr.setFeatures({ background: false });

		await list.goto();
		await expect(list.rowActionLink(emr.seed.attachment_id, 'media_replace')).toHaveCount(1);
		await expect(list.rowActionLink(emr.seed.attachment_id, 'remove_background')).toHaveCount(0);

		const edit = new EditAttachment(page);
		await edit.goto(emr.seed.attachment_id);
		await expect(edit.replaceButton).toBeVisible();
		await expect(edit.removeBackgroundButton).toHaveCount(0);

		const replace = new ReplaceScreen(page);
		await replace.goto(emr.seed.attachment_id);
		await expect(replace.removeBackgroundLink).toHaveCount(0);
		await expect(replace.removeAfter).toHaveCount(0);

		await page.goto(emrUrls.removeBackground(emr.seed.attachment_id));
		await expect(page.locator('body')).toContainText('Something went wrong loading page, please try again');
		await expect(new RemoveBackgroundScreen(page).form).toHaveCount(0);
	});
});
