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

// Operation icons (img/icons/*.svg), shown at 64 px.
$emr_icons = array(
	BulkQueue::OPERATION_BACKGROUND => 'icon-background-removal.svg',
	BulkQueue::OPERATION_UPSCALE    => 'icon-image-upscale.svg',
);
?>
<section class="emr-bulk-panel emr-bulk-dashboard active" data-panel="dashboard">

	<?php if ($view->error) : ?>
		<div class="emr-bulk-alert emr-bulk-alert-warning" role="alert">
			<h3><?php echo esc_html($view->errorTitle); ?></h3>
			<p><?php echo wp_kses($view->errorContent, array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))); ?></p>
		</div>
	<?php endif; ?>

	<div class="emr-bulk-operations">
		<?php foreach ($view->operations as $operation => $info) : ?>
			<div class="emr-bulk-operation" data-operation="<?php echo esc_attr($operation); ?>" data-available="<?php echo $info->available ? '1' : '0'; ?>">
				<h2 class="emr-bulk-operation-title">
					<img class="emr-bulk-operation-icon" src="<?php echo esc_url(plugins_url('img/icons/' . $emr_icons[ $operation ], EMR_ROOT_FILE)); ?>" width="64" height="64" alt="">
					<span class="emr-bulk-operation-name"><?php echo esc_html($info->label); ?></span>
				</h2>
				<?php if (BulkQueue::OPERATION_BACKGROUND === $operation) : ?>
					<p><?php esc_html_e('Removes the background from all images of your WooCommerce products: featured image, gallery and variations. Originals are backed up, so you can restore them anytime.', 'enable-media-replace'); ?></p>
					<p class="emr-bulk-count"><?php printf(esc_html(_n('%s product to scan', '%s products to scan', $info->count, 'enable-media-replace')), '<strong>' . esc_html(number_format_i18n($info->count)) . '</strong>'); ?></p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %1$s: width in pixels, %2$s: the largest upscale factor, for example 4×. */
							esc_html__('Makes small JPG and PNG images in your media library larger. Only images narrower than %1$s px are changed, and each is enlarged as far as its size allows (up to %2$s). Originals are backed up, so you can restore them anytime.', 'enable-media-replace'),
							'<strong data-settings="upscale_threshold">' . esc_html(number_format_i18n($view->settings['upscale_threshold'])) . '</strong>',
							/* translators: %d: upscale factor. */
							esc_html(sprintf(__('%d×', 'enable-media-replace'), (int) $view->maxFactor))
						);
						?>
					</p>
					<p class="emr-bulk-count"><?php printf(esc_html(_n('%s image to scan', '%s images to scan', $info->count, 'enable-media-replace')), '<strong>' . esc_html(number_format_i18n($info->count)) . '</strong>'); ?></p>
				<?php endif; ?>
				<?php if (! $info->available) : ?>
					<p class="emr-bulk-unavailable"><?php echo esc_html($info->reason); ?></p>
				<?php endif; ?>
				<?php
				// Why Start is greyed out, as a hover tooltip on the wrapper (a disabled button gets no hover).
				$emr_tooltip = '';
				if ($view->error) {
					$emr_tooltip = __('Add a ShortPixel API key on an Unlimited plan to start.', 'enable-media-replace');
				} elseif (! $info->available) {
					$emr_tooltip = $info->reason;
				}
				?>
				<nav>
					<span class="emr-bulk-tooltip"<?php echo ('' !== $emr_tooltip) ? ' data-tooltip="' . esc_attr($emr_tooltip) . '"' : ''; ?>><button type="button" class="button button-primary" data-action="open-panel" data-panel="selection" data-select-operation="<?php echo esc_attr($operation); ?>" <?php disabled(! $info->available || $view->error); ?>><?php esc_html_e('Start', 'enable-media-replace'); ?></button></span>
					<?php if ($info->restorable > 0) : ?>
						<button type="button" class="button" data-action="open-panel" data-panel="restore" data-select-operation="<?php echo esc_attr($operation); ?>"><?php printf(esc_html(_n('Restore %s original', 'Restore %s originals', $info->restorable, 'enable-media-replace')), esc_html(number_format_i18n($info->restorable))); ?></button>
					<?php endif; ?>
				</nav>
			</div>
		<?php endforeach; ?>
	</div>

	<?php
	$emr_account_context = 'bulk';
	include \emr()->plugin_path('views/shortpixel-account.php');
	?>

	<?php if (count($view->logs) > 0) : ?>
		<details class="emr-collapsible emr-bulk-history">
			<summary>
				<span class="emr-collapsible-title"><span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e('Bulk history', 'enable-media-replace'); ?></span>
				<span class="emr-collapsible-chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
			</summary>
			<div class="emr-collapsible-content">
				<table class="emr-bulk-log">
					<thead><tr>
						<?php foreach ($view->logHeaders as $header) : ?>
							<th scope="col"><?php echo esc_html($header); ?></th>
						<?php endforeach; ?>
					</tr></thead>
					<tbody>
					<?php foreach ($view->logs as $log) : ?>
						<tr>
							<td class="emr-bulk-log-name"><?php echo esc_html($log['name']); ?></td>
							<td><?php echo esc_html(number_format_i18n($log['images'])); ?></td>
							<td>
								<?php if ($log['file'] && $log['errors'] > 0) : ?>
									<a href="#" class="emr-bulk-errors-link" data-action="OpenLog" data-file="<?php echo esc_attr($log['file']); ?>"><?php echo esc_html(number_format_i18n($log['errors'])); ?></a>
								<?php else : ?>
									<?php echo esc_html(number_format_i18n($log['errors'])); ?>
								<?php endif; ?>
							</td>
							<td class="emr-bulk-log-date"><?php echo esc_html($log['date']); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>

		<div id="emr-bulk-log-modal" class="emr-bulk-modal" hidden>
			<div class="emr-bulk-modal-inner" role="dialog" aria-modal="true" aria-labelledby="emr-bulk-log-title">
				<button type="button" class="emr-bulk-modal-close" data-action="CloseModal" aria-label="<?php esc_attr_e('Close', 'enable-media-replace'); ?>">&times;</button>
				<h3 id="emr-bulk-log-title" class="emr-bulk-log-title"></h3>
				<div class="emr-bulk-log-table"></div>
			</div>
		</div>
	<?php endif; ?>
</section>
