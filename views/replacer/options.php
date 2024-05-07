<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

//use \EnableMediaReplace\UIHelper;
use function EnableMediaReplace\EMR as EMR;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Controller\ReplaceController as ReplaceController;


$image = $view->image;
$uiHelper = emr()->uiHelper();

$settings = $view->settings;
?>

<section class='options wrapper'>
  <div class='section-header'> <?php _e('Options', 'enable-media-replace'); ?></div>
  <div class='option timestamp'>
    <?php
      $attachment_current_date = date_i18n('d/M/Y H:i', strtotime($image->post_date) );
      $attachment_now_date = date_i18n('d/M/Y H:i' );

      $time = current_time('mysql');
      $date = $nowDate = new \dateTime($time); // default to now.
      $attachmentDate = new \dateTime($image->post_date);


      if ($settings['timestamp_replace'] == ReplaceController::TIME_CUSTOM)
      {
         $date = new \dateTime($settings['custom_date']);
      }
    ?>
      <p><?php _e('When replacing the media, do you want to:', 'enable-media-replace'); ?></p>
      <ul>
        <li><label><input type='radio' <?php checked('1', $settings['timestamp_replace']) ?> name='timestamp_replace' value='1' /><?php printf(__('Replace the date with the current date %s(%s)%s', 'enable-media-replace'), "<span class='small'>", $attachment_now_date, "</span>") ; ?></label></li>
        <li><label><input type='radio' <?php checked('2', $settings['timestamp_replace']) ?> name='timestamp_replace' value='2'  /><?php printf(__('Keep the date %s(%s)%s', 'enable-media-replace'), "<span class='small'>", $attachment_current_date, "</span>"); ?></label></li>
        <li><label><input type='radio' <?php checked('3', $settings['timestamp_replace']) ?> name='timestamp_replace' value='3' /><?php _e('Set a Custom Date', 'enable-media-replace'); ?></label></li>
      </ul>
      <div class='custom_date'>

        <span class='field-title dashicons dashicons-calendar'><?php _e('Custom Date', 'enable-media-replace'); ?></span>
       <input type='text' name="custom_date" value="<?php echo $date->format(get_option('date_format')); ?>" id='emr_datepicker'
        class='emr_datepicker' />

       @ <input type='text' name="custom_hour" class='emr_hour' id='emr_hour' value="<?php echo $date->format('H') ?>" /> &nbsp;
        <input type="text" name="custom_minute" class='emr_minute' id='emr_minute' value="<?php echo $date->format('i'); ?>" />
        <input type="hidden" name="custom_date_formatted" value="<?php echo $date->format('Y-m-d'); ?>" />

        <span class="replace_custom_date_wrapper">
        <?php
        printf('<a class="replace_custom_date" data-date="%s" data-hour="%s" data-min="%s" data-format="%s">%s</a>', $nowDate->format(get_option('date_format')), $nowDate->format('H'), $nowDate->format('i'), $nowDate->format('Y-m-d'), __('Now', 'enable-media-replace'));
        echo " ";
        printf('<a class="replace_custom_date" data-date="%s" data-hour="%s" data-min="%s" data-format="%s">%s</a>', $attachmentDate->format(get_option('date_format')), $attachmentDate->format('H'), $attachmentDate->format('i'), $attachmentDate->format('Y-m-d'), __('Original', 'enable-media-replace'));
        ?>
      </span>
     </div>
     <?php if ($subdir = $uiHelper->getRelPathNow()):

        if ($settings['new_location'] !== false)
           $subdir = $settings['new_location_dir'];
      ?>

<!--
    <div class='title_option'>
        <input type="text" name="new_title" value="">
    </div>
-->

     <div class='location_option'>
       <?php
       if (true === $view->is_movable): ?>
       <label><input type="checkbox" name="new_location" value="1" <?php checked($settings['new_location'], 1); ?>  /> <?php _e('Place the newly uploaded file in this folder: ', 'enable-media-replace'); ?></label>
       <br>
        <?php echo $view->custom_basedir ?> <input type="text" name="location_dir" value="<?php echo $subdir ?>" />
        <?php
        else:
            echo __('File is offloaded and can\'t be moved to other directory', 'enable-media-replace');
        endif;
       ?>
      </div>
    <?php endif; ?>

    <div class='title_option'>
      <label><input type="checkbox" name="keep_title" value="1" <?php checked($settings['keep_title'], 1); ?>
        />
        <?php _e('Keep title from old image', 'enable-media-replace' ); ?>

      </label>
    </div>
  </div>

</section>
