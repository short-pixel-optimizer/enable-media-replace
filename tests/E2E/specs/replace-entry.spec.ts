/**
 * Entry points to the replace screen:
 *   - the "Replace media" row action of the Media Library list view;
 *   - the "Upload a new file" button of the edit-attachment "Replace Media"
 *     meta box;
 *   - the three buttons of the attachment details modal of the Media
 *     Library grid view (the same compat fields the editors' media modal
 *     shows);
 *   - permissions: an author who does not own the attachment gets no row
 *     action and the error screen on the direct URL
 *     (EnableMediaReplacePlugin::checkImagePermission(), edit_post);
 *   - a logged-out visitor is sent to wp-login.php.
 */
import { test, expect, newVisitorContext } from '../fixtures';
import { ErrorScreen, ReplaceScreen, emrQuery, emrUrls } from '../helpers/emr';
import { loginAs } from '../helpers/journeys';
import { EditAttachment, MediaGrid, MediaList } from '../helpers/media-list';

test.describe('Replace entry points', () => {
	test('the Replace media row action opens the replace screen for that attachment', async ({ page, emr }) => {
		// A second attachment, so the click has to pick the right row.
		const other = await emr.uploadGenerated(320, 240, { as: 'emr-e2e-other.jpg', title: 'EMR other image' });
		expect(other.id).not.toBe(emr.seed.attachment_id);

		const list = new MediaList(page);
		await list.goto();
		const action = await list.rowAction(emr.seed.attachment_id, 'media_replace');
		await expect(action).toHaveText('Replace media');

		await Promise.all([page.waitForURL(/action=media_replace/), action.click()]);
		expect(emrQuery(page.url())).toEqual({
			page: 'enable-media-replace/enable-media-replace.php',
			action: 'media_replace',
			attachmentId: emr.seed.attachment_id,
		});

		const screen = new ReplaceScreen(page);
		await expect(screen.heading).toBeVisible();
		await expect(screen.form.locator('input[name="ID"]')).toHaveValue(String(emr.seed.attachment_id));
		await expect(page.locator('.explainer b.underline')).toHaveText('emr-seed.jpg');
	});

	test('the Upload a new file button of the edit-attachment meta box opens the replace screen', async ({ page, emr }) => {
		const edit = new EditAttachment(page);
		await edit.goto(emr.seed.attachment_id);
		await expect(edit.replaceBox).toBeVisible();
		await expect(edit.replaceBox).toContainText('Replace Media');
		// The thumbnails box only follows a replace.
		await expect(edit.thumbnailsBox).toHaveCount(0);

		const href = await edit.replaceButton.getAttribute('href');
		expect(href, 'the meta box button carries a media_replace nonce').toMatch(/_wpnonce=[0-9a-f]+/);

		await Promise.all([page.waitForURL(/action=media_replace/), edit.replaceButton.click()]);
		expect(emrQuery(page.url()).attachmentId).toBe(emr.seed.attachment_id);

		const screen = new ReplaceScreen(page);
		await expect(screen.heading).toBeVisible();
		await expect(screen.form.locator('input[name="ID"]')).toHaveValue(String(emr.seed.attachment_id));
	});

	test('the grid view attachment details offer the three buttons, and Upload a new file opens the replace screen', async ({ page, emr }) => {
		const grid = new MediaGrid(page);
		await grid.goto();
		await grid.open(emr.seed.attachment_id);

		// The three compat fields EMR adds (the seed is an 800 px JPEG: removable and upscalable).
		await expect(grid.replaceButton).toBeVisible();
		await expect(grid.removeBackgroundButton).toBeVisible();
		await expect(grid.upscaleButton).toBeVisible();

		// The two API screens are reached through nonced links (their load
		// throws the known emr_admin.js error, pinned in smoke.spec.ts, so
		// they are not clicked through here; their specs cover the screens).
		const removeHref = (await grid.removeBackgroundButton.getAttribute('href')) || '';
		expect(emrQuery(removeHref)).toEqual({ page: 'enable-media-replace/enable-media-replace.php', action: 'emr_prepare_remove', attachmentId: emr.seed.attachment_id });
		expect(removeHref).toMatch(/_wpnonce=[0-9a-f]+/);
		const upscaleHref = (await grid.upscaleButton.getAttribute('href')) || '';
		expect(emrQuery(upscaleHref)).toEqual({ page: 'enable-media-replace/enable-media-replace.php', action: 'emr_prepare_upscale', attachmentId: emr.seed.attachment_id });
		expect(upscaleHref).toMatch(/_wpnonce=[0-9a-f]+/);

		await Promise.all([page.waitForURL(/action=media_replace/), grid.replaceButton.click()]);
		expect(emrQuery(page.url()).attachmentId).toBe(emr.seed.attachment_id);
		const screen = new ReplaceScreen(page);
		await expect(screen.heading).toBeVisible();
		await expect(screen.form.locator('input[name="ID"]')).toHaveValue(String(emr.seed.attachment_id));
	});

	test('an author who does not own the attachment gets no row action and the error screen', async ({ browser, emr }) => {
		const author = await emr.createUser('author', 'emr-e2e-author');
		// Sentinel: the author's OWN attachment does get the row action, so the
		// missing one on the seed is the permission check, not a broken list.
		const own = await emr.uploadGenerated(300, 200, { as: 'emr-e2e-author-own.jpg', title: 'Author own image', author: author.id });

		const session = await loginAs(browser, author.login);
		try {
			const list = new MediaList(session.page);
			await list.goto();
			await expect(list.row(emr.seed.attachment_id)).toBeVisible();
			await expect(list.row(own.id)).toBeVisible();
			await expect(list.rowActionLink(own.id, 'media_replace')).toHaveCount(1);
			await expect(list.rowActionLink(emr.seed.attachment_id, 'media_replace')).toHaveCount(0);
			// Author restricted: no remove background, even on the own image.
			await expect(list.rowActionLink(own.id, 'remove_background')).toHaveCount(0);

			await session.page.goto(emrUrls.replace(emr.seed.attachment_id));
			const error = new ErrorScreen(session.page);
			await expect(error.title).toBeVisible();
			await expect(error.message).toHaveText("You don't have permission to edit this image");
			await expect(new ReplaceScreen(session.page).form).toHaveCount(0);

			expect(session.errors, 'JS errors in the author session').toEqual([]);
		} finally {
			await session.context.close();
		}
	});

	test('a logged-out visitor is sent to wp-login.php', async ({ browser, emr }) => {
		// Explicitly empty: the project's admin storageState would otherwise apply.
		const context = await newVisitorContext(browser, { storageState: { cookies: [], origins: [] } });
		try {
			const page = await context.newPage();
			await page.goto(emrUrls.replace(emr.seed.attachment_id));
			await expect(page).toHaveURL(/\/wp-login\.php\?redirect_to=/);
			const redirectTo = new URL(page.url()).searchParams.get('redirect_to') || '';
			expect(emrQuery(redirectTo).attachmentId).toBe(emr.seed.attachment_id);
			await expect(page.locator('#loginform')).toBeVisible();
		} finally {
			await context.close();
		}
	});
});
