<?php
/**
 * Bulk selection panel: operation, its options, the background-process switch.
 *
 * Variables in scope: see views/bulk/view-bulk.php.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}

use EnableMediaReplace\Controller\Bulk\BulkQueue;

$settings = $view->settings;
?>
<section class="emr-bulk-panel emr-bulk-selection" data-panel="selection" data-status="loaded" hidden>
	<?php $controller->loadView('bulk/part-steps', false, array('part' => 'selection')); ?>

	<div class="emr-bulk-selection-form">
		<div class="emr-bulk-option-block">
			<h2><?php esc_html_e('What do you want to do?', 'enable-media-replace'); ?></h2>
			<fieldset class="emr-bulk-operation-choice">
				<legend class="screen-reader-text"><?php esc_html_e('Operation', 'enable-media-replace'); ?></legend>
				<?php foreach ($view->operations as $operation => $info) : ?>
					<label class="emr-bulk-radio">
						<input type="radio" name="emr_bulk_operation" value="<?php echo esc_attr($operation); ?>" data-action="OperationChanged" data-event="change" <?php checked($view->currentOperation, $operation); ?> <?php disabled(! $info->available); ?>>
						<span><?php echo esc_html($info->label); ?></span>
						<?php if (! $info->available) : ?><em><?php echo esc_html($info->reason); ?></em><?php endif; ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
		</div>

		<div class="emr-bulk-option-block" data-operation-options="<?php echo esc_attr(BulkQueue::OPERATION_BACKGROUND); ?>">
			<h3><?php esc_html_e('Background', 'enable-media-replace'); ?></h3>
			<label class="emr-bulk-radio"><input type="radio" name="emr_bulk_bg_type" value="transparent" data-action="BackgroundTypeChanged" data-event="change" <?php checked('solid' !== $settings['bg_type']); ?>> <span><?php esc_html_e('Transparent', 'enable-media-replace'); ?></span></label>
			<p class="description emr-bulk-indent"><?php esc_html_e('PNG images get a transparent background. JPG images cannot be transparent, so they get a white one; their file names and URLs do not change.', 'enable-media-replace'); ?></p>
			<label class="emr-bulk-radio"><input type="radio" name="emr_bulk_bg_type" value="solid" data-action="BackgroundTypeChanged" data-event="change" <?php checked('solid' === $settings['bg_type']); ?>> <span><?php esc_html_e('Solid background', 'enable-media-replace'); ?></span></label>
			<div id="emr-bulk-bg-color-row" class="emr-bulk-color-row emr-bulk-indent" <?php echo ('solid' === $settings['bg_type']) ? '' : 'hidden'; ?>>
				<label for="emr-bulk-bg-color"><?php esc_html_e('Background color', 'enable-media-replace'); ?></label>
				<input type="color" id="emr-bulk-bg-color" value="<?php echo esc_attr($settings['bg_color']); ?>">
				<label for="emr-bulk-bg-transparency"><?php esc_html_e('Opacity (%)', 'enable-media-replace'); ?></label>
				<input type="number" id="emr-bulk-bg-transparency" min="0" max="100" value="<?php echo esc_attr($settings['bg_transparency']); ?>">
			</div>
			<p class="description emr-bulk-note"><?php esc_html_e('Images that already had their background removed by a previous bulk are skipped.', 'enable-media-replace'); ?></p>
		</div>

		<div class="emr-bulk-option-block" data-operation-options="<?php echo esc_attr(BulkQueue::OPERATION_UPSCALE); ?>" hidden>
			<h3><?php esc_html_e('Upscale', 'enable-media-replace'); ?></h3>
			<label for="emr-bulk-threshold" class="emr-bulk-field-label"><?php esc_html_e('Only images narrower than (pixels)', 'enable-media-replace'); ?></label>
			<input type="number" id="emr-bulk-threshold" min="1" max="<?php echo esc_attr($view->maxThreshold); ?>" value="<?php echo esc_attr($settings['upscale_threshold']); ?>">
			<p class="description">
				<?php
				printf(
					/* translators: %s: the factors, for example "2×, 3× or 4×". */
					esc_html__('Each image is enlarged %s, whichever is the largest that keeps it within the size limit.', 'enable-media-replace'),
					esc_html($view->factorList)
				);
				?>
			</p>
			<p class="description emr-bulk-note"><?php esc_html_e('Images already upscaled by a previous bulk are skipped.', 'enable-media-replace'); ?></p>
		</div>

		<div class="emr-bulk-option-block emr-bulk-background-process">
			<label class="emr-bulk-checkbox">
				<input type="checkbox" id="emr-bulk-background-process" <?php checked($settings['background_process']); ?>>
				<span><?php esc_html_e('Keep going in the background if I close this page', 'enable-media-replace'); ?></span>
			</label>
			<p class="description emr-bulk-indent"><?php esc_html_e('Fastest with this page open. In the background it only moves forward when your site gets visitors (WP-Cron).', 'enable-media-replace'); ?></p>
		</div>

		<nav>
			<button type="button" class="button" data-action="open-panel" data-panel="dashboard"><?php esc_html_e('Back', 'enable-media-replace'); ?></button>
			<button type="button" class="button button-primary" id="emr-bulk-create" data-action="CreateBulk"><?php esc_html_e('Prepare the bulk', 'enable-media-replace'); ?></button>
		</nav>
	</div>

	<div class="emr-bulk-loading">
		<span class="emr-bulk-loader" aria-hidden="true"></span>
		<h3><?php esc_html_e('Collecting the images', 'enable-media-replace'); ?></h3>
		<p><?php printf(esc_html__('%s image(s) queued so far', 'enable-media-replace'), '<strong data-stats-media="total">0</strong>'); ?></p>
		<p class="emr-bulk-overlimit" hidden><?php esc_html_e('The scan is taking longer than one request allows and continues in the next one.', 'enable-media-replace'); ?></p>
		<nav>
			<button type="button" class="button" data-action="SkipPreparing"><?php esc_html_e('Start with what is queued so far', 'enable-media-replace'); ?></button>
		</nav>
	</div>
</section>
