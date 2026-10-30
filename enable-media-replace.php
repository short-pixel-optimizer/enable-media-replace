<?php
/**
 * Plugin Name: Enable Media Replace
 * Plugin URI: https://shortpixel.com
 * Description: Enable replacing media files by uploading a new file in the "Edit Media" section of the WordPress Media Library.
 * Version: 4.2.3
 * Author: ShortPixel
 * Author URI: https://shortpixel.com
 * GitHub Plugin URI: https://github.com/short-pixel-optimizer/enable-media-replace
 * Text Domain: enable-media-replace
 * Requires PHP: 7.4
 * Dual licensed under the MIT and GPL licenses:
 * License URI: http://www.opensource.org/licenses/mit-license.php
 * License URI: http://www.gnu.org/licenses/gpl.html
 */

/**
 * Main Plugin file
 *
 * Bootstrap for Enable Media Replace. In order, this file:
 * - defines EMR_VERSION, EMR_ROOT_FILE and EMR_CAPABILITY (see below);
 * - requires the generated autoloader of the bundled ShortPixel modules
 *   (build/shortpixel/autoload.php: replacer, filesystem, log, notices);
 * - requires every class file under classes/ explicitly, because classes/ has
 *   no autoloader of its own;
 * - defines and calls emr(), which creates the EnableMediaReplacePlugin
 *   singleton; its constructor hooks `init` and `admin_init`, where the
 *   shortcode, the admin UI and the AJAX endpoints are registered;
 * - registers the deactivation and uninstall callbacks on InstallHelper.
 *
 * Requiring classes/ajax.php also instantiates EnableMediaReplace\Ajax at
 * require time (see that file).
 *
 * @author      ShortPixel  <https://shortpixel.com>
 * @copyright   ShortPixel 2018-2020
 * @package     WordPress
 * @subpackage  enable-media-replace
 *
 * The supported floor is PHP 7.4 (`Requires PHP` in this header and in
 * readme.txt, `php` in composer.json). The code still carries shims from the
 * PHP 5.6 and WordPress 4.x era; each one is marked with a @todo where it is
 * dead on that floor.
 *
 * @todo The plugin header declares no `Requires at least` field; readme.txt
 *       declares WordPress 4.9.7. Add the field so WordPress enforces it.
 * @todo The classes/ files are loaded with explicit require_once calls; replace
 *       them with a PSR-4 autoloader (also noted at the require block).
 */

define( 'EMR_VERSION', '4.2.3' );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


if ( ! defined( 'EMR_ROOT_FILE' ) ) {
	  define( 'EMR_ROOT_FILE', __FILE__ );
}


/** Usage:
* Define in wp-config.php
* // User must have this capability to replace all
* define('EMR_CAPABILITY' ,'edit_upload_all' );
* // User must have first capability to replace all OR second capability to replace only own files
* define('EMR_CAPABILITY' ,array('edit_upload_all', 'edit_upload_user') );
*
* EMR_CAPABILITY accepts three shapes:
* - false (default): EMR loads its UI for users with `upload_files`, and
*   EnableMediaReplacePlugin::checkImagePermission() grants access per
*   attachment through current_user_can('edit_post', $id).
* - string: EMR loads its UI only for users with that capability, and a user
*   holding it may replace every attachment (no per-post check).
* - array( general_cap, own_files_cap ): EMR loads its UI for users holding
*   either capability; general_cap grants every attachment, own_files_cap
*   grants only attachments whose post_author is the current user.
* EnableMediaReplacePlugin::runtime() reads the constant on `init`.
* The hidden admin page itself is always registered with `upload_files`
* (see EnableMediaReplacePlugin::menu()).
*
**/
if ( ! defined( 'EMR_CAPABILITY' ) ) {
	define( 'EMR_CAPABILITY', false );
}

/* if (! defined('EMR_CAPABILITY_USERONLY'))
  define('EMR_CAPABILITY_USERONLY', false); */

$plugin_path = plugin_dir_path( EMR_ROOT_FILE );

require_once( $plugin_path . 'build/shortpixel/autoload.php' );

// PSR-4 autoloader for the plugin's own classes (classes/plugin.json maps
// the EnableMediaReplace namespace to classes/). Only files whose path
// matches their class name are reachable this way: the new Bulk code. The
// older files below keep their explicit require_once because their file
// names do not match their class names.
$emr_loader = new EnableMediaReplace\Build\PackageLoader();
$emr_loader->setComposerFile( $plugin_path . 'classes/plugin.json' );
$emr_loader->load( untrailingslashit( $plugin_path ) );
require_once( $plugin_path . 'classes/compat.php' );
//require_once( $plugin_path . 'classes/replacer.php' );
require_once( $plugin_path . 'classes/uihelper.php' );
require_once( $plugin_path . 'classes/Helper/envHelper.php' );

require_once( $plugin_path . 'classes/cache.php' );
require_once( $plugin_path . 'classes/api.php' );
require_once( $plugin_path . 'classes/ApiKeyManager.php' );
require_once( $plugin_path . 'classes/ajax.php' );
require_once( $plugin_path . 'classes/emr-plugin.php' );
require_once( $plugin_path . 'classes/installHelper.php' );

// Legacy files: names do not match PSR-4, see the PackageLoader call above.
require_once( $plugin_path . 'classes/Controller/ReplaceController.php');
require_once( $plugin_path . 'classes/Controller/RemoteNoticeController.php');

require_once( $plugin_path . 'classes/ViewController.php');
require_once( $plugin_path . 'classes/ViewController/UploadViewController.php');
require_once( $plugin_path . 'classes/ViewController/ReplaceViewController.php');
require_once( $plugin_path . 'classes/ViewController/RemoveBackgroundViewController.php');
require_once( $plugin_path . 'classes/ViewController/UpscaleViewController.php');

require_once( $plugin_path . 'classes/externals.php' );
require_once( $plugin_path . 'classes/external/elementor.php' );
require_once( $plugin_path . 'classes/external/wpbakery.php' );
require_once( $plugin_path . 'classes/external/upsell_installer.php' );
require_once( $plugin_path . 'classes/external/siteorigin.php' );
require_once( $plugin_path . 'classes/external/wp-offload.php' );


/**
 * Global accessor for the plugin singleton.
 *
 * Called once at load time to create the instance (which hooks `init` and
 * `admin_init`), and from everywhere else as `\emr()` to reach the plugin's
 * helpers (uiHelper(), env(), filesystem(), checkImagePermission(),
 * useFeature()). Each call also re-applies the debug log path, see
 * EnableMediaReplacePlugin::get().
 *
 * @return \EnableMediaReplace\EnableMediaReplacePlugin The plugin instance.
 */
function emr()
{
	return EnableMediaReplace\EnableMediaReplacePlugin::get();
}
emr(); // runtime.

//register_uninstall_hook( __FILE__, '\EnableMediaReplace\emr_uninstall' );
// Deactivation clears the stored notices; uninstall deletes the plugin options
// (see InstallHelper). The uninstall callback name differs in case from the
// declared method InstallHelper::unInstallPlugin(); PHP method names are
// case-insensitive, so it resolves.
register_deactivation_hook( __FILE__,  array('\EnableMediaReplace\InstallHelper','deactivatePlugin') );
register_uninstall_hook(__FILE__,  array('\EnableMediaReplace\InstallHelper','uninstallPlugin') );
