<?php
/**
 * EMR-side WP Offload Media (Delicious Brains AS3CF) integration.
 *
 * @package EnableMediaReplace
 */
namespace EnableMediaReplace\Externals;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Notices\NoticeController as Notices;


/**
 * Makes replacements work for attachments offloaded by WP Offload Media.
 *
 * Singleton, loaded by Externals::loadWPOffload(). The constructor always
 * registers (whether or not WP Offload Media is installed):
 * - action `as3cf_init` -> init(), fired by WP Offload Media when it boots
 * - action `emr/converter/prevent-offload` -> preventOffload()
 * - action `emr/converter/prevent-offload-off` -> preventOffloadOff()
 * - filter `as3cf_pre_update_attachment_metadata` -> preventUpdateMetaData()
 *
 * The rest of the hooks are registered in init(), only when the installed
 * WP Offload Media version passes the compatibility checks:
 * - filter `shortpixel/image/urltopath` -> checkIfOffloaded(), only when
 *   ShortPixel Image Optimizer is not active (SPIO handles it otherwise)
 * - action `emr_after_remove_current` -> removeRemote()
 * - filter `emr/file/virtual/translate` -> getLocalPath()
 * - filter `emr/replace/file_is_movable` -> isFileMovable()
 * - filter `emr/replace/original_image_path` -> checkScaledUrl()
 * - action `enable-media-replace-upload-done` -> updateOriginalPath()
 */
class WPOffload
{
		/**
		 * Singleton instance.
		 *
		 * @var WPOffload|null
		 */
		private static $instance;

		/**
		 * The WP Offload Media main object passed to `as3cf_init`; null until init() passes the class check.
		 *
		 * @var object|null
		 */
		private $as3cf;

		/**
		 * Request-level cache of URL => attachment (source) id, filled through sourceCache().
		 *
		 * @var array<string,int>|null
		 */
		private $sources; // cache for url > source_id lookup, to prevent duplicate queries.

		/**
		 * Attachment ids for which offload metadata updates are suspended, keyed by id (value `true`).
		 *
		 * Static, so it is shared by every caller in the request.
		 *
		 * @var array<int,bool>
		 */
		private static $offloadPrevented = array();

		/**
		 * Unused.
		 *
		 * @var int|null
		 */
		private $post_id; // source_id.  The plugin has this, so why so tricky checks.


		/**
		 * Registers the hooks that do not depend on WP Offload Media being compatible.
		 *
		 * @hook action as3cf_init
		 * @hook action emr/converter/prevent-offload
		 * @hook action emr/converter/prevent-offload-off
		 * @hook filter as3cf_pre_update_attachment_metadata
		 */
		public function __construct()
		{
			add_action('as3cf_init', array($this, 'init'));

			add_action('emr/converter/prevent-offload', array($this, 'preventOffload'), 10);
			add_action('emr/converter/prevent-offload-off', array($this, 'preventOffloadOff'), 10);
			add_filter('as3cf_pre_update_attachment_metadata', array($this, 'preventUpdateMetaData'), 10,4);


		}

		/**
		 * Returns the singleton, creating it on first call.
		 *
		 * @return WPOffload
		 */
		public static function getInstance()
		{
				 if (is_null(self::$instance))
				 {
					  self::$instance = new WPOffload();
				 }

				 return self::$instance;
		}

