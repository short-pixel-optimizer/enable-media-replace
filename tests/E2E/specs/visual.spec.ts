/**
 * Screenshot regression of EMR's two screens, `visual` project only
 * (Chromium, inside the pinned Playwright image; a native run skips the
 * comparison, see ignoreSnapshots in playwright.config.ts).
 *
 * Captures EMR's own container, not the page. The two dates of the replace
 * screen's date options ("current date (...)", "Keep the date (...)") change
 * every run and are masked. Baselines:
 * tests/E2E/snapshots/visual.spec.ts/{replace-screen,remove-background-screen}.png,
 * refreshed with `bin/test-e2e.sh --project visual --update-snapshots`.
 */
import { test, expect } from '../fixtures';
import { KNOWN_JS_DEFECTS, RemoveBackgroundScreen, ReplaceScreen, expectOnlyKnownErrors } from '../helpers/emr';
import type { Locator } from '@playwright/test';

/** Every <img> under `root` has finished loading with pixels. */
async function imagesLoaded(root: Locator): Promise<void> {
	await expect
		.poll(
			() =>
				root.locator('img').evaluateAll((images) =>
					images.every((img) => (img as HTMLImageElement).complete && (img as HTMLImageElement).naturalWidth > 0),
				),
			{ message: 'every image of the screen loads' },
		)
		.toBe(true);
}

test.describe('Visual', () => {
	test('replace screen', async ({ page, emr }) => {
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);
		const root = page.locator('.emr_upload_form');
		await imagesLoaded(root);

		await expect(root).toHaveScreenshot('replace-screen.png', {
			mask: [page.locator('.option.timestamp .small')],
		});
	});

	test.describe('remove-background screen', () => {
		// The known emr_admin.js error of this screen (pinned in smoke.spec.ts).
		test.use({ allowConsoleErrors: true });

		test('remove-background screen', async ({ page, emr, consoleErrors }) => {
			const screen = new RemoveBackgroundScreen(page);
			await screen.goto(emr.seed.attachment_id);
			const root = page.locator('#remove-background-form');
			await imagesLoaded(root);

			await expect(root).toHaveScreenshot('remove-background-screen.png');
			expectOnlyKnownErrors(consoleErrors, [KNOWN_JS_DEFECTS.removeBackgroundLocationOption]);
		});
	});
});
