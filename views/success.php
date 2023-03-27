<?php
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

	<p><?php _e('Did you know that you can also optimize the images on your website to make them load faster?', 'enable-media-replace'); ?></p>

	<p><?php printf(esc_html__('Try the %s ShortPixel Image Optimizer %s plugin!', 'enable-media-replace'), '<a href="https://wordpress.org/plugins/shortpixel-image-optimiser/" target="_blank">', '</a>'); ?></p>

	<p>You will be redirect to the image screen in a few seconds.
	( <span id='redirect_counter'></span> ) or <a id='redirect_url' href="<?php echo esc_url( $view->postUrl ) ?>">click here to continue</a>
</p>

	</div>

</div>

<?php
require_once ABSPATH . 'wp-admin/admin-footer.php';
