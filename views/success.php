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

	<p>Congratualations. Your image has been replaced!</p>

	<p>You will be redirect to the image screen in a few seconds.
	( <span id='redirect_counter'></span> ) or <a id='redirect_url' href="<?php echo $view->postUrl ?>">click here to continue</a>
</p>

	</div>

</div>

<?php
require_once ABSPATH . 'wp-admin/admin-footer.php';
