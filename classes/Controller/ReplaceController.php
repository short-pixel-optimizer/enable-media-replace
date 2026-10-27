<?php
/**
 * The replace flow: replaces the file of one attachment with an uploaded file.
 *
 * @package EnableMediaReplace\Controller
 */

namespace EnableMediaReplace\Controller;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Replacer\Replacer as Replacer;
use EnableMediaReplace\emrCache as emrCache;

/**
 * Replaces the file behind one attachment and updates everything that points to it.
 *
 * Lifecycle: not a singleton. A new instance is created with `new ReplaceController($post_id)`
 * for every attachment and request (by UploadViewController::load(),
 * RemoveBackGroundViewController::load() and loadPost(), ReplaceViewController::load()
 * and Api::request()). The constructor resolves the source file immediately
 * (setupSource()); setupParams() resolves the target; run() performs the replacement.
 * The class registers no hooks itself; it fires the actions and filters listed on run()
 * and the other methods.
 *
 * Two replace modes:
 * - MODE_REPLACE (1): the uploaded file overwrites the current file at the same path and
 *   name. Only the thumbnail URLs are search-replaced (`thumbnails_only`).
 * - MODE_SEARCHREPLACE (2): the uploaded file keeps its own (uniquified) name and may be
 *   placed in another directory under uploads; the post title, slug, excerpt and GUID are
 *   updated, and all URLs of the old file and its sizes are search-replaced in the database.
 *
 * Three time modes (see updateDate()):
 * - TIME_UPDATEALL (1): post date and modified date are set to the new date (now).
 * - TIME_UPDATEMODIFIED (2): post date is kept, only the modified date is set (now).
 * - TIME_CUSTOM (3): post date and modified date are set to a user-supplied date.
 *
 * Error codes (20-25) are stored in $lastError and read by the view controllers through
 * returnLastError(). They share their values with the ERROR_* constants of
 * \EnableMediaReplace\ViewController, which turns them into messages.
 */
class ReplaceController
{
	/**
	 * Attachment (post) ID being replaced.
	 *
	 * @var int
	 */
	protected $post_id;

	/**
	 * The file being replaced, as a local path. For offloaded (virtual) files this is the
	 * translated local path when the `emr/file/virtual/translate` filter provides one.
	 *
	 * @var \EnableMediaReplace\FileSystem\Model\File\FileModel
	 */
	protected $sourceFile;

	/**
	 * The source file before virtual-path translation; only set when the source is virtual
	 * (offloaded). Used for previews. Null otherwise.
	 *
	 * @var \EnableMediaReplace\FileSystem\Model\File\FileModel|null
	 */
	protected $sourceFileUntranslated;

	/**
	 * Full path (as file object) where the new file is written. Set by setupParams() and
	 * replaced in run() when the `wp_handle_upload` filter returns another file.
	 *
	 * @var \EnableMediaReplace\FileSystem\Model\File\FileModel|null
	 */
	protected $targetFile;

	/**
	 * Replace mode: overwrite the current file in place, update thumbnail URLs only.
	 *
	 * @var int
	 */
	const MODE_REPLACE = 1;

	/**
	 * Replace mode: new file name and optional new location, full search and replace of URLs.
	 *
	 * @var int
	 */
	const MODE_SEARCHREPLACE = 2;

	/**
	 * Time mode: set post date and modified date to the new date.
	 *
	 * @var int
	 */
	const TIME_UPDATEALL = 1; // replace the date

	/**
	 * Time mode: keep the post date, set only the modified date.
	 *
	 * @var int
	 */
	const TIME_UPDATEMODIFIED = 2; // keep the date, update only modified

	/**
	 * Time mode: set post date and modified date to a custom, user-entered date.
	 *
	 * @var int
	 */
	const TIME_CUSTOM = 3; // custom time entry

	/**
	 * Error: in search-replace mode with a new location, a different file with the target
	 * name already exists there.
	 *
	 * @var int
	 */
	const ERROR_TARGET_EXISTS = 20;

	/**
	 * Error: the computed target is a directory and wp_upload_dir() gives no fallback path.
	 *
	 * @var int
	 */
	const ERROR_DESTINATION_FAIL = 21;

	/**
	 * Error: copying the uploaded file to the target failed and the target does not exist.
	 * Set inside run(), which still continues and returns true.
	 *
	 * @var int
	 */
	const ERROR_COPY_FAILED = 22;

	/**
	 * Error: wp_update_post() returns a WP_Error in search-replace mode. Set inside run(),
	 * which still continues and returns true.
	 *
	 * @var int
	 */
	const ERROR_UPDATE_POST = 23;

	/**
	 * Error: the requested new location resolves outside the uploads base directory, or
	 * (on the main site of a multisite) into its `sites/` subdirectory. $lastErrorData then
	 * holds `path` and `basedir`.
	 *
	 * @var int
	 */
	const ERROR_DIRECTORY_SECURITY = 24;

