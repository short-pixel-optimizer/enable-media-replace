/**
 * The three date modes of the replace form (ReplaceController::updateDate()),
 * observed in the database through the support `attachment/<id>` route:
 *   1 (TIME_UPDATEALL)      post_date and post_modified become now;
 *   2 (TIME_UPDATEMODIFIED) post_date is kept, post_modified becomes now
 *                           (the default);
 *   3 (TIME_CUSTOM)         post_date and post_modified become the date
 *                           picked in the jQuery UI datepicker plus the
 *                           hour and minute fields.
 *
 * The seed attachment is created seconds before each test, so "keep" and
 * "now" would look the same on it. The keep and now tests therefore first
 * move the attachment to a custom date in 2020 (asserted), then replace
 * again with the mode under test.
 *
 * The site's timezone is Europe/Bucharest (provision.sh): UTC+2 in
 * January, so the GMT columns of a custom January date are two hours
 * earlier.
 */
import { test, expect } from '../fixtures';
import { ReplaceScreen, SuccessScreen, type EmrSupport } from '../helpers/emr';
import { expectSuccessThenRedirect, submitReplace } from '../helpers/journeys';
import type { Page } from '@playwright/test';

const SITE_TZ = 'Europe/Bucharest';

/** "Y-m-d H:i:s" of `date` in `timeZone`. */
function mysqlDate(date: Date, timeZone: string): string {
	const parts = Object.fromEntries(
		new Intl.DateTimeFormat('en-CA', {
			timeZone,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
			hourCycle: 'h23',
		})
			.formatToParts(date)
			.map((p) => [p.type, p.value]),
	);
	return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;
}

/** Seconds between two "Y-m-d H:i:s" strings of the same timezone. */
function secondsBetween(a: string, b: string): number {
	return Math.abs(Date.parse(`${a.replace(' ', 'T')}Z`) - Date.parse(`${b.replace(' ', 'T')}Z`)) / 1000;
}

/** `value` lies between the two wall-clock bounds (inclusive, one second of slack each side). */
function expectWithin(value: string, from: string, to: string, what: string): void {
	const v = Date.parse(`${value.replace(' ', 'T')}Z`);
	expect(v, `${what} (${value}) not before ${from}`).toBeGreaterThanOrEqual(Date.parse(`${from.replace(' ', 'T')}Z`) - 1000);
	expect(v, `${what} (${value}) not after ${to}`).toBeLessThanOrEqual(Date.parse(`${to.replace(' ', 'T')}Z`) + 1000);
}

/** Pick 15 January 2020 in the datepicker and set 03:04. */
async function pickCustomDate(screen: ReplaceScreen): Promise<void> {
	await screen.timestamp(3).check();
	await expect(screen.customDate).toBeVisible();

	await screen.customDate.click();
	const picker = screen.page.locator('#ui-datepicker-div');
	await expect(picker).toBeVisible();
	// Typing (keyup) makes the datepicker parse the text and show that month.
	await screen.customDate.fill('');
	await screen.customDate.pressSequentially('January 01, 2020');
	await expect(picker.locator('.ui-datepicker-month')).toHaveText('January');
	await expect(picker.locator('.ui-datepicker-year')).toHaveText('2020');
	await picker.locator('td[data-handler="selectDay"] a', { hasText: /^15$/ }).click();
	await expect(picker).toBeHidden();

	// onClose wrote the Y-m-d value the server reads (no zero padding).
	await expect(screen.customDate).toHaveValue('January 15, 2020');
	await expect(screen.customDateFormatted).toHaveValue('2020-1-15');
	await screen.customHour.fill('03');
	await screen.customMinute.fill('04');
}

/** Replace the seed with a fresh upload in `mode` (3 picks the custom date). */
async function replaceWithMode(page: Page, emr: EmrSupport, mode: 1 | 2 | 3, waitForRedirect: boolean): Promise<void> {
	const screen = new ReplaceScreen(page);
	await screen.goto(emr.seed.attachment_id);
	if (mode === 3) {
		await pickCustomDate(screen);
	} else {
		await screen.timestamp(mode).check();
		await expect(screen.customDate).toBeHidden();
	}
	await screen.chooseFile(await emr.generatedFile(640, 480, { name: `emr-e2e-date-${mode}.jpg` }));
	await submitReplace(screen);
	if (waitForRedirect) {
		await expectSuccessThenRedirect(page, emr.seed.attachment_id);
	} else {
		await expect(new SuccessScreen(page).heading).toBeVisible();
	}
}

