<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

class Environment extends Base
{

  protected static $instance;

  public static function getInstance()
  {
    if (is_null(self::$instance))
        self::$instance = new Environment();

    return self::$instance;
  }

  public function plugin_active($name)
  {
     switch($name)
     {
        case 'wpml':
          $plugin = 'sitepress-multilingual-cms/sitepress.php';
        break;
        case 's3-offload':
          $plugin = 'amazon-s3-and-cloudfront/wordpress-s3.php';
        break;
        case 'shortpixel':
          $plugin = 'shortpixel-image-optimiser/wp-shortpixel.php';
        break;
        case 'shortpixel-ai':
          $plugin = 'shortpixel-adaptive-images/short-pixel-ai.php';
        break;
        default:
          $plugin = 'none';
        break;
     }

     if (!function_exists('is_plugin_active')) {
      include_once(ABSPATH . 'wp-admin/includes/plugin.php');
     }

     return \is_plugin_active($plugin);
  }

  public function canInstallPlugins()
  {
    return apply_filters('emr/upsell', current_user_can('install_plugins'));
  }

  public function isOffLoadActive()
  {
     return $this->plugin_active('s3-offload');
  }


}
