/**
 * Setup project: log in once as the provisioned admin and persist the
 * session, so every spec in the browser projects starts authenticated (see
 * storageState in playwright.config.ts).
 */
// The suite's `test`, not Playwright's: the login lands on the dashboard,
// whose third-party requests (gravatar, wordpress.org) must be blocked like
// everywhere else, and the tripwire guards the login page too.
import { test as setup } from '../fixtures';
import { login } from '../helpers/wp-admin';

const STORAGE_STATE = './artifacts/.auth/admin.json';

setup('authenticate as admin', async ({ page }) => {
	await login(page);
	await page.context().storageState({ path: STORAGE_STATE });
});
