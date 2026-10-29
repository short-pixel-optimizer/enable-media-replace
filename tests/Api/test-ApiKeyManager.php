<?php
/**
 * Tests for EnableMediaReplace\ApiKeyManager (classes/ApiKeyManager.php).
 *
 * The api-status endpoint is answered by MockEmrApi (priority 10 on
 * `pre_http_request`); a fail-fast filter at priority 9999 refuses and records
 * any request the mock does not answer, and tear_down() asserts that none
 * happened. A few tests add a filter at priority 11 to rewrite the mock's
 * answer (https-only WP_Error, non-200 status, empty body). The singleton is
 * reset by reflection and the key option is snapshotted and restored.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\ApiKeyManager;

require_once dirname( __DIR__ ) . '/Integration/Helpers/MockEmrApi.php';

class ApiKeyManagerTest extends WP_UnitTestCase {

	const OPTION    = 'emr_shortpixel_api_key';
	const VALID_KEY = 'abcdEFGHIJKLMNOPwxyz';
	const ENDPOINT  = 'https://api.shortpixel.com/v2/api-status.php';

	/**
	 * @var MockEmrApi
	 */
	private $mock;

	/**
	 * The key option before the test (null when absent).
	 *
	 * @var mixed
	 */
	private $saved_option;

	/**
	 * URLs refused by the fail-fast filter.
	 *
	 * @var array
	 */
	private $blocked = array();

	/**
	 * Filters added by a test, as array( hook, callback, priority ).
	 *
	 * @var array
	 */
	private $added_filters = array();

	public function set_up() {
		parent::set_up();
		$this->saved_option = get_option( self::OPTION, null );
		delete_option( self::OPTION );
		$this->reset_singleton();

		$this->mock = MockEmrApi::register();
		$this->mock->reset();
		$this->blocked = array();
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999 );
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		MockEmrApi::unregister();

		if ( null === $this->saved_option ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $this->saved_option );
		}
		$this->reset_singleton();

		$blocked       = $this->blocked;
		$this->blocked = array();
		parent::tear_down();
		$this->assertSame( array(), $blocked, 'A request reached the fail-fast filter.' );
	}

	/**
	 * Refuses every request nobody answered before priority 9999.
	 */
	public function fail_fast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->blocked[] = $url;
		return new WP_Error( 'http_blocked', 'Blocked unmocked request to ' . $url );
	}

	private function reset_singleton() {
		$ref = new ReflectionProperty( ApiKeyManager::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	private function add_test_filter( $hook, $callback, $priority ) {
		add_filter( $hook, $callback, $priority, 3 );
		$this->added_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * A fresh instance that reads the option again.
	 */
	private function fresh() {
		$this->reset_singleton();
		return ApiKeyManager::getInstance();
	}

	private function store_key( $key ) {
		update_option( self::OPTION, $key );
		return $this->fresh();
	}

	/*
	 * Instance and accessors.
	 */

	public function test_getInstance_returns_the_same_instance() {
		$first = ApiKeyManager::getInstance();

		$this->assertInstanceOf( ApiKeyManager::class, $first );
		$this->assertSame( $first, ApiKeyManager::getInstance() );
	}

	public function test_without_option_the_key_is_empty() {
		$manager = $this->fresh();

		$this->assertSame( '', $manager->getApiKey() );
		$this->assertFalse( $manager->hasApiKey() );
	}

	public function test_constructor_reads_the_stored_key() {
		$manager = $this->store_key( self::VALID_KEY );

		$this->assertSame( self::VALID_KEY, $manager->getApiKey() );
		$this->assertTrue( $manager->hasApiKey() );
	}

	public function test_a_non_string_option_is_cast_to_string() {
		$manager = $this->store_key( 12345 );

		$this->assertSame( '12345', $manager->getApiKey() );
	}

	public function test_the_option_is_read_once_per_instance() {
		$manager = $this->store_key( self::VALID_KEY );
		update_option( self::OPTION, 'zzzzZZZZZZZZZZZZyyyy' );

		$this->assertSame( self::VALID_KEY, $manager->getApiKey() );
		$this->assertSame( 'zzzzZZZZZZZZZZZZyyyy', $this->fresh()->getApiKey() );
	}

	/*
	 * getMaskedKey().
	 */

	public function test_masked_key_is_not_set_when_empty() {
		$this->assertSame( 'Not set', $this->fresh()->getMaskedKey() );
	}

	public function test_masked_key_shows_first_and_last_four_characters() {
		$masked = $this->store_key( self::VALID_KEY )->getMaskedKey();

		$this->assertSame( 'abcd************wxyz', $masked );
		$this->assertSame( 20, strlen( $masked ) );
	}

	public function test_masked_key_of_eight_characters_shows_the_whole_key() {
		$this->assertSame( 'abcdwxyz', $this->store_key( 'abcdwxyz' )->getMaskedKey() );
	}

	/**
	 * getMaskedKey() passes `strlen - 8` to str_repeat(). For a stored key
	 * shorter than 8 characters (possible when the option is written outside
	 * saveAndVerify()) the count is negative: PHP 8 throws a ValueError, PHP
	 * 7.4 raises a warning and returns null, so the settings screen breaks.
	 * The count should be clamped with max(0, $len - 8).
	 *
	 * Where: ApiKeyManager::getMaskedKey().
	 *
	 * How to recognise the fix: no ValueError / warning is raised and the
	 * method returns a string for the five-character key.
	 */
	public function test_masked_key_shorter_than_eight_characters_crashes_pinned_for_deferred_fix() {
		$manager = $this->store_key( 'ab123' );
		$this->assertSame( 'ab123', $manager->getApiKey() );

		$error = null;
		try {
			$manager->getMaskedKey();
		} catch ( \Throwable $e ) {
			$error = $e;
		}
		$this->assertInstanceOf( PHP_VERSION_ID >= 80000 ? \ValueError::class : \PHPUnit\Framework\Error\Warning::class, $error );
		$this->assertMatchesRegularExpression( '/^str_repeat\(\)/', $error->getMessage() );
	}

	/*
	 * saveAndVerify(): local validation.
	 */

	public function test_save_empty_key_returns_a_message_without_request() {
		$result = $this->fresh()->saveAndVerify( '   ' );

		$this->assertSame( array( 'success' => false, 'message' => 'Please enter an API Key.' ), $result );
		$this->assertSame( array(), $this->mock->getRequests() );
		$this->assertFalse( get_option( self::OPTION ) );
	}

	public function test_save_key_of_wrong_length_returns_a_message_without_request() {
		$manager = $this->fresh();

		foreach ( array( substr( self::VALID_KEY, 0, 19 ), self::VALID_KEY . 'x' ) as $key ) {
			$result = $manager->saveAndVerify( $key );
			$this->assertFalse( $result['success'] );
			$this->assertSame( 'The API Key must be exactly 20 characters.', $result['message'] );
		}
		$this->assertSame( array(), $this->mock->getRequests() );
		$this->assertFalse( get_option( self::OPTION ) );
	}

	public function test_save_trims_and_sanitizes_the_key_before_the_length_check() {
		$result = $this->fresh()->saveAndVerify( "  <b>" . self::VALID_KEY . "</b>\n" );

		$this->assertTrue( $result['success'] );
		$this->assertSame( self::VALID_KEY, get_option( self::OPTION ) );
		$this->assertSame( self::VALID_KEY, $this->mock->lastRequest()['request']['key'] );
	}

	/*
	 * saveAndVerify(): remote validation.
	 */

	public function test_save_valid_unlimited_key_stores_it() {
		$manager = $this->fresh();
		$result  = $manager->saveAndVerify( self::VALID_KEY );

		$this->assertSame(
			array(
				'success' => true,
				'message' => 'API Key saved and verified successfully. You now have unlimited background removal.',
			),
			$result
		);
		$this->assertSame( self::VALID_KEY, get_option( self::OPTION ) );
		$this->assertSame( self::VALID_KEY, $manager->getApiKey() );
		$this->assertTrue( $manager->hasApiKey() );
	}

	public function test_save_sends_key_host_domain_check_and_info() {
		$this->fresh()->saveAndVerify( self::VALID_KEY );

		$requests = $this->mock->requestsTo( 'api-status.php' );
		$this->assertCount( 1, $requests );
		$this->assertSame( self::ENDPOINT, $requests[0]['url'] );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertSame( 15, $requests[0]['args']['timeout'] );
		$this->assertSame(
			array(
				'key'         => self::VALID_KEY,
				'host'        => wp_parse_url( get_site_url(), PHP_URL_HOST ),
				'DomainCheck' => get_site_url(),
				'Info'        => get_bloginfo( 'version' ) . '|' . phpversion(),
			),
			$requests[0]['request']
		);
	}

	public function test_save_wrong_key_returns_not_correct_and_keeps_option() {
		update_option( self::OPTION, 'previousKEYpreviousK' );
		$manager = $this->fresh();
		$this->mock->setKnob( 'apiStatusCode', -401 );

		$result = $manager->saveAndVerify( self::VALID_KEY );

		$this->assertSame( array( 'success' => false, 'message' => 'The API Key is not correct. Please try again.' ), $result );
		$this->assertSame( 'previousKEYpreviousK', get_option( self::OPTION ) );
		$this->assertSame( 'previousKEYpreviousK', $manager->getApiKey() );
	}

	public function test_status_code_is_compared_loosely() {
		$this->mock->setKnob( 'apiStatusCode', '2' );

		$this->assertTrue( $this->fresh()->saveAndVerify( self::VALID_KEY )['success'] );
	}

	public function test_save_valid_key_without_unlimited_plan_returns_upgrade_message() {
		$this->mock->setKnob( 'unlimited', 'false' );
		$manager = $this->fresh();

		$result = $manager->saveAndVerify( self::VALID_KEY );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( '<a href="https://shortpixel.com/pricing" target="_blank" rel="noopener">upgrade</a>', $result['message'] );
		$this->assertStringContainsString( 'Unlimited or Unlimited AI plan', $result['message'] );
		$this->assertFalse( get_option( self::OPTION ) );
		$this->assertSame( '', $manager->getApiKey() );
	}

	public function test_save_without_unlimited_property_is_not_unlimited() {
		$this->mock->setKnob( 'unlimited', null );

		$result = $this->fresh()->saveAndVerify( self::VALID_KEY );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'upgrade', $result['message'] );
	}

	public function test_https_error_is_retried_over_http() {
		$this->add_test_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				return ( self::ENDPOINT === $url ) ? new WP_Error( 'http_request_failed', 'tls down' ) : $preempt;
			},
			11
		);

		$result = $this->fresh()->saveAndVerify( self::VALID_KEY );

		$requests = $this->mock->requestsTo( 'api-status.php' );
		$this->assertCount( 2, $requests );
		$this->assertSame( self::ENDPOINT, $requests[0]['url'] );
		$this->assertSame( 'http://api.shortpixel.com/v2/api-status.php', $requests[1]['url'] );
		$this->assertSame( self::VALID_KEY, $requests[1]['request']['key'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( self::VALID_KEY, get_option( self::OPTION ) );
	}

	public function test_error_on_both_schemes_returns_default_message() {
		$this->mock->setKnob( 'wpErrorMessage', 'offline' );

		$result = $this->fresh()->saveAndVerify( self::VALID_KEY );

		$this->assertCount( 2, $this->mock->requestsTo( 'api-status.php' ) );
		$this->assertSame(
			array(
				'success' => false,
				'message' => 'API Key could not be validated. Please check your internet connection and try again.',
			),
			$result
		);
		$this->assertFalse( get_option( self::OPTION ) );
	}

	public function test_non_200_status_returns_default_message() {
		$this->add_test_filter(
			'pre_http_request',
			function ( $response ) {
				if ( is_array( $response ) ) {
					$response['response']['code'] = 500;
				}
				return $response;
			},
			11
		);

		$result = $this->fresh()->saveAndVerify( self::VALID_KEY );

		$this->assertFalse( $result['success'] );
		$this->assertStringStartsWith( 'API Key could not be validated.', $result['message'] );
		$this->assertCount( 1, $this->mock->requestsTo( 'api-status.php' ) );
		$this->assertFalse( get_option( self::OPTION ) );
	}

	public function test_empty_body_returns_default_message() {
		$this->add_test_filter(
			'pre_http_request',
			function ( $response ) {
				if ( is_array( $response ) ) {
					$response['body'] = '';
				}
				return $response;
			},
			11
		);

		$result = $this->fresh()->saveAndVerify( self::VALID_KEY );

		$this->assertFalse( $result['success'] );
		$this->assertStringStartsWith( 'API Key could not be validated.', $result['message'] );
	}

	/*
	 * deleteKey().
	 */

	public function test_deleteKey_clears_option_and_instance() {
		$manager = $this->store_key( self::VALID_KEY );

		$manager->deleteKey();

		$this->assertFalse( get_option( self::OPTION ) );
		$this->assertSame( '', $manager->getApiKey() );
		$this->assertFalse( $manager->hasApiKey() );
		$this->assertSame( 'Not set', $manager->getMaskedKey() );
		$this->assertSame( '', $this->fresh()->getApiKey() );
	}

	/*
	 * verifyUnlimitedPlan().
	 */

	public function test_verify_without_key_is_false_without_request() {
		$this->assertFalse( $this->fresh()->verifyUnlimitedPlan() );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	public function test_verify_unlimited_key_is_true_and_omits_domain_check() {
		$result = $this->store_key( self::VALID_KEY )->verifyUnlimitedPlan();

		$this->assertTrue( $result );
		$requests = $this->mock->requestsTo( 'api-status.php' );
		$this->assertCount( 1, $requests );
		$this->assertSame(
			array(
				'key'  => self::VALID_KEY,
				'host' => wp_parse_url( get_site_url(), PHP_URL_HOST ),
			),
			$requests[0]['request']
		);
	}

	public function test_verify_is_not_cached_between_calls() {
		$manager = $this->store_key( self::VALID_KEY );

		$this->assertTrue( $manager->verifyUnlimitedPlan() );
		$this->mock->setKnob( 'unlimited', 'false' );
		$this->assertFalse( $manager->verifyUnlimitedPlan() );
		$this->assertCount( 2, $this->mock->requestsTo( 'api-status.php' ) );
	}

	public function test_verify_invalid_key_is_false() {
		$this->mock->setKnob( 'apiStatusCode', -401 );

		$this->assertFalse( $this->store_key( self::VALID_KEY )->verifyUnlimitedPlan() );
	}

	public function test_verify_network_error_is_false() {
		$this->mock->setKnob( 'wpErrorMessage', 'offline' );

		$this->assertFalse( $this->store_key( self::VALID_KEY )->verifyUnlimitedPlan() );
		$this->assertCount( 2, $this->mock->requestsTo( 'api-status.php' ) );
	}

	public function test_unlimited_boolean_true_passes_the_loose_compare() {
		$this->mock->setKnob( 'unlimited', true );

		$this->assertTrue( $this->store_key( self::VALID_KEY )->verifyUnlimitedPlan() );
	}

	public function test_unlimited_string_false_and_integer_one_fail() {
		$manager = $this->store_key( self::VALID_KEY );

		$this->mock->setKnob( 'unlimited', 'false' );
		$this->assertFalse( $manager->verifyUnlimitedPlan() );

		$this->mock->setKnob( 'unlimited', 1 );
		$this->assertFalse( $manager->verifyUnlimitedPlan() );
	}
}
