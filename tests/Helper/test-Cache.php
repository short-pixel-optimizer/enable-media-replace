<?php
/**
 * Tests for EnableMediaReplace\emrCache (classes/cache.php).
 *
 * emrCache detects ten page-cache plugins through a probe each (a function,
 * a class, a constant, a hooked action or a global) and calls their flush.
 * The probes are reproduced here with stubs that record every call in
 * EMR_Test_Cache_Calls::$calls.
 *
 * This file alters global state for the whole PHPUnit process: it declares
 * the stub functions w3tc_pgcache_flush(), wp_cache_clean_cache(),
 * sg_cachepress_purge_cache() and rocket_clean_domain(), the stub classes
 * WpeCommon, WpFastestCache and Cache_Enabler, and the constants LSCWP_DIR
 * and BREEZE_VERSION. Functions, classes and constants cannot be removed
 * again, so they stay for every later test in the process; they only record
 * calls and have no other effect. Every probe that can be undone (the
 * `fastpixel/purge/all` action, the `$wp_fastest_cache` global, the recorded
 * calls) is set per test and restored in tear_down().
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\emrCache;

/**
 * Call recorder shared by the cache plugin stubs below.
 */
class EMR_Test_Cache_Calls {

	/**
	 * Recorded calls, in order, as strings.
	 *
	 * @var string[]
	 */
	public static $calls = array();

	/**
	 * Records one call.
	 *
	 * @param string $name Call label.
	 */
	public static function record( $name ) {
		self::$calls[] = $name;
	}
}

if ( ! function_exists( 'w3tc_pgcache_flush' ) ) {
	/** W3 Total Cache stub. */
	function w3tc_pgcache_flush() {
		EMR_Test_Cache_Calls::record( 'w3tc_pgcache_flush' );
	}
}

if ( ! function_exists( 'wp_cache_clean_cache' ) ) {
	/**
	 * WP Super Cache stub.
	 *
	 * @param string $file_prefix Cache file prefix.
	 */
	function wp_cache_clean_cache( $file_prefix = '' ) {
		EMR_Test_Cache_Calls::record( 'wp_cache_clean_cache' );
	}
}

if ( ! function_exists( 'sg_cachepress_purge_cache' ) ) {
	/** SiteGround Optimizer stub. */
	function sg_cachepress_purge_cache() {
		EMR_Test_Cache_Calls::record( 'sg_cachepress_purge_cache' );
	}
}

if ( ! function_exists( 'rocket_clean_domain' ) ) {
	/** WP Rocket stub. */
	function rocket_clean_domain() {
		EMR_Test_Cache_Calls::record( 'rocket_clean_domain' );
	}
}

if ( ! class_exists( 'WpeCommon' ) ) {
	/** WP Engine stub with the three purge methods emrCache calls. */
	class WpeCommon {
		public static function purge_memcached() {
			EMR_Test_Cache_Calls::record( 'WpeCommon::purge_memcached' );
		}
		public static function clear_maxcdn_cache() {
			EMR_Test_Cache_Calls::record( 'WpeCommon::clear_maxcdn_cache' );
		}
		public static function purge_varnish_cache() {
			EMR_Test_Cache_Calls::record( 'WpeCommon::purge_varnish_cache' );
		}
	}
}

if ( ! class_exists( 'WpFastestCache' ) ) {
	/** WP Fastest Cache stub. */
	class WpFastestCache {
		public function deleteCache() {
			EMR_Test_Cache_Calls::record( 'WpFastestCache::deleteCache' );
		}
	}
}

if ( ! class_exists( 'Cache_Enabler' ) ) {
	/** Cache Enabler stub (detected by class name only). */
	class Cache_Enabler {
	}
}

if ( ! defined( 'LSCWP_DIR' ) ) {
	define( 'LSCWP_DIR', '/emr-test/litespeed-cache/' );
}

if ( ! defined( 'BREEZE_VERSION' ) ) {
	define( 'BREEZE_VERSION', '0.0.0-emr-test' );
}

class CacheTest extends WP_UnitTestCase {

