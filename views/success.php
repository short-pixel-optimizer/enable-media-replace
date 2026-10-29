<?php
/**
 * Success screen shown after a replacement, with a countdown redirect.
 *
 * Loaded by ViewController::viewSuccess() (from UploadViewController and
 * RemoveBackGroundViewController::loadPost()), which enqueues js/emr_success.js
 * and exits after the view. The view ends by requiring
 * wp-admin/admin-footer.php itself.
 *
 * Variables in scope:
 * - $controller the calling ViewController
 * - $view stdClass with:
 *   - postUrl (string) redirect target: the attachment edit screen
 *     (`post.php?action=edit&post=N&emr_replaced=1`), or the remove-background
 *     screen when the upload form had `remove_after_progress` checked
 *   - post_id (int) and emrUrl (string) are also set, but unused here
 *
 * Countdown: js/emr_success.js writes the remaining seconds into
 * `#redirect_counter` and navigates to the href of `#redirect_url` when it
 * reaches zero; the timeout comes from `emr_success_options.timeout`
 * (filter `emr/success/timeout`, default 5).
 *
 * Hooks fired: filter `emr/upsell` (default current_user_can('install_plugins'))
 * gates the ShortPixel Image Optimizer recommendation line.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

//use \EnableMediaReplace\UIHelper;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

?>

<div class='enable-media-replace emr-screen success-screen'>
	<h3 class='title'><?php _e('Enable Media Replace', 'enable-media-replace'); ?></h3>
	<div class='content'>
	<h1><?php _e('Your image has been replaced!', 'enable-media-replace'); ?></h1>

	<p><?php _e('Your image has been successfully replaced!', 'enable-media-replace'); ?></p>

	<?php if (apply_filters('emr/upsell', current_user_can('install_plugins'))) { ?>
		<p><?php _e('Did you know that you can also optimize the images on your website to make them load faster?', 'enable-media-replace'); ?></p>

		<p><?php printf(esc_html__('Try the %sShortPixel Image Optimizer%s plugin!', 'enable-media-replace'), '<a href="https://wordpress.org/plugins/shortpixel-image-optimiser/" target="_blank">', '</a>'); ?></p>
	<?php } ?>

	<p><?php _e('You will be redirect to the image screen in a few seconds.', 'enable-media-replace');
		printf(esc_html__('( %s ) or %s click here to continue %s', 'enable-media-replace'), '<span id="redirect_counter"></span>',
		'<a id="redirect_url" href="' . esc_url( $view->postUrl ) .  '">', '</a>');
	?>

</p>

	</div>

</div>

<?php
require_once ABSPATH . 'wp-admin/admin-footer.php';
