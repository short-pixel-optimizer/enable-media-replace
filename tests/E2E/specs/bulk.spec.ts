/**
 * The Media > Bulk page: dashboard → selection → summary → process →
 * finished, the restore of the originals, pause / resume / stop, the API
 * error path, the two-tab lock and the WP-Cron hand-over.
 *
 * The E2E install has no WooCommerce, so the background operation is
 * unavailable here (asserted) and every run uses the upscale operation. The
 * mock answers the reducer with tests/fixtures/test-image.jpg (50x50), so an
 * upscaled attachment ends up 50 pixels wide: an unmistakable sign the
 * result was applied, and the restore brings the original width back.
 */
import { test, expect } from '../fixtures';
import { BulkPage } from '../helpers/bulk-page';
import type { EmrSupport } from '../helpers/emr';
import { loginAs } from '../helpers/journeys';

const API_KEY = 'E2EBULKKEY0123456789'; // 20 characters, what ApiKeyManager stores

/** Three images under the default 1200 px threshold (the seed included) and one above it. */
async function seedLibrary(emr: EmrSupport) {
	const small = await emr.uploadGenerated(600, 400, { format: 'jpg', as: 'bulk-small.jpg' });
	const medium = await emr.uploadGenerated(1000, 700, { format: 'png', as: 'bulk-medium.png' });
	const wide = await emr.uploadGenerated(1600, 1200, { format: 'jpg', as: 'bulk-wide.jpg' });
	return { queued: [emr.seed.attachment_id, small.id, medium.id], wide: wide.id };
}

async function widthOf(emr: EmrSupport, id: number): Promise<number> {
	const state = await emr.attachment(id);
	return state.metadata ? Number(state.metadata.width) : NaN;
}

test.describe('Bulk page: dashboard', () => {
	test('without an API key the operations cannot start, and without WooCommerce the background operation says why', async ({ page }) => {
		const bulk = new BulkPage(page);
		await bulk.goto();
		await bulk.expectPanel('dashboard');

		await expect(bulk.panel('dashboard').locator('.notice-warning')).toContainText('API key required');
		await expect(bulk.startButton('upscale')).toBeDisabled();
		await expect(bulk.startButton('background')).toBeDisabled();

		// No `product` post type on this install: the selector reports the reason.
		await expect(bulk.card('background')).toHaveAttribute('data-available', '0');
		await expect(bulk.card('background').locator('.emr-bulk-unavailable')).toBeVisible();
		await expect(bulk.card('upscale')).toHaveAttribute('data-available', '1');
		await expect(bulk.restoreButton('upscale')).toHaveCount(0);
	});
});

