<?php
namespace EnableMediaReplace\Controller;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use function EnableMediaReplace\EMR as EMR;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Replacer\Replacer as Replacer;
use EnableMediaReplace\Cache as Cache;

class ReplaceController
{
  protected $post_id;
//	protected $sourceFile;
//	protected $sourceFileUntranslated;
//
  protected $sourceImage;
	protected $targetFile;

	const MODE_REPLACE = 1;
	const MODE_SEARCHREPLACE = 2;

	const TIME_UPDATEALL = 1; // replace the date
	const TIME_UPDATEMODIFIED = 2; // keep the date, update only modified
	const TIME_CUSTOM = 3; // custom time entry

	const ERROR_TARGET_EXISTS = 20;
	const ERROR_DESTINATION_FAIL = 21;
	const ERROR_COPY_FAILED = 22;
	const ERROR_UPDATE_POST = 23;
	const ERROR_DIRECTORY_SECURITY = 24;
	const ERROR_DIRECTORY_NOTEXIST = 25;

	protected $replaceType;
	/** @var string */
	protected $new_location;
	protected $timeMode;
	protected $newDate;
  protected $keepTitle;

	protected $new_filename;

	protected $tmpUploadPath;

	protected $lastError;
	protected $lastErrorData; // optional extra data for last error.

		public function __construct($sourceImage)
		{
				$this->post_id = $sourceImage->image_id;
			//	$this->setupSource();
			   $this->sourceImage = $sourceImage;
		}

		/* getSourceFile
		*
		* @param $untranslated boolean if file is offloaded, this indicates to return remote variant. Used for displaying preview
		*/
	/*
		public function getSourceFile($untranslated = false)
		{

				if (true === $untranslated && ! is_null($this->sourceFileUntranslated))
				{
					return $this->sourceFileUntranslated;
				}
				return $this->sourceFile;
		} */

		public function setupParams($params)
		{
				$this->replaceType = ($params['replace_type'] === 'replace_and_search') ? self::MODE_SEARCHREPLACE : self::MODE_REPLACE;

				if ($this->replaceType == self::MODE_SEARCHREPLACE && true === $params['new_location'] && ! is_null($params['location_dir']))
				{
					 $this->new_location = $params['location_dir'];
				}

				$this->timeMode = $params['timestamp_replace'];
				$this->newDate = $params['new_date'];
        $this->keepTitle = (bool) $params['keep_title'];

				$this->new_filename = $params['new_filename'];
				$this->tmpUploadPath = $params['uploadFile'];

				$targetFile = $this->setupTarget();
				if (is_null($targetFile))
				{
						return false;
				}
        $fs = EMR()->filesystem();
				$this->targetFile = $fs->getFile($targetFile);

				return true;
		}

		public function returnLastError()
		{
			 return $this->lastError;
		}

		public function returnLastErrorData()
		{
			 if (! is_null($this->lastErrorData))
			 	return $this->lastErrorData;
			 else {
			 		return array();
			 }
		}

