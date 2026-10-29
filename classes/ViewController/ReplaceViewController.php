<?php
/**
 * The replace screen.
 *
 * @package EnableMediaReplace\ViewController
 */
namespace EnableMediaReplace\ViewController;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace as emr;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Controller\ReplaceController as ReplaceController;

/**
 * Renders the replace form (`views/screen.php`) for `action=media_replace`.
 *
 * Lifecycle: singleton via getInstance(), called from EnableMediaReplacePlugin::route()
 * when `attachment_id` is a positive integer. Registers no hooks.
 */
class ReplaceViewController extends \EnableMediaReplace\ViewController
{
	 /**
	  * The singleton instance.
	  *
	  * @var ReplaceViewController|null
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
	  * @return ReplaceViewController
	  */
	 public static function getInstance()
	 {
		 	if (is_null(self::$instance))
		 		self::$instance = new ReplaceViewController();

			return self::$instance;
	 }

	 /**
	  * Prepares the view data and loads the replace screen.
	  *
	  * Shows ERROR_IMAGE_PERMISSION (which exits, so the wp_die() after it is unreachable)
	  * when checkImagePermission() fails. Sets the preview and source sizes on UIHelper and
	  * fills `$view` with:
	  * - `attachment` (WP_Post), `sourceFile` (untranslated source file, for the preview),
	  *   `sourceMime`;
	  * - `settings`: the `enable_media_replace` option merged over the defaults
	  *   (`replace_type` 'replace', `timestamp_replace` TIME_UPDATEMODIFIED, `custom_date`
	  *   now in server time via date(), `new_location` false, `new_location_dir` false);
	  * - `is_author_restricted`: when true, `settings['replace_type']` is forced to
	  *   'replace' because search-replace is not offered to these users;
	  * - `settings['replace_type']` then passes through `emr/replace/default_type`, which
	  *   runs after the author restriction and can override it;
	  * - `is_movable`: `emr/replace/file_is_movable` (default true; false for offloaded files);
	  * - `custom_basedir`: the uploads basedir with trailing slash.
	  *
	  * @hook filter emr/replace/default_type
	  * @hook filter emr/replace/file_is_movable
	  * @uses ReplaceController::TIME_UPDATEMODIFIED
	  * @return void
	  */
	 public function load()
	 {
		 	$attachment_id = intval($_GET['attachment_id']);
			$attachment = get_post($attachment_id);

			if (! \emr()->checkImagePermission($attachment))
			{
			  $this->viewError(self::ERROR_IMAGE_PERMISSION);
			  wp_die( esc_html__('You do not have permission to upload files for this author.', 'enable-media-replace') );
			}

			$replacer = new ReplaceController($attachment_id);

			$file = $replacer->getSourceFile(true);
		/*	$filepath = $file->getFullPath();
			$filename = $file->getFileName();
			$filetype = $file->getExtension(); */
			$source_mime = get_post_mime_type($attachment_id);

			$uiHelper = \emr()->uiHelper();
			$uiHelper->setPreviewSizes();
			$uiHelper->setSourceSizes($attachment_id);

			$defaults = array(
			  'replace_type' => 'replace',
			  'timestamp_replace' => ReplaceController::TIME_UPDATEMODIFIED,
			  'custom_date' => date("Y-m-d H:i:s"),
			  'new_location' => false,
			  'new_location_dir' => false,
			);
			$settings = get_option('enable_media_replace', $defaults);	

			$this->view->attachment = $attachment;
			$this->view->sourceFile = $file;
			$this->view->sourceMime = $source_mime;
			$this->view->settings = array_merge($defaults, $settings); // might miss some

			$this->view->is_author_restricted = $uiHelper->is_author_restricted(); 


			// Override settings here if this happens, because the other option could never show up / be used.
			if (true === $this->view->is_author_restricted)
			{
				$this->view->settings['replace_type'] = 'replace'; 
			}
			/**
			 * Filters the default replace_type ('replace' or 'replace_and_search')
			 * shown on the media replace screen. Overrides whatever was last
			 * remembered in the stored option.
			 *
			 * @param string $replace_type   Current default ('replace' or 'replace_and_search').
			 * @param int    $attachment_id  The attachment being replaced.
			 */
			$this->view->settings['replace_type'] = apply_filters(
				'emr/replace/default_type',
				$this->view->settings['replace_type'],
				$attachment_id
			);

			// Indicates if file can be moved to other location. Can't be done when offloaded.
			$this->view->is_movable = apply_filters('emr/replace/file_is_movable', true, $attachment_id);

			$uploadDir = wp_upload_dir();
		 	$basedir = trailingslashit($uploadDir['basedir']);

			$this->view->custom_basedir = $basedir;

			$this->loadView('screen');
	 }

}