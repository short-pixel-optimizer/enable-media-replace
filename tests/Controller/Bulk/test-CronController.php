<?php
/**
 * Tests for EnableMediaReplace\Controller\Bulk\CronController
 * (classes/Controller/Bulk/CronController.php), ported from ShortPixel
 * Image Optimizer's CronController tests and extended with the cron run
 * itself, which SPIO's tests leave to integration.
 *
 * The WP test install keeps the cron array in the `cron` option, so
 * wp_next_scheduled() is real and rolled back with the test. The ShortPixel
 * endpoints are stubbed; the `wait` between ticks is set to 0 through the
 * options filter so the loop does not sleep.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';
require_once dirname( dirname( __DIR__ ) ) . '/Integration/Helpers/MockEmrApi.php';

use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\Controller\Bulk\CronController;
use EnableMediaReplace\InstallHelper;
use EnableMediaReplace\Model\Bulk\BulkImage;

class BulkCronControllerTest extends WP_UnitTestCase {

	use EMR_BulkTestHelpers;

	/**
	 * See TESTING.md, "Tests that touch the ShortQ queue table".
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		( new \EnableMediaReplace\ShortQ\ShortQ( InstallHelper::QUEUE_SLUG ) )->getQueue( InstallHelper::QUEUE_NAME )->install();
	}

	/** @var MockEmrApi */
	private $mock;

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
		( new BulkQueue() )->resetQueue();
		wp_clear_scheduled_hook( CronController::EVENT );
		$this->resetCron();
		$this->mock = MockEmrApi::register();
		$this->mock->reset();
		add_filter( 'pre_http_request', array( $this, 'failFast' ), 9999, 3 );
		add_filter( 'emr/bulk/cron/options', array( $this, 'noWait' ) );
	}

	public function tear_down() {
		remove_filter( 'emr/bulk/cron/options', array( $this, 'noWait' ) );
		remove_all_filters( 'emr/bulk/cron_interval' );
		remove_filter( 'pre_http_request', array( $this, 'failFast' ), 9999 );
		MockEmrApi::unregister();
		wp_clear_scheduled_hook( CronController::EVENT );
		$this->resetCron();
		$this->bulkTearDown();
		parent::tear_down();
	}

	private function resetCron() {
		$prop = new ReflectionProperty( CronController::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	public function noWait( $options ) {
		$options['wait'] = 0;
		return $options;
	}

	public function failFast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->fail( 'Unstubbed HTTP request to ' . $url );
	}

	private function setBackground( $on ) {
		$settings = BulkController::getSettings();
		$settings['background_process'] = (bool) $on;
		BulkController::saveSettings( $settings );
	}

	/** A prepared bulk with $count images, started when $start is true. */
	private function bulk( $count = 1, $start = true ) {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = $this->makeImageAttachment( 300, 200 );
		}
		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( array( 'operation' => 'upscale', 'items' => $ids ) );
		$q = new BulkQueue();
		while ( BulkQueue::RESULT_PREPARING_DONE !== $q->run()->qstatus ) {
		}
		if ( $start ) {
			$bulk->startBulk();
		}
		return $ids;
	}

	public function test_cron_schedules_adds_the_bulk_interval_and_the_filter_applies() {
		$schedules = CronController::getInstance()->cron_schedules( array( 'hourly' => array( 'interval' => 3600, 'display' => 'Hourly' ) ) );
		$this->assertArrayHasKey( 'hourly', $schedules );
		$this->assertSame( 60, $schedules[ CronController::SCHEDULE ]['interval'] );
		$this->assertNotEmpty( $schedules[ CronController::SCHEDULE ]['display'] );

		add_filter( 'emr/bulk/cron_interval', function () {
			return 5;
		} );
		$this->assertSame( 10, CronController::getInstance()->cron_schedules( array() )[ CronController::SCHEDULE ]['interval'], 'Never below ten seconds.' );

		$this->assertArrayHasKey( CronController::SCHEDULE, wp_get_schedules(), 'Registered on cron_schedules.' );
	}

	public function test_constructor_registers_the_event_handler_and_is_a_singleton() {
		$cron = CronController::getInstance();
		$this->assertSame( $cron, CronController::getInstance() );
		$this->assertNotFalse( has_action( CronController::EVENT, array( $cron, 'processCronHook' ) ) );
		$this->assertFalse( $cron->isScheduled(), 'Nothing running, nothing scheduled.' );
	}

	public function test_scheduler_schedules_only_when_the_setting_is_on_and_the_bulk_runs_with_items() {
		$cron = CronController::getInstance();

		$this->setBackground( false );
		$this->bulk( 1, true );
		$this->assertFalse( $cron->scheduler(), 'Setting off.' );
		$this->assertFalse( $cron->isScheduled() );

		$this->setBackground( true );
		( new BulkQueue() )->resetQueue();
		$this->bulk( 1, false );
		$this->assertFalse( $cron->scheduler(), 'Prepared but not started: the page drives the prepare phase.' );

		BulkController::getInstance()->startBulk(); // startBulk() calls checkNewJobs() itself.
		$this->assertTrue( $cron->isScheduled() );
		$event = wp_get_scheduled_event( CronController::EVENT );
		$this->assertSame( CronController::SCHEDULE, $event->schedule );
		$this->assertTrue( $cron->scheduler(), 'Idempotent.' );
		$this->assertCount( 1, array_filter( _get_cron_array(), function ( $hooks ) {
			return isset( $hooks[ CronController::EVENT ] );
		} ), 'Scheduled once.' );
	}

	public function test_scheduler_removes_the_event_when_the_setting_is_turned_off_or_the_queue_is_empty() {
		$this->setBackground( true );
		$this->bulk( 1, true );
		$cron = CronController::getInstance();
		$this->assertTrue( $cron->isScheduled(), 'Precondition.' );

		$this->setBackground( false );
		$cron->checkNewJobs();
		$this->assertFalse( $cron->isScheduled() );

		$this->setBackground( true );
		$cron->checkNewJobs();
		$this->assertTrue( $cron->isScheduled() );

		BulkController::getInstance()->finishBulk();
		$this->assertFalse( $cron->isScheduled(), 'finishBulk() removes the event.' );
	}

	public function test_onDeactivate_and_the_install_helper_clear_the_event() {
		wp_schedule_event( time(), CronController::SCHEDULE, CronController::EVENT );
		CronController::getInstance()->onDeactivate();
		$this->assertFalse( wp_next_scheduled( CronController::EVENT ) );

		wp_schedule_event( time(), CronController::SCHEDULE, CronController::EVENT );
		InstallHelper::deactivatePlugin();
		$this->assertFalse( wp_next_scheduled( CronController::EVENT ) );

		wp_schedule_event( time(), CronController::SCHEDULE, CronController::EVENT );
		InstallHelper::unInstallPlugin();
		$this->assertFalse( wp_next_scheduled( CronController::EVENT ) );
	}

	public function test_processCronHook_processes_the_running_bulk_until_the_queue_is_empty_and_unschedules() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->setBackground( true );
		$ids = $this->bulk( 2, true );
		$cron = CronController::getInstance();
		$this->assertTrue( $cron->isScheduled(), 'Precondition.' );

		$last = $cron->processCronHook();

		$this->assertSame( BulkQueue::RESULT_QUEUE_EMPTY, $last->media->qstatus );
		$this->assertSame( '2', $last->media->stats->done );
		foreach ( $ids as $id ) {
			$this->assertSame( 50, wp_get_attachment_metadata( $id )['width'], "Attachment $id replaced." );
			$this->assertSame( 4, BulkImage::getById( $id )->getMarker()['upscale']['factor'] );
		}
		$this->assertCount( 2, $this->mock->requestsTo( 'reducer.php' ) );
		$this->assertFalse( $cron->isScheduled(), 'Nothing left: the event is removed.' );
	}

	public function test_processCronHook_stops_at_max_runs_and_keeps_the_event() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->setBackground( true );
		add_filter( 'emr/bulk/queue_options', function ( $options ) {
			$options['numitems'] = 1;
			return $options;
		} );
		add_filter( 'emr/bulk/cron/options', function ( $options ) {
			$options['max_runs'] = 2;
			return $options;
		}, 20 );
		$this->bulk( 3, true );

		$last = CronController::getInstance()->processCronHook();

		remove_all_filters( 'emr/bulk/queue_options' );
		$this->assertSame( BulkQueue::RESULT_ITEMS, $last->media->qstatus );
		$this->assertSame( '2', $last->media->stats->done, 'Two ticks of one item each.' );
		$this->assertSame( '1', $last->media->stats->in_queue );
		$this->assertTrue( CronController::getInstance()->isScheduled(), 'Work left: the event stays.' );
	}

	public function test_processCronHook_stops_on_a_refused_tick_and_does_nothing_with_the_setting_off() {
		delete_option( 'emr_shortpixel_api_key' );
		$this->setBackground( true );
		$this->bulk( 1, true );

		$last = CronController::getInstance()->processCronHook();
		$this->assertFalse( $last->status );
		$this->assertSame( \EnableMediaReplace\Controller\Bulk\QueueController::APIKEY_FAILED, $last->error );
		$this->assertCount( 0, $this->mock->requests );
		$this->assertTrue( CronController::getInstance()->isScheduled(), 'A refused tick keeps the event for a later try.' );

		$this->setBackground( false );
		wp_schedule_event( time(), CronController::SCHEDULE, CronController::EVENT );
		$this->assertNull( CronController::getInstance()->processCronHook() );
		$this->assertFalse( CronController::getInstance()->isScheduled() );
	}

	public function test_runtime_creates_the_cron_controller_for_every_request() {
		$this->resetCron();
		wp_set_current_user( 0 );
		emr()->runtime();
		$prop = new ReflectionProperty( CronController::class, 'instance' );
		$prop->setAccessible( true );
		$this->assertInstanceOf( CronController::class, $prop->getValue( null ), 'Created before the capability check: cron has no user.' );
	}
}
