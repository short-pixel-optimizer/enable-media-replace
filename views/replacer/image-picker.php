<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

//use \EnableMediaReplace\UIHelper;
use function EnableMediaReplace\EMR as EMR;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

$image = $view->image;
$attachment_id = $image->image_id;
$uiHelper = emr()->uiHelper();

?>

<section class='image_chooser wrapper'>
  <div class='section-header'> <?php _e('Select Replacement Media', 'enable-media-replace'); ?>

  </div>


<input type="hidden" name="ID" value="<?php echo esc_attr($attachment_id) ?>" />

<p class='explainer'>
  <?php printf(esc_html__('			You are about to replace %s in your media library. This will be %spermanent%s. %s You can click on the new image panel and select a file from your computer. You can also drag and drop a file into this window', 'enable-media-replace'), '<b class="underline" title="' . $image->getFullPath() . '">' . $image->getFileName()  . '</b>', '<b>','</b>', '<br>' );
  ?>
</p>

<p><?php printf(__('Maximum file size: <strong>%s</strong>','enable-media-replace'), size_format(wp_max_upload_size() ) ) ?></p>
<div class='form-error filesize'><p><?php printf(__('%s f %s exceeds the maximum upload size for this site.', 'enable-media-replace'), '<span class="fn">', '</span>'); ?></p>
</div>

<div class='form-warning filetype'><p><?php printf(__('The replacement file does not have the same file type. This can lead to unexpected issues ( %s )', 'enable-media-replace'), '<span class="source_type"></span> - <span class="target_type"></span>'); ?>

</p></div>

<div class='form-warning mimetype'><p><?php printf(__('The replacement file type does not seem to be allowed by WordPress. This can lead to unexpected issues')); ?></p></div>

<div class='image_previews'>

          <?php

          if (wp_attachment_is('image', $attachment_id) || $view->sourceMime == 'application/pdf')
          {
              echo $uiHelper->getPreviewImage($attachment_id, $image);
              echo $uiHelper->getPreviewImage(-1, $image, array('is_upload' => true));
          }
          else {

                if (strlen($image->getFullPath()) == 0) // check if image in error state.
                {
                    echo $uiHelper->getPreviewError(-1);
                    echo $uiHelper->getPreviewImage(-1, $image, array('is_upload' => true));
                }
                else {
                    echo $uiHelper->getPreviewFile($attachment_id, $image);
                    echo $uiHelper->getPreviewFile(-1, $image, array('is_upload' => true));
                }

          }
          ?>
  </div>
  <?php
    $url = admin_url("upload.php");
    $url = add_query_arg(array(
    'page' => 'enable-media-replace/enable-media-replace.php',
    'action' => 'emr_prepare_remove',
    'attachment_id' => $attachment_id,
    ), $url);
  ?>

  <p>&nbsp;</p>
  <?php if (true === $image->isBackgroundRemovable()): ?>
              <div>

                <a href="<?php echo wp_nonce_url( $url , 'emr_prepare_remove' ); ?>">
                  <?php _e('New! Click here to remove the background of this image!', 'enable-media-replace'); ?></a>
                <br>
                <br>
                <input type="checkbox" id="remove_after_progress" name="remove_after_progress" value="<?php echo esc_attr($attachment_id); ?>">
                <label for="remove_after_progress"><?php _e('Remove the background after replacing this image!' ,'enable-media-replace'); ?> </label>
              </div>
   <?php endif; ?>
</section>
