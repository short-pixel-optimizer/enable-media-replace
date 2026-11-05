<?php
/**
 * Remove-background screen: preview the background removal and confirm the replacement.
 *
 * Loaded by RemoveBackGroundViewController::load() through
 * ViewController::loadView(), for
 * `upload.php?page=enable-media-replace/enable-media-replace.php&action=emr_prepare_remove&attachment_id=N`
 * (only when the `background` feature is enabled).
 *
 * Variables in scope:
 * - $controller RemoveBackGroundViewController
 * - $view stdClass set by the controller:
 *   - attachment (\WP_Post) the attachment
 *   - sourceFile (FileModel) current main file, for the previews
 *   - settings (array) `enable_media_replace` option merged with defaults:
 *     bg_type (`transparent`|`solid`), bg_color (hex), bg_transparency (0-100)
 * Uses ApiKeyManager (hasApiKey, getMaskedKey) and current_user_can('manage_options').
 *
 * Form `#emr_replace_form`: POST to UIHelper::getFormUrl($id, 'do_background_replace'),
 * handled by RemoveBackGroundViewController::loadPost(). Nonce: action
 * `media_remove_background`, field `emr_nonce`. Inputs:
 * - ID (hidden) attachment id
 * - background_type `transparent` or `solid`
 * - bg_display_picker (colour picker) and bg_color (hidden hex copy used by the script)
 * - bg_transparency (range, 0-100)
 * loadPost() runs the request again with these three on the original (nothing of the
 * preview is stored), so they are posted with the form.
 * The Preview button (`#remove_background_button`) triggers the AJAX call
 * (js/remove_bg.js, localised as `emrObject` with nonce `emr_remove_background`);
 * the Replace submit button stays disabled until a preview succeeds.
 *
 * Includes views/shortpixel-account.php (the ShortPixel API key box, rendered
 * for users with `manage_options`; see that file) and views/upsell.php
 * (filter `emr/upsell`).
 *
 * Header: the white `.emr-page-header` bar. Under the previews,
 * views/operation-buttons.php shows the Replace media and Upscale image
 * buttons. "Report a bad result" is a mailto: to support whose {url} and
 * {settings} placeholders the script fills, shown with its hint once a
 * preview exists (`.bad-button.is-ready`); Cancel calls history.back(). The
 * result pane says "Click Preview to see the result" until the first preview;
 * js/emr-magnifier.js adds the hover magnifier to both panes.
 *
 * @todo The form action $formurl is echoed without escaping; wrap it in esc_url().
 */
namespace EnableMediaReplace;

use EnableMediaReplace\EnableMediaReplacePlugin;
use EnableMediaReplace\UIHelper;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$date     = new \dateTime();
$uiHelper = emr()->uiHelper();

$attachment_id = $view->attachment->ID;
//$attachment_id = intval($_GET['attachment_id']);
//$attachment = get_post($attachment_id);

//$replacer = new Replacer($attachment_id);
//$file = $replacer->getSourceFile();

$uiHelper->setPreviewSizes();
$uiHelper->setSourceSizes($attachment_id);

$base_image = $uiHelper->getPreviewImage($attachment_id, $view->sourceFile );
$replace_image = $uiHelper->getPreviewImage(-1, $view->sourceFile, array('remove_bg_ui' => true, 'hint' => __('Click Preview to see the result', 'enable-media-replace')) );

$formurl = $uiHelper->getFormUrl($attachment_id, 'do_background_replace');
//$formurl = wp_nonce_url( $formurl, "do_background_replace" );

$linebreak = '%0D%0A';
$linebreak_double = $linebreak . $linebreak;
$email_subject = __('Bad background removal report', 'enable-media-replace');
$email_body = sprintf(__('Hello! %s This is a report of a background removal that did not go well %s URL: {url} %s Settings: {settings} %s Thank you! %s', 'enable-media-replace'), $linebreak_double, $linebreak_double, $linebreak, $linebreak_double, $linebreak_double);

?>

<div id="emr-bg-notice" class="notice notice-error is-dismissible" style="display:none;">
	<p></p>
	<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php esc_html_e('Dismiss this notice.', 'enable-media-replace'); ?></span></button>
</div>

