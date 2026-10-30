<?php
/**
 * Bulk summary panel: what is queued, start or go back.
 *
 * Variables in scope: see views/bulk/view-bulk.php.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}
?>
<section class="emr-bulk-panel emr-bulk-summary" data-panel="summary" hidden>
	<h2><?php esc_html_e('Ready to start', 'enable-media-replace'); ?></h2>
	<p class="emr-bulk-summary-operation"><span data-operation-label><?php echo esc_html($view->operations[ $view->currentOperation ]->label); ?></span></p>
	<p class="emr-bulk-summary-count"><?php printf(esc_html__('%s images are queued.', 'enable-media-replace'), '<strong data-stats-media="total">0</strong>'); ?></p>
	<p class="description"><?php esc_html_e('Every original is copied to the EMR_Backups folder before it is replaced, so the run can be undone from the dashboard.', 'enable-media-replace'); ?></p>
	<p class="emr-bulk-summary-empty" data-check-visibility="false" data-control="data-check-media-total"><?php esc_html_e('Nothing to do: no image matched.', 'enable-media-replace'); ?></p>
	<span class="hidden" data-check-media-total data-stats-media="total">0</span>
	<nav>
		<button type="button" class="button" data-action="FinishBulk"><?php esc_html_e('Cancel', 'enable-media-replace'); ?></button>
		<button type="button" class="button button-primary" data-action="StartBulk" data-check-visibility data-control="data-check-media-total"><?php esc_html_e('Start processing', 'enable-media-replace'); ?></button>
	</nav>
</section>
