<?php
/**
 * The remove-background screen and its POST handler.
 *
 * @package EnableMediaReplace\ViewController
 */

namespace EnableMediaReplace\ViewController;

use EnableMediaReplace\Replacer\Libraries\Unserialize\Unserialize;


if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Controller\ReplaceController as ReplaceController;
use EnableMediaReplace\Api as Api;

/**
 * Remove-background screen (`action=emr_prepare_remove`) and the replacement with the
 * processed image (`action=do_background_replace`).
 *
 * The class is declared as `RemoveBackGroundViewController` (capital G) in
 * `RemoveBackgroundViewController.php`, and getInstance() and the router refer to it as
 * `RemoveBackgroundViewController`. PHP class names are case-insensitive and the file is
 * required explicitly, so this works; a case-sensitive autoloader would not find it.
 *
 * Lifecycle: singleton via getInstance(), called from EnableMediaReplacePlugin::route()
 * when the `background` feature is on. Registers no hooks. The background removal itself
 * runs over AJAX (Ajax::remove_background() -> Api::request()); this class only shows the
 * screen and, after the user confirms, downloads the result and replaces the file. The
 * imported `Unserialize` class is not used.
 *
 * The screen template and the form nonce action are the properties `$view_name` and
 * `$nonce_action`, so that UpscaleViewController can reuse load() and loadPost() unchanged
 * with its own template and nonce; the two controllers differ in nothing else.
 */
class RemoveBackGroundViewController extends \EnableMediaReplace\ViewController
{
	/**
	 * The singleton instance.
	 *
	 * @var RemoveBackGroundViewController|null
	 */
	static $instance;

	/**
	 * Template under views/ that load() renders.
	 *
	 * @var string
	 */
	protected $view_name = 'prepare-remove-background';

	/**
	 * Nonce action loadPost() verifies `emr_nonce` against; the view creates the field
	 * with the same action.
	 *
	 * @var string
	 */
	protected $nonce_action = 'media_remove_background';

	/**
	 * Creates the controller; see ViewController::__construct().
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Returns the singleton.
	 *
	 * @return RemoveBackGroundViewController
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance))
			self::$instance = new RemoveBackgroundViewController();

		return self::$instance;
	}

	/**
	 * Prepares the view data and loads the remove-background screen.
	 *
	 * Requires `upload_files` (ERROR_UPLOAD_PERMISSION) and checkImagePermission()
	 * (ERROR_IMAGE_PERMISSION; the wp_die() after it is unreachable because viewError()
	 * exits). Unlike loadPost(), it does not check the author restriction. Sets the preview
	 * and source sizes on UIHelper and fills `$view` with `attachment`, `sourceFile`
	 * (untranslated, for display) and `settings`: the `enable_media_replace` option merged
	 * over the defaults `bg_type` 'transparent', `bg_color` '#ffffff', `bg_transparency` 100.
	 * Loads the `$view_name` template (`views/prepare-remove-background.php` here).
	 *
	 * @return void
	 */
	public function load()
	{
	 if (!current_user_can('upload_files')) {
			 $this->viewError(self::ERROR_UPLOAD_PERMISSION);
			// wp_die(esc_html__('You do not have permission to upload files.', 'enable-media-replace'));
	 }

	 $attachment_id = intval($_REQUEST['attachment_id']);
	 $attachment = get_post($attachment_id);

	 if (! \emr()->checkImagePermission($attachment))
	 {
		 $this->viewError(self::ERROR_IMAGE_PERMISSION);
	   wp_die( esc_html__('You do not have permission to upload files for this author.', 'enable-media-replace') );
	 }

	 $uiHelper = \emr()->uiHelper();
	 $uiHelper->setPreviewSizes();
	 $uiHelper->setSourceSizes($attachment_id);

	 $replacer = new ReplaceController($attachment_id);
	 $file = $replacer->getSourceFile(true); // for display only

	 $defaults = array(
	 	'bg_type' => 'transparent',
	 	'bg_color' => '#ffffff',
	 	'bg_transparency' => 100,
	 );
	 $settings = get_option('enable_media_replace', $defaults);
	 $settings = array_merge($defaults, $settings); // might miss some

	 $this->view->attachment = $attachment;
	 $this->view->settings = $settings;
	 $this->view->sourceFile = $file;

	 $this->loadView($this->view_name);

	}

