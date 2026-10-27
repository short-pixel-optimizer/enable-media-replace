/**
 * The upsell box (views/upsell.php, js/upsell.js, the installer in
 * classes/external/upsell_installer.php) on the replace screen:
 *   - SPIO absent: the SPIO offer with INSTALL NOW;
 *   - after the install: ACTIVATE (in place, and on a fresh load while
 *     the plugin is installed but inactive);
 *   - after the activation: "Shortpixel activated!", and on the next load
 *     no SPIO block at all;
 *   - a user without `install_plugins` sees no upsell;
 *   - pinned: a failed install is presented as a success.
 *
 * The install is real (Plugin_Upgrader, activate_plugin()) but what it
 * downloads is a code-free stub plugin the mock builds for
 * downloads.wordpress.org/plugin/shortpixel-image-optimiser.zip; the
 * `emr` reset removes it again.
 *
 * "Administrator without install_plugins": the support `user` route
 * creates users with a stock role and cannot remove a capability from one,
 * so the spec uses an editor, the stock role that has everything the
 * replace screen needs (upload_files, edit_others_posts) and lacks
 * install_plugins. On a multisite the same holds for a site administrator
 * who is not a super admin; this stack is a single site.
 */
import { test, expect } from '../fixtures';
import { ReplaceScreen, Upsell } from '../helpers/emr';
import { loginAs } from '../helpers/journeys';

const SPIO = 'shortpixel-image-optimiser/wp-shortpixel.php';

async function activePlugins(emr: import('../helpers/emr').EmrSupport): Promise<string[]> {
	const option = await emr.getOption<string[] | Record<string, string>>('active_plugins');
	return Object.values(option.value || {});
}

test.describe('Upsell box', () => {
	test('SPIO absent: the SPIO offer shows INSTALL NOW', async ({ page, emr }) => {
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);

		const upsell = new Upsell(page);
		await expect(upsell.root).toBeVisible();
		await expect(upsell.offer('spio')).toBeVisible();
		await expect(upsell.offer('spio')).toContainText('ShortPixel Image Optimizer');
		await expect(upsell.install('spio')).toBeVisible();
		await expect(upsell.install('spio')).toHaveText('INSTALL NOW');
		await expect(upsell.activate('spio')).toBeHidden();
		await expect(upsell.activated('spio')).toBeHidden();
		// The SPAI block sits in an HTML comment: never rendered.
		await expect(upsell.offer('spai')).toHaveCount(0);
		expect(await activePlugins(emr)).not.toContain(SPIO);
	});

	test('install, then activate: ACTIVATE replaces INSTALL NOW, then the activated state, then no SPIO block', async ({ page, emr }) => {
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);
		const upsell = new Upsell(page);

		await upsell.install('spio').click();
		// The script relabels the button while the AJAX install runs, then swaps it.
		await expect(upsell.activate('spio')).toBeVisible({ timeout: 30_000 });
		await expect(upsell.install('spio')).toBeHidden();
		const downloads = await emr.mockRequests('/plugin/shortpixel-image-optimiser');
		expect(downloads.map((r) => r.host)).toContain('downloads.wordpress.org');
		expect(await activePlugins(emr)).not.toContain(SPIO);

		await upsell.activate('spio').click();
		await expect(upsell.activated('spio')).toBeVisible();
		await expect(upsell.activated('spio')).toHaveText('Shortpixel activated!');
		await expect(upsell.activate('spio')).toBeHidden();
		await expect.poll(() => activePlugins(emr), { message: 'SPIO (the stub) is active' }).toContain(SPIO);

		// SPIO active: the next load has no SPIO block; the FastPixel one stays.
		await screen.goto(emr.seed.attachment_id);
		await expect(upsell.root).toBeAttached();
		await expect(upsell.offer('spio')).toHaveCount(0);
		await expect(upsell.offer('fp')).toBeVisible();
	});

	test('SPIO installed but inactive: a fresh load offers ACTIVATE', async ({ page, emr }) => {
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);
		const upsell = new Upsell(page);
		await upsell.install('spio').click();
		await expect(upsell.activate('spio')).toBeVisible({ timeout: 30_000 });

		await screen.goto(emr.seed.attachment_id);
		await expect(upsell.offer('spio')).toBeVisible();
		await expect(upsell.activate('spio')).toBeVisible();
		await expect(upsell.activate('spio')).toHaveText('ACTIVATE');
		await expect(upsell.install('spio')).toBeHidden();
		expect(await activePlugins(emr)).not.toContain(SPIO);
	});

	/**
	 * js/upsell.js treats every answer of the installer as a success: both
	 * AJAX success callbacks swap the buttons without reading the response.
	 * emr_plugin_install() answers a failed install (here the download from
	 * downloads.wordpress.org fails) with a 200 JSON `{"result": false, ...}`,
	 * so INSTALL NOW turns into ACTIVATE although nothing was installed; and
	 * emr_activate() answers the failed activation with
	 * `{"error": "Plugin file does not exist."}`, so the box then reports
	 * "Shortpixel activated!" for a plugin that does not exist. The correct
	 * behaviour is to check the answer (`response.success` /
	 * `response.error`) and keep INSTALL NOW with an error message.
	 *
	 * How to recognise the fix: after the failed install ACTIVATE stays
	 * hidden (the first assertion after the click fails), and the
	 * activated state never shows.
	 */
	test('a failed install still offers ACTIVATE and then reports activated, pinned_for_deferred_fix', async ({ page, emr }) => {
		await emr.mock({ downloadsFail: true });
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);
		const upsell = new Upsell(page);

		const installAnswer = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData()?.includes('action=emr_plugin_install') === true);
		await upsell.install('spio').click();
		const install = await installAnswer;
		expect(install.status()).toBe(200);
		const installBody = await install.json();
		expect(installBody.result, 'the installer reports the failure').toBe(false);
		expect(installBody.success).toBeUndefined();
		// Sentinel: the download really failed at the mock.
		expect((await emr.mockRequests('/plugin/shortpixel-image-optimiser')).length).toBeGreaterThan(0);

		// Defect: the UI swaps to ACTIVATE anyway.
		await expect(upsell.activate('spio')).toBeVisible();
		await expect(upsell.install('spio')).toBeHidden();

		const activateAnswer = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData()?.includes('action=emr_plugin_activate') === true);
		await upsell.activate('spio').click();
		const activateBody = await (await activateAnswer).json();
		expect(typeof activateBody.error, 'the activation reports an error').toBe('string');
		expect(activateBody.error).toMatch(/does not exist/i);

		// Defect: and then claims success.
		await expect(upsell.activated('spio')).toBeVisible();
		expect(await activePlugins(emr)).not.toContain(SPIO);
	});

	test('a user without install_plugins sees no upsell', async ({ browser, emr }) => {
		const editor = await emr.createUser('editor', 'emr-e2e-editor');
		const session = await loginAs(browser, editor.login);
		try {
			const screen = new ReplaceScreen(session.page);
			await screen.goto(emr.seed.attachment_id);
			// Sentinel: the editor gets the full screen (replace_and_search too).
			await expect(screen.replaceType('replace_and_search')).toBeEnabled();
			await expect(new Upsell(session.page).root).toHaveCount(0);
			await expect(session.page.locator('#upsell-nonce')).toHaveCount(0);
			expect(session.errors, 'JS errors in the editor session').toEqual([]);
		} finally {
			await session.context.close();
		}
	});
});