	/**
	 * Error: the requested new location does not exist. Defined for the view's message
	 * table; nothing in this class sets it (the check in getNewTargetLocation() is
	 * commented out, the directory is created by run() instead).
	 *
	 * @var int
	 */
	const ERROR_DIRECTORY_NOTEXIST = 25;

	/**
	 * MODE_REPLACE or MODE_SEARCHREPLACE, set by setupParams(). Null before that.
	 *
	 * @var int|null
	 */
	protected $replaceType;
	/**
	 * New location relative to the uploads base directory. Only set in search-replace mode
	 * when the form asks for a new location and a directory is given. Null otherwise.
	 *
	 * @var string
	 */
	protected $new_location;

	/**
	 * TIME_UPDATEALL, TIME_UPDATEMODIFIED or TIME_CUSTOM, as passed in setupParams().
	 *
	 * @var int
	 */
	protected $timeMode;

	/**
	 * The new date in MySQL format (site local time), used by updateDate().
	 *
	 * @var string
	 */
	protected $newDate;

	/**
	 * Original file name of the upload (as sent by the browser, not sanitized).
	 *
	 * @var string
	 */
	protected $new_filename;

	/**
	 * Path to the uploaded temporary file that run() copies to the target and then deletes.
	 *
	 * @var string
	 */
	protected $tmpUploadPath;

	/**
	 * Last error code (one of the ERROR_* constants), or null when none.
	 *
	 * @var int|null
	 */
	protected $lastError;

	/**
	 * Optional extra data for the last error, e.g. `array('path' => ..., 'basedir' => ...)`
	 * for ERROR_DIRECTORY_SECURITY. Null when none.
	 *
	 * @var array|null
	 */
	protected $lastErrorData; // optional extra data for last error.

	/**
	 * Creates the controller for one attachment and resolves its source file.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function __construct($post_id)
	{
		$this->post_id = $post_id;
		$this->setupSource();
	}

	/**
	 * Returns the file being replaced.
	 *
	 * @param bool $untranslated When true and the file is offloaded (virtual), returns the
	 *                           untranslated remote variant. Used for displaying the preview.
	 * @return \EnableMediaReplace\FileSystem\Model\File\FileModel The source file object.
	 */
	public function getSourceFile($untranslated = false)
	{

		if (true === $untranslated && ! is_null($this->sourceFileUntranslated)) {
			return $this->sourceFileUntranslated;
		}
		return $this->sourceFile;
	}

	/**
	 * Stores the request parameters and resolves the target file.
	 *
	 * `replace_type` 'replace_and_search' selects MODE_SEARCHREPLACE; any other value
	 * (including an integer or false) selects MODE_REPLACE. The new location is only taken
	 * in search-replace mode, when `new_location` is exactly true and `location_dir` is
	 * not null.
	 *
	 * @param array $params {
	 *     @type string|int|false $replace_type      'replace' or 'replace_and_search'.
	 *     @type bool             $new_location      Whether to move the file (search-replace only).
	 *     @type string|null      $location_dir      New directory relative to uploads basedir.
	 *     @type int              $timestamp_replace One of the TIME_* constants.
	 *     @type string           $new_date          MySQL date for updateDate().
	 *     @type string           $new_filename      Original name of the uploaded file.
	 *     @type string           $uploadFile        Path of the temporary uploaded file.
	 * }
	 * @return bool True when a target was resolved; false when setupTarget() fails (the
	 *              error is then available via returnLastError() / returnLastErrorData()).
	 */
	public function setupParams($params)
	{
		$this->replaceType = ($params['replace_type'] === 'replace_and_search') ? self::MODE_SEARCHREPLACE : self::MODE_REPLACE;

		if ($this->replaceType == self::MODE_SEARCHREPLACE && true === $params['new_location'] && ! is_null($params['location_dir'])) {
			$this->new_location = $params['location_dir'];
		}

		$this->timeMode = $params['timestamp_replace'];
		$this->newDate = $params['new_date'];

		$this->new_filename = $params['new_filename'];
		$this->tmpUploadPath = $params['uploadFile'];

		$targetFile = $this->setupTarget();
		if (is_null($targetFile)) {
			return false;
		}
		$this->targetFile = $this->fs()->getFile($targetFile);

		return true;
	}

	/**
	 * Returns the last error code.
	 *
	 * @return int|null One of the ERROR_* constants, or null when no error is recorded.
	 */
	public function returnLastError()
	{
		return $this->lastError;
	}

	/**
	 * Returns the extra data recorded with the last error.
	 *
	 * @return array The data (e.g. `path` and `basedir` for ERROR_DIRECTORY_SECURITY), or an
	 *               empty array when none.
	 */
	public function returnLastErrorData()
	{
		if (! is_null($this->lastErrorData))
			return $this->lastErrorData;
		else {
			return array();
		}
	}