	// When the background has been posted - process.
	/**
	 * Replaces the attachment with the background-removed image and ends the request.
	 *
	 * Sequence:
	 * 1. Nonce `emr_nonce` for the `$nonce_action` action (`media_remove_background` here)
	 *    (ERROR_NONCE).
	 * 2. `key` (the transient key returned by Api::request()) must be non-empty (ERROR_KEY).
	 * 3. `ID` present (ERROR_FORM), checkImagePermission() (ERROR_IMAGE_PERMISSION), and the
	 *    user must not be author restricted (ERROR_IMAGE_PERMISSION). The wp_die() calls
	 *    after viewError() are unreachable.
	 * 4. setView(), then replaceBackground() downloads the stored result to a temp file. A
	 *    failed download or missing file shows ERROR_DOWNLOAD_FAILED.
	 * 5. A new ReplaceController runs with MODE_REPLACE, TIME_UPDATEMODIFIED, now as the
	 *    date, the downloaded temp file as upload and the current source file name as the
	 *    new name, so the file is overwritten in place. `replace_type` is passed as the
	 *    integer MODE_REPLACE, which setupParams() also reads as replace mode because it is
	 *    not 'replace_and_search'.
	 * 6. viewError() with the controller's last error when setupParams() fails (without the
	 *    error data); viewSuccess() when run() returns true.
	 *
	 * The download key is not bound to the attachment: any key from the current transient
	 * store can be applied to any attachment that passes the permission checks.
	 *
	 * @todo On a failed download, `$this->view->errorMessage = $result->message` is
	 *       overwritten by viewError(ERROR_DOWNLOAD_FAILED), so the API's message is never
	 *       shown. Suggested fix: pass the message as error data, or set it as the
	 *       description after the generic message.
	 *
	 * @uses ReplaceController::MODE_REPLACE
	 * @uses ReplaceController::TIME_UPDATEMODIFIED
	 * @return void Normally never returns: every path ends in viewSuccess() or viewError().
	 */
	public function loadPost()
	{
		 if ( ! isset( $_POST['emr_nonce'] )
		 || ! wp_verify_nonce( $_POST['emr_nonce'], $this->nonce_action ))
		 {
			 $this->viewError(self::ERROR_NONCE);
		 }

		 $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : null;
		 if (is_null($key) || strlen($key) === 0)
		 {
			 $this->viewError(self::ERROR_KEY);
		 }

		 $post_id = isset($_POST['ID']) ? intval($_POST['ID']) : null; // sanitize, post_id.
		 if (is_null($post_id)) {
			 	 $this->viewError(self::ERROR_FORM);
		 }

		 $attachment = get_post($post_id);

		 if (! \emr()->checkImagePermission($attachment))
		 {
			 $this->viewError(self::ERROR_IMAGE_PERMISSION);
		   wp_die( esc_html__('You do not have permission to upload files for this author.', 'enable-media-replace') );
		 }		 

 		 $uiHelper = \emr()->uiHelper();
		 $author_restricted = $uiHelper->is_author_restricted();

		 if (true === $author_restricted)
		 {
			$this->viewError(self::ERROR_IMAGE_PERMISSION);
		   wp_die( esc_html__('You do not have permission to upload files for this author.', 'enable-media-replace') );
		 }


		 $this->setView($post_id);
		 $result = $this->replaceBackground($post_id, $key);

		 if (false === $result->success)
		 {
			  $this->view->errorMessage = $result->message;
				$this->viewError(self::ERROR_DOWNLOAD_FAILED);
		 }
		 elseif (! file_exists($result->image))
		 {
			 $this->viewError(self::ERROR_DOWNLOAD_FAILED);
		 }

		$replaceController = new ReplaceController($post_id);
		$sourceFile = $replaceController->getSourceFile();

		$datetime = current_time('mysql');

		$params = array(
			 'post_id' => $post_id,
			 'replace_type' => ReplaceController::MODE_REPLACE,
			 'timestamp_replace' => ReplaceController::TIME_UPDATEMODIFIED,
			 'new_date' => $datetime,
			 'is_custom_date' => false,
			 'remove_background' => true,
			 'uploadFile' => $result->image,
			 'new_filename' => $sourceFile->getFileName(),
		);


		 $check = $replaceController->setupParams($params);
		 $this->setView($post_id, $params);

		 if (false === $check)
		 {
				$error = $replaceController->returnLastError();
				$this->viewError($error);
		 }

		 $result = $replaceController->run();
		 if (true == $result)
		 {
				$this->viewSuccess();
		 }

	}

	// Low init might only be w/ post_id ( error handling et al ), most advanced / nicer with params.
	/**
	 * Sets the view data used by the success and error screens.
	 *
	 * Sets `post_id`, `postUrl` (success redirect) and `emrUrl` (failure redirect).
	 * $params is accepted but not used.
	 *
	 * @param int|null $post_id Attachment ID.
	 * @param array    $params  Unused.
	 * @return void
	 */
	protected function setView($post_id, $params = array())
	{
		 $uiHelper = \emr()->uiHelper();
		 $this->view->post_id = $post_id;
		 $this->view->postUrl = $uiHelper->getSuccesRedirect($post_id);
		 $this->view->emrUrl = $uiHelper->getFailedRedirect($post_id);

	}


	/**
	 * Downloads the processed image stored under the transient key.
	 *
	 * Despite the name, this only downloads: it calls Api::handleDownload(). The replacement
	 * itself happens in loadPost().
	 *
	 * @param int    $post_id Attachment ID (unused).
	 * @param string $key     Transient key returned by Api::request().
	 * @return \stdClass Result object: `success`, `image` (temp file path on success),
	 *                   `message` (on failure).
	 */
	protected function replaceBackground($post_id, $key)
	{
		$api = new Api();
		$result = $api->handleDownload($key);

		return $result;
	}



} // class
