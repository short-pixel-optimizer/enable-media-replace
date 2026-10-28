<?php
/**
 * Tests for the upsell AJAX handlers in classes/external/upsell_installer.php:
 * emr_plugin_install() on `wp_ajax_emr_plugin_install` and emr_activate()
 * on `wp_ajax_emr_plugin_activate`.
 *
 * The handlers end in wp_send_json() / wp_die() (turned into exceptions by
 * WP_Ajax_UnitTestCase) or in a bare `die` after an echo, which nothing can
 * intercept and which would end the PHPUnit process. The bare-die paths are
 * avoided: emr_activate() is stopped right after the activation by an
 * `activated_plugin` callback that throws, so its decision (which plugin
 * gets activated) is observable without reaching its `echo ...; die;`.
 * The "missing slug prints true" and "activation error" outputs of
 * emr_activate() and the credentials-form output of emr_plugin_install()
 * belong to the integration suite.
 *
 * Network: downloads.wordpress.org is answered by this class with a small
 * plugin zip built with ZipArchive (folder `emr-test-dummy/` holding
 * `emr-test-dummy.php` with a plugin header). download_url() streams the
 * response to `$args['filename']`, so the filter writes the zip to that
 * path and returns a 200 response. A fail-fast `pre_http_request` filter
 * at priority 9999 records anything else and tear_down() asserts nothing
 * was refused. The `upgrader_process_complete` and `admin_init` chains are
 * dropped (core's update checks would otherwise call api.wordpress.org);
 * WP_UnitTestCase restores the hooks.
 *
 * Filesystem: the `filesystem_method` filter forces the direct method, so
 * request_filesystem_credentials() never prints the credentials form (no
 * FS_METHOD constant is defined). Plugin folders the tests create under
 * WP_PLUGIN_DIR are removed in tear_down(), and plugins they activate are
 * deactivated; a test that would overwrite an existing plugin folder skips.
 *
 * @package Enable_Media_Replace
 */

/**
 * Thrown from `activated_plugin` to stop emr_activate() before its bare die.
 */
class EMR_Test_Activation_Stop extends Exception {
	/** @var string */
	public $plugin;

	public function __construct( $plugin ) {
		parent::__construct( 'Activation intercepted: ' . $plugin );
		$this->plugin = $plugin;
	}
}

class UpsellInstallerTest extends WP_Ajax_UnitTestCase {

	const DUMMY = 'emr-test-dummy';

	private $saved_post;

	/**
	 * Plugin folders created under WP_PLUGIN_DIR, removed in tear_down().
	 *
	 * @var string[]
	 */
	private $plugin_dirs = array();

	/**
	 * Plugin basenames to deactivate in tear_down().
	 *
	 * @var string[]
	 */
	private $activated = array();

	/**
	 * downloads.wordpress.org requests answered.
	 *
	 * @var string[]
	 */
	private $downloads = array();

	/**
	 * @var string[]
	 */
	private $blocked = array();

	public function set_up() {
		parent::set_up();
		$this->saved_post = $_POST;
		remove_all_actions( 'admin_init' );
		remove_all_actions( 'upgrader_process_complete' );
		$this->downloads = array();
		$this->blocked   = array();
		add_filter( 'pre_http_request', array( $this, 'serve_download' ), 10, 3 );
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );
		add_filter( 'filesystem_method', array( $this, 'direct_method' ) );
		$this->_setRole( 'administrator' );
		$_POST = array();
		$this->assertFalse( file_exists( WP_PLUGIN_DIR . '/' . self::DUMMY ), 'Sentinel: no dummy plugin left over.' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'serve_download' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999 );
		remove_filter( 'filesystem_method', array( $this, 'direct_method' ) );
		if ( $this->activated ) {
			deactivate_plugins( $this->activated, true );
		}
		$this->activated = array();
		foreach ( $this->plugin_dirs as $dir ) {
			$this->remove_tree( $dir );
		}
		$this->plugin_dirs = array();
		wp_clean_plugins_cache( false );
		$_POST = $this->saved_post;

