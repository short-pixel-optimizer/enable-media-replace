/**
 * Playwright configuration for the EMR browser E2E suite.
 *
 * Run through bin/test-e2e.sh (Docker, canonical) or natively from this
 * directory against the Dockerized WordPress (`npx playwright test --headed`
 * with E2E_BASE_URL defaulting to http://localhost:8031).
 *
 * Serial by design: every spec shares ONE WordPress install and mutates it
 * (uploads, options, the mock API state). workers: 1 + fullyParallel: false
 * keeps tests from racing each other; the `emr` fixture resets state per
 * test.
 */
import path from 'path';
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL || 'http://localhost:8031';
const isCI = !!process.env.CI;
const DESKTOP = { width: 1366, height: 768 };
const AUTH = './artifacts/.auth/admin.json';

export default defineConfig({
	testDir: './specs',
	outputDir: './artifacts/test-results',

	fullyParallel: false,
	workers: 1,
	// No retries anywhere. A test that fails once and passes on retry is
	// exactly the intermittent JS/timing problem this suite exists to catch,
	// and Playwright counts such a "flaky" test as a PASS for the exit code,
	// so a real race would hide behind a green run. Failures must go red the
	// first time; fix the race, don't retry past it.
	retries: 0,
	forbidOnly: isCI,

	timeout: 90_000,
	expect: {
		timeout: 15_000,
		toHaveScreenshot: {
			// Deterministic captures: no CSS animations/transitions mid-frame,
			// no blinking caret, CSS pixels regardless of device scale.
			animations: 'disabled',
			caret: 'hide',
			scale: 'css',
			// Tight ABSOLUTE budget. Rendering inside the pinned Playwright
			// image is pixel-stable run to run, so this only absorbs stray
			// anti-aliasing. A ratio is too lax: 1% of a tall screen is tens
			// of thousands of pixels, enough to let a whole panel change
			// pass unnoticed. Per-pixel colour tolerance stays at the default.
			maxDiffPixels: 100,
			// Screenshot-only CSS: hides the WP admin chrome and footer
			// (see the file for why).
			stylePath: path.join(__dirname, 'helpers', 'screenshot.css'),
		},
	},

	reporter: [
		['list'],
		['html', { outputFolder: './artifacts/html-report', open: 'never' }],
	],

	use: {
		baseURL,
		// Post-mortem material only when something went wrong.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		// Recording every test and throwing the file away on success costs
		// memory and CPU for the whole run. On a 2-core / 7 GB CI runner that
		// overhead is a plausible trigger for WebKit's web process dying
		// mid-navigation; traces already carry the post-mortem material, so
		// the video is not worth the pressure there.
		video: isCI ? 'off' : 'retain-on-failure',
		actionTimeout: 15_000,
		navigationTimeout: 30_000,
	},

	// Visual baselines (specs/visual.spec.ts, the replace and the
	// remove-background screens) live next to the specs, one PNG
	// per screenshot name. No platform/browser suffix: baselines are only
	// ever produced and compared by the `visual` project inside the pinned
	// Playwright Docker image (Linux, fixed fonts + browser build).
	snapshotPathTemplate: '{testDir}/../snapshots/{testFileName}/{arg}{ext}',
	// Outside Docker (native --headed / --ui runs on a Mac) fonts and
	// rendering differ, so screenshot assertions are no-ops there instead of
	// failing on every pixel. bin/test-e2e.sh's container sets E2E_IN_DOCKER.
	ignoreSnapshots: !process.env.E2E_IN_DOCKER,

	projects: [
		{
			// Logs in once and stores the admin session for every other project.
			name: 'setup',
			testMatch: /.*\.setup\.ts/,
		},
		// Functional suite, once per engine. Same viewport everywhere so a
		// layout-dependent assertion means the same thing in all three.
		{
			name: 'chromium',
			testIgnore: /visual\.spec\.ts/,
			use: { ...devices['Desktop Chrome'], viewport: DESKTOP, storageState: AUTH },
			dependencies: ['setup'],
		},
		{
			name: 'firefox',
			testIgnore: /visual\.spec\.ts/,
			use: { ...devices['Desktop Firefox'], viewport: DESKTOP, storageState: AUTH },
			dependencies: ['setup'],
		},
		{
			name: 'webkit',
			testIgnore: /visual\.spec\.ts/,
			use: { ...devices['Desktop Safari'], viewport: DESKTOP, storageState: AUTH },
			dependencies: ['setup'],
		},
		// Screenshot regression, Chromium only: one set of baselines to review
		// and maintain; cross-engine coverage comes from the functional
		// projects above. Refresh with `bin/test-e2e.sh --project visual
		// --update-snapshots` (routine after a WordPress major).
		{
			name: 'visual',
			testMatch: /visual\.spec\.ts/,
			use: { ...devices['Desktop Chrome'], viewport: DESKTOP, storageState: AUTH },
			dependencies: ['setup'],
		},
	],
});
