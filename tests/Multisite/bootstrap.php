<?php
/**
 * PHPUnit bootstrap for the Multisite suite.
 *
 * PHPUnit allows one bootstrap per configuration file, and
 * phpunit-integration.xml names tests/Integration/bootstrap.php. The
 * Multisite suite needs the WordPress test library to build the install as
 * a network, which it decides while it loads, so the switch has to be set
 * before that. bin/test.sh with the ms flag therefore runs
 *
 *     phpunit -c phpunit-integration.xml --bootstrap tests/Multisite/bootstrap.php --testsuite Multisite
 *
 * (a command-line bootstrap overrides the configured one) and also passes
 * WP_MULTISITE=1 into the container. This file sets the same switches
 * itself, so selecting it by hand works too, and then hands over to the
 * integration bootstrap unchanged.
 *
 * The WP test library reads two switches: the WP_MULTISITE environment
 * variable (also forwarded to the install script it runs in a child
 * process) and the WP_TESTS_MULTISITE constant. Both are set.
 *
 * @package Enable_Media_Replace
 */

putenv( 'WP_MULTISITE=1' );
$_ENV['WP_MULTISITE']    = '1';
$_SERVER['WP_MULTISITE'] = '1';

if ( ! defined( 'WP_TESTS_MULTISITE' ) ) {
	define( 'WP_TESTS_MULTISITE', true );
}

require dirname( __DIR__ ) . '/Integration/bootstrap.php';
