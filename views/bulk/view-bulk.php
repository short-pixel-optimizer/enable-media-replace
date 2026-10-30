<?php
/**
 * The Media > Bulk page: wrapper and panel includes.
 *
 * Loaded by BulkViewController::load() through ViewController::loadView().
 * Variables in scope: $controller (BulkViewController) and $view (stdClass):
 * stats (startup data), settings, hasKey, logs, logHeaders, currentOperation,
 * customOperation, errorLog, factors, maxThreshold, operations (per
 * operation: available, reason, count, restorable, label), error,
 * errorTitle, errorContent.
 *
 * The panels are `section.emr-bulk-panel` with a data-panel attribute;
 * js/bulk/emr-bulk-screen.js shows one at a time. No inline scripts or
 * styles: the stylesheet is css/bulk.css, strings for the script are
 * localized in EnableMediaReplacePlugin::bulkScriptData().
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap emr-bulk-wrapper">
	<h1><?php esc_html_e('Bulk processing', 'enable-media-replace'); ?></h1>
	<div class="emr-bulk-notices"></div>

	<div id="emr-bulk-paused" class="emr-bulk-banner emr-bulk-banner-paused" data-action="ResumeBulk" hidden>
		<span class="dashicons dashicons-controls-pause"></span>
		<?php esc_html_e('The bulk is paused in this browser. Click to resume.', 'enable-media-replace'); ?>
	</div>
	<div id="emr-bulk-blocked" class="emr-bulk-banner emr-bulk-banner-blocked" hidden>
		<span class="dashicons dashicons-lock"></span>
		<?php esc_html_e('The bulk is being processed in another window. This page checks again in a few minutes.', 'enable-media-replace'); ?>
	</div>
	<div id="emr-bulk-overquota" class="emr-bulk-banner emr-bulk-banner-error" hidden>
		<strong><?php esc_html_e('The bulk is paused.', 'enable-media-replace'); ?></strong>
		<span class="emr-bulk-overquota-message"></span>
		<a href="<?php echo esc_url(admin_url('upload.php?page=' . EnableMediaReplacePlugin::BULK_PAGE_SLUG)); ?>"><?php esc_html_e('Reload the page after fixing the key or the plan', 'enable-media-replace'); ?></a>
	</div>

	<?php
	$controller->loadView('bulk/part-dashboard');
	$controller->loadView('bulk/part-selection');
	$controller->loadView('bulk/part-summary');
	$controller->loadView('bulk/part-process');
	$controller->loadView('bulk/part-finished');
	$controller->loadView('bulk/part-restore');
	?>
</div>
