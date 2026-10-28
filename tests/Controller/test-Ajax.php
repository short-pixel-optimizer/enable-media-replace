<?php
/**
 * Tests for EnableMediaReplace\Ajax (classes/ajax.php): the
 * `wp_ajax_emr_remove_background`, `wp_ajax_emr_save_api_key` and
 * `wp_ajax_emr_delete_api_key` endpoints.
 *
 * The endpoints end in wp_send_json*(); WP_Ajax_UnitTestCase turns the
 * wp_die() inside into an exception and keeps the output in
 * $this->_last_response. remove_background() answers a wrong nonce with a
 * bare die('Wrong nonce'), which no handler can intercept and which would
 * end the PHPUnit process, so that path is reached through check_nonce()
 * (by reflection) only; the full request belongs to the integration suite.
 *
 * _handleAjax() fires admin_init first, where WordPress core schedules its
 * update checks (HTTP to wordpress.org) and EMR's adminInit() would add a
 * second Ajax instance; the admin_init chain is dropped for these tests
 * (the hooks are restored by WP_UnitTestCase). The ShortPixel endpoints are
 * answered by MockEmrApi; a fail-fast `pre_http_request` filter at priority
 * 9999 refuses and records anything else, and tear_down() asserts nothing
 * was refused.
 *
 * Snapshot/restore: $_POST, the `emr_shortpixel_api_key` and
 * `enable_media_replace` options, the ApiKeyManager singleton, the
 * attachments and the `emr_<key>` transients created.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Ajax;
use EnableMediaReplace\ApiKeyManager;

require_once dirname( __DIR__ ) . '/Integration/Helpers/MockEmrApi.php';

class AjaxTest extends WP_Ajax_UnitTestCase {

	const KEY_OPTION      = 'emr_shortpixel_api_key';
	const SETTINGS_OPTION = 'enable_media_replace';
	const API_KEY         = 'abcdEFGHIJKLMNOPwxyz';

	/**
	 * @var MockEmrApi
	 */
	private $mock;

	private $saved_post;
	private $saved_key;
	private $saved_settings;

	/**
	 * @var int[]
	 */
	private $attachments = array();

	/**
	 * @var string[]
	 */
	private $transients = array();

	/**
	 * @var string[]
	 */
	private $blocked = array();

	public function set_up() {
		parent::set_up();
		$this->saved_post     = $_POST;
		$this->saved_key      = get_option( self::KEY_OPTION, null );
		$this->saved_settings = get_option( self::SETTINGS_OPTION, null );
		delete_option( self::KEY_OPTION );
		$this->reset_key_manager();
		remove_all_actions( 'admin_init' );

		$this->mock = MockEmrApi::register();
		$this->mock->reset();
		$this->blocked = array();
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );
		$this->_setRole( 'administrator' );
		$_POST = array();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999 );
		MockEmrApi::unregister();
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachments = array();
		foreach ( $this->transients as $transient ) {
			delete_transient( $transient );
		}
		$this->transients = array();
		$_POST            = $this->saved_post;
		$this->restore_option( self::KEY_OPTION, $this->saved_key );
		$this->restore_option( self::SETTINGS_OPTION, $this->saved_settings );
		$this->reset_key_manager();

		$blocked       = $this->blocked;
		$this->blocked = array();
		parent::tear_down();
		$this->assertSame( array(), $blocked, 'A request reached the fail-fast filter.' );
	}

	/**
	 * Refuses and records every HTTP request nothing else answered.
	 */
	public function fail_fast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->blocked[] = $url;
		return new WP_Error( 'http_blocked', 'Blocked unmocked request to ' . $url );
	}

	private function restore_option( $name, $value ) {
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}

	private function reset_key_manager() {
		$ref = new ReflectionProperty( ApiKeyManager::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * Posts to an endpoint and returns the raw response text.
	 */
	private function request( $action, array $post ) {
		$_POST = $post;
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			$this->_last_response .= $e->getMessage();
		}
		return $this->_last_response;
	}

	private function json( $action, array $post ) {
		$decoded = json_decode( $this->request( $action, $post ), true );
		$this->assertIsArray( $decoded, 'The response is JSON.' );
		return $decoded;
	}

	private function check_nonce( array $post ) {
		$_POST  = $post;
		$method = new ReflectionMethod( Ajax::class, 'check_nonce' );
		$method->setAccessible( true );
		return $method->invoke( new Ajax() );
	}

	private function callback_count( $hook ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ]->callbacks[10] ) ) {
			return 0;
		}
		return count( $wp_filter[ $hook ]->callbacks[10] );
	}

	/*
	 * Registration.
	 */

	public function test_constructor_registers_the_three_logged_in_endpoints() {
		$ajax = new Ajax();

		$this->assertSame( 10, has_action( 'wp_ajax_emr_remove_background', array( $ajax, 'remove_background' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_emr_save_api_key', array( $ajax, 'save_api_key' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_emr_delete_api_key', array( $ajax, 'delete_api_key' ) ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_emr_remove_background' ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_emr_save_api_key' ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_emr_delete_api_key' ) );
	}

	/**
	 * classes/ajax.php creates an Ajax instance when it is required, and
	 * EnableMediaReplacePlugin::adminInit() creates another one on every
	 * admin_init, so each endpoint has two callbacks (on two objects). The
	 * first ends the request, so the second never runs, but the duplicate
	 * is unintended. One of the two `new Ajax()` calls should go.
	 *
	 * How to recognise the fix: adminInit() no longer adds a callback (or
	 * the require-time instance is gone), and the count assertions fail.
	 */
	public function test_endpoints_are_registered_twice_pinned_for_deferred_fix() {
		$before = $this->callback_count( 'wp_ajax_emr_remove_background' );
		$this->assertGreaterThanOrEqual( 1, $before, 'Sentinel: the require-time instance is registered.' );

		emr()->adminInit();
		$after_one = $this->callback_count( 'wp_ajax_emr_remove_background' );
		emr()->adminInit();
		$after_two = $this->callback_count( 'wp_ajax_emr_remove_background' );

		$this->assertGreaterThanOrEqual( 2, $after_one, 'Pinned: two callbacks after one adminInit().' );
		$this->assertSame( $before + 1, $after_one );
		$this->assertSame( $before + 2, $after_two, 'Every adminInit() adds another.' );
		$this->assertSame( $before + 2, $this->callback_count( 'wp_ajax_emr_save_api_key' ) );
		$this->assertSame( $before + 2, $this->callback_count( 'wp_ajax_emr_delete_api_key' ) );
	}

	/*
	 * check_nonce().
	 */

	public function test_check_nonce_verifies_against_the_posted_action() {
		$result = $this->check_nonce(
			array(
				'action' => 'emr_remove_background',
				'nonce'  => wp_create_nonce( 'emr_remove_background' ),
			)
		);

		$this->assertSame( 1, $result );
	}

	public function test_check_nonce_follows_whatever_action_is_posted() {
		$result = $this->check_nonce(
			array(
				'action' => 'some_other_action',
				'nonce'  => wp_create_nonce( 'some_other_action' ),
			)
		);

		$this->assertSame( 1, $result );
	}

	public function test_check_nonce_refuses_a_nonce_for_another_action() {
		$result = $this->check_nonce(
			array(
				'action' => 'emr_remove_background',
				'nonce'  => wp_create_nonce( 'emr_save_api_key' ),
			)
		);

		$this->assertFalse( $result );
	}

	public function test_check_nonce_refuses_missing_fields() {
		$this->assertFalse( $this->check_nonce( array() ) );
		$this->assertFalse( $this->check_nonce( array( 'action' => 'emr_remove_background' ) ) );
		$this->assertFalse( $this->check_nonce( array( 'nonce' => 'abc', 'action' => 'emr_remove_background' ) ) );
	}

	/*
	 * remove_background().
	 */

	public function test_remove_background_returns_the_api_result_as_json() {
		$id                  = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/test-image.jpg' );
		$this->attachments[] = $id;

		$response = $this->json(
			'emr_remove_background',
			array(
				'nonce'         => wp_create_nonce( 'emr_remove_background' ),
				'attachment_id' => (string) $id,
				'background'    => array( 'type' => 'transparent' ),
			)
		);
		if ( isset( $response['key'] ) ) {
			$this->transients[] = 'emr_' . $response['key'];
		}

		$this->assertTrue( $response['success'] );
		$this->assertArrayNotHasKey( 'data', $response, 'No success/data wrapper.' );
		$this->assertStringStartsWith( MockEmrApi::DOWNLOAD_BASE, $response['image'] );
		$this->assertSame( wp_get_original_image_url( $id ), $response['url'] );
		$this->assertIsString( $response['key'] );
		$this->assertCount( 1, $this->mock->requestsTo( 'free-reducer.php' ) );
	}

	public function test_remove_background_without_attachment_reports_it() {
		$response = $this->json(
			'emr_remove_background',
			array(
				'nonce'      => wp_create_nonce( 'emr_remove_background' ),
				'background' => array( 'type' => 'transparent' ),
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'No attachment ID given', $response['message'] );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	/*
	 * save_api_key().
	 */

	public function test_save_api_key_requires_manage_options() {
		$this->_setRole( 'editor' );

		$response = $this->json(
			'emr_save_api_key',
			array(
				'nonce'   => wp_create_nonce( 'emr_save_api_key' ),
				'api_key' => self::API_KEY,
			)
		);

		$this->assertSame( array( 'success' => false, 'data' => array( 'message' => 'You do not have permission to perform this action.' ) ), $response );
		$this->assertFalse( get_option( self::KEY_OPTION ) );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	/**
	 * @dataProvider provide_bad_nonces
	 */
	public function test_save_api_key_refuses_a_bad_nonce( $nonce_action ) {
		$post = array( 'api_key' => self::API_KEY );
		if ( null !== $nonce_action ) {
			$post['nonce'] = wp_create_nonce( $nonce_action );
		}

		$response = $this->json( 'emr_save_api_key', $post );

		$this->assertSame( array( 'success' => false, 'data' => array( 'message' => 'Invalid security token. Please reload the page and try again.' ) ), $response );
		$this->assertFalse( get_option( self::KEY_OPTION ) );
	}

	public function provide_bad_nonces() {
		return array(
			'missing'                    => array( null ),
			'remove-background nonce'    => array( 'emr_remove_background' ),
			'unrelated nonce'            => array( 'something' ),
		);
	}

	public function test_save_api_key_saves_a_valid_unlimited_key() {
		$response = $this->json(
			'emr_save_api_key',
			array(
				'nonce'   => wp_create_nonce( 'emr_save_api_key' ),
				'api_key' => self::API_KEY,
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'API Key saved and verified successfully. You now have unlimited background removal.', $response['data']['message'] );
		$this->assertSame( self::API_KEY, get_option( self::KEY_OPTION ) );
		$requests = $this->mock->requestsTo( 'api-status.php' );
		$this->assertCount( 1, $requests );
		$this->assertSame( self::API_KEY, $requests[0]['request']['key'] );
	}

	public function test_save_api_key_unslashes_and_trims_the_key() {
		$response = $this->json(
			'emr_save_api_key',
			array(
				'nonce'   => wp_create_nonce( 'emr_save_api_key' ),
				'api_key' => '  ' . self::API_KEY . "\n",
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( self::API_KEY, get_option( self::KEY_OPTION ) );
	}

	public function test_save_api_key_reports_a_key_of_the_wrong_length() {
		$response = $this->json(
			'emr_save_api_key',
			array(
				'nonce'   => wp_create_nonce( 'emr_save_api_key' ),
				'api_key' => 'short',
			)
		);

		$this->assertSame( array( 'success' => false, 'data' => array( 'message' => 'The API Key must be exactly 20 characters.' ) ), $response );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	public function test_save_api_key_refuses_a_key_without_unlimited_plan() {
		$this->mock->setKnob( 'unlimited', 'false' );

		$response = $this->json(
			'emr_save_api_key',
			array(
				'nonce'   => wp_create_nonce( 'emr_save_api_key' ),
				'api_key' => self::API_KEY,
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'upgrade', $response['data']['message'] );
		$this->assertFalse( get_option( self::KEY_OPTION ) );
	}

	/*
	 * delete_api_key().
	 */

	public function test_delete_api_key_requires_manage_options() {
		update_option( self::KEY_OPTION, self::API_KEY );
		$this->_setRole( 'editor' );

		$response = $this->json( 'emr_delete_api_key', array( 'nonce' => wp_create_nonce( 'emr_save_api_key' ) ) );

		$this->assertSame( array( 'success' => false, 'data' => array( 'message' => 'You do not have permission to perform this action.' ) ), $response );
		$this->assertSame( self::API_KEY, get_option( self::KEY_OPTION ) );
	}

	/**
	 * @dataProvider provide_bad_nonces
	 */
	public function test_delete_api_key_refuses_a_bad_nonce( $nonce_action ) {
		update_option( self::KEY_OPTION, self::API_KEY );
		$post = array();
		if ( null !== $nonce_action ) {
			$post['nonce'] = wp_create_nonce( $nonce_action );
		}

		$response = $this->json( 'emr_delete_api_key', $post );

		$this->assertSame( array( 'success' => false, 'data' => array( 'message' => 'Invalid security token. Please reload the page and try again.' ) ), $response );
		$this->assertSame( self::API_KEY, get_option( self::KEY_OPTION ) );
	}

	public function test_delete_api_key_removes_the_option() {
		update_option( self::KEY_OPTION, self::API_KEY );
		$this->reset_key_manager();

		$response = $this->json( 'emr_delete_api_key', array( 'nonce' => wp_create_nonce( 'emr_save_api_key' ) ) );

		$this->assertSame( array( 'success' => true, 'data' => array( 'message' => 'API Key removed.' ) ), $response );
		$this->assertFalse( get_option( self::KEY_OPTION ) );
		$this->assertSame( '', ApiKeyManager::getInstance()->getApiKey() );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	public function test_delete_api_key_without_a_stored_key_still_succeeds() {
		$response = $this->json( 'emr_delete_api_key', array( 'nonce' => wp_create_nonce( 'emr_save_api_key' ) ) );

		$this->assertTrue( $response['success'] );
		$this->assertFalse( get_option( self::KEY_OPTION ) );
	}
}