	/**
	 * Performs the replacement. Must be called after a successful setupParams().
	 *
	 * Sequence, in order:
	 * 1. Fires the `wp_handle_replace` action with `array('post_id' => $post_id)`.
	 * 2. Creates a Replacer and sets the source URL (getSourceUrl()) and the current
	 *    attachment metadata as source metadata.
	 * 3. Checks (and creates when missing) the target directory; a failure is only logged.
	 * 4. Snapshots the permissions of the source file (-1 when it does not exist).
	 * 5. removeCurrent(): deletes the current file, its sizes and backups from disk. In
	 *    MODE_REPLACE the source and target paths are the same, so a failed copy in the next
	 *    step leaves the attachment without a file.
	 * 6. Copies the temporary upload to the target. On failure, ERROR_COPY_FAILED is set
	 *    only when the target does not exist; execution continues either way.
	 * 7. Deletes the temporary upload (a failure is logged), resets the target status and
	 *    restores the permission snapshot with chmod() when it is > 0.
	 * 8. update_attached_file() with the target's raw (not normalized) full path, because
	 *    update_attached_file does not normalize either.
	 * 9. Applies the core `wp_handle_upload` filter with context 'sideload'; when the
	 *    returned `file` differs, the attached file is updated again and that file becomes
	 *    the target.
	 * 10. Sets the Replacer target URL (getTargetURL()).
	 * 11. Updates the post MIME type when it differs from the target's non-empty MIME type.
	 * 12. Brackets wp_generate_attachment_metadata() with the `emr/converter/prevent-offload`
	 *     and `emr/converter/prevent-offload-off` actions, then saves the new metadata and
	 *     sets it as the Replacer target metadata.
	 * 13. `_emr_replace_author` post meta: set to the current user when the post author is
	 *     someone else; deleted when the current user is the author and the meta exists.
	 * 14. MODE_SEARCHREPLACE only: updates post_title, post_name (sanitize_title of the
	 *     title), post_excerpt (when the image caption is non-empty) and guid via
	 *     wp_update_post(), then writes the guid again with a direct $wpdb->update() because
	 *     wp_update_post() does not change the guid of an existing post. A WP_Error sets
	 *     ERROR_UPDATE_POST.
	 * 15. Applies `emr/replace/doreplace` (default true); when it returns exactly true,
	 *     runs Replacer::replace() with `thumbnails_only` true in MODE_REPLACE and false in
	 *     MODE_SEARCHREPLACE.
	 * 16. updateDate().
	 * 17. Flushes caches through emrCache::flushCache() in 'post' mode with the post ID and
	 *     both URLs.
	 * 18. Fires `enable-media-replace-upload-done` with ($target_url, $source_url, $post_id).
	 *
	 * @todo run() returns true even when ERROR_COPY_FAILED or ERROR_UPDATE_POST is set in
	 *       steps 6 and 14, so callers that only check the return value show the success
	 *       screen. Suggested fix: return false (or check $this->lastError) after those
	 *       errors so the view controllers call viewError().
	 *
	 * @global \wpdb $wpdb Used for the direct guid update.
	 * @hook action wp_handle_replace
	 * @hook filter wp_handle_upload
	 * @hook action emr/converter/prevent-offload
	 * @hook action emr/converter/prevent-offload-off
	 * @hook filter emr/replace/doreplace
	 * @hook action enable-media-replace-upload-done
	 * @uses self::MODE_SEARCHREPLACE
	 * @uses self::ERROR_COPY_FAILED
	 * @uses self::ERROR_UPDATE_POST
	 * @return bool Always true.
	 */
	public function run()
	{
		do_action('wp_handle_replace', array('post_id' => $this->post_id));

		// Set Source / and Source Metadata
		$Replacer = new Replacer();
		$source_url = $this->getSourceUrl();
		$Replacer->setSource($source_url);
		$Replacer->setSourceMeta(wp_get_attachment_metadata($this->post_id));

		$targetFileObj = $this->fs()->getFile($this->targetFile);
		$directoryObj = $targetFileObj->getFileDir();

		$result = $directoryObj->check();

		if ($result === false) {
			Log::addError('Directory creation for targetFile failed');
		}

		$permissions = ($this->sourceFile->exists()) ? $this->sourceFile->getPermissions() : -1;

		$this->removeCurrent(); // tries to remove the current files.

		$fileObj = $this->fs()->getFile($this->tmpUploadPath);
		$copied = $fileObj->copy($targetFileObj);

		if (false === $copied) {
			if ($targetFileObj->exists()) {
				Log::addDebug('Copy declared failed, but target available');
			} else {
				$this->lastError = self::ERROR_COPY_FAILED;
			}
		}

		$deleted = $fileObj->delete();
		if (false === $deleted) {
			Log::addWarn('Temp file could not be removed. Permission issues?');
		}

		$this->targetFile->resetStatus(); // reinit target file because it came into existence.

		if ($permissions > 0)
			chmod($this->targetFile->getFullPath(), $permissions); // restore permissions
		else {
			Log::addWarn('Setting permissions failed');
		}

		// Uspdate the file attached. This is required for wp_get_attachment_url to work.
		// Using RawFullPath because FullPath does normalize path, which update_attached_file doesn't so in case of windows / strange Apspaths it fails.
		$updated = update_attached_file($this->post_id, $this->targetFile->getRawFullPath());
		if (! $updated) {
			Log::addError('Update Attached File reports as not updated or same value');
		}


		// Run the filter, so other plugins can hook if needed.
		$filtered = apply_filters('wp_handle_upload', array(
			'file' => $this->targetFile->getFullPath(),
			'url'  => $this->getTargetURL(),
			'type' => $this->targetFile->getMime(),
		), 'sideload');

		// check if file changed during filter. Set changed to attached file meta properly.
		if (isset($filtered['file']) && $filtered['file'] != $this->targetFile->getFullPath()) {
			update_attached_file($this->post_id, $filtered['file']);
			$this->targetFile = $this->fs()->getFile($filtered['file']);  // handle as a new file
			Log::addInfo('WP_Handle_upload filter returned different file', $filtered);
		}

		$target_url = $this->getTargetURL();
		$Replacer->setTarget($target_url);

		// Check and update post mimetype, otherwise badly coded plugins cry.
		$post_mime = get_post_mime_type($this->post_id);
		$target_mime = $this->targetFile->getMime();

		// update DB post mime type, if somebody decided to mess it up, and the target one is not empty.
		if ($target_mime !== $post_mime && strlen($target_mime) > 0) {
			\wp_update_post(array('post_mime_type' => $this->targetFile->getMime(), 'ID' => $this->post_id));
		}

		do_action('emr/converter/prevent-offload', $this->post_id);
		$target_metadata = wp_generate_attachment_metadata($this->post_id, $this->targetFile->getFullPath());
		do_action('emr/converter/prevent-offload-off', $this->post_id);

		wp_update_attachment_metadata($this->post_id, $target_metadata);

		$Replacer->setTargetMeta($target_metadata);

		/** If author is different from replacer, note this */
		$post_author = get_post_field('post_author', $this->post_id);
		$author_id = get_post_meta($this->post_id, '_emr_replace_author', true);

		if (intval($post_author) !== get_current_user_id()) {
			update_post_meta($this->post_id, '_emr_replace_author', get_current_user_id());
		} elseif ($author_id) {
			delete_post_meta($this->post_id, '_emr_replace_author');
		}

		if ($this->replaceType == self::MODE_SEARCHREPLACE) {
			// Write new image title.
			$title = $this->getNewTitle($target_metadata);
			$excerpt = $this->getNewExcerpt($target_metadata);
			$update_ar = array('ID' => $this->post_id);
			$update_ar['post_title'] = $title;
			$update_ar['post_name'] = sanitize_title($title);
			if ($excerpt !== false) {
				$update_ar['post_excerpt'] = $excerpt;
			}
			$update_ar['guid'] = $target_url; //wp_get_attachment_url($this->post_id);

			$post_id = \wp_update_post($update_ar, true);

			global $wpdb;
			// update post doesn't update GUID on updates.
			$wpdb->update($wpdb->posts, array('guid' =>  $target_url), array('ID' => $this->post_id));
			//enable-media-replace-upload-done

			// @todo This error in general ever happens?
			if (is_wp_error($post_id)) {
				$this->lastError = self::ERROR_UPDATE_POST;
			}
		}

		/// Here run the Replacer Module
		$args = array(
			'thumbnails_only' => ($this->replaceType == self::MODE_SEARCHREPLACE) ? false : true,
		);

		$doreplace = apply_filters('emr/replace/doreplace', true);
		if (true === $doreplace) {
			$Replacer->replace($args);
		}

		// Here Updatedata and a ffew others.
		$this->updateDate();

		// Give the caching a kick. Off pending specifics.
		$cache_args = array(
			'flush_mode' => 'post',
			'post_id' => $this->post_id,
			'source_url' => $source_url, 
			'target_url' => $target_url, 
		);

		$cache = new emrCache();
		$cache->flushCache($cache_args);

		do_action("enable-media-replace-upload-done", $target_url, $source_url, $this->post_id);

		return true;
	} // run


