<?php
/**
 * Smoke tests confirming the plugin bootstraps inside the WordPress test suite.
 *
 * These are intentionally light: they verify that the toolchain (WP test
 * library, tests/bootstrap.php loading enable-media-replace.php on
 * `plugins_loaded`, the bundled module autoloader) is wired up, so that
 * `bin/test.sh --verify` proves real tests ran against a loaded plugin.
 *
 * @package Enable_Media_Replace
 */

class PluginLoadedTest extends WP_UnitTestCase {

	/** Absolute path of the plugin's main file, independent of EMR_ROOT_FILE. */
	private function pluginFile(): string {
		return dirname( __DIR__ ) . '/enable-media-replace.php';
	}

	public function test_version_constant_matches_the_plugin_header() {
		$this->assertTrue( defined( 'EMR_VERSION' ), 'EMR_VERSION must be defined once the plugin is loaded.' );

		$header = get_file_data( $this->pluginFile(), array( 'Version' => 'Version' ) );

		$this->assertNotSame( '', $header['Version'], 'The plugin header must declare a Version.' );
		$this->assertSame( $header['Version'], EMR_VERSION );
	}

	public function test_root_file_constant_points_at_the_main_plugin_file() {
		$this->assertTrue( defined( 'EMR_ROOT_FILE' ) );
		$this->assertSame( realpath( $this->pluginFile() ), realpath( EMR_ROOT_FILE ) );
	}

	public function test_emr_accessor_returns_the_plugin_singleton() {
		$this->assertTrue( function_exists( 'emr' ), 'emr() accessor should be defined once the plugin is loaded.' );

		$first  = emr();
		$second = emr();

		$this->assertInstanceOf( \EnableMediaReplace\EnableMediaReplacePlugin::class, $first );
		$this->assertSame( $first, $second, 'emr() must return the same instance on every call.' );
	}

	public function test_bundled_autoloader_resolves_module_classes() {
		$this->assertTrue( class_exists( 'EnableMediaReplace\\Replacer\\Replacer' ), 'The bundled autoloader should resolve the replacer module.' );
		$this->assertTrue( class_exists( 'EnableMediaReplace\\FileSystem\\Controller\\FileSystemController' ), 'The bundled autoloader should resolve the filesystem module.' );
	}

	public function test_runtime_is_hooked_on_init() {
		$this->assertSame( 10, has_action( 'init', array( emr(), 'runtime' ) ), 'EnableMediaReplacePlugin::runtime() should be hooked on init at the default priority.' );
	}

	public function test_image_fixture_is_present() {
		$fixture = dirname( __FILE__ ) . '/fixtures/test-image.jpg';

		$this->assertFileExists( $fixture );
		$this->assertGreaterThan( 0, filesize( $fixture ) );
	}
}
