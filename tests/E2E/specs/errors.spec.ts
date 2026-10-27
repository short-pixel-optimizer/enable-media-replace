/**
 * Error screens of the replace POST (UploadViewController, ViewController::viewError()):
 *   - a bad nonce (ERROR_NONCE), posted from Node with the admin cookies;
 *   - an unparsable custom time (ERROR_TIME);
 *   - a new location that already holds a file of the uploaded name
 *     (ERROR_TARGET_EXISTS);
 * and the containment of the new location: `../` segments and a leading
 * slash are dropped by ReplaceController::normalizePath(), so the file
 * lands INSIDE uploads instead of an error screen.
 *
 * ERROR_DIRECTORY_SECURITY is not reachable on a single site through the
 * form: normalizePath() removes every `..` and empty segment before the
 * basedir check, so only a basedir whose realpath differs from its path
 * (a symlinked uploads folder) or the multisite `sites/` guard can trigger
 * it. The multisite PHPUnit suite covers the latter.
 *
 * Every error test also proves the attachment is unchanged.
 */
import { test, expect } from '../fixtures';
import { ErrorScreen, NO_KEEPALIVE_HEADERS, ReplaceScreen, type AttachmentState } from '../helpers/emr';
import { expectSuccessThenRedirect, fetchBytes, submitReplace } from '../helpers/journeys';

/** An error must change nothing of the attachment the database holds. */
function expectUnchanged(after: AttachmentState, before: AttachmentState): void {
	expect(after.file).toBe(before.file);
	expect(after.attached_file).toBe(before.attached_file);
	expect(after.file_exists).toBe(true);
	expect(after.mime).toBe(before.mime);
	expect(after.title).toBe(before.title);
	expect(after.metadata).toEqual(before.metadata);
	expect(after.post_date).toBe(before.post_date);
	expect(after.post_modified).toBe(before.post_modified);
}

test.describe('Replace: error screens', () => {
	test('a POST with a wrong nonce shows the nonce error screen', async ({ page, emr, baseURL }) => {
		const id = emr.seed.attachment_id;
		const before = await emr.attachment(id);
		const served = await fetchBytes(page.request, before.url);
		const upload = await emr.generatedFile(640, 480, { name: 'emr-e2e-nonce.jpg' });

		// page.request shares the browser context's cookies: an authenticated
		// admin POST that only gets the nonce wrong.
		const formUrl = `${baseURL}/wp-admin/upload.php?page=enable-media-replace/enable-media-replace.php&action=media_replace_upload&attachment_id=${id}`;
		const response = await page.request.post(formUrl, {
			headers: { ...NO_KEEPALIVE_HEADERS },
			multipart: {
				emr_nonce: 'not-a-nonce',
				ID: String(id),
				replace_type: 'replace',
				timestamp_replace: '2',
				userfile: upload,
			},
		});
		expect(response.status()).toBe(200);
		// Show the answer in the browser, at its own URL (so its scripts run in
		// the site's origin, as after a real form POST), and assert on the screen.
		await page.route(formUrl, (route) => route.fulfill({ response }), { times: 1 });
		await page.goto(formUrl);
		const error = new ErrorScreen(page);
		await expect(error.title).toBeVisible();
		await expect(error.message).toHaveText('Fail to validate form nonce. Please try again');
		await expect(error.description).toContainText('This can happen when the window is open for a long time');

		expectUnchanged(await emr.attachment(id), before);
		expect((await fetchBytes(page.request, before.url)).body.equals(served.body), 'the file bytes are untouched').toBe(true);
	});

	test('an unparsable custom time shows the time error screen', async ({ page, emr }) => {
		const id = emr.seed.attachment_id;
		const before = await emr.attachment(id);

		const screen = new ReplaceScreen(page);
		await screen.goto(id);
		await screen.timestamp(3).check();
		await screen.customHour.fill('xx');
		await screen.chooseFile(await emr.generatedFile(640, 480, { name: 'emr-e2e-time.jpg' }));
		await submitReplace(screen);

		const error = new ErrorScreen(page);
		await expect(error.title).toBeVisible();
		await expect(error.message).toHaveText('The custom time format submitted is invalid');
		expectUnchanged(await emr.attachment(id), before);
	});

	test('a new location that already holds the uploaded file name shows the target-exists error screen', async ({ page, emr }) => {
		const id = emr.seed.attachment_id;
		const before = await emr.attachment(id);
		// Another attachment in the same year/month folder, under the name the upload will carry.
		const existing = await emr.uploadGenerated(300, 200, { as: 'emr-e2e-existing.jpg', title: 'Existing image' });
		const existingBefore = await emr.attachment(existing.id);
		expect(existingBefore.attached_file.split('/').slice(0, 2)).toEqual(before.attached_file.split('/').slice(0, 2));

		const screen = new ReplaceScreen(page);
		await screen.goto(id);
		await screen.replaceType('replace_and_search').check();
		await screen.newLocation.check();
		const folder = before.attached_file.split('/').slice(0, 2).join('/');
		await expect(screen.locationDir).toHaveValue(folder);
		await screen.chooseFile(await emr.generatedFile(640, 480, { name: 'emr-e2e-existing.jpg' }));
		await submitReplace(screen);

		const error = new ErrorScreen(page);
		await expect(error.title).toBeVisible();
		await expect(error.message).toHaveText('The target file already exists in this directory. Please try another name / directory');
		await expect(error.description).toHaveText(
			'This error is shown because you try to move the image to another folder, which already has this file',
		);

		expectUnchanged(await emr.attachment(id), before);
		const existingAfter = await emr.attachment(existing.id);
		expect(existingAfter.metadata).toEqual(existingBefore.metadata);
		expect(existingAfter.file_exists).toBe(true);
	});
});

test.describe('Replace: new location containment', () => {
	for (const { label, dir, expected } of [
		{ label: 'a ../ traversal', dir: '../../emr-e2e-escape/x', expected: 'emr-e2e-escape/x' },
		{ label: 'an absolute path', dir: '/emr-e2e-absolute', expected: 'emr-e2e-absolute' },
	]) {
		test(`${label} in the new location stays inside uploads`, async ({ page, emr }) => {
			const id = emr.seed.attachment_id;
			const before = await emr.attachment(id);
			const uploadsDir = before.file.slice(0, before.file.length - before.attached_file.length);
			expect(uploadsDir).toMatch(/\/wp-content\/uploads\/$/);

			const screen = new ReplaceScreen(page);
			await screen.goto(id);
			await screen.replaceType('replace_and_search').check();
			await screen.newLocation.check();
			await screen.locationDir.fill(dir);
			await screen.chooseFile(await emr.generatedFile(640, 480, { name: 'emr-e2e-contained.jpg' }));
			await submitReplace(screen);
			await expectSuccessThenRedirect(page, id);

			const after = await emr.attachment(id);
			expect(after.attached_file).toBe(`${expected}/emr-e2e-contained.jpg`);
			expect(after.file).toBe(`${uploadsDir}${expected}/emr-e2e-contained.jpg`);
			expect(after.file_exists).toBe(true);
			expect((await fetchBytes(page.request, after.url)).status).toBe(200);
		});
	}
});
