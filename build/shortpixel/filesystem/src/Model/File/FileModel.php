<?php
/**
 * File model of the FileSystem module.
 *
 * @package EnableMediaReplace\FileSystem
 */
namespace EnableMediaReplace\FileSystem\Model\File;
use EnableMediaReplace\ShortpixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * One file, existing or not, with lazy status checks and the low-level
 * operations on it.
 *
 * Obtain instances through FileSystemController::getFile();
 * `new FileModel($path)` is equivalent. The constructor stores the path as
 * given (getRawFullPath()) and trimmed; when the path is a URL it is
 * resolved right away by UrlToPath(), so that PHP stream wrappers never
 * see a remote URL. Everything else is deferred: the first call to
 * getFullPath(), getFileName(), getFileBase(), getExtension() or getMime()
 * runs setFileInfo(), which normalises the path through processPath()
 * (relative to absolute, open_basedir handling) and splits it with a
 * multibyte-safe pathinfo. The status flags (`exists`, `is_writable`,
 * `is_readable`, `is_file`, `filesize`, `permissions`) are each checked on
 * first use and cached; resetStatus() clears them after an operation that
 * changed the file, and copy(), move() and delete() call it.
 *
 * Virtual files: a URL that does not belong to this site cannot be mapped
 * to a path. The model then becomes virtual (is_virtual()) and asks
 * through the `shortpixel/image/urltopath` filter whether an integration
 * (an offload plugin, for example) can serve it. The filter answers false
 * (file does not exist), true or self::$VIRTUAL_REMOTE (exists, read-only)
 * or self::$VIRTUAL_STATELESS (exists and accepts writes through a stream
 * wrapper). Virtual files report size -1 and MIME false.
 *
 * Trusted mode: setting the static $TRUSTED_MODE to true makes every new
 * model assume it is an existing, readable, writable image file without
 * touching the disk, and skips the path checks in processPath(). Meant
 * for bulk listings of many files where the caller already knows they
 * are plain local files; never for processing.
 *
 * Hooks fired by this class:
 * - filter `shortpixel_image_exists` (exists(); legacy name, not rewritten
 *   by the build tool)
 * - filter `shortpixel/file/exists` (exists())
 * - action `shortpixel/filesystem/addfile` (copy())
 * - filter `shortpixel/filesystem/processFilePath` (processPath())
 * - filter `shortpixel/file/basedir_check` (fileIsRestricted())
 * - filter `shortpixel/image/urltopath` (UrlToPath())
 *
 * @todo $is_restricted is initialised to false and fileIsRestricted()
 *       returns the cached value whenever it is not null, so the
 *       open_basedir check never runs for a fresh model; it only runs
 *       after resetStatus() has set the property to null. Suggested fix:
 *       initialise $is_restricted to null.
 * @todo setFileInfo() takes the extension from mb_pathinfo(), which
 *       pre-fills 'extension' with '', so getExtension() returns '' rather
 *       than null for a file without extension. Suggested fix: treat the
 *       empty string as missing.
 */
/* FileModel class.
*
*
* - Represents a -single- file.
* - Can handle any type
* - Usually controllers would use a collection of files
* - Meant for all low-level file operations and checks.
* - Every file can have a backup counterpart.
*
*/
class FileModel
{

  // File info

  /**
   * Normalised absolute path. Holds the trimmed input until setFileInfo()
   * has run; empty string for a null input.
   *
   * @var string|null
   */
  protected $fullpath = null;

  /**
   * Path exactly as passed to the constructor.
   *
   * @var string|null
   */
  protected $rawfullpath = null;

  /**
   * File name with extension. Null until setFileInfo() runs, and stays
   * null when the path is not a file.
   *
   * @var string|null
   */
  protected $filename = null; // filename + extension

  /**
   * File name without extension.
   *
   * @var string|null
   */
  protected $filebase = null; // filename without extension

  /**
   * Model of the containing directory, created by getFileDir().
   *
   * @var DirectoryModel|null
   */
  protected $directory = null;

  /**
   * Lowercased last extension; '' when the file has none.
   *
   * @var string|null
   */
  protected $extension = null;

  /**
   * MIME type as determined by getMime(); false when unavailable.
   *
   * @var string|false|null
   */
  protected $mime = null;

  /**
   * Cached permission bits (mode & 0777).
   *
   * @var int|null
   */
  protected $permissions = null;

  /**
   * Cached size in bytes.
   *
   * @var int|null
   */
  protected $filesize = null;

  // File Status

  /**
   * Cached result of exists(); null until checked.
   *
   * @var bool|null
   */
  protected $exists = null;

