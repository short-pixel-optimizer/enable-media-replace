/**
 * Page objects for the two WordPress screens EMR adds its entry points to.
 *
 * Media Library LIST view (wp-admin/upload.php?mode=list):
 *   - row:          `tr#post-<id>`;
 *   - row actions:  EnableMediaReplacePlugin::add_media_action() adds
 *                   `.row-actions .media_replace a` ("Replace media", to the
 *                   replace screen, no nonce) and, when the background
 *                   feature is on and the user is not author restricted,
 *                   `.row-actions .remove_background a` ("Remove  background",
 *                   two spaces, with an `emr_prepare_remove` nonce).
 *                   Row actions are visually hidden until the row is hovered
 *                   or focused; rowAction() hovers first.
 *
 * Media Library GRID view (wp-admin/upload.php?mode=grid):
 *   - tiles:        `li.attachment[data-id="<id>"]`; a click opens the
 *                   attachment details modal (`.media-modal`);
 *   - EMR's buttons: EnableMediaReplacePlugin::attachment_editor() adds
 *                   compat fields, rendered in the modal as
 *                   `tr.compat-field-enable-media-replace` ("Upload a new
 *                   file"), `tr.compat-field-emr-remove-background` and
 *                   `tr.compat-field-emr-upscale`, each holding one
 *                   `a.button-secondary`. The same fields show in the media
 *                   modal of the editors ("Replace" on an image block).
 *
 * Edit-attachment screen (wp-admin/post.php?post=<id>&action=edit):
 *   - meta box `#emr-replace-box` ("Replace Media") with the "Upload a new
 *     file" button (to the replace screen) and, for removable images, the
 *     "Remove background" button;
 *   - meta box `#emr-showthumbs-box` ("Replaced Thumbnails Preview"), only
 *     on the redirect after a replace (`emr_replaced=1`).
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { adminUrls } from './wp-admin';

export class MediaList {
	constructor(readonly page: Page) {}

	async goto(): Promise<void> {
		await this.page.goto(adminUrls.mediaList);
		await expect(this.page.locator('table.wp-list-table.media')).toBeVisible();
	}

	row(id: number): Locator {
		return this.page.locator(`tr#post-${id}`);
	}

	/** A row action link ('media_replace' or 'remove_background'), revealed by hovering the row. */
	async rowAction(id: number, action: 'media_replace' | 'remove_background' | 'upscale_image'): Promise<Locator> {
		const row = this.row(id);
		await expect(row).toBeVisible();
		await row.hover();
		return row.locator(`.row-actions .${action} a`);
	}

	/** Locator only (no hover): for presence / absence checks. */
	rowActionLink(id: number, action: 'media_replace' | 'remove_background' | 'upscale_image'): Locator {
		return this.row(id).locator(`.row-actions .${action} a`);
	}
}

export class MediaGrid {
	constructor(readonly page: Page) {}

	async goto(): Promise<void> {
		await this.page.goto(adminUrls.mediaGrid);
		await expect(this.page.locator('.attachments-browser .attachments')).toBeVisible();
	}

	tile(id: number): Locator {
		return this.page.locator(`li.attachment[data-id="${id}"]`);
	}

	/** Open the attachment details modal of a tile. */
	async open(id: number): Promise<void> {
		await this.tile(id).click();
		await expect(this.page.locator('.media-modal .attachment-details')).toBeVisible();
	}

	/** One of EMR's compat fields inside the open modal. */
	field(name: 'enable-media-replace' | 'emr-remove-background' | 'emr-upscale'): Locator {
		return this.page.locator(`.media-modal tr.compat-field-${name}`);
	}

	get replaceButton(): Locator {
		return this.field('enable-media-replace').getByRole('link', { name: 'Upload a new file' });
	}

	get removeBackgroundButton(): Locator {
		return this.field('emr-remove-background').getByRole('link', { name: 'Remove background' });
	}

	get upscaleButton(): Locator {
		return this.field('emr-upscale').getByRole('link', { name: 'Upscale image' });
	}
}

export class EditAttachment {
	constructor(readonly page: Page) {}

	async goto(id: number, query = ''): Promise<void> {
		await this.page.goto(adminUrls.editAttachment(id) + query);
		await expect(this.page.locator('#post')).toBeVisible();
	}

	get replaceBox(): Locator {
		return this.page.locator('#emr-replace-box');
	}

	/** "Upload a new file" in the Replace Media meta box. */
	get replaceButton(): Locator {
		return this.replaceBox.getByRole('link', { name: 'Upload a new file' });
	}

	get removeBackgroundButton(): Locator {
		return this.replaceBox.getByRole('link', { name: 'Remove background' });
	}

	get upscaleButton(): Locator {
		return this.replaceBox.getByRole('link', { name: 'Upscale image' });
	}

	/** "Replaced Thumbnails Preview", shown after a replace (emr_replaced=1). */
	get thumbnailsBox(): Locator {
		return this.page.locator('#emr-showthumbs-box');
	}
}
