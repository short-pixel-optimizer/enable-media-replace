<?php
/**
 * Directory model of the FileSystem module.
 *
 * @package EnableMediaReplace\FileSystem
 */
namespace EnableMediaReplace\FileSystem\Model\File;
use EnableMediaReplace\ShortpixelLogger\ShortPixelLogger as Log;

/**
 * One directory, existing or not, with lazy status checks and the
 * low-level operations on it.
 *
 * Obtain instances through FileSystemController::getDirectory() or
 * FileModel::getFileDir(); `new DirectoryModel($path)` is equivalent. The
 * constructor normalises the path once: a URL marks the model virtual, a
 * path outside PHP's open_basedir marks it restricted (and non-existent),
 * a path that ends in a file name is reduced to its directory, a path that
 * does not exist is passed through realpath() in case it is a symlink, and
 * the result is stored with a trailing slash. The name is taken with
 * SplFileInfo because basename() mishandles non-latin names.
 *
 * `exists`, `is_writable` and `is_readable` are checked on first use and
 * cached for the life of the object; check() resets them after creating
 * the directory. getFiles() and getSubDirectories() build new models for
 * every entry, so prefer FileSystemController::getFilesRecursive() with
 * filters for large trees.
 *
 * Hooks fired by this class:
 * - filter `shortpixel/file/basedir_check` (fileIsRestricted())
 *
 * @todo check() calls `chmod($this->path, $this->permission)` when the
 *       directory exists but is not writable; the property is named
 *       $new_directory_permission, so chmod() receives null and sets mode
 *       0 before the following chmod() restores 0755. It then re-reads
 *       the cached is_writable value, so the method reports failure even
 *       when the chmod helped. Suggested fix: use $new_directory_permission
 *       and reset $is_writable before testing again.
 * @todo getPermissionRecursive() calls exists() on the result of
 *       getParent(), which is false at the file system root. Suggested
 *       fix: return false when there is no parent.
 */
/* Model for Directories
*
* For all low-level operations on directories
* Private model of FileSystemController. Please get your directories via there.
*
*/

class DirectoryModel
{
  // Directory info

  /**
   * Full path with trailing slash.
   *
   * @var string
   */
  protected $path;

  /**
   * Last path segment (the directory's own name).
   *
   * @var string
   */
  protected $name;

  // Directory status

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
   * Cached result of is_readable(); null until checked.
   *
   * @var bool|null
   */
  protected $is_readable = null;

  /**
   * True when the path is a URL. Set in the constructor.
   *
   * @var bool|null
   */
  protected $is_virtual = null;

  /**
   * True when the path is outside open_basedir. Set in the constructor.
   *
   * @var bool
   */
  protected $is_restricted = false;

  /**
   * Unused.
   *
   * @var array
   */
  protected $fields = array();

  /**
   * Mode for directories created by check(), when the parent's mode
   * cannot be read.
   *
   * @var int
   */
  protected $new_directory_permission = 0755;

