/**
 * The replace journeys, through the real form (views/screen.php,
 * js/emr_admin.js) and UploadViewController / ReplaceController:
 *   - `replace` with a same-type upload: success screen, countdown redirect
 *     to the edit screen with emr_replaced=1, the "Replaced Thumbnails
 *     Preview" box, the new bytes under the old name;
 *   - `replace` with a type WordPress refuses (SVG): the screen warns, the
 *     POST ends on the error screen with the security message;
 *   - `replace` with another allowed type (PNG over JPEG): the screen warns;
 *   - `replace_and_search` with another file name: renamed file, links in
 *     a post rewritten, old file gone;
 *   - `replace_and_search` with a new location: the file moves there;
 *   - the client-side size check, through the file input and through
 *     drag and drop.
 */
import { test, expect } from '../fixtures';
import { ErrorScreen, ReplaceScreen, fixtureFile } from '../helpers/emr';
import { expectSuccessThenRedirect, fetchBytes, paddedJpeg, submitReplace } from '../helpers/journeys';
import { EditAttachment } from '../helpers/media-list';

test.describe('Replace: replace mode', () => {
	test('a same-type upload replaces the file in place, redirects with emr_replaced=1 and lists the regenerated sizes', async ({ page, emr }) => {
		const id = emr.seed.attachment_id;
		const before = await emr.attachment(id);
		expect(before.metadata && before.metadata.width).toBe(800);

		const upload = await emr.generatedFile(1024, 768, { name: 'emr-e2e-upload-1024.jpg' });
		const screen = new ReplaceScreen(page);
		await screen.goto(id);
		await expect(screen.replaceType('replace')).toBeChecked();
		await screen.chooseFile(upload);
		// The JS preview shows the chosen image with its dimensions.
		await expect(screen.uploadPreview.locator('.textlayer')).toHaveText('1024 x 768');
		await expect(page.locator('.form-warning.filetype').first()).toBeHidden();

		await submitReplace(screen);
		await expectSuccessThenRedirect(page, id);

		// Server truth: same name and path, new bytes and metadata.
		const after = await emr.attachment(id);
		expect(after.file).toBe(before.file);
		expect(after.attached_file).toBe(before.attached_file);
		expect(after.file_exists).toBe(true);
		expect(after.mime).toBe('image/jpeg');
		expect(after.metadata && after.metadata.width).toBe(1024);
		expect(after.metadata && after.metadata.height).toBe(768);
		const sizes = Object.keys((after.metadata && after.metadata.sizes) || {});
		expect(sizes).toEqual(expect.arrayContaining(['thumbnail', 'medium', 'medium_large']));
		const served = await fetchBytes(page.request, after.url);
		expect(served.status).toBe(200);
		expect(served.body.equals(upload.buffer), 'the served file is the uploaded bytes').toBe(true);

		// The thumbnails box: added (closed) on the redirect, one labelled
		// preview per size in the new metadata.
		const edit = new EditAttachment(page);
		await expect(edit.thumbnailsBox).toBeAttached();
		await expect(edit.thumbnailsBox).toHaveClass(/closed/);
		await edit.thumbnailsBox.locator('button.handlediv').click();
		await expect(edit.thumbnailsBox).not.toHaveClass(/closed/);
		const wrappers = edit.thumbnailsBox.locator('.previewwrapper');
		await expect(wrappers).toHaveCount(sizes.length);
		await expect(edit.thumbnailsBox.locator('.thumbnail .label')).toHaveText('Thumbnail');
		await expect(edit.thumbnailsBox.locator('.medium_large .label')).toHaveText('Medium large');
		const thumb = edit.thumbnailsBox.locator('.thumbnail img');
		await expect(thumb).toHaveAttribute('src', /emr-seed-150x150\.jpg/);
		await expect
			.poll(() => thumb.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth === 150), {
				message: 'the regenerated thumbnail loads',
			})
			.toBe(true);
	});

	test('a file type WordPress refuses is warned about on the screen and ends on the error screen', async ({ page, emr }) => {
		const id = emr.seed.attachment_id;
		const before = await emr.attachment(id);

		const screen = new ReplaceScreen(page);
		await screen.goto(id);
		await screen.chooseFile(fixtureFile('test-vector.svg'));

		// js/emr_admin.js: different type, and not in the allowed mime list.
		const typeWarning = page.locator('div.form-warning.filetype');
		await expect(typeWarning).toBeVisible();
		await expect(typeWarning.locator('.source_type')).toHaveText('image/jpeg');
		await expect(typeWarning.locator('.target_type')).toHaveText('image/svg+xml');
		await expect(page.locator('.form-warning.mimetype')).toBeVisible();

		await submitReplace(screen);
		const error = new ErrorScreen(page);
		await expect(error.title).toBeVisible();
		await expect(error.message).toHaveText(
			'The file upload has been rejected for security reason. WordPress might not allow uploading this extension or filetype',
		);

		const after = await emr.attachment(id);
		expect(after.file).toBe(before.file);
		expect(after.mime).toBe('image/jpeg');
		expect(after.metadata).toEqual(before.metadata);
		expect(after.post_modified).toBe(before.post_modified);
	});

	test('another allowed type shows the file type warning only', async ({ page, emr }) => {
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);
		await screen.chooseFile(fixtureFile('codeispoetry.png'));

		const typeWarning = page.locator('div.form-warning.filetype');
		await expect(typeWarning).toBeVisible();
		await expect(typeWarning.locator('.source_type')).toHaveText('image/jpeg');
		await expect(typeWarning.locator('.target_type')).toHaveText('image/png');
		await expect(page.locator('.form-warning.mimetype')).toBeHidden();
		await expect(page.locator('.form-error.filesize')).toBeHidden();
		await expect(screen.uploadPreview.locator('.textlayer')).toHaveText('190 x 15');
	});
});

