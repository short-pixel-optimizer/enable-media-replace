<?php
/**
 * Bulk page variant of the ShortPixel Account box (views/shortpixel-account.php).
 *
 * A collapsible row: "ShortPixel Account - ✓ Connected - Unlimited",
 * collapsed while a key is stored, open while there is none. It keeps the
 * element IDs of the shared box, so js/shortpixel-account.js (handle
 * `emr_shortpixel_account`, localised as `emrAccount`) saves, changes and
 * removes the key the same way. A stored key is always on an Unlimited plan:
 * ApiKeyManager::saveAndVerify() refuses any other.
 *
 * Variables in scope: see views/bulk/view-bulk.php. Included for users with
 * `manage_options` only.
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}

$emr_key_manager = ApiKeyManager::getInstance();
$emr_has_api_key = $emr_key_manager->hasApiKey();

wp_enqueue_script('emr_shortpixel_account');
?>
<details class="emr-sp-account emr-bulk-collapsible emr-bulk-account" data-confirm-remove="<?php esc_attr_e('Remove the API key? Bulk Enhance will stop working until you add one.', 'enable-media-replace'); ?>"<?php echo $emr_has_api_key ? '' : ' open'; ?>>
	<summary>
		<span class="emr-bulk-collapsible-title"><?php esc_html_e('ShortPixel Account', 'enable-media-replace'); ?></span>
		<?php if ($emr_has_api_key) : ?>
			<span class="emr-bulk-account-status emr-bulk-account-connected"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('Connected', 'enable-media-replace'); ?></span>
			<span class="emr-bulk-account-plan"><?php esc_html_e('Unlimited', 'enable-media-replace'); ?></span>
		<?php else : ?>
			<span class="emr-bulk-account-status emr-bulk-account-disconnected"><?php esc_html_e('Not connected', 'enable-media-replace'); ?></span>
		<?php endif; ?>
		<span class="emr-bulk-chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
	</summary>

	<div class="emr-bulk-collapsible-content emr-sp-account-body">
		<?php if ($emr_has_api_key) : ?>
			<p class="emr-sp-intro"><?php esc_html_e('Unlimited plan active. Background removal and upscale have no limit while your plan is active.', 'enable-media-replace'); ?></p>
			<div class="emr-sp-key-card">
				<div class="emr-sp-key-status">
					<span class="emr-sp-status-dot"></span>
					<span><?php esc_html_e('API key:', 'enable-media-replace'); ?> <code class="emr-sp-key-value"><?php echo esc_html($emr_key_manager->getMaskedKey()); ?></code></span>
				</div>
				<div class="emr-sp-key-actions">
					<a href="#" id="emr-change-key-toggle" class="button"><?php esc_html_e('Change key', 'enable-media-replace'); ?></a>
					<a href="#" id="emr-remove-key" class="button emr-bulk-button-danger"><?php esc_html_e('Remove key', 'enable-media-replace'); ?></a>
				</div>
			</div>

			<div id="emr-change-key-form" class="emr-sp-edit-form" hidden>
				<label for="emr-new-api-key"><?php esc_html_e('New API key', 'enable-media-replace'); ?></label>
				<div class="emr-sp-edit-row">
					<input type="text" id="emr-new-api-key" class="emr-bulk-key-input" placeholder="<?php esc_attr_e('Paste your API key', 'enable-media-replace'); ?>" maxlength="20" autocomplete="off" spellcheck="false">
					<button type="button" id="emr-update-api-key" class="button button-primary"><?php esc_html_e('Save', 'enable-media-replace'); ?></button>
					<a href="#" id="emr-change-key-cancel" class="emr-sp-cancel-link"><?php esc_html_e('Cancel', 'enable-media-replace'); ?></a>
				</div>
			</div>
		<?php else : ?>
			<p class="emr-sp-intro"><?php esc_html_e('The Free plan includes 10 background removals and upscales. To remove the limit, add an API key from a ShortPixel Unlimited or Unlimited AI plan.', 'enable-media-replace'); ?></p>
			<div class="emr-sp-edit-form">
				<label for="emr-new-api-key"><?php esc_html_e('API key', 'enable-media-replace'); ?></label>
				<div class="emr-sp-edit-row">
					<input type="text" id="emr-new-api-key" class="emr-bulk-key-input" placeholder="<?php esc_attr_e('Paste your API key', 'enable-media-replace'); ?>" maxlength="20" autocomplete="off" spellcheck="false">
					<button type="button" id="emr-update-api-key" class="button button-primary"><?php esc_html_e('Activate', 'enable-media-replace'); ?></button>
				</div>
				<p class="description">
					<?php
					printf(
						/* translators: %1$s and %2$s: opening and closing tags of the sign-up link. */
						esc_html__('Don\'t have a key yet? %1$sGet a free API key%2$s', 'enable-media-replace'),
						'<a href="' . esc_url($view->signupUrl) . '" target="_blank" rel="noopener">',
						'</a>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div id="emr-apikey-notice" class="notice" hidden></div>
	</div>
</details>
