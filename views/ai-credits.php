<?php
/**
 * The AI credits of the stored API key: in the ShortPixel account row
 * (views/shortpixel-account.php) and on the bulk summary
 * (views/bulk/part-summary.php).
 *
 * Background removal and image upscale use one AI credit per image.
 * Unlimited AI: ShortPixel Image Optimizer's "do not have to worry about AI
 * credits" line. Other Unlimited plans: the credits left of the monthly
 * amount, a <progress> meter and SPIO's "Buy Unlimited AI credits" button
 * (UIHelper::getUpgradeAiButton()). Styles: scss/_shortpixel-account.scss.
 *
 * Variables in scope:
 * - $emr_credits (array|false) ApiKeyManager::getAiCredits(); false when the
 *   API could not be reached.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}
?>
<?php if (false === $emr_credits) : ?>
	<p class="emr-ai-credits emr-ai-credits-unknown"><?php esc_html_e('The AI credits of this API key could not be loaded right now.', 'enable-media-replace'); ?></p>
<?php elseif ($emr_credits['unlimited']) : ?>
	<p class="emr-ai-credits emr-ai-credits-unlimited"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('This site is on the ShortPixel Unlimited AI plan, so you do not have to worry about AI credits. Enjoy!', 'enable-media-replace'); ?></p>
<?php else : ?>
	<div class="emr-ai-credits emr-ai-credits-limited">
		<p class="emr-ai-credits-count">
			<?php
			printf(
				/* translators: %1$s: AI credits left, %2$s: AI credits per month. */
				esc_html__('AI credits: %1$s of %2$s left this month', 'enable-media-replace'),
				'<strong>' . esc_html(number_format_i18n($emr_credits['remaining'])) . '</strong>',
				esc_html(number_format_i18n($emr_credits['quota']))
			);
			?>
		</p>
		<progress class="emr-ai-credits-meter" max="<?php echo esc_attr(max(1, $emr_credits['quota'])); ?>" value="<?php echo esc_attr($emr_credits['remaining']); ?>"></progress>
		<p class="emr-ai-credits-help"><?php esc_html_e('Background removal and image upscale use one AI credit per image. The Unlimited AI plan has no limit.', 'enable-media-replace'); ?></p>
		<?php echo \emr()->uiHelper()->getUpgradeAiButton(); // Escaped by getUpgradeAiButton(). ?>
	</div>
<?php endif; ?>