test.describe('Replace: replace_and_search mode', () => {
	test('a different file name renames the file and rewrites the links in a post', async ({ page, emr }) => {
		const id = emr.seed.attachment_id;
		const before = await emr.attachment(id);
		const postBefore = await emr.postContent(emr.seed.post_id);
		expect(postBefore.content).toContain('/emr-seed.jpg');
		expect(postBefore.content).toContain('/emr-seed-300x225.jpg');

		const upload = await emr.generatedFile(640, 480, { name: 'emr-e2e-renamed.jpg' });
		const screen = new ReplaceScreen(page);
		await screen.goto(id);
		// The location option is shown only for replace_and_search.
		await expect(page.locator('.location_option')).toHaveClass(/hidden/);
		await screen.replaceType('replace_and_search').check();
		await expect(page.locator('.location_option')).not.toHaveClass(/hidden/);
		await screen.chooseFile(upload);
		await submitReplace(screen);
		await expectSuccessThenRedirect(page, id);

		const after = await emr.attachment(id);
		expect(after.attached_file).toBe(before.attached_file.replace('emr-seed.jpg', 'emr-e2e-renamed.jpg'));
		expect(after.file_exists).toBe(true);
		expect(after.title).toBe('emr-e2e-renamed');
		expect(after.url).toMatch(/\/emr-e2e-renamed\.jpg$/);
		expect(after.guid).toBe(after.url);
		expect(after.metadata && after.metadata.width).toBe(640);

		const post = await emr.postContent(emr.seed.post_id);
		expect(post.content).toContain(after.url);
		expect(post.content).toContain(after.url.replace('.jpg', '-300x225.jpg'));
		expect(post.content).not.toContain('emr-seed');

		// The old file is gone from disk.
		expect((await fetchBytes(page.request, before.url)).status).toBe(404);
		const served = await fetchBytes(page.request, after.url);
		expect(served.status).toBe(200);
		expect(served.body.equals(upload.buffer)).toBe(true);
	});

	test('a new location moves the file into that folder', async ({ page, emr }) => {
		const id = emr.seed.attachment_id;
		const before = await emr.attachment(id);
		const upload = await emr.generatedFile(640, 480, { name: 'emr-e2e-moved.jpg' });

		const screen = new ReplaceScreen(page);
		await screen.goto(id);
		await screen.replaceType('replace_and_search').check();
		// Default: the current upload sub directory (year/month).
		await expect(screen.locationDir).toHaveValue(/^\d{4}\/\d{2}$/);
		await screen.newLocation.check();
		await screen.locationDir.fill('emr-e2e-moved/sub');
		await screen.chooseFile(upload);
		await submitReplace(screen);
		await expectSuccessThenRedirect(page, id);

		const after = await emr.attachment(id);
		expect(after.attached_file).toBe('emr-e2e-moved/sub/emr-e2e-moved.jpg');
		expect(after.file).toMatch(/\/wp-content\/uploads\/emr-e2e-moved\/sub\/emr-e2e-moved\.jpg$/);
		expect(after.file_exists).toBe(true);
		expect(after.url).toMatch(/\/wp-content\/uploads\/emr-e2e-moved\/sub\/emr-e2e-moved\.jpg$/);
		expect(after.metadata && after.metadata.file).toBe('emr-e2e-moved/sub/emr-e2e-moved.jpg');

		const post = await emr.postContent(emr.seed.post_id);
		expect(post.content).toContain(after.url);
		expect(post.content).not.toContain('emr-seed');
		expect((await fetchBytes(page.request, before.url)).status).toBe(404);
		expect((await fetchBytes(page.request, after.url)).status).toBe(200);

		// The location is remembered for the next replace screen.
		await screen.goto(id);
		await expect(screen.replaceType('replace_and_search')).toBeChecked();
		await expect(screen.newLocation).toBeChecked();
		await expect(screen.locationDir).toHaveValue('emr-e2e-moved/sub');
	});
});

