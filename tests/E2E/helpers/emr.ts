/**
 * EMR helpers for the E2E suite:
 *
 *   - EmrSupport: typed client of the emr-e2e/v1 test-support REST API
 *     (tests/E2E/mu-plugins/emr-e2e-support.php). Specs get an instance as
 *     the `emr` fixture (fixtures.ts), already reset to the seed.
 *   - emrUrls: the hidden EMR admin page
 *     (upload.php?page=enable-media-replace/enable-media-replace.php&action=...).
 *   - ReplaceScreen, SuccessScreen, ErrorScreen, RemoveBackgroundScreen,
 *     Upsell: locators for the markup in views/screen.php, views/success.php,
 *     views/error.php, views/prepare-remove-background.php and
 *     views/upsell.php.
 *   - fixtureFile(): a file from tests/fixtures/ as a setInputFiles payload.
 */
import fs from 'fs';
import path from 'path';
import { expect, type APIRequestContext, type Locator, type Page } from '@playwright/test';

// -------------------------------------------------------------------------
// Support API types
// -------------------------------------------------------------------------

/** The baseline content every test starts with (see emr_e2e_apply_seed()). */
export type Seed = {
	/** emr-seed.jpg, 800x600, with thumbnail / medium / medium_large sizes. */
	attachment_id: number;
	attachment_url: string;
	attachment_file: string;
	/** "EMR seed post": links the full image and embeds its medium size. */
	post_id: number;
	post_url: string;
};

export type SizeInfo = { file: string; width: number; height: number; url: string };

/** Answer of the fixture route. */
export type AttachmentSummary = {
	id: number;
	url: string;
	file: string;
	mime: string;
	width: number | null;
	height: number | null;
	sizes: Record<string, SizeInfo>;
};

/** Answer of GET attachment/<id>: server-side truth, straight from the database. */
export type AttachmentState = {
	id: number;
	title: string;
	mime: string;
	attached_file: string;
	file: string;
	file_exists: boolean;
	url: string;
	guid: string;
	post_date: string;
	post_date_gmt: string;
	post_modified: string;
	post_modified_gmt: string;
	metadata: false | {
		width?: number;
		height?: number;
		file?: string;
		sizes?: Record<string, { file: string; width: number; height: number; 'mime-type'?: string }>;
		[key: string]: unknown;
	};
	backup_sizes: Record<string, unknown> | null;
	replace_author: string | number;
};

export type PostInfo = { id: number; url: string; edit_url: string; content: string };
export type PostContent = { id: number; content: string; status: string; title: string };
export type UserInfo = { id: number; login: string; password: string; role: string };

/**
 * Knobs of the mock (tests/E2E/mu-plugins/emr-e2e-mock-api.php, which
 * documents each one). The route refuses an unknown name.
 */
export type MockKnobs = Partial<{
	forceStatusCode: string | null;
	waitingRounds: number;
	errorMessage: string;
	quotaExceeded: boolean;
	malformedBody: string | null;
	wpErrorMessage: string | null;
	apiStatusCode: number | string;
	apiStatusMessage: string;
	unlimited: 'true' | 'false' | null;
	notices: Array<{ id: string; message: string; type?: string }> | string;
	resultFixture: string;
	downloadsFail: boolean;
}>;

export type MockRequest = {
	time: string;
	url: string;
	host: string;
	path: string;
	method: string;
	/** Decoded JSON body, form fields, or query arguments; null otherwise. */
	request: Record<string, unknown> | null;
};

/** A file for locator.setInputFiles(). */
export type FilePayload = { name: string; mimeType: string; buffer: Buffer };

/**
 * Headers for EVERY request made from Node (a Playwright request context)
 * to the WordPress container.
 *
 * Playwright's request client keeps one keep-alive HTTP agent per worker
 * process, while Apache in the container closes idle keep-alive connections
 * after 5 s (`KeepAliveTimeout 5`). A socket that sat idle while a test drove
 * the browser can be closed by Apache at the very moment the next support
 * call reuses it, and the request dies with "socket hang up" (ECONNRESET)
 * before a byte reaches WordPress. Browsers retry that race transparently;
 * Node does not, and with retries: 0 the test goes red. `Connection: close`
 * gives every request a fresh socket, which removes the race instead of
 * retrying past it.
 */
export const NO_KEEPALIVE_HEADERS = { Connection: 'close' } as const;

/** Header carrying the shared secret (EMR_E2E_TOKEN in wp-config.php). */
const TOKEN_HEADER = 'X-EMR-E2E-TOKEN';