		/**
		 * Checks WP Offload Media compatibility and registers the replace-time hooks.
		 *
		 * Bails out with a persistent warning notice when the
		 * `\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item` class is
		 * missing, or when $as3cf has no `get_item_handler()` method (in the
		 * latter case $as3cf is already stored). Loads
		 * wp-admin/includes/plugin.php when is_plugin_active() is not yet
		 * defined, and registers the `shortpixel/image/urltopath` filter only
		 * when ShortPixel Image Optimizer is not active.
		 *
		 * @hook action as3cf_init
		 * @hook filter shortpixel/image/urltopath
		 * @hook action emr_after_remove_current
		 * @hook filter emr/file/virtual/translate
		 * @hook filter emr/replace/file_is_movable
		 * @hook filter emr/replace/original_image_path
		 * @hook action enable-media-replace-upload-done
		 *
		 * @param object $as3cf WP Offload Media main plugin object.
		 * @return false|void False when the installed version is not compatible.
		 */
		public function init($as3cf)
		{
				if (! class_exists('\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item'))
				{
					Notices::addWarning(__('Your S3-Offload plugin version doesn\'t seem to be compatible. Please upgrade the S3-Offload plugin', 'enable-media-replace'), true);
					return false;
				}

				$this->as3cf = $as3cf;

				if (method_exists($as3cf, 'get_item_handler'))
				{
				}
				else {
					Notices::addWarning(__('Your S3-Offload plugin version doesn\'t seem to be compatible. Please upgrade the S3-Offload plugin', 'enable-media-replace'), true);
					return false;
				}

        // @todo This all is begging for the creating of an enviroment model / controller.
        if( !function_exists('is_plugin_active') ) {

    			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

    		}
        $spio_active = \is_plugin_active('shortpixel-image-optimiser/wp-shortpixel.php');

        // Let spio handle this.
        if (false === $spio_active)
        {
				      add_filter('shortpixel/image/urltopath', array($this, 'checkIfOffloaded'), 10,2);
        }

				add_action('emr_after_remove_current', array($this, 'removeRemote'), 10, 5);
				add_filter('emr/file/virtual/translate', array($this, 'getLocalPath'), 10, 3);
				add_filter('emr/replace/file_is_movable', array($this, 'isFileMovable'), 10, 2);

				add_filter('emr/replace/original_image_path', array($this, 'checkScaledUrl'), 10,2);

				add_action('enable-media-replace-upload-done', array($this, 'updateOriginalPath'), 10, 3);
		}

		/*
		* @param $post_id int  The post ID
		* @param $meta  array Old Metadata before remove
		* @param $backup_sizes array  WP Backup sizes
		* @param $sourceFile  Object Source File
		* @param $targetFile Object Target File
		*/
		/**
		 * Removes the offloaded copies of an attachment from the remote provider.
		 *
		 * Fired after EMR removes the current local files. Always removes when
		 * the attachment is offloaded, because the new thumbnails can differ
		 * from the old ones. Uses the `Remove_Provider_Handler` item handler
		 * with `verify_exists_on_local => false`; the handler result is not used.
		 *
		 * @hook action emr_after_remove_current
		 *
		 * @param int    $post_id      Attachment id.
		 * @param array  $meta         Attachment metadata before removal.
		 * @param array  $backup_sizes WordPress backup sizes.
		 * @param object $sourceFile   Source file object.
		 * @param object $targetFile   Target file object.
		 * @return false|void False when the attachment is not offloaded.
		 */
		public function removeRemote($post_id, $meta, $backup_sizes, $sourceFile, $targetFile )
		{
// Always remove because also thumbnails can be different.
					$a3cfItem = $this->getItemById($post_id); // MediaItem is AS3CF Object
					if ($a3cfItem === false)
					{
						Log::addDebug('S3-Offload MediaItem not remote - ' . $post_id);
						return false;
					}

						$remove = \DeliciousBrains\WP_Offload_Media\Items\Remove_Provider_Handler::get_item_handler_key_name();
						$itemHandler = $this->as3cf->get_item_handler($remove);

						$result = $itemHandler->handle($a3cfItem, array( 'verify_exists_on_local' => false)); //handle it then.

		}

		// @param s3 based URL that which is needed for finding local path
		// @return String Filepath.  Translated file path
		/**
		 * Translates the virtual (offloaded) path of a source file into its local upload path.
		 *
		 * Looks up the Offload item by $source_id, falling back to
		 * getSourceIDByURL($url). The local path is the upload base directory
		 * plus the item's `original_source_path()`. When the basename of $url
		 * differs from that path (for example a thumbnail URL), the basename of
		 * the original path is replaced with the basename of $url, so the
		 * result points to that file in the main file's directory.
		 *
		 * When the URL lookup returns an id for which getItemById() also
		 * returns false, $item stays false and the `original_source_path()`
		 * call fails.
		 *
		 * @hook filter emr/file/virtual/translate
		 *
		 * @todo getLocalPath() calls $item->original_source_path() without checking that $item is an object after the URL fallback; return false when $item is false.
		 *
		 * @param string $url           Virtual full path (remote URL form) of the source file.
		 * @param object $sourceFileObj Source file object (unused).
		 * @param int    $source_id     Attachment id.
		 * @return string|false Local file path, or false when no source id can be found.
		 */
		public function getLocalPath($url, $sourceFileObj, $source_id)
		{
			 $item = $this->getItemById($source_id);

			 if ($item === false)
			 {
			 	$source_id = $this->getSourceIDByURL($url);
				if (false !== $source_id)
					$item = $this->getItemById($source_id);
			 }

			 if ($source_id == false)
			 {
				Log::addError('Get Local Path: No source id for URL (Offload) ' . $url);
				return false;
			}

			 $original_path = $item->original_source_path(); // $values['original_source_path'];

			 if (wp_basename($url) !== wp_basename($original_path)) // thumbnails translate to main file.
			 {
					$original_path = str_replace(wp_basename($original_path), wp_basename($url), $original_path);
			 }

			 $fs = emr()->filesystem();
			 $base = $fs->getWPUploadBase();

			 $file  = $base . $original_path;
			 return $file;
		}