		$blocked       = $this->blocked;
		$this->blocked = array();
		parent::tear_down();
		$this->assertSame( array(), $blocked, 'A request reached the fail-fast filter.' );
	}

	public function direct_method() {
		return 'direct';
	}

	/**
	 * Answers downloads.wordpress.org with the dummy plugin zip.
	 */
	public function serve_download( $preempt, $args, $url ) {
		if ( false !== $preempt || 'downloads.wordpress.org' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $preempt;
		}
		if ( '.sig' === substr( $url, -4 ) ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 404, 'message' => 'Not Found' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		$this->downloads[] = $url;
		$zip               = $this->build_zip();
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			copy( $zip, $args['filename'] );
			$body = '';
		} else {
			$body = file_get_contents( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		unlink( $zip );
		return array(
			'headers'  => array( 'content-type' => 'application/zip' ),
			'body'     => $body,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => empty( $args['stream'] ) ? null : $args['filename'],
		);
	}

	/**
	 * Refuses and records every HTTP request nothing else answered.
	 */
	public function fail_fast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->blocked[] = $url;
		return new WP_Error( 'http_blocked', 'Blocked unmocked request to ' . $url );
	}

	private function plugin_source( $name ) {
		return "<?php\n/**\n * Plugin Name: {$name}\n * Description: Test fixture of the Enable Media Replace unit suite.\n * Version: 1.0.0\n */\n";
	}

	private function build_zip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is needed to build the dummy plugin package.' );
		}
		$path = wp_tempnam( 'emr-dummy-zip' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addEmptyDir( self::DUMMY );
		$zip->addFromString( self::DUMMY . '/' . self::DUMMY . '.php', $this->plugin_source( 'EMR Test Dummy' ) );
		$zip->close();
		return $path;
	}

	/**
	 * Writes a dummy plugin at "<dir>/<file>" under WP_PLUGIN_DIR.
	 */
	private function install_dummy_at( $basename ) {
		$dir = WP_PLUGIN_DIR . '/' . dirname( $basename );
		if ( file_exists( $dir ) ) {
			$this->markTestSkipped( 'The plugin folder ' . dirname( $basename ) . ' already exists in the test install; the test does not overwrite it.' );
		}
		wp_mkdir_p( $dir );
		$this->plugin_dirs[] = $dir;
		file_put_contents( WP_PLUGIN_DIR . '/' . $basename, $this->plugin_source( 'EMR Test Dummy ' . dirname( $basename ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_clean_plugins_cache( false );
		$this->assertSame( 0, validate_plugin( $basename ), 'Sentinel: WordPress sees the dummy plugin.' );
	}

	private function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->remove_tree( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Runs an endpoint; returns the output, or the exception that ended it
	 * when that is not a WPAjaxDie* one. Output buffers left open by the
	 * handler are closed and their content appended.
	 */
	private function request( $action, array $post ) {
		$_POST = $post;
		$level = ob_get_level();
		$other = null;
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			$this->_last_response .= $e->getMessage();
		} catch ( Exception $e ) {
			$other = $e;
		}
		while ( ob_get_level() > $level ) {
			$this->_last_response .= ob_get_clean();
		}
		return null === $other ? $this->_last_response : $other;
	}

	private function json( $action, array $post ) {
		$response = $this->request( $action, $post );
		$this->assertIsString( $response, 'The request ended with a response.' );
		$decoded = json_decode( $response, true );
		$this->assertIsArray( $decoded, 'The response is JSON: ' . $response );
		return $decoded;
	}

	/*
	 * Registration.
	 */

	public function test_handlers_are_registered_for_logged_in_users_only() {
		$this->assertSame( 10, has_action( 'wp_ajax_emr_plugin_install', 'emr_plugin_install' ) );
		$this->assertSame( 10, has_action( 'wp_ajax_emr_plugin_activate', 'emr_activate' ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_emr_plugin_install' ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_emr_plugin_activate' ) );
	}

	/*
	 * emr_plugin_install().
	 */

	/**
	 * @dataProvider provide_bad_install_nonces
	 */
	public function test_install_dies_on_a_bad_nonce( $nonce_action ) {
		$post = array( 'plugin' => 'spio' );
		if ( null !== $nonce_action ) {
			$post['nonce'] = wp_create_nonce( $nonce_action );
		}

		$response = $this->request( 'emr_plugin_install', $post );

		$this->assertIsString( $response );
		$this->assertNull( json_decode( $response, true ), 'No JSON: check_admin_referer() ends the request.' );
		$this->assertSame( array(), $this->downloads );
		$this->assertFalse( file_exists( WP_PLUGIN_DIR . '/' . self::DUMMY ) );
	}

	public function provide_bad_install_nonces() {
		return array(
			'missing'         => array( null ),
			'activate nonce'  => array( 'emr-plugin-activate' ),
		);
	}

	public function test_install_requires_install_plugins() {
		$this->_setRole( 'editor' );

		$response = $this->json(
			'emr_plugin_install',
			array(
				'plugin' => 'spio',
				'nonce'  => wp_create_nonce( 'emr-plugin-install' ),
			)
		);

		$this->assertSame( array( 'result' => false, 'message' => 'Install permission issue' ), $response );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_install_downloads_and_installs_the_package() {
		$this->plugin_dirs[] = WP_PLUGIN_DIR . '/' . self::DUMMY;

		$response = $this->json(
			'emr_plugin_install',
			array(
				'plugin' => 'spio',
				'nonce'  => wp_create_nonce( 'emr-plugin-install' ),
			)
		);

		$this->assertSame( array( 'success' => true, 'data' => array( 'plugin' => self::DUMMY . '/' . self::DUMMY . '.php' ) ), $response );
		$this->assertSame( array( 'https://downloads.wordpress.org/plugin/shortpixel-image-optimiser.zip' ), $this->downloads );
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . self::DUMMY . '/' . self::DUMMY . '.php' );
		$this->assertFalse( is_plugin_active( self::DUMMY . '/' . self::DUMMY . '.php' ), 'Installing does not activate.' );
	}

	/**
	 * @dataProvider provide_install_slugs
	 */
	public function test_install_maps_each_slug_to_its_download_url( $slug, $url ) {
		$this->plugin_dirs[] = WP_PLUGIN_DIR . '/' . self::DUMMY;

		$this->json(
			'emr_plugin_install',
			array(
				'plugin' => $slug,
				'nonce'  => wp_create_nonce( 'emr-plugin-install' ),
			)
		);

		$this->assertSame( array( $url ), $this->downloads );
	}

	public function provide_install_slugs() {
		return array(
			'envira' => array( 'envira', 'https://downloads.wordpress.org/plugin/envira-gallery-lite.zip' ),
			'spai'   => array( 'spai', 'https://downloads.wordpress.org/plugin/shortpixel-adaptive-images.zip' ),
			'fp'     => array( 'fp', 'https://downloads.wordpress.org/plugin/fastpixel-website-accelerator.zip' ),
		);
	}

	public function test_install_of_an_existing_folder_answers_result_false() {
		$this->install_dummy_at( self::DUMMY . '/' . self::DUMMY . '.php' );

		$response = $this->json(
			'emr_plugin_install',
			array(
				'plugin' => 'spio',
				'nonce'  => wp_create_nonce( 'emr-plugin-install' ),
			)
		);

		$this->assertFalse( $response['result'] );
		$this->assertCount( 1, $this->downloads );
	}

	/**
	 * emr_plugin_install() assigns $download_url only inside the switch, so
	 * an unknown or missing slug reads an undefined variable at
	 * `is_null($download_url)`: a warning on PHP 8 (a notice on 7.4), which
	 * this harness turns into an exception. WP_Ajax_UnitTestCase masks
	 * E_WARNING, so the test reports every level while the request runs.
	 * $download_url should be initialised to null before the switch.
	 *
	 * How to recognise the fix: no notice or warning is raised and the
	 * assertion on the caught error fails.
	 *
	 * @dataProvider provide_unknown_install_slugs
	 */
	public function test_install_reads_an_undefined_download_url_pinned_for_deferred_fix( $slug ) {
		$post = array( 'nonce' => wp_create_nonce( 'emr-plugin-install' ) );
		if ( null !== $slug ) {
			$post['plugin'] = $slug;
		}

		// WP_Ajax_UnitTestCase masks E_WARNING; report it like the rest of the suite.
		$level = error_reporting( E_ALL );
		try {
			$result = $this->request( 'emr_plugin_install', $post );
		} finally {
			error_reporting( $level );
		}

		$this->assertTrue(
			$result instanceof \PHPUnit\Framework\Error\Warning || $result instanceof \PHPUnit\Framework\Error\Notice,
			'Pinned: an undefined-variable warning or notice ends the request.'
		);
		$this->assertMatchesRegularExpression( '/Undefined variable:? \$?download_url/', $result->getMessage() );
		$this->assertSame( array(), $this->downloads );
	}

	public function provide_unknown_install_slugs() {
		return array(
			'unknown slug' => array( 'not-a-plugin' ),
			'no slug'      => array( null ),
		);
	}

	/**
	 * With the undefined-variable diagnostic ignored (as on a production
	 * site, where it is only logged), an unknown slug reaches the final
	 * `wp_send_json(array('result' => false, 'download url issue'))`: the
	 * text sits under the numeric key 0 instead of `message`, unlike the
	 * permission failure.
	 *
	 * How to recognise the fix: the response carries a `message` key and
	 * the assertSame on key 0 fails.
	 */
	public function test_install_failure_message_sits_under_key_zero_pinned_for_deferred_fix() {
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler
			static function ( $errno, $errstr ) {
				return false !== strpos( $errstr, 'download_url' );
			},
			E_WARNING | E_NOTICE
		);
		try {
			$response = $this->json(
				'emr_plugin_install',
				array(
					'plugin' => 'not-a-plugin',
					'nonce'  => wp_create_nonce( 'emr-plugin-install' ),
				)
			);
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array( 'result' => false, 0 => 'download url issue' ), $response, 'Pinned: the message has no key.' );
		$this->assertArrayNotHasKey( 'message', $response );
	}

	/*
	 * emr_activate().
	 */

	/**
	 * Hooks `activated_plugin` to stop emr_activate() after the activation.
	 */
	private function stop_after_activation() {
		$activated = &$this->activated;
		add_action(
			'activated_plugin',
			function ( $plugin ) use ( &$activated ) {
				$activated[] = $plugin;
				throw new EMR_Test_Activation_Stop( $plugin );
			}
		);
	}

	/**
	 * @dataProvider provide_bad_activate_nonces
	 */
	public function test_activate_dies_on_a_bad_nonce( $nonce_action ) {
		$this->stop_after_activation();
		$post = array( 'plugin' => 'envira' );
		if ( null !== $nonce_action ) {
			$post['nonce'] = wp_create_nonce( $nonce_action );
		}

		$response = $this->request( 'emr_plugin_activate', $post );

		$this->assertIsString( $response );
		$this->assertNull( json_decode( $response, true ) );
		$this->assertSame( array(), $this->activated );
	}

	public function provide_bad_activate_nonces() {
		return array(
			'missing'       => array( null ),
			'install nonce' => array( 'emr-plugin-install' ),
		);
	}

	public function test_activate_requires_activate_plugins() {
		$this->_setRole( 'editor' );
		$this->stop_after_activation();

		$response = $this->json(
			'emr_plugin_activate',
			array(
				'plugin' => 'envira',
				'nonce'  => wp_create_nonce( 'emr-plugin-activate' ),
			)
		);

		$this->assertSame( array( 'result' => false ), $response );
		$this->assertSame( array(), $this->activated );
	}

	public function test_activate_maps_a_known_slug_to_its_basename() {
		$basename = 'envira-gallery-lite/envira-gallery-lite.php';
		$this->install_dummy_at( $basename );
		$this->stop_after_activation();

		$result = $this->request(
			'emr_plugin_activate',
			array(
				'plugin' => 'envira',
				'nonce'  => wp_create_nonce( 'emr-plugin-activate' ),
			)
		);

		$this->assertInstanceOf( EMR_Test_Activation_Stop::class, $result );
		$this->assertSame( $basename, $result->plugin );
		$this->assertTrue( is_plugin_active( $basename ) );
	}

	/**
	 * emr_activate() maps the four upsell slugs to their basenames but
	 * passes any other value unchanged to activate_plugin(), so the endpoint
	 * activates any installed plugin whose basename is posted. A default
	 * case should set $plugin to null so only the four listed plugins can
	 * be activated here.
	 *
	 * How to recognise the fix: the dummy plugin is not activated, no
	 * activation is intercepted and the assertInstanceOf fails.
	 */
	public function test_activate_passes_an_unknown_slug_through_pinned_for_deferred_fix() {
		$basename = self::DUMMY . '/' . self::DUMMY . '.php';
		$this->install_dummy_at( $basename );
		$this->assertFalse( is_plugin_active( $basename ), 'Sentinel: the dummy starts inactive.' );
		$this->stop_after_activation();

		$result = $this->request(
			'emr_plugin_activate',
			array(
				'plugin' => $basename,
				'nonce'  => wp_create_nonce( 'emr-plugin-activate' ),
			)
		);

		$this->assertInstanceOf( EMR_Test_Activation_Stop::class, $result, 'Pinned: the posted basename is activated.' );
		$this->assertSame( $basename, $result->plugin );
		$this->assertTrue( is_plugin_active( $basename ) );
	}
}
