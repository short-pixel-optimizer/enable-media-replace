<?php
/**
 * Cross-plugin compatibility: Envira Gallery Lite and the upsell installer.
 *
 * Runs with the REAL envira-gallery-lite plugin active (bin/test.sh
 * --compat downloads + activates it). Envira is one of the four slugs the
 * upsell installer knows (classes/external/upsell_installer.php), but
 * views/upsell.php computes its installed/active state and renders no
 * block for it. SPIO is also active in a --compat run, so its offer is
 * skipped too; the FastPixel offer and the commented-out Adaptive Images
 * offer are the ones that still render.
 *
 * AJAX: `emr_plugin_activate` for a plugin that is already active reaches
 * activate_plugin(), which returns null without firing any activation
 * hook, and then ends in `echo json_encode( true ); die;`. That bare die
 * cannot run in-process, so the request is stopped where activate_plugin()
 * reads `active_plugins` (the last observable step), and the decision
 * activate_plugin() makes for an active plugin is checked by calling it
 * directly. `emr_plugin_install` for `envira` downloads a package
 * (answered here with a dummy zip holding an `envira-gallery-lite/` folder,
 * no network) and Plugin_Upgrader refuses to overwrite the real folder.
 *
 * The partner-ABSENT paths (slug mapping with dummy plugins, nonces,
 * capabilities, the unknown-slug pins) are covered by
 * tests/Controller/test-UpsellInstaller.php and
 * tests/Integration/test-AjaxEndpoints.php.
 *
 * Snapshot/restore: $_POST/$_GET/$_REQUEST, the current user, the global
 * script and style registries, hooks (WP_UnitTestCase), the plugin
 * folder (checked unchanged), the upgrade working folder.
 *
 * @package Enable_Media_Replace
 */

/**
 * Thrown from a hook to stop a handler right before a bare die.
 */
class EMR_Test_EnviraStop extends Exception {

	/** @var array Arguments of the frame that threw. */
	public $frameArgs;

	public function __construct( $message, array $args ) {
		parent::__construct( $message );
		$this->frameArgs = $args;
	}
}

class CompatEnviraTest extends EMR_AjaxTestCase {

	const ENVIRA_FILE   = 'envira-gallery-lite/envira-gallery-lite.php';
	const ENVIRA_FOLDER = 'envira-gallery-lite';

	/** @var array */
	private $savedRequest = array();

	/** @var int */
	private $savedUser = 0;

	/** @var string[] downloads.wordpress.org URLs served. */
	private $downloads = array();

	/** @var Exception|null Non-ajax exception that ended the last request. */
	private $otherException = null;

	/** @var array|null Script and style registries before the test. */
	private $savedRegistries = null;

	public function set_up() {
		if ( ! defined( 'ENVIRA_LITE_VERSION' ) ) {
			$this->markTestSkipped( 'Envira Gallery Lite is not loaded — run via bin/test.sh --compat.' );
		}
		parent::set_up();
		$this->savedRequest = array( $_POST, $_GET, $_REQUEST );
		$this->savedUser    = get_current_user_id();
		$_POST              = array();
		$_GET               = array();
		$_REQUEST           = array();
		$this->downloads    = array();

		// The installer would trigger core's update checks here.
		remove_all_actions( 'upgrader_process_complete' );
		add_filter( 'pre_http_request', array( $this, 'serveDownload' ), 10, 3 );
		add_filter( 'filesystem_method', array( $this, 'directMethod' ) );

		$this->_setRole( 'administrator' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'serveDownload' ), 10 );
		remove_filter( 'filesystem_method', array( $this, 'directMethod' ) );
		if ( null !== $this->savedRegistries ) {
			$GLOBALS['wp_scripts'] = $this->savedRegistries[0];
			$GLOBALS['wp_styles']  = $this->savedRegistries[1];
			$this->savedRegistries = null;
		}
		list( $_POST, $_GET, $_REQUEST ) = $this->savedRequest;
		wp_set_current_user( $this->savedUser );
		wp_clean_plugins_cache( false );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Plumbing.
	// ---------------------------------------------------------------------

	public function directMethod() {
		return 'direct';
	}

