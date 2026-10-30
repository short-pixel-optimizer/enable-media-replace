<?php
/**
 * Tests for the plugin root file enable-media-replace.php: the constants,
 * the emr() accessor, the require list and the deactivation / uninstall
 * hook registration.
 *
 * The root file runs once, when tests/bootstrap.php loads it on
 * `plugins_loaded`, so these tests assert what that load left behind.
 * EMR_CAPABILITY can only be tested in its default shape (false) here:
 * the constant is defined before any test runs.
 *
 * The root file also assigns $plugin_path and $emr_loader at file scope.
 * WordPress includes active plugins from the global scope of
 * wp-settings.php, so in production those variables leak into the global
 * namespace; the test bootstrap requires
 * the file from inside a function, where it stays local, so the leak cannot
 * be observed here.
 *
 * @package Enable_Media_Replace
 */

class RootFileTest extends WP_UnitTestCase {

	/** Absolute path of the root file, independent of EMR_ROOT_FILE. */
	private function root_file() {
		return dirname( __DIR__ ) . '/enable-media-replace.php';
	}

	public function test_version_constant_is_a_dotted_version_string() {
		$this->assertIsString( EMR_VERSION );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', EMR_VERSION );
	}

	public function test_root_file_constant_is_the_main_plugin_file() {
		$this->assertIsString( EMR_ROOT_FILE );
		$this->assertSame( 'enable-media-replace.php', basename( EMR_ROOT_FILE ) );
		$this->assertFileExists( EMR_ROOT_FILE );
	}

	public function test_capability_constant_defaults_to_false() {
		$this->assertTrue( defined( 'EMR_CAPABILITY' ) );
		$this->assertFalse( EMR_CAPABILITY );
	}

	/**
	 * The 19 legacy classes the root file requires explicitly (their file
	 * names do not match PSR-4), checked without triggering any autoloader.
	 * Classes under classes/ whose path matches their name are autoloaded
	 * instead, see test_root_file_registers_a_psr4_loader_for_classes().
	 */
	public function test_every_required_class_is_loaded() {
		$classes = array(
			'EnableMediaReplace\UIHelper',
			'EnableMediaReplace\Helper\EnvHelper',
			'EnableMediaReplace\emrCache',
			'EnableMediaReplace\Api',
			'EnableMediaReplace\ApiKeyManager',
			'EnableMediaReplace\Ajax',
			'EnableMediaReplace\EnableMediaReplacePlugin',
			'EnableMediaReplace\InstallHelper',
			'EnableMediaReplace\Controller\ReplaceController',
			'EnableMediaReplace\Controller\RemoteNoticeController',
			'EnableMediaReplace\ViewController',
			'EnableMediaReplace\ViewController\UploadViewController',
			'EnableMediaReplace\ViewController\ReplaceViewController',
			'EnableMediaReplace\ViewController\RemoveBackGroundViewController',
			'EnableMediaReplace\Externals',
			'EnableMediaReplace\Externals\Elementor',
			'EnableMediaReplace\Externals\WpBakery',
			'EnableMediaReplace\Externals\SiteOrigin',
			'EnableMediaReplace\Externals\WPOffload',
		);
		$this->assertCount( 19, $classes );
		foreach ( $classes as $class ) {
			$this->assertTrue( class_exists( $class, false ), $class . ' is not loaded.' );
		}
	}

	public function test_global_functions_from_the_required_files_exist() {
		foreach ( array( 'emr', 'emr_plugin_install', 'emr_activate', 'wp_delete_attachment_files', 'wp_delete_file_from_directory', 'wp_get_upload_dir' ) as $function ) {
			$this->assertTrue( function_exists( $function ), $function . '() is not defined.' );
		}
	}

	public function test_bundled_module_autoloader_resolves_the_logger_and_notices() {
		$this->assertTrue( class_exists( 'EnableMediaReplace\ShortPixelLogger\ShortPixelLogger' ) );
		$this->assertTrue( class_exists( 'EnableMediaReplace\Notices\NoticeController' ) );
		$this->assertTrue( class_exists( 'EnableMediaReplace\ShortQ\ShortQ' ) );
	}

	/**
	 * The root file creates a second PackageLoader with classes/plugin.json,
	 * so an spl autoloader for the EnableMediaReplace prefix rooted at the
	 * plugin directory exists next to the module loaders (which are rooted
	 * at build/shortpixel).
	 */
	public function test_root_file_registers_a_psr4_loader_for_classes() {
		$root  = dirname( __DIR__ );
		$found = 0;
		foreach ( spl_autoload_functions() as $function ) {
			if ( ! $function instanceof Closure ) {
				continue;
			}
			$vars = ( new ReflectionFunction( $function ) )->getStaticVariables();
			if ( isset( $vars['namespace'], $vars['dir'], $vars['classpaths'] ) && 'EnableMediaReplace' === $vars['namespace'] && $root === $vars['dir'] ) {
				$found++;
				$this->assertSame( array( 'classes' ), $vars['classpaths'] );
			}
		}
		$this->assertSame( 1, $found, 'Exactly one classes/ autoloader is registered.' );
	}

	public function test_emr_returns_the_instance_held_by_the_class() {
		$prop = new ReflectionProperty( \EnableMediaReplace\EnableMediaReplacePlugin::class, 'instance' );
		$prop->setAccessible( true );

		$this->assertSame( $prop->getValue(), emr() );
		$this->assertSame( \EnableMediaReplace\EnableMediaReplacePlugin::get(), emr() );
	}

	public function test_admin_init_is_hooked_on_admin_init() {
		$this->assertSame( 10, has_action( 'admin_init', array( emr(), 'adminInit' ) ) );
	}

	/**
	 * register_deactivation_hook() adds an action on `deactivate_<basename>`.
	 * The callback was registered with a leading backslash, so has_action()
	 * must be asked with the same string.
	 */
	public function test_deactivation_hook_points_at_install_helper() {
		$hook = 'deactivate_' . plugin_basename( EMR_ROOT_FILE );

		$this->assertSame( 10, has_action( $hook, array( '\EnableMediaReplace\InstallHelper', 'deactivatePlugin' ) ) );
		$this->assertTrue( is_callable( array( '\EnableMediaReplace\InstallHelper', 'deactivatePlugin' ) ) );
	}

	/**
	 * register_uninstall_hook() stores the callable in the `uninstall_plugins`
	 * option under the plugin basename. The method name `uninstallPlugin`
	 * differs in case from the declared `unInstallPlugin`; it still resolves.
	 */
	public function test_uninstall_hook_is_stored_in_the_uninstall_plugins_option() {
		$stored   = (array) get_option( 'uninstall_plugins' );
		$basename = plugin_basename( EMR_ROOT_FILE );

		$this->assertArrayHasKey( $basename, $stored );
		$this->assertSame( array( '\EnableMediaReplace\InstallHelper', 'uninstallPlugin' ), $stored[ $basename ] );
		$this->assertTrue( is_callable( $stored[ $basename ] ) );
	}

	public function test_root_file_is_the_file_the_bootstrap_loaded() {
		$this->assertSame( realpath( $this->root_file() ), realpath( EMR_ROOT_FILE ) );
		$header = get_file_data( $this->root_file(), array( 'TextDomain' => 'Text Domain', 'RequiresPHP' => 'Requires PHP' ) );
		$this->assertSame( 'enable-media-replace', $header['TextDomain'] );
		$this->assertSame( '7.4', $header['RequiresPHP'] );
	}
}
