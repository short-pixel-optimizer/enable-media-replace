/**
 * Page object for the Media > Bulk page (wp-admin/upload.php?page=emr-bulk,
 * BulkViewController + views/bulk/*, driven by js/bulk/*). Port of SPIO's
 * tests/E2E/helpers/bulk-page.ts, reduced to EMR's one queue.
 *
 * Selector facts (verified against the live UI):
 *   - panels     `section.emr-bulk-panel[data-panel]` (dashboard | selection |
 *                summary | process | finished | restore); the shown panel has
 *                `.active` and no `hidden` attribute (SwitchPanel()).
 *   - dashboard  one `.emr-bulk-operation[data-operation]` card per operation
 *                with `data-available`, the Start button
 *                (`[data-action="open-panel"][data-panel="selection"]`) and,
 *                when there is something to restore, the Restore button
 *                (`[data-panel="restore"]`); `.emr-bulk-unavailable` carries
 *                the reason an operation cannot run.
 *   - selection  `input[name="emr_bulk_operation"]` radios, `#emr-bulk-threshold`,
 *                `#emr-bulk-background-process`, `#emr-bulk-create` "Prepare the bulk"
 *                (disabled while the selected operation is unavailable);
 *                `data-status="loading"` while preparing.
 *   - summary    `[data-stats-media="total"]`, `[data-action="StartBulk"]`
 *                (hidden until the total is > 0: data-check-visibility).
 *   - process    `[data-stats-media="percentage_done|total|done|in_process|in_queue|fatal_errors"]`,
 *                `#emr-bulk-pause` / `#emr-bulk-resume` / `[data-action="StopBulk"]`
 *                (StopBulk uses a native confirm()), `#emr-bulk-paused` banner,
 *                `#emr-bulk-blocked` banner (another processor holds the key),
 *                error rows `.emr-bulk-errorbox > div.fatal`, revealed by
 *                `input[name="emr-show-errors"]`.
 *   - finished   `[data-action="FinishBulk"]` → server finishBulk → reload to the dashboard.
 *   - restore    `input[data-target="emr-bulk-restore-<op>"]` arms `#emr-bulk-restore-<op>`.
 *   - events     `emr.PrepareBulk` (createBulk answered), `emr.bulk.started`,
 *                `emr.processor.responseHandled` (every tick),
 *                `emr.processor.paused` {paused}, `emr.bulk.onSwitchPanel`.
 *   - timing     the processor polls every emrBulk.interval ms (3 s by
 *                default, 200 ms while preparing). An item the API answers
 *                "pending" is re-picked only after ShortQ's 10 s
 *                process_timeout, so the driver loop backdates the queue
 *                rows between ticks (support route queue/backdate).
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { emrUrls, withSelfReload, type EmrSupport } from './emr';

export type BulkPanel = 'dashboard' | 'selection' | 'summary' | 'process' | 'finished' | 'restore';
export type BulkOperation = 'background' | 'upscale';

/**
 * Run `action` and wait for the window event `eventName` it triggers. The
 * listener is installed BEFORE the action, so an event fired synchronously
 * by the click is not missed.
 */
export async function withEmrEvent<T>(page: Page, eventName: string, action: () => Promise<T>, timeoutMs = 30_000): Promise<T> {
	const waiter = page.evaluate(
		([name, timeout]) =>
			new Promise<boolean>((resolve, reject) => {
				const timer = setTimeout(() => reject(new Error(`timed out waiting for window event "${name}"`)), timeout);
				window.addEventListener(
					name,
					() => {
						clearTimeout(timer);
						resolve(true);
					},
					{ once: true },
				);
			}),
		[eventName, timeoutMs] as const,
	);
	const result = await action();
	await waiter;
	return result;
}

