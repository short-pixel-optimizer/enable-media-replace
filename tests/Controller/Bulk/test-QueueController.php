<?php
/**
 * Tests for EnableMediaReplace\Controller\Bulk\QueueController
 * (classes/Controller/Bulk/QueueController.php), ported from ShortPixel
 * Image Optimizer's QueueController tests and extended with the gates
 * processQueue() applies before a tick (API key, Unlimited plan) and the
 * tick itself with a stubbed API.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';

use EnableMediaReplace\Controller\Bulk\BulkApi;
use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\Controller\Bulk\QueueController;
use EnableMediaReplace\Model\Bulk\BulkImage;
use EnableMediaReplace\Model\Bulk\QueueItem;

class BulkQueueControllerTest extends WP_UnitTestCase {

	use EMR_BulkTestHelpers;

	/** @var array */
	private $requests = array();

	/** @var callable|null */
	private $answer;

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
		( new BulkQueue() )->resetQueue();
		$this->requests = array();
		$this->answer   = null;
		add_filter( 'pre_http_request', array( $this, 'stub' ), 10, 3 );
		add_filter( 'pre_http_request', array( $this, 'failFast' ), 9999, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'stub' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'failFast' ), 9999 );
		remove_all_filters( 'emr/bulk/plan_check_ttl' );
		$this->bulkTearDown();
		parent::tear_down();
	}

	public function stub( $preempt, $args, $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		if ( is_callable( $this->answer ) ) {
			return call_user_func( $this->answer, $url, $args );
		}
		return $preempt;
	}

	public function failFast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->fail( 'Unstubbed HTTP request to ' . $url );
	}

	private function response( $body ) {
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'headers' => array(), 'cookies' => array() );
	}

	/** api-status answers Unlimited; the reducer answers pending unless overridden. */
	private function answerUnlimited( $unlimited = 'true', $reducer = null ) {
		$this->answer = function ( $url ) use ( $unlimited, $reducer ) {
			if ( false !== strpos( $url, 'api-status.php' ) ) {
				return $this->response( array( 'Status' => array( 'Code' => 2, 'Message' => '' ), 'Unlimited' => $unlimited ) );
			}
			if ( false !== strpos( $url, 'cleanup.php' ) ) {
				return $this->response( array( 'Status' => array( 'Code' => '2' ) ) );
			}
			if ( is_callable( $reducer ) ) {
				return call_user_func( $reducer, $url );
			}
			return $this->response( array( array( 'Status' => array( 'Code' => '1', 'Message' => 'Pending' ) ) ) );
		};
	}

	private function invokePrivate( QueueController $c, $method, array $args = array() ) {
		$m = new ReflectionMethod( QueueController::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	private function urlsTo( $needle ) {
		return array_values( array_filter( $this->requests, function ( $r ) use ( $needle ) {
			return false !== strpos( $r['url'], $needle );
		} ) );
	}

	public function test_error_constants_match_spios_ajax_controller_plus_plan_expired() {
		$this->assertSame( -1, QueueController::PROCESSOR_ACTIVE );
		$this->assertSame( -2, QueueController::NONCE_FAILED );
		$this->assertSame( -3, QueueController::NO_ACTION );
		$this->assertSame( -4, QueueController::APIKEY_FAILED );
		$this->assertSame( -5, QueueController::NOQUOTA );
		$this->assertSame( -6, QueueController::SERVER_ERROR );
		$this->assertSame( -7, QueueController::NO_ACCESS );
		$this->assertSame( -8, QueueController::PLAN_EXPIRED );
		$this->assertSame( 'emr_bulk_plan_ok', QueueController::PLAN_TRANSIENT );
	}

	public function test_constructor_defaults_to_bulk_and_getQueue_returns_the_bulk_queue() {
		$c = new QueueController();
		$p = new ReflectionProperty( QueueController::class, 'args' );
		$p->setAccessible( true );
		$this->assertTrue( $p->getValue( $c )['is_bulk'] );
		$this->assertInstanceOf( BulkQueue::class, $c->getQueue() );
		$this->assertInstanceOf( BulkQueue::class, $c->getQueue( 'anything' ) );
		$this->assertNull( $c->getLastQueueStatus() );
	}

	public function test_lastId_round_trip() {
		$p = new ReflectionProperty( QueueController::class, 'lastId' );
		$p->setAccessible( true );
		$p->setValue( null, null );
		$this->assertNull( QueueController::getLastId() );
		$this->invokePrivate( new QueueController(), 'setLastID', array( 42 ) );
		$this->assertSame( 42, QueueController::getLastId() );
	}

	public function test_getJsonResponse_shape() {
		$json = $this->invokePrivate( new QueueController(), 'getJsonResponse' );
		$this->assertIsObject( $json );
		foreach ( array( 'status', 'result', 'results', 'message' ) as $field ) {
			$this->assertNull( $json->$field );
		}
	}

	public function test_queueToJson_maps_every_result_status() {
		$c = new QueueController();

		$json = $this->invokePrivate( $c, 'queueToJson', array( (object) array( 'qstatus' => BulkQueue::RESULT_PREPARING, 'items' => 5 ) ) );
		$this->assertStringContainsString( '5', $json->message );
		$this->assertSame( BulkQueue::RESULT_PREPARING, $json->qstatus );

		$json = $this->invokePrivate( $c, 'queueToJson', array( (object) array( 'qstatus' => BulkQueue::RESULT_PREPARING_OVERLIMIT, 'items' => 5 ) ) );
		$this->assertNotEmpty( $json->message );

		$stats = (object) array( 'total' => 10 );
		$json  = $this->invokePrivate( $c, 'queueToJson', array( (object) array( 'qstatus' => BulkQueue::RESULT_PREPARING_DONE, 'items' => 0, 'stats' => $stats ) ) );
		$this->assertStringContainsString( '10', $json->message );
		$this->assertSame( $stats, $json->stats );

		$items = array( (object) array( 'id' => 1 ), (object) array( 'id' => 2 ) );
		$json  = $this->invokePrivate( $c, 'queueToJson', array( (object) array( 'qstatus' => BulkQueue::RESULT_ITEMS, 'items' => $items ) ) );
		$this->assertSame( $items, $json->results );

		$this->assertNotEmpty( $this->invokePrivate( $c, 'queueToJson', array( (object) array( 'qstatus' => BulkQueue::RESULT_EMPTY ) ) )->message );
		$this->assertNotEmpty( $this->invokePrivate( $c, 'queueToJson', array( (object) array( 'qstatus' => BulkQueue::RESULT_QUEUE_EMPTY ) ) )->message );
		$this->assertTrue( $this->invokePrivate( $c, 'queueToJson', array( (object) array( 'qstatus' => BulkQueue::RESULT_RECOUNT ) ) )->has_error );
		$this->assertStringContainsString( '9999', $this->invokePrivate( $c, 'queueToJson', array( (object) array( 'qstatus' => 9999 ) ) )->message );
	}

	public function test_numberFormatStats_formats_numbers_keeps_booleans_and_gives_percentages_two_decimals() {
		$c       = new QueueController();
		$results = new stdClass();
		$results->media = new stdClass();
		$results->media->stats = (object) array( 'done' => 1234, 'is_running' => true, 'percentage_done' => round( 100 / 3, 4 ), 'images' => (object) array( 'images' => 2000 ) );

		$out = $this->invokePrivate( $c, 'numberFormatStats', array( $results ) );

		$this->assertSame( '1,234', $out->media->stats->done );
		$this->assertTrue( $out->media->stats->is_running );
		$this->assertStringContainsString( '.', (string) $out->media->stats->percentage_done );
		$this->assertSame( '2,000', $out->media->stats->images->images );
	}

	public function test_getStartupData_returns_media_and_total_stats() {
		$data = ( new QueueController() )->getStartupData( false );
		$this->assertObjectHasProperty( 'media', $data );
		$this->assertObjectHasProperty( 'total', $data );
		$this->assertSame( 0, $data->media->stats->total );
		$this->assertEquals( $data->media->stats, $data->total->stats );
		$this->assertNotSame( $data->media->stats, $data->total->stats );

		$formatted = ( new QueueController() )->getStartupData();
		$this->assertSame( '0', $formatted->media->stats->total );
	}

	public function test_processQueue_without_an_api_key_stops_with_APIKEY_FAILED_and_no_request() {
		delete_option( 'emr_shortpixel_api_key' );
		$json = ( new QueueController() )->processQueue();

		$this->assertFalse( $json->status );
		$this->assertSame( QueueController::APIKEY_FAILED, $json->error );
		$this->assertNotEmpty( $json->message );
		$this->assertCount( 0, $this->requests );
	}

	public function test_processQueue_without_an_unlimited_plan_stops_with_PLAN_EXPIRED() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->answerUnlimited( 'false' );

		$json = ( new QueueController() )->processQueue();

		$this->assertFalse( $json->status );
		$this->assertSame( QueueController::PLAN_EXPIRED, $json->error );
		$this->assertCount( 1, $this->urlsTo( 'api-status.php' ) );
		$this->assertFalse( get_transient( QueueController::PLAN_TRANSIENT ) );
	}

	public function test_processQueue_caches_a_positive_plan_check_in_a_transient() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->answerUnlimited( 'true' );
		add_filter( 'emr/bulk/plan_check_ttl', function () {
			return 123;
		} );

		$first = ( new QueueController() )->processQueue();
		$this->assertSame( 1, $first->status );
		$this->assertCount( 1, $this->urlsTo( 'api-status.php' ) );
		$this->assertSame( 1, (int) get_transient( QueueController::PLAN_TRANSIENT ) );
		$this->assertEqualsWithDelta( time() + 123, (int) get_option( '_transient_timeout_' . QueueController::PLAN_TRANSIENT ), 5 );

		( new QueueController() )->processQueue();
		$this->assertCount( 1, $this->urlsTo( 'api-status.php' ), 'Cached: no second call.' );
	}

	public function test_processQueue_for_a_restore_skips_the_key_and_plan_checks() {
		delete_option( 'emr_shortpixel_api_key' );
		BulkController::getInstance()->createNewBulk( array( 'operation' => 'background', 'customOp' => BulkQueue::CUSTOM_OP_RESTORE ) );

		$json = ( new QueueController() )->processQueue();

		$this->assertSame( 1, $json->status );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( BulkQueue::RESULT_PREPARING_DONE, $json->media->qstatus, 'Nothing marked: preparing ends at once.' );
	}

	public function test_processQueue_idle_tick_returns_queue_empty_with_media_and_total() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->answerUnlimited();

		$json = ( new QueueController() )->processQueue();

		$this->assertSame( 1, $json->status );
		$this->assertSame( BulkQueue::RESULT_QUEUE_EMPTY, $json->media->qstatus );
		$this->assertSame( '0', $json->media->stats->total );
		$this->assertSame( '0', $json->total->stats->total );
	}

	public function test_processQueue_drives_prepare_and_sends_a_dequeued_item_to_the_api() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->answerUnlimited();
		$id   = $this->makeImageAttachment( 300, 200 );
		$ctrl = BulkController::getInstance();
		$ctrl->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );

		$c    = new QueueController();
		$json = $c->processQueue();
		$this->assertSame( BulkQueue::RESULT_PREPARING, $json->media->qstatus );
		$json = $c->processQueue();
		$this->assertSame( BulkQueue::RESULT_PREPARING_DONE, $json->media->qstatus );
		$this->assertCount( 0, $this->urlsTo( 'reducer.php' ), 'Nothing is sent before the bulk starts.' );

		$ctrl->startBulk();
		$json = $c->processQueue();

		$this->assertSame( BulkQueue::RESULT_ITEMS, $json->media->qstatus );
		$this->assertCount( 1, $json->media->results );
		$result = $json->media->results[0];
		$this->assertSame( $id, $result->item_id );
		$this->assertSame( BulkApi::STATUS_UNCHANGED, $result->apiStatus, 'The stubbed reducer answers pending.' );
		$this->assertFalse( $result->is_done );
		$this->assertSame( basename( get_attached_file( $id ) ), $result->filename );
		$this->assertCount( 1, $this->urlsTo( 'cleanup.php' ), 'First try refreshes.' );
		$this->assertCount( 1, $this->urlsTo( 'reducer.php' ) );
		$this->assertSame( $id, QueueController::getLastId() );
		$this->assertSame( '1', $json->media->stats->in_process );
	}

	public function test_processQueue_stops_with_NOQUOTA_when_the_api_reports_quota_exceeded() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		set_transient( QueueController::PLAN_TRANSIENT, 1, 600 );
		$this->answerUnlimited( 'true', function () {
			return $this->response( array( 'Status' => array( 'Code' => -403, 'Message' => 'Quota exceeded' ) ) );
		} );
		$id   = $this->makeImageAttachment( 300, 200 );
		$ctrl = BulkController::getInstance();
		$ctrl->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$c = new QueueController();
		while ( BulkQueue::RESULT_PREPARING_DONE !== $c->processQueue()->media->qstatus ) {
		}
		$ctrl->startBulk();

		$json = $c->processQueue();

		$this->assertFalse( $json->status );
		$this->assertSame( QueueController::NOQUOTA, $json->error );
		$this->assertSame( QueueController::NOQUOTA, $json->media->results[0]->error );
		$this->assertFalse( get_transient( QueueController::PLAN_TRANSIENT ), 'The cached plan check is dropped.' );
		$this->assertFalse( $json->media->results[0]->is_done, 'Quota is retryable, the item stays.' );
	}

	public function test_addItemToQueue_enqueues_appends_actions_and_refuses_unknown_actions() {
		$id    = $this->makeImageAttachment( 300, 200 );
		$image = BulkImage::getById( $id );
		$c     = new QueueController();

		$result = $c->addItemToQueue( $image, array( 'action' => 'remove_background' ) );
		$this->assertFalse( $result->is_error );
		$this->assertStringContainsString( $image->getFileName(), $result->message );
		$this->assertSame( BulkQueue::RESULT_ITEMS, $c->getLastQueueStatus() );
		$this->assertCount( 1, $this->queueRows( $id ) );

		$again = $c->addItemToQueue( $image, array( 'action' => 'scale_image' ) );
		$this->assertFalse( $again->is_done, 'A different action is appended.' );
		$this->assertSame( array( 'scale_image' ), $c->getQueue()->getItem( $id )->data()->next_actions );

		$same = $c->addItemToQueue( $image, array( 'action' => 'scale_image' ) );
		$this->assertTrue( $same->is_done, 'Already queued with that action.' );

		$other = BulkImage::getById( $this->makeImageAttachment( 300, 200 ) );
		$bad   = $c->addItemToQueue( $other, array( 'action' => 'optimize' ) );
		$this->assertTrue( $bad->is_error );
		$this->assertStringContainsString( 'No action', $bad->message );
		$this->assertCount( 0, $this->queueRows( $other->get( 'id' ) ) );
	}

	public function test_addItemToQueue_refuses_an_item_that_fails_the_action_check() {
		$id    = $this->makeImageAttachment( 1300, 200 );
		$image = BulkImage::getById( $id );
		$result = ( new QueueController() )->addItemToQueue( $image, array( 'action' => 'scale_image' ) );

		$this->assertTrue( $result->is_error );
		$this->assertTrue( $result->is_done );
		$this->assertSame( QueueItem::FILE_STATUS_ERROR, $result->fileStatus );
		$this->assertCount( 0, $this->queueRows( $id ) );
	}

	public function test_resetQueues_and_uninstallPlugin_clear_the_queue() {
		$id = $this->makeImageAttachment( 100, 100 );
		( new QueueController() )->addItemToQueue( BulkImage::getById( $id ) );
		$this->assertCount( 1, $this->queueRows() );

		QueueController::resetQueues();
		$this->assertCount( 0, $this->queueRows() );

		( new QueueController() )->addItemToQueue( BulkImage::getById( $id ) );
		QueueController::uninstallPlugin();
		$this->assertCount( 0, $this->queueRows() );
		$this->assertFalse( get_option( 'shortqwp_emr' ) );
	}
}