	/**
	 * Resolves the file being replaced and stores it in $sourceFile.
	 *
	 * - Starts from get_attached_file() (unfiltered by default), which may be the
	 *   `-scaled` image.
	 * - When wp_get_original_image_path() exists (WP 5.3+), prefers the original (pre-scaled)
	 *   image, so replacing does not produce names like `image-scaled-scaled.jpg`. The result
	 *   passes through `emr/replace/original_image_path`. Falls back to the attached file
	 *   when that returns false (not scaled, or not an image). The function_exists() guard
	 *   only matters for WordPress 4.9.7 to 5.2; the readme's minimum is 4.9.7.
	 * - Virtual (offloaded) files: the untranslated file is kept in $sourceFileUntranslated
	 *   and `emr/file/virtual/translate` may return a local path, which then becomes the
	 *   source.
	 * - Relative-path repair: when the path is not virtual, does not exist, is not absolute
	 *   (no leading `/` and no Windows drive letter), it is prefixed with the uploads basedir
	 *   from wp_get_upload_dir(). This covers a broken upload-dir setup returning relative
	 *   paths.
	 *
	 * The local `$file` read from `_wp_attached_file` in the repair branch is never used.
	 *
	 * @hook filter emr_unfiltered_get_attached_file Whether to read the attached file unfiltered (default true).
	 * @hook filter emr/replace/original_image_path
	 * @hook filter emr/file/virtual/translate
	 * @return void
	 */
	protected function setupSource()
	{
		$source_file = false;

		// The main image as registered in attached_file metadata.  This can be regular or -scaled.
		$source_file_main = trim(get_attached_file($this->post_id, apply_filters('emr_unfiltered_get_attached_file', true)));

		// If available it -needs- to use the main image when replacing since treating a -scaled images as main will create a resursion in the filename when not replacing that one . Ie image-scaled-scaled.jpg or image-scaled-100x100.jpg .
		if (function_exists('wp_get_original_image_path')) // WP 5.3+
		{
			$source_file = wp_get_original_image_path($this->post_id, apply_filters('emr_unfiltered_get_attached_file', true));
			// For offload et al to change path if wrong. Somehow this happens?
			$source_file = apply_filters('emr/replace/original_image_path', $source_file, $this->post_id);
		}

		if (false === $source_file) // If not scaled, use the main one.
		{
			$source_file = $source_file_main;
		}


		$sourceFileObj = $this->fs()->getFile($source_file);
		$isVirtual = false;
		if ($sourceFileObj->is_virtual()) {
			$isVirtual = true;

			/***
			 *** Either here the table should check scaled - non-scaled ** or ** the original_path should be updated.
			 ***

			 */

			$this->sourceFileUntranslated = $this->fs()->getFile($source_file);
			$sourcePath = apply_filters('emr/file/virtual/translate', $sourceFileObj->getFullPath(), $sourceFileObj, $this->post_id);

			if (false !== $sourcePath && $sourceFileObj->getFullPath() !== $sourcePath) {
				$sourceFileObj = $this->fs()->getFile($sourcePath);
				$source_file = $sourcePath;
			}
		}


		/* It happens that the SourceFile returns relative / incomplete when something messes up get_upload_dir with an error something.
					 This case shoudl be detected here and create a non-relative path anyhow..
				*/
		if (
			false === $isVirtual &&
			false === file_exists($source_file) &&
			$source_file && 0 !== strpos($source_file, '/')
			&& ! preg_match('|^.:\\\|', $source_file)
		) {
			$file = get_post_meta($this->post_id, '_wp_attached_file', true);
			$uploads = wp_get_upload_dir();
			$source_file = $uploads['basedir'] . "/$source_file";
		}

		Log::addDebug('SetupSource SourceFile Path ' . $source_file);
		$this->sourceFile = $this->fs()->getFile($source_file);
	}