  /**
   * Cached result of is_writable(); null until checked.
   *
   * @var bool|null
   */
  protected $is_writable = null;

  /**
   * Cached result of is_directory_writable(); null until checked.
   *
   * @var bool|null
   */
  protected $is_directory_writable = null;

  /**
   * Cached result of is_readable(); null until checked.
   *
   * @var bool|null
   */
  protected $is_readable = null;

  /**
   * Cached result of is_file(); null until checked.
   *
   * @var bool|null
   */
  protected $is_file = null;

  /**
   * True when the path is a URL that could not be mapped to a local path.
   *
   * @var bool|null
   */
  protected $is_virtual = false;

  /**
   * Cached open_basedir verdict of fileIsRestricted(). See the class
   * @todo about its initial value.
   *
   * @var bool|null
   */
  protected $is_restricted = false;

  /**
   * self::$VIRTUAL_REMOTE or self::$VIRTUAL_STATELESS for virtual files
   * that an integration claimed, null otherwise.
   *
   * @var int|null
   */
  protected $virtual_status = null;

  /**
   * Unused.
   *
   * @var mixed
   */
  protected $status; // seems unused ?

  /**
   * Unused status code.
   *
   * @var int
   */
  const FILE_OK = 1;

  /**
   * Unused status code.
   *
   * @var int
   */
  const FILE_UNKNOWN_ERROR = 2;

  /**
   * When true, new models skip all file system checks; see the class
   * description. Global for the request.
   *
   * @var bool
   */
	public static $TRUSTED_MODE = false;

	// Constants for is_virtual . Virtual Remote is truly a remote file, not writable from machine. Stateless means it looks remote, but it's a protocol-based filesystem remote or not - that will accept writes / is_writable. Stateless also mean performance issue since it can't be 'translated' to a local path. All communication happens over http wrapper, so check should be very limited.

  /**
   * Answer for `shortpixel/image/urltopath`: the file is remote and not
   * writable from this machine.
   *
   * @var int
   */
	public static $VIRTUAL_REMOTE = 1;

  /**
   * Answer for `shortpixel/image/urltopath`: the file is served by a
   * stream wrapper that also accepts writes, but has no local path.
   *
   * @var int
   */
	public static $VIRTUAL_STATELESS = 2;

  /**
   * Stores the path and resolves it when it is a URL. A null path is
   * logged and leaves an empty model.
   *
   * @param string|null $path Full path, or a URL.
   */
  /** Creates a file model object. FileModel files don't need to exist on FileSystem */
  public function __construct($path)
  {
		$this->rawfullpath = $path;

		if (is_null($path))
		{
			 Log::addWarn('FileModel: Loading null path! ');
			 return false;
		}

		if (strlen($path) > 0)
			$path = trim($path);

		$this->fullpath = $path;

		$this->checkTrustedMode();

    $fs = $this->getFS();

    if ($fs->pathIsUrl($path)) // Asap check for URL's to prevent remote wrappers from running.
    {
      $this->UrlToPath($path);
    }
  }

  /**
   * Returns a new FileSystemController for the helpers the model needs.
   *
   * @return \EnableMediaReplace\FileSystem\Controller\FileSystemController
   */
	private function getFS()
	{
		return new \EnableMediaReplace\FileSystem\Controller\FileSystemController();
	}

  /**
   * Returns the full path as currently held, which is the unprocessed
   * input until setFileInfo() has run. Prefer getFullPath().
   *
   * @return string
   */
  /* Get a string representation of file, the fullpath
  *  Note - this might be risky, without processedpath, in cases.
  * @return String  Full path  processed or unprocessed.
  */
  public function __toString()
  {
    return (string) $this->fullpath;
  }

  /**
   * Normalises the path with processPath() and fills $filename, $filebase
   * and $extension with a multibyte-safe pathinfo. The name fields are
   * only set when the path is a file, so a directory path leaves them
   * null.
   *
   * @return void
   */
  protected function setFileInfo()
  {
      $processed_path = $this->processPath($this->fullpath);
      if ($processed_path !== false)
        $this->fullpath = $processed_path; // set processed path if that went alright


      $info = $this->mb_pathinfo($this->fullpath);
      // Todo, maybe replace this with splFileINfo.
      if ($this->is_file()) // only set fileinfo when it's an actual file.
      {
        $this->filename = isset($info['basename']) ? $info['basename'] : null; // filename + extension
        $this->filebase = isset($info['filename']) ? $info['filename'] : null; // only filename
        $this->extension = isset($info['extension']) ? strtolower($info['extension']) : null; // only (last) extension
      }

  }

