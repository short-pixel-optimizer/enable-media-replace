<?php
/**
 * Multisite: the bulk on a subsite.
 *
 * ShortQ names its table with `$wpdb->prefix`, so every site of a network
 * has its own `{prefix}_{blog}_shortpixel_queue`, its own `shortqwp_emr`
 * status option, bulk logs, API key, settings and `EMR_Backups` folder
 * under `uploads/sites/N`. A bulk started on a subsite must scan that
 * site's library only, write everything under `sites/N`, schedule its cron
 * event there, and leave the main site's library, queue, options and cron
 * alone; the restore brings the subsite's originals back.
 *
 * The queue table of the subsite is created BEFORE the test transaction:
 * ShortQ's install() runs dbDelta (a TEMPORARY table inside a test) and an
 * ALTER TABLE (an implicit commit that would leak every option written so
 * far, see TESTING.md "Tests that touch the ShortQ queue table"). The
 * subsite does not exist yet at that point, so its id is read from the
 * blogs table's AUTO_INCREMENT counter (rolled-back sites do advance it)
 * and the table is copied from the main site's, which
 * set_up_before_class() installs. install() then finds the table and runs
 * no DDL.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/MultisiteHelpers.php';

use EnableMediaReplace\ApiKeyManager;
use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\Controller\Bulk\CronController;
use EnableMediaReplace\Controller\Bulk\QueueController;
use EnableMediaReplace\InstallHelper;
use EnableMediaReplace\Model\Bulk\BulkImage;

class MultisiteBulkTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_MultisiteHelpers;

	const SUB_KEY = 'subsEFGHIJKLMNOPsubs';

	/** @var int The subsite under test. */
	private $blog = 0;

	/** @var string The subsite's queue table, created before the transaction. */
	private $subTable = '';

	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( is_multisite() ) {
			( new \EnableMediaReplace\ShortQ\ShortQ( InstallHelper::QUEUE_SLUG ) )->getQueue( InstallHelper::QUEUE_NAME )->install();
		}
	}

	public function set_up() {
		global $wpdb;
		$this->skipUnlessMultisite();

		// The id the next site will get, and its queue table, before the transaction opens.
		$status    = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->blogs ), ARRAY_A );
		$next_blog = (int) $status['Auto_increment'];
		$this->assertGreaterThan( 1, $next_blog, 'Sentinel: the blogs table has an AUTO_INCREMENT counter.' );
		$this->subTable = $wpdb->base_prefix . $next_blog . '_shortpixel_queue';
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$this->subTable}` LIKE `{$wpdb->base_prefix}shortpixel_queue`" ); // phpcs:ignore WordPress.DB

		parent::set_up();
		$this->flowSetUp();
		$this->resetBulkState();

		$this->blog = $this->newSite();
		$this->assertSame( $next_blog, $this->blog, 'Sentinel: the subsite got the predicted id, its queue table exists.' );
	}

	public function tear_down() {
		global $wpdb;
		$this->msTearDown();
		$this->flowTearDown();
		$this->resetBulkState();
		wp_clear_scheduled_hook( CronController::EVENT );
		$main = $wpdb->base_prefix . 'shortpixel_queue';
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$main}` WHERE plugin_slug = %s", InstallHelper::QUEUE_SLUG ) ); // phpcs:ignore WordPress.DB
		parent::tear_down();
		if ( '' !== $this->subTable ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$this->subTable}`" ); // phpcs:ignore WordPress.DB
		}
	}

	private function resetBulkState() {
		foreach ( array(
			'EnableMediaReplace\Controller\Bulk\BulkApi'        => 'instance',
			'EnableMediaReplace\Controller\Bulk\BulkController' => 'instance',
			'EnableMediaReplace\Controller\Bulk\CronController' => 'instance',
		) as $class => $property ) {
			$this->resetSingleton( $class, $property );
		}
		foreach ( array( 'EnableMediaReplace\Controller\Bulk\ActionController' => 'instances', 'EnableMediaReplace\Controller\Bulk\BulkQueue' => 'isInQueue' ) as $class => $property ) {
			$prop = new ReflectionProperty( $class, $property );
			$prop->setAccessible( true );
			$prop->setValue( null, array() );
		}
		\EnableMediaReplace\Controller\Bulk\ResponseController::reset();
	}

	// ---------------------------------------------------------------------
	// Driving the queue (as BulkFlowTest does).
	// ---------------------------------------------------------------------

	private function prepare( $maxTicks = 20 ) {
		$c = new QueueController();
		for ( $i = 0; $i < $maxTicks; $i++ ) {
			$json = $c->processQueue();
			$this->assertSame( 1, $json->status, 'Tick refused: ' . wp_json_encode( $json ) );
			if ( BulkQueue::RESULT_PREPARING_DONE === $json->media->qstatus ) {
				return $json;
			}
		}
		$this->fail( "Still preparing after $maxTicks ticks: " . wp_json_encode( $json ) );
	}

	private function runUntilDone( $maxTicks = 40 ) {
		global $wpdb;
		$c = new QueueController();
		for ( $i = 0; $i < $maxTicks; $i++ ) {
			$json = $c->processQueue();
			if ( false === $json->status || BulkQueue::RESULT_QUEUE_EMPTY === $json->media->qstatus ) {
				return $json;
			}
			$wpdb->query( "UPDATE {$wpdb->prefix}shortpixel_queue SET updated = '2000-01-01 00:00:00'" ); // phpcs:ignore WordPress.DB
		}
		$this->fail( "Not finished after $maxTicks ticks: " . wp_json_encode( $json ) );
	}

	private function emrRows( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE plugin_slug = %s", InstallHelper::QUEUE_SLUG ) ); // phpcs:ignore WordPress.DB
	}

	/** Runs $callback on the main site and returns its value. */
	private function onMainSite( $callback ) {
		switch_to_blog( get_main_site_id() );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	// ---------------------------------------------------------------------
	// The test.
	// ---------------------------------------------------------------------

	public function test_a_bulk_on_a_subsite_processes_and_restores_its_own_library_and_leaves_the_main_site_alone() {
		global $wpdb;
		$main_table = $wpdb->base_prefix . 'shortpixel_queue';

		// Main site: an eligible image that must never be touched, and no key.
		$main_image = $this->uploadJpeg( 'emr-ms-main-' . $this->token() . '.jpg', 400, 300 );
		$this->assertFalse( get_option( ApiKeyManager::OPTION_NAME ), 'Sentinel: no key on the main site.' );

		$this->enterSite( $this->blog );
		update_option( ApiKeyManager::OPTION_NAME, self::SUB_KEY );
		update_option( 'enable_media_replace', array( 'bulk_background_process' => true ), false );
		$ids = $this->subsiteUpload(
			function () {
				return array(
					$this->uploadJpeg( 'emr-ms-sub-' . $this->token() . '.jpg', 400, 300 ),
					$this->uploadJpeg( 'emr-ms-sub-' . $this->token() . '.jpg', 600, 200 ),
				);
			}
		);
		$originals = array();
		foreach ( $ids as $id ) {
			$this->assertStringContainsString( '/sites/' . $this->blog . '/', get_attached_file( $id ), 'Sentinel: the subsite file lives under sites/N.' );
			$originals[ $id ] = file_get_contents( get_attached_file( $id ) );
		}

		// No explicit items: the selector scans the current site's library.
		$bulk  = BulkController::getInstance();
		$stats = $bulk->createNewBulk( array( 'operation' => 'upscale', 'threshold' => 1200 ) );
		$this->assertTrue( $stats->is_preparing );
		$prepared = $this->prepare();
		$this->assertSame( '2', $prepared->media->stats->total, 'The two subsite images, not the main site one.' );
		$this->assertSame( 2, $this->emrRows( $this->subTable ), 'The rows are in the subsite table.' );
		$this->assertSame( 0, $this->emrRows( $main_table ), 'None in the main site table.' );

		$bulk->startBulk();
		$this->assertNotFalse( wp_next_scheduled( CronController::EVENT ), 'The cron event is scheduled on the subsite.' );
		$done = $this->runUntilDone();
		$this->assertSame( '2', $done->media->stats->done, wp_json_encode( $done ) );
		$this->assertSame( '0', $done->media->stats->fatal_errors );

		$sub_backups = BackupController::getBackupPath();
		$this->assertStringContainsString( '/sites/' . $this->blog . '/EMR_Backups', $sub_backups, 'The backups live under the subsite uploads.' );
		foreach ( $ids as $id ) {
			$this->assertSame( 50, wp_get_attachment_metadata( $id )['width'], "Attachment $id was replaced by the result." );
			$image = BulkImage::getById( $id );
			$this->assertTrue( BackupController::hasBackup( $image ) );
			$this->assertStringStartsWith( $sub_backups, BackupController::getBackupFile( $image->getMarker()['backup'] )->getFullPath() );
		}
		$bodies = $this->api->requestsTo( 'reducer.php' );
		$this->assertCount( 2, $bodies );
		foreach ( $bodies as $body ) {
			$this->assertSame( self::SUB_KEY, $body['request']['key'], 'The subsite key is used.' );
			$this->assertStringContainsString( '/sites/' . $this->blog . '/', urldecode( $body['request']['urllist'][0] ), 'The subsite URL is sent.' );
		}
		$this->assertNotFalse( get_option( 'shortqwp_' . InstallHelper::QUEUE_SLUG ), 'The queue status option is on the subsite.' );

		// The main site saw nothing of it.
		$this->onMainSite(
			function () use ( $main_image ) {
				$this->assertSame( 400, wp_get_attachment_metadata( $main_image )['width'], 'The main site image is untouched.' );
				// The main site's queue status (its option may exist from the table install) shows no run.
				$main_stats = ( new BulkQueue() )->getStats();
				$this->assertFalse( $main_stats->is_preparing, 'No bulk preparing on the main site.' );
				$this->assertFalse( $main_stats->is_running, 'No bulk running on the main site.' );
				$this->assertSame( 0, (int) $main_stats->in_queue );
				$this->assertSame( 0, (int) $main_stats->done );
				$this->assertSame( 0, (int) $main_stats->total );
				$this->assertFalse( get_option( BulkController::LOG_OPTION ), 'No bulk logs on the main site.' );
				$this->assertFalse( wp_next_scheduled( CronController::EVENT ), 'No cron event on the main site.' );
				$this->assertFalse( is_dir( BackupController::getBackupPath() ), 'No EMR_Backups folder on the main site.' );
			}
		);
		$this->assertSame( 0, $this->emrRows( $main_table ) );

		// Restore on the subsite.
		$bulk->finishBulk();
		$this->assertFalse( wp_next_scheduled( CronController::EVENT ), 'finishBulk unschedules the subsite event.' );
		$this->resetBulkState();
		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( array( 'operation' => 'upscale', 'customOp' => BulkQueue::CUSTOM_OP_RESTORE ) );
		$this->assertSame( '2', $this->prepare()->media->stats->total, 'Both subsite images are restorable.' );
		$bulk->startBulk();
		$restored = $this->runUntilDone();
		$this->assertSame( '2', $restored->media->stats->done, wp_json_encode( $restored ) );
		foreach ( $ids as $id ) {
			$this->assertSame( $originals[ $id ], file_get_contents( get_attached_file( $id ) ), "Attachment $id is the original again." );
			$this->assertSame( array(), BulkImage::getById( $id )->getMarker() );
		}
		$logs = get_option( BulkController::LOG_OPTION );
		$this->assertIsArray( $logs, 'The run is logged on the subsite.' );
		$this->assertGreaterThanOrEqual( 1, count( $logs ) );
		$this->leaveSite();
	}
}
