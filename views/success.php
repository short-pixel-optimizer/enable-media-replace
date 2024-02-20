<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

//use \EnableMediaReplace\UIHelper;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use function EnableMediaReplace\EMR as EMR;

$env = EMR()->env();

?>

<div class='enable-media-replace emr-screen success-screen'>
	<h3 class='title'><?php _e('Enable Media Replace', 'enable-media-replace'); ?></h3>
	<div class='content'>
	<h1><?php _e('Your image has been replaced!', 'enable-media-replace'); ?></h1>

	<p><?php _e('Your image has been successfully replaced!', 'enable-media-replace'); ?></p>


  <p><?php
  printf(__('If you enjoy using this plugin, please %s leave us a ⭐⭐⭐⭐⭐ review %s, it would help us a lot!', 'enable-media-replace'), '<a href="https://wordpress.org/support/plugin/enable-media-replace/reviews/#new-post" target="_blank">', '</a>');
  ?>

  </p>

  <?php
  $spio_active = $env->plugin_active('shortpixel');
  if (true === $env->canInstallPlugins() && false === $spio_active)
  { ?>
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
require_once (ABSPATH . 'wp-admin/admin-footer.php');