	/**
	 * Returns the full target path (including the file name) for the new file.
	 *
	 * - MODE_REPLACE: the source file's full path (overwrite in place).
	 * - MODE_SEARCHREPLACE: the source directory, or the new location from
	 *   getNewTargetLocation() when one is set, plus the new file name. getNewTargetLocation()
	 *   runs (and can fail) even when no new location is requested. With a new location, an
	 *   existing different file with that name gives ERROR_TARGET_EXISTS. When the computed
	 *   path equals the source path, the source file name is kept; otherwise
	 *   wp_unique_filename() makes the name unique. The name then passes through
	 *   `emr_unique_filename`.
	 * - When the result is a directory (a broken source), falls back to the current upload
	 *   path from wp_upload_dir() with a unique name, or ERROR_DESTINATION_FAIL when that
	 *   has no `path`.
	 *
	 * The local `$targetPath` is unused (the code uses `$targetpath`). When $replaceType is
	 * neither mode, `$targetFile` is undefined.
	 *
	 * @todo On the "same path" branch, `$this->replaceType == self::MODE_REPLACE;` is a
	 *       comparison with no effect, so the mode stays MODE_SEARCHREPLACE and run() still
	 *       updates the title/guid and does a full search and replace. Suggested fix: use
	 *       `=` if switching to MODE_REPLACE is intended, or remove the statement.
	 *
	 * @hook filter emr_unique_filename
	 * @uses self::ERROR_TARGET_EXISTS
	 * @uses self::ERROR_DESTINATION_FAIL
	 * @return string|null Full target path, or null on error (see $lastError).
	 */
	protected function setupTarget()
	{
		$targetPath = null;
		if ($this->replaceType == self::MODE_REPLACE) {
			$targetFile = $this->getSourceFile()->getFullPath(); // overwrite source
		} elseif ($this->replaceType == self::MODE_SEARCHREPLACE) {
			$path = (string) $this->getSourceFile()->getFileDir();
			$targetLocation = $this->getNewTargetLocation();
			if (false === $targetLocation) {
				return null;
			}

			if (false === is_null($this->new_location)) // Replace to another path.
			{
				$otherTarget = $this->fs()->getFile($targetLocation . $this->new_filename);
				// Halt if new target exists, but not if it's the same ( overwriting itself )

				if ($otherTarget->exists() && $otherTarget->getFullPath() !== $this->getSourceFile()->getFullPath()) {
					$this->lastError = self::ERROR_TARGET_EXISTS;
					return null;
				}

				$path = $targetLocation; // $this->target_location; // if all went well.
			}
			$targetpath = $path . $this->new_filename;

			// If the source and target path AND filename are identical, user has wrong mode, just overwrite the sourceFile.
			if ($targetpath == $this->sourceFile->getFullPath()) {
				$unique = $this->sourceFile->getFileName();
				$this->replaceType == self::MODE_REPLACE;
			} else {
				$unique = wp_unique_filename($path, $this->new_filename);
			}
			$new_filename = apply_filters('emr_unique_filename', $unique, $path, $this->post_id);
			$targetFile = trailingslashit($path) . $new_filename;
		}
		if (is_dir($targetFile)) // this indicates an error with the source.
		{
			Log::addWarn('TargetFile is directory ' . $targetFile);
			$upload_dir = wp_upload_dir();
			if (isset($upload_dir['path'])) {
				$targetFile = trailingslashit($upload_dir['path']) . wp_unique_filename($targetFile, $this->new_filename);
			} else {

				$this->lastError = self::ERROR_DESTINATION_FAIL;
				return null;
			}
		}
		return $targetFile;
	}