test.describe('Bulk page: upscale', () => {
	test.beforeEach(async ({ emr }) => {
		await emr.setApiKey(API_KEY); // the mock reports Unlimited by default
	});

	test('a full run upscales every image under the threshold, then the restore brings the originals back', async ({ page, emr }) => {
		const { queued, wide } = await seedLibrary(emr);
		const before = new Map<number, number>();
		for (const id of [...queued, wide]) {
			before.set(id, await widthOf(emr, id));
		}

		const bulk = new BulkPage(page);
		await bulk.goto();
		await expect(bulk.startButton('upscale')).toBeEnabled();
		await bulk.startSelection('upscale');
		await expect(bulk.thresholdInput).toHaveValue('1200');
		await bulk.prepare();

		// The summary counts the three images narrower than 1200 px, not the wide one.
		await expect(bulk.stat('total')).toHaveText('3');
		await bulk.startBulk();
		await bulk.driveToFinish(emr);

		await expect(bulk.stat('done')).toHaveText('3');
		await expect(bulk.stat('fatal_errors')).toHaveText('0');
		for (const id of queued) {
			expect(await widthOf(emr, id), `attachment ${id} must carry the API result`).toBe(50);
		}
		expect(await widthOf(emr, wide), 'the wide image must be untouched').toBe(before.get(wide));

		// The mock was asked to upscale (the body carries `upscale`, not `bg_remove`), once per image.
		const requests = await emr.mockRequests('reducer.php');
		expect(requests.length).toBeGreaterThanOrEqual(3);
		for (const request of requests) {
			expect(request.request?.upscale, 'upscale factor in the reducer body').toBeTruthy();
			expect(request.request?.bg_remove).toBeUndefined();
		}

		await bulk.finish(emr);
		await expect(bulk.restoreButton('upscale')).toHaveText(/Restore 3 originals/);
		await expect(bulk.panel('dashboard').locator('.emr-bulk-history tbody tr')).toHaveCount(1);

		// Restore: the backups under EMR_Backups replace the results in place.
		await bulk.startRestore('upscale');
		await bulk.driveToFinish(emr);
		await expect(bulk.stat('done')).toHaveText('3');
		for (const id of queued) {
			expect(await widthOf(emr, id), `attachment ${id} must be back to its original width`).toBe(before.get(id));
		}
		await bulk.finish(emr);
		await expect(bulk.restoreButton('upscale')).toHaveCount(0);
	});

	test('pause and resume during processing, stop confirms and finishes the bulk server-side', async ({ page, emr }) => {
		const { queued } = await seedLibrary(emr);
		await emr.mock({ waitingRounds: 50 }); // the API keeps every item pending: the run never ends by itself

		const bulk = new BulkPage(page);
		await bulk.goto();
		await bulk.startSelection('upscale');
		await bulk.prepare();
		await bulk.startBulk();

		await bulk.pause();
		await expect
			.poll(async () => (await emr.bulkStatus()).media.stats.is_running, { message: 'the bulk stays running server-side while paused in the browser' })
			.toBe(true);
		await bulk.resume();
		await expect(bulk.panel('process').locator('[data-action="StopBulk"]')).toBeVisible();

		await bulk.stop(emr);
		const { stats } = (await emr.bulkStatus()).media;
		expect(stats.is_running).toBe(false);
		expect(Number(stats.in_queue)).toBe(0);
		for (const id of queued) {
			expect(await widthOf(emr, id), `attachment ${id} must be untouched after a stop`).not.toBe(50);
		}
	});

	test('API errors are counted, listed and logged; the images stay as they were', async ({ page, emr }) => {
		const { queued } = await seedLibrary(emr);
		const before = new Map<number, number>();
		for (const id of queued) {
			before.set(id, await widthOf(emr, id));
		}
		await emr.mock({ forceStatusCode: '-102', errorMessage: 'E2E: invalid URL' });

		const bulk = new BulkPage(page);
		await bulk.goto();
		await bulk.startSelection('upscale');
		await bulk.prepare();
		await bulk.startBulk();
		await bulk.driveToFinish(emr);

		await expect(bulk.stat('fatal_errors')).toHaveText('3');
		const rows = await bulk.showErrors();
		await expect(rows).toHaveCount(3);
		await expect(rows.first()).toContainText('E2E: invalid URL');
		for (const id of queued) {
			expect(await widthOf(emr, id), `attachment ${id} must be untouched after an API error`).toBe(before.get(id));
		}

		await bulk.finish(emr);
		const history = bulk.panel('dashboard').locator('.emr-bulk-history tbody tr');
		await expect(history).toHaveCount(1);
		await expect(history.first().locator('td').nth(2)).toHaveText('3');
		await expect(bulk.restoreButton('upscale')).toHaveCount(0);
	});

	test('a second browser sees the running bulk but is blocked from processing it', async ({ page, emr, browser }) => {
		await seedLibrary(emr);
		await emr.mock({ waitingRounds: 50 });

		const bulk = new BulkPage(page);
		await bulk.goto();
		await bulk.startSelection('upscale');
		await bulk.prepare();
		await bulk.startBulk();
		// One answered tick: the processor key (transient emr_bulk_secret) is claimed.
		await bulk.driveToFinish(emr, 1).catch(() => undefined);
		await bulk.expectPanel('process');

		const other = await loginAs(browser, 'admin');
		try {
			const otherBulk = new BulkPage(other.page);
			await otherBulk.goto();
			await otherBulk.expectPanel('process');
			await expect(other.page.locator('#emr-bulk-blocked')).toBeVisible();
			expect(other.errors, 'no JS errors in the second browser').toEqual([]);
		} finally {
			await other.context.close();
		}

		await bulk.stop(emr);
	});

	test('the background-process choice is stored and schedules the cron event while the bulk runs', async ({ page, emr }) => {
		await seedLibrary(emr);
		await emr.mock({ waitingRounds: 50 });

		const bulk = new BulkPage(page);
		await bulk.goto();
		await bulk.startSelection('upscale');
		await expect(bulk.backgroundProcessCheckbox).not.toBeChecked();
		await bulk.backgroundProcessCheckbox.check();
		await bulk.prepare();

		const settings = await emr.getOption<Record<string, unknown>>('enable_media_replace');
		expect(settings.value?.bulk_background_process).toBe(true);
		expect(settings.value?.bulk_upscale_threshold).toBe(1200);

		await bulk.startBulk();
		const scheduled = async () => JSON.stringify((await emr.getOption('cron')).value).includes('emr_bulk_cron');
		await expect.poll(scheduled, { message: 'startBulk must schedule emr_bulk_cron' }).toBe(true);

		await bulk.stop(emr);
		await expect.poll(scheduled, { message: 'finishBulk must unschedule emr_bulk_cron' }).toBe(false);
	});
});
