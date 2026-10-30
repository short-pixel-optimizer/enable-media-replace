<?php
/**
 * Tests for EnableMediaReplace\Controller\Bulk\BulkController
 * (classes/Controller/Bulk/BulkController.php), ported from ShortPixel
 * Image Optimizer's BulkController tests and extended with createNewBulk()
 * / startBulk() / finishBulk(), which run against the real queue here.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';

use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;

class BulkControllerTest extends WP_UnitTestCase {

	use EMR_BulkTestHelpers;

	/** @var string */
	private $backupPath;

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
		( new BulkQueue() )->resetQueue();
		delete_option( BulkController::LOG_OPTION );
		$this->backupPath = BackupController::getBackupDirectory()->getPath();
	}

	public function tear_down() {
		remove_all_filters( 'emr/bulk/custom_options' );
		delete_option( BulkController::LOG_OPTION );
		$this->bulkTearDown();
		parent::tear_down();
	}

	private function fresh() {
		return ( new ReflectionClass( BulkController::class ) )->newInstanceWithoutConstructor();
	}

	private function invokeProtected( BulkController $obj, $method, array $args = array() ) {
		$m = new ReflectionMethod( BulkController::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $obj, ...$args );
	}

	private function setProtected( BulkController $obj, $prop, $value ) {
		$p = new ReflectionProperty( BulkController::class, $prop );
		$p->setAccessible( true );
		$p->setValue( $obj, $value );
	}

	/** Queue stub with the interface addLog() uses. */
	private function queueStub( array $statsOverrides = array(), $operation = 'background', $customOp = false ) {
		$stats = (object) array_merge( array( 'done' => 5, 'in_queue' => 0, 'errors' => 0, 'fatal_errors' => 0, 'is_finished' => true, 'total' => 5 ), $statsOverrides );
		return new class( $stats, $operation, $customOp ) {
			private $stats; private $operation; private $customOp;
			public function __construct( $stats, $operation, $customOp ) { $this->stats = $stats; $this->operation = $operation; $this->customOp = $customOp; }
			public function getStats() { return $this->stats; }
			public function getOperation() { return $this->operation; }
			public function getCustomDataItem( $key ) { return ( 'customOperation' === $key ) ? $this->customOp : false; }
		};
	}

	public function test_getInstance_returns_a_singleton() {
		$a = BulkController::getInstance();
		$this->assertInstanceOf( BulkController::class, $a );
		$this->assertSame( $a, BulkController::getInstance() );
	}

	public function test_currentLogName_is_per_operation_and_sanitised() {
		$this->assertSame( 'current_bulk_background.log', BulkController::currentLogName( 'background' ) );
		$this->assertSame( 'current_bulk_upscale.log', BulkController::currentLogName( 'Up Scale!' ) );
		$this->assertSame( 'current_bulk_upscalex.log', BulkController::currentLogName( 'upscale/../x' ), 'sanitize_key() strips slashes and dots.' );
	}

	public function test_createNewBulk_stores_the_operation_and_options_and_flags_preparing() {
		$ctrl  = BulkController::getInstance();
		$stats = $ctrl->createNewBulk( array( 'operation' => 'upscale', 'threshold' => 900, 'items' => array( 1, 2 ) ) );

		$this->assertTrue( $stats->is_preparing );
		$this->assertTrue( $stats->bulk_running );
		$this->assertSame( 'upscale', $stats->operation );
		$q = new BulkQueue();
		$this->assertSame( 'upscale', $q->getOperation() );
		$this->assertSame( 900, $q->getOptions()['threshold'] );
		$this->assertSame( array( 1, 2 ), $q->getOptions()['items'] );
		$this->assertArrayNotHasKey( 'customOp', $q->getOptions() );
		$this->assertFalse( $q->isCustomOperation() );
		$this->assertFalse( $ctrl->getCustomOperation() );
	}

	public function test_createNewBulk_falls_back_to_background_for_an_unknown_operation() {
		BulkController::getInstance()->createNewBulk( array( 'operation' => 'optimize' ) );
		$this->assertSame( 'background', ( new BulkQueue() )->getOperation() );
	}

	public function test_createNewBulk_for_a_restore_lowers_the_shortq_limits_like_spio_and_applies_the_filter() {
		add_filter( 'emr/bulk/custom_options', function ( $args ) {
			$args['retry_limit'] = 9;
			return $args;
		} );
		$ctrl = BulkController::getInstance();
		$ctrl->createNewBulk( array( 'operation' => 'background', 'customOp' => BulkQueue::CUSTOM_OP_RESTORE ) );

		$q = new BulkQueue();
		$this->assertTrue( $q->isCustomOperation() );
		$this->assertSame( BulkQueue::CUSTOM_OP_RESTORE, $ctrl->getCustomOperation() );
		$this->assertSame( 5, $q->getShortQ()->getOption( 'numitems' ) );
		$this->assertSame( 9, $q->getShortQ()->getOption( 'retry_limit' ) );
		$this->assertSame( 3000, $q->getShortQ()->getOption( 'process_timeout' ) );
	}

	public function test_createNewBulk_removes_the_current_log_of_a_previous_run() {
		$current = $this->backupPath . BulkController::currentLogName( 'background' );
		file_put_contents( $current, 'old' );
		BulkController::getInstance()->createNewBulk( array( 'operation' => 'background' ) );
		$this->assertFileDoesNotExist( $current );
	}

	public function test_startBulk_and_isBulkRunning() {
		$ctrl = BulkController::getInstance();
		$this->assertFalse( $ctrl->isBulkRunning(), 'Empty queue.' );

		$id = $this->makeImageAttachment( 100, 100 );
		$ctrl->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$q = new BulkQueue();
		while ( BulkQueue::RESULT_PREPARING_DONE !== $q->run()->qstatus ) {
		}
		$this->assertTrue( $ctrl->isBulkRunning(), 'Items waiting, not finished.' );

		$stats = $ctrl->startBulk();
		$this->assertTrue( $stats->is_running );
		$this->assertFalse( $stats->is_preparing );
	}

	public function test_finishBulk_logs_the_run_and_resets_the_queue() {
		$ctrl = BulkController::getInstance();
		$id   = $this->makeImageAttachment( 100, 100 );
		$ctrl->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$q = new BulkQueue();
		while ( BulkQueue::RESULT_PREPARING_DONE !== $q->run()->qstatus ) {
		}
		$ctrl->startBulk();
		$q    = new BulkQueue(); // startBulk() ran on another instance; the status is read from the option.
		$item = $q->run()->items[0];
		$q->itemDone( $item );
		file_put_contents( $this->backupPath . BulkController::currentLogName( 'background' ), "line\n" );

		$ctrl->finishBulk();

		$logs = get_option( BulkController::LOG_OPTION );
		$this->assertIsArray( $logs );
		$this->assertCount( 1, $logs );
		$this->assertSame( 1, $logs[0]['processed'] );
		$this->assertSame( 'background', $logs[0]['type'] );
		$this->assertMatchesRegularExpression( '/^bulk_background_\d+\.log$/', $logs[0]['logfile'] );
		$this->assertFileExists( $this->backupPath . $logs[0]['logfile'] );
		$this->assertFileDoesNotExist( $this->backupPath . BulkController::currentLogName( 'background' ) );
		$this->assertCount( 0, $this->queueRows() );
		$this->assertFalse( ( new BulkQueue() )->getStats()->bulk_running );
	}

	public function test_getLogs_round_trip_and_memoisation() {
		$ctrl = $this->fresh();
		$this->assertSame( array(), $ctrl->getLogs() );

		$seeded = array(
			array( 'processed' => 3, 'type' => 'background', 'date' => 1700000000, 'logfile' => 'bulk_background_1700000000.log' ),
			array( 'processed' => 7, 'type' => 'upscale', 'date' => 1700001000, 'logfile' => 'bulk_upscale_1700001000.log' ),
		);
		update_option( BulkController::LOG_OPTION, $seeded, false );
		$ctrl = $this->fresh();
		$this->assertCount( 2, $ctrl->getLogs() );
		$this->assertSame( 'upscale', $ctrl->getLogs()[1]['type'] );
		update_option( BulkController::LOG_OPTION, array(), false );
		$this->assertCount( 2, $ctrl->getLogs(), 'Memoised.' );
	}

	public function test_saveLogs_persists_or_deletes_the_option() {
		$ctrl = $this->fresh();
		$this->invokeProtected( $ctrl, 'saveLogs', array( array( array( 'processed' => 2 ) ) ) );
		$this->assertSame( 2, get_option( BulkController::LOG_OPTION )[0]['processed'] );
		$this->invokeProtected( $ctrl, 'saveLogs', array( array() ) );
		$this->assertFalse( get_option( BulkController::LOG_OPTION, false ) );
	}

	public function test_uninstallPlugin_removes_the_option_and_is_idempotent() {
		update_option( BulkController::LOG_OPTION, array( array( 'processed' => 1 ) ), false );
		BulkController::uninstallPlugin();
		$this->assertFalse( get_option( BulkController::LOG_OPTION, false ) );
		BulkController::uninstallPlugin();
		$this->assertFalse( get_option( BulkController::LOG_OPTION, false ) );
	}

	public function test_getLogData_finds_an_entry_by_file_name() {
		update_option( BulkController::LOG_OPTION, array(
			array( 'processed' => 1, 'logfile' => 'bulk_background_1.log' ),
			array( 'processed' => 9, 'logfile' => 'bulk_background_42.log' ),
		), false );
		$ctrl = $this->fresh();
		$this->assertSame( 9, $ctrl->getLogData( 'bulk_background_42.log' )['processed'] );
		$this->assertFalse( $ctrl->getLogData( 'no-such-file.log' ) );
	}

	public function test_getLog_rejects_path_traversal_and_missing_files_and_returns_existing_ones() {
		$ctrl = $this->fresh();
		$this->assertFalse( $ctrl->getLog( '../../../etc/passwd' ) );
		$this->assertFalse( $ctrl->getLog( '../..' ) );
		$this->assertFalse( $ctrl->getLog( 'nonexistent.log' ) );

		$name = 'test_bulk_getlog_' . time() . '.log';
		file_put_contents( $this->backupPath . $name, 'test data' );
		$log = $ctrl->getLog( $name );
		$this->assertIsObject( $log );
		$this->assertSame( $this->backupPath . $name, $log->getFullPath() );
	}

	public function test_addLog_skips_a_run_without_done_or_fatal_items() {
		$ctrl = $this->fresh();
		$this->invokeProtected( $ctrl, 'addLog', array( $this->queueStub( array( 'done' => 0, 'fatal_errors' => 0 ) ) ) );
		$this->assertFalse( get_option( BulkController::LOG_OPTION, false ) );
	}

	public function test_addLog_writes_an_entry_with_operation_and_timestamped_file_name() {
		$ctrl   = $this->fresh();
		$before = time();
		$this->invokeProtected( $ctrl, 'addLog', array( $this->queueStub( array( 'done' => 0, 'fatal_errors' => 2 ), 'upscale', BulkQueue::CUSTOM_OP_RESTORE ) ) );

		$stored = get_option( BulkController::LOG_OPTION );
		$this->assertCount( 1, $stored );
		$this->assertSame( 2, $stored[0]['fatal_errors'] );
		$this->assertSame( 'upscale', $stored[0]['type'] );
		$this->assertSame( BulkQueue::CUSTOM_OP_RESTORE, $stored[0]['operation'] );
		$this->assertMatchesRegularExpression( '/^bulk_upscale_\d+\.log$/', $stored[0]['logfile'] );
		preg_match( '/_(\d+)\.log$/', $stored[0]['logfile'], $m );
		$this->assertGreaterThanOrEqual( $before, (int) $m[1] );
		$this->assertLessThanOrEqual( time(), (int) $m[1] );
	}

	public function test_addLog_rotates_at_ten_entries_and_deletes_the_oldest_file() {
		$oldName = 'bulk_background_1700000001.log';
		file_put_contents( $this->backupPath . $oldName, 'oldest' );
		$existing = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$existing[] = array( 'processed' => $i, 'type' => 'background', 'date' => 1700000000 + $i, 'logfile' => 'bulk_background_' . ( 1700000000 + $i ) . '.log' );
		}
		update_option( BulkController::LOG_OPTION, $existing, false );
		$ctrl = $this->fresh();
		$this->setProtected( $ctrl, 'logs', $existing );

		$this->invokeProtected( $ctrl, 'addLog', array( $this->queueStub( array( 'done' => 99 ) ) ) );

		$stored = get_option( BulkController::LOG_OPTION );
		$this->assertCount( 10, $stored );
		$this->assertNotContains( 1, array_column( $stored, 'processed' ) );
		$this->assertSame( 99, end( $stored )['processed'] );
		$this->assertFileDoesNotExist( $this->backupPath . $oldName );
	}
}