test.describe('Replace: client-side size check', () => {
	/** The upload limit js/emr_admin.js checks against (emr_options.maxfilesize). */
	async function maxFileSize(page: import('@playwright/test').Page): Promise<number> {
		const max = await page.evaluate(() => Number((window as unknown as { emr_options: { maxfilesize: number } }).emr_options.maxfilesize));
		expect(max, 'wp_max_upload_size() as localised').toBeGreaterThan(0);
		// A file one byte over the limit is built in memory: keep it sane.
		expect(max).toBeLessThan(64 * 1024 * 1024);
		return max;
	}

	test('a file over the upload limit chosen in the file input is refused with the size message', async ({ page, emr }) => {
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);
		const max = await maxFileSize(page);
		const big = paddedJpeg(await emr.generatedFile(400, 300), max + 1, 'emr-e2e-too-big.jpg');

		await screen.fileInput.setInputFiles(big);
		const sizeError = page.locator('.form-error.filesize');
		await expect(sizeError).toBeVisible();
		await expect(sizeError.locator('.fn')).toHaveText('emr-e2e-too-big.jpg');
		await expect(sizeError).toContainText('exceeds the maximum upload size for this site');
		// The input is cleared and the form cannot be submitted.
		await expect(screen.fileInput).toHaveValue('');
		await expect(screen.submit).toBeDisabled();
		await expect(screen.uploadPreview).toHaveClass(/not_image/);

		// A file exactly at the limit is accepted.
		const fits = paddedJpeg(await emr.generatedFile(400, 300), max, 'emr-e2e-fits.jpg');
		await screen.chooseFile(fits);
		await expect(sizeError).toBeHidden();
		await expect(screen.uploadPreview).toHaveClass(/is_image/);
	});

	test('a dropped file goes through the same checks as the file input', async ({ page, emr }) => {
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);
		const max = await maxFileSize(page);

		/** Drop `file` on the drop area, as a browser drag and drop does. */
		async function drop(file: { name: string; mimeType: string; buffer: Buffer }): Promise<void> {
			const dataTransfer = await page.evaluateHandle(
				({ base64, name, type }) => {
					const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
					const transfer = new DataTransfer();
					transfer.items.add(new File([bytes], name, { type }));
					return transfer;
				},
				{ base64: file.buffer.toString('base64'), name: file.name, type: file.mimeType },
			);
			await page.locator('#emr-drop-area').dispatchEvent('drop', { dataTransfer });
		}

		// Too big: refused like through the input.
		await drop(paddedJpeg(await emr.generatedFile(400, 300), max + 1, 'emr-e2e-dropped-big.jpg'));
		const sizeError = page.locator('.form-error.filesize');
		await expect(sizeError).toBeVisible();
		await expect(sizeError.locator('.fn')).toHaveText('emr-e2e-dropped-big.jpg');
		await expect(screen.submit).toBeDisabled();

		// A valid drop: preview with dimensions, submit enabled.
		await drop(await emr.generatedFile(320, 200, { name: 'emr-e2e-dropped.jpg' }));
		await expect(sizeError).toBeHidden();
		await expect(screen.submit).toBeEnabled();
		await expect(screen.uploadPreview.locator('.textlayer')).toHaveText('320 x 200');
		await expect(page.locator('#emr-drop-area-active')).toHaveCount(0);
	});
});
