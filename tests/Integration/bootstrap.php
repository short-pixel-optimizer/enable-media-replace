<?php
/**
 * PHPUnit bootstrap for the INTEGRATION-config suites
 * (phpunit-integration.xml: Integration, IntegrationIsolated, Smoke,
 * Compat, Multisite).
 *
 * Reuses the unit-test bootstrap (WP test lib, plugin load, partner-plugin
 * activation list, PHP 8.5 deprecation handler) and adds the
 * integration-only pieces on top: the ShortPixel API mock and the shared
 * integration base classes.
 *
 * The integration suites run the REAL replace and remove-background flows
 * against the WordPress test install; only outbound HTTP to
 * api.shortpixel.com is intercepted (pre_http_request) — see MockEmrApi.
 * Every other outbound request is refused by EMR_IntegrationTestCase and
 * EMR_AjaxTestCase.
 *
 * The Multisite suite enters through tests/Multisite/bootstrap.php, which
 * sets the multisite switches and then requires this file.
 *
 * @package Enable_Media_Replace
 */

require dirname( __DIR__ ) . '/bootstrap.php';

/**
 * Compat runs: fire each partner plugin's activation hook once so their
 * installers create the tables they need (WooCommerce's wc_* tables,
 * Smart Slider's and Envira's own data). The WP test install never goes
 * through a real activation, and DDL auto-commits in MySQL, so this runs
 * here — before the first test transaction — and survives per-test
 * rollbacks.
 */
if ( '1' === getenv( 'EMR_PARTNER_PLUGINS' ) ) {
	/**
	 * Breakdance's activation is NOT idempotent, and activation is re-run
	 * on every suite run against a database that persists between runs.
	 *
	 * Breakdance\Setup\init_db_tables issues
	 * `ALTER TABLE {prefix}breakdance_icons CHANGE COLUMN id id SERIAL`.
	 * In MySQL, SERIAL expands to
	 * `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE`, and that UNIQUE
	 * adds a NEW index each time it is applied — MySQL auto-names them
	 * id_2, id_3, ... So the table gains one index per local test run
	 * until it hits MySQL's hard limit, after which every run prints
	 * "Too many keys specified; max 64 keys allowed" twice (once for the
	 * ALTER, once for the follow-up ADD FULLTEXT(name), which can then
	 * never be created).
	 *
	 * CI never sees this because its database is new every run; only
	 * long-lived local databases accumulate. Dropping the table first
	 * makes the activation deterministic — Breakdance recreates it and
	 * re-imports its icon CSV, which is cheap and self-healing.
	 */
	$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS `' . $GLOBALS['wpdb']->prefix . 'breakdance_icons`' ); // phpcs:ignore WordPress.DB

	/**
	 * Some activation hooks call home: WPBakery's deletes the update_plugins
	 * transient and runs wp_update_plugins(), a real request to
	 * api.wordpress.org. When that request fails, core raises a warning,
	 * the error handler of tests/bootstrap.php (already installed here)
	 * throws, and the whole run ends before the first test. The per-test
	 * baseline (EMR_IntegrationHelpers::emrAnswerWordPressOrgUpdateChecks)
	 * runs too late for this, so answer api.wordpress.org with an empty
	 * success for the lifetime of the process. Every other host is still
	 * refused inside tests by the fail-fast filter.
	 */
	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) {
			if ( false !== $preempt || false === strpos( (string) $url, 'api.wordpress.org' ) ) {
				return $preempt;
			}
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		},
		10,
		3
	);

	foreach ( wp_get_active_and_valid_plugins() as $_emr_partner_file ) {
		do_action( 'activate_' . plugin_basename( $_emr_partner_file ) );
	}
	unset( $_emr_partner_file );

	/**
	 * Force WP Offload Media's two custom tables into existence up front.
	 *
	 * as3cf creates them lazily on first use, guarded by a per-class static
	 * ("checked_table_exists") that is set BEFORE the install runs. In the
	 * test process something marks the files table as already checked, so
	 * the lazy installer never fires and every Media_Library_Item
	 * construction emits "Table 'wptests_as3cf_files' doesn't exist", each
	 * with a full backtrace and an HTML error div. Tests can still pass,
	 * because a missing file row is indistinguishable from "not offloaded",
	 * which is why the noise is easy to miss.
	 *
	 * init_cache() clears that static, so the following get_table_name()
	 * calls really do install. Done here, before the first test
	 * transaction, so the implicit COMMIT of CREATE TABLE cannot leak into
	 * (or be rolled back with) a test.
	 */
	if ( class_exists( '\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item' ) ) {
		\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item::init_cache();
		\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item::get_table_name();
	}
	if ( class_exists( '\DeliciousBrains\WP_Offload_Media\Items\File' ) ) {
		\DeliciousBrains\WP_Offload_Media\Items\File::init_cache();
		\DeliciousBrains\WP_Offload_Media\Items\File::get_table_name();
	}

	// NOTE: the partner-plugin _doing_it_wrong() suppression lives in
	// tests/bootstrap.php, registered BEFORE the WP test bootstrap runs —
	// those notices fire while the partner plugins load, which is long
	// before this file's code executes.
}

/**
 * Pin the image editor to GD so results don't depend on the host's Imagick.
 *
 * The thumbnails WordPress generates on upload and on replace (and, since
 * WP 6.7, whether a HEIC upload is converted to JPEG on the way in) depend
 * on which image editor is active and what it can read. The local harness
 * image has GD only, while GitHub's runners also get php-imagick from
 * setup-php; pinning GD everywhere keeps file names, sizes and generated
 * sub-sizes identical between the two.
 *
 * Registered before the first test, so WP_UnitTestCase's hook backup keeps
 * it for every test.
 */
add_filter(
	'wp_image_editors',
	static function () {
		return array( 'WP_Image_Editor_GD' );
	}
);

require_once __DIR__ . '/Helpers/MockEmrApi.php';
require_once __DIR__ . '/Helpers/EMR_IntegrationHelpers.php';
require_once __DIR__ . '/Helpers/EMR_IntegrationTestCase.php';
require_once __DIR__ . '/Helpers/EMR_AjaxTestCase.php';