  /**
   * Forgets every cached status flag, including the virtual flag, so the
   * next checks hit the file system again. Call after the file was
   * changed outside this object.
   *
   * @return void
   */
  /** Call when file status changed, so writable / readable / exists are not reliable anymore */
  public function resetStatus()
  {
      $this->is_writable = null;
			$this->is_directory_writable = null;
      $this->is_readable = null;
      $this->is_file = null;
      $this->is_restricted = null;
      $this->exists = null;
      $this->is_virtual = null;
			$this->filesize = null;

	$this->permissions = null;
  }

  /**
   * Tells whether the file exists and is a regular file.
   *
   * Restricted (open_basedir) paths count as missing. The cached result
   * passes through the `shortpixel_image_exists` and
   * `shortpixel/file/exists` filters on every call, so integrations can
   * claim files that are not on disk.
   *
   * @param bool $forceCheck True to re-check the disk instead of using the
   *                         cached value. Use sparingly; exists for retina
   *                         handling under trusted mode.
   * @return bool
   */
	/**
	* @param $forceCheck  Forces a filesystem check instead of using cached.  Use very sparingly. Implemented for retina on trusted mode.
	*/
  public function exists($forceCheck = false)
  {
    if (true === $forceCheck || is_null($this->exists))
    {
      if (true === $this->fileIsRestricted($this->fullpath))
      {
          $this->exists = false;
      }
      else {
          $this->exists = (@file_exists($this->fullpath) && is_file($this->fullpath));
      }

    }


    $this->exists = apply_filters('shortpixel_image_exists', $this->exists, $this->fullpath, $this); //legacy
    $this->exists = apply_filters('emr/file/exists',  $this->exists, $this->fullpath, $this);
    return $this->exists;
  }

  /**
   * Tells whether the file can be written.
   *
   * Virtual files are not writable unless UrlToPath() marked them
   * stateless. For an existing local file PHP's is_writable() decides; for
   * a missing one the only reliable probe is to create() and delete() an
   * empty file, which is what happens.
   *
   * @return bool
   */
  public function is_writable()
  {
		// Return when already asked / Stateless might set this
		if (! is_null($this->is_writable))
		{
			 return $this->is_writable;
		}
    elseif ($this->is_virtual())
    {
       $this->is_writable = false;  // can't write to remote files
    }
    elseif (is_null($this->is_writable))
    {
      if ($this->exists())
      {
        $this->is_writable = @is_writable($this->fullpath);
      }
      else // quite expensive check to see if file is writable.
      {
          $res = $this->create();
          $this->delete();
          $this->is_writable = $res;
      }

    }

    return $this->is_writable;
  }

  /**
   * Tells whether the containing directory can be written, for callers
   * that want to create a sibling file (a converted copy, for example).
   * False for virtual files (unless stateless) and missing directories.
   *
   * @return bool
   */
	public function is_directory_writable()
	{
		// Return when already asked / Stateless might set this
		if (! is_null($this->is_directory_writable))
		{
			 return $this->is_directory_writable;
		}
		elseif ($this->is_virtual())
		{
			 $this->is_directory_writable = false;  // can't write to remote files
		}
		elseif (is_null($this->is_directory_writable))
		{
			$directory = $this->getFileDir();
			if (is_object($directory) && $directory->exists())
			{
				$this->is_directory_writable = $directory->is_writable();
			}
			else {
				$this->is_directory_writable = false;
			}

		}

		return $this->is_directory_writable;
	}

  /**
   * Tells whether the file can be read. Cached after the first check.
   *
   * @return bool
   */
  public function is_readable()
  {
    if (is_null($this->is_readable))
      $this->is_readable = @is_readable($this->fullpath);

    return $this->is_readable;
  }

  /**
   * Tells whether the file is virtual: a URL with no local counterpart.
   * Set by UrlToPath() during construction.
   *
   * @return bool
   */
  // A file is virtual when the file is remote  with URL and no local alternative is present.
  public function is_virtual()
  {
     if ( is_null($this->is_virtual))
      $this->is_virtual = false; // return bool
     return $this->is_virtual;
  }