const CUSTOM = { local: '2020-01-15 03:04:00', gmt: '2020-01-15 01:04:00' };

test.describe('Replace: date modes', () => {
	test('custom date: the datepicker date and the hour and minute become post_date and post_modified', async ({ page, emr }) => {
		await replaceWithMode(page, emr, 3, true);

		const after = await emr.attachment(emr.seed.attachment_id);
		expect(after.post_date).toBe(CUSTOM.local);
		expect(after.post_date_gmt).toBe(CUSTOM.gmt);
		expect(after.post_modified).toBe(CUSTOM.local);
		expect(after.post_modified_gmt).toBe(CUSTOM.gmt);

		// The choice is remembered: the next screen opens on the custom date.
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);
		await expect(screen.timestamp(3)).toBeChecked();
		await expect(screen.customDateFormatted).toHaveValue('2020-01-15');
		await expect(screen.customHour).toHaveValue('03');
		await expect(screen.customMinute).toHaveValue('04');
	});

	test('keep the date (the default): post_date stays, post_modified becomes now', async ({ page, emr }) => {
		// Precondition: an old date to keep.
		await replaceWithMode(page, emr, 3, false);
		const old = await emr.attachment(emr.seed.attachment_id);
		expect(old.post_date).toBe(CUSTOM.local);

		const from = mysqlDate(new Date(), SITE_TZ);
		await replaceWithMode(page, emr, 2, true);
		const to = mysqlDate(new Date(), SITE_TZ);

		const after = await emr.attachment(emr.seed.attachment_id);
		expect(after.post_date).toBe(CUSTOM.local);
		expect(after.post_date_gmt).toBe(CUSTOM.gmt);
		expectWithin(after.post_modified, from, to, 'post_modified');
		expect(secondsBetween(after.post_modified, after.post_modified_gmt), 'Bucharest is 2 or 3 hours ahead of GMT').toBeGreaterThanOrEqual(7200);
		expect(secondsBetween(after.post_modified, after.post_modified_gmt)).toBeLessThanOrEqual(10800);
	});

	test('replace the date: post_date and post_modified become now', async ({ page, emr }) => {
		await replaceWithMode(page, emr, 3, false);
		expect((await emr.attachment(emr.seed.attachment_id)).post_date).toBe(CUSTOM.local);

		const from = mysqlDate(new Date(), SITE_TZ);
		await replaceWithMode(page, emr, 1, true);
		const to = mysqlDate(new Date(), SITE_TZ);

		const after = await emr.attachment(emr.seed.attachment_id);
		expectWithin(after.post_date, from, to, 'post_date');
		expect(after.post_modified).toBe(after.post_date);
		expect(after.post_date_gmt).toBe(after.post_modified_gmt);
		expect(secondsBetween(after.post_date, after.post_date_gmt)).toBeGreaterThanOrEqual(7200);
	});

	test('the Now and Original shortcuts fill the custom date fields', async ({ page, emr }) => {
		const attachment = await emr.attachment(emr.seed.attachment_id);
		const screen = new ReplaceScreen(page);
		await screen.goto(emr.seed.attachment_id);
		await screen.timestamp(3).check();

		await screen.customHour.fill('23');
		await screen.customDateShortcut('Original').click();
		const [date, time] = attachment.post_date.split(' ');
		await expect(screen.customDateFormatted).toHaveValue(date);
		await expect(screen.customHour).toHaveValue(time.slice(0, 2));
		await expect(screen.customMinute).toHaveValue(time.slice(3, 5));

		await screen.customDateFormatted.evaluate((el: HTMLInputElement) => (el.value = 'unset'));
		await screen.customDateShortcut('Now').click();
		await expect(screen.customDateFormatted).toHaveValue(mysqlDate(new Date(), SITE_TZ).slice(0, 10));
	});
});
