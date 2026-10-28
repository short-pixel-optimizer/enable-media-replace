<?php
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
