<?php
/**
 * Plugin deactivation and uninstall handling.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Notices\NoticeController as Notices;
use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\CronController;
use EnableMediaReplace\Controller\Bulk\QueueController;

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
		 * ShortQ plugin slug under which EMR stores its rows in the shared
		 * `{prefix}shortpixel_queue` table. SPIO uses 'SPIO', RTA uses 'rta';
		 * rows are scoped by (plugin_slug, queue_name), so the three plugins
		 * share the table without touching each other's items. Also the suffix
		 * of the queue status option, `shortqwp_emr`.
		 *
		 * @var string
		 */
		const QUEUE_SLUG = 'emr';

		/**
		 * Name of the single EMR queue (bulk operations).
		 *
		 * @var string
		 */
		const QUEUE_NAME = 'bulk';

	 	/**
		 * Uninstall callback: deletes the plugin's options.
		 *
		 * Deletes `enable_media_replace` (the last-used replace and
		 * remove-background form settings), and `emr_news` and `emr_url_cache`,
		 * which no current code reads or writes. Then removes the bulk state:
		 * the queue (its rows in the shared `shortpixel_queue` table and the
		 * `shortqwp_emr` option; ShortQ drops the table itself only when no
		 * rows of any plugin remain, so an active SPIO or RTA install keeps
		 * its queue), the bulk logs option and the cached plan check.
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

			QueueController::uninstallPlugin();
			BulkController::uninstallPlugin();
			delete_transient( QueueController::PLAN_TRANSIENT );
			wp_clear_scheduled_hook( CronController::EVENT );
		}

		/**
		 * Deactivation callback: clears the stored admin notices and the bulk cron event.
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
			wp_clear_scheduled_hook( CronController::EVENT );
		}
}
