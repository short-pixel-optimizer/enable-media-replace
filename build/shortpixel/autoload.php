<?php

 

          if ( ! defined( "ABSPATH" ) ) {
          exit; // Exit if accessed directly.
          }
         require_once  (__DIR__  . "/PackageLoader.php");
         $loader = new EnableMediaReplace\Build\PackageLoader();
         $loader->load(__DIR__);
         