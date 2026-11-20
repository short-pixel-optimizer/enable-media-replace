<?php
/**
 * PHPUnit bootstrap file for the unit suites (phpunit.xml.dist).
 *
 * Loads the WordPress test library and, on `plugins_loaded`, the plugin
 * under test. tests/Integration/bootstrap.php builds on this file, and
 * tests/Multisite/bootstrap.php builds on that one.
 *
 * Nothing in this file prints output: PHPUnit treats bytes written before
 * the first test as part of the run, and a stray echo here would end up in
 * every captured AJAX response and in the suite logs.
 *
 * @package Enable_Media_Replace
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

/**
 * Debug logging of the bundled ShortPixel logger.
 *
 * SHORTPIXEL_DEBUG is the log level (4 = debug, every message), and
 * SHORTPIXEL_DEBUG_TARGET sends the log to a file instead of nowhere.
 * EnableMediaReplacePlugin::get() points the logger at
 * `<uploads basedir>/emr_log` whenever debugging is active, which inside
 * the container is the test install's uploads directory under /tmp, so the
 * log never lands in the repository and never reaches the PHPUnit output.
 */
if ( ! defined( 'SHORTPIXEL_DEBUG' ) ) {
	define( 'SHORTPIXEL_DEBUG', 4 );
}
if ( ! defined( 'SHORTPIXEL_DEBUG_TARGET' ) ) {
	define( 'SHORTPIXEL_DEBUG_TARGET', true );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 *
 * Loaded on `plugins_loaded`, like a regular plugin would be at that point:
 * EnableMediaReplacePlugin hooks `init` (runtime()) and `admin_init`
 * (adminInit()), both of which fire later, so nothing is missed.
 */
function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/enable-media-replace.php';
}

tests_add_filter( 'plugins_loaded', '_manually_load_plugin' );

/**
 * Cross-plugin compatibility runs (bin/test.sh --compat) set
 * EMR_PARTNER_PLUGINS=1 after extracting the partner plugins into the
 * test install's plugin dir. Activating them via the active_plugins
 * option lets WP core load them exactly like a production install —
 * paths, constants and lifecycle hooks all behave normally, and EMR's
 * detection of the third parties (Externals, the replacer modules) sees
 * the real plugins.
 *
 * The callback runs when WP core reads the option (WP_PLUGIN_DIR is
 * defined by then); plugins missing from disk are silently skipped so
 * a partial download never fatals the whole suite.
 */
