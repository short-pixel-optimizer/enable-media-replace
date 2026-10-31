/**
 * The replace entry point inside the editors' media modal (the manual
 * plan's "Replace" from an image in a post):
 *   - Block editor: select the image block of the seed post, toolbar
 *     "Replace" › "Open Media Library"; the modal opens (on its Upload tab),
 *     the library tab and the attachment's details show EMR's compat fields; "Upload a new
 *     file" opens the replace screen for that attachment.
 *   - Classic editor (block editor turned off by the support switch, the
 *     way the Classic Editor plugin does it): click the image in TinyMCE,
 *     its toolbar "Edit" opens Image Details, "Replace" opens the library
 *     on the current attachment with the same compat fields.
 * Both end on the replace screen of the seed attachment. The two API
 * screens are not clicked through here (replace-entry.spec.ts checks their
 * links from the same fields).
 */
import type { FrameLocator, Locator, Page } from '@playwright/test';
import { test, expect } from '../fixtures';
import { ReplaceScreen, emrQuery } from '../helpers/emr';
import { adminUrls } from '../helpers/wp-admin';

/**
 * The media modal opens on its "Upload files" tab when nothing is selected
 * in the library state: switch to the library and pick the attachment, which
 * shows the details sidebar with EMR's compat fields.
 */
async function pickInLibrary(page: Page, attachmentId: number): Promise<Locator> {
	const modal = page.locator('.media-modal');
	await expect(modal).toBeVisible();
	await modal.getByRole('tab', { name: 'Media Library' }).click();
	const tile = modal.locator(`li.attachment[data-id="${attachmentId}"]`);
	await expect(tile).toBeVisible();
	// The library re-renders its tiles while the first page of attachments
	// settles, which can swallow a click that landed on the old element; a
	// click that did not stick is repeated, with the keyboard as a fallback
	// (the tile is a checkbox: Enter / Space toggle it).
	for (let attempt = 0; attempt < 4 && !(await tile.getAttribute('aria-checked').then((v) => v === 'true')); attempt++) {
		if (attempt < 2) {
			await tile.locator('.attachment-preview').click();
		} else {
			await tile.focus();
			await tile.press('Enter');
		}
		await tile.locator(':scope.selected').waitFor({ state: 'attached', timeout: 2_000 }).catch(() => undefined);
	}
	await expect(tile).toHaveClass(/selected/);
	await expect(modal.locator('.attachment-details')).toBeVisible();
	return modal;
}

/** EMR's "Upload a new file" compat field inside the open media modal. */
function modalReplaceButton(page: Page): Locator {
	return page.locator('.media-modal tr.compat-field-enable-media-replace').getByRole('link', { name: 'Upload a new file' });
}

async function expectReplaceScreenFor(page: Page, attachmentId: number): Promise<void> {
	expect(emrQuery(page.url())).toEqual({ page: 'enable-media-replace/enable-media-replace.php', action: 'media_replace', attachmentId });
	const screen = new ReplaceScreen(page);
	await expect(screen.heading).toBeVisible();
	await expect(screen.form.locator('input[name="ID"]')).toHaveValue(String(attachmentId));
}

test.describe('Editors: Replace from the media modal', () => {
	test('block editor: Replace › Open Media Library shows the EMR fields and Upload a new file opens the replace screen', async ({ page, emr }) => {
		await page.goto(adminUrls.editPost(emr.seed.post_id));
		await expect(page.locator('.editor-header, .edit-post-header')).toBeVisible({ timeout: 30_000 });

		// The welcome guide of a first visit opens a moment after the header
		// renders and its overlay swallows every click; closing it is a
		// one-off per user (a persisted preference), so later runs wait the
		// few seconds for nothing.
		const guide = page.getByRole('dialog', { name: 'Welcome to the editor' });
		if (await guide.waitFor({ state: 'visible', timeout: 5_000 }).then(() => true, () => false)) {
			await guide.getByRole('button', { name: 'Close' }).click();
			await expect(guide).toBeHidden();
		}

		// The canvas is an iframe on recent cores, the page itself on older ones.
		const canvasFrame = page.locator('iframe[name="editor-canvas"]');
		const canvas: Page | FrameLocator = (await canvasFrame.count()) ? page.frameLocator('iframe[name="editor-canvas"]') : page;
		const block = canvas.locator('.wp-block-image').first();
		await expect(block).toBeVisible({ timeout: 30_000 });
		await block.click(); // the block wrapper takes the pointer events, not the img

		await page.getByRole('button', { name: 'Replace', exact: true }).click();
		await page.getByRole('menuitem', { name: 'Open Media Library' }).click();

		const modal = await pickInLibrary(page, emr.seed.attachment_id);
		await expect(modal.locator('tr.compat-field-emr-remove-background')).toBeVisible();
		await expect(modal.locator('tr.compat-field-emr-upscale')).toBeVisible();

		page.on('dialog', (dialog) => dialog.accept()); // a "leave page" prompt, should the editor consider the post dirty
		await Promise.all([page.waitForURL(/action=media_replace/), modalReplaceButton(page).click()]);
		await expectReplaceScreenFor(page, emr.seed.attachment_id);
	});

	test('classic editor: the image toolbar Edit › Replace shows the EMR fields and Upload a new file opens the replace screen', async ({ page, emr }) => {
		await emr.setClassicEditor(true);
		await page.goto(adminUrls.editPost(emr.seed.post_id));
		await expect(page.locator('#post')).toBeVisible();

		// Visual mode (the default for a fresh user); the image sits in TinyMCE's iframe.
		const visualTab = page.locator('#content-tmce');
		if (await visualTab.isVisible()) {
			await visualTab.click();
		}
		const editor = page.frameLocator('#content_ifr');
		const image = editor.locator('img').first();
		await expect(image).toBeVisible({ timeout: 30_000 });
		await image.click();

		// TinyMCE's inline image toolbar (wpeditimage): Edit opens Image Details.
		// Every image in the document owns a toolbar; only the clicked image's one is shown.
		await page.locator('.mce-inline-toolbar-grp div[aria-label="Edit"]:visible').first().click();
		const details = page.getByRole('dialog', { name: 'Image details' });
		await expect(details).toBeVisible();
		await details.getByRole('button', { name: 'Replace', exact: true }).click();

		const modal = await pickInLibrary(page, emr.seed.attachment_id);
		await expect(modal.locator('tr.compat-field-emr-remove-background')).toBeVisible();

		page.on('dialog', (dialog) => dialog.accept());
		await Promise.all([page.waitForURL(/action=media_replace/), modalReplaceButton(page).click()]);
		await expectReplaceScreenFor(page, emr.seed.attachment_id);
	});
});
