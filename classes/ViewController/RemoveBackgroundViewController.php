<?php

namespace EnableMediaReplace\ViewController;

use EnableMediaReplace\Replacer\Libraries\Unserialize\Unserialize;


if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Controller\ReplaceController as ReplaceController;
use EnableMediaReplace\Api as Api;

class RemoveBackGroundViewController extends \EnableMediaReplace\ViewController
{
	static $instance;

	public function __construct()
	{
		parent::__construct();
	}

	public static function getInstance()
	{
		if (is_null(self::$instance))
			self::$instance = new RemoveBackgroundViewController();

		return self::$instance;
	}

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
	 	'save_backup' => false,
	 );
	 $settings = get_option('enable_media_replace', $defaults);
	 $settings = array_merge($defaults, $settings); // might miss some

	 $this->view->attachment = $attachment;
	 $this->view->settings = $settings;
	 $this->view->sourceFile = $file;

	 $this->loadView('prepare-remove-background');

	}

	// When the background has been posted - process.
	public function loadPost()
	{
			if ( ! isset( $_POST['emr_nonce'] )
		 || ! wp_verify_nonce( $_POST['emr_nonce'], 'media_remove_background' ))
		 {
			 $this->viewError(self::ERROR_NONCE);
		 }

		 $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : null;
		 if (is_null($key) || strlen($key) == 0)
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

		 // Persist the "save backup" preference so it's remembered for next time.
		 $save_backup = !empty($_POST['save_backup']);
		 $settings = get_option('enable_media_replace', array());
		 $settings['save_backup'] = $save_backup;
		 update_option('enable_media_replace', $settings, false);

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

		 // If the user opted to keep the original, duplicate the attachment
		 // before the replacement overwrites the source file.
		 if ($save_backup)
		 {
		 	$this->createBackup($post_id);
		 }

		 $result = $replaceController->run();
		 if (true == $result)
		 {
				$this->viewSuccess();
		 }

	}

	// Low init might only be w/ post_id ( error handling et al ), most advanced / nicer with params.
	protected function setView($post_id, $params = array())
	{
		 $uiHelper = \emr()->uiHelper();
		 $this->view->post_id = $post_id;
		 $this->view->postUrl = $uiHelper->getSuccesRedirect($post_id);
		 $this->view->emrUrl = $uiHelper->getFailedRedirect($post_id);

	}


	protected function replaceBackground($post_id, $key)
	{
		$api = new Api();
		$result = $api->handleDownload($key);

		return $result;
	}

	/**
	 * Duplicate the original attachment so the user keeps a copy in the
	 * Media Library before the background-removed version overwrites the source.
	 *
	 * @param int $post_id Original attachment ID.
	 * @return int|false New attachment ID on success, false on failure.
	 */
	protected function createBackup($post_id)
	{
		$source_file = get_attached_file($post_id);
		if (!$source_file || !file_exists($source_file)) {
			Log::addError('EMR backup: source file missing', $source_file);
			return false;
		}

		$dir = dirname($source_file);
		$filename = wp_basename($source_file);
		$pathinfo = pathinfo($filename);
		$extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
		$base = isset($pathinfo['filename']) ? $pathinfo['filename'] : $filename;

		$backup_filename = wp_unique_filename($dir, $base . '-backup' . $extension);
		$backup_path = trailingslashit($dir) . $backup_filename;

		if (!@copy($source_file, $backup_path)) {
			Log::addError('EMR backup: copy failed', array('from' => $source_file, 'to' => $backup_path));
			return false;
		}

		$original = get_post($post_id);
		$original_url = wp_get_attachment_url($post_id);
		$backup_url = $original_url ? trailingslashit(dirname($original_url)) . $backup_filename : '';

		$attachment_data = array(
			'guid'           => $backup_url,
			'post_mime_type' => $original->post_mime_type,
			'post_title'     => $original->post_title . ' ' . __('(backup)', 'enable-media-replace'),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$backup_id = wp_insert_attachment($attachment_data, $backup_path, 0, true);

		if (is_wp_error($backup_id) || !$backup_id) {
			@unlink($backup_path);
			Log::addError('EMR backup: wp_insert_attachment failed', is_wp_error($backup_id) ? $backup_id->get_error_message() : 'unknown');
			return false;
		}

		if (!function_exists('wp_generate_attachment_metadata')) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata($backup_id, $backup_path);
		wp_update_attachment_metadata($backup_id, $metadata);

		// Cross-reference so the relationship can be inspected later if needed.
		update_post_meta($backup_id, '_emr_backup_of', $post_id);
		update_post_meta($post_id, '_emr_backup_id', $backup_id);

		return $backup_id;
	}



} // class