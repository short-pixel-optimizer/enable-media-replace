<?php
namespace EnableMediaReplace\FileSystem\Model\File;
use EnableMediaReplace\ShortpixelLogger\ShortPixelLogger as Log;

/* Model for Directories
*
* For all low-level operations on directories
* Private model of FileSystemController. Please get your directories via there.
*
*/

class DirectoryModel
{
  // Directory info
  protected $path;
  protected $name;

  // Directory status
  protected $exists = null;
  protected $is_writable = null;
  protected $is_readable = null;
  protected $is_virtual = null;
  protected $is_restricted = false;

  protected $fields = array();

  protected $new_directory_permission = 0755;

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

	private function getFS()
	{
		return new \EnableMediaReplace\FileSystem\Controller\FileSystemController();
	}


  public function __toString()
  {
    return (string) $this->path;
  }

  /** Returns path *with* trailing slash
  *
  * @return String Path with trailing slash
  */
  public function getPath()
  {
    return $this->path;
  }

  public function getModified()
  {
    return filemtime($this->path);
  }

  /**
  * Get basename of the directory. Without path
  */
  public function getName()
  {
    return $this->name;
  }

  public function exists()
  {
		if (is_null($this->exists))
		{
			$this->exists = file_exists($this->path) && is_dir($this->path);
		}
    return $this->exists;
  }

  public function is_writable()
  {
		if (is_null($this->is_writable))
		{
    	$this->is_writable = is_writable($this->path);
		}
    return $this->is_writable;
  }


  public function is_readable()
  {
		if (is_null($this->is_readable))
		{
     $this->is_readable = is_readable($this->path);
	  }

    return $this->is_readable;
  }

  public function is_virtual()
  {
      return $this->is_virtual;
  }
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

     return $restricted;
  }


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

  public function delete()
  {
     return rmdir($this->getPath());
  }

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
