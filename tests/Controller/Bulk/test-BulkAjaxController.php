<?php
/**
 * Tests for EnableMediaReplace\Controller\Bulk\BulkAjaxController
 * (classes/Controller/Bulk/BulkAjaxController.php): the three bulk AJAX
 * actions, their nonce and capability gates, the two-tab processor lock and
 * the screen actions.
 *
 * Runs on WP_Ajax_UnitTestCase: wp_send_json() dies into an exception and
 * the output lands in $_last_response. The admin_init chain is dropped
 * (core's update checks would call wordpress.org). The ShortPixel endpoints
 * are answered by MockEmrApi; anything else is refused at priority 9999.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';
require_once dirname( dirname( __DIR__ ) ) . '/Integration/Helpers/MockEmrApi.php';

use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\Controller\Bulk\BulkAjaxController;
use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\Controller\Bulk\QueueController;
use EnableMediaReplace\Model\Bulk\BulkImage;

class BulkAjaxControllerTest extends WP_Ajax_UnitTestCase {

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

	use EMR_BulkTestHelpers;

	const API_KEY = 'abcdEFGHIJKLMNOPwxyz';

	/** @var MockEmrApi */
	private $mock;

	/** @var array */
	private $savedPost;

	/** @var int */
	private $savedUser;

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
		( new BulkQueue() )->resetQueue();
		$this->savedPost = $_POST;
		$this->savedUser = get_current_user_id();
		$_POST           = array();
		remove_all_actions( 'admin_init' );
		$this->mock = MockEmrApi::register();
		$this->mock->reset();
		add_filter( 'pre_http_request', array( $this, 'failFast' ), 9999, 3 );
		delete_transient( BulkAjaxController::SECRET_TRANSIENT );
		$this->_setRole( 'administrator' );
	}

	public function tear_down() {
		if ( $this->registeredProductTypes ) {
			unregister_post_type( 'product' );
			unregister_post_type( 'product_variation' );
			$this->registeredProductTypes = false;
		}
		$_POST = $this->savedPost;
		wp_set_current_user( $this->savedUser );
		remove_filter( 'pre_http_request', array( $this, 'failFast' ), 9999 );
		MockEmrApi::unregister();
		delete_transient( BulkAjaxController::SECRET_TRANSIENT );
		$this->bulkTearDown();
		parent::tear_down();
	}

	public function failFast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->fail( 'Unstubbed HTTP request to ' . $url );
	}

	/** Fires an action with $_POST set, returns the decoded JSON. */
	private function call( $action, array $post ) {
		$_POST = array_merge( array( 'action' => $action ), $post );
		$this->_last_response = '';
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			// wp_send_json() ended the request.
		}
		$json = json_decode( $this->_last_response );
		$this->assertIsObject( $json, 'Response is JSON: ' . $this->_last_response );
		return $json;
	}

	private function request( $screen_action, array $extra = array(), $nonce = null ) {
		return $this->call( 'emr_bulk_request', array_merge( array(
			'nonce'         => is_null( $nonce ) ? wp_create_nonce( 'emr_bulk_request' ) : $nonce,
			'screen_action' => $screen_action,
		), $extra ) );
	}

	private function tick( array $extra = array(), $nonce = null ) {
		return $this->call( 'emr_bulk_process', array_merge( array(
			'nonce'       => is_null( $nonce ) ? wp_create_nonce( 'emr_bulk_process' ) : $nonce,
			'bulk-secret' => 'tab-one',
		), $extra ) );
	}

	/**
	 * The callback count is not asserted here: Ajax is instantiated twice
	 * (require time and adminInit()), so the bulk endpoints share the known
	 * duplicate registration; see the pins in tests/Controller/test-Ajax.php.
	 */
	public function test_the_three_actions_are_hooked_for_logged_in_users_only() {
		foreach ( array( 'emr_bulk_process', 'emr_bulk_request', 'emr_bulk_settings' ) as $action ) {
			$this->assertNotFalse( has_action( 'wp_ajax_' . $action ), $action );
			$this->assertFalse( has_action( 'wp_ajax_nopriv_' . $action ) );
		}
	}

	public function test_a_wrong_nonce_is_refused_with_NONCE_FAILED_on_every_action() {
		foreach ( array( 'emr_bulk_process', 'emr_bulk_request', 'emr_bulk_settings' ) as $action ) {
			$json = $this->call( $action, array( 'nonce' => 'bad', 'screen_action' => 'getStatus' ) );
			$this->assertFalse( $json->status, $action );
			$this->assertSame( QueueController::NONCE_FAILED, $json->error, $action );
		}
	}

	public function test_a_user_without_manage_options_is_refused_with_NO_ACCESS() {
		$this->_setRole( 'editor' );
		$json = $this->request( 'getStatus' );
		$this->assertFalse( $json->status );
		$this->assertSame( QueueController::NO_ACCESS, $json->error );

		$json = $this->tick();
		$this->assertSame( QueueController::NO_ACCESS, $json->error );
		$this->assertFalse( get_transient( BulkAjaxController::SECRET_TRANSIENT ), 'No key claimed.' );
	}

	public function test_an_unknown_screen_action_answers_NO_ACTION() {
		$json = $this->request( 'doesNotExist' );
		$this->assertFalse( $json->status );
		$this->assertSame( QueueController::NO_ACTION, $json->error );
	}

	public function test_the_callback_is_echoed_and_the_processor_key_added() {
		update_option( 'emr_shortpixel_api_key', self::API_KEY );
		$this->tick();
		$json = $this->request( 'getStatus', array( 'callback' => 'emr.test' ) );
		$this->assertSame( 'emr.test', $json->callback );
		$this->assertSame( 'tab-one', $json->processorKey );
	}

	public function test_the_first_tick_claims_the_processor_key_and_another_key_is_refused_until_it_expires() {
		update_option( 'emr_shortpixel_api_key', self::API_KEY );
		$this->assertFalse( ( new BulkAjaxController() )->getProcessorKey() );

		$first = $this->tick( array( 'bulk-secret' => 'tab-one' ) );
		$this->assertSame( 1, $first->status, wp_json_encode( $first ) );
		$this->assertSame( 'tab-one', get_transient( BulkAjaxController::SECRET_TRANSIENT ) );
		$this->assertSame( 'tab-one', $first->processorKey );

		$other = $this->tick( array( 'bulk-secret' => 'tab-two' ) );
		$this->assertFalse( $other->status );
		$this->assertSame( QueueController::PROCESSOR_ACTIVE, $other->error );
		$this->assertSame( 'tab-one', $other->processorKey, 'The holder is told.' );
		$this->assertSame( 'tab-one', get_transient( BulkAjaxController::SECRET_TRANSIENT ), 'Still the first key.' );

		$same = $this->tick( array( 'bulk-secret' => 'tab-one' ) );
		$this->assertSame( 1, $same->status, 'The holder keeps going.' );

		delete_transient( BulkAjaxController::SECRET_TRANSIENT ); // expired
		$takeover = $this->tick( array( 'bulk-secret' => 'tab-two' ) );
		$this->assertSame( 1, $takeover->status );
		$this->assertSame( 'tab-two', get_transient( BulkAjaxController::SECRET_TRANSIENT ) );
	}

	public function test_recheckActive_claims_the_key_when_free_and_is_refused_otherwise() {
		set_transient( BulkAjaxController::SECRET_TRANSIENT, 'tab-one', 120 );
		$json = $this->request( 'recheckActive', array( 'bulk-secret' => 'tab-two' ) );
		$this->assertFalse( $json->status );
		$this->assertSame( QueueController::PROCESSOR_ACTIVE, $json->error );

		delete_transient( BulkAjaxController::SECRET_TRANSIENT );
		$json = $this->request( 'recheckActive', array( 'bulk-secret' => 'tab-two' ) );
		$this->assertTrue( $json->status );
		$this->assertSame( 'tab-two', $json->processorKey );
	}

	public function test_a_tick_without_a_key_is_the_queue_controllers_answer() {
		delete_option( 'emr_shortpixel_api_key' );
		$json = $this->tick();
		$this->assertFalse( $json->status );
		$this->assertSame( QueueController::APIKEY_FAILED, $json->error );
	}

	public function test_createBulk_stores_the_settings_creates_the_bulk_and_answers_the_stats() {
		$id   = $this->makeImageAttachment( 500, 300 );
		$json = $this->request( 'createBulk', array(
			'operation'          => 'upscale',
			'threshold'          => '900',
			'background_process' => 'true',
		) );

		$this->assertTrue( $json->status, wp_json_encode( $json ) );
		$this->assertTrue( $json->media->stats->is_preparing );
		$this->assertSame( 'upscale', $json->media->stats->operation );
		$this->assertObjectHasProperty( 'total', $json );

		$settings = BulkController::getSettings();
		$this->assertSame( 900, $settings['upscale_threshold'] );
		$this->assertTrue( $settings['background_process'] );
		$q = new BulkQueue();
		$this->assertSame( 'upscale', $q->getOperation() );
		$this->assertSame( 900, $q->getOptions()['threshold'] );
	}

	public function test_createBulk_for_the_background_passes_the_colour_options_and_sanitises_them() {
		$this->registerProductTypes();
		$json = $this->request( 'createBulk', array(
			'operation'       => 'background',
			'bg_type'         => 'solid',
			'bg_color'        => '#00FF00',
			'bg_transparency' => '150',
		) );

		$this->assertTrue( $json->status, wp_json_encode( $json ) );
		$settings = BulkController::getSettings();
		$this->assertSame( 'solid', $settings['bg_type'] );
		$this->assertSame( '#00FF00', $settings['bg_color'] );
		$this->assertSame( 100, $settings['bg_transparency'], 'Clamped to 100.' );
		$options = ( new BulkQueue() )->getOptions();
		$this->assertFalse( $options['do_transparent'] );
		$this->assertSame( '#00FF00', $options['replace_color'] );
		$this->assertSame( 100, $options['replace_transparency'] );

		$bad = $this->request( 'createBulk', array( 'operation' => 'background', 'bg_type' => 'weird', 'bg_color' => 'not-a-colour' ) );
		$this->assertTrue( $bad->status );
		$settings = BulkController::getSettings();
		$this->assertSame( 'transparent', $settings['bg_type'] );
		$this->assertSame( '#ffffff', $settings['bg_color'] );
	}

	/** @var bool */
	private $registeredProductTypes = false;

	private function registerProductTypes() {
		register_post_type( 'product', array( 'public' => true ) );
		register_post_type( 'product_variation', array( 'public' => false ) );
		$this->registeredProductTypes = true;
	}

	public function test_createBulk_for_an_unavailable_operation_is_refused_with_the_selectors_reason() {
		$this->assertFalse( post_type_exists( 'product' ), 'Precondition: no WooCommerce.' );
		$json = $this->request( 'createBulk', array( 'operation' => 'background' ) );

		$this->assertFalse( $json->status );
		$this->assertSame( QueueController::NO_ACTION, $json->error );
		$this->assertStringContainsString( 'WooCommerce', $json->message );
		$this->assertFalse( ( new BulkQueue() )->getStats()->is_preparing, 'No bulk was created.' );
	}

	public function test_startBulk_skipPreparing_and_finishBulk_drive_the_queue_states() {
		$id = $this->makeImageAttachment( 500, 300 );
		$this->request( 'createBulk', array( 'operation' => 'upscale' ) );
		$this->assertTrue( ( new BulkQueue() )->getStats()->is_preparing );

		$skipped = $this->request( 'skipPreparing' );
		$this->assertTrue( $skipped->status );
		$this->assertFalse( $skipped->media->stats->is_preparing );
		$this->assertFalse( $skipped->media->stats->is_running );

		$started = $this->request( 'startBulk' );
		$this->assertTrue( $started->status );
		$this->assertTrue( $started->media->stats->is_running );

		$finished = $this->request( 'finishBulk' );
		$this->assertSame( 1, $finished->status );
		$this->assertFalse( ( new BulkQueue() )->getStats()->bulk_running );
	}

	public function test_startRestore_creates_the_restore_bulk_for_the_operation() {
		$json = $this->request( 'startRestore', array( 'operation' => 'upscale' ) );
		$this->assertTrue( $json->status );
		$this->assertSame( BulkQueue::CUSTOM_OP_RESTORE, $json->media->stats->customOperation );
		$q = new BulkQueue();
		$this->assertSame( 'upscale', $q->getOperation() );
		$this->assertSame( 5, $q->getShortQ()->getOption( 'numitems' ) );
	}

	public function test_settings_request_saves_background_process_and_threshold() {
		$json = $this->call( 'emr_bulk_settings', array(
			'nonce'              => wp_create_nonce( 'emr_bulk_settings' ),
			'background_process' => 'false',
			'threshold'          => '5000',
		) );
		$this->assertTrue( $json->status );
		$this->assertFalse( $json->settings->background_process );
		$this->assertSame( 1200, $json->settings->upscale_threshold, 'Above the widest source: capped to the factor table.' );
		$this->assertSame( 1200, BulkController::getSettings()['upscale_threshold'] );
	}

	public function test_loadLogFile_parses_a_finished_log_and_refuses_bad_names() {
		$dir  = BackupController::getBackupDirectory()->getPath();
		$name = 'bulk_background_1700000000';
		file_put_contents( $dir . $name . '.log', "2026-10-30 10:00:00|a.jpg| 12|Invalid image format;\n2026-10-30 10:00:01|b.jpg| 13|Too big;" );
		update_option( BulkController::LOG_OPTION, array( array( 'logfile' => $name . '.log', 'date' => 1700000000, 'type' => 'background', 'processed' => 0, 'fatal_errors' => 2 ) ), false );

		$json = $this->request( 'loadLogFile', array( 'loadFile' => $name ) );
		$this->assertTrue( $json->status );
		$this->assertFalse( $json->log->is_error );
		$this->assertCount( 3, $json->log->results, 'Header plus two lines.' );
		$this->assertSame( 'a.jpg', $json->log->results[1]->filename );
		$this->assertSame( 12, $json->log->results[1]->id );
		$this->assertSame( 'Invalid image format', $json->log->results[1]->error );
		$this->assertStringContainsString( 'post=12', $json->log->results[1]->link );

		$missing = $this->request( 'loadLogFile', array( 'loadFile' => 'nope' ) );
		$this->assertTrue( $missing->log->is_error );
		$traversal = $this->request( 'loadLogFile', array( 'loadFile' => '../../wp-config' ) );
		$this->assertTrue( $traversal->log->is_error );
	}

	public function test_a_full_run_through_the_ajax_actions_processes_an_image() {
		update_option( 'emr_shortpixel_api_key', self::API_KEY );
		$id = $this->makeImageAttachment( 500, 300 );
		$this->request( 'createBulk', array( 'operation' => 'upscale' ) );
		for ( $i = 0; $i < 10; $i++ ) {
			$json = $this->tick();
			$this->assertSame( 1, $json->status, wp_json_encode( $json ) );
			if ( BulkQueue::RESULT_PREPARING_DONE === $json->media->qstatus ) {
				break;
			}
		}
		$this->assertSame( '1', $json->media->stats->total );
		$this->request( 'startBulk' );

		$json = $this->tick();
		$this->assertSame( BulkQueue::RESULT_ITEMS, $json->media->qstatus );
		$this->assertSame( $id, $json->media->results[0]->item_id );
		$this->assertTrue( $json->media->results[0]->is_done, wp_json_encode( $json->media->results[0] ) );
		$this->assertFalse( $json->media->results[0]->is_error );
		$this->assertSame( 50, wp_get_attachment_metadata( $id )['width'], 'Replaced by the mock result.' );
		$this->assertSame( 4, BulkImage::getById( $id )->getMarker()['upscale']['factor'] );
		$this->assertCount( 1, $this->mock->requestsTo( 'reducer.php' ) );
	}
}