  /**
   * Tells whether the path denotes a file rather than a directory.
   *
   * Virtual files are files. An existing path is a file when is_file()
   * says so. A missing path is treated as a potential file unless it is
   * `.`/`..` or an existing directory, because the model allows files
   * that are created later. Guards against a directory path being passed
   * to the model by mistake.
   *
   * @return bool
   */
  /* Function checks if path is actually a file. This can be used to check possible confusion if a directory path is given to filemodel */
  public function is_file()
  {
    if ($this->is_virtual()) // don't look further when virtual
    {
        $this->is_file = true;
        return $this->is_file;
    }
    elseif (is_null($this->is_file))
    {
      if ($this->exists())
      {
        if (basename($this->fullpath) == '..' || basename($this->fullpath) == '.')
          $this->is_file = false;
        else
          $this->is_file = is_file($this->fullpath);
      }
      else // file can not exist, but still have a valid filepath format. In that case, if file should return true.
      {

         /*  if file does not exist on disk, anything can become a file ( with/ without extension, etc). Meaning everything non-existing is a potential file ( or directory ) until created. */

         if (basename($this->fullpath) == '..' || basename($this->fullpath) == '.') // don't see this as file.
         {
            $this->is_file = false;
         }
         else if (! file_exists($this->fullpath) && ! is_dir($this->fullpath))
         {
              $this->is_file = true;
         }
         else //if (! is_file($this->fullpath)) // can be  a non-existing directory. /
         {
              $this->is_file = false;
         }

      }
    }
    return $this->is_file;
  }

  /**
   * Returns the modification time. Not cached, no existence check.
   *
   * @return int|false Unix timestamp, false when it cannot be read.
   */
  public function getModified()
  {
    return filemtime($this->fullpath);
  }


  /**
   * Returns the model of the containing directory, created on first use.
   * The full path is passed on because DirectoryModel strips the file
   * part itself; dirname() would misbehave when the path is already a
   * directory.
   *
   * @return DirectoryModel|null Null when the path is empty.
   */
  /** Returns the Directory Model this file resides in
  *
  * @return DirectoryModel Directorymodel Object
  */
  public function getFileDir()
 {
      $fullpath = $this->getFullPath(); // triggers a file lookup if needed.
      // create this only when needed.
      if (is_null($this->directory) && strlen($fullpath) > 0)
      {
        // Feed to full path to DirectoryModel since it checks if input is file, or dir. Using dirname here would cause errors when fullpath is already just a dirpath ( faulty input )
          $this->directory = new DirectoryModel($fullpath);
      }

      return $this->directory;
  }

  /**
   * Returns the size in bytes, cached.
   *
   * @return int Bytes; 0 for a missing file, -1 for a virtual file.
   */
  public function getFileSize()
  {
		if (! is_null($this->filesize))
		{
			 return $this->filesize;
		}
    elseif ($this->exists() && false === $this->is_virtual() )
		{
       $this->filesize = filesize($this->fullpath);
			 return $this->filesize;
		}
    elseif (true === $this->is_virtual())
		{
			 return -1;
		}
		else
      return 0;
  }

  /**
   * Creates an empty file with touch(). Requires the directory to exist;
   * an already existing file is logged and left alone. Used by
   * is_writable() as a probe for missing files.
   *
   * @return bool True when the file was created.
   */
  // Creates an empty file
  public function create()
  {
     if (! $this->exists() )
     {
      $fileDir = $this->getFileDir();

      if (! is_null($fileDir) && $fileDir->exists())
      {
        $res = @touch($this->fullpath);
        $this->exists = $res;
        return $res;
      }
    }
     else
      Log::addWarn('Could not create/write file: ' . $this->fullpath);

    return false;
  }

  /**
   * Appends a string to the file, creating the file first when needed.
   * Used by the logger.
   *
   * @param string $message Content to append.
   * @return bool False (and a warning in the log) when not writable.
   */
  public function append($message)
  {
       if (! $this->exists() )
          $this->create();

      if (! $this->is_writable() )
			{
          Log::addWarn('File append failed on ' . $this->getFullPath() . ' - not writable');
					return false;
			}
      $handle = fopen($this->getFullPath(), 'a');
      fwrite($handle, $message);
      fclose($handle);

			return true;
  }


  /**
   * Copies the file to another FileModel's path with copy().
   *
   * Refuses empty paths and a missing source (both logged). On success
   * the destination's cached status and name fields are refreshed. The
   * `shortpixel/filesystem/addfile` action fires in every case after the
   * attempt, with `[destination path, destination model, source model,
   * is_new]`, where is_new tells whether the destination existed before.
   *
   * @param FileModel $destination Target file.
   * @return bool
   */
  /** Copy a file to somewhere
  *
  * @param $destination String Full Path to new file.
  */
  public function copy(FileModel $destination)
  {
      $sourcePath = $this->getFullPath();
      $destinationPath = $destination->getFullPath();
      Log::addDebug("Copy from $sourcePath to $destinationPath ");

      if (! strlen($sourcePath) > 0 || ! strlen($destinationPath) > 0)
      {
        Log::addWarn('Attempted Copy on Empty Path', array($sourcePath, $destinationPath));
        return false;
      }

      if (! $this->exists())
      {
        Log::addWarn('Tried to copy non-existing file - '  . $sourcePath);
        return false;
      }

      $is_new = ($destination->exists()) ? false : true;
      $status = @copy($sourcePath, $destinationPath);

      if (! $status)
			{
        Log::addWarn('Could not copy file ' . $sourcePath . ' to' . $destinationPath);
			}
      else
			{
        $destination->resetStatus();
        $destination->setFileInfo(); // refresh info.
      }
      //
      do_action('emr/filesystem/addfile', array($destinationPath, $destination, $this, $is_new));
      return $status;
  }

