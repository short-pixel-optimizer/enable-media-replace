<?php
/**
 * Bulk finished panel: the totals and the finish button.
 *
 * Variables in scope: see views/bulk/view-bulk.php.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}
?>
<section class="emr-bulk-panel emr-bulk-finished" data-panel="finished" hidden>
	<h2><?php esc_html_e('The bulk is finished', 'enable-media-replace'); ?></h2>
	<p class="emr-bulk-finished-text"><?php printf(esc_html__('%1$s: %2$s images processed, %3$s errors.', 'enable-media-replace'), '<span data-operation-label>' . esc_html($view->operations[ $view->currentOperation ]->label) . '</span>', '<strong data-stats-media="done">0</strong>', '<strong data-stats-media="fatal_errors" data-check-media-fatalerrors-finished>0</strong>'); ?></p>
	<label class="emr-bulk-show-errors" data-check-visibility data-control="data-check-media-fatalerrors-finished"><input type="checkbox" name="emr-show-errors" data-action="ToggleErrorBox" data-event="change"> <?php esc_html_e('Show errors', 'enable-media-replace'); ?></label>
	<div class="emr-bulk-errorbox" data-error-media="message" hidden><?php if (false !== $view->errorLog) { echo $view->errorLog; /* escaped per line in loadCurrentLog() */ } ?></div>
	<p class="description"><?php esc_html_e('The originals are kept in the EMR_Backups folder; use Restore on the dashboard to undo this run.', 'enable-media-replace'); ?></p>
	<nav>
		<button type="button" class="button button-primary" data-action="FinishBulk"><?php esc_html_e('Back to the dashboard', 'enable-media-replace'); ?></button>
	</nav>
</section>
