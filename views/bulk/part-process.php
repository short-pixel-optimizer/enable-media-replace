<?php
/**
 * Bulk process panel: progress, the last processed image, errors, controls.
 *
 * Variables in scope: see views/bulk/view-bulk.php.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}

// Shown in the before/after boxes until the first result arrives (same image as SPIO's bulk).
$placeholder = plugins_url('img/bulk/placeholder.svg', EMR_ROOT_FILE);
?>
<section class="emr-bulk-panel emr-bulk-process" data-panel="process" hidden>
	<h2><?php esc_html_e('Processing', 'enable-media-replace'); ?> <span data-operation-label><?php echo esc_html($view->operations[ $view->currentOperation ]->label); ?></span></h2>
	<p class="description"><?php esc_html_e('Keep this page open to finish fastest. Items are sent to ShortPixel, the result replaces the file and the thumbnails are regenerated.', 'enable-media-replace'); ?></p>

	<div class="emr-bulk-progress">
		<div class="emr-bulk-progress-bar"><span class="emr-bulk-progress-done" data-stats-media="percentage_done" data-presentation="css.width.percentage"></span></div>
		<span class="emr-bulk-progress-text"><span data-stats-media="percentage_done">0</span>%</span>
		<span class="dashicons dashicons-update emr-bulk-spinner spin"></span>
	</div>

	<div class="emr-bulk-stats">
		<span><?php esc_html_e('Total', 'enable-media-replace'); ?>: <strong data-stats-media="total">0</strong></span>
		<span><?php esc_html_e('Processed', 'enable-media-replace'); ?>: <strong data-stats-media="done">0</strong></span>
		<span><?php esc_html_e('Processing', 'enable-media-replace'); ?>: <strong data-stats-media="in_process">0</strong></span>
		<span><?php esc_html_e('Waiting', 'enable-media-replace'); ?>: <strong data-stats-media="in_queue">0</strong></span>
		<span><?php esc_html_e('Errors', 'enable-media-replace'); ?>: <strong class="error" data-check-media-fatalerrors data-stats-media="fatal_errors">0</strong>
			<label class="emr-bulk-show-errors" data-check-visibility data-control="data-check-media-fatalerrors"><input type="checkbox" name="emr-show-errors" data-action="ToggleErrorBox" data-event="change"> <?php esc_html_e('Show errors', 'enable-media-replace'); ?></label>
		</span>
	</div>
	<div class="emr-bulk-errorbox" data-error-media="message" hidden><?php if (false !== $view->errorLog) { echo $view->errorLog; /* escaped per line in loadCurrentLog() */ } ?></div>

	<nav>
		<button type="button" class="button" data-action="StopBulk"><span class="dashicons dashicons-no"></span> <?php esc_html_e('Stop', 'enable-media-replace'); ?></button>
		<button type="button" class="button" id="emr-bulk-pause" data-action="PauseBulk"><span class="dashicons dashicons-controls-pause"></span> <?php esc_html_e('Pause', 'enable-media-replace'); ?></button>
		<button type="button" class="button button-primary" id="emr-bulk-resume" data-action="ResumeBulk" hidden><span class="dashicons dashicons-controls-play"></span> <?php esc_html_e('Resume', 'enable-media-replace'); ?></button>
	</nav>

	<div class="emr-bulk-preview" hidden>
		<h3><?php esc_html_e('Just processed', 'enable-media-replace'); ?>: <span data-result="filename"></span></h3>
		<div id="emr-bulk-preview-images" class="emr-bulk-preview-images" data-placeholder="<?php echo esc_url($placeholder); ?>">
			<div class="image source"><img src="<?php echo esc_url($placeholder); ?>" alt=""><p><?php esc_html_e('Original', 'enable-media-replace'); ?></p></div>
			<div class="image result"><img src="<?php echo esc_url($placeholder); ?>" alt=""><p><?php esc_html_e('Result', 'enable-media-replace'); ?></p></div>
		</div>
	</div>
</section>
