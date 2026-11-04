<?php
/**
 * ShortPixel Account row: shows, saves, changes and removes the ShortPixel API key.
 *
 * One collapsible `<details>` row, the same on the bulk page and the single
 * remove background and upscale screens: "ShortPixel Account - ✓ Connected -
 * Unlimited" with a key, collapsed; without one "Not connected" and open on
 * the bulk page (the bulk needs a key), "Free plan (10 images)" and collapsed
 * on the single screens (they work without one). A stored key is always on an
 * Unlimited plan: ApiKeyManager::saveAndVerify() refuses any other.
 *
 * Included by views/bulk/part-dashboard.php, views/prepare-remove-background.php
 * and views/prepare-upscale.php (inside their form). Rendered only for users with
 * `manage_options`; for them it also enqueues `emr_shortpixel_account`
 * (js/shortpixel-account.js, localised as `emrAccount`), which posts to the
 * `emr_save_api_key` and `emr_delete_api_key` AJAX actions (nonce
 * `emr_save_api_key`) and reloads the page on success. Styles: scss/_shortpixel-account.scss,
 * compiled into css/bulk.css and css/single.css.
 *
 * Variables in scope:
 * - $emr_account_context (string, optional) 'bulk', 'background' (default) or
 *   'upscale': the no-key state, the remove confirmation (`data-confirm-remove`)
 *   and the UTM campaign of the sign-up link. Set it before the include.
 *
 * With a stored key: the masked key (ApiKeyManager::getMaskedKey()), "Change key"
 * (`#emr-change-key-toggle`, reveals `#emr-change-key-form`) and "Remove key"
 * (`#emr-remove-key`, asks for confirmation). Without one: the `#emr-new-api-key`
 * input (20 characters), "Activate" (`#emr-update-api-key`) and the sign-up link.
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

if (! current_user_can('manage_options')) {
	return;
}

$emr_context     = (isset($emr_account_context) && in_array($emr_account_context, array('bulk', 'background', 'upscale'), true)) ? $emr_account_context : 'background';
$emr_is_bulk     = ('bulk' === $emr_context);
$emr_key_manager = ApiKeyManager::getInstance();
$emr_has_api_key = $emr_key_manager->hasApiKey();
$emr_signup_url  = 'https://shortpixel.com/free-sign-up?utm_source=plugin&utm_medium=emr&utm_campaign=' . ($emr_is_bulk ? 'emr_bulk' : 'emr_' . $emr_context);
$emr_confirm     = $emr_is_bulk
	? __('Remove the API key? Bulk Enhance will stop working until you add one.', 'enable-media-replace')
	: __('Remove the API key? Background removal and upscale go back to the Free plan (10 images).', 'enable-media-replace');
// Open by default only where nothing works without a key.
$emr_open = (! $emr_has_api_key && $emr_is_bulk);

wp_enqueue_script('emr_shortpixel_account');
?>
<details class="emr-sp-account emr-collapsible emr-sp-account-<?php echo esc_attr($emr_context); ?>" data-confirm-remove="<?php echo esc_attr($emr_confirm); ?>"<?php echo $emr_open ? ' open' : ''; ?>>
	<summary>
		<span class="emr-collapsible-title"><?php esc_html_e('ShortPixel Account', 'enable-media-replace'); ?></span>
		<?php if ($emr_has_api_key) : ?>
			<span class="emr-sp-account-status emr-sp-account-connected"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('Connected', 'enable-media-replace'); ?></span>
			<span class="emr-sp-account-plan"><?php esc_html_e('Unlimited', 'enable-media-replace'); ?></span>
		<?php elseif ($emr_is_bulk) : ?>
			<span class="emr-sp-account-status emr-sp-account-disconnected"><?php esc_html_e('Not connected', 'enable-media-replace'); ?></span>
		<?php else : ?>
			<span class="emr-sp-account-status emr-sp-account-free"><?php esc_html_e('Free plan (10 images)', 'enable-media-replace'); ?></span>
		<?php endif; ?>
		<span class="emr-collapsible-chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
	</summary>

	<div class="emr-collapsible-content emr-sp-account-body">
		<?php if ($emr_has_api_key) : ?>
			<p class="emr-sp-intro"><?php esc_html_e('Unlimited plan active. Background removal and upscale have no limit while your plan is active.', 'enable-media-replace'); ?></p>
			<div class="emr-sp-key-card">
				<div class="emr-sp-key-status">
					<span class="emr-sp-status-dot"></span>
					<span><?php esc_html_e('API key:', 'enable-media-replace'); ?> <code class="emr-sp-key-value"><?php echo esc_html($emr_key_manager->getMaskedKey()); ?></code></span>
				</div>
				<div class="emr-sp-key-actions">
					<a href="#" id="emr-change-key-toggle" class="button"><?php esc_html_e('Change key', 'enable-media-replace'); ?></a>
					<a href="#" id="emr-remove-key" class="button emr-button-danger"><?php esc_html_e('Remove key', 'enable-media-replace'); ?></a>
				</div>
			</div>

			<div id="emr-change-key-form" class="emr-sp-edit-form" hidden>
				<label for="emr-new-api-key"><?php esc_html_e('New API key', 'enable-media-replace'); ?></label>
				<div class="emr-sp-edit-row">
					<input type="text" id="emr-new-api-key" class="emr-sp-key-input" placeholder="<?php esc_attr_e('Paste your API key', 'enable-media-replace'); ?>" maxlength="20" autocomplete="off" spellcheck="false">
					<button type="button" id="emr-update-api-key" class="button button-primary"><?php esc_html_e('Save', 'enable-media-replace'); ?></button>
					<a href="#" id="emr-change-key-cancel" class="emr-sp-cancel-link"><?php esc_html_e('Cancel', 'enable-media-replace'); ?></a>
				</div>
			</div>
		<?php else : ?>
			<p class="emr-sp-intro"><?php esc_html_e('The Free plan includes 10 background removals and upscales. To remove the limit, add an API key from a ShortPixel Unlimited or Unlimited AI plan.', 'enable-media-replace'); ?></p>
			<div class="emr-sp-edit-form">
				<label for="emr-new-api-key"><?php esc_html_e('API key', 'enable-media-replace'); ?></label>
				<div class="emr-sp-edit-row">
					<input type="text" id="emr-new-api-key" class="emr-sp-key-input" placeholder="<?php esc_attr_e('Paste your API key', 'enable-media-replace'); ?>" maxlength="20" autocomplete="off" spellcheck="false">
					<button type="button" id="emr-update-api-key" class="button button-primary"><?php esc_html_e('Activate', 'enable-media-replace'); ?></button>
				</div>
				<p class="description">
					<?php
					printf(
						/* translators: %1$s and %2$s: opening and closing tags of the sign-up link. */
						esc_html__('Don\'t have a key yet? %1$sGet a free API key%2$s', 'enable-media-replace'),
						'<a href="' . esc_url($emr_signup_url) . '" target="_blank" rel="noopener">',
						'</a>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div id="emr-apikey-notice" class="notice" hidden></div>
	</div>
</details>
