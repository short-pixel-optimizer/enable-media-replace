/**
 * Smoke gate: proves the harness end to end.
 *   1. the provisioned install accepts the admin login and lands on the
 *      dashboard;
 *   2. the Media Library list shows EMR's "Replace media" row action for
 *      the seeded attachment, pointing at the replace screen;
 *   3. the replace screen renders the seeded attachment's thumbnail;
 *   4. the support endpoint resets, seeds, uploads and reports;
 *   5. the mock answers api-status.php: saving an API key on the
 *      remove-background screen goes through the REAL ajax handler and
 *      ApiKeyManager, and the key is stored;
 *   6. the console-error tripwire really fails a test.
 * It also pins the JS error the remove-background screen throws on load.
 */
import { test, expect } from '../fixtures';
import { KNOWN_JS_DEFECTS, RemoveBackgroundScreen, ReplaceScreen, emrQuery, expectOnlyKnownErrors } from '../helpers/emr';
import { MediaList } from '../helpers/media-list';
import { adminUrls, login } from '../helpers/wp-admin';

test.describe('Smoke: login', () => {
	// A fresh, logged-out session: this test drives the real login form.
	test.use({ storageState: { cookies: [], origins: [] } });

	test('the admin login lands on the dashboard', async ({ page }) => {
		await login(page);
		await expect(page).toHaveURL(/\/wp-admin\/?$/);
		await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
		await expect(page.locator('#wp-admin-bar-my-account')).toContainText(/admin/i);
	});
});

test.describe('Smoke', () => {
	test('the media list shows the Replace media row action for the seeded attachment', async ({ page, emr }) => {
		const list = new MediaList(page);
		await list.goto();

		await expect(list.row(emr.seed.attachment_id)).toContainText('EMR seed image');
		const action = await list.rowAction(emr.seed.attachment_id, 'media_replace');
		await expect(action).toBeVisible();
		await expect(action).toHaveText('Replace media');

		const href = await action.getAttribute('href');
		expect(href, 'the row action must link somewhere').not.toBeNull();
		expect(emrQuery(href as string)).toEqual({
			page: 'enable-media-replace/enable-media-replace.php',
			action: 'media_replace',
			attachmentId: emr.seed.attachment_id,
		});
	});

	test('the replace screen loads with the seeded attachment thumbnail', async ({ page, emr }) => {
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);

		await expect(screen.form.locator('input[name="ID"]')).toHaveValue(String(emr.seed.attachment_id));

		// The current-file panel shows the seeded image, rendered (not a broken <img>).
		const thumbnail = screen.currentThumbnail;
		await expect(thumbnail).toBeVisible();
		await expect(thumbnail).toHaveAttribute('src', /\/emr-seed(-\d+x\d+)?\.jpg/);
		await expect
			.poll(() => thumbnail.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0), {
				message: 'the seeded thumbnail must load',
			})
			.toBe(true);

		// The upload panel and the defaults the screen documents.
		await expect(screen.fileInput).toBeAttached();
		await expect(screen.submit).toBeDisabled();
		await expect(screen.replaceType('replace')).toBeChecked();
		// Default date mode: keep the date (TIME_UPDATEMODIFIED).
		await expect(screen.timestamp(2)).toBeChecked();
	});

	test('the support endpoint resets, seeds, uploads and reports', async ({ emr }) => {
		const first = emr.seed;
		const second = await emr.reset();
		expect(second.attachment_id, 'reset recreates the seed attachment').not.toBe(first.attachment_id);
		expect(second.attachment_url).toMatch(/\/wp-content\/uploads\/\d{4}\/\d{2}\/emr-seed\.jpg$/);

		const seeded = await emr.attachment(second.attachment_id);
		expect(seeded.file_exists).toBe(true);
		expect(seeded.mime).toBe('image/jpeg');
		expect(seeded.metadata && seeded.metadata.width).toBe(800);
		expect(seeded.metadata && Object.keys(seeded.metadata.sizes || {})).toEqual(
			expect.arrayContaining(['thumbnail', 'medium', 'medium_large']),
		);

		const post = await emr.postContent(second.post_id);
		expect(post.status).toBe('publish');
		expect(post.content).toContain(second.attachment_url);
		expect(post.content).toContain(`wp-image-${second.attachment_id}`);

		const upload = await emr.uploadFixture('codeispoetry.png');
		expect(upload.mime).toBe('image/png');
		expect(upload.url).toMatch(/codeispoetry\.png$/);

		// Clean slate for the mock and the EMR options.
		expect(await emr.mockRequests()).toEqual([]);
		expect((await emr.getOption('emr_shortpixel_api_key')).exists).toBe(false);
		expect((await emr.mockKnobs()).apiStatusCode).toBe(2);
	});
});

