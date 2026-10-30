<?php
/**
 * Tests for EnableMediaReplace\Controller\Bulk\BulkApi (classes/Controller/Bulk/BulkApi.php),
 * ported from ShortPixel Image Optimizer's ApiController tests.
 *
 * handleResponse() / handleActionResponse() are exercised with crafted
 * response arrays; processActionItem() with a pre_http_request stub that
 * records the body and answers like the reducer, so no traffic leaves the
 * machine. A fail-fast filter at priority 9999 refuses anything unstubbed.
 *
 * Where SPIO pins handleActionResponse() returning null for an unknown
 * status, the EMR port returns a failure, asserted positively here.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';

use EnableMediaReplace\Controller\Bulk\BulkApi;
use EnableMediaReplace\Model\Bulk\BulkImage;
use EnableMediaReplace\Model\Bulk\QueueItem;

class BulkApiTest extends WP_UnitTestCase {

	use EMR_BulkTestHelpers;

	/** @var BulkApi */
	private $api;

	/** @var array Captured requests: url and decoded body. */
	private $requests = array();

	/** @var callable|null */
	private $answer;

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
		$this->api      = new BulkApi();
		$this->requests = array();
		$this->answer   = null;
		add_filter( 'pre_http_request', array( $this, 'stub' ), 10, 3 );
		add_filter( 'pre_http_request', array( $this, 'failFast' ), 9999, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'stub' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'failFast' ), 9999 );
		remove_all_filters( 'emr/bulk/api/request' );
		$this->bulkTearDown();
		parent::tear_down();
	}

	public function stub( $preempt, $args, $url ) {
		$this->requests[] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ), 'args' => $args );
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

	private function response( $body, $code = 200 ) {
		return array(
			'response' => array( 'code' => $code, 'message' => ( 200 === $code ) ? 'OK' : 'Error' ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	private function invoke( $method, array $args ) {
		$m = new ReflectionMethod( BulkApi::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $this->api, ...$args );
	}

	private function item( $action = 'remove_background', array $data = array() ) {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->data()->action = $action;
		foreach ( $data as $k => $v ) {
			$q->data()->$k = $v;
		}
		return $q;
	}

	private function statusBody( $code, $message ) {
		return array( 'Status' => array( 'Code' => $code, 'Message' => $message ) );
	}

	private function imageEntry( $code, $lossless = '', $original = '' ) {
		$entry = array( 'Status' => array( 'Code' => (string) $code, 'Message' => 'msg' . $code ) );
		if ( '' !== $lossless ) {
			$entry['LosslessURL'] = $lossless;
			$entry['OriginalURL'] = $original;
		}
		return array( $entry );
	}

	public function test_status_constants_match_spio() {
		$this->assertSame( 10, BulkApi::STATUS_ENQUEUED );
		$this->assertSame( 2, BulkApi::STATUS_SUCCESS );
		$this->assertSame( 1, BulkApi::STATUS_WAITING );
		$this->assertSame( 0, BulkApi::STATUS_UNCHANGED );
		$this->assertSame( -1, BulkApi::STATUS_ERROR );
		$this->assertSame( -2, BulkApi::STATUS_FAIL );
		$this->assertSame( -3, BulkApi::STATUS_QUOTA_EXCEEDED );
		$this->assertSame( -6, BulkApi::STATUS_NO_KEY );
		$this->assertSame( -404, BulkApi::STATUS_QUEUE_FULL );
		$this->assertSame( -500, BulkApi::STATUS_MAINTENANCE );
		$this->assertSame( -1000, BulkApi::STATUS_NOT_API );
		$this->assertSame( -903, BulkApi::ERR_TIMEOUT );
		$this->assertSame( 'https://api.shortpixel.com/v2/reducer.php', $this->api->getEndPoint() );
	}

	/**
	 * @dataProvider knownErrorCodes
	 */
	public function test_handleResponse_maps_known_top_level_error_codes( $code, $status, $done ) {
		$result = $this->invoke( 'handleResponse', array( $this->item(), $this->response( $this->statusBody( $code, 'API message ' . $code ) ) ) );
		$this->assertSame( $status, $result['apiStatus'], "code $code" );
		$this->assertTrue( $result['is_error'] );
		$this->assertSame( $done, $result['is_done'], "code $code" );
	}

	public function knownErrorCodes() {
		return array(
			'-102 invalid url'      => array( -102, BulkApi::STATUS_ERROR, true ),
			'-111 too big'          => array( -111, BulkApi::STATUS_ERROR, true ),
			'-201 invalid image'    => array( -201, BulkApi::STATUS_ERROR, true ),
			'-301 quota'            => array( -301, BulkApi::STATUS_QUOTA_EXCEEDED, false ),
			'-403 quota'            => array( -403, BulkApi::STATUS_QUOTA_EXCEEDED, false ),
			'-302 not available'    => array( -302, BulkApi::STATUS_FAIL, true ),
			'-306 mixed domains'    => array( -306, BulkApi::STATUS_FAIL, true ),
			'-401 invalid key'      => array( -401, BulkApi::STATUS_NO_KEY, true ),
			'-402 wrong key'        => array( -402, BulkApi::STATUS_NO_KEY, true ),
			'-404 queue full'       => array( -404, BulkApi::STATUS_QUEUE_FULL, false ),
			'-500 maintenance'      => array( -500, BulkApi::STATUS_MAINTENANCE, false ),
		);
	}

	public function test_handleResponse_known_error_code_inside_the_image_entry_is_mapped_too() {
		$result = $this->invoke( 'handleResponse', array( $this->item(), $this->response( $this->imageEntry( -403 ) ) ) );
		$this->assertSame( BulkApi::STATUS_QUOTA_EXCEEDED, $result['apiStatus'] );
		$this->assertFalse( $result['is_done'] );
	}

	public function test_handleResponse_unrecognized_body_returns_fail() {
		$result = $this->invoke( 'handleResponse', array( $this->item(), $this->response( array( 'garbage' => true ) ) ) );
		$this->assertSame( BulkApi::STATUS_FAIL, $result['apiStatus'] );
		$this->assertTrue( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
	}

	public function test_handleResponse_image_entry_for_unknown_action_fails() {
		$result = $this->invoke( 'handleResponse', array( $this->item( 'restore' ), $this->response( $this->imageEntry( 2, 'https://x/l.jpg', 'https://x/o.jpg' ) ) ) );
		$this->assertSame( BulkApi::STATUS_FAIL, $result['apiStatus'] );
	}

	public function test_handleActionResponse_waiting_codes_keep_the_item_pending() {
		foreach ( array( 0, 1 ) as $code ) {
			$result = $this->invoke( 'handleActionResponse', array( $this->item(), $this->imageEntryObjects( $code ) ) );
			$this->assertSame( BulkApi::STATUS_UNCHANGED, $result['apiStatus'] );
			$this->assertFalse( $result['is_error'] );
			$this->assertFalse( $result['is_done'] );
		}
	}

	private function imageEntryObjects( $code, $lossless = '', $original = '' ) {
		return json_decode( wp_json_encode( $this->imageEntry( $code, $lossless, $original ) ) );
	}

	public function test_handleActionResponse_success_returns_the_result_urls_and_is_done() {
		$result = $this->invoke( 'handleActionResponse', array( $this->item( 'scale_image' ), $this->imageEntryObjects( '2', 'https://x/l.jpg', 'https://x/o.jpg' ) ) );
		$this->assertSame( BulkApi::STATUS_SUCCESS, $result['apiStatus'] );
		$this->assertFalse( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
		$this->assertSame( 'https://x/l.jpg', $result['optimized'] );
		$this->assertSame( 'https://x/o.jpg', $result['original'] );
	}

	public function test_handleActionResponse_success_without_a_result_url_is_a_failure() {
		$entry = $this->imageEntryObjects( '2' );
		$result = $this->invoke( 'handleActionResponse', array( $this->item(), $entry ) );
		$this->assertSame( BulkApi::STATUS_FAIL, $result['apiStatus'] );
		$this->assertTrue( $result['is_done'] );
	}

	/**
	 * SPIO's handleActionResponse() falls off the end for any other code and
	 * returns null; the EMR port returns a terminal failure carrying the
	 * API message.
	 */
	public function test_handleActionResponse_unknown_status_returns_a_failure_with_the_api_message() {
		$result = $this->invoke( 'handleActionResponse', array( $this->item(), $this->imageEntryObjects( -999 ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( BulkApi::STATUS_ERROR, $result['apiStatus'] );
		$this->assertTrue( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
		$this->assertSame( 'msg-999', $result['message'] );
	}

	public function test_parseResponse_decodes_json_and_recovers_the_first_object_from_noisy_bodies() {
		$this->assertSame( array( 'a' => 1 ), (array) $this->invoke( 'parseResponse', array( $this->response( '{"a":1}' ) ) ) );
		$noisy = $this->invoke( 'parseResponse', array( $this->response( 'Warning: x on line 3 {"Status":{"Code":2}} trailing' ) ) );
		$this->assertSame( 2, $noisy['Status']->Code );
		$broken = $this->invoke( 'parseResponse', array( $this->response( 'not json at all' ) ) );
		$this->assertSame( BulkApi::STATUS_ERROR, $broken['status'] );
	}

	public function test_return_helpers_shapes() {
		$this->assertSame( array( 'apiStatus' => -1, 'message' => 'm', 'is_error' => true, 'is_done' => true ), $this->api->returnFailure( -1, 'm' ) );
		$this->assertSame( array( 'apiStatus' => -3, 'message' => 'm', 'is_error' => true, 'is_done' => false ), $this->api->returnRetry( -3, 'm' ) );
		$this->assertSame( array( 'apiStatus' => 0, 'message' => false, 'is_error' => false, 'is_done' => false ), $this->api->returnOK() );
		$ok = $this->api->returnSuccess( array( 'optimized' => 'u' ) );
		$this->assertTrue( $ok['is_done'] );
		$this->assertArrayNotHasKey( 'message', $ok );
		$this->assertSame( 'u', $ok['optimized'] );
		$partial = $this->api->returnSuccess( array(), BulkApi::STATUS_PARTIAL_SUCCESS, 'half' );
		$this->assertArrayNotHasKey( 'is_done', $partial );
		$this->assertSame( 'half', $partial['message'] );
	}

	public function test_processActionItem_without_an_api_key_fails_without_a_request() {
		delete_option( 'emr_shortpixel_api_key' );
		$q = $this->item( 'remove_background', array( 'urls' => array( 'https://example.org/a.jpg' ), 'paramlist' => array( 'bg_remove' => 1, 'refresh' => true ) ) );

		$this->api->processActionItem( $q );

		$this->assertSame( BulkApi::STATUS_NO_KEY, $q->result()->apiStatus );
		$this->assertTrue( $q->result()->is_done );
		$this->assertCount( 0, $this->requests );
	}

	public function test_processActionItem_first_try_purges_then_posts_the_single_flow_body_for_background() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$image = BulkImage::getById( $this->makeImageAttachment( 300, 200 ) );
		$q     = new QueueItem( array( 'imageModel' => $image ) );
		$q->newRemoveBackgroundAction( array( 'do_transparent' => false, 'replace_color' => '#ffffff', 'replace_transparency' => 100 ) );
		$q->set( 'queueItem', (object) array( 'item_id' => $image->get( 'id' ) ) );

		$this->answer = function ( $url ) use ( $image ) {
			if ( false !== strpos( $url, 'cleanup.php' ) ) {
				return $this->response( array( 'Status' => array( 'Code' => '2' ) ) );
			}
			return $this->response( array( array( 'Status' => array( 'Code' => '1', 'Message' => 'Pending' ), 'OriginalURL' => $image->getUrl() ) ) );
		};

		$this->api->processActionItem( $q );

		$this->assertCount( 2, $this->requests );
		$this->assertStringContainsString( 'cleanup.php', $this->requests[0]['url'] );
		$this->assertSame( array( $image->getUrl() ), $this->requests[0]['body']['urllist'] );
		$this->assertSame( $image->get( 'id' ), $this->requests[0]['body']['item_id'] );

		$body = $this->requests[1]['body'];
		$this->assertStringContainsString( 'reducer.php', $this->requests[1]['url'] );
		$this->assertSame( 'EMR' . EMR_VERSION, $body['plugin_version'] );
		$this->assertSame( 'abcdEFGHIJKLMNOPwxyz', $body['key'] );
		$this->assertSame( array( $image->getUrl() ), $body['urllist'] );
		$this->assertSame( 0, $body['lossy'] );
		$this->assertSame( $image->get( 'id' ), $body['item_id'] );
		$this->assertTrue( $body['refresh'] );
		$this->assertSame( '#ffffffFF', $body['bg_remove'] );
		$this->assertArrayNotHasKey( 'upscale', $body );
		$this->assertTrue( $this->requests[1]['args']['blocking'] );
		$this->assertSame( 15, $this->requests[1]['args']['timeout'] );

		$this->assertSame( BulkApi::STATUS_UNCHANGED, $q->result()->apiStatus, 'Pending keeps the item waiting.' );
		$this->assertFalse( $q->result()->is_done );
	}

	public function test_processActionItem_later_tries_do_not_refresh_and_send_upscale_instead_of_bg_remove() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$image = BulkImage::getById( $this->makeImageAttachment( 500, 200 ) );
		$q     = new QueueItem( array( 'imageModel' => $image ) );
		$q->newScaleImageAction( array( 'scale' => 2 ) );
		$q->data()->tries = 3;

		$this->answer = function () use ( $image ) {
			return $this->response( array( array( 'Status' => array( 'Code' => '2', 'Message' => 'Success' ), 'OriginalURL' => $image->getUrl(), 'LosslessURL' => 'https://api.shortpixel.com/f/x.jpg', 'LossyURL' => 'https://api.shortpixel.com/f/y.jpg' ) ) );
		};

		$this->api->processActionItem( $q );

		$this->assertCount( 1, $this->requests, 'No cleanup after the first try.' );
		$body = $this->requests[0]['body'];
		$this->assertFalse( $body['refresh'] );
		$this->assertSame( 2, $body['upscale'] );
		$this->assertArrayNotHasKey( 'bg_remove', $body );

		$this->assertSame( BulkApi::STATUS_SUCCESS, $q->result()->apiStatus );
		$this->assertTrue( $q->result()->is_done );
		$this->assertSame( 'https://api.shortpixel.com/f/x.jpg', $q->result()->optimized );
	}

	public function test_processActionItem_terminal_error_dumps_the_item_afterwards() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$q = $this->item( 'remove_background', array( 'urls' => array( 'https://example.org/a.jpg' ), 'paramlist' => array( 'bg_remove' => 1, 'refresh' => false ), 'tries' => 1 ) );

		$this->answer = function ( $url ) {
			if ( false !== strpos( $url, 'cleanup.php' ) ) {
				return $this->response( array( 'Status' => array( 'Code' => '2' ) ) );
			}
			return $this->response( $this->statusBody( -201, 'Invalid image format' ) );
		};

		$this->api->processActionItem( $q );

		$this->assertSame( BulkApi::STATUS_ERROR, $q->result()->apiStatus );
		$this->assertSame( 'Invalid image format', $q->result()->message );
		$this->assertCount( 2, $this->requests );
		$this->assertStringContainsString( 'reducer.php', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'cleanup.php', $this->requests[1]['url'] );
	}

	public function test_doRequest_maps_wp_errors_and_http_errors() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$data = array( 'urls' => array( 'https://example.org/a.jpg' ), 'paramlist' => array( 'bg_remove' => 1, 'refresh' => false ), 'tries' => 1 );

		$this->answer = function () {
			return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		};
		$q = $this->item( 'remove_background', $data );
		$this->api->processActionItem( $q );
		$this->assertSame( BulkApi::STATUS_CONNECTION_ERROR, $q->result()->apiStatus );
		$this->assertFalse( $q->result()->is_done, 'A timeout retries.' );

		$this->answer = function () {
			return new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem' );
		};
		$q = $this->item( 'remove_background', $data );
		$this->api->processActionItem( $q );
		$this->assertTrue( $q->result()->is_done, 'An SSL error is terminal.' );

		$this->answer = function ( $url ) {
			if ( false !== strpos( $url, 'cleanup.php' ) ) {
				return $this->response( '{}' );
			}
			return $this->response( array( 'error' => 'Forbidden by proxy' ), 403 );
		};
		$q = $this->item( 'remove_background', $data );
		$this->api->processActionItem( $q );
		$this->assertSame( 403, $q->result()->apiStatus );
		$this->assertSame( 'Forbidden by proxy', $q->result()->message );
		$this->assertTrue( $q->result()->is_done );
	}

	public function test_request_body_filter_is_applied() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		add_filter( 'emr/bulk/api/request', function ( $body, $item_id ) {
			$body['marker'] = 'item-' . $item_id;
			return $body;
		}, 10, 2 );
		$this->answer = function () {
			return $this->response( '[]' );
		};
		$q = $this->item( 'remove_background', array( 'urls' => array( 'https://example.org/a.jpg' ), 'paramlist' => array( 'bg_remove' => 1, 'refresh' => false ), 'tries' => 1 ) );

		$this->api->processActionItem( $q );

		$this->assertSame( 'item-1', $this->requests[0]['body']['marker'] );
	}
}