  /**
   * Normalises the path and records what can be known without touching
   * the directory; see the class description for the steps.
   *
   * @param string $path Full path to a directory or to a file in it.
   */
  /** Creates a directory model object. DirectoryModel directories don't need to exist on FileSystem
  *
  * When a filepath is given, it  will remove the file part.
  * @param $path String The Path
  */
  public function __construct($path)
  {
   //   $path = wp_normalize_path($path);
      $fs = $this->getFS();

      if ($fs->pathIsUrl($path))
      {
        $pathinfo = pathinfo($path);
        if (isset($pathinfo['extension'])) // check if this is a file, remove the file information.
        {
          $path = $pathinfo['dirname'];
        }

        $this->is_virtual = true;
        $this->is_readable = true; // assume
        $this->exists = true;
      }

      if( false == $this->is_virtual() && true === $this->fileIsRestricted($path) )
      {
         $this->exists = false;
         $this->is_readable = false;
         $this->is_writable = false;
         $this->is_restricted = true;
      }
      else {
        $this->is_virtual = false;
      }

      if (false === $this->is_virtual() &&
          false === $this->is_restricted &&
          false === is_dir($path)
          ) // path is wrong, *or* simply doesn't exist.
      {
        /* Test for file input.
        * If pathinfo is fed a fullpath, it rips of last entry without setting extension, don't further trust.
        * If it's a file extension is set, then trust.
        */
        $pathinfo = pathinfo($path);

        if (isset($pathinfo['extension']))
        {
          $path = $pathinfo['dirname'];
        }
        elseif (is_file($path))
          $path = dirname($path);
      }

      if (false === $this->is_virtual() &&
          false === $this->is_restricted &&
          false === is_dir($path)
          ) // path is wrong, *or* simply doesn't exist.
      {
        /* Check if realpath improves things. We support non-existing paths, which realpath fails on, so only apply on result.
        Moved realpath to check after main pathinfo is set. Reason is that symlinked directories which don't include the WordPress upload dir will start to fail in file_model on processpath ( doesn't see it as a wp path, starts to try relative path). Not sure if realpath should be used anyhow in this model /BS
        */
        $testpath = realpath($path);
        if ($testpath)
          $path = $testpath;
      }

      $this->path = trailingslashit($path);

      // Basename doesn't work properly on non-latin ( cyrillic, greek etc )  directory names, returning the parent path instead.
      $dir = new \SplFileInfo($path);
      //basename($this->path);
      $this->name = $dir->getFileName();

		// Off the keep resources  / not sure if needed.
    //  if (file_exists($this->path))
    //  {
    //    $this->exists();
    //  $this->is_writable();
    //    $this->is_readable();
    //  }
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
   * Returns the path with trailing slash.
   *
   * @return string
   */
  public function __toString()
  {
    return (string) $this->path;
  }

  /**
   * Returns the full path, with trailing slash.
   *
   * @return string
   */
  /** Returns path *with* trailing slash
  *
  * @return String Path with trailing slash
  */
  public function getPath()
  {
    return $this->path;
  }

  /**
   * Returns the modification time of the directory.
   *
   * @return int|false Unix timestamp, false when it cannot be read.
   */
  public function getModified()
  {
    return filemtime($this->path);
  }

  /**
   * Returns the directory's own name, without the path.
   *
   * @return string
   */
  /**
  * Get basename of the directory. Without path
  */
  public function getName()
  {
    return $this->name;
  }

  /**
   * Tells whether the directory exists. Cached after the first check;
   * always true for virtual and false for restricted directories.
   *
   * @return bool
   */
  public function exists()
  {
		if (is_null($this->exists))
		{
			$this->exists = file_exists($this->path) && is_dir($this->path);
		}
    return $this->exists;
  }

  /**
   * Tells whether the directory is writable. Cached after the first check.
   *
   * @return bool
   */
  public function is_writable()
  {
		if (is_null($this->is_writable))
		{
    	$this->is_writable = is_writable($this->path);
		}
    return $this->is_writable;
  }


  /**
   * Tells whether the directory is readable. Cached after the first check.
   *
   * @return bool
   */
  public function is_readable()
  {
		if (is_null($this->is_readable))
		{
     $this->is_readable = is_readable($this->path);
	  }

    return $this->is_readable;
  }

  /**
   * Tells whether the model was created from a URL.
   *
   * @return bool|null Null only while the constructor is still running.
   */
  public function is_virtual()
  {
      return $this->is_virtual;
  }

  /**
   * Returns the path relative to the WordPress installation.
   *
   * The installation directory is get_home_path(), or the content root
   * from FileSystemController::getWPAbsPath() when that is `/`. When
   * stripping it does not leave an existing directory, the path is rebuilt
   * from its last segments upwards until one exists under the install
   * directory (reverseConstructPath()) and, failing that, cut at
   * `wp-content` or `uploads` (constructUsualDirectories()).
   *
   * @return string|false Relative path with trailing slash and without
   *                      leading slash, or false when nothing was stripped.
   */
  /** Try to obtain the path, minus the installation directory.
  * @return Mixed False if this didn't work, Path as string without basedir if it did. With trailing slash, without starting slash.
  */
  public function getRelativePath()
  {
		// not used anywhere in directory.
    // $upload_dir = wp_upload_dir(null, false);

     $install_dir = get_home_path();
     if($install_dir == '/') {
       $install_dir = $this->getFS()->getWPAbsPath();
     }

     $install_dir = trailingslashit($install_dir);

     $path = $this->getPath();
     // try to build relativePath without first slash.
     $relativePath = str_replace($install_dir, '', $path);

     if (is_dir( $install_dir . $relativePath) === false)
     {
        $test_path = $this->reverseConstructPath($path, $install_dir);
        if ($test_path !== false)
        {
            $relativePath = $test_path;
        }
        else {
           if($test_path = $this->constructUsualDirectories($path))
           {
             $relativePath = $test_path;
           }

        }
     }

     // If relativePath has less amount of characters, changes are this worked.
     if (strlen($path) > strlen($relativePath))
     {
       return ltrim(trailingslashit($relativePath), '/');
     }
     return false;
  }


  /**
   * Finds the longest tail of $path that exists under $install_path, by
   * adding segments from the end one at a time.
   *
   * @param string $path         Absolute path.
   * @param string $install_path Installation directory with trailing slash.
   * @return string|false Relative tail without slashes at either end, or
   *                      false when no tail exists there.
   */
  private function reverseConstructPath($path, $install_path)
  {
    // Array value to reset index
    $pathar = array_values(array_filter(explode('/', $path)));
    $parts = array();

    if (is_array($pathar))
    {
      // Reverse loop the structure until solid ground is found.
      for ($i = (count($pathar)); $i > 0; $i--)
      {
        $parts[]  = $pathar[$i - 1];
        $testpath = implode('/', array_reverse($parts));
        // if the whole thing exists
        if (is_dir($install_path . $testpath) === true)
        {
          return $testpath;
        }
      }
    }
    return false;

  }

  /**
   * Tells whether a path falls outside PHP's open_basedir, in which case
   * file functions would raise warnings. Without open_basedir nothing is
   * restricted. The result passes through `shortpixel/file/basedir_check`
   * so hosts with symlinked layouts can override a false positive.
   *
   * @param string $path Path to test.
   * @return bool
   */
  /** Check if path is allowed within openbasedir restrictions. This is an attempt to limit notices in file funtions if so.  Most likely the path will be relative in that case.
  * @param String Path as String
  */
  private function fileIsRestricted($path)
  {

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

     return $restricted;
  }


  /**
   * Cuts a path at its `wp-content` segment, or else at `uploads`, as a
   * last resort for getRelativePath().
   *
   * @param string $path Absolute path.
   * @return string|false Relative path from that segment on, or false.
   */
  /* Last Resort function to just reduce path to various known WorPress paths. */
  private function constructUsualDirectories($path)
  {
    $pathar = array_values(array_filter(explode('/', $path))); // array value to reset index
    $testpath = false;
    if ( ($key = array_search('wp-content', $pathar)) !== false)
    {
        $testpath = implode('/', array_slice($pathar, $key));
    }
    elseif ( ($key = array_search('uploads', $pathar)) !== false)
    {
        $testpath = implode('/', array_slice($pathar, $key));
    }

    return $testpath;
  }

  /**
   * Makes sure the directory exists and, optionally, is writable.
   *
   * Creates the directory recursively with the mode of the nearest
   * existing ancestor (0755 when that cannot be read) and resets the
   * cached status. When it exists but is not writable and
   * $check_writable is set, tries to fix the mode; see the class @todo.
   * Failures are logged.
   *
   * @param bool $check_writable True to also require write access.
   * @return bool True when the directory exists (and is writable, when
   *              required).
   */
  /** Checks the directory into working order
  * Tries to create directory if it doesn't exist
  * Tries to fix file permission if writable is needed
  * @param $check_writable Boolean Directory should be writable
  */
  public function check($check_writable = false)
  {
		 $permission = $this->getPermissionRecursive();

  	 if ($permission === false) // if something wrong, return to default.
		 {
		 		$permission = $this->new_directory_permission;
		 }

     if (! $this->exists())
     {

        Log::addInfo('Directory does not exists. Try to create recursive ' . $this->path . ' with '  . $permission);


        $result = @mkdir($this->path, $permission , true);
				chmod ($this->path, $permission );

        if (! $result)
        {
          $error = error_get_last();
          Log::addWarn('MkDir failed: ' . $error['message'], array($error));
        }
				// reset.
				$this->exists = null;
				$this->is_readable = null;
				$this->is_writable = null;

     }
     if ($this->exists() && $check_writable && ! $this->is_writable())
     {
       chmod($this->path, $this->permission);
			 if (! $this->is_writable()) // perhaps parent permission is no good.
			 {
			 		chmod($this->path, $this->new_directory_permission);
			 }
     }

     if (! $this->exists())
     {
       Log::addInfo('Directory does not exist :' . $this->path);
       return false;
     }
    if ($check_writable && !$this->is_writable())
    {
        Log::addInfo('Directory not writable :' . $this->path);
        return false;
    }
    return true;
  }

  /**
   * Returns the mode of the nearest existing ancestor, used as the mode
   * for directories created by check().
   *
   * @return int|false fileperms() value (including type bits) or false.
   */
	public function getPermissionRecursive()
	{
		 $parent = $this->getParent();
		  if (! $parent->exists())
			{
				 return $parent->getPermissionRecursive();
			}
			else
			{
				 return $parent->getPermissions();
			}

	}

  /**
   * Lists the files directly in the directory as FileModels.
   *
   * Entries are matched with case-insensitive substring tests on the raw
   * path, so filters work on file names as well as on directory parts.
   *
   * @param array $args {
   *     Optional filters; an entry that is null is not applied.
   *
   *     @type int      $date_newer    Keep files modified at or after this
   *                                   Unix timestamp.
   *     @type string[] $exclude_files Drop files whose path contains any
   *                                   of these strings.
   *     @type string[] $include_files Keep only files whose path contains
   *                                   at least one of these strings.
   * }
   * @return FileModel[]|false False when the directory does not exist or
   *                           is not readable.
   */
  /* Get files from directory
  * @returns Array|boolean Returns false if something wrong w/ directory, otherwise a files array of FileModel Object.
  */
  public function getFiles($args = array())
  {

    $defaults = array(
        'date_newer' => null,
        'exclude_files' => null,
				'include_files' => null,
    );
    $args = wp_parse_args($args, $defaults);

    // if all filters are set to null, so point in checking those.
    $has_filters = (count(array_filter($args)) > 0) ? true : false;

    if ( ! $this->exists() || ! $this->is_readable() )
      return false;

    $fileArray = array();

    if ($handle = opendir($this->path)) {
        while (false !== ($entry = readdir($handle))) {
            if ( ($entry != "." && $entry != "..") && ! is_dir($this->path . $entry) ) {


								$fileObj = new FileModel($this->path . $entry);
								if ($has_filters)
								{
									 if ($this->fileFilter($fileObj,$args) === false)
									 {
									 	$fileObj = null;
								 	 }
								}

								if (! is_null($fileObj))
                	$fileArray[] = $fileObj;
            }
        }
        closedir($handle);
    }

/*
    if ($has_filters)
    {
      $fileArray = array_filter($fileArray, function ($file) use ($args) {
           return $this->fileFilter($file, $args);
       } );
    } */
    return $fileArray;
  }

  /**
   * Applies the getFiles() filters to one file.
   *
   * @param FileModel $file File to test.
   * @param array     $args Parsed filter arguments.
   * @return bool True to keep the file.
   */
  // @return boolean true if it should be kept in array, false if not.
  private function fileFilter(FileModel $file, $args)
  {
     $filter = true;

		 if (! is_null($args['include_files']))
		 {
			  foreach($args['include_files'] as $inc)
				{
						// If any in included is true, filter is good for us.
					 $filter = false;
           if (strpos( strtolower($file->getRawFullPath()), strtolower($inc) ) !== false)
					 {
             $filter = true;
						 break;
					 }
				}
		 }
     if (! is_null($args['date_newer']))
     {
       $modified = $file->getModified();
       if ($modified < $args['date_newer'] )
          $filter = false;
     }
     if (! is_null($args['exclude_files']))
     {
        foreach($args['exclude_files'] as $ex)
        {
           if (strpos( strtolower($file->getRawFullPath()), strtolower($ex) ) !== false)
             $filter = false;
        }
     }

     return $filter;
  }

  /**
   * Lists the readable subdirectories as DirectoryModels, resolved through
   * their real path. The isDot() test runs first so that open_basedir does
   * not complain about `..`.
   *
   * The branch for 'EnableMediaReplace\Model\File\DirectoryOtherMediaModel' is a
   * leftover from the plugin that this module was extracted from and
   * never matches here.
   *
   * @return DirectoryModel[]|false False when the directory does not exist
   *                                or is not readable.
   */
  /** Get subdirectories from directory
  * * @returns Array|boolean Returns false if something wrong w/ directory, otherwise a files array of DirectoryModel Object.
  */
  public function getSubDirectories()
  {

    if (! $this->exists() || ! $this->is_readable())
    {
      return false;
    }

    $dirIt = new \DirectoryIterator($this->path);
    $dirArray = array();
    foreach ($dirIt as $fileInfo)
    { // IsDot must go first here, or there is possiblity to run into openbasedir restrictions.
       if (! $fileInfo->isDot() && $fileInfo->isDir() && $fileInfo->isReadable())
       {
				 if ('EnableMediaReplace\Model\File\DirectoryOtherMediaModel' == get_called_class())
				 {
				 	$dir = new DirectoryOtherMediaModel($fileInfo->getRealPath());
				 }
				 else
				 {
         	$dir = new DirectoryModel($fileInfo->getRealPath());
				 }

         if ($dir->exists())
            $dirArray[] = $dir;
       }

    }
    return $dirArray;
  }

  /**
   * Tells whether this directory lies inside another one. A directory is
   * not a subfolder of itself. Pure string comparison of the paths.
   *
   * @param DirectoryModel $dir Candidate parent.
   * @return bool
   */
  /** Check if this dir is a subfolder
  * @param DirectoryModel The directoryObject that is tested as the parent */
  public function isSubFolderOf(DirectoryModel $dir)
  {
    // the same path, is not a subdir of.
    if ($this->getPath() === $dir->getPath())
      return false;

    // the main path must be followed from the beginning to be a subfolder.
    if (strpos($this->getPath(), $dir->getPath() ) === 0)
    {
      return true;
    }
    return false;
  }

  /**
   * Sums the sizes of all files in the tree. Recursive and expensive;
   * use sparingly.
   *
   * @return int Bytes; 0 when the directory cannot be listed.
   */
  //** Note, use sparingly, recursive function
  public function getFolderSize()
  {
        $size = 0;
        $files = $this->getFiles();

        // GetFiles can return Boolean false on missing directory.
        if (! is_array($files))
        {
           return $size;
        }

        foreach($files as $fileObj)
        {
            $size += $fileObj->getFileSize();
        }
        unset($files); //attempt at performance.

        $subdirs = $this->getSubDirectories();

        foreach($subdirs as $subdir)
        {
             $size += $subdir->getFolderSize();
        }

        return $size;
  }

  /**
   * Returns the parent directory.
   *
   * @return DirectoryModel|false False at the file system root.
   */
  /** Get this paths parent */
  public function getParent()
  {
      $path = $this->getPath();
      $parentPath = dirname($path);

      if ($path === $parentPath)
      {
         return false;
      }
      $parentDir = new DirectoryModel($parentPath);

      return $parentDir;
  }

  /**
   * Returns the raw fileperms() value of the directory. Logs a warning and
   * returns false when the directory does not exist.
   *
   * @return int|false
   */
	public function getPermissions()
	{
			if (! $this->exists())
			{
				 Log::addWarning('Directory not existing (fileperms): '. $this->getPath() );
				 return false;
			}
		  $perms = fileperms($this->getPath());

			if ($perms !== false)
			{
				return $perms;
			}
			else
				return false;

	}

  /**
   * Removes the directory, which must be empty. The cached status is not
   * reset.
   *
   * @return bool
   */
  public function delete()
  {
     return rmdir($this->getPath());
  }

  /**
   * Deletes the directory with everything in it.
   *
   * Refuses when the directory does not exist, is not writable, or is not
   * inside the uploads base directory; the last rule is the guard against
   * wiping something unintended. Used to clear backup folders.
   *
   * @return false|void False when refused, nothing otherwise.
   */
  /** This will try to remove the whole structure. Use with care.
  *  This is mostly used to clear the backups.
  */
  public function recursiveDelete()
  {
       if (! $this->exists() || ! $this->is_writable())
         return false;

       // This is a security measure to prevent unintended wipes.
       $wpdir = $this->getFS()->getWPUploadBase();
       if (! $this->isSubFolderOf($wpdir))
        return false;

       $files = $this->getFiles();
       $subdirs = $this->getSubDirectories();

       foreach($files as $file)
        $file->delete();

       foreach($subdirs as $subdir)
          $subdir->recursiveDelete();

        $this->delete();

  }

}
