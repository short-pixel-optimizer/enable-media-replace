<?php
namespace EnableMediaReplace;

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Notices\NoticeController as Notices;
use \EnableMediaReplace\Replacer as Replacer;


if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (! check_admin_referer('do_background_replace'))
{
	wp_die(esc_html__('Nonce in form failed. Go back, refresh and try again.', 'enable-media-replace'));
}

$key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : null;

if (is_null($key) || strlen($key) == 0)
{
	wp_die(esc_html__('Error while sending form (no key). Please try again.', 'enable-media-replace'));
}

$post_id = isset($_POST['ID']) ? intval($_POST['ID']) : null; // sanitize, post_id.
if (is_null($post_id)) {
    wp_die(esc_html__('Error in request. Please try again', 'enable-media-replace'));
}

$attachment = get_post($post_id);

if (! emr()->checkImagePermission($attachment)) {
    wp_die(esc_html__('You do not have permission to upload files for this author.', 'enable-media-replace'));
}

$uiHelper = emr()->uiHelper();

$replacer = new Replacer($post_id);
$replacer->setMode(\EnableMediaReplace\Replacer::MODE_REPLACE);

$datetime = current_time('mysql');
$replacer->setTimeMode( \EnableMediaReplace\Replacer::TIME_UPDATEMODIFIED, $datetime);




$api = new Api();
$result = $api->handleDownload($key);

if (! $result->success)
{
	 die($result->message);
}

// When are 1-1 replacing.
$source = $replacer->getSourceFile();

$redirect_error = $uiHelper->getFailedRedirect($post_id);
$redirect_success = $uiHelper->getSuccesRedirect($post_id);

if (! file_exists($result->image))
{
	 Log::addError('Download File not here', $result->image);
	 exit(__('Temp file does not exist', 'enable-media-replace'));
}

try {
		$result = $replacer->replaceWith($result->image, $source->getFileName() , true);
} catch (\RunTimeException $e) {
		print_r($e->getMessage());
		Log::addError($e->getMessage());
		die;

}

if (is_null($result)) {
		 wp_safe_redirect($redirect_error);
		 exit();
}

$noticeController = Notices::getInstance();
$notice = Notices::addSuccess('<p>' . __('File successfully replaced', 'enable-media-replace') . '</p>');
$notice->is_removable = false;
$noticeController->update();

wp_redirect($redirect_success);
exit();