		/**
		 * Prevents moving offloaded attachments to a new location.
		 *
		 * @hook filter emr/replace/file_is_movable
		 *
		 * @todo isFileMovable() returns null (no return statement) when the item is neither false nor an object; add a final `return $bool;`.
		 *
		 * @param bool $bool      Whether the file may be moved.
		 * @param int  $attach_id Attachment id.
		 * @return bool|null $bool when not offloaded, false when offloaded, null otherwise.
		 */
		public function isFileMovable($bool, $attach_id)
		{
				$item = $this->getItemById($attach_id);
				if ($item === false)
				{
					 return $bool;
				}

				// Can't move offloaded items.
				if (is_object($item))
				{
					 return false;
				}

		}

		/**
		 * Tells the filesystem module whether a URL belongs to an offloaded attachment.
		 *
		 * Registered only when ShortPixel Image Optimizer is not active. Checks
		 * the source cache first; on a miss, URLs ending in `webp` or `avif`
		 * return false straight away (they are not in the Offload path table and
		 * can only be found through cache entries primed from an item's
		 * extra_info), other URLs go through getSourceIDByURL(). The incoming
		 * $bool is ignored.
		 *
		 * @hook filter shortpixel/image/urltopath
		 *
		 * @param bool   $bool Incoming value (ignored).
		 * @param string $url  URL to check.
		 * @return bool True when an attachment id is found for the URL.
		 */
		public function checkIfOffloaded($bool, $url)
		{

			$source_id = $this->sourceCache($url);

			if (false === $source_id)
			{
				$extension = substr($url, strrpos($url, '.') + 1);
				// If these filetypes are not in the cache, they cannot be found via geSourceyIDByUrl method ( not in path DB ), so it's pointless to try. If they are offloaded, at some point the extra-info might load.
				if ($extension == 'webp' || $extension == 'avif')
				{
					return false;
				}

				$source_id = $this->getSourceIDByURL($url);
			}

			if ($source_id !== false)
			{
				return true;
			}
			else
			{
				return false;
			}
		}

		// This is used in the converted. Might be deployed elsewhere for better control.
		/**
		 * Marks an attachment so preventUpdateMetaData() cancels Offload's metadata update.
		 *
		 * ReplaceController fires the action right before
		 * wp_generate_attachment_metadata().
		 *
		 * @hook action emr/converter/prevent-offload
		 *
		 * @param int $attach_id Attachment id.
		 * @return void
		 */
		public function preventOffload($attach_id)
		{
			 self::$offloadPrevented[$attach_id] = true;
		}

		/**
		 * Removes the mark set by preventOffload().
		 *
		 * ReplaceController fires the action right after
		 * wp_generate_attachment_metadata().
		 *
		 * @hook action emr/converter/prevent-offload-off
		 *
		 * @param int $attach_id Attachment id.
		 * @return void
		 */
		public function preventOffloadOff($attach_id)
		{
				unset(self::$offloadPrevented[$attach_id]);
		}

