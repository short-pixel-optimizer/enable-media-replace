<?php
/**
 * Bulk summary panel: what is queued, start or go back.
 *
 * The AI credits box (views/ai-credits.php) follows, with a warning the
 * screen script shows when the queue is larger than the credits left.
 *
 * `[data-operation-detail]` is filled by the screen script with the chosen
 * options ("Transparent", "Solid color #ffffff, 100% opacity", "Images
 * narrower than 1200 px"); `[data-background-process]` follows the
 * background-process checkbox of the selection panel.
 *
 * Variables in scope: see views/bulk/view-bulk.php.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}
?>
<section class="emr-bulk-panel emr-bulk-summary" data-panel="summary" hidden>
	<?php $controller->loadView('bulk/part-steps', false, array('part' => 'summary')); ?>

	<h2 class="emr-bulk-heading"><?php esc_html_e('Ready to start', 'enable-media-replace'); ?></h2>

	<div class="emr-bulk-summary-box">
		<p class="emr-bulk-summary-operation"><span data-operation-label><?php echo esc_html($view->operations[ $view->currentOperation ]->label); ?></span><span class="emr-bulk-summary-detail" data-operation-detail></span></p>
		<p class="emr-bulk-summary-count" data-check-visibility data-control="data-check-media-total"><?php printf(esc_html__('%s will be processed.', 'enable-media-replace'), '<strong>' . sprintf(esc_html__('%s image(s)', 'enable-media-replace'), '<span data-stats-media="total">0</span>') . '</strong>'); ?></p>
		<p class="emr-bulk-summary-empty" data-check-visibility="false" data-control="data-check-media-total"><?php esc_html_e('Nothing to do: no image matched.', 'enable-media-replace'); ?></p>
		<span class="hidden" data-check-media-total data-stats-media="total">0</span>
		<p>
			<?php
			printf(
				/* translators: %1$s: the backup folder, %2$s: "Restore originals" in bold. */
				esc_html__('Your originals are copied to the %1$s folder first, so you can undo this run anytime with %2$s.', 'enable-media-replace'),
				'<code>' . esc_html($view->backupPath) . '</code>',
				'<strong>' . esc_html__('Restore originals', 'enable-media-replace') . '</strong>'
			);
			?>
		</p>
		<p class="emr-bulk-summary-keep-open" data-background-process="0"<?php echo $view->settings['background_process'] ? ' hidden' : ''; ?>><?php esc_html_e('Keep this page open while it runs.', 'enable-media-replace'); ?></p>
		<p class="emr-bulk-summary-keep-open" data-background-process="1"<?php echo $view->settings['background_process'] ? '' : ' hidden'; ?>><?php esc_html_e('It is fastest with this page open; if you close it, the bulk keeps going in the background.', 'enable-media-replace'); ?></p>
	</div>

	<?php if ($view->hasKey) : ?>
		<?php // The AI credits; the warning shows when the queue is larger than the credits left (js/bulk/emr-bulk-screen.js, CheckCredits()). ?>
		<div class="emr-bulk-summary-box emr-bulk-credits" <?php echo $controller->operationOnly('background upscale'); ?>>
			<?php
			$emr_credits = $view->aiCredits;
			include \emr()->plugin_path('views/ai-credits.php');
			?>
			<?php if (is_array($view->aiCredits) && ! $view->aiCredits['unlimited']) : ?>
				<p class="emr-bulk-credits-warning" data-ai-remaining="<?php echo esc_attr($view->aiCredits['remaining']); ?>" hidden>
					<?php
					printf(
						/* translators: %1$s: images in the bulk, %2$s: AI credits left. */
						esc_html(_n('This bulk has %1$s image(s), but only %2$s AI credit is left this month. The images beyond that fail until your credits renew, unless you upgrade to Unlimited AI.', 'This bulk has %1$s image(s), but only %2$s AI credits are left this month. The images beyond that fail until your credits renew, unless you upgrade to Unlimited AI.', $view->aiCredits['remaining'], 'enable-media-replace')),
						'<strong data-stats-media="total">0</strong>',
						'<strong>' . esc_html(number_format_i18n($view->aiCredits['remaining'])) . '</strong>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<nav>
		<button type="button" class="button" data-action="FinishBulk"><?php esc_html_e('Cancel', 'enable-media-replace'); ?></button>
		<button type="button" class="button button-primary" data-action="StartBulk" data-check-visibility data-control="data-check-media-total"><?php esc_html_e('Start processing', 'enable-media-replace'); ?></button>
	</nav>
</section>
