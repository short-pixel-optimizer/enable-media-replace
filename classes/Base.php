<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace\FileSystem\Controller\FileSystemController as FileSystem;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

class Base
{

    public function emr()
    {
       if (class_exists('EnableMediaReplace\PluginPro'))
       {
          return PluginPro::get();
       }
       else {
          return Plugin::get();
       }
    }

    public function filesystem()
    {
       return new FileSystem();
    }

    public function env()
    {
       return Environment::getInstance();
    }
}
