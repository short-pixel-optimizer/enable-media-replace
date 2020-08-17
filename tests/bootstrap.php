<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Enable_Media_Replace
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

define('SHORTPIXEL_DEBUG', 4); // Note - debug logs will go into /tmp/
define('SHORTPIXEL_DEBUG_TARGET', true);

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // WPCS: XSS ok.
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	echo "MANUAL LOAD";
	require dirname( dirname( __FILE__ ) ) . '/enable-media-replace.php';
}
tests_add_filter( 'plugins_loaded', '_manually_load_plugin' );


// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
