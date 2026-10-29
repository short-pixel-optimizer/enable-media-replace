<?php
/**
 * ShortPixel Account box: shows, saves, changes and removes the ShortPixel API key.
 *
 * Included by views/prepare-remove-background.php and views/prepare-upscale.php, inside
 * their form. Rendered only for users with `manage_options`; for them it also enqueues
 * `emr_shortpixel_account` (js/shortpixel-account.js, localised as `emrAccount`), which
 * posts to the `emr_save_api_key` and `emr_delete_api_key` AJAX actions (nonce
 * `emr_save_api_key`) and reloads the page on success.
 *
 * Variables in scope:
 * - $emr_account_feature (string, optional) 'upscale' selects the upscale wording of the
 *   intro text and of the remove confirmation (`data-confirm-remove`); anything else, or
 *   unset, gives the background-removal wording. Set it before the include.
 *
 * With a stored key: the masked key (ApiKeyManager::getMaskedKey()), "Change Key"
 * (`#emr-change-key-toggle`, reveals `#emr-change-key-form`) and "Remove Key"
 * (`#emr-remove-key`, asks for confirmation). Without one: the `#emr-new-api-key` input
 * (20 characters), the "Activate" button (`#emr-update-api-key`) and the sign-up link.
 * `#emr-apikey-notice` receives the result of a save or removal.
 *
 * @todo ApiKeyManager's answers ("You now have unlimited background removal", "unlock
 *       unlimited background removal") are shown unchanged on the upscale screen, where
 *       they describe the wrong feature. Word them per feature, or neutrally.
 */
namespace EnableMediaReplace;

use EnableMediaReplace\ApiKeyManager;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// ShortPixel Account box, shared by the remove background and upscale screens.
// Set $emr_account_feature = 'upscale' before including for the upscale texts.
$apiKeyManager   = ApiKeyManager::getInstance();
$emr_has_api_key = $apiKeyManager->hasApiKey();
$emr_masked_key  = $apiKeyManager->getMaskedKey();
$emr_can_manage  = current_user_can('manage_options');
$emr_is_upscale  = (isset($emr_account_feature) && 'upscale' === $emr_account_feature);

if ($emr_can_manage) {
	wp_enqueue_script('emr_shortpixel_account');
}

$emr_confirm_remove = $emr_is_upscale
	? __('Remove the saved ShortPixel API Key? Image upscale will fall back to the Free plan.', 'enable-media-replace')
	: __('Remove the saved ShortPixel API Key? Background removal will fall back to the Free plan (100 image limit).', 'enable-media-replace');

?>
<?php if ($emr_can_manage) : ?>
<section class="emr-sp-account wrapper" data-confirm-remove="<?php echo esc_attr($emr_confirm_remove); ?>">
	<div class="section-header"><?php esc_html_e('ShortPixel Account', 'enable-media-replace'); ?></div>
	<div class="emr-sp-account-body">
		<p class="emr-sp-intro">
			<?php
			if ($emr_has_api_key) {
				if ($emr_is_upscale) {
					esc_html_e('Your ShortPixel API Key is connected. Image Upscale is unlimited as long as your Unlimited or Unlimited AI plan is active.', 'enable-media-replace');
				} else {
					esc_html_e('Your ShortPixel API Key is connected. Background Removal is unlimited as long as your Unlimited or Unlimited AI plan is active.', 'enable-media-replace');
				}
			} else {
				if ($emr_is_upscale) {
					esc_html_e('Image Upscale is limited on the Free plan. Add your ShortPixel Unlimited or Unlimited AI plan API Key to remove that limit.', 'enable-media-replace');
				} else {
					esc_html_e('Background Removal is limited to 100 images on the Free plan. Add your ShortPixel Unlimited or Unlimited AI plan API Key to remove that limit.', 'enable-media-replace');
				}
			}
			?>
		</p>

		<?php if ($emr_has_api_key) : ?>
			<div class="emr-sp-key-card">
				<div class="emr-sp-key-status">
					<div class="emr-sp-status-dot"></div>
					<div>
						<span class="emr-sp-key-label"><?php esc_html_e('API Key', 'enable-media-replace'); ?></span>
						<span class="emr-sp-key-value"><?php echo esc_html($emr_masked_key); ?></span>
					</div>
				</div>
				<div class="emr-sp-key-actions">
					<a href="#" id="emr-change-key-toggle" class="button emr-sp-change-btn">
						<?php esc_html_e('Change Key', 'enable-media-replace'); ?>
					</a>
					<a href="#" id="emr-remove-key" class="button emr-sp-remove-btn">
						<?php esc_html_e('Remove Key', 'enable-media-replace'); ?>
					</a>
				</div>
			</div>

			<div id="emr-change-key-form" class="emr-sp-edit-form" hidden>
				<label for="emr-new-api-key"><?php esc_html_e('New API Key', 'enable-media-replace'); ?></label>
				<div class="emr-sp-edit-row">
					<input type="text"
					       id="emr-new-api-key"
					       placeholder="<?php esc_attr_e('20-character API Key', 'enable-media-replace'); ?>"
					       maxlength="20"
					       autocomplete="off">
					<button id="emr-update-api-key" class="button emr-sp-save-btn">
						<?php esc_html_e('Save', 'enable-media-replace'); ?>
					</button>
					<a href="#" id="emr-change-key-cancel" class="emr-sp-cancel-link">
						<?php esc_html_e('Cancel', 'enable-media-replace'); ?>
					</a>
				</div>
			</div>
		<?php else : ?>
			<div class="emr-sp-edit-form">
				<label for="emr-new-api-key"><?php esc_html_e('ShortPixel API Key', 'enable-media-replace'); ?></label>
				<div class="emr-sp-edit-row">
					<input type="text"
					       id="emr-new-api-key"
					       placeholder="<?php esc_attr_e('Enter your 20-character API Key', 'enable-media-replace'); ?>"
					       maxlength="20"
					       autocomplete="off">
					<button id="emr-update-api-key" class="button emr-sp-save-btn">
						<?php esc_html_e('Activate', 'enable-media-replace'); ?>
					</button>
				</div>
				<p class="description">
					<?php
					printf(
						wp_kses(
							__('Don\'t have a key yet? <a href="%s" target="_blank" rel="noopener">Get one here</a>.', 'enable-media-replace'),
							array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))
						),
						'https://shortpixel.com/free-sign-up'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div id="emr-apikey-notice" class="notice" hidden></div>
	</div>
</section>


<?php endif; ?>
