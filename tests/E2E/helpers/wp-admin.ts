/**
 * Plain WordPress admin helpers (login, navigation).
 */
import { expect, type Page } from '@playwright/test';

export const ADMIN_USER = 'admin';
export const ADMIN_PASSWORD = 'password';

/**
 * Log in through the real wp-login.php form.
 *
 * wp-login.php focuses `#user_login` from an inline script. On a slow or
 * CPU-starved machine that script can run BETWEEN Playwright focusing
 * `#user_pass` and typing into it, so the password text lands in the
 * username field, the form fails browser validation and never submits (the
 * setup project then dies on a 30s navigation timeout). Waiting for
 * WordPress's own focus before filling closes that window; the value
 * assertions are sentinels that would catch any other focus thief.
 */
export async function login(page: Page, user = ADMIN_USER, password = ADMIN_PASSWORD): Promise<void> {
	await page.goto('/wp-login.php', { waitUntil: 'load' });
	const userInput = page.locator('#user_login');
	const passwordInput = page.locator('#user_pass');

	await expect(userInput).toBeVisible();
	// Best effort: if the inline focus never happens, filling is safe anyway.
	await expect(userInput).toBeFocused({ timeout: 5_000 }).catch(() => undefined);

	await userInput.fill(user);
	await passwordInput.fill(password);
	await expect(userInput, 'the username field must still hold the username').toHaveValue(user);
	await expect(passwordInput, 'the password must be in the password field').toHaveValue(password);

	await Promise.all([page.waitForURL(/\/wp-admin\/?/), page.locator('#wp-submit').click()]);
	await expect(page.locator('#wpadminbar')).toBeVisible();
}

/** Plain WordPress admin URLs (relative to baseURL). */
export const adminUrls = {
	dashboard: '/wp-admin/',
	/** Media Library in LIST mode (the row actions live there, not in the grid). */
	mediaList: '/wp-admin/upload.php?mode=list',
	editAttachment: (id: number): string => `/wp-admin/post.php?post=${id}&action=edit`,
	plugins: '/wp-admin/plugins.php',
} as const;
