<?php
/**
 * Bulk restore panel: confirmation before the originals are put back.
 *
 * Variables in scope: see views/bulk/view-bulk.php.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}
?>
<section class="emr-bulk-panel emr-bulk-restore" data-panel="restore" hidden>
	<h2><?php esc_html_e('Restore the originals', 'enable-media-replace'); ?></h2>
	<p><?php esc_html_e('Every image a previous bulk of this operation replaced gets its original file back from the EMR_Backups folder; the thumbnails are regenerated and the backup is removed.', 'enable-media-replace'); ?></p>
	<p class="emr-bulk-warning"><?php esc_html_e('Edits made to those images after the bulk are lost. Make a full backup first.', 'enable-media-replace'); ?></p>
	<?php foreach ($view->operations as $operation => $info) : ?>
		<div class="emr-bulk-restore-choice" data-restore-operation="<?php echo esc_attr($operation); ?>">
			<label class="emr-bulk-checkbox">
				<input type="checkbox" data-action="ToggleButton" data-target="emr-bulk-restore-<?php echo esc_attr($operation); ?>">
				<span><?php printf(esc_html__('I want to restore the %1$s originals of "%2$s". This cannot be undone.', 'enable-media-replace'), esc_html(number_format_i18n($info->restorable)), esc_html($info->label)); ?></span>
			</label>
			<button type="button" class="button button-primary" id="emr-bulk-restore-<?php echo esc_attr($operation); ?>" data-action="BulkRestore" data-operation="<?php echo esc_attr($operation); ?>" disabled><?php printf(esc_html__('Restore: %s', 'enable-media-replace'), esc_html($info->label)); ?></button>
		</div>
	<?php endforeach; ?>
	<nav>
		<button type="button" class="button" data-action="open-panel" data-panel="dashboard"><?php esc_html_e('Back', 'enable-media-replace'); ?></button>
	</nav>
</section>