	/**
	 * Returns the new attachment title for search-replace mode.
	 *
	 * Uses the original upload name without extension (keeps accents and spaces that
	 * sanitize_file_name() removes from the target name), or the target file name when no
	 * upload name is set. A non-empty `image_meta.title` in the new metadata wins.
	 *
	 * @param array $meta Attachment metadata generated for the new file.
	 * @hook filter enable_media_replace_title
	 * @return string The title.
	 */
	protected function getNewTitle($meta)
	{
		// Use the original uploaded filename (preserves diacritics and spaces)
		// rather than $this->targetFile, whose name has been run through
		// sanitize_file_name() and lost accents/spaces by this point.
		$source = ! empty($this->new_filename) ? $this->new_filename : $this->targetFile->getFileName();
		$title  = pathinfo($source, PATHINFO_FILENAME);

		if (isset($meta['image_meta'])) {
			if (isset($meta['image_meta']['title'])) {
				if (strlen($meta['image_meta']['title']) > 0) {
					$title = $meta['image_meta']['title'];
				}
			}
		}

		// Thanks Jonas Lundman   (http://wordpress.org/support/topic/add-filter-hook-suggestion-to)
		$title = apply_filters('enable_media_replace_title', $title);

		return $title;
	}

	/**
	 * Returns the new attachment excerpt (caption) for search-replace mode.
	 *
	 * @param array $meta Attachment metadata generated for the new file.
	 * @return string|false The non-empty `image_meta.caption`, or false when there is none
	 *                      (the excerpt is then left unchanged).
	 */
	protected function getNewExcerpt($meta)
	{
		//	 $meta = $this->target_metadata;
		$excerpt = false;

		if (isset($meta['image_meta'])) {
			if (isset($meta['image_meta']['caption'])) {
				if (strlen($meta['image_meta']['caption']) > 0) {
					$excerpt = $meta['image_meta']['caption'];
				}
			}
		}

		return $excerpt;
	}

	/**
	 * Returns the URL of the file being replaced.
	 *
	 * Uses wp_get_original_image_url() when it exists (WP 5.3+; the readme's minimum is
	 * 4.9.7, so the guard only matters for 4.9.7 to 5.2), falling back to
	 * wp_get_attachment_url() when it returns false. The `$source_url = $source_url;` line is
	 * a no-op.
	 *
	 * @return string|false The source URL, or false when WordPress cannot build one.
	 */
	public function getSourceUrl()
	{
		if (function_exists('wp_get_original_image_url')) // WP 5.3+
		{
			$source_url = wp_get_original_image_url($this->post_id);
			if ($source_url === false)  // not an image, or borked, try the old way
				$source_url = wp_get_attachment_url($this->post_id);

			$source_url = $source_url;
		} else
			$source_url = wp_get_attachment_url($this->post_id);

		return $source_url;
	}