export class EmrSupport {
	private readonly token = process.env.E2E_TOKEN || 'emr-e2e-local';

	/** The seed of the last reset() / seed() call. */
	seed!: Seed;

	constructor(private readonly request: APIRequestContext, private readonly baseURL: string) {}

	private url(route: string): string {
		// ?rest_route= works whatever the permalink structure.
		return `${this.baseURL}/?rest_route=/emr-e2e/v1/${route}`;
	}

	private async post<T = unknown>(route: string, data?: unknown): Promise<T> {
		const response = await this.request.post(this.url(route), {
			data: data ?? {},
			headers: { ...NO_KEEPALIVE_HEADERS, [TOKEN_HEADER]: this.token },
		});
		expect(response.ok(), `support endpoint ${route}: HTTP ${response.status()} ${await response.text()}`).toBe(true);
		return (await response.json()) as T;
	}

	private async get<T = unknown>(route: string, query: Record<string, string> = {}): Promise<T> {
		const params = Object.entries(query)
			.map(([k, v]) => `&${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
			.join('');
		const response = await this.request.get(this.url(route) + params, {
			headers: { ...NO_KEEPALIVE_HEADERS, [TOKEN_HEADER]: this.token },
		});
		expect(response.ok(), `support endpoint ${route}: HTTP ${response.status()} ${await response.text()}`).toBe(true);
		return (await response.json()) as T;
	}

	private checkSeed(seed: Seed & { error?: string }): Seed {
		expect(seed.error, 'the seed must apply without error').toBeUndefined();
		expect(typeof seed.attachment_id, 'seed attachment id').toBe('number');
		expect(seed.attachment_id).toBeGreaterThan(0);
		this.seed = seed;
		return seed;
	}

	/**
	 * Delete every attachment, post, page and suite user, the EMR options and
	 * transients, the mock state and installed stub plugins, the year/month
	 * and emr-e2e-* upload folders; then re-seed. The fixture calls it
	 * before every test.
	 */
	async reset(): Promise<Seed> {
		return this.checkSeed(await this.post<Seed>('reset'));
	}

	/** Re-apply the baseline (EMR options cleared, seed content recreated) without the full wipe. */
	async reapplySeed(): Promise<Seed> {
		return this.checkSeed(await this.post<Seed>('seed'));
	}

	setOption(name: string, value: unknown): Promise<{ ok: boolean }> {
		return this.post('option', { name, value });
	}

	deleteOption(name: string): Promise<{ ok: boolean }> {
		return this.post('option', { name, delete: true });
	}

	getOption<T = unknown>(name: string): Promise<{ name: string; exists: boolean; value: T | null }> {
		return this.get('option', { name });
	}

	/** Upload tests/fixtures/<name> as an attachment (optionally under another file name `as`). */
	uploadFixture(name: string, options: { as?: string; title?: string; author?: number } = {}): Promise<AttachmentSummary> {
		return this.post('fixture', { name, ...options });
	}

	/** Upload a GD-drawn image of the given size as an attachment. */
	uploadGenerated(
		width: number,
		height: number,
		options: { format?: 'jpg' | 'png'; as?: string; title?: string; author?: number } = {},
	): Promise<AttachmentSummary> {
		return this.post('fixture', { width, height, ...options });
	}

	/** Bytes of a GD-drawn image, for a browser upload (no attachment is created). */
	async generatedFile(width: number, height: number, options: { format?: 'jpg' | 'png'; name?: string } = {}): Promise<FilePayload> {
		const answer = await this.post<{ name: string; mimeType: string; base64: string }>('image', { width, height, ...options });
		return { name: answer.name, mimeType: answer.mimeType, buffer: Buffer.from(answer.base64, 'base64') };
	}

	/** A published post: an image block for `attachmentId` (size default medium), or raw `content`. */
	createPost(options: { attachmentId?: number; size?: string; content?: string; title?: string; status?: string }): Promise<PostInfo> {
		return this.post('post', {
			attachment_id: options.attachmentId,
			size: options.size,
			content: options.content,
			title: options.title,
			status: options.status,
		});
	}

	/** Raw post_content of a post, read from the database. */
	postContent(id: number): Promise<PostContent> {
		return this.get(`post/${id}`);
	}

	attachment(id: number): Promise<AttachmentState> {
		return this.get(`attachment/${id}`);
	}

	/** Store the API key option directly (no verification); null deletes it. */
	setApiKey(key: string | null): Promise<{ ok: boolean; key: string | null }> {
		return this.post('key', { key });
	}

	/** Force the emr/feature/* filter outcome; null restores the default. */
	setFeatures(features: { background?: boolean | null; remote_notice?: boolean | null }): Promise<{ ok: boolean }> {
		return this.post('feature', features);
	}

	/** A user with the role and password "password" (removed by reset). */
	createUser(role: string, login?: string): Promise<UserInfo> {
		return this.post('user', { role, login });
	}

	/** Merge knobs into the mock. */
	mock(knobs: MockKnobs): Promise<{ ok: boolean; knobs: Required<MockKnobs> }> {
		return this.post('mock', knobs);
	}

	mockKnobs(): Promise<Required<MockKnobs>> {
		return this.get('mock');
	}

	resetMock(): Promise<{ ok: boolean }> {
		return this.post('mock/reset');
	}

	/** The mock's request log, oldest first; `pathFragment` filters on the URL path. */
	mockRequests(pathFragment?: string): Promise<MockRequest[]> {
		return this.get('mock/requests', pathFragment ? { path: pathFragment } : {});
	}
}

/**
 * A file from tests/fixtures/ as a setInputFiles payload. The Playwright
 * container mounts tests/fixtures at /fixtures (docker-compose.e2e.yml),
 * which is the same relative path from this file as in the checkout.
 */
export function fixtureFile(name: string): FilePayload {
	const file = path.resolve(__dirname, '..', '..', 'fixtures', name);
	const ext = path.extname(name).toLowerCase();
	const mimeType =
		{ '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.gif': 'image/gif', '.webp': 'image/webp', '.svg': 'image/svg+xml', '.pdf': 'application/pdf' }[ext] ||
		'application/octet-stream';
	return { name, mimeType, buffer: fs.readFileSync(file) };
}

/**
 * Run an action that makes the PAGE navigate itself, and wait for the new
 * document.
 *
 * Use it instead of `page.waitForURL(...)` whenever the target URL may
 * already match the current one (a reload, a form that posts back to the
 * same admin page): waitForURL resolves IMMEDIATELY then, before the
 * navigation starts, and every assertion after it races the page swap. The
 * `load` event is the real document load and cannot resolve early.
 */
export async function withSelfReload(page: Page, action: () => Promise<unknown>, timeoutMs = 30_000): Promise<void> {
	const loaded = page.waitForEvent('load', { timeout: timeoutMs });
	await action();
	await loaded;
}

/**
 * Known JS defects the tripwire sees, as patterns over a tripwire entry
 * (message plus stack). A spec that has to cross one of them opts out with
 * `test.use({ allowConsoleErrors: true })` and calls expectOnlyKnownErrors();
 * each defect also has a pinned test that fails the day it is fixed.
 */
export const KNOWN_JS_DEFECTS = {
	/**
	 * js/emr_admin.js is enqueued on every EMR screen (route()), and its
	 * init() ends with showReplaceOptions(), which dereferences
	 * `section.options .location_option` without a null check. The
	 * remove-background screen has no such element, so every load throws a
	 * TypeError (Chromium: "Cannot read properties of null (reading
	 * 'classList')", Firefox: "locationOption is null", WebKit: "null is not
	 * an object (evaluating 'locationOption.classList')"). Pinned in
	 * specs/smoke.spec.ts. Chromium and Firefox name the function in the
	 * stack; WebKit reports the frame as `unknown`, so the pattern also
	 * accepts the variable name WebKit's message carries.
	 */
	removeBackgroundLocationOption: /(showReplaceOptions|locationOption)[\s\S]*emr_admin\.js/,
} as const;

/** Every captured error matches one of `allowed` (and, when given, at least one was captured). */
export function expectOnlyKnownErrors(consoleErrors: string[], allowed: RegExp[]): void {
	const unknown = consoleErrors.filter((entry) => !allowed.some((re) => re.test(entry)));
	expect(unknown, 'JS errors other than the known, pinned defects').toEqual([]);
}

// -------------------------------------------------------------------------
// URLs
// -------------------------------------------------------------------------

const EMR_PAGE = '/wp-admin/upload.php?page=enable-media-replace/enable-media-replace.php';

/**
 * The hidden EMR page (EnableMediaReplacePlugin::route()). Neither screen
 * checks a nonce on GET; the row action and meta box links carry one anyway.
 */
export const emrUrls = {
	/** Replace screen (views/screen.php). */
	replace: (attachmentId: number): string => `${EMR_PAGE}&action=media_replace&attachment_id=${attachmentId}`,
	/** Remove-background screen (views/prepare-remove-background.php); needs the `background` feature. */
	removeBackground: (attachmentId: number): string => `${EMR_PAGE}&action=emr_prepare_remove&attachment_id=${attachmentId}`,
} as const;

/** The query arguments of an EMR page URL (absolute or relative), for assertions on links and redirects. */
export function emrQuery(url: string): { page: string | null; action: string | null; attachmentId: number | null } {
	const params = new URL(url, 'http://localhost').searchParams;
	const id = params.get('attachment_id');
	return { page: params.get('page'), action: params.get('action'), attachmentId: id === null ? null : Number(id) };
}

// -------------------------------------------------------------------------
// Screens
// -------------------------------------------------------------------------

/**
 * The replace screen, views/screen.php. The form posts to
 * action=media_replace_upload (UploadViewController).
 *
 * There is no "new file name" field: in replace_and_search mode the new
 * name is the uploaded file's name (set it through the FilePayload name).
 * The `new_title` input sits inside an HTML comment and is never rendered.
 */
export class ReplaceScreen {
	constructor(readonly page: Page) {}

	async goto(attachmentId: number): Promise<void> {
		await this.page.goto(emrUrls.replace(attachmentId));
		await expect(this.heading).toBeVisible();
	}

	get heading(): Locator {
		return this.page.getByRole('heading', { level: 1, name: 'Replace Media Upload' });
	}

	get form(): Locator {
		return this.page.locator('.emr_upload_form form');
	}

	/** Current file panel and the "New" upload panel (both `.image_placeholder`). */
	get currentPreview(): Locator {
		return this.page.locator('.image_previews .image_placeholder').first();
	}

	get currentThumbnail(): Locator {
		return this.currentPreview.locator('img');
	}

	get uploadPreview(): Locator {
		return this.page.locator('.image_previews .image_placeholder').nth(1);
	}

	/** The file input (name="userfile", id="upload-file"), inside the upload panel. */
	get fileInput(): Locator {
		return this.page.locator('#upload-file');
	}

	/** replace_type radio: 'replace' (#replace_type_1) or 'replace_and_search' (#replace_type_2). */
	replaceType(value: 'replace' | 'replace_and_search'): Locator {
		return this.page.locator(`input[name="replace_type"][value="${value}"]`);
	}

	/** timestamp_replace radio: 1 = current date, 2 = keep the date, 3 = custom date. */
	timestamp(value: 1 | 2 | 3): Locator {
		return this.page.locator(`input[name="timestamp_replace"][value="${value}"]`);
	}

	/** Custom date fields (shown for timestamp 3). The hidden custom_date_formatted (Y-m-d) is the value used. */
	get customDate(): Locator {
		return this.page.locator('#emr_datepicker');
	}

	get customHour(): Locator {
		return this.page.locator('#emr_hour');
	}

	get customMinute(): Locator {
		return this.page.locator('#emr_minute');
	}

	get customDateFormatted(): Locator {
		return this.page.locator('input[name="custom_date_formatted"]');
	}

	/** "Now" / "Original" shortcuts next to the custom date. */
	customDateShortcut(label: 'Now' | 'Original'): Locator {
		return this.page.locator('a.replace_custom_date', { hasText: label });
	}

	/** New location (only rendered when the upload path has a sub directory and the file is movable). */
	get newLocation(): Locator {
		return this.page.locator('input[name="new_location"]');
	}

	get locationDir(): Locator {
		return this.page.locator('input[name="location_dir"]');
	}

	/** "Remove the background after replacing" (only when background removal is possible). */
	get removeAfter(): Locator {
		return this.page.locator('#remove_after_progress');
	}

	get removeBackgroundLink(): Locator {
		return this.page.getByRole('link', { name: /remove the background of this image/i });
	}

	/** The Upload submit; disabled until a file is chosen (js/emr_admin.js). */
	get submit(): Locator {
		return this.page.locator('#submit');
	}

	/** Choose the replacement file and wait for the script to enable the submit. */
	async chooseFile(file: FilePayload | string): Promise<void> {
		await this.fileInput.setInputFiles(file);
		await expect(this.submit).toBeEnabled();
	}
}

/** views/success.php: heading, countdown, continue link (redirects to the edit screen). */
export class SuccessScreen {
	constructor(readonly page: Page) {}

	get root(): Locator {
		return this.page.locator('.emr-screen.success-screen');
	}

	get heading(): Locator {
		return this.root.getByRole('heading', { level: 1, name: 'Your image has been replaced!' });
	}

	get counter(): Locator {
		return this.page.locator('#redirect_counter');
	}

	get continueLink(): Locator {
		return this.page.locator('#redirect_url');
	}
}

/** views/error.php: "An error occured" plus the message and optional description. */
export class ErrorScreen {
	constructor(readonly page: Page) {}

	get root(): Locator {
		return this.page.locator('.emr-screen.error-screen');
	}

	get title(): Locator {
		return this.root.getByRole('heading', { level: 1, name: 'An error occured' });
	}

	get message(): Locator {
		return this.root.locator('.error-message');
	}

	get description(): Locator {
		return this.root.locator('.description');
	}
}

/**
 * The remove-background screen, views/prepare-remove-background.php and
 * js/remove_bg.js. Preview runs the `emr_remove_background` AJAX call; on
 * success the script prepends an <img> (the mock's LosslessURL, answered by
 * fixtures.ts from the mock stash) to the last `.image_placeholder`, fills
 * the hidden `key` and enables Replace.
 */
export class RemoveBackgroundScreen {
	constructor(readonly page: Page) {}

	async goto(attachmentId: number): Promise<void> {
		await this.page.goto(emrUrls.removeBackground(attachmentId));
		await expect(this.form).toBeVisible();
	}

	get form(): Locator {
		return this.page.locator('#emr_replace_form');
	}

	get originalPreview(): Locator {
		return this.page.locator('.image_previews .image_placeholder').first();
	}

	/** The result panel; holds the result <img> once a preview succeeded. */
	get resultPreview(): Locator {
		return this.page.locator('.image_previews .image_placeholder').last();
	}

	get resultImage(): Locator {
		return this.resultPreview.locator('img');
	}

	/** Error text the script prepends for a failed preview (not quota / plan). */
	get resultError(): Locator {
		return this.resultPreview.locator('h1.response');
	}

	/** Dismissible notice for quota-exceeded and plan-expired answers. */
	get notice(): Locator {
		return this.page.locator('#emr-bg-notice');
	}

	get overlay(): Locator {
		return this.page.locator('#overlay');
	}

	backgroundType(value: 'transparent' | 'solid'): Locator {
		return this.page.locator(`input[name="background_type"][value="${value}"]`);
	}

	get colorPicker(): Locator {
		return this.page.locator('#bg_display_picker');
	}

	get color(): Locator {
		return this.page.locator('#bg_color');
	}

	get transparency(): Locator {
		return this.page.locator('#bg_transparency');
	}

	get resultKey(): Locator {
		return this.page.locator('input[name="key"]');
	}

	get previewButton(): Locator {
		return this.page.locator('#remove_background_button');
	}

	get replaceButton(): Locator {
		return this.page.locator('#replace_image_button');
	}

	get badBackgroundLink(): Locator {
		return this.page.locator('#bad-background-link');
	}

	get replaceInsteadLink(): Locator {
		return this.page.getByRole('link', { name: /Replace this image with another one instead/i });
	}

	// --- ShortPixel account section (manage_options only) ---

	get account(): Locator {
		return this.page.locator('section.emr-sp-account');
	}

	get apiKeyInput(): Locator {
		return this.page.locator('#emr-new-api-key');
	}

	/** "Activate" without a key, "Save" in the change-key form. */
	get apiKeySave(): Locator {
		return this.page.locator('#emr-update-api-key');
	}

	get changeKeyToggle(): Locator {
		return this.page.locator('#emr-change-key-toggle');
	}

	/** Remove Key asks window.confirm() first: accept it with page.once('dialog'). */
	get removeKey(): Locator {
		return this.page.locator('#emr-remove-key');
	}

	get maskedKey(): Locator {
		return this.page.locator('.emr-sp-key-value');
	}

	/** Result of a save or remove; the page reloads on success. */
	get apiKeyNotice(): Locator {
		return this.page.locator('#emr-apikey-notice');
	}
}

/** views/upsell.php, included in both screens when the user can install plugins. */
export class Upsell {
	constructor(readonly page: Page) {}

	get root(): Locator {
		return this.page.locator('section.upsell-wrapper');
	}

	/** 'spio' | 'fp' | 'spai' (the data-plugin values). */
	offer(plugin: string): Locator {
		return this.page.locator(`.shortpixel-offer.${plugin}`);
	}

	install(plugin: string): Locator {
		return this.page.locator(`.emr-installer[data-plugin="${plugin}"]`);
	}

	activate(plugin: string): Locator {
		return this.page.locator(`.emr-activate[data-plugin="${plugin}"]`);
	}

	activated(plugin: string): Locator {
		return this.page.locator(`.emr-activate-done[data-plugin="${plugin}"]`);
	}
}