	/**
	 * Answers downloads.wordpress.org with a dummy package holding an
	 * `envira-gallery-lite/` folder. Every other host falls through to the
	 * base class, which refuses it.
	 */
	public function serveDownload( $preempt, $args, $url ) {
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
		$zip               = $this->buildZip();
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			copy( $zip, $args['filename'] );
			$body = '';
		} else {
			$body = file_get_contents( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		unlink( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array(
			'headers'  => array( 'content-type' => 'application/zip' ),
			'body'     => $body,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => empty( $args['stream'] ) ? null : $args['filename'],
		);
	}

	private function buildZip() {
		$this->assertTrue( class_exists( 'ZipArchive' ), 'Precondition: ZipArchive builds the dummy package.' );
		$path = wp_tempnam( 'emr-envira-zip' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addEmptyDir( self::ENVIRA_FOLDER );
		$zip->addFromString( self::ENVIRA_FILE, "<?php\n/**\n * Plugin Name: EMR Compat Dummy Envira\n * Version: 0.0.1\n */\n" );
		$zip->close();
		return $path;
	}

	/**
	 * Posts $post to $action through the admin-ajax dispatch and returns the
	 * decoded JSON (object), or null. A non-ajax exception that ended the
	 * request is kept in $otherException.
	 */
	private function post( $action, array $post ) {
		$_POST                = $post;
		$this->_last_response = '';
		$this->otherException = null;
		$level                = ob_get_level();
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		} catch ( Exception $e ) {
			$this->otherException = $e;
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$decoded = json_decode( $this->_last_response );
		return is_object( $decoded ) ? $decoded : null;
	}

	/** Renders views/upsell.php for the current user and returns the markup. */
	private function renderUpsell(): string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		ob_start();
		include dirname( __DIR__, 2 ) . '/views/upsell.php';
		return (string) ob_get_clean();
	}

	/** md5 of every file in the real Envira folder, keyed by relative path. */
	private function folderFingerprint() {
		$dir   = WP_PLUGIN_DIR . '/' . self::ENVIRA_FOLDER;
		$files = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$files[ substr( (string) $file, strlen( $dir ) ) ] = md5_file( (string) $file );
		}
		ksort( $files );
		return $files;
	}

	// ---------------------------------------------------------------------
	// Coexistence.
	// ---------------------------------------------------------------------

	public function test_envira_lite_loads_alongside_emr() {
		$this->assertIsString( ENVIRA_LITE_VERSION );
		$this->assertSame( 'envira-gallery-lite', ENVIRA_LITE_SLUG );
		$this->assertTrue( class_exists( 'Envira_Gallery_Lite' ), 'The Envira main class is loaded.' );
		$this->assertTrue( is_plugin_active( self::ENVIRA_FILE ), 'Envira is an active plugin.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	// ---------------------------------------------------------------------
	// Upsell view.
	// ---------------------------------------------------------------------

	/**
	 * views/upsell.php has no Envira block, active or not: no Envira text,
	 * no install or activate button for the `envira` slug. With SPIO active
	 * its block is skipped too, while the FastPixel offer and the
	 * Adaptive Images markup (inside an HTML comment) still render.
	 */
	public function test_upsell_renders_no_envira_block_while_the_other_offers_render() {
		$this->assertTrue( current_user_can( 'install_plugins' ), 'Precondition: the administrator may install plugins.' );
		$this->assertTrue( is_plugin_active( 'shortpixel-image-optimiser/wp-shortpixel.php' ), 'Precondition: SPIO is active in a --compat run.' );

		$html = $this->renderUpsell();

		$this->assertStringContainsString( "<section class='upsell-wrapper'>", $html, 'The upsell box renders.' );
		$this->assertStringNotContainsString( 'data-plugin="envira"', $html, 'No install or activate button for Envira.' );
		$this->assertStringNotContainsString( "data-plugin='envira'", $html );
		$this->assertDoesNotMatchRegularExpression( '/envira/i', $html, 'Envira is not mentioned at all.' );
		$this->assertStringNotContainsString( 'shortpixel-offer spio', $html, 'The SPIO offer is skipped (SPIO is active).' );
		$this->assertStringContainsString( "<div class='shortpixel-offer fp'>", $html, 'The FastPixel offer renders.' );
		$this->assertStringContainsString( 'data-plugin="fp"', $html );
		$this->assertStringContainsString( 'data-plugin="spai"', $html, 'The Adaptive Images markup is sent (commented out).' );
		$this->assertStringContainsString( "id='upsell-nonce' value=\"" . wp_create_nonce( 'emr-plugin-install' ) . '"', $html );
		$this->assertStringContainsString( "id='upsell-nonce-activate' value=\"" . wp_create_nonce( 'emr-plugin-activate' ) . '"', $html );
	}

	/**
	 * The `emr_upsell` script js/upsell.js drives the buttons; it is
	 * registered with its admin-ajax URL whatever plugins are active.
	 */
	public function test_admin_scripts_registers_the_upsell_script_with_its_ajax_url() {
		$this->savedRegistries = array(
			isset( $GLOBALS['wp_scripts'] ) ? $GLOBALS['wp_scripts'] : null,
			isset( $GLOBALS['wp_styles'] ) ? $GLOBALS['wp_styles'] : null,
		);
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		emr()->admin_scripts();

		$script = wp_scripts()->query( 'emr_upsell', 'registered' );
		$this->assertInstanceOf( '_WP_Dependency', $script );
		$this->assertSame( plugins_url( 'js/upsell.js', EMR_ROOT_FILE ), $script->src );
		$this->assertSame( array( 'jquery' ), $script->deps );
		$data = (string) wp_scripts()->get_data( 'emr_upsell', 'data' );
		$this->assertStringContainsString( 'var emr_upsell = ', $data );
		$this->assertStringContainsString( '"ajax":"' . admin_url( 'admin-ajax.php' ) . '"', $data );
		$this->assertFalse( wp_script_is( 'emr_upsell', 'enqueued' ), 'Registered only: route() enqueues it on the EMR screens.' );
	}

	// ---------------------------------------------------------------------
	// emr_plugin_activate.
	// ---------------------------------------------------------------------

	/**
	 * For `envira` the handler maps the slug to the Envira basename and calls
	 * activate_plugin() with it. The request is stopped where
	 * activate_plugin() reads `active_plugins`, before the handler's bare
	 * die; nothing gets activated on the way.
	 */
	public function test_activate_envira_reaches_activate_plugin_with_the_envira_basename() {
		$activations = array();
		add_action(
			'activate_plugin',
			function ( $plugin ) use ( &$activations ) {
				$activations[] = $plugin;
			}
		);
		add_filter(
			'pre_option_active_plugins',
			function ( $pre ) {
				foreach ( debug_backtrace( 0 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					if ( isset( $frame['function'] ) && 'activate_plugin' === $frame['function'] && ! isset( $frame['class'] ) ) {
						throw new EMR_Test_EnviraStop( 'activate_plugin reached', $frame['args'] );
					}
				}
				return $pre;
			},
			PHP_INT_MAX
		);

		$response = $this->post(
			'emr_plugin_activate',
			array(
				'plugin' => 'envira',
				'nonce'  => wp_create_nonce( 'emr-plugin-activate' ),
			)
		);

		$this->assertNull( $response, 'No JSON yet: the request stopped inside activate_plugin().' );
		$this->assertInstanceOf( EMR_Test_EnviraStop::class, $this->otherException );
		$this->assertSame( self::ENVIRA_FILE, $this->otherException->frameArgs[0], 'activate_plugin() received the Envira basename.' );
		$this->assertSame( array(), $activations, 'Nothing was activated.' );
	}

	/**
	 * What emr_activate() does next for an already active Envira:
	 * activate_plugin() returns null (not a WP_Error) without loading the
	 * plugin again or firing any activation hook, and leaves
	 * `active_plugins` unchanged. The handler therefore prints `true`, the
	 * success answer js/upsell.js expects, and dies.
	 */
	public function test_activate_plugin_on_the_active_envira_returns_null_and_changes_nothing() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertTrue( is_plugin_active( self::ENVIRA_FILE ), 'Precondition: Envira is active.' );
		$before = get_option( 'active_plugins' );
		$fired  = array();
		foreach ( array( 'activate_plugin', 'activate_' . self::ENVIRA_FILE, 'activated_plugin' ) as $hook ) {
			add_action(
				$hook,
				function () use ( $hook, &$fired ) {
					$fired[] = $hook;
				}
			);
		}

		$result = activate_plugin( self::ENVIRA_FILE );

		$this->assertNull( $result );
		$this->assertSame( array(), $fired, 'No activation hook fires for an active plugin.' );
		$this->assertSame( $before, get_option( 'active_plugins' ) );
		$this->assertSame( 'true', json_encode( true ), 'The handler answers `true` after a non-error result.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	// ---------------------------------------------------------------------
	// emr_plugin_install.
	// ---------------------------------------------------------------------

	/**
	 * Installing Envira while its folder exists: the package is downloaded
	 * (here: the dummy zip), Plugin_Upgrader refuses to overwrite the
	 * existing folder, plugin_info() is false and the handler answers its
	 * failure array, `result` false (the message under key 0 is pinned in
	 * tests/Controller/test-UpsellInstaller.php). The real plugin is
	 * untouched.
	 */
	public function test_install_envira_over_the_existing_folder_answers_result_false() {
		$before = $this->folderFingerprint();
		$this->assertArrayHasKey( '/envira-gallery-lite.php', $before, 'Precondition: the real Envira folder is in place.' );

		$response = $this->post(
			'emr_plugin_install',
			array(
				'plugin' => 'envira',
				'nonce'  => wp_create_nonce( 'emr-plugin-install' ),
			)
		);

		$this->assertNull( $this->otherException, 'The request ended through wp_die().' );
		$this->assertIsObject( $response, 'JSON answer: ' . $this->_last_response );
		$this->assertFalse( $response->result );
		$this->assertObjectNotHasProperty( 'success', $response, 'Not the success envelope.' );
		$this->assertSame( array( 'https://downloads.wordpress.org/plugin/envira-gallery-lite.zip' ), $this->downloads );
		$this->assertSame( $before, $this->folderFingerprint(), 'The real Envira files are unchanged.' );
		$this->assertTrue( is_plugin_active( self::ENVIRA_FILE ), 'Envira is still active.' );
	}
}