  /**
   * Moves the file by copy() followed by delete() of the source. Fails
   * when either step fails; a copy that succeeded is kept even when the
   * delete fails (logged as an error). Both models' status is reset.
   *
   * @param FileModel $destination Target file.
   * @return bool
   */
  /** Move a file to somewhere
  * This uses copy and delete functions and will fail if any of those fail.
  * @param $destination String Full Path to new file.
  */
  public function move(FileModel $destination)
  {
     $result = false;
     if ($this->copy($destination))
     {
       $result = $this->delete();
			 if ($result == false)
			 {
				 Log::addError('Move can\'t remove file ' . $this->getFullPath());
			 }

       $this->resetStatus();
       $destination->resetStatus();
     }
     return $result;
  }

  /**
   * Deletes the file through wp_delete_file(), so WordPress' own filter
   * runs. Success means the file is gone afterwards, whether or not it
   * existed before (a missing file is logged as a warning).
   *
   * @return bool
   */
  /** Deletes current file
  * This uses the WP function since it has a filter that might be useful
  */
  public function delete()
  {
     if ($this->exists())
		 {
      \wp_delete_file($this->fullpath);  // delete file hook via wp_delete_file
		 }
		 else
		 {
			  Log::addWarn('Trying to remove non-existing file: ' . $this->getFullPath());
		 }

      if (! file_exists($this->fullpath))
      {
        $this->resetStatus();
        return true;
      }
      else {
				$writable = ($this->is_writable()) ? 'true' : 'false';
				Log::addWarn('File seems not removed - ' . $this->getFullPath() . ' (writable:' . $writable . ')');
        return false;
      }

  }

  /**
   * Reads the whole file. No existence or size check.
   *
   * @return string|false
   */
	public function getContents()
	{
			return file_get_contents($this->getFullPath());
	}

  /**
   * Returns the normalised absolute path, running setFileInfo() on first
   * use.
   *
   * @return string Empty string when the constructor received null.
   */
  public function getFullPath()
  {
		// filename here since fullpath is set unchecked in constructor, but might be a different take
    if (is_null($this->filename))
		{
      $this->setFileInfo();
		}

    return $this->fullpath;
  }

  /**
   * Returns the path exactly as given to the constructor, without any
   * normalisation or file system access.
   *
   * Only for high-volume listings (the directory filters use it) where
   * the caller already knows the path is a plain local file and does not
   * want to pay for processPath() per entry.
   *
   * @return string|null
   */
	// Testing this. Principle is that when the plugin is absolutely sure this is a file, not something remote, not something non-existing, get the fullpath without any check.
	// This function should *only* be used when processing mega amounts of files while not doing optimization or any processing.
	// So far, testing use for file Filter */
	public function getRawFullPath()
	{
			return $this->rawfullpath;
	}

  /**
   * Returns the file name with extension. Runs setFileInfo() on first use.
   *
   * @return string|null Null when the path is not a file.
   */
  public function getFileName()
  {
    if (is_null($this->filename))
      $this->setFileInfo();

    return $this->filename;
  }

  /**
   * Returns the file name without extension. Runs setFileInfo() on first
   * use.
   *
   * @return string|null Null when the path is not a file.
   */
  public function getFileBase()
  {
    if (is_null($this->filebase))
      $this->setFileInfo();

    return $this->filebase;
  }


  /**
   * Returns the lowercased extension. Runs setFileInfo() on first use.
   *
   * @return string|null '' when the file has no extension, null when the
   *                     path is not a file.
   */
  public function getExtension()
  {
    if (is_null($this->extension))
      $this->setFileInfo();

    return $this->extension;
  }

  /**
   * Returns the MIME type, determined on every call for existing local
   * files: wp_get_image_mime() first, then wp_check_filetype_and_ext()
   * for non-image files.
   *
   * @return string|false False for missing and virtual files.
   */
  public function getMime()
  {
    if (is_null($this->mime))
        $this->setFileInfo();

    if ($this->exists() && ! $this->is_virtual() )
    {
        $this->mime = wp_get_image_mime($this->fullpath);
				if (false === $this->mime)
				{
					 $image_data = wp_check_filetype_and_ext($this->getFullPath(), $this->getFileName());
					 if (is_array($image_data) && isset($image_data['type']) && strlen($image_data['type']) > 0)
					 {
						 $this->mime = $image_data['type'];
					 }

				}
    }
    else
       $this->mime = false;

    return $this->mime;
  }