		/**
		 * Updates the Offload item's original paths after a replace changed the file name.
		 *
		 * Compares the basename of WordPress's original image path (passed
		 * through `emr/replace/original_image_path`, so `-scaled` is stripped by
		 * checkScaledUrl()) with the item's `original_path()`. When they differ,
		 * both `original_path` and `original_source_path` get the new basename
		 * and the item is saved. This also covers a `-scaled` main file being
		 * replaced by one without it.
		 *
		 * The action passes ($target_url, $source_url, $post_id), so the first
		 * two parameter names are swapped relative to the values; neither is
		 * used. $path, $source_path, $wp_source and $updated are computed but
		 * not used.
		 *
		 * @hook action enable-media-replace-upload-done
		 * @hook filter emr_unfiltered_get_attached_file
		 * @hook filter emr/replace/original_image_path
		 *
		 * @param string $source_url Receives the target URL (unused).
		 * @param string $target_url Receives the source URL (unused).
		 * @param int    $post_id    Attachment id.
		 * @return void
		 */
		public function updateOriginalPath($source_url, $target_url, $post_id)
		{
				$item = $this->getItemById($post_id);

				// If no item comes back, probably it's not offloaded
				if (false === $item)
				{
					 return;
				}

				$original_path = $item->original_path(); // Original path (non-scaled-)
				$original_source_path = $item->original_source_path();
				$path = $item->path();
				$source_path = $item->source_path();

				$wp_original = wp_get_original_image_path($post_id, apply_filters( 'emr_unfiltered_get_attached_file', true ));

				$wp_original = apply_filters('emr/replace/original_image_path', $wp_original, $post_id);
				$wp_source = trim(get_attached_file($post_id, apply_filters( 'emr_unfiltered_get_attached_file', true )));
				$updated = false;

				// If image is replaced with another name, the original soruce path will not match.  This could also happen when an image is with -scaled as main is replaced by an image that doesn't have it.  In all cases update the table to reflect proper changes.
				if (wp_basename($wp_original) !== wp_basename($original_path))
				{

					 $newpath = str_replace( wp_basename( $original_path ), wp_basename($wp_original), $original_path );

					 $item->set_original_path($newpath);

					 $newpath = str_replace( wp_basename( $original_source_path ), wp_basename($wp_original), $original_source_path );
					 $updated = true;

					 $item->set_original_source_path($newpath);

					 $item->save();

				}
		}

		// When Offload is not offloaded but is created during the process of generate metadata in WP, wp_create_image_subsizes fires an update metadata after just moving the upload, before making any thumbnails.  If this is the case and the file has an -scaled / original image setup, the original_source_path becomes the same as the source_path which creates issue later on when dealing with optimizing it, if the file is deleted on local server.  Prevent this, and lean on later update metadata.
		/**
		 * Cancels Offload's metadata update for attachments marked by preventOffload().
		 *
		 * @hook filter as3cf_pre_update_attachment_metadata
		 *
		 * @param bool   $bool                Whether to cancel the update.
		 * @param array  $data                Attachment metadata.
		 * @param int    $post_id             Attachment id.
		 * @param object $old_provider_object Previous provider item.
		 * @return bool True (cancel) when the attachment is marked, otherwise $bool.
		 */
		public function preventUpdateMetaData($bool, $data, $post_id, $old_provider_object)
		{
			if (isset(self::$offloadPrevented[$post_id]))
			{
					return true ; // return true to cancel.
			}

			return $bool;

		}



		// WP Offload -for some reason - returns the same result of get_attached_file and wp_get_original_image_path , which are different files (one scaled) which then causes a wrong copy action after optimizing the image ( wrong destination download of the remote file ).   This happens if offload with delete is on.  Attempt to fix the URL to reflect the differences between -scaled and not.
		/**
		 * Removes `-scaled` from the original image path.
		 *
		 * Removes every `-scaled` substring anywhere in the path, including in
		 * directory names or in a file name that contains `-scaled` for another
		 * reason (see the inline @todo).
		 *
		 * @hook filter emr/replace/original_image_path
		 *
		 * @param string|false $filepath Original image path.
		 * @param int          $id       Attachment id (unused).
		 * @return string|false Path without `-scaled`.
		 */
		public function checkScaledUrl($filepath, $id)
		{
				// Original filepath can never have a scaled in there.
				// @todo This should probably check -scaled.<extension> as string end preventing issues.
				if (strpos($filepath, '-scaled') !== false)
				{
					$filepath = str_replace('-scaled', '', $filepath);
				}
			 return $filepath;
		}

		/**
		 * Returns the Offload media item of an attachment.
		 *
		 * @param int  $id     Attachment id.
		 * @param bool $create Create the item from the attachment when none exists.
		 * @return object|false S3 Offload MediaItem, or false when this does not exist.
		 */
		protected function getItemById($id, $create = false)
		{
				$class = $this->getMediaClass();
				$mediaItem = $class::get_by_source_id($id);

				if (true === $create && $mediaItem === false)
				{
					 $mediaItem = $class::create_from_source_id($id);
				}

				return $mediaItem;
		}

