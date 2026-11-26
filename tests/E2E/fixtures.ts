/**
 * Shared test fixtures for the EMR E2E suite. Every spec imports `test` and
 * `expect` from here instead of from @playwright/test so that:
 *
 *   1. The CONSOLE-ERROR TRIPWIRE is armed on every page of every test: any
 *      uncaught JS exception (`pageerror`) or console.error fails the test
 *      at teardown. A JS error on EMR's screens usually means a feature went
 *      silently dead (a button that does nothing), so every flow test is
 *      also a crash detector for everything it touches. A spec that
 *      legitimately expects errors (a pin for a known bug) opts out with
 *      `test.use({ allowConsoleErrors: true })` and asserts on
 *      `consoleErrors` explicitly.
 *
 *   2. The run is HERMETIC in the browser: requests to shortpixel.com
 *      (the upsell images and links, the pricing link), wordpress.org
 *      (api., downloads., the news feed), gravatar.com (avatars) and s.w.org
 *      (emoji images, fetched by engines without an emoji font) are aborted.
 *      A DNS hiccup on any of them would otherwise trip the console-error
 *      tripwire in whichever test happens to be running. Server-side traffic
 *      to the same hosts is answered by the emr-e2e-mock-api mu-plugin.
 *
 *      One exception: the remove-background preview. js/remove_bg.js shows
 *      the API's result URL (https://api.shortpixel.com/f/<token>.<ext>,
 *      handed out by the mock) in an <img>. That request is answered here
 *      with the same bytes, fetched from the mock's stash on the site
 *      (/wp-content/uploads/emr-e2e-mock/<token>.<ext>), so the preview
 *      renders exactly as with the real API and still nothing leaves the
 *      machine.
 *
 *   3. `emr` gives specs the test-support REST client (helpers/emr.ts),
 *      already RESET to the seed before the test starts (auto fixture: every
 *      test starts from the same state, whether it uses `emr` or not).
 *      `emr.seed` holds the seeded attachment and post.
 */
import { test as base, expect, type Browser, type BrowserContext, type BrowserContextOptions, type Page } from '@playwright/test';
import { EmrSupport } from './helpers/emr';

type Fixtures = {
	/** Set to true (via test.use) for specs that assert on expected JS errors. */
	allowConsoleErrors: boolean;
	/** Collected `pageerror` + console.error entries for the current test. */
	consoleErrors: string[];
	/** Test-support REST client, reset to the seed before the test. */
	emr: EmrSupport;
};

const DEFAULT_BASE_URL = 'http://localhost:8031';

/** Hosts whose requests are aborted in the browser to keep runs hermetic. */
const BLOCKED_HOSTS = /(^|\.)shortpixel\.com$|(^|\.)shortpixel\.ai$|(^|\.)wordpress\.org$|(^|\.)gravatar\.com$|^s\.w\.org$/i;

/** The mock's result download URLs (see emr-e2e-mock-api.php, DOWNLOAD_BASE). */
const MOCK_DOWNLOAD = /^https?:\/\/api\.shortpixel\.com\/f\/([A-Za-z0-9._-]+)$/;

/**
 * Console errors that are noise, not signal. Keep this list SHORT and
 * documented: every entry is a place a real bug could hide.
 */
const CONSOLE_ALLOWLIST: RegExp[] = [
	// Our own hermetic blocking above surfaces as a failed resource load.
	/net::ERR_BLOCKED_BY_CLIENT/,
	// WordPress admin has no favicon in a stock install.
	/favicon\.ico/,
];

/**
 * Aborts every request of `context` to BLOCKED_HOSTS, except the mock's
 * result download URLs, which are answered from the stash on the site.
 */
async function routeThirdPartyHosts(context: BrowserContext, baseURL: string): Promise<void> {
	await context.route(
		(url) => BLOCKED_HOSTS.test(url.hostname),
		async (route) => {
			const url = new URL(route.request().url());
			const match = MOCK_DOWNLOAD.exec(`${url.protocol}//${url.host}${url.pathname}`);
			if (match) {
				const response = await route.fetch({ url: `${baseURL}/wp-content/uploads/emr-e2e-mock/${match[1]}` });
				await route.fulfill({ response });
				return;
			}
			await route.abort('blockedbyclient');
		},
	);
}

/**
 * A fresh, unauthenticated context (a site visitor) with the same hermetic
 * routing as the fixture's own context. Use it instead of
 * browser.newContext(): a context created that way bypasses the routing, so
 * the theme's third-party requests reach the internet.
 */
export async function newVisitorContext(browser: Browser, options: BrowserContextOptions = {}): Promise<BrowserContext> {
	const context = await browser.newContext(options);
	await routeThirdPartyHosts(context, process.env.E2E_BASE_URL || DEFAULT_BASE_URL);
	return context;
}

/**
 * Collects `pageerror` and console.error entries of `page` into `sink`,
 * minus the expected noise (blocked hosts, CONSOLE_ALLOWLIST). The fixture
 * arms it on every page of its context; a spec that opens a page in another
 * context (newVisitorContext) arms it itself and asserts on its own sink.
 */
export function attachTripwire(page: Page, sink: string[]): void {
	page.on('pageerror', (error) => {
		// A page that throws a non-Error (`throw {…}`) gives an error whose
		// message is the useless "[object Object]". Prefer the stack, and
		// fall back to serialising the payload so the attachment names
		// something.
		let detail = error?.stack || [error?.name, error?.message].filter(Boolean).join(': ');
		if (!detail || /\[object Object\]/.test(detail)) {
			try {
				detail = `${detail} ${JSON.stringify(error, Object.getOwnPropertyNames(error ?? {}))}`.trim();
			} catch {
				detail = String(error);
			}
		}
		sink.push(`pageerror: ${detail}`);
	});
	page.on('console', (msg) => {
		if (msg.type() !== 'error') {
			return;
		}
		const location = msg.location();
		const text = `console.error: ${msg.text()} @ ${location.url}:${location.lineNumber}`;
		if (BLOCKED_HOSTS.test(safeHostname(location.url))) {
			return; // aborted third-party request: expected
		}
		if (CONSOLE_ALLOWLIST.some((re) => re.test(text))) {
			return;
		}
		sink.push(text);
	});
}

function safeHostname(url: string): string {
	try {
		return new URL(url).hostname;
	} catch {
		return '';
	}
}

export const test = base.extend<Fixtures>({
	allowConsoleErrors: [false, { option: true }],

	consoleErrors: [
		async ({ context, allowConsoleErrors, baseURL }, use, testInfo) => {
			const errors: string[] = [];

			// Hermetic routing for every page in this context.
			await routeThirdPartyHosts(context, baseURL || DEFAULT_BASE_URL);

			// Tripwire on pages that already exist and on every page opened later.
			context.pages().forEach((page) => attachTripwire(page, errors));
			context.on('page', (page) => attachTripwire(page, errors));

			await use(errors);

			if (errors.length > 0) {
				await testInfo.attach('js-errors.txt', { body: errors.join('\n'), contentType: 'text/plain' });
			}
			if (!allowConsoleErrors) {
				expect(
					errors,
					'JS TRIPWIRE: uncaught errors / console.error during the test (see js-errors.txt attachment)',
				).toEqual([]);
			}
		},
		{ auto: true },
	],

	emr: [
		async ({ request, baseURL }, use) => {
			const support = new EmrSupport(request, baseURL || DEFAULT_BASE_URL);
			await support.reset();
			await use(support);
		},
		{ auto: true },
	],
});

export { expect };
