<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$counts = $view->counts;
$has_products = $view->has_products;

?>

<div id="emr-bg-notice" class="notice notice-error is-dismissible" style="display:none;">
	<p></p>
	<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php esc_html_e('Dismiss this notice.', 'enable-media-replace'); ?></span></button>
</div>

<div class="wrap emr_upload_form" id="emr-bulk-background">

	<?php // WordPress moves admin notices after this marker. Without it they end up inside our centered header, after the h1. ?>
	<hr class="wp-header-end">

	<?php // Outside the form, so the upsell sidebar next to the form lines up with the notices. ?>
	<header class="emr-bulk-header">
		<h1><?php esc_html_e('Bulk Background Removal', 'enable-media-replace'); ?></h1>
		<p><?php esc_html_e('Remove the background of all your product images in one go.', 'enable-media-replace'); ?></p>
	</header>

	<form id="emr_bulk_form" method="POST" action="">

		<div class="editor-wrapper">

			<div class="emr-bulk-notes">
				<div class="emr-bulk-notice is-info">
					<span class="dashicons dashicons-products" aria-hidden="true"></span>
					<p class="emr-bulk-notice-title"><?php esc_html_e('For now, Bulk Background Removal only works for product images', 'enable-media-replace'); ?></p>
					<p><?php esc_html_e('Only images attached to a WooCommerce product are processed: the main product image, the product gallery images and the variation images. Images inserted in the product description or short description, and all other Media Library images, are not touched.', 'enable-media-replace'); ?></p>
				</div>
				<div class="emr-bulk-notice is-warning">
					<span class="dashicons dashicons-backup" aria-hidden="true"></span>
					<p class="emr-bulk-notice-title"><?php esc_html_e('The original files are replaced', 'enable-media-replace'); ?></p>
					<p><?php esc_html_e('There is no preview step before replacing in bulk, so we recommend making a backup before starting. If a CDN is used, remember to clear its cache afterwards.', 'enable-media-replace'); ?></p>
				</div>
			</div>

			<?php if (! $has_products) : ?>
				<div class="emr-bulk-notice is-error emr-bulk-notice-wide">
					<p><?php esc_html_e('WooCommerce does not seem to be active on this site, so there are no product images to process.', 'enable-media-replace'); ?></p>
				</div>
			<?php endif; ?>

			<div class="emr-bulk-stats">
				<div class="emr-bulk-stat is-total">
					<span class="emr-bulk-stat-value" id="emr-bulk-count-total"><?php echo intval($counts['total']); ?></span>
					<span class="emr-bulk-stat-label"><?php esc_html_e('Product images found', 'enable-media-replace'); ?></span>
				</div>
				<div class="emr-bulk-stat">
					<span class="emr-bulk-stat-value" id="emr-bulk-count-processed"><?php echo intval($counts['processed']); ?></span>
					<span class="emr-bulk-stat-label"><?php esc_html_e('Already processed', 'enable-media-replace'); ?></span>
				</div>
				<div class="emr-bulk-stat">
					<span class="emr-bulk-stat-value" id="emr-bulk-count-pending"><?php echo intval($counts['pending']); ?></span>
					<span class="emr-bulk-stat-label"><?php esc_html_e('Not processed yet', 'enable-media-replace'); ?></span>
				</div>
			</div>
			<p class="emr-bulk-stats-note"><?php esc_html_e('Only JPG, PNG and WebP images are counted, since the background can only be removed from these.', 'enable-media-replace'); ?></p>

			<section class="replace_type wrapper emr-bulk-settings">
				<div class="section-header"><?php esc_html_e('Bulk Settings', 'enable-media-replace'); ?></div>

				<p class="emr-bulk-group-label"><?php esc_html_e('Background', 'enable-media-replace'); ?></p>
				<div class="option replace">
					<label for="transparent_background">
						<input id="transparent_background" type="radio" name="background_type" value="transparent" <?php checked('transparent', $view->settings['bg_type']); ?> >
						<?php esc_html_e('Transparent/white background', 'enable-media-replace'); ?>
					</label>
					<p class="howto">
						<?php esc_html_e('Returns a transparent background if it is a PNG or WebP image, or a white one if it is a JPG image.', 'enable-media-replace'); ?>
					</p>
				</div>
				<div class="option searchreplace">
					<label for="solid_background">
						<input id="solid_background" type="radio" name="background_type" value="solid" <?php checked('solid', $view->settings['bg_type']); ?>>
						<?php esc_html_e('Solid background', 'enable-media-replace'); ?>
					</label>
					<p class="howto">
						<?php esc_html_e('The images will get a solid color background. Choose the color below.', 'enable-media-replace'); ?>
					</p>
					<div id="solid_selecter" style="display:none;">
						<label for="bg_display_picker">
							<p><?php esc_html_e('Background Color:','enable-media-replace'); ?> <strong>
								<span style="text-transform: uppercase;" id="color_range">
									<?php echo esc_attr($view->settings['bg_color']); ?></span>
								</strong>
							</p>
							<input type="color" value="<?php echo esc_attr($view->settings['bg_color']); ?>" name="bg_display_picker" id="bg_display_picker" />
							<input type="hidden"  value="<?php echo esc_attr($view->settings['bg_color']); ?>" name="bg_color" id="bg_color" />
						</label>
						<hr>
						<label for="bg_transparency">
							<p><?php esc_html_e('Opacity:', 'enable-media-replace'); ?>
								<strong>
									<span id="transparency_range"><?php echo esc_attr($view->settings['bg_transparency']); ?></span>%</strong>
							</p>
							<input type="range" min="0" max="100" value="<?php echo esc_attr($view->settings['bg_transparency']); ?>" id="bg_transparency" />
						</label>
					</div>
				</div>

				<p class="emr-bulk-group-label"><?php esc_html_e('Processing', 'enable-media-replace'); ?></p>
				<div class="option emr-bulk-toggle">
					<label for="emr_skip_processed">
						<input id="emr_skip_processed" type="checkbox" name="skip_processed" value="1" checked>
						<?php esc_html_e('Skip images that were already processed', 'enable-media-replace'); ?>
					</label>
					<p class="howto"><?php esc_html_e('Images whose background was already removed by a previous bulk run are left as they are.', 'enable-media-replace'); ?></p>
				</div>
				<div class="option emr-bulk-toggle">
					<label for="emr_background_mode">
						<input id="emr_background_mode" type="checkbox" name="background_mode" value="1">
						<?php esc_html_e('Background mode', 'enable-media-replace'); ?>
					</label>
					<p class="howto"><?php esc_html_e('The images are processed on the server with WP-Cron, so you can close this page or your browser and the bulk keeps going. It can be a bit slower, since WP-Cron runs about once a minute and depends on visits to your site.', 'enable-media-replace'); ?></p>
					<?php if ($view->cron_disabled) : ?>
						<p class="howto emr-bulk-cron-warning"><?php esc_html_e('WP-Cron is disabled on this site (DISABLE_WP_CRON). Background mode only works if a server cron job calls wp-cron.php.', 'enable-media-replace'); ?></p>
					<?php endif; ?>
				</div>
				<div class="emr-bulk-embedded emr-bulk-account">
					<?php include( emr()->plugin_path('views/api-key-box.php') ); ?>
				</div>

				<div class="emr-bulk-embedded emr-bulk-actions">
					<p class="emr-bulk-group-label"><?php esc_html_e('Run', 'enable-media-replace'); ?></p>
					<p class="emr-bulk-status" id="emr-bulk-status"><?php esc_html_e('Ready to start.', 'enable-media-replace'); ?></p>
					<?php // Start spans both columns, so it is as wide as Pause + Stop. Those only show while a run is going on (is-idle hides them). ?>
					<div class="emr-bulk-buttons is-idle" id="emr-bulk-buttons">
						<button type="button" class="button button-primary emr-bulk-start" id="emr-bulk-start" <?php disabled(0 === $counts['total']); ?>><?php esc_html_e('Start Background Removal', 'enable-media-replace'); ?></button>
						<button type="button" class="button emr-bulk-secondary" id="emr-bulk-pause" disabled><?php esc_html_e('Pause', 'enable-media-replace'); ?></button>
						<button type="button" class="button emr-bulk-secondary emr-bulk-stop" id="emr-bulk-stop" disabled><?php esc_html_e('Stop', 'enable-media-replace'); ?></button>
					</div>
				</div>
			</section>

			<section class="wrapper emr-bulk-progress" id="emr-bulk-progress" style="display:none;">
				<div class="section-header"><?php esc_html_e('Progress', 'enable-media-replace'); ?></div>
				<div class="emr-bulk-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<div class="emr-bulk-bar-fill" id="emr-bulk-bar-fill"></div>
				</div>
				<div class="emr-bulk-results">
					<span><?php esc_html_e('Processed:', 'enable-media-replace'); ?> <strong id="emr-bulk-position">0</strong> / <strong id="emr-bulk-total">0</strong></span>
					<span class="emr-bulk-result-ok"><?php esc_html_e('Done:', 'enable-media-replace'); ?> <strong id="emr-bulk-ok">0</strong></span>
					<span class="emr-bulk-result-fail"><?php esc_html_e('Failed:', 'enable-media-replace'); ?> <strong id="emr-bulk-fail">0</strong></span>
				</div>

				<div class="emr-bulk-progress-body">
					<div class="emr-bulk-preview" id="emr-bulk-preview" style="display:none;">
						<div class="emr-bulk-preview-pair">
							<figure class="emr-bulk-preview-image" id="emr-bulk-preview-before">
								<figcaption><?php esc_html_e('Original', 'enable-media-replace'); ?></figcaption>
								<div class="emr-bulk-preview-frame"></div>
							</figure>
							<figure class="emr-bulk-preview-image is-after" id="emr-bulk-preview-after">
								<figcaption><?php esc_html_e('Without background', 'enable-media-replace'); ?></figcaption>
								<div class="emr-bulk-preview-frame"></div>
							</figure>
						</div>
						<p class="emr-bulk-preview-title" id="emr-bulk-preview-title"></p>
						<p class="emr-bulk-preview-next" id="emr-bulk-preview-next"></p>
					</div>

					<div class="emr-bulk-history" id="emr-bulk-history" style="display:none;">
						<p class="emr-bulk-history-title"><?php esc_html_e('History', 'enable-media-replace'); ?></p>
						<ul class="emr-bulk-log" id="emr-bulk-log"></ul>
					</div>
				</div>
			</section>

		</div> <!--- editor wrapper -->
		<?php include_once( 'upsell.php' ); ?>
	</form>
</div>