	/**
	 * Previous value of the WP Fastest Cache global.
	 *
	 * @var mixed
	 */
	private $saved_fastest_cache;

	/**
	 * Whether the WP Fastest Cache global was set before the test.
	 *
	 * @var bool
	 */
	private $had_fastest_cache;

	/**
	 * Arguments received by the `emr/cache/flush` listener.
	 *
	 * @var array
	 */
	private $flush_args = array();

	public function set_up() {
		parent::set_up();
		EMR_Test_Cache_Calls::$calls = array();
		$this->flush_args            = array();
		$this->had_fastest_cache     = array_key_exists( 'wp_fastest_cache', $GLOBALS );
		$this->saved_fastest_cache   = $this->had_fastest_cache ? $GLOBALS['wp_fastest_cache'] : null;
		unset( $GLOBALS['wp_fastest_cache'] );
	}

	public function tear_down() {
		remove_action( 'fastpixel/purge/all', array( $this, 'record_fastpixel' ) );
		remove_action( 'litespeed_purge_all', array( $this, 'record_litespeed' ) );
		remove_action( 'breeze_clear_all_cache', array( $this, 'record_breeze' ) );
		remove_action( 'cache_enabler_clear_complete_cache', array( $this, 'record_cache_enabler' ) );
		remove_action( 'emr/cache/flush', array( $this, 'record_emr_flush' ) );
		if ( $this->had_fastest_cache ) {
			$GLOBALS['wp_fastest_cache'] = $this->saved_fastest_cache;
		} else {
			unset( $GLOBALS['wp_fastest_cache'] );
		}
		EMR_Test_Cache_Calls::$calls = array();
		parent::tear_down();
	}

	/** Listener for `fastpixel/purge/all`. */
	public function record_fastpixel() {
		EMR_Test_Cache_Calls::record( 'fastpixel/purge/all' );
	}

	/** Listener for `litespeed_purge_all`. */
	public function record_litespeed() {
		EMR_Test_Cache_Calls::record( 'litespeed_purge_all' );
	}

	/** Listener for `breeze_clear_all_cache`. */
	public function record_breeze() {
		EMR_Test_Cache_Calls::record( 'breeze_clear_all_cache' );
	}

	/** Listener for `cache_enabler_clear_complete_cache`. */
	public function record_cache_enabler() {
		EMR_Test_Cache_Calls::record( 'cache_enabler_clear_complete_cache' );
	}

	/**
	 * Listener for `emr/cache/flush`.
	 *
	 * @param mixed $post_id Post ID passed by emrCache.
	 */
	public function record_emr_flush( $post_id ) {
		$this->flush_args[] = $post_id;
	}

	/**
	 * Reads a protected `has_*` flag.
	 *
	 * @param emrCache $cache Instance.
	 * @param string   $flag  Property name.
	 * @return mixed
	 */
	private function flag( emrCache $cache, $flag ) {
		$prop = new ReflectionProperty( emrCache::class, $flag );
		$prop->setAccessible( true );
		return $prop->getValue( $cache );
	}

	/** Returns an instance after checkCaches(). */
	private function checked() {
		$cache = new emrCache();
		$cache->checkCaches();
		return $cache;
	}