		public function run()
		{
			do_action('wp_handle_replace', array('post_id' => $this->post_id));
      $fs = EMR()->filesystem();

			// Set Source / and Source Metadata
			$Replacer = new Replacer();
			$source_url = $this->getSourceUrl();
			$Replacer->setSource($source_url);

      $meta = $this->getSourceMeta();
			$Replacer->setSourceMeta($meta);

			$targetFileObj = $fs->getFile($this->targetFile);

			$directoryObj = $targetFileObj->getFileDir();

			$result = $directoryObj->check();

			if ($result === false)
			{
				Log::addError('Directory creation for targetFile failed');
			}

			$permissions = ($this->sourceImage->exists() ) ? $this->sourceImage->getPermissions() : -1;

			$this->removeCurrent(); // tries to remove the current files.

			$fileObj = $fs->getFile($this->tmpUploadPath);
			$copied = $fileObj->copy($targetFileObj);

			if (false === $copied)
			{
				if ($targetFileObj->exists())
				{
					 Log::addInfo('Copy declared failed, but target available');
				}
				else {
					$this->lastError = self::ERROR_COPY_FAILED;
				}
			}

			$deleted = $fileObj->delete();
			if (false === $deleted)
			{
				 Log::addWarn('Temp file could not be removed. Permission issues?');
			}

			$this->targetFile->resetStatus(); // reinit target file because it came into existence.

			if ($permissions > 0)
        $modbool = chmod( $this->targetFile->getFullPath(), $permissions ); // restore permissions

      if ($permissions <= 0 || false === $modbool)
      {
        Log::addWarn('Setting permissions failed');
      }

			// Uspdate the file attached. This is required for wp_get_attachment_url to work.
			// Using RawFullPath because FullPath does normalize path, which update_attached_file doesn't so in case of windows / strange Apspaths it fails.
			$updated = update_attached_file($this->post_id, $this->targetFile->getRawFullPath() );
      if (! $updated)
			{
        Log::addError('Update Attached File reports as not updated or same value');
			}

      // Run the filter, so other plugins can hook if needed.
      $filtered = apply_filters( 'wp_handle_upload', array(
          'file' => $this->targetFile->getFullPath(),
          'url'  => $this->getTargetURL(),
          'type' => $this->targetFile->getMime(),
      ), 'sideload');

      // check if file changed during filter. Set changed to attached file meta properly.
      if (isset($filtered['file']) && $filtered['file'] != $this->targetFile->getFullPath() )
      {
        update_attached_file($this->post_id, $filtered['file'] );
        $this->targetFile = $fs->getFile($filtered['file']);  // handle as a new file
        Log::addInfo('WP_Handle_upload filter returned different file', $filtered);
      }

			// Check and update post mimetype, otherwise badly coded plugins cry.
		  $post_mime = get_post_mime_type($this->post_id);
			$target_mime = $this->targetFile->getMime();

			// update DB post mime type, if somebody decided to mess it up, and the target one is not empty.
			if ($target_mime !== $post_mime && strlen($target_mime) > 0)
			{
				  \wp_update_post(array('post_mime_type' => $this->targetFile->getMime(), 'ID' => $this->post_id));
			}

			do_action('emr/converter/prevent-offload', $this->post_id);
      $target_metadata = wp_generate_attachment_metadata( $this->post_id, $this->targetFile->getFullPath() );
			do_action('emr/converter/prevent-offload-off', $this->post_id);

      wp_update_attachment_metadata( $this->post_id, $target_metadata );

      $target_url = $this->getTargetURL();
			$Replacer->setTarget($target_url);

      Log::addTemp("TARGET URL", $target_url);

			$Replacer->setTargetMeta($target_metadata);
			//$this->target_metadata = $metadata;

      /** If author is different from replacer, note this */
			$post_author = get_post_field( 'post_author', $this->post_id );
      $author_id = get_post_meta($this->post_id, '_emr_replace_author', true);

      if ( intval($post_author) !== get_current_user_id())
      {
         update_post_meta($this->post_id, '_emr_replace_author', get_current_user_id());
      }
      elseif ($author_id)
      {
        delete_post_meta($this->post_id, '_emr_replace_author');
      }


      if ($this->replaceType == self::MODE_SEARCHREPLACE)
      {
         // Write new image title.
         $title = $this->getNewTitle($target_metadata);
				 $excerpt = $this->getNewExcerpt($target_metadata);
         $update_ar = array('ID' => $this->post_id);

         // Option for keeping the title.
         if (false === $this->keepTitle)
         {
             $update_ar['post_title'] = $title;
             $update_ar['post_name'] = sanitize_title($title);

             if ($excerpt !== false)
    				 {
    				 		$update_ar['post_excerpt'] = $excerpt;
    				 }
         }
         $update_ar['guid'] = $target_url; //wp_get_attachment_url($this->post_id);

         $post_id = \wp_update_post($update_ar, true);

				 global $wpdb;
         // update post doesn't update GUID on updates.
         $wpdb->update( $wpdb->posts, array( 'guid' =>  $target_url), array('ID' => $this->post_id) );

         // @todo This error in general ever happens?
         if (is_wp_error($post_id))
         {
					  $this->lastError = self::ERROR_UPDATE_POST;
         }

      }

			/// Here run the Replacer Module
			$args = array(
          'thumbnails_only' => ($this->replaceType == self::MODE_SEARCHREPLACE) ? false : true,
      );

      Log::addTemp('Replacer URL before Replace', $this->getTargetURL());

			$doreplace = apply_filters('emr/replace/doreplace', true);
			if(true === $doreplace){
				$Replacer->replace($args);
			}

			// Here Updatedata and a ffew others.
			$this->updateDate();

      // Remove backups  - if any - from the WordPress native image editor.  -- return false to cancel . This is done before everything because the main file is changed to to edited URL. Need to reset the source

      $remove_editor_backup = apply_filters('shortpixel/replacer/remove_editor_backup', true);
      if (true === $remove_editor_backup)
      {
          $this->removeEditorBackup();
      }


			// Give the caching a kick. Off pending specifics.
			$cache_args = array(
				'flush_mode' => 'post',
				'post_id' => $this->post_id,
			);

			$cache = new Cache();
			$cache->flushCache($cache_args);

			do_action("enable-media-replace-upload-done", $target_url, $source_url, $this->post_id);

			return true;
		} // run


