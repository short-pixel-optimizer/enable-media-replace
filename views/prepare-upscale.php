<?php
/**
 * Upscale screen: choose a factor, preview the upscaled image and confirm the replacement.
 *
 * Loaded by UpscaleViewController::load() (inherited from
 * RemoveBackGroundViewController) through ViewController::loadView(), for
 * `upload.php?page=enable-media-replace/enable-media-replace.php&action=emr_prepare_upscale&attachment_id=N`
 * (only when the `upscale` feature is enabled).
 *
 * Variables in scope:
 * - $controller UpscaleViewController
 * - $view stdClass set by the controller:
 *   - attachment (\WP_Post) the attachment
 *   - sourceFile (FileModel) current main file, for the previews
 *   - settings (array) the background settings the inherited load() reads; unused here
 *
 * Form `#emr_replace_form`: POST to UIHelper::getFormUrl($id, 'do_upscale_replace'),
 * handled by the inherited loadPost(). Nonce: action `media_upscale_image`, field
 * `emr_nonce`. Inputs:
 * - ID (hidden) attachment id
 * - key (hidden) empty on load; js/remove_bg.js fills it with the result key
 *   returned by the `emr_remove_background` AJAX call
 * - upscale (radio) one of the factors of UIHelper::getUpscaleFactors(); a factor is
 *   disabled when its maximum width is at or below the metadata width of the image,
 *   the first enabled one is checked, and with none enabled the help text reads "This
 *   image is too big to be upscaled" and the Preview button is disabled
 * The Preview button (`#remove_background_button`, the id the shared script expects)
 * triggers the AJAX call with the checked factor; the result panel's text layer then
 * shows the new dimensions. The Replace submit button stays disabled until a preview
 * succeeds. The result preview is a placeholder with `upscale_ui`.
 *
 * Includes views/shortpixel-account.php with `$emr_account_feature` 'upscale' (the API
 * key box with upscale wording, for users with `manage_options`) and views/upsell.php.
 * Links: "Replace this image with another one instead" goes to `action=media_replace`
 * with a nonce for `media_replace`; Cancel calls history.back().
 *
 * @todo The form action $formurl is echoed without escaping; wrap it in esc_url().
 */
namespace EnableMediaReplace;

use EnableMediaReplace\EnableMediaReplacePlugin;
use EnableMediaReplace\UIHelper;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$uiHelper = emr()->uiHelper();

$attachment_id = $view->attachment->ID;

$uiHelper->setPreviewSizes();
$uiHelper->setSourceSizes($attachment_id);

$base_image = $uiHelper->getPreviewImage($attachment_id, $view->sourceFile );
$replace_image = $uiHelper->getPreviewImage(-1, $view->sourceFile, array('upscale_ui' => true) );

$formurl = $uiHelper->getFormUrl($attachment_id, 'do_upscale_replace');

$replace_url = add_query_arg(array(
'page' => 'enable-media-replace/enable-media-replace.php',
'action' => 'media_replace',
'attachment_id' => $attachment_id,
), admin_url("upload.php"));

$meta = wp_get_attachment_metadata($attachment_id);
$image_width = (is_array($meta) && isset($meta['width'])) ? intval($meta['width']) : 0;

$has_scale_option = false;
$scale_checked = false;

?>

<div id="emr-bg-notice" class="notice notice-error is-dismissible" style="display:none;">
	<p></p>
	<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php esc_html_e('Dismiss this notice.', 'enable-media-replace'); ?></span></button>
</div>

<div class="wrap emr_upload_form emr-upscale-form" id="remove-background-form">

	<form id="emr_replace_form" enctype="multipart/form-data" method="POST" action="<?php
	echo $formurl; ?>" >

	<?php wp_nonce_field('media_upscale_image', 'emr_nonce'); ?>


	<input type="hidden" name="ID" value="<?php echo intval($attachment_id); ?>" />
	<input type='hidden' name='key' value='' />

		<div class="editor-wrapper" >
			<section class='image_chooser wrapper'>
				<div class='section-header'> <?php esc_html_e( 'Upscale Image', 'enable-media-replace' ); ?></div>
				<div class='image_previews'>
						<?php echo $base_image; ?>
						<?php echo $replace_image ?>

				</div>

			</section>

			<p><a href="<?php echo esc_attr(wp_nonce_url($replace_url, 'media_replace')); ?>">
					<?php esc_html_e('Replace this image with another one instead!', 'enable-media-replace'); ?>
			</a></p>
			<div class="option-flex-wrapper">
				<section class="replace_type wrapper">
					<div class="section-header"><?php esc_html_e('Upscale Options', 'enable-media-replace'); ?></div>
					<div class="option replace ">
						<p>
							<?php esc_html_e('If a CDN is used, remember to clear the cache for this image!', 'enable-media-replace'); ?>
						</p>
						<?php foreach ($uiHelper->getUpscaleFactors() as $factor => $max_width) :
							$disabled = ($image_width > 0 && $max_width <= $image_width);
							$checked = (false === $disabled && false === $scale_checked);
							if (false === $disabled) {
								$has_scale_option = true;
							}
							if ($checked) {
								$scale_checked = true;
							}
						?>
						<label for="upscale_<?php echo intval($factor); ?>">
							<input id="upscale_<?php echo intval($factor); ?>" type="radio" name="upscale" value="<?php echo intval($factor); ?>" <?php checked($checked); ?> <?php disabled($disabled); ?> >
							<?php echo esc_html($factor . 'x'); ?>
						</label>
						<?php endforeach; ?>
						<p class="howto">
							<?php
							if ($has_scale_option) {
								esc_html_e('Enlarges the image with the selected factor using AI upscaling. The current image will be replaced with the upscaled version.', 'enable-media-replace');
							} else {
								esc_html_e('This image is too big to be upscaled.', 'enable-media-replace');
							}
							?>
						</p>
					</div>
				</section>


			</div>

			<?php
			$emr_account_feature = 'upscale';
			include( __DIR__ . '/shortpixel-account.php' );
			?>

			<button type="button" class="button button-primary" id="remove_background_button" <?php disabled(! $has_scale_option); ?>><?php esc_html_e('Preview', 'enable-media-replace'); ?></button>
			<button type="submit" class="button button-primary" id="replace_image_button" disabled><?php esc_html_e('Replace', 'enable-media-replace'); ?></button>
			<a class="button" href="javascript:history.back()"><?php esc_html_e('Cancel', 'enable-media-replace'); ?></a>
		</div> <!--- editor wrapper -->
		<?php include_once( 'upsell.php' ); ?>
	</form>
</div>