	/**
	 * Writes the new dates for the replacement directly to the posts table.
	 *
	 * post_modified / post_modified_gmt are always set to $newDate. post_date / post_date_gmt
	 * are set only for TIME_UPDATEALL and TIME_CUSTOM. The GMT values come from
	 * get_gmt_from_date(). The write uses $wpdb->update() (no post hooks fire), its result is
	 * not checked, and the post's object cache entry is deleted afterwards.
	 *
	 * @global \wpdb $wpdb
	 * @uses self::TIME_UPDATEALL
	 * @uses self::TIME_CUSTOM
	 * @return void
	 */
	protected function updateDate()
	{
		global $wpdb;
		$post_date = $this->newDate;
		$post_date_gmt = get_gmt_from_date($post_date);

		$update_ar = array('ID' => $this->post_id);
		if ($this->timeMode == static::TIME_UPDATEALL || $this->timeMode == static::TIME_CUSTOM) {
			$update_ar['post_date'] = $post_date;
			$update_ar['post_date_gmt'] = $post_date_gmt;
		} else {
		}
		$update_ar['post_modified'] = $post_date;
		$update_ar['post_modified_gmt'] = $post_date_gmt;

		$updated = $wpdb->update($wpdb->posts, $update_ar, array('ID' => $this->post_id));

		wp_cache_delete($this->post_id, 'posts');
	}

	/**
	 * Tries to remove all files of the current image from disk, without touching the
	 * metadata in the database.
	 *
	 * Calls wp_delete_attachment_files() with the current metadata, the
	 * `_wp_attachment_backup_sizes` meta and the source file (the original, pre-scaled
	 * image). Then, when the (filtered) attached file still exists, it is removed with a
	 * hard, error-suppressed unlink(). This covers the `-scaled` file and plugins (such as
	 * Polylang) that block deletion while a translation or duplicate references the file.
	 * A failed removal is not an error: the copy in run() can still overwrite the file.
	 *
	 * @hook action emr_after_remove_current Fired with ($post_id, $meta, $backup_sizes, $sourceFile, $targetFile).
	 * @return void
	 */
	protected function removeCurrent()
	{
		$meta = \wp_get_attachment_metadata($this->post_id);
		$backup_sizes = get_post_meta($this->post_id, '_wp_attachment_backup_sizes', true);

		// this must be -scaled if that exists, since wp_delete_attachment_files checks for original_files but doesn't recheck if scaled is included since that the one 'that exists' in WP . $this->source_file replaces original image, not the -scaled one.
		$file = $this->sourceFile->getFullPath();
		$result = \wp_delete_attachment_files($this->post_id, $meta, $backup_sizes, $file);

		// If Attached file is not the same path as file, this indicates a -scaled images is in play.
		// Also plugins like Polylang tend to block delete image while there is translation / duplicate item somewhere
		// A hard delete runs when the file still exists.
		$attached_file = get_attached_file($this->post_id);
		if (file_exists($attached_file)) {
			@unlink($attached_file);
		}

		do_action('emr_after_remove_current', $this->post_id, $meta, $backup_sizes, $this->sourceFile, $this->targetFile);
	}

	/**
	 * Builds the URL of the target file, because WordPress functions cannot be trusted here
	 * in certain cases.
	 *
	 * - Returns wp_get_attachment_url() unchanged when its basename equals the target
	 *   file name.
	 * - When that URL has no `://` (a relative URL), builds it by replacing the uploads
	 *   basedir with baseurl in the target's full path.
	 * - Otherwise (a stale GUID or old file name), replaces the old basename in the URL with
	 *   the target file name.
	 *
	 * @todo `strpos($url, '://') >= 0` is always true (false >= 0 is true in PHP), so the
	 *       first branch returns a relative URL unchanged whenever the basename matches.
	 *       Suggested fix: `strpos($url, '://') !== false`.
	 *
	 * @return string|false The target URL, or false (with an error log entry) when no target
	 *                      file is set.
	 */
	protected function getTargetURL()
	{
		if (is_null($this->targetFile)) {
			Log::addError('TargetFile NULL ', debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10));
			return false;
		}
		//$uploads['baseurl']
		$url = wp_get_attachment_url($this->post_id);
		$url_basename = basename($url);

		// Seems all worked as normal.
		if (strpos($url, '://') >= 0 && $this->targetFile->getFileName() == $url_basename)
			return $url;

		// Relative path for some reason
		if (strpos($url, '://') === false) {
			$uploads = wp_get_upload_dir();
			$url = str_replace($uploads['basedir'], $uploads['baseurl'], $this->targetFile->getFullPath());
		}
		// This can happen when WordPress is not taking from attached file, but wrong /old GUID. Try to replace it to the new one.
		elseif ($this->targetFile->getFileName() != $url_basename) {
			$url = str_replace($url_basename, $this->targetFile->getFileName(), $url);
		}