if ( '1' === getenv( 'EMR_PARTNER_PLUGINS' ) ) {
	/**
	 * The partner plugins, as "<dir>/<entry-file>.php".
	 *
	 * Declared once and reused by both the active_plugins override below
	 * and the deprecation filter after it, so the two cannot drift apart.
	 */
	$emr_partner_plugins = array(
		// Free, downloaded from wordpress.org by bin/test.sh --compat.
		// Elementor, Yoast SEO and Smart Slider 3 are detected by the
		// bundled replacer modules; Elementor, SiteOrigin and WP Offload
		// Media also by EMR's own classes/external integrations.
		'elementor/elementor.php',
		'wordpress-seo/wp-seo.php',
		'polylang/polylang.php',
		'woocommerce/woocommerce.php',
		'amazon-s3-and-cloudfront/wordpress-s3.php',
		'siteorigin-panels/siteorigin-panels.php',
		'smart-slider-3/smart-slider-3.php',
		'beaver-builder-lite-version/fl-builder.php',
		'shortpixel-image-optimiser/wp-shortpixel.php',
		'envira-gallery-lite/envira-gallery-lite.php',
		// ACF (free): image, URL, text, WYSIWYG and group fields hold
		// attachment ids and URLs in postmeta; no EMR integration of its
		// own, covered by the generic postmeta rewrite.
		'advanced-custom-fields/acf.php',
		// Commercial — extracted from tests/partner-plugins/ when
		// present; silently skipped (like the rest) when not.
		'sitepress-multilingual-cms/sitepress.php',
		// WPML add-on: must load AFTER sitepress.
		'wpml-media-translation/plugin.php',
		// Commercial (WPBakery) — tests/partner-plugins/js_composer.zip.
		'js_composer/js_composer.php',
		// Commercial (Breakdance) — tests/partner-plugins/breakdance-*.zip.
		'breakdance/plugin.php',
	);

	/**
	 * The partner THEME (Bricks, commercial): bin/test.sh --compat extracts
	 * tests/partner-themes/bricks*.zip into the themes dir. When it is there
	 * it becomes the active theme for the whole compat run (one theme per
	 * WordPress load), so the Bricks compat tests run against the real
	 * theme; without the zip WordPress keeps its default theme and those
	 * tests self-skip. WP_CONTENT_DIR is defined by the time WP core reads
	 * these options.
	 */
	$emr_partner_theme        = 'bricks';
	$emr_partner_theme_filter = static function ( $value ) use ( $emr_partner_theme ) {
		return file_exists( WP_CONTENT_DIR . '/themes/' . $emr_partner_theme . '/style.css' ) ? $emr_partner_theme : $value;
	};
	tests_add_filter( 'pre_option_template', $emr_partner_theme_filter );
	tests_add_filter( 'pre_option_stylesheet', $emr_partner_theme_filter );

	tests_add_filter(
		'pre_option_active_plugins',
		function () use ( $emr_partner_plugins ) {
			$active = array();
			foreach ( $emr_partner_plugins as $partner ) {
				if ( file_exists( WP_PLUGIN_DIR . '/' . $partner ) ) {
					$active[] = $partner;
				}
			}
			return $active;
		}
	);

	/**
	 * Hide E_DEPRECATED raised from inside the PARTNER plugins' own code.
	 *
	 * On PHP 8.5 a single Breakdance loop (plugin/icons/functions.php,
	 * fgetcsv() without the new $escape argument) emits thousands of
	 * identical deprecations while its icon CSV is read at load time,
	 * enough to bury a real problem. Their code cannot be fixed from here,
	 * and the alternative (error_reporting without E_DEPRECATED) would also
	 * hide EMR's own deprecations.
	 *
	 * Narrow on three axes: only E_DEPRECATED, only when the raising FILE
	 * sits inside one of the partner plugin directories above, and only
	 * under EMR_PARTNER_PLUGINS. EMR's own deprecations, and every other
	 * error level from anywhere, fall through to normal handling.
	 *
	 * These all fire while the plugins load, before PHPUnit installs its
	 * own error handler, so this never competes with PHPUnit's reporting.
	 */
	$emr_partner_dirs = array( '/themes/' . $emr_partner_theme . '/' );
	foreach ( $emr_partner_plugins as $emr_partner_plugin ) {
		$emr_partner_dirs[] = '/plugins/' . strtok( $emr_partner_plugin, '/' ) . '/';
	}
	unset( $emr_partner_plugin );

	set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler
		static function ( $errno, $errstr, $errfile = '' ) use ( $emr_partner_dirs ) {
			if ( E_DEPRECATED === $errno ) {
				foreach ( $emr_partner_dirs as $emr_partner_dir ) {
					if ( false !== strpos( (string) $errfile, $emr_partner_dir ) ) {
						return true; // Handled: swallow it.
					}
				}
			}
			return false; // Everything else: normal PHP handling.
		},
		E_ALL
	);

	/**
	 * Silence _doing_it_wrong() notices raised by the PARTNER plugins only.
	 *
	 * Several load their textdomain before `init` (WooCommerce, Yoast and
	 * others) and WooCommerce runs a user query before `plugins_loaded`.
	 * None is actionable from this repo, and they fire on every compat run
	 * during WP bootstrap.
	 *
	 * Registered HERE, before the WP test bootstrap loads, because the
	 * notices are emitted while the partner plugins load — a filter added
	 * after WP is up (e.g. in tests/Integration/bootstrap.php) runs far
	 * too late to catch them.
	 *
	 * Deliberately narrow: the textdomain notice is only suppressed for a
	 * known partner domain, so if EMR itself ever loads
	 * 'enable-media-replace' too early the notice still surfaces.
	 * Gated on EMR_PARTNER_PLUGINS, so unit and plain integration runs
	 * are completely unaffected.
	 */
	tests_add_filter(
		'doing_it_wrong_trigger_error',
		static function ( $trigger, $function_name, $message = '' ) {
			if ( 'WP_User_Query::query' === $function_name ) {
				return false;
			}
			if ( '_load_textdomain_just_in_time' === $function_name ) {
				$partner_domains = array(
					'elementor',
					'wordpress-seo',
					'polylang',
					'woocommerce',
					'amazon-s3-and-cloudfront',
					'siteorigin-panels',
					'smart-slider-3',
					'nextend',
					'fl-builder',
					'shortpixel-image-optimiser',
					'envira-gallery',
					'envira-gallery-lite',
					'sitepress',
					'wpml-media-translation',
					'js_composer',
					'breakdance',
					'bricks',
				);
				foreach ( $partner_domains as $partner_domain ) {
					if ( false !== strpos( (string) $message, '<code>' . $partner_domain . '</code>' ) ) {
						return false;
					}
				}
			}
			return $trigger;
		},
		10,
		3
	);
}

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

