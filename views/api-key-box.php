<?php
namespace EnableMediaReplace;

use EnableMediaReplace\ApiKeyManager;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// ShortPixel Account / API Key box. Shared between the single and bulk background removal screens.
$apiKeyManager   = ApiKeyManager::getInstance();
$emr_has_api_key = $apiKeyManager->hasApiKey();
$emr_masked_key  = $apiKeyManager->getMaskedKey();
$emr_can_manage  = current_user_can('manage_options');

?>
<?php if ($emr_can_manage) : ?>
<section class="emr-sp-account wrapper">
	<div class="section-header"><?php esc_html_e('ShortPixel Account', 'enable-media-replace'); ?></div>
	<div class="emr-sp-account-body">
		<p class="emr-sp-intro">
			<?php
			if ($emr_has_api_key) {
				esc_html_e('Your ShortPixel API Key is connected. Background Removal is unlimited as long as your Unlimited or Unlimited AI plan is active.', 'enable-media-replace');
			} else {
				esc_html_e('Background Removal is limited to 100 images on the Free plan. Add your ShortPixel Unlimited or Unlimited AI plan API Key to remove that limit.', 'enable-media-replace');
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

			<div id="emr-change-key-form" class="emr-sp-edit-form" style="display:none;">
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

		<div id="emr-apikey-notice" class="notice" style="display:none;"></div>
	</div>
</section>

<style>
.emr-sp-account { margin-top: 16px; margin-bottom: 24px; }
.emr-sp-account-body { padding: 12px 0 4px; }
.emr-sp-intro { margin: 0 0 12px; color: #50575e; }
.emr-sp-key-card {
	display: flex;
	align-items: center;
	justify-content: space-between;
	background: #f8f8f8;
	border: 1px solid #e2e2e2;
	border-radius: 6px;
	padding: 12px 16px;
	gap: 12px;
}
.emr-sp-key-status { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.emr-sp-status-dot {
	width: 9px;
	height: 9px;
	border-radius: 50%;
	background: #46b450;
	flex-shrink: 0;
	box-shadow: 0 0 0 3px rgba(70,180,80,.15);
}
.emr-sp-key-label {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: .05em;
	color: #888;
	display: block;
	margin-bottom: 2px;
}
.emr-sp-key-value { font-size: 13px; color: #333; }
.emr-sp-key-actions { display: flex; gap: 8px; flex-shrink: 0; align-items: center; }
.emr-sp-change-btn,
.emr-sp-remove-btn {
	display: inline-flex !important;
	align-items: center !important;
	justify-content: center !important;
	font-size: 12px !important;
	padding: 0 14px !important;
	height: 32px !important;
	min-height: 32px !important;
	line-height: 1 !important;
	min-width: 0 !important;
	box-sizing: border-box !important;
}
.emr-sp-remove-btn {
	color: #b32d2e !important;
	border-color: #b32d2e !important;
}
.emr-sp-remove-btn:hover {
	background: #b32d2e !important;
	color: #fff !important;
}
.emr-sp-edit-form {
	margin-top: 12px;
	background: #fff;
	border: 1px solid #e2e2e2;
	border-radius: 6px;
	padding: 16px;
}
.emr-sp-edit-form label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	color: #555;
	margin-bottom: 8px;
	text-transform: uppercase;
	letter-spacing: .04em;
}
.emr-sp-edit-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.emr-sp-edit-row input[type="text"] { flex: 1; min-width: 180px; max-width: 280px; }
.emr-sp-save-btn {
	background: #1abdca !important;
	border-color: #15a8b4 !important;
	color: #fff !important;
	font-weight: 600 !important;
}
.emr-sp-save-btn:hover { background: #15a8b4 !important; }
.emr-sp-save-btn:disabled { background: #ccc !important; border-color: #bbb !important; }
.emr-sp-cancel-link { font-size: 12px; color: #888; text-decoration: none; }
.emr-sp-cancel-link:hover { color: #555; }
#emr-apikey-notice { border-radius: 4px; margin-top: 12px; padding: 8px 12px; }
#emr-apikey-notice p { margin: 0; }
</style>

<script>
jQuery(function ($) {
	var nonce = '<?php echo esc_js(wp_create_nonce('emr_save_api_key')); ?>';
	var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

	$('#emr-change-key-toggle').on('click', function (e) {
		e.preventDefault();
		$(this).hide();
		$('#emr-change-key-form').slideDown(160);
		$('#emr-new-api-key').focus();
	});

	$('#emr-change-key-cancel').on('click', function (e) {
		e.preventDefault();
		$('#emr-change-key-form').slideUp(160);
		$('#emr-change-key-toggle').show();
		$('#emr-new-api-key').val('');
		$('#emr-apikey-notice').hide().removeClass('notice-success notice-error');
	});

	$('#emr-remove-key').on('click', function (e) {
		e.preventDefault();
		if ( ! window.confirm('<?php echo esc_js(__('Remove the saved ShortPixel API Key? Background removal will fall back to the Free plan (100 image limit).', 'enable-media-replace')); ?>') ) {
			return;
		}
		var $btn = $(this);
		var $msg = $('#emr-apikey-notice');
		$btn.prop('disabled', true);
		$msg.hide().removeClass('notice-success notice-error');

		$.post(ajaxUrl, {
			action: 'emr_delete_api_key',
			nonce : nonce
		}, function (response) {
			if (response.success) {
				$msg.addClass('notice notice-success').html('<p>' + response.data.message + '</p>').show();
				setTimeout(function () { location.reload(); }, 900);
			} else {
				$btn.prop('disabled', false);
				$msg.addClass('notice notice-error').html('<p>' + response.data.message + '</p>').show();
			}
		}).fail(function () {
			$btn.prop('disabled', false);
			$msg.addClass('notice notice-error').html('<p><?php echo esc_js(__('Request failed. Please try again.', 'enable-media-replace')); ?></p>').show();
		});
	});

	$('#emr-update-api-key').on('click', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $msg = $('#emr-apikey-notice');
		var key  = $('#emr-new-api-key').val().trim();
		var originalText = $btn.text();

		$btn.prop('disabled', true).text('<?php echo esc_js(__('Verifying…', 'enable-media-replace')); ?>');
		$msg.hide().removeClass('notice-success notice-error');

		$.post(ajaxUrl, {
			action : 'emr_save_api_key',
			nonce  : nonce,
			api_key: key
		}, function (response) {
			$btn.prop('disabled', false).text(originalText);
			if (response.success) {
				$msg.addClass('notice notice-success').html('<p>' + response.data.message + '</p>').show();
				setTimeout(function () { location.reload(); }, 1200);
			} else {
				$msg.addClass('notice notice-error').html('<p>' + response.data.message + '</p>').show();
			}
		}).fail(function () {
			$btn.prop('disabled', false).text(originalText);
			$msg.addClass('notice notice-error').html('<p><?php echo esc_js(__('Request failed. Please try again.', 'enable-media-replace')); ?></p>').show();
		});
	});
});
</script>
<?php endif; ?>