		/** Returns a full target path to place to new file. Including the file name!  **/
		protected function setupTarget()
		{
			$targetPath = null;
      $fs = EMR()->filesystem();

			if ($this->replaceType == self::MODE_REPLACE)
			{
				$targetFile = $this->sourceImage->getFullPath(); // overwrite source
			}
			elseif ($this->replaceType == self::MODE_SEARCHREPLACE)
			{
					$path = (string) $this->sourceImage->getFileDir();
					$targetLocation = $this->getNewTargetLocation();
					if (false === $targetLocation)
					{
						return null;
					}

					if (false === is_null($this->new_location)) // Replace to another path.
					{
						 $otherTarget = $fs->getFile($targetLocation . $this->new_filename);
						 // Halt if new target exists, but not if it's the same ( overwriting itself )

						 if ($otherTarget->exists() && $otherTarget->getFullPath() !== $this->sourceImage->getFullPath() )
						 {
                Log::addWarn('Image already exists in target directory. Source : ' . $this->sourceImage->getFullPath() . ' Target : ' . $otherTarget->getFullPath());

								$this->lastError = self::ERROR_TARGET_EXISTS;
								return null;
						 }

						 $path = $targetLocation; // $this->target_location; // if all went well.
					}
					$targetpath = $path . $this->new_filename;

					// If the source and target path AND filename are identical, user has wrong mode, just overwrite the sourceFile.
					if ($targetpath == $this->sourceImage->getFullPath())
					{
							$unique = $this->sourceImage->getFileName();
							$this->replaceType == self::MODE_REPLACE;
					}
					else
					{
							$unique = wp_unique_filename($path, $this->new_filename);
					}
					$new_filename = apply_filters( 'emr_unique_filename', $unique, $path, $this->post_id );
					$targetFile = trailingslashit($path) . $new_filename;
			}
			if (is_dir($targetFile)) // this indicates an error with the source.
			{
					Log::addWarn('TargetFile is directory ' . $targetFile );
					$upload_dir = wp_upload_dir();
					if (isset($upload_dir['path']))
					{
						$targetFile = trailingslashit($upload_dir['path']) . wp_unique_filename($targetFile, $this->new_filename);
					}
					else {

						$this->lastError = self::ERROR_DESTINATION_FAIL;
					 	return null;
					}
			}
			return $targetFile;
		}

