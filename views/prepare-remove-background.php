<?php
namespace EnableMediaReplace;

use EnableMediaReplace\EnableMediaReplacePlugin;
use EnableMediaReplace\UIHelper;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$date     = new \dateTime();
$uiHelper = new UIHelper();

$attachment_id = intval($_GET['attachment_id']);
$attachment = get_post($attachment_id);

$replacer = new Replacer($attachment_id);

$file = $replacer->getSourceFile();

$uiHelper->setPreviewSizes();
$uiHelper->setSourceSizes($attachment_id);

$base_image = $uiHelper->getPreviewImage($attachment_id, $file );
$replace_image = $uiHelper->getPreviewImage(-1, $file, array('remove_bg_ui' => true) );

$formurl = $uiHelper->getFormUrl($attachment_id, 'do_background_replace');
$formurl = wp_nonce_url( $formurl, "do_background_replace" );

$linebreak = '%0D%0A';
$linebreak_double = $linebreak . $linebreak;
$email_subject = __('Bad remove of background report', 'enable-media-replace');
$email_body = sprintf(__('Hello! %s This is a report of a background removal that did not go well %s Url: {url} %s Settings : {settings} %s Thank you! %s', 'enable-media-replace'), $linebreak_double, $linebreak_double, $linebreak, $linebreak_double, $linebreak_double);

?>
<div class="wrap emr_upload_form" id="remove-background-form">

	<form id="emr_replace_form" enctype="multipart/form-data" method="POST" action="<?php
	echo $formurl; ?>" >
	<input type="hidden" name="ID" value="<?php echo intval($attachment_id); ?>" />
	<input type='hidden' name='key' value='' />

		<div class="editor-wrapper" >
			<section class='image_chooser wrapper'>
				<div class='section-header'> <?php esc_html_e( 'Remove Media Background', 'enable-media-replace' ); ?></div>
				<div class='image_previews'>
						<?php echo $base_image; ?>
						<?php echo $replace_image ?>

				</div>

				<div class='bad-button'>
						<a href="" data-link="mailto:support@shortpixel.com?subject=<?php echo esc_attr($email_subject) ?>&body=<?php echo esc_attr($email_body) ?>" id="bad-background-link" class="button"><?php esc_html_e('Report bad background removal','enable-media-replace'); ?></a>

				</div>

			</section>
			<div class="option-flex-wrapper">
				<section class="replace_type wrapper">
					<div class="section-header"><?php esc_html_e('Background Removal Options'); ?></div>
					<div class="option replace ">
						<label for="transparent_background">
							<input checked="checked" id="transparent_background" type="radio" name="background_type" value="transparent">
							<?php esc_html_e('Transparent/white background', 'enable-media-replace'); ?>
						</label>
						<p class="howto">
							<?php esc_html_e('Returns a transparent background if it is a PNG image, or a white one if it is a JPG image.', 'enable-media-replace'); ?>
						</p>
					</div>
					<div class="option searchreplace">
						<label for="solid_background">
							<input id="solid_background" type="radio" name="background_type" value="solid">
							<?php esc_html_e('Solid background', 'enable-media-replace'); ?>
						</label>
						<p class="howto">
							<?php esc_html_e('If you select this option, the image will have a solid color background and you can choose the color code from the color picker below.', 'enable-media-replace'); ?>
						</p>
						<div id="solid_selecter" style="display:none;">
							<label for="bg_display_picker">
								<p><?php esc_html_e('Background Color:','enable-media-replace'); ?> <strong><span style="text-transform: uppercase;" id="color_range">#ffffff</span></strong></p>
								<input type="color" value="#ffffff" name="bg_display_picker" id="bg_display_picker" />
								<input type="hidden"  value="#ffffff" name="bg_color" id="bg_color" />
							</label>
							<hr>
							<label for="bg_transparency">
								<p><?php esc_html_e('Opacity:', 'enable-media-replace'); ?> <strong><span id="transparency_range">100</span>%</strong></p>
								<input type="range" min="0" max="100" value="100" id="bg_transparency" />
							</label>
						</div>
					</div>
				</section>

			<!--
				<section class="options wrapper">


					<div class="section-header"><?php esc_html_e('Image Compression', 'enable-media-replace'); ?></div>
						<div class="option replace">
							<label for="lossy">
								<input id="lossy" type="radio" name="compression_level" value="1">
								<?php esc_html_e('Lossy compression','enable-media-replace'); ?>
							</label>
							<p class="howto">
								<?php esc_html_e('Lossy has a better compression rate than lossless compression. The resulting image is not 100% identical with the original. Works well for photos taken with your camera.', 'enable-media-replace'); ?>
							</p>
						</div>
						 <div class="option searchreplace">
							<label for="glossy">
								<input id="glossy" type="radio" name="compression_level" value="2">
								<?php esc_html_e('Glossy compression', 'enable-media-replace'); ?>
							</label>
							<p class="howto">
								<?php esc_html_e('Creates images that are almost pixel-perfect identical to the originals. Best option for photographers and other professionals that use very high quality images on their sites and want best compression while keeping the quality untouched.', 'enable-media-replace'); ?>
							</p>
						</div>
						<div class="option searchreplace">
							<label for="lossless">
								<input checked="checked" id="lossless" type="radio" name="compression_level" value="0">
								<?php esc_html_e('Lossless compression', 'enable-media-replace'); ?>
							</label>
							<p class="howto">
								<?php esc_html_e('The shrunk image will be identical with the original and smaller in size. Use this when you do not want to loose any of the original image\'s details. Works best for technical drawings, clip art and comics.', 'enable-media-replace');  ?>
							</p>
						</div>
				</section> -->
			</div>
			<button type="button" class="button button-primary" id="remove_background_button"><?php esc_html_e('Preview', 'enable-media-replace'); ?></button>
			<button type="submit" style="display:none;" class="button button-primary" id="replace_image_button"><?php esc_html_e('Replace', 'enable-media-replace'); ?></button>
			<a class="button" href="javascript:history.back()"><?php esc_html_e('Cancel', 'enable-media-replace'); ?></a>
		</div> <!--- editor wrapper -->
		<?php include_once( 'upsell.php' ); ?>
	</form>
</div>