/** Set a checkbox through the DOM and fire the events the screen listens to (hidden custom switches). */
export async function setChecked(locator: Locator, on: boolean): Promise<void> {
	await locator.evaluate((el, value) => {
		const input = el as HTMLInputElement;
		if (input.checked !== value) {
			input.checked = value;
			input.dispatchEvent(new Event('input', { bubbles: true }));
			input.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}, on);
	if (on) {
		await expect(locator).toBeChecked();
	} else {
		await expect(locator).not.toBeChecked();
	}
}

export class BulkPage {
	constructor(readonly page: Page) {}

	async goto(): Promise<void> {
		await this.page.goto(emrUrls.bulk);
		await expect(this.page.locator('.emr-bulk-wrapper')).toBeVisible();
	}

	panel(name: BulkPanel): Locator {
		return this.page.locator(`section.emr-bulk-panel[data-panel="${name}"]`);
	}

	/**
	 * Wait until a panel is BOTH active and visible, in one poll: two
	 * assertions could straddle a panel switch (the first passes, the panel
	 * changes, the second reports the element hidden). On failure the
	 * message names the panel that actually won.
	 */
	async expectPanel(name: BulkPanel, timeoutMs = 30_000): Promise<void> {
		await expect
			.poll(
				() =>
					this.page
						.evaluate((wanted) => {
							const all = Array.from(document.querySelectorAll('section.emr-bulk-panel'));
							const target = all.find((el) => el.getAttribute('data-panel') === wanted);
							const visible = (el: Element) => !!(el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden');
							if (target && target.classList.contains('active') && visible(target)) {
								return wanted;
							}
							const active = all.find((el) => el.classList.contains('active'));
							const shown = all.filter(visible).map((el) => el.getAttribute('data-panel')).join(',');
							return `active=${active ? active.getAttribute('data-panel') : 'none'} visible=[${shown || 'none'}]`;
						}, name)
						.catch((e) => `evaluate failed: ${String(e)}`),
				{ timeout: timeoutMs, message: `bulk panel "${name}" must be the active, visible panel` },
			)
			.toBe(name);
	}

	/** The operation card on the dashboard. */
	card(operation: BulkOperation): Locator {
		return this.page.locator(`.emr-bulk-operation[data-operation="${operation}"]`);
	}

	startButton(operation: BulkOperation): Locator {
		return this.card(operation).locator('button[data-action="open-panel"][data-panel="selection"]');
	}

	restoreButton(operation: BulkOperation): Locator {
		return this.card(operation).locator('button[data-action="open-panel"][data-panel="restore"]');
	}

	/** A stat of the ACTIVE panel (the same field is rendered on several panels). */
	stat(key: string): Locator {
		return this.page.locator(`section.emr-bulk-panel.active [data-stats-media="${key}"]`).first();
	}

	/** Numeric value of a stat (the server formats thousands separators). */
	async statNumber(key: string): Promise<number> {
		const text = (await this.stat(key).textContent()) || '';
		return Number(text.replace(/[^\d.-]/g, '')) || 0;
	}

	/** dashboard → selection, with the operation pre-selected by the Start button. */
	async startSelection(operation: BulkOperation): Promise<void> {
		await this.startButton(operation).click();
		await this.expectPanel('selection');
		await expect(this.page.locator(`input[name="emr_bulk_operation"][value="${operation}"]`)).toBeChecked();
	}

	get thresholdInput(): Locator {
		return this.page.locator('#emr-bulk-threshold');
	}

	get backgroundProcessCheckbox(): Locator {
		return this.page.locator('#emr-bulk-background-process');
	}

	/** selection → (prepare ticks, 200 ms apart) → summary. */
	async prepare(): Promise<void> {
		await withEmrEvent(this.page, 'emr.PrepareBulk', () => this.page.locator('#emr-bulk-create').click());
		await this.expectPanel('summary', 60_000);
	}

	/** summary → process. The Start button is hidden until the total is known. */
	async startBulk(): Promise<void> {
		const button = this.panel('summary').locator('[data-action="StartBulk"]');
		await expect(button).toBeVisible({ timeout: 30_000 });
		await withEmrEvent(this.page, 'emr.bulk.started', () => button.click());
		await this.expectPanel('process');
	}

	/**
	 * Drive the process panel to completion: after every tick, age the queue
	 * rows so the next tick re-picks the items the API answered "pending"
	 * without waiting out ShortQ's process_timeout. Ends on the finished panel.
	 */
	async driveToFinish(emr: EmrSupport, maxTicks = 60): Promise<void> {
		for (let tick = 0; tick < maxTicks; tick++) {
			if (await this.panel('finished').evaluate((el) => el.classList.contains('active')).catch(() => false)) {
				break;
			}
			await emr.backdateQueue();
			try {
				await withEmrEvent(this.page, 'emr.processor.responseHandled', async () => undefined, 25_000);
			} catch {
				// A missed tick (the processor may already have stopped on
				// QUEUE_EMPTY); the loop head checks the finished panel again.
			}
		}
		await this.expectPanel('finished', 10_000);
	}

	async pause(): Promise<void> {
		await withEmrEvent(this.page, 'emr.processor.paused', () => this.page.locator('#emr-bulk-pause').click());
		await expect(this.page.locator('#emr-bulk-paused')).toBeVisible();
		await expect(this.page.locator('#emr-bulk-resume')).toBeVisible();
	}

	async resume(): Promise<void> {
		await withEmrEvent(this.page, 'emr.processor.paused', () => this.page.locator('#emr-bulk-resume').click());
		await expect(this.page.locator('#emr-bulk-paused')).toBeHidden();
		await expect(this.page.locator('#emr-bulk-pause')).toBeVisible();
	}

	/**
	 * Stop = native confirm() + server finishBulk + a JS-triggered reload.
	 * The reload is not proof the bulk is over (the new document picks its
	 * panel from the server state of that request), so wait for the SERVER
	 * to report the queue clear, then assert the dashboard on a fresh load.
	 */
	async stop(emr: EmrSupport): Promise<void> {
		this.page.once('dialog', (dialog) => dialog.accept());
		await withSelfReload(this.page, () => this.page.locator('section.emr-bulk-panel.active [data-action="StopBulk"]').click());
		await this.expectQueueClear(emr);
		await this.goto();
		await this.expectPanel('dashboard');
	}

	/** finished → dashboard (server finishBulk + the same self-reload). */
	async finish(emr: EmrSupport): Promise<void> {
		await withSelfReload(this.page, () => this.panel('finished').locator('[data-action="FinishBulk"]').click());
		await this.expectQueueClear(emr);
		await this.expectPanel('dashboard');
	}

	async expectQueueClear(emr: EmrSupport): Promise<void> {
		await expect
			.poll(
				async () => {
					const { stats } = (await emr.bulkStatus()).media;
					return !stats.is_preparing && !stats.is_running && Number(stats.in_queue) === 0 && Number(stats.in_process) === 0;
				},
				{ timeout: 30_000, message: 'finishBulk must clear the queue server-side' },
			)
			.toBe(true);
	}

	/** Reveal the error box of the active panel and return its fatal rows. */
	async showErrors(): Promise<Locator> {
		const toggle = this.page.locator('section.emr-bulk-panel.active input[name="emr-show-errors"]');
		await setChecked(toggle, true);
		return this.page.locator('section.emr-bulk-panel.active .emr-bulk-errorbox > div.fatal');
	}

	/** dashboard → restore panel → arm and click the restore button → process (restore ticks). */
	async startRestore(operation: BulkOperation): Promise<void> {
		await this.restoreButton(operation).click();
		await this.expectPanel('restore');
		// The arming checkbox is bound on `click` (no data-event), so a real click, not a DOM change.
		await this.page.locator(`input[data-target="emr-bulk-restore-${operation}"]`).check();
		const button = this.page.locator(`#emr-bulk-restore-${operation}`);
		await expect(button).toBeEnabled();
		await withEmrEvent(this.page, 'emr.bulk.started', () => button.click(), 60_000);
		await this.expectPanel('process');
	}
}
