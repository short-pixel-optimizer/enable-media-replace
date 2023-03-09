<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

//use \EnableMediaReplace\UIHelper;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


?>

<div class='enable-media-replace emr-screen error-screen'>
	<h3 class='title'><?php _e('Enable Media Replace', 'enable-media-replace'); ?></h3>

	<div class='content'>
		<h1><?php _e('An error occured', 'enable-media-replace'); ?></h1>
		<p class="error-message"> <?php echo $view->errorMessage; ?> </p>

		<p>You can <a href='javascript:history.back()'>return to previous page</a> </p>

		<PRE class='hide'><?php print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10)); ?></PRE>
	</div>
</div> <!--- screen -->


<?php
require_once ABSPATH . 'wp-admin/admin-footer.php';