		/**
		 * Finds the attachment id of an offloaded URL.
		 *
		 * Tries, in order, stopping at the first hit (each step checks the
		 * source cache before querying Offload):
		 * 1. the URL as given, prefixed with `http://` when it has no http(s)
		 *    scheme, through `get_item_source_by_remote_url()`;
		 * 2. the URL with a `-{width}x{height}` thumbnail suffix removed;
		 * 3. when the path holds more than one dot (double extension such as
		 *    `.jpg.webp`), the URL cut at its first dot plus the last extension.
		 * On a hit the URL (as modified by the steps) is cached, and when the
		 * item has `extra_info()`, every `objects[*].source_file` in it is
		 * cached under the same base URL, so later webp/avif lookups hit the
		 * cache.
		 *
		 * @todo Step 3 builds $checkurl but queries Offload with $url instead; pass $checkurl to get_item_source_by_remote_url().
		 * @todo Step 3 cuts the URL at its first dot, which is usually in the host name; cut the file name only (for example from the last `/`).
		 *
		 * @param string $url Remote URL.
		 * @return int|false Attachment id, or false when not found.
		 */
		protected function getSourceIDByURL($url)
    {
			$source_id = $this->sourceCache($url); // check cache first.

			if (false === $source_id) // check on the raw url.
			{
      	$class = $this->getMediaClass();

				$parsedUrl = parse_url($url);

				if (! isset($parsedUrl['scheme']) || ! in_array($parsedUrl['scheme'], array('http','https')))
				{
					 $url = 'http://' . $url; //str_replace($parsedUrl['scheme'], 'https', $url);
				}

      	$source = $class::get_item_source_by_remote_url($url);

				$source_id = isset($source['id']) ? intval($source['id']) : false;
			}

			if (false === $source_id) // check now via the thumbnail hocus.
			{
				$pattern = '/(.*)-\d+[xX]\d+(\.\w+)/m';
				$url = preg_replace($pattern, '$1$2', $url);

				$source_id = $this->sourceCache($url); // check cache first.

				if (false === $source_id)
				{
					$source = $class::get_item_source_by_remote_url($url);
					$source_id = isset($source['id']) ? intval($source['id']) : false;
				}

      }

			// Check issue with double extensions. If say double webp/avif is on, the double extension causes the URL not to be found (ie .jpg)
			if (false === $source_id)
			{
				 if (substr_count($parsedUrl['path'], '.') > 1)
				 {
					  // Get extension
						$ext = substr(strrchr($url, '.'), 1);

						// Remove all extensions from the URL
					  $checkurl = substr($url, 0, strpos($url,'.')) ;

						// Add back the last one.
						$checkurl .= '.' . $ext;

						// Retry
						$source_id = $this->sourceCache($checkurl); // check cache first.

						if (false === $source_id)
						{
							$source = $class::get_item_source_by_remote_url($url);
							$source_id = isset($source['id']) ? intval($source['id']) : false;
						}


				 }
			}

			if ($source_id !== false)
			{

				$this->sourceCache($url, $source_id);  // cache it.

				// get item
				$item = $this->getItemById($source_id);
				if (is_object($item) && method_exists($item, 'extra_info'))
				{
					$baseUrl = str_replace(basename($url),'', $url);
					$extra_info = $item->extra_info();

					if (isset($extra_info['objects']))
					{
						foreach($extra_info['objects'] as $extraItem)
						{
							 if (is_array($extraItem) && isset($extraItem['source_file']))
							 {
								 // Add source stuff into cache.
								  $this->sourceCache($baseUrl . $extraItem['source_file'], $source_id);
							 }
						}
					}
				}

				return $source_id;
			}

      return false;
    }

		/**
		 * Gets or sets an entry in the URL => attachment id cache.
		 *
		 * Without $source_id it is a getter: returns the cached id or false.
		 * With $source_id it is a setter: stores the id only when the URL is not
		 * cached yet (existing entries are never overwritten) and returns the
		 * given $source_id either way.
		 *
		 * @param string   $url       URL key.
		 * @param int|null $source_id Attachment id to store, or null to read.
		 * @return int|false Cached or given id, or false on a read miss.
		 */
		private function sourceCache($url, $source_id = null)
		{
			if ($source_id === null && isset($this->sources[$url]))
			{
				$source_id = $this->sources[$url];
				return $source_id;
			}
			elseif ($source_id !== null)
			{
				 if (! isset($this->sources[$url]))
				 {
					  $this->sources[$url]  = $source_id;
				 }
				 return $source_id;
			}

			return false;
		}


		/**
		 * Returns the Offload source-type class name for the media library.
		 *
		 * @return string Class name (Media_Library_Item or a subclass).
		 */
		private function getMediaClass()
		{
			$class = $this->as3cf->get_source_type_class('media-library');
			return $class;
		}

}
