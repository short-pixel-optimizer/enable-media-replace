<?php
namespace EnableMediaReplace\ViewController;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use function EnableMediaReplace\EMR as EMR;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Controller\ReplaceController as ReplaceController;

class ReplaceViewController extends \EnableMediaReplace\ViewController
{
	 static $instance;

	 public function __construct()
	 {
		 parent::__construct();
	 }

	 public static function getInstance()
	 {
		 	if (is_null(self::$instance))
		 		self::$instance = new static();

			return self::$instance;
	 }

	 public function load()
	 {
		 	$attachment_id = intval($_GET['attachment_id']);

      $imageClass = emr()->getClass('image');
      $image = new $imageClass($attachment_id);

      // Perhaps this move to an image Class with all data?
			if (false === $image->hasImagePermission())
			{
				$this->viewError(self::ERROR_IMAGE_PERMISSION);
			  wp_die( esc_html__('You do not have permission to upload files for this author.', 'enable-media-replace') );
			}

      $replaceClass = emr()->getClass('replaceController');
			$replacer = new $replaceClass($image);

			//$file = $replacer->getSourceFile(true);
			//$source_mime = get_post_mime_type($attachment_id);

			$uiHelper = EMR()->uiHelper();
			$uiHelper->setPreviewSizes();
			$uiHelper->setSourceSizes($attachment_id);

			$defaults = array(
			  'replace_type' => 'replace',
			  'timestamp_replace' => ReplaceController::TIME_UPDATEMODIFIED,
			  'custom_date' => date("Y-m-d H:i:s"),
			  'new_location' => false,
			  'new_location_dir' => false,
        'keep_title' => 0,
			);
			$settings = get_option('enable_media_replace', $defaults);

		//	$this->view->attachment = $attachment;
		  $this->view->image = $image;
		//	$this->view->sourceFile = $file;
			$this->view->sourceMime = $image->getMime();
			$this->view->settings = array_merge($defaults, $settings); // might miss some

			// Indicates if file can be moved to other location. Can't be done when offloaded.
			$is_movable = ($image->is_virtual()) ? false : true;
			$this->view->is_movable = apply_filters('emr/replace/file_is_movable', $is_movable, $attachment_id);

			$uploadDir = wp_upload_dir();
		 	$basedir = trailingslashit($uploadDir['basedir']);

			$this->view->custom_basedir = $basedir;

			$this->loadView('screen');

	 }

}