  /**
   * Turns whatever the constructor received into an absolute local path.
   *
   * URLs go through UrlToPath(). In trusted mode the path is returned
   * untouched. Otherwise: a path outside open_basedir, and a path that
   * neither exists, nor sits in an existing directory, nor contains the
   * WordPress root, is treated as relative and resolved by
   * relativeToFullPath(). The result passes through the
   * `shortpixel/filesystem/processFilePath` filter with the original path
   * as second argument. Existence is not required.
   *
   * @param string $path Path or URL.
   * @return string|false False when the input is a URL of another site.
   */
  /* Internal function to check if path is a real path
  *  - Test for URL's based on http / https
  *  - Test if given path is absolute, from the filesystem root.
  * @param $path String The file path
  * @param String The Fixed filepath.
  */
  protected function processPath($path)
  {
    $original_path = $path;
    $fs = $this->getFS();

    if ($fs->pathIsUrl($path))
    {
      $path = $this->UrlToPath($path);
    }

    if ($path === false) // don't process further
      return false;

    //$path = wp_normalize_path($path);
		$abspath = $fs->getWPAbsPath();


		// Prevent file operation below if trusted.
		if (true === self::$TRUSTED_MODE)
		{
			 return $path;
		}

    // Check if some openbasedir is active.
    if (true === $this->fileIsRestricted($path))
    {
      $path = $this->relativeToFullPath($path);
    }

    if ( is_file($path) && ! is_dir($path) ) // if path and file exist, all should be okish.
    {
      return $path;
    }
		// If attempted file does not exist, but the file is in a dir that exists, that is good enough.
		elseif ( ! is_dir($path) && is_dir(dirname($path)) )
		{
			 return $path;
		}
		// If path is not in the abspath, it might be relative.
    elseif (strpos($path, $abspath->getPath()) === false)
    {
	    // if path does not contain basepath.
	    //$uploadDir = $fs->getWPUploadBase();
	    //$abspath = $fs->getWPAbsPath();

      $path = $this->relativeToFullPath($path);
    }
    $path = apply_filters('emr/filesystem/processFilePath', $path, $original_path);
    /* This needs some check here on malformed path's, but can't be test for existing since that's not a requirement.
    if (file_exists($path) === false) // failed to process path to something workable.
    {
    //  Log::addInfo('Failed to process path', array($path));
      $path = false;
    } */

    return $path;
  }

  /**
   * In trusted mode, pre-fills the status flags as if the file were an
   * existing, readable and writable image, and derives the MIME type from
   * the extension, so no file system call is made later. Runs from the
   * constructor before any file info is loaded, hence the direct
   * pathinfo() call to avoid recursion.
   *
   * @return void
   */
	protected function checkTrustedMode()
	{
		// When in trusted mode prevent filesystem checks as much as possible.
		if (true === self::$TRUSTED_MODE)
		{

				// At this point file info might not be loaded, because it goes w/ construct -> processpath -> urlToPath etc on virtual files. And called via getFileInfo.  Using any of the file info functions can trigger a loop.
				if (is_null($this->extension))
				{
						$extension = pathinfo($this->fullpath, PATHINFO_EXTENSION);
				}
				else {
					$extension = $this->getExtension();
				}

				$this->exists = true;
				$this->is_writable = true;
				$this->is_directory_writable = true;
				$this->is_readable = true;
				$this->is_file = true;
				// Set mime to prevent lookup in IsImage
				$this->mime = 'image/' . $extension;

				if (is_null($this->filesize))
				{
					$this->filesize = 0;
				}
		}

	}

  /**
   * Tells whether the path falls outside PHP's open_basedir, in which
   * case file functions would raise warnings; such a path is most likely
   * relative. Cached in $is_restricted (see the class @todo). The verdict
   * passes through `shortpixel/file/basedir_check` so hosts with symlinked
   * layouts can override a false positive.
   *
   * @param string $path Path to test.
   * @return bool
   */
  /** Check if path is allowed within openbasedir restrictions. This is an attempt to limit notices in file funtions if so.  Most likely the path will be relative in that case.
  * @param String Path as String
  */
  private function fileIsRestricted($path)
  {
     if (! is_null($this->is_restricted))
     {
        return $this->is_restricted;
     }

     $basedir = ini_get('open_basedir');

     if (false === $basedir || strlen($basedir) == 0)
     {
         return false;
     }

     $restricted = true;
     $basedirs = preg_split('/:|;/i', $basedir);

     foreach($basedirs as $basepath)
     {
          if (strpos($path, $basepath) !== false)
          {
             $restricted = false;
             break;
          }
     }

     // Allow this to be overridden due to specific server configs ( ie symlinks ) might get this flagged falsely.
     $restricted = apply_filters('emr/file/basedir_check', $restricted);

     $this->is_restricted = $restricted;
     return $restricted;
  }

