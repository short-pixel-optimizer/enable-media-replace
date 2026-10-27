<?php
/**
 * Base class for the EMR admin screens.
 *
 * @package EnableMediaReplace
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace as emr;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


/**
 * Abstract base for the screen controllers: view loading and the error / success screens.
 *
 * Subclasses (ReplaceViewController, UploadViewController, RemoveBackGroundViewController)
 * are singletons through their own getInstance() and are called from
 * EnableMediaReplacePlugin::route(). No hooks are registered here.
 *
 * Error codes, turned into messages by viewError():
 *
 * | Code | Constant                 | Meaning                                              |
 * |------|--------------------------|------------------------------------------------------|
 * | 1    | ERROR_UPLOAD_PERMISSION  | user lacks `upload_files`                            |
 * | 2    | ERROR_IMAGE_PERMISSION   | checkImagePermission() fails, or author restricted   |
 * | 3    | ERROR_FORM               | form field (attachment ID) missing                   |
 * | 4    | ERROR_TIME               | custom date cannot be parsed                         |
 * | 5    | ERROR_UPDATE_FAILED      | upload array reports an error (see UploadViewController::getUpload()) |
 * | 6    | ERROR_SECURITY           | file type/extension not allowed                      |
 * | 7    | ERROR_UPLOAD_FAILED      | no uploaded file                                     |
 * | 8    | ERROR_NONCE              | nonce missing or invalid                             |
 * | 9    | ERROR_KEY                | remove-background download key missing               |
 * | 20-25| ERROR_TARGET_EXISTS ... ERROR_DIRECTORY_NOTEXIST | same values as ReplaceController's constants |
 * | 31   | ERROR_DOWNLOAD_FAILED    | remove-background result could not be downloaded     |
 *
 * Any other code (including null) shows "An unknown error has occured".
 */
abstract class ViewController
{

	  /**
	   * Handles the request for the screen. Implemented by each subclass.
	   *
	   * @return void
	   */
	  abstract function load();


	 /** @var int User lacks the `upload_files` capability. */
	 const ERROR_UPLOAD_PERMISSION = 1;
	 /** @var int User may not edit this attachment (checkImagePermission() or author restriction). */
	 const ERROR_IMAGE_PERMISSION = 2;
	 /** @var int Required form field missing. */
	 const ERROR_FORM = 3;
	 /** @var int Custom date/time cannot be parsed. */
	 const ERROR_TIME = 4;
	 /** @var int The upload reports an error. */
	 const ERROR_UPDATE_FAILED = 5;
	 /** @var int File type or extension rejected by wp_check_filetype_and_ext(). */
	 const ERROR_SECURITY = 6;
	 /** @var int No uploaded file in the request. */
	 const ERROR_UPLOAD_FAILED = 7;
	 /** @var int Nonce missing or invalid. */
	 const ERROR_NONCE = 8;
	 /** @var int Download key missing when replacing backgrounds. */
	 const ERROR_KEY = 9; // Missing key when replacing backgrounds.

	 // These synced with ReplaceController
	 /** @var int See ReplaceController::ERROR_TARGET_EXISTS. */
	 const ERROR_TARGET_EXISTS = 20;
	 /** @var int See ReplaceController::ERROR_DESTINATION_FAIL. */
	 const ERROR_DESTINATION_FAIL = 21;
	 /** @var int See ReplaceController::ERROR_COPY_FAILED. */
	 const ERROR_COPY_FAILED = 22;
	 /** @var int See ReplaceController::ERROR_UPDATE_POST. */
	 const ERROR_UPDATE_POST = 23;
	 /** @var int See ReplaceController::ERROR_DIRECTORY_SECURITY. */
	 const ERROR_DIRECTORY_SECURITY = 24;
	 /** @var int See ReplaceController::ERROR_DIRECTORY_NOTEXIST. */
	 const ERROR_DIRECTORY_NOTEXIST = 25;

	 // Remove Background
	 /** @var int The remove-background result could not be downloaded or is missing. */
	 const ERROR_DOWNLOAD_FAILED = 31;

		/**
		 * Template names already included in this request. Shared by all subclasses
		 * (static on the base class), used by loadView() to load a template only once.
		 *
		 * @var string[]
		 */
		protected static $viewsLoaded = array();

		/**
		 * Data object for the templates; available there as `$view`.
		 *
		 * @var \stdClass
		 */
		protected $view; // object to use in the view.

		/**
		 * URL of the page this controller serves, for redirects. Not set or read by the
		 * current controllers.
		 *
		 * @var string|null
		 */
	  protected $url; // if controller is home to a page, sets the URL here. For redirects and what not.


		/**
		 * Initialises the empty view data object.
		 */
		public function __construct()
		{
			 $this->view = new \stdClass;
		}

		/**
		 * Includes `views/<template>.php` with `$view` (the view data) and `$controller`
		 * (this object) in scope.
		 *
		 * With $unique true (default), a template already loaded in this request (by any
		 * controller) is skipped. A missing template file is logged as an error and nothing
		 * is included.
		 *
		 * @param string|null $template Template name without directory and `.php`.
		 * @param bool        $unique   Whether to skip templates that are already loaded.
		 * @return false|void False when the template name is null or empty; nothing otherwise.
		 */
		protected function loadView($template = null, $unique = true)
		{
				if (is_null($template) )
				{
					return false;
				}
				elseif (strlen(trim($template)) == 0)
				{
					return false;
				}

				$view = $this->view;
				$controller = $this;
				$template_path = emr()->plugin_path('views/' . $template  . '.php');

				if (file_exists($template_path) === false)
				{
					Log::addError("View $template could not be found in " . $template_path,
					array('class' => get_class($this)));
				}
				elseif ($unique === false || ! in_array($template, self::$viewsLoaded))
				{
					include($template_path);
					self::$viewsLoaded[] = $template;
				}
		}