		return $url;
	}

	/**
	 * Resolves and validates the directory for search-replace mode.
	 *
	 * Joins the uploads basedir with $new_location (empty when no new location is set, so
	 * the result is the basedir itself), normalizes it with normalizePath() (realpath()
	 * would fail on directories that do not exist yet) and adds a trailing slash.
	 *
	 * Traversal check: the result must start with the trailing-slashed realpath() of the
	 * basedir. Because normalizePath() drops `..` segments instead of resolving them, a
	 * location such as `../../etc` becomes `<basedir>/etc` and passes the check; it does not
	 * escape uploads.
	 *
	 * Multisite guard: on the main site of a multisite (EnvHelper flags), a result containing
	 * `<basedir>sites` is rejected, so main-site files cannot be moved into sub-site upload
	 * folders. This is a prefix match, so any main-site folder whose name starts with
	 * `sites` is rejected as well.
	 *
	 * Both failures set ERROR_DIRECTORY_SECURITY and $lastErrorData
	 * `array('path' => $realPath, 'basedir' => $basedir)`. The directory-exists check
	 * (ERROR_DIRECTORY_NOTEXIST) is commented out.
	 *
	 * @todo The left side is normalized but not resolved, while the basedir goes through
	 *       realpath(). When the uploads path contains a symlink, or on Windows (realpath()
	 *       returns backslashes, normalizePath() forward slashes), the prefix never matches
	 *       and every search-replace replacement fails with ERROR_DIRECTORY_SECURITY. Suggested
	 *       fix: normalize both sides the same way (normalizePath() on the realpath() of
	 *       the basedir, and on an unresolved basedir as a second accepted prefix).
	 *
	 * @uses self::ERROR_DIRECTORY_SECURITY
	 * @return string|false The absolute target directory with trailing slash, or false.
	 */
	protected function getNewTargetLocation()
	{
		$uploadDir = wp_upload_dir();
		$new_rel_location = $this->new_location;
		$newPath = trailingslashit($uploadDir['basedir']) . $new_rel_location;

		//$realPath = realpath($newPath);
		/** normalizePath() is used because realpath() returns false for non-existing paths.
		 *  The trailing slash keeps the multisite check from matching /2 against /20.
		 */
		$realPath = trailingslashit($this->normalizePath($newPath));
		$basedir = trailingslashit(realpath($uploadDir['basedir'])); // both need to go to realpath, otherwise some servers will have issues with it.

		// Detect traversal by making sure the canonical path starts with uploads' basedir.
		if (strpos($realPath, $basedir) !== 0) {
			$this->lastError = self::ERROR_DIRECTORY_SECURITY;
			$this->lastErrorData = array('path' => $realPath, 'basedir' => $basedir);
			return false;
		}

		// If multisite, prevent mainsite uploads from ending up in subsites. 
		// Too solve, check if blog_id 20 for instance, want to overwrite something in blog_id 2, strpos doesn't detect this properly. 
		if (true === \emr()->env()->is_multisite)
		{
			$mainsite = \emr()->env()->is_mainsite; 

			// If Mainsite, it should not accepts a /sites/ path
			if (true === $mainsite)
			{
				if (strpos($realPath, $basedir . 'sites') !== false)	
				{
						$this->lastError = self::ERROR_DIRECTORY_SECURITY;
						$this->lastErrorData = array('path' => $realPath, 'basedir' => $basedir);
						return false; 
				}
			}
		}

		/*if (! is_dir($realPath)) {
			$this->lastError = self::ERROR_DIRECTORY_NOTEXIST;
			return false;
		} */
		return $realPath;
	}

	/**
	 * Normalizes a path without touching the filesystem.
	 *
	 * Converts backslashes to forward slashes, drops empty, `.` and `..` segments (`..` is
	 * discarded, not resolved against its parent), and keeps a leading slash for absolute
	 * paths. A Windows drive letter stays as the first segment, without a leading slash.
	 * Expects a non-empty string (reads `$path[0]`).
	 *
	 * @param string $path Path to normalize.
	 * @return string The normalized path, without trailing slash.
	 */
	private function normalizePath($path)
	{
    // Convert all separators to forward slashes
		$path = str_replace('\\', '/', $path);
		
		// Split into parts and filter
		$parts = explode('/', $path);
		$resolved = [];
		
		foreach ($parts as $part) {
			if ($part === '' || $part === '.' || $part === '..') {
				continue;
			}
			$resolved[] = $part;
		}
		
		// Preserve leading slash for absolute paths
		$prefix = ($path[0] === '/') ? '/' : '';
		
		return $prefix . implode('/', $resolved);

	}




	/**
	 * Returns the plugin's filesystem controller.
	 *
	 * @return \EnableMediaReplace\FileSystem\Controller\FileSystemController A new instance on every call.
	 */
	private function fs()
	{
		return emr()->filesystem();
	}
}
