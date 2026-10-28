<?php
/**
 * POST handler of the replace form.
 *
 * @package EnableMediaReplace\ViewController
 */

namespace EnableMediaReplace\ViewController;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use EnableMediaReplace as emr;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Controller\UploadController as UploadController;
use EnableMediaReplace\Controller\ReplaceController as ReplaceController;


/**
 * Handles the submitted replace form (`action=media_replace_upload`).
 *
 * Lifecycle: singleton via getInstance(), called from EnableMediaReplacePlugin::route().
 * Validates the nonce, capabilities and form, parses the date mode and the upload, runs
 * ReplaceController, saves the form choices as defaults on success, and ends with
 * viewSuccess() or viewError() (both exit). Registers no hooks. The imported
 * `UploadController` class does not exist and is not used.
 */
class UploadViewController extends \EnableMediaReplace\ViewController
{
	/**
	 * The singleton instance.
	 *
	 * @var UploadViewController|null
	 */
	static $instance;

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
	 * @return UploadViewController
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance))
			self::$instance = new UploadViewController();

		return self::$instance;
	}

	/**
	 * Processes the replace form and ends the request with the success or error screen.
	 *
	 * - Request without POST data: sets the view from `attachment_id` and, when
	 *   `emr_success` is in the query string, shows the success screen. Without
	 *   `emr_success` it falls through to the nonce check and shows ERROR_NONCE.
	 * - Nonce `emr_nonce` for action `media_replace_upload` (ERROR_NONCE).
	 * - `upload_files` capability (ERROR_UPLOAD_PERMISSION).
	 * - `ID` present (ERROR_FORM); intval() of a non-numeric ID gives 0, which then fails
	 *   the next check.
	 * - checkImagePermission() on the attachment (ERROR_IMAGE_PERMISSION).
	 * - getPost(), setView(), ReplaceController::setupParams() (its error and error data go
	 *   to viewError()), then ReplaceController::run().
	 * - On a true result, updateSettings() and viewSuccess().
	 *
	 * @return void Normally never returns: every path ends in viewSuccess() or viewError().
	 */
	public function load()
	{

		// No form submit?
		if (count($_POST) == 0) {
			$post_id = isset($_REQUEST['attachment_id']) ? intval($_REQUEST['attachment_id']) : null;
			$this->setView($post_id);

			if (isset($_GET['emr_success'])) {
				$this->viewSuccess();
			}
		}

		if (
			! isset($_POST['emr_nonce'])
			|| ! wp_verify_nonce($_POST['emr_nonce'], 'media_replace_upload')
		) {
			$this->viewError(self::ERROR_NONCE);
		}

		if (!current_user_can('upload_files')) {
			$this->viewError(self::ERROR_UPLOAD_PERMISSION);
		}

		$post_id = isset($_POST['ID']) ? intval($_POST['ID']) : null; // sanitize, post_id.
		if (is_null($post_id)) {
			$this->viewError(self::ERROR_FORM);
		}
		$attachment = get_post($post_id);

		if (! emr()->checkImagePermission($attachment)) {
			$this->viewError(self::ERROR_IMAGE_PERMISSION);
		}

		$params = $this->getPost();

		// UploadController here / replacerController here with save Settings as well? s
		$this->setView($post_id, $params); // set variables needed for view.

		$replaceController = new ReplaceController($post_id);
		$check = $replaceController->setupParams($params);

		if (false === $check) {
			$error = $replaceController->returnLastError();
			$data = $replaceController->returnLastErrorData();
			$this->viewError($error, $data);
		}

		$result = $replaceController->run();

		if (true == $result) {
			// Save settings only on success run, to prevent bad data becoming default.
			$this->updateSettings($params);
			$this->viewSuccess();
		}
	}

	/**
	 * Reads and normalises the replace form fields into the ReplaceController parameters.
	 *
	 * - `replace_type`: sanitized text, false when absent; forced to 'replace' when the user
	 *   is author restricted (UIHelper::is_author_restricted(), no `edit_others_posts`).
	 * - `timestamp_replace`: int, default TIME_UPDATEMODIFIED.
	 * - `remove_after_progress`: presence flag, sends the user to remove-background after.
	 * - `new_location`: presence flag, passed through `emr/replace/file_is_movable`
	 *   (WP Offload Media turns it off for offloaded files).
	 * - `location_dir`: sanitized text or null.
	 * - Date modes: TIME_UPDATEALL and TIME_UPDATEMODIFIED use current_time('mysql').
	 *   TIME_CUSTOM builds the date from `custom_date_formatted` (Y-m-d) plus
	 *   `custom_hour` and `custom_minute` (left-padded to two digits), parsed with
	 *   DateTime::createFromFormat('Y-m-d G:i'); an unparsable date shows ERROR_TIME. These
	 *   three custom fields are read without isset() or sanitizing.
	 * - The upload itself comes from getUpload().
	 *
	 * @todo A `timestamp_replace` value other than 1, 2 or 3 leaves `$datetime` undefined.
	 *       Suggested fix: add a `default:` that falls back to TIME_UPDATEMODIFIED with
	 *       current_time('mysql').
	 *
	 * @hook filter emr/replace/file_is_movable
	 * @uses ReplaceController::TIME_UPDATEALL
	 * @uses ReplaceController::TIME_UPDATEMODIFIED
	 * @uses ReplaceController::TIME_CUSTOM
	 * @return array {
	 *     @type int         $post_id           Attachment ID.
	 *     @type string|false $replace_type     'replace', 'replace_and_search' or false.
	 *     @type int         $timestamp_replace TIME_* mode.
	 *     @type string      $new_date          MySQL date (site local time).
	 *     @type bool        $new_location      Whether to move the file.
	 *     @type string|null $location_dir      New directory relative to uploads.
	 *     @type bool        $is_custom_date    True for TIME_CUSTOM.
	 *     @type bool        $remove_background Redirect to remove-background on success.
	 *     @type string      $uploadFile        Temporary upload path.
	 *     @type string      $new_filename      Original upload file name.
	 * }
	 */
	protected function getPost()
	{
		$ID = intval($_POST["ID"]); // legacy
		$replace_type = isset($_POST["replace_type"]) ? sanitize_text_field($_POST["replace_type"]) : false;

		$uiHelper = \emr()->uiHelper();
		$author_restricted = $uiHelper->is_author_restricted();

		// Only one option here. 
		if (true === $author_restricted) {
			$replace_type = 'replace';
		}

		$timestamp_replace = isset($_POST['timestamp_replace']) ? intval($_POST['timestamp_replace']) : ReplaceController::TIME_UPDATEMODIFIED;
		$remove_background = (isset($_POST['remove_after_progress'])) ? true : false;

		$do_new_location  = isset($_POST['new_location']) ? true : false;
		$do_new_location = apply_filters('emr/replace/file_is_movable', $do_new_location, $ID);
		$new_location_dir = isset($_POST['location_dir']) ? sanitize_text_field($_POST['location_dir']) : null;

		$is_custom_date = false;

		switch ($timestamp_replace) {
			case ReplaceController::TIME_UPDATEALL:
			case ReplaceController::TIME_UPDATEMODIFIED:
				$datetime = current_time('mysql');
				break;
			case ReplaceController::TIME_CUSTOM:
				$custom_date = $_POST['custom_date_formatted'];
				$custom_hour = str_pad($_POST['custom_hour'], 2, 0, STR_PAD_LEFT);
				$custom_minute = str_pad($_POST['custom_minute'], 2, 0, STR_PAD_LEFT);

				// create a mysql time representation from what we have.
				Log::addDebug('Custom Date - ' . $custom_date . ' ' . $custom_hour . ':' . $custom_minute);
				$custom_date = \DateTime::createFromFormat('Y-m-d G:i', $custom_date . ' ' . $custom_hour . ':' . $custom_minute);
				if ($custom_date === false) {
					$this->viewError(self::ERROR_TIME);
				}
				$datetime  =  $custom_date->format("Y-m-d H:i:s");
				$is_custom_date = true;
				break;
		}

		list($uploadFile, $new_filename) = $this->getUpload();

		return array(
			'post_id' => $ID,
			'replace_type' => $replace_type,
			'timestamp_replace' => $timestamp_replace,
			'new_date' => $datetime,
			'new_location' => $do_new_location,
			'location_dir' => $new_location_dir,
			'is_custom_date' => $is_custom_date,
			'remove_background' => $remove_background,
			'uploadFile' => $uploadFile,
			'new_filename' => $new_filename,
		);
	}

	// Low init might only be w/ post_id ( error handling et al ), most advanced / nicer with params.
	/**
	 * Sets the view data used by the success and error screens.
	 *
	 * Sets `post_id`, `postUrl` (success redirect) and `emrUrl` (back to the replace screen).
	 * When `$params['remove_background']` is true, `postUrl` goes to the remove-background
	 * screen instead.
	 *
	 * @param int|null $post_id Attachment ID.
	 * @param array    $params  Parameters from getPost(); may be empty.
	 * @return void
	 */
	protected function setView($post_id, $params = array())
	{
		$uiHelper = \emr()->uiHelper();
		$this->view->post_id = $post_id;
		$this->view->postUrl = $uiHelper->getSuccesRedirect($post_id);
		$this->view->emrUrl = $uiHelper->getFailedRedirect($post_id);

		if (isset($params['remove_background']) && true === $params['remove_background']) {
			$this->view->postUrl = $uiHelper->getBackgroundRemoveRedirect($post_id);
		}
	}


	/**
	 * Saves the form choices as defaults for the next replace screen.
	 *
	 * Called only after a successful run(), so failed submissions do not become defaults.
	 * Writes `replace_type`, `timestamp_replace`, `new_location`, `new_location_dir` and,
	 * for a custom date, `custom_date` into the `enable_media_replace` option (not
	 * autoloaded), keeping the other keys (such as the remove-background settings).
	 *
	 * @param array $params Parameters from getPost().
	 * @return void
	 */
	protected function updateSettings($params)
	{
		$settings = get_option('enable_media_replace', array()); // save settings and show last loaded.
		$settings['replace_type'] = $params['replace_type'];
		$settings['timestamp_replace'] = $params['timestamp_replace'];
		$settings['new_location'] = $params['new_location'];
		$settings['new_location_dir'] = $params['location_dir'];

		if (true === $params['is_custom_date']) {
			$settings['custom_date']  = $params['new_date'];
		}
		update_option('enable_media_replace', $settings, false);
	}

	/**
	 * Validates the uploaded file `userfile` and returns its temporary path and name.
	 *
	 * Checks is_uploaded_file(), runs wp_check_filetype_and_ext() on the original array,
	 * then applies the core `wp_handle_upload_prefilter` filter. A file whose extension is
	 * not allowed shows ERROR_SECURITY, unless the user has `unfiltered_upload`. No uploaded
	 * file shows ERROR_UPLOAD_FAILED. `$new_filetype` is computed but unused, and the
	 * comment after the return is unreachable.
	 *
	 * @todo The error check reads `$uploadFilearray` (lower-case "a", an undefined variable)
	 *       and then `$uploadFileArray['userfile']['error']` (wrong index), so it never
	 *       fires and an error set by `wp_handle_upload_prefilter` is ignored. Suggested fix:
	 *       `if (isset($uploadFileArray['error']) && $uploadFileArray['error'])`, which also
	 *       covers the string error that prefilter callbacks return.
	 *
	 * @hook filter wp_handle_upload_prefilter
	 * @return array{0: string, 1: string} Temporary file path and original file name. On
	 *                                      errors viewError() ends the request instead.
	 */
	protected function getUpload()
	{
		if (is_uploaded_file($_FILES["userfile"]["tmp_name"])) {
			Log::addDebug('Uploaded Files', $_FILES['userfile']);

			$uploadFileArray = $_FILES['userfile'];

			// New method for validating that the uploaded file is allowed, using WP:s internal wp_check_filetype_and_ext() function.
			$filedata = wp_check_filetype_and_ext($uploadFileArray["tmp_name"], $uploadFileArray["name"]);

			$uploadFileArray = apply_filters('wp_handle_upload_prefilter', $uploadFileArray); 

			Log::addDebug('Data after check', $filedata);
			if (isset($uploadFilearray['error']) && $uploadFileArray['userfile']['error'] > 0) {
				$this->viewError(self::ERROR_UPDATE_FAILED);
			}

			if ($filedata["ext"] == false && ! current_user_can('unfiltered_upload')) {
				$this->viewError(self::ERROR_SECURITY);
			}


			// Here we have the uploaded file
			$new_filename = $uploadFileArray["name"];
			$new_filetype = $filedata["type"] ? $filedata["type"] : $_FILES['userfile']['type'];

			return array($uploadFileArray["tmp_name"], $new_filename);
			// Execute hook actions - thanks rubious for the suggestion!
		}
		$this->viewError(self::ERROR_UPLOAD_FAILED);
	}
} // class
