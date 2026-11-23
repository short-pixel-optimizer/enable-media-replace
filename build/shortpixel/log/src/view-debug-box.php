<?php
/**
 * Admin debug box template of the logger.
 *
 * Included by ShortPixelLogger::loadView() on `admin_footer` when logging
 * was enabled through the `SHORTPIXEL_DEBUG` request parameter. Renders a
 * small draggable box (jQuery UI draggable is enqueued here) with a link
 * that opens the log file. Expects from the including scope:
 * - `$view` (\stdClass) with `namespace` (string, shown in the header) and
 *   `logLink` (string, unescaped URL of the log file, escaped here; empty
 *   when no log path is set, in which case no link is printed).
 * - `$controller` (ShortPixelLogger), available but not used.
 *
 * @package EnableMediaReplace\ShortPixelLogger
 */
namespace EnableMediaReplace\ShortPixelLogger;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

wp_enqueue_script( 'jquery-ui-draggable' );
?>

<style>
  .sp_debug_wrap
  {
    position: relative;
    clear: both;
  }
  .sp_debug_box
  {
    position: absolute;
    right: 0px;
    top: 50px;
    background-color: #fff;
    width: 150px;
    z-index: 1000000;
    border: 1px solid #000;

  }
  .sp_debug_box .header
  {
    min-height: 10px;
    background: #000;
    color: #fff;
    padding: 8px
  }
  .sp_debug_box .content_box
  {
    background: #ccc;
  }
  .content_box
  {
    padding: 8px;
  }
</style>

<script language='javascript'>
  jQuery(document).ready(function($)
  {
     $( ".sp_debug_box" ).draggable();

  });
</script>


<div class='sp_debug_box'>
     <div class='header'><?php echo esc_html($view->namespace) ?> Debug Box </div>
     <?php if ('' !== $view->logLink) : ?>
     <a target="_blank" rel="noopener" href='<?php echo esc_url($view->logLink); ?>'>Logfile</a>
     <?php endif; ?>
     <div class='content_box'>

     </div>
</div>
