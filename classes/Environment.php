<?php
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

class Environment
{

  protected static $instance;

  protected $permissions = array(
    'general_cap' => 'edit_others_posts',
    'user_cap' => 'edit_posts',
    'general_roles' => false,
    'user_roles' => false,
  );


  // Init
  public function __construct()
  {
      $this->setCaps();
  }


  public static function getInstance()
  {
    if (is_null(self::$instance))
        self::$instance = new static();

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

  public function getPermission($name)
  {
      if ('user' === $name)
      {
         $cap = $this->permissions['user_cap'];
      }
      if ('general' === $name)
      {
         $cap = $this->permissions['general_cap'];
      }

      return $cap;
  }


  protected function setCaps()
  {
      if (EMR_CAPABILITY !== false) {
          if (is_array(EMR_CAPABILITY)) {
              $this->permissions['general_cap'] = EMR_CAPABILITY[0];
              $this->permissions['user_cap'] = EMR_CAPABILITY[1];

          } else {
              $this->permissions['general_cap'] = EMR_CAPABILITY;
          }
      }
  }

  public function checkUserPermission($author_id)
  {

    $general_cap = $this->getPermission('general');
    $user_cap = $this->getPermission('user');

     if ($general_cap === false && $user_cap === false) {
         if (current_user_can('edit_others_posts', $post_id)  === true) {
                         return true;
         }
     } elseif (current_user_can($general_cap)) {
         return true;
     } elseif (current_user_can($user_cap) && $author_id == get_current_user_id()) {
         return true;
     }

     return false;
  }



}