<div class="wrap emr_upload_form" id="remove-background-form">
	<header class="emr-page-header">
		<h1><?php esc_html_e('Enable Media Replace - Remove background', 'enable-media-replace'); ?></h1>
	</header>
	<hr class="wp-header-end">
	<?php \emr()->promoBanner()->render(); // The Black Friday banner, when its campaign runs. ?>

	<form id="emr_replace_form" enctype="multipart/form-data" method="POST" action="<?php
	echo $formurl; ?>" >

	<?php wp_nonce_field('media_remove_background', 'emr_nonce'); ?>


	<input type="hidden" name="ID" value="<?php echo intval($attachment_id); ?>" />

		<div class="editor-wrapper" >
			<section class='image_chooser wrapper'>
				<div class='section-header'> <?php esc_html_e( 'Remove media background', 'enable-media-replace' ); ?></div>
				<div class='image_previews'>
						<?php echo $base_image; ?>
						<?php echo $replace_image ?>

				</div>

				<div class='bad-button'>
						<span class="bad-button-hint"><?php esc_html_e('Opens an email to ShortPixel support with the link to the result and your settings.', 'enable-media-replace'); ?></span>
						<a href="" data-link="mailto:support@shortpixel.com?subject=<?php echo esc_attr($email_subject) ?>&body=<?php echo esc_attr($email_body) ?>" id="bad-background-link" class="button"><?php esc_html_e('Report a bad result','enable-media-replace'); ?></a>
				</div>

				<?php
				$emr_operation_current = 'background';
				include __DIR__ . '/operation-buttons.php';
				?>
			</section>

			<div class="option-flex-wrapper">
				<section class="replace_type wrapper">
					<div class="section-header"><?php esc_html_e('Background removal options', 'enable-media-replace'); ?></div>
					<div class="option replace ">
						<label for="transparent_background">
							<input id="transparent_background" type="radio" name="background_type" value="transparent" <?php checked('transparent', $view->settings['bg_type']); ?> >
							<?php esc_html_e('Transparent (white for JPG)', 'enable-media-replace'); ?>
						</label>
						<p class="howto">
							<?php esc_html_e('Returns a transparent background if it is a PNG image, or a white one if it is a JPG image.', 'enable-media-replace'); ?>
						</p>
					</div>
					<div class="option searchreplace">
						<label for="solid_background">
							<input id="solid_background" type="radio" name="background_type" value="solid" <?php checked('solid', $view->settings['bg_type']); ?>>
							<?php esc_html_e('Solid background', 'enable-media-replace'); ?>
						</label>
						<p class="howto">
							<?php esc_html_e('If you select this option, the image will have a solid color background and you can choose the color code from the color picker below.', 'enable-media-replace'); ?>
						</p>
						<div id="solid_selecter" style="display:none;">
							<label for="bg_display_picker">
								<p><?php esc_html_e('Background color','enable-media-replace'); ?>: <strong>
									<span id="color_range">
										<?php echo esc_attr($view->settings['bg_color']); ?></span>
									</strong>
								</p>
								<input type="color" value="<?php echo esc_attr($view->settings['bg_color']); ?>" name="bg_display_picker" id="bg_display_picker" />
								<input type="hidden"  value="<?php echo esc_attr($view->settings['bg_color']); ?>" name="bg_color" id="bg_color" />
							</label>
							<hr>
							<label for="bg_transparency">
								<p><?php esc_html_e('Opacity (%)', 'enable-media-replace'); ?>:
									<strong>
										<span id="transparency_range"><?php echo esc_attr($view->settings['bg_transparency']); ?></span></strong>
								</p>
								<input type="range" min="0" max="100" value="<?php echo esc_attr($view->settings['bg_transparency']); ?>" name="bg_transparency" id="bg_transparency" />
							</label>
						</div>
					</div>
				</section>


			</div>

			<?php
			$emr_account_context = 'background';
			include( __DIR__ . '/shortpixel-account.php' );
			?>

			<button type="button" class="button button-primary" id="remove_background_button"><?php esc_html_e('Preview', 'enable-media-replace'); ?></button>
			<button type="submit" class="button button-primary" id="replace_image_button" disabled><?php esc_html_e('Replace', 'enable-media-replace'); ?></button>
			<a class="button" href="javascript:history.back()"><?php esc_html_e('Cancel', 'enable-media-replace'); ?></a>
		</div> <!--- editor wrapper -->
		<?php include_once( 'upsell.php' ); ?>
	</form>
</div>