  /**
   * Maps a URL of this site to a local path, or marks the file virtual.
   *
   * Compares scheme-less URLs. The site part is home_url(), or the
   * uploads base URL when the URL contains `/files/` (old WPMU layout,
   * where home_url() lacks that segment). A match is replaced by the
   * WordPress root from FileSystemController::getWPAbsPath(). Otherwise
   * the model becomes virtual and the `shortpixel/image/urltopath` filter
   * (false, URL, raw path) decides whether it exists: false means missing;
   * true, self::$VIRTUAL_REMOTE or self::$VIRTUAL_STATELESS mean it does,
   * the last one also writable.
   *
   * @param string $url URL to resolve.
   * @return string|false Local path, or false when the file is virtual.
   */
  /** Resolve an URL to a local path
  *  This partially comes from WordPress functions attempting the same
  * @param String $url  The URL to resolve
  * @return String/Boolean - False is this seems an external domain, otherwise resolved path.
  */
  private function UrlToPath($url)
  {
     //$uploadDir = wp_upload_dir();

		 // If files is present, high chance that it's WPMU old style, which doesn't have in home_url the /files/ needed to properly replace and get the filepath . It would result in a /files/files path which is incorrect.
		 if (strpos($url, '/files/') !== false)
		 {
			 $uploadDir = wp_upload_dir();
			 $site_url = str_replace(array('http:', 'https:'), '', $uploadDir['baseurl']);
		 }
		 else {
			 $site_url = str_replace('http:', '', home_url('', 'http'));
		 }

     $url = str_replace(array('http:', 'https:'), '', $url);
     $fs = $this->getFS();

     if (strpos($url, $site_url) !== false)
     {
       // try to replace URL for Path
       $abspath =  $this->getFS()->getWPAbsPath();
       $path = str_replace($site_url, rtrim($abspath->getPath(),'/'), $url);


       if (! $fs->pathIsUrl($path)) // test again.
       {
        return $path;
       }
     }

     $this->is_virtual = true;

		 /* This filter checks if some supplier will be able to handle the file when needed.
		 *   Use translate filter to correct filepath when needed.
		 * Return could be true, or fileModel virtual constant
		 */
     $result = apply_filters('emr/image/urltopath', false, $url, $this->getRawFullPath());

		 if ($result === false)
		 {
			 $this->exists = false;
			 $this->is_readable = false;
			 $this->is_file = false;
		 }
		 else {
			 $this->exists = true;
			 $this->is_readable = true;
			 $this->is_file = true;
		 }

		 // If return is a stateless server, assume that it's writable and all that.
		 if ($result === self::$VIRTUAL_STATELESS)
		 {
			  $this->is_writable = true;
				$this->is_directory_writable = true;
				$this->virtual_status = self::$VIRTUAL_STATELESS;
		 }
		 elseif ($result === self::$VIRTUAL_REMOTE)
		 {
			  $this->virtual_status = self::$VIRTUAL_REMOTE;
		 }

     return false; // seems URL from other server, use virtual mode.
  }

