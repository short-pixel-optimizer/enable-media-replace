<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

//use \EnableMediaReplace\UIHelper;
use function EnableMediaReplace\EMR as EMR;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

$image = $view->image;

$settings = $view->settings;
?>

<section class='replace_type wrapper'>
  <div class='section-header'> <?php _e('Replacement Options', 'enable-media-replace'); ?></div>

        <?php
    // these are also used in externals, for checks.
    do_action( 'emr_before_replace_type_options' ); ?>

   <?php
     $enabled_search = apply_filters( 'emr_display_replace_type_options', true );
     $search_disabled = (! $enabled_search) ? 'disabled' : '';
  ?>
    <div class='option replace <?php echo $search_disabled ?>'>
        <label for="replace_type_1"  ><input <?php checked('replace', $settings['replace_type']) ?> id="replace_type_1" type="radio" name="replace_type" value="replace" <?php echo $search_disabled ?> > <?php echo esc_html__("Just replace the file", "enable-media-replace"); ?>
      </label>

        <p class="howto">
          <?php printf( esc_html__("Note: This option requires you to upload a file of the same type (%s) as the file you want to replace. The attachment name will remain the same (%s) regardless of what the file you upload is called. If a CDN is used, remember to clear the cache for this image!", "enable-media-replace"), $image->getExtension(), $image->getFileName() ); ?>
      </p>

      <p class='form-warning filetype'><?php _e('If you replace the file with a different filetype, this file might become unreadable and / or cause unexpected issues', 'enable-media-replace'); ?>
      </p>

      <?php do_action('emr_after_search_type_options'); ?>
    </div>

        <?php $enabled_replacesearch = apply_filters( 'emr_enable_replace_and_search', true );
      $searchreplace_disabled = (! $enabled_replacesearch) ? 'disabled' : '';
    ?>

    <div class="option searchreplace <?php echo $searchreplace_disabled ?>">
        <label for="replace_type_2"><input id="replace_type_2" <?php checked('replace_and_search', $settings['replace_type']) ?> type="radio" name="replace_type" value="replace_and_search" <?php echo $searchreplace_disabled ?> > <?php echo __("Replace the file, use the new file name, and update all links", "enable-media-replace"); ?>
    </label>

        <p class="howto"><?php printf( esc_html__("Note: If you enable this option, the name and type of the file you are uploading will replace the old file. All links pointing to the current file (%s) will be updated to point to the new file name. (If other websites link directly to the file, those links will no longer work. Be careful!)", "enable-media-replace"), $image->getFileName() ); ?></p>

   <!-- <p class="howto"><?php echo esc_html__("Please note that if you upload a new image, only the embeds/links of the original size image will be replaced in your posts.", "enable-media-replace"); ?></p> -->

    <?php do_action('emr_after_replace_type_options'); ?>
    </div>

  </section>