		/**
		 * Shows the error screen for an error code and ends the request.
		 *
		 * Sets `$view->errorMessage` (always) and `$view->errorDescription` (for
		 * ERROR_TARGET_EXISTS, ERROR_NONCE, and ERROR_DIRECTORY_SECURITY when $errorData has
		 * both `path` and `basedir`), loads `views/error.php` and calls exit(). Any
		 * errorMessage set before the call is overwritten. Unknown codes get a generic
		 * message.
		 *
		 * @todo The ERROR_DIRECTORY_SECURITY description ("Path: %s is not within basedir
		 *       reported as: %s") uses the 'shortpixel-image-optimiser' text domain, so it is
		 *       never translated through EMR's files. Suggested fix: use 'enable-media-replace'.
		 *
		 * @param int|null $errorCode One of the ERROR_* constants.
		 * @param array    $errorData Optional extra data; `path` and `basedir` are used for
		 *                            ERROR_DIRECTORY_SECURITY.
		 * @return never Calls exit().
		 */
		protected function viewError($errorCode, $errorData = array())
		{
			 $message = $description = false;
			 switch($errorCode)
			 {
					case self::ERROR_UPLOAD_PERMISSION:
					 $message = __('You don\'t have permission to upload images. Please refer to your administrator', 'enable-media-replace');
					break;
					case self::ERROR_IMAGE_PERMISSION:
					 $message = __('You don\'t have permission to edit this image', 'enable-media-replace');
					break;
					case self::ERROR_FORM:
					 $message = __('The form submitted is missing various fields', 'enable-media-replace');
					break;
					case self::ERROR_TIME:
					 $message = __('The custom time format submitted is invalid', 'enable-media-replace');
					break;
					case self::ERROR_UPDATE_FAILED:
					 $message = __('Updating the WordPress attachment failed', 'enable-media-replace');
					break;
					case self::ERROR_SECURITY:
					 $message = __('The file upload has been rejected for security reason. WordPress might not allow uploading this extension or filetype', 'enable-media-replace');
					break;
					case self::ERROR_UPLOAD_FAILED:
					 $message = __('The upload from your browser seem to have failed', 'enable-media-replace');
					break;
					case self::ERROR_TARGET_EXISTS:
					 $message = __('The target file already exists in this directory. Please try another name / directory', 'enable-media-replace');
					 $description = __('This error is shown because you try to move the image to another folder, which already has this file', 'enable-media-replace');
					break;
					case self::ERROR_DESTINATION_FAIL:
					 $message = __('Something went wrong while writing the file or directory', 'enable-media-replace');
					break;
					case self::ERROR_COPY_FAILED:
					 $message = __('Copying replacement file to destination failed', 'enable-media-replace');
					break;
					case self::ERROR_UPDATE_POST:
						$message = __('Error updating WordPress post in the database', 'enable-media-replace');
					break;
					case self::ERROR_DIRECTORY_SECURITY:
						$message = __('Specified directory is outside the upload directory. This is not allowed for security reasons', 'enable-media-replace');
						$path = isset($errorData['path']) ? $errorData['path'] : false;
						$basedir = isset($errorData['basedir']) ? $errorData['basedir'] : false;

						if ($path !== false && $basedir !== false)
						{
							 $description  = sprintf(__('Path: %s is not within basedir reported as: %s', 'shortpixel-image-optimiser'), $path, $basedir);
						}
					break;
					case self::ERROR_DIRECTORY_NOTEXIST:
						$message = __('Specified new directory does not exist. Path must be a relative path from the upload directory and exist', 'enable-media-replace');
					break;

					case self::ERROR_NONCE:
					 $message = __('Fail to validate form nonce. Please try again', 'enable-media-replace');
					 $description = __('This can happen when the window is open for a long time and/or there has been a timeout.  You can go back to previous screen and try again. If this happens each time when replacing, contact us', 'enable-media-replace');
					break;

					// Remove Background
					case self::ERROR_DOWNLOAD_FAILED:
						$message = __('Replacement Image could not be downloaded or does not exist', 'enable-media-replace');
					break;

					default:
					 $message = __('An unknown error has occured', 'enable-media-replace');
					break;
			 }

			 if( false !== $message)
			 	$this->view->errorMessage = $message;


			 if (false !== $description)
			 {
				  $this->view->errorDescription = $description;
			 }


			 $this->loadView('error');
			 exit();
		}


		/**
		 * Shows the success screen and ends the request.
		 *
		 * Enqueues the `emr_success` script (countdown redirect to `$view->postUrl`), loads
		 * `views/success.php` and calls exit().
		 *
		 * @return never Calls exit().
		 */
		protected function viewSuccess()
		{
			 wp_enqueue_script('emr_success');
			 $this->loadView('success');
			 exit();
		}

}
