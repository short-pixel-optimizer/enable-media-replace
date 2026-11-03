<?php
/**
 * Bulk process panel: progress, the last processed image, errors, controls.
 *
 * The stats table follows SPIO's `.bulk-summary`. The error count is plain
 * text at 0 and a red link that opens the error box above 0
 * (`[data-check-media-fatalerrors]` drives both through data-check-visibility).
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
	<?php $controller->loadSteps('process'); ?>

	<h2 class="emr-bulk-heading">
		<span <?php echo $controller->operationOnly('background'); ?>><?php esc_html_e('Removing backgrounds…', 'enable-media-replace'); ?></span>
		<span <?php echo $controller->operationOnly('upscale'); ?>><?php esc_html_e('Upscaling images…', 'enable-media-replace'); ?></span>
		<span <?php echo $controller->operationOnly('restore'); ?>><?php esc_html_e('Restoring originals…', 'enable-media-replace'); ?></span>
	</h2>
	<p class="description emr-bulk-subheading" <?php echo $controller->operationOnly('background upscale'); ?>><?php esc_html_e('Keep this page open for the fastest result. Your images are sent to ShortPixel for processing, and the originals are backed up first.', 'enable-media-replace'); ?></p>
	<p class="description emr-bulk-subheading" <?php echo $controller->operationOnly('restore'); ?>><?php esc_html_e('Keep this page open for the fastest result. The originals are copied back from the backup folder and the thumbnails are regenerated.', 'enable-media-replace'); ?></p>

	<div class="emr-bulk-stats">
		<div class="heading">
			<span class="emr-bulk-stats-name"><span class="dashicons dashicons-images-alt2" aria-hidden="true"></span> <span data-operation-label><?php echo esc_html($view->operations[ $view->currentOperation ]->label); ?></span></span>
			<span class="emr-bulk-progress">
				<span class="emr-bulk-progress-bar">
					<span class="emr-bulk-progress-done" data-stats-media="percentage_done" data-presentation="css.width.percentage"></span>
				</span>
				<span class="emr-bulk-progress-text"><span data-stats-media="percentage_done">0</span>%</span>
				<span class="dashicons dashicons-update emr-bulk-spinner spin" aria-hidden="true"></span>
			</span>
			<span><?php esc_html_e('Total', 'enable-media-replace'); ?>: <strong data-stats-media="total">0</strong></span>
		</div>
		<div>
			<span><?php esc_html_e('Processed', 'enable-media-replace'); ?>: <strong data-stats-media="done">0</strong></span>
			<span><?php esc_html_e('Processing', 'enable-media-replace'); ?>: <strong data-stats-media="in_process">0</strong> &middot; <?php esc_html_e('Waiting', 'enable-media-replace'); ?>: <strong data-stats-media="in_queue">0</strong></span>
			<span class="emr-bulk-stats-errors"><?php esc_html_e('Errors', 'enable-media-replace'); ?>:
				<span class="hidden" data-check-media-fatalerrors data-stats-media="fatal_errors">0</span>
				<strong data-check-visibility="false" data-control="data-check-media-fatalerrors" data-stats-media="fatal_errors">0</strong>
				<a href="#" class="emr-bulk-errors-link" data-action="ToggleErrorBox" aria-expanded="false" data-check-visibility data-control="data-check-media-fatalerrors" hidden><strong data-stats-media="fatal_errors">0</strong> <span class="emr-bulk-errors-link-text"><?php esc_html_e('Show details', 'enable-media-replace'); ?></span></a>
			</span>
		</div>
	</div>
	<div class="emr-bulk-errorbox" data-error-media="message" hidden><?php if (false !== $view->errorLog) { echo $view->errorLog; /* escaped per line in loadCurrentLog() */ } ?></div>

	<nav>
		<button type="button" class="button" data-action="StopBulk"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span> <?php esc_html_e('Stop', 'enable-media-replace'); ?></button>
		<button type="button" class="button" id="emr-bulk-pause" data-action="PauseBulk"><span class="dashicons dashicons-controls-pause" aria-hidden="true"></span> <?php esc_html_e('Pause', 'enable-media-replace'); ?></button>
		<button type="button" class="button button-primary" id="emr-bulk-resume" data-action="ResumeBulk" hidden><span class="dashicons dashicons-controls-play" aria-hidden="true"></span> <?php esc_html_e('Resume', 'enable-media-replace'); ?></button>
	</nav>

	<div class="emr-bulk-preview" hidden>
		<div class="emr-bulk-preview-title"><?php esc_html_e('Latest result:', 'enable-media-replace'); ?> <span data-result="filename"></span></div>
		<div id="emr-bulk-preview-images" class="emr-bulk-preview-images" data-placeholder="<?php echo esc_url($placeholder); ?>">
			<div class="image source"><img src="<?php echo esc_url($placeholder); ?>" alt=""><p><?php esc_html_e('Original', 'enable-media-replace'); ?></p></div>
			<div class="image result"><img src="<?php echo esc_url($placeholder); ?>" alt=""><p><?php esc_html_e('Result', 'enable-media-replace'); ?></p></div>
		</div>
	</div>
</section>
