<?php
/**
 * Bulk dashboard panel: the two operations, the API key box and the history.
 *
 * Variables in scope: see views/bulk/view-bulk.php.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}

use EnableMediaReplace\Controller\Bulk\BulkQueue;
?>
<section class="emr-bulk-panel emr-bulk-dashboard active" data-panel="dashboard">

	<?php if ($view->error) : ?>
		<div class="notice notice-warning inline"><p><strong><?php echo esc_html($view->errorTitle); ?></strong> <?php echo esc_html($view->errorContent); ?></p></div>
	<?php endif; ?>

	<div class="emr-bulk-operations">
		<?php foreach ($view->operations as $operation => $info) : ?>
			<div class="emr-bulk-operation" data-operation="<?php echo esc_attr($operation); ?>" data-available="<?php echo $info->available ? '1' : '0'; ?>">
				<h2 class="emr-bulk-operation-title"><?php echo esc_html($info->label); ?></h2>
				<?php if (BulkQueue::OPERATION_BACKGROUND === $operation) : ?>
					<p><?php esc_html_e('Removes the background of every image used by your WooCommerce products (featured image, gallery, variations) and replaces the file in place.', 'enable-media-replace'); ?></p>
					<p class="emr-bulk-count"><?php printf(esc_html(_n('%s product to scan', '%s products to scan', $info->count, 'enable-media-replace')), '<strong>' . esc_html(number_format_i18n($info->count)) . '</strong>'); ?></p>
				<?php else : ?>
					<p><?php printf(esc_html__('Upscales every JPEG and PNG image of the media library narrower than %s pixels, with the largest factor its width allows, and replaces the file in place.', 'enable-media-replace'), '<strong data-settings="upscale_threshold">' . esc_html(number_format_i18n($view->settings['upscale_threshold'])) . '</strong>'); ?></p>
					<p class="emr-bulk-count"><?php printf(esc_html(_n('%s image to scan', '%s images to scan', $info->count, 'enable-media-replace')), '<strong>' . esc_html(number_format_i18n($info->count)) . '</strong>'); ?></p>
				<?php endif; ?>
				<?php if (! $info->available) : ?>
					<p class="emr-bulk-unavailable"><?php echo esc_html($info->reason); ?></p>
				<?php endif; ?>
				<nav>
					<button type="button" class="button button-primary" data-action="open-panel" data-panel="selection" data-select-operation="<?php echo esc_attr($operation); ?>" <?php disabled(! $info->available || $view->error); ?>><?php esc_html_e('Start', 'enable-media-replace'); ?></button>
					<?php if ($info->restorable > 0) : ?>
						<button type="button" class="button" data-action="open-panel" data-panel="restore" data-select-operation="<?php echo esc_attr($operation); ?>"><?php printf(esc_html(_n('Restore %s original', 'Restore %s originals', $info->restorable, 'enable-media-replace')), esc_html(number_format_i18n($info->restorable))); ?></button>
					<?php endif; ?>
				</nav>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if (current_user_can('manage_options')) : ?>
		<div class="emr-bulk-account">
			<?php
			$emr_account_feature = 'bulk';
			$controller->loadView('shortpixel-account', false);
			?>
		</div>
	<?php endif; ?>

	<?php if (count($view->logs) > 0) : ?>
		<div class="emr-bulk-history">
			<h2><?php esc_html_e('Bulk history', 'enable-media-replace'); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<?php foreach ($view->logHeaders as $header) : ?>
						<th><?php echo esc_html($header); ?></th>
					<?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php foreach ($view->logs as $log) : ?>
					<tr>
						<td><?php echo esc_html($log['name']); ?></td>
						<td><?php echo esc_html(number_format_i18n($log['images'])); ?></td>
						<td>
							<?php if ($log['file'] && $log['errors'] > 0) : ?>
								<a href="#" data-action="OpenLog" data-file="<?php echo esc_attr($log['file']); ?>"><?php echo esc_html(number_format_i18n($log['errors'])); ?></a>
							<?php else : ?>
								<?php echo esc_html(number_format_i18n($log['errors'])); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html($log['date']); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div id="emr-bulk-log-modal" class="emr-bulk-modal" hidden>
			<div class="emr-bulk-modal-inner">
				<button type="button" class="emr-bulk-modal-close" data-action="CloseModal" aria-label="<?php esc_attr_e('Close', 'enable-media-replace'); ?>">&times;</button>
				<h3 class="emr-bulk-log-title"></h3>
				<div class="emr-bulk-log-table"></div>
			</div>
		</div>
	<?php endif; ?>
</section>
