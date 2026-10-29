<?php
/**
 * Tests for EnableMediaReplace\Api (classes/api.php).
 *
 * request() is called directly with $_POST populated the way the
 * remove-background AJAX handler receives it, for a background removal
 * (the default) and for an upscale (`upscale` 2, 3 or 4 in the posted
 * data). The reducer, cleanup,
 * api-status and result-download endpoints are answered by MockEmrApi
 * (priority 10 on `pre_http_request`); a fail-fast filter at priority 9999
 * refuses and records anything else, and tear_down() asserts nothing was
 * refused. A few tests add a filter at priority 1 (to observe state when a
 * request is sent) or 11 (to rewrite the mock's answer).
 *
 * Two tests run through the production sleep() in request(): the single
 * status-1 retry and the attempt limit, about 3 s each. No other test
 * reaches a retry.
 *
 * Snapshot/restore: $_POST, the current user, the `enable_media_replace`
 * and `emr_shortpixel_api_key` options, the ApiKeyManager singleton, the
 * `emr_<key>` transients created, the uploaded attachments and temp files.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Api;
use EnableMediaReplace\ApiKeyManager;

require_once dirname( __DIR__ ) . '/Integration/Helpers/MockEmrApi.php';

class ApiTest extends WP_UnitTestCase {

	const SETTINGS_OPTION = 'enable_media_replace';
	const KEY_OPTION      = 'emr_shortpixel_api_key';
	const API_KEY         = 'abcdEFGHIJKLMNOPwxyz';
	const FREE_URL        = 'http://api.shortpixel.com/v2/free-reducer.php';
	const KEYED_URL       = 'https://api.shortpixel.com/v2/reducer.php';
	const CLEANUP_URL     = 'https://api.shortpixel.com/v2/cleanup.php';
	const LIMIT_MESSAGE   = 'The background could not be removed in a reasonable amount of time. The file might be too big, or the API could be busy. Please try again later!';
	const UPSCALE_LIMIT_MESSAGE = 'The image could not be upscaled in a reasonable amount of time. The file might be too big, or the API could be busy. Please try again later!';

	/**
	 * @var MockEmrApi
	 */
	private $mock;

	/**
	 * Uploaded test attachment.
	 *
	 * @var int
	 */
	private $attachment_id;

	/**
	 * Source URL of the test attachment.
	 *
	 * @var string
	 */
	private $source_url;

	/**
	 * @var int
	 */
	private $admin_id;

	private $saved_post;
	private $saved_user;
	private $saved_settings;
	private $saved_key;

	/**
	 * Attachments to delete in tear_down().
	 *
	 * @var int[]
	 */
	private $attachments = array();

	/**
	 * Files to delete in tear_down().
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Filters added by a test, as array( hook, callback, priority ).
	 *
	 * @var array
	 */
	private $added_filters = array();

	/**
	 * URLs refused by the fail-fast filter.
	 *
	 * @var array
	 */
	private $blocked = array();

	public function set_up() {
		parent::set_up();
		$this->saved_post     = $_POST;
		$this->saved_user     = get_current_user_id();
		$this->saved_settings = get_option( self::SETTINGS_OPTION, null );
		$this->saved_key      = get_option( self::KEY_OPTION, null );
		delete_option( self::SETTINGS_OPTION );
		delete_option( self::KEY_OPTION );
		$this->reset_key_manager();

		$this->mock = MockEmrApi::register();
		$this->mock->reset();
		$this->blocked = array();
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		$this->attachment_id = $this->upload();
		$this->source_url    = wp_get_original_image_url( $this->attachment_id );
		$_POST               = array();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999 );
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		MockEmrApi::unregister();

		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachments = array();
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();

		$_POST = $this->saved_post;
		wp_set_current_user( $this->saved_user );
		$this->restore_option( self::SETTINGS_OPTION, $this->saved_settings );
		$this->restore_option( self::KEY_OPTION, $this->saved_key );
		$this->reset_key_manager();

		$blocked       = $this->blocked;
		$this->blocked = array();
		parent::tear_down();
		$this->assertSame( array(), $blocked, 'A request reached the fail-fast filter.' );
	}

	public function fail_fast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->blocked[] = $url;
		return new WP_Error( 'http_blocked', 'Blocked unmocked request to ' . $url );
	}

	/*
	 * Helpers.
	 */

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

	private function use_key() {
		update_option( self::KEY_OPTION, self::API_KEY );
		$this->reset_key_manager();
	}

	private function upload() {
		$id                  = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/test-image.jpg' );
		$this->attachments[] = $id;
		return $id;
	}

	private function add_test_filter( $hook, $callback, $priority ) {
		add_filter( $hook, $callback, $priority, 3 );
		$this->added_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Fills $_POST like the AJAX handler receives it and returns the same
	 * array, to pass as $posted_data.
	 */
	private function post( array $background = array( 'type' => 'transparent' ), $attachment_id = null ) {
		$_POST = array(
			'attachment_id' => (string) ( null === $attachment_id ? $this->attachment_id : $attachment_id ),
			'background'    => $background,
		);
		return $_POST;
	}

	private function run_request( array $posted ) {
		return ( new Api() )->request( $posted );
	}

	/**
	 * Fills $_POST like js/remove_bg.js sends it from the upscale screen:
	 * the factor and no background options.
	 */
	private function upscale_post( $factor, $attachment_id = null ) {
		$_POST = array(
			'attachment_id' => (string) ( null === $attachment_id ? $this->attachment_id : $attachment_id ),
			'upscale'       => (string) $factor,
		);
		return $_POST;
	}

	/** Uploads a GD-drawn JPEG of the given size; returns the attachment ID. */
	private function upload_jpeg( $width, $height ) {
		$path  = get_temp_dir() . 'emr-api-' . wp_generate_password( 8, false, false ) . '.jpg';
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 40, 90, 160 ) );
		imagejpeg( $image, $path, 80 );
		$this->files[] = $path;
		$id            = self::factory()->attachment->create_upload_object( $path );
		$this->attachments[] = $id;
		return $id;
	}

	private function reducer_body( $index = 0 ) {
		$requests = $this->mock->requestsTo( 'reducer.php' );
		$this->assertArrayHasKey( $index, $requests, 'Reducer request missing' );
		return $requests[ $index ]['request'];
	}

	private function set_counter( Api $api, $value ) {
		$ref = new ReflectionProperty( Api::class, 'counter' );
		$ref->setAccessible( true );
		$ref->setValue( $api, $value );
	}

	private function track_transient( $result ) {
		if ( isset( $result->key ) ) {
			$this->assertNotFalse( get_transient( 'emr_' . $result->key ) );
		}
	}

	/*
	 * Guards.
	 */

	public function test_without_attachment_id_returns_no_attachment_message() {
		update_option( self::SETTINGS_OPTION, array( 'bg_type' => 'kept' ) );
		$_POST = array( 'background' => array( 'type' => 'solid' ) );

		$result = $this->run_request( array( 'background' => array( 'type' => 'transparent' ) ) );

		$this->assertInstanceOf( stdClass::class, $result );
		$this->assertFalse( $result->success );
		$this->assertNull( $result->image );
		$this->assertSame( 'No attachment ID given', $result->message );
		$this->assertSame( array(), $this->mock->getRequests() );
		$this->assertSame( array( 'bg_type' => 'kept' ), get_option( self::SETTINGS_OPTION ) );
	}

	public function test_subscriber_gets_no_permission() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->run_request( $this->post() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'No permission for user', $result->message );
		$this->assertSame( array(), $this->mock->getRequests() );
		$this->assertFalse( get_option( self::SETTINGS_OPTION ) );
	}

	public function test_logged_out_user_gets_no_permission() {
		wp_set_current_user( 0 );

		$result = $this->run_request( $this->post() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'No permission for user', $result->message );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	public function test_id_of_a_non_attachment_or_missing_post_gets_no_permission() {
		$post_id = self::factory()->post->create();

		foreach ( array( $post_id, 987654, 'abc' ) as $id ) {
			$result = $this->run_request( $this->post( array( 'type' => 'transparent' ), $id ) );
			$this->assertFalse( $result->success );
			$this->assertSame( 'No permission for user', $result->message, 'ID ' . $id );
		}
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	/**
	 * request() reads `$posted_data['background']['type']` without isset(),
	 * so posted data without a background raises "Undefined array key"
	 * (a warning on PHP 8, a notice on 7.4), after the settings were
	 * already saved. A missing background should default to transparent.
	 *
	 * Where: Api::request(), `if ( 'solid' === $posted_data['background']['type'] )`.
	 *
	 * How to recognise the fix: no warning / notice is raised and a reducer
	 * request with bg_remove '1' is sent.
	 */
	public function test_posted_data_without_background_raises_undefined_index_pinned_for_deferred_fix() {
		$_POST = array( 'attachment_id' => (string) $this->attachment_id );

		$error = null;
		try {
			$this->run_request( array( 'attachment_id' => (string) $this->attachment_id ) );
		} catch ( \PHPUnit\Framework\Error\Error $e ) {
			$error = $e;
		}

		$this->assertInstanceOf( PHP_VERSION_ID >= 80000 ? \PHPUnit\Framework\Error\Warning::class : \PHPUnit\Framework\Error\Notice::class, $error );
		$this->assertMatchesRegularExpression( '/Undefined (index|array key).*background/', $error->getMessage() );
		$this->assertSame( array(), $this->mock->getRequests() );
		$this->assertIsArray( get_option( self::SETTINGS_OPTION ) );
	}

	/*
	 * Settings.
	 */

	public function test_settings_are_saved_before_the_request_is_sent() {
		update_option( self::SETTINGS_OPTION, array( 'other' => 'kept' ) );
		$seen = array();
		$this->add_test_filter(
			'pre_http_request',
			function ( $preempt ) use ( &$seen ) {
				$seen[] = get_option( 'enable_media_replace' );
				return $preempt;
			},
			1
		);

		$this->run_request(
			$this->post(
				array(
					'type'         => 'solid',
					'color'        => '#123456',
					'transparency' => '40',
				)
			)
		);

		$expected = array(
			'other'           => 'kept',
			'bg_type'         => 'solid',
			'bg_color'        => '#123456',
			'bg_transparency' => '40',
		);
		$this->assertCount( 1, $seen );
		$this->assertSame( $expected, $seen[0] );
		$this->assertSame( $expected, get_option( self::SETTINGS_OPTION ) );
	}

	public function test_settings_default_when_background_is_absent_from_post() {
		$_POST = array( 'attachment_id' => (string) $this->attachment_id );

		$this->run_request( array( 'background' => array( 'type' => 'transparent' ) ) );

		$this->assertSame(
			array(
				'bg_type'         => false,
				'bg_color'        => '#ffffff',
				'bg_transparency' => false,
			),
			get_option( self::SETTINGS_OPTION )
		);
	}

	public function test_settings_are_sanitized() {
		$this->run_request(
			$this->post(
				array(
					'type'         => "<b>solid</b>\n",
					'color'        => '<script>x</script>#abcdef',
					'transparency' => ' 7 ',
				)
			)
		);

		$settings = get_option( self::SETTINGS_OPTION );
		$this->assertSame( 'solid', $settings['bg_type'] );
		$this->assertSame( '#abcdef', $settings['bg_color'] );
		$this->assertSame( '7', $settings['bg_transparency'] );
	}

	/*
	 * Request building: free endpoint.
	 */

	public function test_without_key_the_free_endpoint_gets_a_json_body() {
		$this->run_request( $this->post() );

		$requests = $this->mock->getRequests();
		$this->assertSame( self::FREE_URL, $requests[0]['url'] );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertSame( 60, $requests[0]['args']['timeout'] );
		$this->assertSame( array( 'Content-Type: application/json', 'Accept: application/json' ), $requests[0]['args']['headers'] );
		$this->assertSame(
			array(
				'plugin_version' => 'EMR4.2.0',
				'bg_remove'      => '1',
				'urllist'        => array( urlencode( esc_url( $this->source_url ) ) ),
				'lossy'          => 0,
				'refresh'        => true,
			),
			$requests[0]['request']
		);
		$this->assertCount( 0, $this->mock->requestsTo( 'api-status.php' ) );
		$this->assertCount( 0, $this->mock->requestsTo( 'cleanup.php' ) );
	}

	public function test_transparent_sends_bg_remove_one() {
		$this->run_request( $this->post( array( 'type' => 'transparent', 'color' => '#ff0000', 'transparency' => '50' ) ) );

		$this->assertSame( '1', $this->reducer_body()['bg_remove'] );
	}

	public function test_solid_colour_gets_the_alpha_suffix_for_0_and_100() {
		$this->run_request( $this->post( array( 'type' => 'solid', 'color' => '#ff0000', 'transparency' => '100' ) ) );
		$this->run_request( $this->post( array( 'type' => 'solid', 'color' => '#ff0000', 'transparency' => '0' ) ) );

		$this->assertSame( '#ff0000FF', $this->reducer_body( 0 )['bg_remove'] );
		$this->assertSame( '#ff000000', $this->reducer_body( 1 )['bg_remove'] );
	}

	public function test_solid_colour_without_or_with_out_of_range_transparency_has_no_suffix() {
		$this->run_request( $this->post( array( 'type' => 'solid', 'color' => '#00ff00' ) ) );
		$this->run_request( $this->post( array( 'type' => 'solid', 'color' => '#00ff00', 'transparency' => '101' ) ) );
		$this->run_request( $this->post( array( 'type' => 'solid', 'color' => '#00ff00', 'transparency' => '-5' ) ) );

		foreach ( array( 0, 1, 2 ) as $i ) {
			$this->assertSame( '#00ff00', $this->reducer_body( $i )['bg_remove'] );
		}
	}

	/**
	 * The alpha suffix is the decimal transparency padded to two characters
	 * (`str_pad($transparency, 2, '0', STR_PAD_LEFT)`), but the API reads it
	 * as a hex alpha: 50 % becomes '50' (0x50, about 31 %) instead of '80',
	 * 5 % becomes '05' instead of '0D'. Only 0 and 100 come out right.
	 *
	 * Where: Api::request(), the solid-background branch.
	 *
	 * How to recognise the fix: transparency 50 sends '#ff000080' and 5
	 * sends '#ff00000D'.
	 */
	public function test_alpha_suffix_is_decimal_not_hex_pinned_for_deferred_fix() {
		$this->run_request( $this->post( array( 'type' => 'solid', 'color' => '#ff0000', 'transparency' => '50' ) ) );
		$this->run_request( $this->post( array( 'type' => 'solid', 'color' => '#ff0000', 'transparency' => '5' ) ) );

		$this->assertSame( '#ff000050', $this->reducer_body( 0 )['bg_remove'] );
		$this->assertNotSame( '#ff000080', $this->reducer_body( 0 )['bg_remove'] );
		$this->assertSame( '#ff000005', $this->reducer_body( 1 )['bg_remove'] );
	}

	/**
	 * `plugin_version` is the literal 'EMR4.2.0' in request() (and in
	 * purgeRemoteCache()) instead of being built from EMR_VERSION, so the API
	 * sees a stale version for every release after 4.2.0.
	 *
	 * Where: Api::request() and Api::purgeRemoteCache().
	 *
	 * How to recognise the fix: the bodies carry 'EMR' . EMR_VERSION.
	 */
	public function test_plugin_version_is_hard_coded_pinned_for_deferred_fix() {
		$this->assertNotSame( 'EMR4.2.0', 'EMR' . EMR_VERSION );
		$this->use_key();

		$this->run_request( $this->post() );

		$this->assertSame( 'EMR4.2.0', $this->reducer_body()['plugin_version'] );
		$this->assertSame( 'EMR4.2.0', $this->mock->requestsTo( 'cleanup.php' )[0]['request']['plugin_version'] );
		$this->assertStringContainsString( '"plugin_version":"EMR4.2.0"', $this->mock->requestsTo( 'reducer.php' )[0]['args']['body'] );
	}

	/**
	 * request() takes `$posted_data` but reads `attachment_id` and the
	 * background settings from `$_POST`. When the two disagree, the image
	 * from `$_POST` is processed and the settings come from `$_POST`, while
	 * bg_remove comes from `$posted_data`. All inputs should come from one
	 * source, the parameter.
	 *
	 * Where: Api::request().
	 *
	 * How to recognise the fix: the reducer receives the URL of the
	 * attachment passed in $posted_data.
	 */
	public function test_post_superglobal_wins_over_posted_data_pinned_for_deferred_fix() {
		$other_id  = $this->upload();
		$other_url = wp_get_original_image_url( $other_id );
		$this->assertNotSame( $this->attachment_id, $other_id );
		$this->assertNotSame( $this->source_url, $other_url );

		$this->post( array( 'type' => 'solid', 'color' => '#111111', 'transparency' => '100' ), $this->attachment_id );
		$posted = array(
			'attachment_id' => (string) $other_id,
			'background'    => array( 'type' => 'solid', 'color' => '#222222', 'transparency' => '100' ),
		);

		$this->run_request( $posted );

		$body = $this->reducer_body();
		$this->assertSame( array( urlencode( esc_url( $this->source_url ) ) ), $body['urllist'] );
		$this->assertSame( '#222222FF', $body['bg_remove'] );
		$this->assertSame( '#111111', get_option( self::SETTINGS_OPTION )['bg_color'] );
	}

	/*
	 * Request building: upscale.
	 */

	public function test_upscale_replaces_bg_remove_with_the_factor_in_the_free_body() {
		$this->run_request( $this->upscale_post( 2 ) );

		$requests = $this->mock->getRequests();
		$this->assertCount( 1, $requests );
		$this->assertSame( self::FREE_URL, $requests[0]['url'] );
		$this->assertSame(
			array(
				'plugin_version' => 'EMR4.2.0',
				'urllist'        => array( urlencode( esc_url( $this->source_url ) ) ),
				'lossy'          => 0,
				'refresh'        => true,
				'upscale'        => 2,
			),
			$requests[0]['request']
		);
		$this->assertArrayNotHasKey( 'bg_remove', $requests[0]['request'] );
	}

	public function test_upscale_accepts_the_factors_two_three_and_four_as_integers() {
		foreach ( array( '2', '3', '4' ) as $i => $factor ) {
			$result = $this->run_request( $this->upscale_post( $factor ) );

			$this->assertTrue( $result->success, 'Factor ' . $factor );
			$this->assertSame( (int) $factor, $this->reducer_body( $i )['upscale'] );
			$this->track_transient( $result );
		}
		$this->assertCount( 3, $this->mock->requestsTo( 'reducer.php' ) );
	}

	public function test_upscale_with_a_factor_outside_the_set_is_refused_before_any_request() {
		update_option( self::SETTINGS_OPTION, array( 'bg_type' => 'kept' ) );

		foreach ( array( '1', '5', '-2', '8' ) as $factor ) {
			$result = $this->run_request( $this->upscale_post( $factor ) );

			$this->assertInstanceOf( stdClass::class, $result, 'Factor ' . $factor );
			$this->assertFalse( $result->success, 'Factor ' . $factor );
			$this->assertNull( $result->image );
			$this->assertSame( 'Invalid upscale factor', $result->message );
		}
		$this->assertSame( array(), $this->mock->getRequests() );
		$this->assertSame( array( 'bg_type' => 'kept' ), get_option( self::SETTINGS_OPTION ), 'Nothing is saved for a refused factor.' );
	}

	public function test_upscale_factor_zero_or_a_non_numeric_value_is_a_background_removal() {
		foreach ( array( '0', 'abc' ) as $i => $factor ) {
			$posted               = $this->upscale_post( $factor );
			$posted['background'] = array( 'type' => 'transparent' );
			$_POST                = $posted;

			$result = $this->run_request( $posted );

			$this->assertTrue( $result->success, 'Factor ' . $factor );
			$body = $this->reducer_body( $i );
			$this->assertSame( '1', $body['bg_remove'], 'Factor ' . $factor );
			$this->assertArrayNotHasKey( 'upscale', $body );
			$this->track_transient( $result );
		}
	}

	public function test_upscale_runs_the_permission_check_before_the_factor_check() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->run_request( $this->upscale_post( 7 ) );

		$this->assertSame( 'No permission for user', $result->message );
	}

	public function test_upscale_does_not_save_the_background_settings() {
		update_option( self::SETTINGS_OPTION, array( 'bg_type' => 'solid', 'bg_color' => '#123456' ) );
		$posted               = $this->upscale_post( 3 );
		$posted['background'] = array( 'type' => 'transparent', 'color' => '#ffffff', 'transparency' => '100' );
		$_POST                = $posted;

		$result = $this->run_request( $posted );

		$this->assertTrue( $result->success );
		$this->assertSame( array( 'bg_type' => 'solid', 'bg_color' => '#123456' ), get_option( self::SETTINGS_OPTION ), 'The remove-background choice is left alone.' );
		$this->track_transient( $result );
	}

	public function test_upscale_ignores_a_posted_solid_background() {
		$posted               = $this->upscale_post( 4 );
		$posted['background'] = array( 'type' => 'solid', 'color' => '#ff0000', 'transparency' => '100' );
		$_POST                = $posted;

		$result = $this->run_request( $posted );

		$this->assertTrue( $result->success );
		$body = $this->reducer_body();
		$this->assertArrayNotHasKey( 'bg_remove', $body );
		$this->assertSame( 4, $body['upscale'] );
		$this->assertStringNotContainsString( '#ff0000', wp_json_encode( $body ) );
		$this->track_transient( $result );
	}

	public function test_upscale_settings_summary_lists_the_factor_instead_of_bg_remove() {
		$result = $this->run_request( $this->upscale_post( 2 ) );

		$this->assertTrue( $result->success );
		$this->assertSame( ' plugin_version:EMR4.2.0  lossy:0  upscale:2 ', $result->settings );
		$this->assertStringNotContainsString( 'bg_remove', $result->settings );
		$this->track_transient( $result );
	}

	public function test_upscale_with_key_sends_key_item_id_and_the_factor_to_the_authenticated_endpoint() {
		$this->use_key();

		$result = $this->run_request( $this->upscale_post( 3 ) );

		$this->assertTrue( $result->success );
		$paths = wp_list_pluck( $this->mock->getRequests(), 'path' );
		$this->assertSame( array( '/v2/api-status.php', '/v2/cleanup.php', '/v2/reducer.php' ), $paths );
		$requests = $this->mock->requestsTo( 'reducer.php' );
		$this->assertSame( self::KEYED_URL, $requests[0]['url'] );
		$this->assertSame(
			array(
				'plugin_version' => 'EMR4.2.0',
				'urllist'        => array( esc_url_raw( $this->source_url ) ),
				'lossy'          => 0,
				'refresh'        => true,
				'upscale'        => 3,
				'key'            => self::API_KEY,
				'item_id'        => $this->attachment_id,
			),
			$requests[0]['request']
		);
		$this->track_transient( $result );
	}

	public function test_upscale_quota_exceeded_message_names_upscaling() {
		$this->mock->setKnob( 'quotaExceeded', true );

		$result = $this->run_request( $this->upscale_post( 2 ) );

		$this->assertFalse( $result->success );
		$this->assertTrue( $result->quota_exceeded );
		$this->assertSame( 'Quota exceeded. Please purchase the Unlimited or Unlimited AI plan and add your API key to continue upscaling images.', $result->message );
	}

	public function test_upscale_attempt_limit_message_names_upscaling_without_request() {
		$this->use_key();
		$api = new Api();
		$this->set_counter( $api, 29 );

		$result = $api->request( $this->upscale_post( 2 ) );

		$this->assertFalse( $result->success );
		$this->assertSame( self::UPSCALE_LIMIT_MESSAGE, $result->message );
		$this->assertNotSame( self::LIMIT_MESSAGE, $result->message );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	public function test_upscale_error_status_copies_the_api_message() {
		$this->mock->setKnob( 'forceStatusCode', '-102' );
		$this->mock->setKnob( 'errorMessage', 'Image too large to upscale' );

		$result = $this->run_request( $this->upscale_post( 4 ) );

		$this->assertFalse( $result->success );
		$this->assertSame( 'Image too large to upscale', $result->message );
		$this->assertCount( 1, $this->mock->requestsTo( 'reducer.php' ) );
	}

	/**
	 * The upscale screen disables a factor whose maximum source width
	 * (UIHelper::getUpscaleFactors(): 4x up to 1024 px) is at or below the
	 * image width, but request() only checks that the factor is 2, 3 or 4.
	 * A crafted request therefore sends 4x for an 1100 px wide image to the
	 * reducer. request() should refuse a factor the screen would not offer.
	 *
	 * How to recognise the fix: the result is a failure without a reducer
	 * request (the assertions on success and on the sent body fail).
	 */
	public function test_upscale_factor_above_the_width_limit_is_sent_pinned_for_deferred_fix() {
		$wide = $this->upload_jpeg( 1100, 80 );
		$meta = wp_get_attachment_metadata( $wide );
		$this->assertSame( 1100, $meta['width'], 'Precondition: the image is 1100 px wide.' );
		$limits = emr()->uiHelper()->getUpscaleFactors();
		$this->assertLessThan( 1100, $limits[4], 'Precondition: the screen disables 4x for this image.' );
		$this->assertGreaterThan( 1100, $limits[2], 'Precondition: the screen still offers 2x.' );

		$result = $this->run_request( $this->upscale_post( 4, $wide ) );

		$this->assertTrue( $result->success );
		$this->assertSame( 4, $this->reducer_body()['upscale'] );
		$this->assertSame( array( urlencode( esc_url( wp_get_original_image_url( $wide ) ) ) ), $this->reducer_body()['urllist'] );
		$this->track_transient( $result );
	}

	/*
	 * Request building: keyed endpoint.
	 */

	public function test_with_key_the_authenticated_endpoint_gets_key_and_item_id() {
		$this->use_key();

		$this->run_request( $this->post( array( 'type' => 'solid', 'color' => '#ff0000', 'transparency' => '100' ) ) );

		$requests = $this->mock->requestsTo( 'reducer.php' );
		$this->assertCount( 1, $requests );
		$this->assertSame( self::KEYED_URL, $requests[0]['url'] );
		$this->assertSame( 15, $requests[0]['args']['timeout'] );
		$this->assertSame( array(), $requests[0]['args']['headers'] );
		$this->assertSame(
			array(
				'plugin_version' => 'EMR4.2.0',
				'bg_remove'      => '#ff0000FF',
				'urllist'        => array( esc_url_raw( $this->source_url ) ),
				'lossy'          => 0,
				'refresh'        => true,
				'key'            => self::API_KEY,
				'item_id'        => $this->attachment_id,
			),
			$requests[0]['request']
		);
	}

	public function test_with_key_plan_check_and_cleanup_precede_the_reducer() {
		$this->use_key();

		$this->run_request( $this->post() );

		$paths = wp_list_pluck( $this->mock->getRequests(), 'path' );
		$this->assertSame( array( '/v2/api-status.php', '/v2/cleanup.php', '/v2/reducer.php' ), $paths );

		$cleanup = $this->mock->requestsTo( 'cleanup.php' );
		$this->assertSame( self::CLEANUP_URL, $cleanup[0]['url'] );
		$this->assertSame(
			array(
				'plugin_version' => 'EMR4.2.0',
				'key'            => self::API_KEY,
				'urllist'        => array( esc_url_raw( $this->source_url ) ),
				'item_id'        => $this->attachment_id,
			),
			$cleanup[0]['request']
		);
	}

	public function test_cleanup_error_does_not_stop_the_request() {
		$this->use_key();
		$this->add_test_filter(
			'pre_http_request',
			function ( $response, $args, $url ) {
				return ( self::CLEANUP_URL === $url ) ? new WP_Error( 'http_request_failed', 'cleanup down' ) : $response;
			},
			11
		);

		$result = $this->run_request( $this->post() );

		$this->assertTrue( $result->success );
		$this->assertCount( 1, $this->mock->requestsTo( 'reducer.php' ) );
	}

	public function test_expired_plan_returns_plan_expired_without_reducer_request() {
		$this->use_key();
		$this->mock->setKnob( 'unlimited', 'false' );

		$result = $this->run_request( $this->post() );

		$this->assertFalse( $result->success );
		$this->assertTrue( $result->plan_expired );
		$this->assertSame( 'Your Unlimited plan is no longer active. Please renew at shortpixel.com/pricing or remove your API Key to use the Free plan.', $result->message );
		$this->assertCount( 1, $this->mock->requestsTo( 'api-status.php' ) );
		$this->assertCount( 0, $this->mock->requestsTo( 'reducer.php' ) );
		$this->assertCount( 0, $this->mock->requestsTo( 'cleanup.php' ) );
		$this->assertSame( 'transparent', get_option( self::SETTINGS_OPTION )['bg_type'] );
	}

	/*
	 * Responses.
	 */

	public function test_status_two_returns_the_result_and_stores_it_in_a_transient() {
		$_POST          = $this->post();
		$_POST['nonce'] = 'nonce-value';
		$before         = time();

		$result = $this->run_request( $_POST );

		$this->assertTrue( $result->success );
		$this->assertNull( $result->message );
		$this->assertSame( $this->source_url, $result->url );
		$this->assertSame( ' plugin_version:EMR4.2.0  bg_remove:1  lossy:0 ', $result->settings );

		$this->assertIsString( $result->image );
		$this->assertStringStartsWith( MockEmrApi::DOWNLOAD_BASE, $result->image );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $result->image, PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( 'ts', $query );
		$this->assertGreaterThanOrEqual( $before, (int) $query['ts'] );
		$this->assertLessThanOrEqual( time(), (int) $query['ts'] );

		$stored = remove_query_arg( 'ts', $result->image );
		$this->assertIsString( $result->key );
		$this->assertSame( wp_hash( 'nonce-value' . $stored, 'logged_in' ), $result->key );
		$this->assertSame( $stored, get_transient( 'emr_' . $result->key ) );
	}

	public function test_keyed_settings_summary_includes_key_and_item_id() {
		$this->use_key();

		$result = $this->run_request( $this->post() );

		$this->assertTrue( $result->success );
		$this->assertSame(
			' plugin_version:EMR4.2.0  bg_remove:1  lossy:0  key:' . self::API_KEY . '  item_id:' . $this->attachment_id . ' ',
			$result->settings
		);
	}

	public function test_integer_status_two_is_not_success() {
		$this->mock->setKnob( 'malformedBody', '[{"Status":{"Code":2,"Message":"Done as int"},"LosslessURL":"https://api.shortpixel.com/f/x.jpg"}]' );

		$result = $this->run_request( $this->post() );

		$this->assertFalse( $result->success );
		$this->assertNull( $result->image );
		$this->assertSame( 'Done as int', $result->message );
	}

	public function test_error_status_copies_the_api_message() {
		$this->mock->setKnob( 'forceStatusCode', '-102' );
		$this->mock->setKnob( 'errorMessage', 'Invalid image' );

		$result = $this->run_request( $this->post() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'Invalid image', $result->message );
		$this->assertFalse( property_exists( $result, 'quota_exceeded' ) );
		$this->assertFalse( property_exists( $result, 'key' ) );
		$this->assertCount( 1, $this->mock->requestsTo( 'reducer.php' ) );
	}

	public function test_quota_exceeded_sets_the_flag_and_friendly_message() {
		$this->mock->setKnob( 'quotaExceeded', true );

		$result = $this->run_request( $this->post() );

		$this->assertFalse( $result->success );
		$this->assertTrue( $result->quota_exceeded );
		$this->assertSame( 'Quota exceeded. Please purchase the Unlimited or Unlimited AI plan and add your API key to continue removing backgrounds.', $result->message );
	}

	public function test_object_response_message_is_used() {
		$this->mock->setKnob( 'malformedBody', '{"Status":{"Code":"-1","Message":"Object shaped error"}}' );

		$result = $this->run_request( $this->post() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'Object shaped error', $result->message );
	}

	public function test_object_response_with_quota_message_sets_the_flag() {
		$this->mock->setKnob( 'malformedBody', '{"Status":{"Code":"-403","Message":"quota EXCEEDED for today"}}' );

		$result = $this->run_request( $this->post() );

		$this->assertTrue( $result->quota_exceeded );
		$this->assertStringStartsWith( 'Quota exceeded. Please purchase', $result->message );
	}

	public function test_malformed_body_fails_without_message() {
		foreach ( array( 'not json at all', '{}', '' ) as $body ) {
			$this->mock->setKnob( 'malformedBody', $body );

			$result = $this->run_request( $this->post() );

			$this->assertFalse( $result->success, 'Body: ' . $body );
			$this->assertNull( $result->image );
			$this->assertNull( $result->message );
		}
	}

	public function test_wp_error_message_is_returned() {
		$this->mock->setKnob( 'wpErrorMessage', 'cURL error 28: timed out' );

		$result = $this->run_request( $this->post() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'cURL error 28: timed out', $result->message );
		$this->assertCount( 1, $this->mock->requestsTo( 'reducer.php' ) );
	}

	public function test_exception_during_the_request_becomes_the_message() {
		$this->add_test_filter(
			'pre_http_request',
			function () {
				throw new Exception( 'transport exploded' );
			},
			1
		);

		$result = $this->run_request( $this->post() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'transport exploded', $result->message );
	}

	/*
	 * Retries. These two tests sleep about 3 s each in production code.
	 */

	public function test_pending_status_is_retried_once_then_succeeds() {
		$this->use_key();
		$this->mock->setKnob( 'forceStatusCode', '1' );
		$this->mock->setKnob( 'waitingRounds', 1 );

		$result = $this->run_request( $this->post() );

		$reducer = $this->mock->requestsTo( 'reducer.php' );
		$this->assertCount( 2, $reducer );
		$this->assertTrue( $reducer[0]['request']['refresh'] );
		$this->assertFalse( $reducer[1]['request']['refresh'] );
		$this->assertCount( 1, $this->mock->requestsTo( 'api-status.php' ) );
		$this->assertCount( 1, $this->mock->requestsTo( 'cleanup.php' ) );
		$this->assertTrue( $result->success );
		$this->assertIsString( $result->key );
		$this->track_transient( $result );
	}

	public function test_attempt_limit_returns_the_timeout_message_without_request() {
		$this->use_key();
		$api = new Api();
		$this->set_counter( $api, 29 );

		$result = $api->request( $this->post() );

		$this->assertFalse( $result->success );
		$this->assertSame( self::LIMIT_MESSAGE, $result->message );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	/*
	 * handleSuccess().
	 */

	public function test_handleSuccess_keys_the_transient_on_the_posted_nonce() {
		$_POST  = array( 'nonce' => ' <b>abc</b> ' );
		$result = (object) array( 'image' => 'https://api.shortpixel.com/f/one.jpg' );

		$key = ( new Api() )->handleSuccess( $result );

		$this->assertIsString( $key );
		$this->assertSame( wp_hash( 'abc' . $result->image, 'logged_in' ), $key );
		$this->assertSame( $result->image, get_transient( 'emr_' . $key ) );
	}

	public function test_handleSuccess_without_nonce_uses_a_created_nonce_and_thirty_minutes() {
		$result = (object) array( 'image' => 'https://api.shortpixel.com/f/two.jpg' );
		$before = time();

		$key = ( new Api() )->handleSuccess( $result );

		$this->assertSame( wp_hash( wp_create_nonce() . $result->image, 'logged_in' ), $key );
		$this->assertSame( $result->image, get_transient( 'emr_' . $key ) );
		if ( ! wp_using_ext_object_cache() ) {
			$timeout = (int) get_option( '_transient_timeout_emr_' . $key );
			$this->assertGreaterThanOrEqual( $before + 30 * MINUTE_IN_SECONDS, $timeout );
			$this->assertLessThanOrEqual( time() + 30 * MINUTE_IN_SECONDS, $timeout );
		}
	}

	/*
	 * handleDownload().
	 */

	public function test_handleDownload_without_transient_returns_message() {
		$result = ( new Api() )->handleDownload( 'no-such-key' );

		$this->assertFalse( $result->success );
		$this->assertNull( $result->image );
		$this->assertSame( 'This file seems not available anymore. Please try again', $result->message );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	public function test_handleDownload_saves_the_result_to_a_temp_file() {
		$url = $this->mock->stashImage();
		set_transient( 'emr_download-key', $url, 60 );

		$result = ( new Api() )->handleDownload( 'download-key' );
		if ( is_string( $result->image ) ) {
			$this->files[] = $result->image;
		}

		$this->assertTrue( $result->success );
		$this->assertNull( $result->message );
		$this->assertIsString( $result->image );
		$this->assertFileExists( $result->image );
		$this->assertSame( file_get_contents( dirname( __DIR__ ) . '/fixtures/test-image.jpg' ), file_get_contents( $result->image ) );
		$this->assertSame( $url, $this->mock->requestsTo( '/f/' )[0]['url'] );
		$this->assertSame( $url, get_transient( 'emr_download-key' ) );
	}

	public function test_handleDownload_reports_download_failed_when_no_file_exists() {
		$url = $this->mock->stashImage();
		set_transient( 'emr_failed-key', $url, 60 );
		$files = &$this->files;
		$this->add_test_filter(
			'shortpixel_image_exists',
			function ( $exists, $path ) use ( &$files ) {
				$files[] = $path;
				return false;
			},
			10
		);

		$result = ( new Api() )->handleDownload( 'failed-key' );

		$this->assertFalse( $result->success );
		$this->assertNull( $result->image );
		$this->assertSame( 'Download failed', $result->message );
	}

	/**
	 * handleDownload() creates its target with wp_tempnam(), which already
	 * creates an empty file, and FileSystemController::downloadFile() only
	 * checks that the target exists. When both download attempts fail, the
	 * empty temp file still exists, so the result is success with an empty
	 * image and "Download failed" is never returned for a network error.
	 * The download should be judged on the HTTP result (or a non-empty file).
	 *
	 * Where: Api::handleDownload() with FileSystemController::downloadFile().
	 *
	 * How to recognise the fix: success is false and the message is
	 * 'Download failed'.
	 */
	public function test_handleDownload_network_error_reports_success_pinned_for_deferred_fix() {
		// A host the mock does not answer: the filter below fails every
		// attempt before any byte is written to the target.
		$url = 'https://results.example.test/f/broken.jpg';
		set_transient( 'emr_error-key', $url, 60 );
		$attempts = 0;
		$this->add_test_filter(
			'pre_http_request',
			function ( $response, $args, $request_url ) use ( $url, &$attempts ) {
				if ( $url !== $request_url ) {
					return $response;
				}
				$attempts++;
				return new WP_Error( 'http_request_failed', 'download down' );
			},
			11
		);

		$result = ( new Api() )->handleDownload( 'error-key' );
		if ( is_string( $result->image ) ) {
			$this->files[] = $result->image;
		}

		$this->assertSame( 2, $attempts );
		$this->assertTrue( $result->success );
		$this->assertNull( $result->message );
		$this->assertIsString( $result->image );
		$this->assertFileExists( $result->image );
		$this->assertSame( 0, filesize( $result->image ) );
	}
}