	/** Creates an attachment post and returns its ID. */
	private function attachment() {
		return self::factory()->attachment->create_object(
			array(
				'file'           => 'emr-cache-test.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
	}

	public function test_constructor_detects_nothing() {
		$cache = new emrCache();

		$this->assertFalse( $this->flag( $cache, 'has_w3tc' ) );
		$this->assertFalse( $this->flag( $cache, 'has_litespeed' ) );
	}

	public function test_detects_w3tc_super_cache_siteground_and_rocket_by_function() {
		$cache = $this->checked();

		$this->assertTrue( $this->flag( $cache, 'has_w3tc' ) );
		$this->assertTrue( $this->flag( $cache, 'has_supercache' ) );
		$this->assertTrue( $this->flag( $cache, 'has_siteground' ) );
		$this->assertTrue( $this->flag( $cache, 'has_wprocket' ) );
	}

	public function test_detects_wp_engine_and_cache_enabler_by_class() {
		$cache = $this->checked();

		$this->assertTrue( $this->flag( $cache, 'has_wpengine' ) );
		$this->assertTrue( $this->flag( $cache, 'has_cacheenabler' ) );
	}

	public function test_detects_litespeed_and_breeze_by_constant() {
		$cache = $this->checked();

		$this->assertTrue( $this->flag( $cache, 'has_litespeed' ) );
		$this->assertTrue( $this->flag( $cache, 'has_breeze' ) );
	}

	public function test_detects_wp_fastest_cache_by_method_and_global() {
		$GLOBALS['wp_fastest_cache'] = new WpFastestCache();

		$this->assertTrue( $this->flag( $this->checked(), 'has_fastestcache' ) );
	}

	public function test_does_not_detect_wp_fastest_cache_without_the_global() {
		$this->assertTrue( method_exists( 'WpFastestCache', 'deleteCache' ), 'Precondition: the class is present.' );

		$this->assertFalse( $this->flag( $this->checked(), 'has_fastestcache' ) );
	}

	public function test_detects_fastpixel_by_hooked_action() {
		add_action( 'fastpixel/purge/all', array( $this, 'record_fastpixel' ) );

		$this->assertTrue( $this->flag( $this->checked(), 'has_fastpixel' ) );
	}

	public function test_does_not_detect_fastpixel_without_a_hooked_action() {
		$this->assertFalse( has_action( 'fastpixel/purge/all' ), 'Precondition: nothing hooked.' );

		$this->assertFalse( $this->flag( $this->checked(), 'has_fastpixel' ) );
	}

	public function test_check_caches_never_resets_a_flag() {
		add_action( 'fastpixel/purge/all', array( $this, 'record_fastpixel' ) );
		$cache = new emrCache();
		$cache->checkCaches();
		$this->assertTrue( $this->flag( $cache, 'has_fastpixel' ), 'Precondition: detected on the first call.' );

		remove_action( 'fastpixel/purge/all', array( $this, 'record_fastpixel' ) );
		$cache->checkCaches();

		$this->assertTrue( $this->flag( $cache, 'has_fastpixel' ) );
	}

	public function test_flush_calls_every_detected_plugin_flush() {
		add_action( 'litespeed_purge_all', array( $this, 'record_litespeed' ) );
		add_action( 'breeze_clear_all_cache', array( $this, 'record_breeze' ) );
		add_action( 'cache_enabler_clear_complete_cache', array( $this, 'record_cache_enabler' ) );

		$this->assertNull( ( new emrCache() )->flushCache( array( 'post_id' => 0 ) ) );

		$this->assertSame(
			array(
				'w3tc_pgcache_flush',
				'WpeCommon::purge_memcached',
				'WpeCommon::clear_maxcdn_cache',
				'WpeCommon::purge_varnish_cache',
				'sg_cachepress_purge_cache',
				'litespeed_purge_all',
				'rocket_clean_domain',
				'breeze_clear_all_cache',
				'cache_enabler_clear_complete_cache',
			),
			EMR_Test_Cache_Calls::$calls
		);
	}

	public function test_flush_calls_wp_fastest_cache_delete_through_the_global() {
		$GLOBALS['wp_fastest_cache'] = new WpFastestCache();

		( new emrCache() )->flushCache( array() );

		$this->assertContains( 'WpFastestCache::deleteCache', EMR_Test_Cache_Calls::$calls );
	}

	public function test_flush_skips_wp_fastest_cache_without_the_global() {
		( new emrCache() )->flushCache( array() );

		$this->assertContains( 'w3tc_pgcache_flush', EMR_Test_Cache_Calls::$calls, 'Precondition: the flush ran.' );
		$this->assertNotContains( 'WpFastestCache::deleteCache', EMR_Test_Cache_Calls::$calls );
	}

	public function test_flush_fires_fastpixel_purge_once_when_hooked() {
		add_action( 'fastpixel/purge/all', array( $this, 'record_fastpixel' ) );

		( new emrCache() )->flushCache( array() );

		$this->assertSame( 1, count( array_keys( EMR_Test_Cache_Calls::$calls, 'fastpixel/purge/all', true ) ) );
	}

	public function test_flush_fires_emr_cache_flush_last_with_the_post_id() {
		add_action( 'emr/cache/flush', array( $this, 'record_emr_flush' ) );
		add_action( 'cache_enabler_clear_complete_cache', array( $this, 'record_cache_enabler' ) );
		$post_id = $this->attachment();

		( new emrCache() )->flushCache( array( 'post_id' => $post_id ) );

		$this->assertSame( array( $post_id ), $this->flush_args );
		$this->assertSame( 'cache_enabler_clear_complete_cache', end( EMR_Test_Cache_Calls::$calls ), 'The plugin flushes run before the action.' );
	}

	public function test_flush_fires_emr_cache_flush_with_zero_by_default() {
		add_action( 'emr/cache/flush', array( $this, 'record_emr_flush' ) );

		( new emrCache() )->flushCache( array() );

		$this->assertSame( array( 0 ), $this->flush_args );
	}

	public function test_flush_mode_post_with_a_post_id_cleans_only_that_post() {
		$post_id = $this->attachment();
		wp_cache_set( 'emr_probe', 'kept', 'emr_test_group' );
		$cleaned = did_action( 'clean_post_cache' );

		( new emrCache() )->flushCache( array( 'flush_mode' => 'post', 'post_id' => $post_id ) );

		$this->assertSame( $cleaned + 1, did_action( 'clean_post_cache' ) );
		$this->assertSame( 'kept', wp_cache_get( 'emr_probe', 'emr_test_group' ), 'The object cache is not flushed.' );
	}

	public function test_flush_mode_post_with_post_id_zero_flushes_the_whole_object_cache() {
		wp_cache_set( 'emr_probe', 'gone', 'emr_test_group' );
		$cleaned = did_action( 'clean_post_cache' );

		( new emrCache() )->flushCache( array( 'flush_mode' => 'post', 'post_id' => 0 ) );

		$this->assertFalse( wp_cache_get( 'emr_probe', 'emr_test_group' ) );
		$this->assertSame( $cleaned, did_action( 'clean_post_cache' ) );
	}

	public function test_other_flush_mode_flushes_the_whole_object_cache_even_with_a_post_id() {
		$post_id = $this->attachment();
		wp_cache_set( 'emr_probe', 'gone', 'emr_test_group' );
		$cleaned = did_action( 'clean_post_cache' );

		( new emrCache() )->flushCache( array( 'flush_mode' => 'all', 'post_id' => $post_id ) );

		$this->assertFalse( wp_cache_get( 'emr_probe', 'emr_test_group' ) );
		$this->assertSame( $cleaned, did_action( 'clean_post_cache' ) );
	}

	/**
	 * emrCache::removeSuperCache() is never called: flushCache() detects WP
	 * Super Cache (has_supercache becomes true when wp_cache_clean_cache()
	 * exists) but its call to removeSuperCache() is commented out, so the
	 * detection has no effect and the method is dead code. The correct
	 * behaviour is either to remove the method and its detection or to call
	 * it when WP Super Cache is detected.
	 *
	 * How to recognise the fix: either wp_cache_clean_cache() shows up in the
	 * recorded calls (assertNotContains fails) or has_supercache no longer
	 * exists (the reflection precondition fails).
	 */
	public function test_flush_does_not_call_wp_super_cache_pinned_for_deferred_fix() {
		$cache = new emrCache();

		$cache->flushCache( array( 'post_id' => 0 ) );

		$this->assertTrue( $this->flag( $cache, 'has_supercache' ), 'Precondition: WP Super Cache is detected.' );
		$this->assertContains( 'w3tc_pgcache_flush', EMR_Test_Cache_Calls::$calls, 'Precondition: the flush ran.' );
		$this->assertNotContains( 'wp_cache_clean_cache', EMR_Test_Cache_Calls::$calls );
	}
}
