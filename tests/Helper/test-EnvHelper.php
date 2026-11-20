<?php
/**
 * Tests for EnableMediaReplace\Helper\EnvHelper (classes/Helper/envHelper.php).
 *
 * The flags are computed once, in the constructor of the singleton, from the
 * WordPress conditional functions. Each test resets the singleton by
 * reflection so it sees a freshly computed instance. DOING_AJAX and
 * DOING_CRON cannot be defined in-process without leaking into every later
 * test, so the request-type flags are reached through the `wp_doing_ajax` and
 * `wp_doing_cron` filters and the HTTP_ACCEPT header that their core
 * functions read.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Helper\EnvHelper;

class EnvHelperTest extends WP_UnitTestCase {

	/**
	 * Server variables touched by the JSON request test.
	 *
	 * @var array
	 */
	private $saved_server = array();

	public function set_up() {
		parent::set_up();
		$this->reset_instance();
		$this->saved_server = array(
			'HTTP_ACCEPT'  => isset( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : null,
			'CONTENT_TYPE' => isset( $_SERVER['CONTENT_TYPE'] ) ? $_SERVER['CONTENT_TYPE'] : null,
		);
	}

	public function tear_down() {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_doing_cron', '__return_true' );
		foreach ( $this->saved_server as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		$this->reset_instance();
		parent::tear_down();
	}

	/** Sets the protected static singleton back to null. */
	private function reset_instance() {
		$prop = new ReflectionProperty( EnvHelper::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	public function test_get_instance_returns_the_same_object_on_every_call() {
		$first  = EnvHelper::getInstance();
		$second = EnvHelper::getInstance();

		$this->assertInstanceOf( EnvHelper::class, $first );
		$this->assertSame( $first, $second );
	}

	public function test_emr_env_accessor_returns_the_singleton() {
		$this->assertSame( EnvHelper::getInstance(), emr()->env() );
	}

	public function test_single_site_flags() {
		$this->assertFalse( is_multisite(), 'Precondition: the unit suites run on a single site.' );
		$env = EnvHelper::getInstance();

		$this->assertFalse( $env->is_multisite );
		$this->assertTrue( $env->is_mainsite );
		$this->assertFalse( $env->is_network_admin );
	}

	public function test_request_type_flags_are_false_by_default() {
		$env = EnvHelper::getInstance();

		$this->assertFalse( $env->is_ajaxcall );
		$this->assertFalse( $env->is_jsoncall );
		$this->assertFalse( $env->is_croncall );
		foreach ( array( 'is_multisite', 'is_mainsite', 'is_network_admin', 'is_ajaxcall', 'is_jsoncall', 'is_croncall' ) as $flag ) {
			$this->assertIsBool( $env->$flag, $flag . ' must be a bool.' );
		}
	}

	public function test_is_ajaxcall_follows_the_wp_doing_ajax_filter() {
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertTrue( EnvHelper::getInstance()->is_ajaxcall );
	}

	public function test_is_croncall_follows_the_wp_doing_cron_filter() {
		add_filter( 'wp_doing_cron', '__return_true' );

		$this->assertTrue( EnvHelper::getInstance()->is_croncall );
	}

	public function test_is_jsoncall_is_true_for_a_json_accept_header() {
		$_SERVER['HTTP_ACCEPT'] = 'application/json';

		$this->assertTrue( EnvHelper::getInstance()->is_jsoncall );
	}

	public function test_flags_are_computed_once_and_not_refreshed() {
		$env = EnvHelper::getInstance();
		$this->assertFalse( $env->is_ajaxcall, 'Precondition: not an AJAX request.' );

		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertFalse( EnvHelper::getInstance()->is_ajaxcall, 'The singleton keeps the flags of its first computation.' );
		$this->assertTrue( ( new EnvHelper() )->is_ajaxcall, 'A new instance computes the flags again.' );
	}

	/* ------------------------------------------------------------------
	 * Budgets (SPIO's EnvironmentModel::IsOverTimeLimit / IsOverMemoryLimit).
	 * ------------------------------------------------------------------ */

	public function test_the_environment_captures_the_start_time_and_the_php_limits() {
		$before = time();
		$env    = EnvHelper::getInstance();

		$this->assertGreaterThanOrEqual( $before, $env->executionStart );
		$this->assertLessThanOrEqual( time(), $env->executionStart );
		$this->assertSame( (int) ini_get( 'max_execution_time' ), $env->executionLimit );
		$memory = (string) ini_get( 'memory_limit' );
		$this->assertSame( ( (int) $memory < 0 ) ? -1 : (int) wp_convert_hr_to_bytes( $memory ), $env->memoryLimit );
	}

	public function test_is_over_time_limit_at_ninety_percent_of_the_budget_and_never_without_a_limit() {
		$env = EnvHelper::getInstance();

		$this->assertFalse( $env->IsOverTimeLimit( array( 'limit' => 0, 'start' => time() - 1000 ) ), 'No limit, never over.' );
		$this->assertFalse( $env->IsOverTimeLimit( array( 'limit' => 100, 'start' => time() - 89 ) ), '89 of 100 seconds: under 90 %.' );
		$this->assertTrue( $env->IsOverTimeLimit( array( 'limit' => 100, 'start' => time() - 90 ) ), '90 of 100 seconds: over.' );
		$this->assertFalse( $env->IsOverTimeLimit( array( 'limit' => 100, 'start' => time() + 5 ) ), 'A start in the future counts as no time elapsed.' );

		$env->executionLimit = 10;
		$env->executionStart = time() - 9;
		$this->assertTrue( $env->IsOverTimeLimit(), 'Defaults: the request start and max_execution_time.' );

		add_filter( 'emr/process/max_execution', array( $this, 'full_budget' ) );
		$this->assertFalse( $env->IsOverTimeLimit(), 'The filter raises the threshold to 100 %.' );
		remove_filter( 'emr/process/max_execution', array( $this, 'full_budget' ) );
	}

	public function test_is_over_memory_limit_compares_the_usage_with_ninety_percent_of_the_limit() {
		$env = EnvHelper::getInstance();

		$env->memoryLimit = -1;
		$this->assertFalse( $env->IsOverMemoryLimit( 1 ), 'Unlimited memory is never over.' );

		$env->memoryLimit = 1024; // far below any real usage.
		$this->assertTrue( $env->IsOverMemoryLimit( 2 ) );

		$env->memoryLimit = memory_get_usage() * 10;
		$this->assertFalse( $env->IsOverMemoryLimit( 3 ) );

		$env->memoryLimit = (int) ( memory_get_usage() / 0.95 ); // usage is 95 % of the limit: over at 90, under at 100.
		$this->assertTrue( $env->IsOverMemoryLimit( 4 ) );
		add_filter( 'emr/process/max_memory', array( $this, 'full_budget' ) );
		$this->assertFalse( $env->IsOverMemoryLimit( 5 ) );
		remove_filter( 'emr/process/max_memory', array( $this, 'full_budget' ) );
	}

	/** The `emr/process/max_*` filters: the whole budget. */
	public function full_budget() {
		return 100;
	}
}