test.describe('Smoke: remove-background screen', () => {
	// The screen throws a known JS error on every load (see
	// KNOWN_JS_DEFECTS.removeBackgroundLocationOption and the pin below);
	// these tests accept that one error and nothing else.
	test.use({ allowConsoleErrors: true });

	test('the mock answers api-status.php when an API key is saved on the remove-background screen', async ({ page, emr, consoleErrors }) => {
		const key = 'E2EKEY0123456789ABCD';
		expect(key).toHaveLength(20);

		const screen = new RemoveBackgroundScreen(page);
		await screen.goto(emr.seed.attachment_id);
		await expect(screen.account).toBeVisible();
		await expect(screen.maskedKey).toHaveCount(0);

		await screen.apiKeyInput.fill(key);
		await screen.apiKeySave.click();
		await expect(screen.apiKeyNotice).toContainText('API Key saved and verified successfully');

		// The page reloads itself after a successful save; the connected-key
		// card replaces the form.
		await expect(screen.maskedKey).toHaveText('E2EK************ABCD');

		const stored = await emr.getOption<string>('emr_shortpixel_api_key');
		expect(stored).toEqual({ name: 'emr_shortpixel_api_key', exists: true, value: key });

		const calls = await emr.mockRequests('api-status.php');
		expect(calls.length, 'one validation call to api-status.php').toBe(1);
		expect(calls[0].host).toBe('api.shortpixel.com');
		expect(calls[0].request).toMatchObject({ key, DomainCheck: 'http://localhost:8031' });

		expectOnlyKnownErrors(consoleErrors, [KNOWN_JS_DEFECTS.removeBackgroundLocationOption]);
	});

	/**
	 * js/emr_admin.js runs on the remove-background screen too
	 * (EnableMediaReplacePlugin::route() enqueues it for every action), and
	 * its init() ends with showReplaceOptions(), which calls
	 * `document.querySelector('section.options .location_option').classList`
	 * without a null check. The remove-background screen
	 * (views/prepare-remove-background.php) has no `.location_option`, so
	 * every load throws an uncaught TypeError. The correct behaviour is a
	 * silent load: guard the element (and the checked replace_type radio)
	 * against null, or enqueue emr_admin.js on the replace screen only.
	 *
	 * How to recognise the fix: the load no longer throws, `consoleErrors`
	 * stays empty and the assertion below fails; then drop this pin, the
	 * entry in KNOWN_JS_DEFECTS and the allowConsoleErrors of this describe.
	 */
	test('emr_admin.js throws on the remove-background screen load, pinned_for_deferred_fix', async ({ page, emr, consoleErrors }) => {
		const screen = new RemoveBackgroundScreen(page);
		await screen.goto(emr.seed.attachment_id);
		// Sentinel: the screen really rendered.
		await expect(screen.previewButton).toBeVisible();

		await expect
			.poll(() => consoleErrors.filter((e) => KNOWN_JS_DEFECTS.removeBackgroundLocationOption.test(e)).length, {
				message: 'the showReplaceOptions TypeError from emr_admin.js',
			})
			.toBeGreaterThan(0);
		expectOnlyKnownErrors(consoleErrors, [KNOWN_JS_DEFECTS.removeBackgroundLocationOption]);
	});
});

/** Fire an uncaught error on the page, asynchronously (like a real bug would). */
async function injectUncaughtError(page: import('@playwright/test').Page): Promise<void> {
	await page.goto(adminUrls.dashboard);
	await page.evaluate(() => {
		setTimeout(() => {
			throw new Error('emr-e2e tripwire probe');
		}, 0);
	});
}

test.describe('Smoke: tripwire proof of life', () => {
	test.describe('capture', () => {
		// Opt out of teardown enforcement so this test can PASS while asserting
		// that the fixture actually CAPTURED the injected error. If the
		// tripwire ever goes blind, this test fails.
		test.use({ allowConsoleErrors: true });

		test('the tripwire captures an injected uncaught error', async ({ page, consoleErrors }) => {
			await injectUncaughtError(page);
			await expect.poll(() => consoleErrors.length, { timeout: 5_000 }).toBeGreaterThan(0);
			expect(consoleErrors.join('\n')).toContain('emr-e2e tripwire probe');
		});
	});

	test('the tripwire fails a test that leaks an uncaught error', async ({ page, consoleErrors }) => {
		// Expected to FAIL at fixture teardown. Playwright reports an expected
		// failure as OK and an unexpected PASS as a failure, so if enforcement
		// ever stops working, this test goes red.
		test.fail(true, 'proof of life: the tripwire must turn the injected error into a failure');
		await injectUncaughtError(page);
		// Completion signal: the error has been captured (no timer).
		await expect.poll(() => consoleErrors.length, { timeout: 5_000 }).toBeGreaterThan(0);
	});
});
