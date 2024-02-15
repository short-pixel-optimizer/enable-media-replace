<?php
namespace EnableMediaReplace;
/**
 * Plugin Name: Enable Media Replace
 * Plugin URI: https://wordpress.org/plugins/enable-media-replace/
 * Description: Enable replacing media files by uploading a new file in the "Edit Media" section of the WordPress Media Library.
 * Version: 4.1.5
 * Author: ShortPixel
 * Author URI: https://shortpixel.com
 * GitHub Plugin URI: https://github.com/short-pixel-optimizer/enable-media-replace
 * Text Domain: enable-media-replace
 * Dual licensed under the MIT and GPL licenses:
 * License URI: http://www.opensource.org/licenses/mit-license.php
 * License URI: http://www.gnu.org/licenses/gpl.html
 */

/**
 * Main Plugin file
 * Set action hooks and add shortcode
 *
 * @author      ShortPixel  <https://shortpixel.com>
 * @copyright   ShortPixel 2018-2020
 * @package     WordPress
 * @subpackage  enable-media-replace
 *
 */

define( 'EMR_VERSION', '4.1.5' );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/* Not sure why we define this?
if(!defined("S3_UPLOADS_AUTOENABLE")) {
	define('S3_UPLOADS_AUTOENABLE', true);
} */

if ( ! defined( 'EMR_ROOT_FILE' ) ) {
	  define( 'EMR_ROOT_FILE', __FILE__ );
}

if ( ! defined( 'SHORTPIXEL_AFFILIATE_CODE' ) ) {
	define( 'SHORTPIXEL_AFFILIATE_CODE', 'VKG6LYN28044' );
}

/** Usage:
* Define in wp-config.php
* // User must have this capability to replace all
* define('EMR_CAPABILITY' ,'edit_upload_all' );
* // User must have first capability to replace all OR second capability to replace only own files
* define('EMR_CAPABILITY' ,array('edit_upload_all', 'edit_upload_user') );
*
*
**/
if ( ! defined( 'EMR_CAPABILITY' ) ) {
	define( 'EMR_CAPABILITY', false );
}

/* if (! defined('EMR_CAPABILITY_USERONLY'))
  define('EMR_CAPABILITY_USERONLY', false); */


	$plugin_path = plugin_dir_path( EMR_ROOT_FILE );

	require_once( $plugin_path . 'build/shortpixel/autoload.php' );

	$loader = new Build\PackageLoader();
	$loader->setComposerFile($plugin_path . 'classes/plugin.json');
	$loader->load($plugin_path);

function EMR()
{
	if (class_exists('\EnableMediaReplace\PluginPro'))
	{
		return PluginPro::get();
	}
	else {
		return Plugin::get();
	}

}


Plugin::checkLogger();

add_action('plugins_loaded', 'EnableMediaReplace\EMR');

//register_uninstall_hook( __FILE__, '\EnableMediaReplace\emr_uninstall' );
register_deactivation_hook( __FILE__,  array('\EnableMediaReplace\InstallHelper','deactivatePlugin') );
register_uninstall_hook(__FILE__,  array('\EnableMediaReplace\InstallHelper','uninstallPlugin') );