		protected function getNewTitle($meta)
		{
			// get basename without extension
			$title = basename($this->targetFile->getFileName(), '.' . $this->targetFile->getExtension());

			if (isset($meta['image_meta']))
			{
				if (isset($meta['image_meta']['title']))
				{
						if (strlen($meta['image_meta']['title']) > 0)
						{
							 $title = $meta['image_meta']['title'];
						}
				}
			}

			// Thanks Jonas Lundman   (http://wordpress.org/support/topic/add-filter-hook-suggestion-to)
			$title = apply_filters( 'enable_media_replace_title', $title );

			return $title;
		}

		protected function getNewExcerpt($meta)
		{
			 $excerpt = false;

			 if (isset($meta['image_meta']))
			 {
				 if (isset($meta['image_meta']['caption']))
				 {
						 if (strlen($meta['image_meta']['caption']) > 0)
						 {
								$excerpt = $meta['image_meta']['caption'];
						 }
				 }
			 }

		 return $excerpt;
		}

		public function getSourceUrl()
		{
			if (function_exists('wp_get_original_image_url')) // WP 5.3+
			{
				$source_url = wp_get_original_image_url($this->post_id);
				if ($source_url === false)  // not an image, or borked, try the old way
					$source_url = wp_get_attachment_url($this->post_id);

				$source_url = $source_url;
			}
			else
				$source_url = wp_get_attachment_url($this->post_id);

			return $source_url;
		}

    protected function getSourceMeta()
    {
            $meta = wp_get_attachment_metadata( $this->post_id );

            if (false === $meta)
            {
              return false;
            }

            if (isset($meta['sizes']))
            {
              $meta['sizes'] = array_merge($meta['sizes'], $this->getBackupmeta());
            }
        return $meta;
    }

		/** Handle new dates for the replacement */
	  protected function updateDate()
	  {
	 	 global $wpdb;
	 	 $post_date = $this->newDate;
	 	 $post_date_gmt = get_gmt_from_date($post_date);

	 	 $update_ar = array('ID' => $this->post_id);
	 	 if ($this->timeMode == static::TIME_UPDATEALL || $this->timeMode == static::TIME_CUSTOM)
	 	 {
	 		 $update_ar['post_date'] = $post_date;
	 		 $update_ar['post_date_gmt'] = $post_date_gmt;
	 	 }
	 	 else {

 	 	 }
	 	 $update_ar['post_modified'] = $post_date;
	 	 $update_ar['post_modified_gmt'] = $post_date_gmt;

	 	 $updated = $wpdb->update( $wpdb->posts, $update_ar , array('ID' => $this->post_id) );

	 	 wp_cache_delete($this->post_id, 'posts');

	  }

		/** Tries to remove all of the old image, without touching the metadata in database
	  *  This might fail on certain files, but this is not an indication of success ( remove might fail, but overwrite can still work)
	  */
	  protected function removeCurrent()
	  {
	    $meta = \wp_get_attachment_metadata( $this->post_id );
	    $backup_sizes = get_post_meta( $this->post_id, '_wp_attachment_backup_sizes', true );

	    // this must be -scaled if that exists, since wp_delete_attachment_files checks for original_files but doesn't recheck if scaled is included since that the one 'that exists' in WP . $this->source_file replaces original image, not the -scaled one.
	    $file = $this->sourceImage->getFullPath();
	    $result = \wp_delete_attachment_files($this->post_id, $meta, $backup_sizes, $file );

	    // If Attached file is not the same path as file, this indicates a -scaled images is in play.
		  // Also plugins like Polylang tend to block delete image while there is translation / duplicate item somewhere
			// 10/06/22 : Added a hard delete if file still exists.  Be gone, hard way.
	    $attached_file = get_attached_file($this->post_id);
	    if (file_exists($attached_file))
	    {
	       @unlink($attached_file);
	    }


	    do_action( 'emr_after_remove_current', $this->post_id, $meta, $backup_sizes, $this->sourceImage, $this->targetFile );
	  }

