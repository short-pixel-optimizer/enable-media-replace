<?php
/**
 * Bulk finished panel: the totals and the way back.
 *
 * "Back to Bulk Enhance" (FinishBulk) and "Restore originals"
 * (FinishAndRestore) both finish the run on the server; the latter reloads
 * the page on the restore panel.
 *
 * Variables in scope: see views/bulk/view-bulk.php.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}

$emr_done   = '<strong data-stats-media="done">0</strong>';
$emr_errors = '<span class="hidden" data-check-media-fatalerrors-finished data-stats-media="fatal_errors">0</span>'
	. '<strong data-check-visibility="false" data-control="data-check-media-fatalerrors-finished" data-stats-media="fatal_errors">0</strong>'
	. '<a href="#" class="emr-bulk-errors-link" data-action="ToggleErrorBox" aria-expanded="false" data-check-visibility data-control="data-check-media-fatalerrors-finished" hidden><strong data-stats-media="fatal_errors">0</strong></a>';
?>
<section class="emr-bulk-panel emr-bulk-finished" data-panel="finished" hidden>
	<?php $controller->loadSteps('finished'); ?>

	<div class="emr-bulk-finished-icon"><span class="dashicons dashicons-yes" aria-hidden="true"></span></div>
	<h2 class="emr-bulk-heading"><?php esc_html_e('All done', 'enable-media-replace'); ?></h2>
	<p class="emr-bulk-finished-text"><?php
		// One line of output: whitespace between the hidden spans would show before the comma.
		/* translators: %s: number of images, filled in by the script (no plural form). */
		echo '<span ' . $controller->operationOnly('background') . '>' . sprintf(esc_html__('Backgrounds removed from %s image(s)', 'enable-media-replace'), $emr_done) . '</span>';
		/* translators: %s: number of images, filled in by the script (no plural form). */
		echo '<span ' . $controller->operationOnly('upscale') . '>' . sprintf(esc_html__('%s image(s) upscaled', 'enable-media-replace'), $emr_done) . '</span>';
		/* translators: %s: number of originals, filled in by the script (no plural form). */
		echo '<span ' . $controller->operationOnly('restore') . '>' . sprintf(esc_html__('%s original(s) restored', 'enable-media-replace'), $emr_done) . '</span>';
		/* translators: %s: number of errors (no plural form), follows "Backgrounds removed from 10 image(s)" and the like. */
		printf(esc_html__(', %s error(s)', 'enable-media-replace'), $emr_errors);
	?></p>
	<div class="emr-bulk-errorbox" data-error-media="message" hidden><?php if (false !== $view->errorLog) { echo $view->errorLog; /* escaped per line in loadCurrentLog() */ } ?></div>

	<p class="description emr-bulk-subheading" <?php echo $controller->operationOnly('background upscale'); ?>>
		<?php
		printf(
			/* translators: %s: the backup folder. */
			esc_html__('Originals are backed up in the %s folder, so you can undo this run anytime. If your site uses caching or a CDN, clear it to see the new images.', 'enable-media-replace'),
			'<code>' . esc_html($view->backupPath) . '</code>'
		);
		?>
	</p>
	<p class="description emr-bulk-subheading" <?php echo $controller->operationOnly('restore'); ?>><?php esc_html_e('The original images are back in place. If your site uses caching or a CDN, clear it to see them.', 'enable-media-replace'); ?></p>

	<nav>
		<button type="button" class="button button-primary" data-action="FinishBulk"><?php esc_html_e('Back to Bulk Enhance', 'enable-media-replace'); ?></button>
		<button type="button" class="button" data-action="FinishAndRestore" <?php echo $controller->operationOnly('background upscale'); ?>><?php esc_html_e('Restore originals', 'enable-media-replace'); ?></button>
		<a class="button" href="<?php echo esc_url(admin_url('upload.php')); ?>"><?php esc_html_e('View in Media Library', 'enable-media-replace'); ?></a>
	</nav>
</section>
