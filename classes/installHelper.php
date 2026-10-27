<?php
/**
 * Plugin deactivation and uninstall handling.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Notices\NoticeController as Notices;

/**
 * Deactivation and uninstall routines.
 *
 * Static-only; never instantiated. The root plugin file registers
 * deactivatePlugin() with register_deactivation_hook() and unInstallPlugin()
 * with register_uninstall_hook().
 */
class InstallHelper
{

	 	/**
		 * Uninstall callback: deletes the plugin's options.
		 *
		 * Deletes `enable_media_replace` (the last-used replace and
		 * remove-background form settings), and `emr_news` and `emr_url_cache`,
		 * which no current code reads or writes.
		 *
		 * Registered from the root file as 'uninstallPlugin'; the declared name
		 * differs only in case, which PHP resolves.
		 *
		 * @return void
		 *
		 * @todo It does not delete the `emr_shortpixel_api_key` option (the
		 *       remove-background API key, stored in plain text), the
		 *       `emr_remote_notice` transient, the `EnableMediaReplace-notices`
		 *       option (only deleted on deactivation), or the
		 *       `_emr_replace_author` post meta. Add delete_option() /
		 *       delete_transient() calls for the first three and decide whether
		 *       the post meta should go too.
		 */
	 	public static function unInstallPlugin()
		{
			delete_option( 'enable_media_replace' );
			delete_option( 'emr_news' );
			delete_option( 'emr_url_cache');
		}

		/**
		 * Deactivation callback: clears the stored admin notices.
		 *
		 * NoticeController::resetNotices() deletes the `EnableMediaReplace-notices`
		 * option, which holds persistent notices and their dismissal state, so a
		 * dismissed notice (for example the feature notice) shows again after
		 * reactivation.
		 *
		 * @return void
		 */
		public static function deactivatePlugin()
		{
			Notices::resetNotices();
		}
}
