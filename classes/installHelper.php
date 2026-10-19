<?php
namespace EnableMediaReplace;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Notices\NoticeController as Notices;

class InstallHelper
{

	 	public static function unInstallPlugin()
		{
			delete_option( 'enable_media_replace' );
			delete_option( 'emr_news' );
			delete_option( 'emr_url_cache');
			delete_option( 'emr_bulk_bg_queue' );
			delete_option( 'emr_bulk_bg_lock' );
			wp_clear_scheduled_hook( 'emr_bulk_bg_cron' );
		}

		public static function deactivatePlugin()
		{
			Notices::resetNotices();
			// Background mode of the bulk background removal.
			wp_clear_scheduled_hook( 'emr_bulk_bg_cron' );
		}
}
