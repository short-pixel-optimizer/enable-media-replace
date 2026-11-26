/**
 * Shared steps of the journey specs:
 *
 *   - loginAs(): a second, logged-in browser context for a suite user (an
 *     author, an editor) with the same hermetic routing and tripwire as the
 *     fixture's own context.
 *   - submitReplace(): submit the replace form and wait for the document the
 *     POST answers with (the success or the error screen).
 *   - expectSuccessThenRedirect(): the success screen heading, then its
 *     countdown redirect to the edit screen with emr_replaced=1.
 *   - fetchBytes(): a file of the site, read from Node (Connection: close).
 *   - paddedJpeg(): a real JPEG grown to an exact byte size (trailing bytes
 *     after the EOI marker are ignored by decoders), for the size check.
 */
import { expect, type APIRequestContext, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { attachTripwire, newVisitorContext } from '../fixtures';
import { NO_KEEPALIVE_HEADERS, ReplaceScreen, SuccessScreen, type FilePayload } from './emr';
import { login } from './wp-admin';

export type UserSession = { context: BrowserContext; page: Page; errors: string[] };

/** A new context logged in as `user` (password "password"); close it in the test. */
export async function loginAs(browser: Browser, user: string): Promise<UserSession> {
	// An empty storage state: Playwright applies the project's `use` options
	// (the admin session included) to contexts a test creates.
	const context = await newVisitorContext(browser, { storageState: { cookies: [], origins: [] } });
	const page = await context.newPage();
	const errors: string[] = [];
	attachTripwire(page, errors);
	await login(page, user, 'password');
	// The dashboard the login lands on starts REST calls (apiFetch, e.g.
	// wp/v2/users/me). Navigating away while one is in flight aborts it and
	// core rejects the promise with an unhandled `{ message, code }`, which
	// Firefox reports as an uncaught exception. Let them settle first.
	await page.waitForLoadState('networkidle');
	return { context, page, errors };
}

/** Click Upload and wait for the answer of the form POST (a full document load). */
export async function submitReplace(screen: ReplaceScreen): Promise<void> {
	await Promise.all([
		screen.page.waitForURL(/action=media_replace_upload/),
		screen.submit.click(),
	]);
	await screen.page.waitForLoadState('load');
}

/** The success screen, then the countdown redirect to the edit screen with emr_replaced=1. */
export async function expectSuccessThenRedirect(page: Page, attachmentId: number): Promise<void> {
	const success = new SuccessScreen(page);
	await expect(success.heading).toBeVisible();
	await expect(success.continueLink).toHaveAttribute('href', new RegExp(`post\\.php\\?.*post=${attachmentId}.*emr_replaced=1`));
	// js/emr_success.js writes the remaining seconds before navigating.
	await expect(success.counter).toHaveText(/^\d+$/);
	await page.waitForURL(new RegExp(`/wp-admin/post\\.php\\?.*post=${attachmentId}.*emr_replaced=1`), { timeout: 20_000 });
	await expect(page.locator('#post')).toBeVisible();
}

/** Bytes of a URL of the site. */
export async function fetchBytes(request: APIRequestContext, url: string): Promise<{ status: number; body: Buffer }> {
	const response = await request.get(url, { headers: { ...NO_KEEPALIVE_HEADERS } });
	return { status: response.status(), body: await response.body() };
}

/** `file` grown with zero bytes to exactly `size` bytes, under `name`. */
export function paddedJpeg(file: FilePayload, size: number, name: string): FilePayload {
	expect(size, 'the padded size must exceed the source').toBeGreaterThan(file.buffer.length);
	return { name, mimeType: 'image/jpeg', buffer: Buffer.concat([file.buffer, Buffer.alloc(size - file.buffer.length)]) };
}