  /**
   * Resolves a path that does not contain the WordPress root.
   *
   * Returned unchanged when it exists, lies in PHP's upload_tmp_dir or
   * sys_get_temp_dir(), or already contains the uploads base directory
   * (uploads outside the WordPress tree). Then the path is tried under the
   * WordPress root and under the uploads base; when neither exists it is
   * appended to the WordPress root anyway, because the model allows files
   * that do not exist yet.
   *
   * @param string $path Possibly relative path.
   * @return string Absolute path, or the input when it is empty.
   */
  /** Tries to find the full path for a perceived relative path.
  *
  * Relative path is detected on basis of WordPress ABSPATH. If this doesn't appear in the file path, it might be a relative path.
  * Function checks for expections on this rule ( tmp path ) and returns modified - or not - path.
  * @param $path The path for the file_exists
  * @returns String The updated path, if that was possible.
  */
  private function relativeToFullPath($path)
  {
      $originalPath = $path; // for safe-keeping

      // A file with no path, can never be created to a fullpath.
      if (strlen($path) == 0)
        return $path;

      // if the file plainly exists, it's usable /**
      if (false === $this->fileIsRestricted($path) && file_exists($path))
      {
        return $path;
      }

      // Test if our 'relative' path is not a path to /tmp directory.

      // This ini value might not exist.
      $tempdirini = ini_get('upload_tmp_dir');
      if ( (strlen($tempdirini) > 0) && strpos($path, $tempdirini) !== false)
        return $path;

      $tempdir = sys_get_temp_dir();
      if ( (strlen($tempdir) > 0) && strpos($path, $tempdir) !== false)
        return $path;

      // Path contains upload basedir. This happens when upload dir is outside of usual WP.
      $fs = $this->getFS();
      $uploadDir = $fs->getWPUploadBase();
      $abspath = $fs->getWPAbsPath();

      if (strpos($path, $uploadDir->getPath()) !== false) // If upload Dir is feature in path, consider it ok.
      {
        return $path;
      }
      elseif (file_exists($abspath->getPath() . $path)) // If upload dir is abspath plus return path. Exceptions.
      {
        return $abspath->getPath() . $path;
      }
      elseif(file_exists($uploadDir->getPath() . $path)) // This happens when upload_dir is not properly prepended in get_attachment_file due to WP errors
      {
          return $uploadDir->getPath() . $path;
      }

      // this is probably a bit of a sharp corner to take.
      // if path starts with / remove it due to trailingslashing ABSPATH
      $path = ltrim($path, '/');
      $fullpath = $abspath->getPath() . $path;

      // We can't test for file_exists here, since file_model allows non-existing files.
      // Test if directory exists, perhaps. Otherwise we are in for a failure anyhow.
      //if (is_dir(dirname($fullpath)))
          return $fullpath;
      //else
      //    return $originalPath;
  }

  /**
   * Returns the permission bits (mode & 0777), cached.
   *
   * @return int
   */
	public function getPermissions()
  {
		if (is_null($this->permissions))
			$this->permissions = fileperms($this->getFullPath()) & 0777;

    return $this->permissions;
  }

  /**
   * Changes the file mode with chmod(), silently. The cached permissions
   * and status are not updated.
   *
   * @param int $permissions Octal mode, for example 0644.
   * @return void
   */
	// @tozo Lazy IMplementation / copy, should be brought in line w/ other attributes.
  public function setPermissions($permissions)
  {
    @chmod($this->fullpath, $permissions);
  }


  /**
   * Locale-independent pathinfo() for multibyte file names, taken from
   * PHPMailer. Every key is pre-filled with '' so callers get strings.
   *
   * @param string          $path    Path to split.
   * @param int|string|null $options One PATHINFO_* constant or its name
   *                                 for a single part, null for all.
   * @return array|string Array with dirname, basename, extension and
   *                      filename, or the requested part.
   */
  /** Fix for multibyte pathnames and pathinfo which doesn't take into regard the locale.
  * This snippet taken from PHPMailer.
  */
  private function mb_pathinfo($path, $options = null)
  {
        $ret = ['dirname' => '', 'basename' => '', 'extension' => '', 'filename' => ''];
        $pathinfo = [];
        if (preg_match('#^(.*?)[\\\\/]*(([^/\\\\]*?)(\.([^.\\\\/]+?)|))[\\\\/.]*$#m', $path, $pathinfo)) {
            if (array_key_exists(1, $pathinfo)) {
                $ret['dirname'] = $pathinfo[1];
            }
            if (array_key_exists(2, $pathinfo)) {
                $ret['basename'] = $pathinfo[2];
            }
            if (array_key_exists(5, $pathinfo)) {
                $ret['extension'] = $pathinfo[5];
            }
            if (array_key_exists(3, $pathinfo)) {
                $ret['filename'] = $pathinfo[3];
            }
        }
        switch ($options) {
            case PATHINFO_DIRNAME:
            case 'dirname':
                return $ret['dirname'];
            case PATHINFO_BASENAME:
            case 'basename':
                return $ret['basename'];
            case PATHINFO_EXTENSION:
            case 'extension':
                return $ret['extension'];
            case PATHINFO_FILENAME:
            case 'filename':
                return $ret['filename'];
            default:
                return $ret;
        }
    }

    /**
     * Limits var_dump() output to the path, name and main status flags.
     *
     * @return array
     */
    public function __debuginfo()
    {
       return [
          'fullpath' => $this->fullpath,
          'filename' => $this->filename,
          'filebase' => $this->filebase,
          'exists' => $this->exists,
          'is_writable' => $this->is_writable,
          'is_readable' => $this->is_readable,
					'is_virtual' => $this->is_virtual,
       ];
    }


} // FileModel Class
