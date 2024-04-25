<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

//use \EnableMediaReplace\UIHelper;
use function EnableMediaReplace\EMR as EMR;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Controller\ReplaceController as ReplaceController;


 if ( ! defined( 'ABSPATH' ) )
	exit; // Exit if accessed directly.

if (!current_user_can('upload_files'))
	wp_die( esc_html__('You do not have permission to upload files.', 'enable-media-replace') );


$image = $view->image;
$attachment_id = $image->image_id;


//$sourceFile = $view->sourceFile;

$uiHelper = emr()->uiHelper();
$env = emr()->env();
$fs = emr()->filesystem();


?>

<div class="wrap emr_upload_form">

	<div class='emr_drop_area' id='emr-drop-area'>
    <h3><?php _e('Drop File', 'enable-media-replace'); ?></h3>
    <p class='close'><?php _e('Click to Close', 'enable-media-replace'); ?> </p>
  </div>

	<h1 class='emr-title'>
    <?php echo esc_html__("Replace Media Upload", "enable-media-replace"); ?>
    <span class='small-title'>

      <?php echo $uiHelper->getSubtitle(); ?>
    </span>
  </h1>

	<?php

	$formurl = $uiHelper->getFormUrl($attachment_id);

	if (FORCE_SSL_ADMIN) {
			$formurl = str_replace("http:", "https:", $formurl);
		}
	?>

	<form enctype="multipart/form-data" method="POST" action="<?php echo esc_url($formurl); ?>">
		<?php wp_nonce_field('media_replace_upload', 'emr_nonce'); ?>

<div class='editor-wrapper'>
    <?php $this->loadView('replacer/image-picker'); ?>


<div class='option-flex-wrapper'>
    <?php $this->loadView('replacer/replace-options'); ?>
    <?php $this->loadView('replacer/options'); ?>

  </div>


 <?php if (true === $env->isOffLoadActive())
 { ?>
  <section class='offload-warning'>
     <?php _e('EMR detected an active offload plugin. ', 'enable-media-replace'); ?>
  </section>
<?php } ?>

  <section class='form_controls wrapper'>
    <a href="#" class="button" onclick="history.back();"><?php echo esc_html__("Back", "enable-media-replace"); ?></a>
		<input id="submit" type="submit" class="button button-primary" disabled="disabled" value="<?php echo esc_attr__("Upload", "enable-media-replace"); ?>" />

  </section>
</div>

	<?php include_once('upsell.php'); ?>



	</form>
</div>
