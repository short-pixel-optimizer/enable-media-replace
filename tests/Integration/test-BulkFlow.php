<?php
/**
 * Integration tests: the bulk queue end to end, the way the bulk screen
 * (and later cron) will drive it, against MockEmrApi.
 *
 * Port of ShortPixel Image Optimizer's tests/Integration/test-BulkOptimization.php
 * to the EMR bulk: BulkController::createNewBulk() puts the queue in the
 * preparing state, QueueController::processQueue() ticks scan the source
 * and enqueue the items, startBulk() switches to running, further ticks
 * send every item to the reducer (MockEmrApi) and apply the result in
 * place through ReplaceController. A pending reducer answer keeps the item
 * in process until ShortQ's process_timeout passes; the tests backdate the
 * rows between ticks, as SPIO's tests do, instead of waiting ten seconds.
 *
 * The attachments are GD-built 400x300 JPEGs; the mock serves the bytes of
 * tests/fixtures/test-image.jpg (50x50), so a replacement is visible in the
 * bytes and the metadata, and a restore brings the 400 px original back.
 *
 * Snapshot/restore: EMR_IntegrationTestCase (options, singletons,
 * attachments, mock) plus the queue rows of the EMR slug, the bulk logs
 * option, the plan transient, the backup folder and the hooks added.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ReplaceFlowHelpers.php';

use EnableMediaReplace\ApiKeyManager;
use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\Controller\Bulk\BulkApi;
use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\Controller\Bulk\QueueController;
use EnableMediaReplace\InstallHelper;
use EnableMediaReplace\Model\Bulk\BulkImage;

class BulkFlowTest extends EMR_IntegrationTestCase {

	/**
	 * Creates the shared `shortpixel_queue` table once, outside the test
	 * transaction. ShortQ installs it with CREATE TABLE and ALTER TABLE on
	 * first use; inside a test WP_UnitTestCase rewrites the CREATE into a
	 * TEMPORARY table (invisible to SHOW TABLES, dropped per connection) and
	 * the ALTER commits the transaction, which leaks every option written
	 * before it into later tests. Installed up front, the in-test install is
	 * a no-op (dbDelta sees the table, the unique key exists).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		( new \EnableMediaReplace\ShortQ\ShortQ( \EnableMediaReplace\InstallHelper::QUEUE_SLUG ) )->getQueue( \EnableMediaReplace\InstallHelper::QUEUE_NAME )->install();
	}

	use EMR_ReplaceFlowHelpers;

	const API_KEY = 'abcdEFGHIJKLMNOPwxyz';

	public function set_up() {
		parent::set_up();
		$this->flowSetUp();
		update_option( ApiKeyManager::OPTION_NAME, self::API_KEY );
		delete_option( BulkController::LOG_OPTION );
		delete_transient( QueueController::PLAN_TRANSIENT );
		$this->resetBulkState();
		( new BulkQueue() )->resetQueue();
	}

	public function tear_down() {
		global $wpdb;
		$this->flowTearDown();
		$table = $wpdb->prefix . 'shortpixel_queue';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE plugin_slug = %s", InstallHelper::QUEUE_SLUG ) );
		delete_option( 'shortqwp_' . InstallHelper::QUEUE_SLUG );
		delete_option( BulkController::LOG_OPTION );
		delete_transient( QueueController::PLAN_TRANSIENT );
		$backup = BackupController::getBackupPath();
		if ( is_dir( $backup ) ) {
			$this->removeTree( $backup );
		}
		$this->resetBulkState();
		parent::tear_down();
	}

	private function resetBulkState() {
		wp_clear_scheduled_hook( \EnableMediaReplace\Controller\Bulk\CronController::EVENT );
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
	// Driving the queue.
	// ---------------------------------------------------------------------

	/** Ticks until the queue stops preparing. */
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

	/** Ticks until the queue is empty and done; returns the last JSON. */
	private function runUntilDone( $maxTicks = 40 ) {
		$c = new QueueController();
		for ( $i = 0; $i < $maxTicks; $i++ ) {
			$json = $c->processQueue();
			if ( false === $json->status ) {
				return $json;
			}
			if ( BulkQueue::RESULT_QUEUE_EMPTY === $json->media->qstatus ) {
				return $json;
			}
			$this->backdateQueueRows();
		}
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT item_id, status, tries, value FROM {$wpdb->prefix}shortpixel_queue", ARRAY_A );
		$this->fail( "Not finished after $maxTicks ticks: " . wp_json_encode( $json ) . "\nROWS: " . wp_json_encode( $rows ) );
	}

	/** Lets ShortQ's process_timeout pass for every in-process row. */
	private function backdateQueueRows() {
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}shortpixel_queue SET updated = '2000-01-01 00:00:00'" );
	}

	private function image( $width = 400, $height = 300 ) {
		return $this->uploadJpeg( 'emr-bulk-' . $this->token() . '.jpg', $width, $height );
	}

	private function reducerBodies() {
		return array_map( function ( $r ) {
			return $r['request'];
		}, $this->api->requestsTo( 'reducer.php' ) );
	}

	// ---------------------------------------------------------------------
	// Remove background.
	// ---------------------------------------------------------------------

	public function test_bulk_remove_background_replaces_every_queued_image_in_place_with_a_backup_and_a_marker() {
		$ids = array( $this->image(), $this->image(), $this->image() );
		$originals = array();
		foreach ( $ids as $id ) {
			$originals[ $id ] = file_get_contents( get_attached_file( $id ) );
		}
		$text = $this->uploadText( 'emr-bulk-' . $this->token() . '.txt' );
		$this->api->waitingRounds = 1; // every image answers pending once, then succeeds.
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload-done' ), 10, 3 );
		$this->addHook( 'wp_handle_replace', $this->record( 'handle-replace' ) );
		$this->addHook( 'emr/bulk/item/done', $this->record( 'bulk-done' ), 10, 3 );

		$bulk  = BulkController::getInstance();
		$stats = $bulk->createNewBulk( array( 'operation' => 'background', 'items' => array_merge( $ids, array( $text ) ) ) );
		$this->assertTrue( $stats->is_preparing );

		$prepared = $this->prepare();
		$this->assertSame( '3', $prepared->media->stats->total, 'The text file is not queued.' );
		$this->assertCount( 0, $this->api->requestsTo( 'reducer.php' ), 'Nothing is sent before the bulk starts.' );

		$bulk->startBulk();
		$done = $this->runUntilDone();

		$this->assertSame( '3', $done->media->stats->done );
		$this->assertSame( '0', $done->media->stats->fatal_errors );
		$this->assertSame( '0', $done->media->stats->errors );
		$this->assertSame( '100', $done->media->stats->percentage_done );
		$this->assertTrue( ( new BulkQueue() )->getStats()->is_finished );

		$fixture = file_get_contents( $this->fixturePath( 'test-image.jpg' ) );
		foreach ( $ids as $id ) {
			$path = get_attached_file( $id );
			$this->assertSame( $fixture, file_get_contents( $path ), "Attachment $id holds the result bytes." );
			$this->assertSame( 50, wp_get_attachment_metadata( $id )['width'] );
			$image  = BulkImage::getById( $id );
			$marker = $image->getMarker();
			$this->assertSame( 1, $marker['background']['bg_remove'] );
			$this->assertTrue( BackupController::hasBackup( $image ) );
			$this->assertSame( $originals[ $id ], file_get_contents( BackupController::getBackupFile( $marker['backup'] )->getFullPath() ), 'The backup is the original.' );
		}

		// Each image: one cleanup (first try refreshes) and two reducer calls (pending, then success).
		$this->assertCount( 3, $this->api->requestsTo( 'cleanup.php' ) );
		$bodies = $this->reducerBodies();
		$this->assertCount( 6, $bodies );
		foreach ( $bodies as $body ) {
			$this->assertSame( self::API_KEY, $body['key'] );
			$this->assertSame( 'EMR' . EMR_VERSION, $body['plugin_version'] );
			$this->assertSame( 1, $body['bg_remove'] );
			$this->assertSame( 0, $body['lossy'] );
			$this->assertContains( $body['item_id'], $ids );
			$this->assertArrayNotHasKey( 'upscale', $body );
		}
		$refreshes = array_filter( $bodies, function ( $b ) {
			return true === $b['refresh'];
		} );
		$this->assertCount( 3, $refreshes, 'Only the first try of each image refreshes.' );
		$this->assertCount( 1, $this->api->requestsTo( 'api-status.php' ), 'The plan is checked once and cached.' );

		$this->assertCount( 3, $this->eventsNamed( 'handle-replace' ) );
		$this->assertCount( 3, $this->eventsNamed( 'upload-done' ) );
		$this->assertCount( 3, $this->eventsNamed( 'bulk-done' ) );

		$bulk->finishBulk();
		$logs = get_option( BulkController::LOG_OPTION );
		$this->assertCount( 1, $logs );
		$this->assertSame( 3, $logs[0]['processed'] );
		$this->assertSame( 'background', $logs[0]['type'] );
		$this->assertSame( 0, ( new BulkQueue() )->getStats()->total );
	}

	private function eventsNamed( $name ) {
		return array_values( array_filter( $this->events, function ( $event ) use ( $name ) {
			return $event[0] === $name;
		} ) );
	}

	public function test_bulk_restore_brings_the_originals_back_and_drops_markers_and_backups() {
		$ids = array( $this->image(), $this->image() );
		$originals = array();
		foreach ( $ids as $id ) {
			$originals[ $id ] = file_get_contents( get_attached_file( $id ) );
		}
		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( array( 'operation' => 'background', 'items' => $ids ) );
		$this->prepare();
		$bulk->startBulk();
		$this->runUntilDone();
		$bulk->finishBulk();
		foreach ( $ids as $id ) {
			$this->assertSame( 50, wp_get_attachment_metadata( $id )['width'], 'Precondition: replaced.' );
		}
		$backups = array_map( function ( $id ) {
			return BulkImage::getById( $id )->getMarker()['backup'];
		}, $ids );
		$this->api->reset();
		delete_option( ApiKeyManager::OPTION_NAME ); // A restore needs no key.
		$this->resetSingleton( 'EnableMediaReplace\ApiKeyManager', 'instance' );

		$bulk->createNewBulk( array( 'operation' => 'background', 'customOp' => BulkQueue::CUSTOM_OP_RESTORE ) );
		$this->prepare();
		$bulk->startBulk();
		$done = $this->runUntilDone();

		$this->assertSame( '2', $done->media->stats->done );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( $originals[ $id ], file_get_contents( get_attached_file( $id ) ) );
			$this->assertSame( 400, wp_get_attachment_metadata( $id )['width'] );
			$this->assertSame( array(), BulkImage::getById( $id )->getMarker() );
			$this->assertFalse( BackupController::getBackupFile( $backups[ $i ] )->exists() );
		}
		$this->assertCount( 0, $this->api->requests, 'No API traffic for a restore.' );

		$bulk->finishBulk();
		$logs = get_option( BulkController::LOG_OPTION );
		$this->assertCount( 2, $logs );
		$this->assertSame( BulkQueue::CUSTOM_OP_RESTORE, $logs[1]['operation'] );
	}

	// ---------------------------------------------------------------------
	// Upscale.
	// ---------------------------------------------------------------------

	public function test_bulk_upscale_takes_only_images_below_the_threshold_and_sends_the_automatic_factor() {
		$narrow = $this->image( 500, 200 );
		$wide   = $this->image( 1100, 200 );
		$bulk   = BulkController::getInstance();
		$bulk->createNewBulk( array( 'operation' => 'upscale', 'threshold' => 800, 'items' => array( $narrow, $wide ) ) );

		$prepared = $this->prepare();
		$this->assertSame( '1', $prepared->media->stats->total );

		$bulk->startBulk();
		$done = $this->runUntilDone();

		$this->assertSame( '1', $done->media->stats->done );
		$this->assertSame( 50, wp_get_attachment_metadata( $narrow )['width'], 'Replaced by the result.' );
		$this->assertSame( 1100, wp_get_attachment_metadata( $wide )['width'], 'Untouched.' );
		$marker = BulkImage::getById( $narrow )->getMarker();
		$this->assertSame( 4, $marker['upscale']['factor'] );
		$this->assertSame( 500, $marker['upscale']['from_width'] );
		$this->assertSame( array(), BulkImage::getById( $wide )->getMarker() );

		$bodies = $this->reducerBodies();
		$this->assertCount( 1, $bodies );
		$this->assertSame( 4, $bodies[0]['upscale'] );
		$this->assertArrayNotHasKey( 'bg_remove', $bodies[0] );
		$this->assertSame( $narrow, $bodies[0]['item_id'] );
	}

	// ---------------------------------------------------------------------
	// Failures.
	// ---------------------------------------------------------------------

	public function test_quota_exceeded_stops_the_tick_with_NOQUOTA_and_keeps_the_items() {
		$id   = $this->image();
		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$this->prepare();
		$bulk->startBulk();
		$this->api->quotaExceeded = true;

		$json = ( new QueueController() )->processQueue();

		$this->assertFalse( $json->status );
		$this->assertSame( QueueController::NOQUOTA, $json->error );
		$this->assertSame( QueueController::NOQUOTA, $json->media->results[0]->error );
		$this->assertFalse( $json->media->results[0]->is_done );
		$this->assertSame( BulkApi::STATUS_QUOTA_EXCEEDED, $json->media->results[0]->apiStatus );
		$this->assertSame( '1', $json->media->stats->in_process, 'The item is kept for a later retry.' );
		$this->assertSame( 400, wp_get_attachment_metadata( $id )['width'], 'Untouched.' );
		$this->assertFalse( get_transient( QueueController::PLAN_TRANSIENT ) );
	}

	public function test_an_api_error_fails_the_item_and_is_written_to_the_bulk_log() {
		$id   = $this->image();
		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$this->prepare();
		$bulk->startBulk();
		$this->api->forceStatusCode = '-201';
		$this->api->errorMessage    = 'Invalid image format';

		$done = $this->runUntilDone();

		$this->assertSame( '1', $done->media->stats->fatal_errors );
		$this->assertSame( '0', $done->media->stats->done );
		$this->assertSame( 400, wp_get_attachment_metadata( $id )['width'] );
		$this->assertSame( array(), BulkImage::getById( $id )->getMarker() );
		$this->assertFalse( BackupController::hasBackup( BulkImage::getById( $id ) ), 'No backup without a result.' );

		$current = BackupController::getBackupDirectory()->getPath() . BulkController::currentLogName( 'background' );
		$this->assertFileExists( $current );
		$line = file_get_contents( $current );
		$this->assertStringContainsString( basename( get_attached_file( $id ) ), $line );
		$this->assertStringContainsString( 'Invalid image format', $line );
		$this->assertStringContainsString( (string) $id, $line );

		$bulk->finishBulk();
		$logs = get_option( BulkController::LOG_OPTION );
		$this->assertSame( 1, $logs[0]['fatal_errors'] );
		$this->assertFileExists( BackupController::getBackupDirectory()->getPath() . $logs[0]['logfile'] );
		$this->assertFileDoesNotExist( $current );
	}

	public function test_an_expired_plan_refuses_the_tick_before_any_item_is_sent() {
		$id   = $this->image();
		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$this->api->unlimited = 'false';

		$json = ( new QueueController() )->processQueue();

		$this->assertFalse( $json->status );
		$this->assertSame( QueueController::PLAN_EXPIRED, $json->error );
		$this->assertCount( 1, $this->api->requestsTo( 'api-status.php' ) );
		$this->assertCount( 0, $this->api->requestsTo( 'reducer.php' ) );
		$this->assertTrue( ( new BulkQueue() )->getStats()->is_preparing, 'Nothing was prepared yet.' );
	}

	/**
	 * The cron run: the page prepared and started the bulk, then was closed;
	 * WP-Cron finishes it. Pending answers are handled by the in-process
	 * timeout like any tick, so the mock answers with success at once here.
	 */
	public function test_cron_finishes_a_started_bulk_when_the_background_setting_is_on() {
		$settings = BulkController::getSettings();
		$settings['background_process'] = true;
		BulkController::saveSettings( $settings );
		add_filter( 'emr/bulk/cron/options', function ( $options ) {
			$options['wait'] = 0;
			return $options;
		} );
		$ids  = array( $this->image(), $this->image() );
		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( array( 'operation' => 'background', 'items' => $ids ) );
		$this->prepare();
		$bulk->startBulk();
		$cron = \EnableMediaReplace\Controller\Bulk\CronController::getInstance();
		$this->assertTrue( $cron->isScheduled(), 'startBulk() scheduled the event.' );

		$last = $cron->processCronHook();

		remove_all_filters( 'emr/bulk/cron/options' );
		$this->assertSame( BulkQueue::RESULT_QUEUE_EMPTY, $last->media->qstatus );
		foreach ( $ids as $id ) {
			$this->assertSame( 50, wp_get_attachment_metadata( $id )['width'] );
			$this->assertTrue( BackupController::hasBackup( BulkImage::getById( $id ) ) );
		}
		$this->assertFalse( $cron->isScheduled() );
		$bulk->finishBulk();
	}

	public function test_a_second_run_skips_images_the_operation_already_processed() {
		$id   = $this->image();
		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$this->prepare();
		$bulk->startBulk();
		$this->runUntilDone();
		$bulk->finishBulk();
		$this->api->reset();
		$this->resetBulkState();

		$bulk->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$prepared = $this->prepare();
		$this->assertSame( '0', $prepared->media->stats->total, 'Already removed: not queued again.' );

		// The other operation still applies to it.
		$bulk->createNewBulk( array( 'operation' => 'upscale', 'items' => array( $id ) ) );
		$prepared = $this->prepare();
		$this->assertSame( '1', $prepared->media->stats->total );
	}
}