    /** Remove the backups from the WP native image editor  - prevent 'restore image' to remove the replacement */
    protected function removeEditorBackup()
    {
        $post_id = $this->post_id;

        // If backup sizes restore the image.
        $backup_sizes = get_post_meta( $post_id, '_wp_attachment_backup_sizes', true );
        if (true === is_array($backup_sizes))
        {

           delete_post_meta($post_id, '_wp_attachment_backup_sizes');
        }

    }

    // Check for metadata in the backup, indicating the image editor has been used. Replace those too.
    protected function getBackupmeta()
    {
      $meta = array();
      $backup_sizes = get_post_meta( $this->post_id, '_wp_attachment_backup_sizes', true );

      if (true === is_array($backup_sizes))
      {
          foreach($backup_sizes as $backup_name => $backup_data)
          {
              // Don't include the original files mentioned in backups.
              if (strpos($backup_name, 'orig') !== false)
              {
                 continue;
              }

              $meta[$backup_name] = $backup_data;
          }
      }

      return $meta;
    }

		/** Since WP functions also can't be trusted here in certain cases, create the URL by ourselves */
		protected function getTargetURL()
		{
			if (is_null($this->targetFile))
			{
				 return false;
			}
			//$uploads['baseurl']
			$url = wp_get_attachment_url($this->post_id);
			$url_basename = basename($url);

			// Seems all worked as normal.
			if (strpos($url, '://') >= 0 && $this->targetFile->getFileName() == $url_basename)
					return $url;

			// Relative path for some reason
			if (strpos($url, '://') === false)
			{
					$uploads = wp_get_upload_dir();
					$url = str_replace($uploads['basedir'], $uploads['baseurl'], $this->targetFile->getFullPath());
			}
			// This can happen when WordPress is not taking from attached file, but wrong /old GUID. Try to replace it to the new one.
			elseif ($this->targetFile->getFileName() != $url_basename)
			{
					$url = str_replace($url_basename, $this->targetFile->getFileName(), $url);
			}

			return $url;

		}

		protected function getNewTargetLocation()
		{
				$uploadDir = wp_upload_dir();
        $fs = emr()->filesystem();

				$new_rel_location = $this->new_location;
				$newPath = trailingslashit($uploadDir['basedir']) . $new_rel_location;

				$realPath = realpath($newPath);
				$basedir = realpath($uploadDir['basedir']); // both need to go to realpath, otherwise some servers will have issues with it.

        // If path is virtual, realpath fails and returns false. If the file is offloaded, don't check for the directory further ( also since move path is not supported on offload).
        // realpath also reports false if directory doesn't exist, so check if structure itself it within allowed bounds.
        if (false === $realPath)
        {

            if (true === $this->sourceImage->is_virtual())
            {
            return $newPath;
            }
            else { // This happens when directory doesn't exist.
                $topdir = $dir = $fs->getDirectory($newPath);
                $i = 0;
                while($dir !== false) // check if the structure somewhere is readable / try to check the directory into live.
                {
                    $dir = $dir->getParent();
                    if ($dir !== false && $dir->exists())
                    {
                       break;
                    }

                    $i++;
                    if ($i > 10) // loop prevention in case of unsuspected cases.
                    {
                       break;
                    }
                }

                $topdir->check();
                $realPath = $topdir->getPath();

            }
        }

				// Detect traversal by making sure the canonical path starts with uploads' basedir.
			 	if ( strpos($realPath, $basedir) !== 0)
			 	{
          Log::addWarn('Path outside of allowed directories: ' . $realPath);
					$this->lastError = self::ERROR_DIRECTORY_SECURITY;
					$this->lastErrorData = array('path' => $realPath, 'basedir' => $basedir);
					return false;
				}

				if (! is_dir($newPath))
				{
					$this->lastError = self::ERROR_DIRECTORY_NOTEXIST;
					return false;
				}
				return trailingslashit($newPath);
		}

}