/**
 * Silence ONLY PHP 8.5 deprecations that cannot be fixed while retaining
 * PHP 7.4 back-compat (per the plugin header). Everything else — real
 * warnings, deprecations tied to production bugs, test-fixture races —
 * stays visible so the symptoms visibly disappear when the
 * corresponding fix lands.
 *
 * Installed AFTER the WP test bootstrap so we sit on top of any handler
 * WordPress registers, and chain-delegate to it for everything else via
 * `$previous`. Zero effect on PHP 7.4 / 8.3 — none of these deprecations
 * fire there.
 *
 * What we silence and why (both are structurally unfixable in this repo):
 *
 *   - `Reflection[Method|Property]::setAccessible()` (deprecated 8.5,
 *     no-op since 8.1). Removing the calls would break the test suite on
 *     PHP 7.4 where they are still required (the singleton resets in the
 *     integration helpers use them).
 *
 *   - `SplObjectStorage::attach()` / `SplObjectStorage::contains()`
 *     (deprecated 8.5) — emitted from `vendor/sebastian/recursion-context/`,
 *     a PHPUnit transitive dependency. Vendor code; upstream must fix.
 *
 * Side effect that this handler has to compensate for: PHPUnit 9 only
 * installs its own error handler when none is registered yet
 * (`PHPUnit\Util\ErrorHandler::register()` backs off otherwise). With a
 * bootstrap-level handler in place, `convertNoticesToExceptions`,
 * `convertWarningsToExceptions` and `convertErrorsToExceptions` from
 * phpunit.xml.dist would silently do nothing, and a notice raised by
 * production code would print and leave the test green. So this handler
 * performs the same conversion itself: notices, warnings and user errors
 * that are not suppressed with `@` (checked through error_reporting())
 * become the matching `PHPUnit\Framework\Error\*` exception, exactly what
 * PHPUnit's handler throws. Deprecations are left alone, as PHPUnit's
 * default `convertDeprecationsToExceptions="false"` would.
 */
$_emr_previous_error_handler = null;
$_emr_previous_error_handler = set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) use ( &$_emr_previous_error_handler ) {
		static $silenced_deprecations = array(
			'ReflectionMethod::setAccessible()',
			'ReflectionProperty::setAccessible()',
			'SplObjectStorage::attach()',
			'SplObjectStorage::contains()',
		);
		if ( E_DEPRECATED === $errno ) {
			foreach ( $silenced_deprecations as $needle ) {
				if ( false !== strpos( $errstr, $needle ) ) {
					return true;
				}
			}
			// Deprecations raised from WordPress core itself (an older core
			// such as 5.9 on PHP 8.x is not clean) are outside this repo and
			// would otherwise print into AJAX responses and break their JSON.
			// Swallowed only when the raising file lives under the core dir;
			// the plugin's own deprecations stay visible.
			if ( defined( 'ABSPATH' ) && 0 === strpos( (string) $errfile, ABSPATH ) ) {
				return true;
			}
		}
		if ( $_emr_previous_error_handler ) {
			$handled = ( $_emr_previous_error_handler )( $errno, $errstr, $errfile, $errline );
			if ( true === $handled ) {
				return true;
			}
		}
		if ( ! ( error_reporting() & $errno ) ) {
			return false; // Suppressed with @ (or outside error_reporting): PHP default.
		}
		switch ( $errno ) {
			case E_NOTICE:
			case E_USER_NOTICE:
			case 2048: // E_STRICT; the constant itself is deprecated since PHP 8.4.
				throw new \PHPUnit\Framework\Error\Notice( $errstr, $errno, $errfile, $errline );
			case E_WARNING:
			case E_USER_WARNING:
				throw new \PHPUnit\Framework\Error\Warning( $errstr, $errno, $errfile, $errline );
			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				return false; // Printed, not fatal: PHPUnit's own default for deprecations.
			default:
				throw new \PHPUnit\Framework\Error\Error( $errstr, $errno, $errfile, $errline );
		}
	}
);
