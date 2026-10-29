<?php
/**
 * Integration tests: the five EMR admin-ajax endpoints as full requests.
 *
 * Endpoints: `emr_remove_background`, `emr_save_api_key`,
 * `emr_delete_api_key` (classes/ajax.php) and `emr_plugin_install`,
 * `emr_plugin_activate` (classes/external/upsell_installer.php). The unit
 * suites (tests/Controller/test-Ajax.php, test-UpsellInstaller.php) check
 * the handlers' decisions; here every request goes through the admin-ajax
 * dispatch of WP_Ajax_UnitTestCase with the plugin's real registration,
 * the real Api / ApiKeyManager / Plugin_Upgrader, MockEmrApi on
 * api.shortpixel.com and a dummy plugin zip on downloads.wordpress.org.
 *
 * Request lifecycle: in a real admin-ajax request `admin_init` fires before
 * the dispatch, and EnableMediaReplacePlugin::adminInit() runs there (it
 * fills the feature flags and instantiates Ajax again). EMR_AjaxTestCase
 * drops the whole admin_init chain (core's update checks would call
 * wordpress.org), so set_up() calls emr()->adminInit() itself, once, to
 * reproduce EMR's part of that hook. The endpoints are then registered as
 * on a real request (twice for the three emr_* actions, see the @todo on
 * Ajax; the first callback ends the request).
 *
 * Envelopes: remove_background answers with wp_send_json() of the raw
 * Api result object (no success/data wrapper); the key endpoints with
 * wp_send_json_success() / wp_send_json_error() (`success` + `data.message`);
 * the installer with its own arrays (`result`, `message`) or
 * wp_send_json_success( array( 'plugin' => ... ) ).
 *
 * Paths that end in a bare `die` cannot run in-process (PHP exits and
 * PHPUnit reports nothing): remove_background's `die('Wrong nonce')`,
 * emr_activate()'s `echo ...; die;` after a successful or failed
 * activation, and the installer's `echo json(form); die;`. They are
 * stopped one step earlier by a hook that throws: `wp_verify_nonce_failed`
 * for the wrong nonce, `activated_plugin` for the activation. A MISSING
 * nonce on remove_background reaches die('Wrong nonce') without any hook
 * in between (wp_verify_nonce() returns false on an empty nonce before it
 * fires anything), so that one case is not covered here.
 *
 * Logged-out visitors: no `wp_ajax_nopriv_*` action is registered for any
 * of the five. _handleAjax() always fires `wp_ajax_*`, so the logged-out
 * tests replay admin-ajax.php's own dispatch (is_user_logged_in() ->
 * nopriv action -> `wp_die( '0', 400 )` when nothing is hooked).
 *
 * HTTP: every intercepted request is logged by MockEmrApi; a recorder at
 * priority 9998 records every request nothing answered (the base class
 * refuses it at 9999), and tear_down() asserts there was none.
 *
 * Snapshot/restore: $_POST/$_GET/$_REQUEST, the plugin's feature flags,
 * the two EMR options (base class), the ApiKeyManager singleton (base
 * class), the `emr_<key>` transients, attachments (base class), the dummy
 * plugin folders and activations, the current user.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\ApiKeyManager;

/**
 * Thrown from a hook to stop a handler right before a bare die.
 */
class EMR_Test_AjaxStop extends Exception {

	/** @var array Arguments of the hook that threw. */
	public $hookArgs;

	public function __construct( $message, array $args ) {
		parent::__construct( $message );
		$this->hookArgs = $args;
	}
}

class AjaxEndpointsTest extends EMR_AjaxTestCase {

	const API_KEY     = 'abcdEFGHIJKLMNOPwxyz';
	const SPIO_FOLDER = 'shortpixel-image-optimiser';
	const SPIO_FILE   = 'shortpixel-image-optimiser/wp-shortpixel.php';

	/** @var array */
	private $savedRequest = array();

	/** @var array|null */
	private $savedFeatures;

	/** @var int */
	private $savedUser = 0;

	/** @var string[] URLs nothing answered. */
	private $stray = array();

	/** @var string[] downloads.wordpress.org URLs served. */
	private $downloads = array();

	/** @var string[] Plugin folders to remove in tear_down. */
	private $pluginDirs = array();

	/** @var string[] Plugin basenames to deactivate in tear_down. */
	private $activated = array();

	/** @var string|null Message of the WPAjaxDieStopException of the last request. */
	private $stopMessage = null;

	/** @var array Closures added by a test: array( hook, callback ). */
	private $addedHooks = array();

	/** @var Exception|null Non-ajax exception that ended the last request. */
	private $otherException = null;

	public function set_up() {
		parent::set_up();
		$this->savedRequest = array( $_POST, $_GET, $_REQUEST );
		$this->savedUser    = get_current_user_id();
		$this->savedFeatures = $this->featuresProperty()->getValue( emr() );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		// The installer would trigger core's update checks here.
		remove_all_actions( 'upgrader_process_complete' );
		add_filter( 'pre_http_request', array( $this, 'serveDownload' ), 10, 3 );
		add_filter( 'pre_http_request', array( $this, 'recordStray' ), 9998, 3 );
		add_filter( 'filesystem_method', array( $this, 'directMethod' ) );

		$this->_setRole( 'administrator' );

		// admin_init is dropped by the base class; run EMR's part of it.
		emr()->adminInit();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'serveDownload' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'recordStray' ), 9998 );
		remove_filter( 'filesystem_method', array( $this, 'directMethod' ) );
		foreach ( $this->addedHooks as $hook ) {
			remove_action( $hook[0], $hook[1], 10 );
		}
		$this->addedHooks = array();
		if ( $this->activated ) {
			deactivate_plugins( $this->activated, true );
		}
		$this->activated = array();
		foreach ( $this->pluginDirs as $dir ) {
			$this->removeTree( $dir );
		}
		$this->pluginDirs = array();
		wp_clean_plugins_cache( false );

		$this->featuresProperty()->setValue( emr(), $this->savedFeatures );
		list( $_POST, $_GET, $_REQUEST ) = $this->savedRequest;
		wp_set_current_user( $this->savedUser );

		$stray       = $this->stray;
		$this->stray = array();
		parent::tear_down();
		$this->assertSame( array(), $stray, 'An HTTP request left the mocks.' );
	}

	// ---------------------------------------------------------------------
	// Plumbing.
	// ---------------------------------------------------------------------

	private function featuresProperty() {
		$prop = new ReflectionProperty( get_class( emr() ), 'features' );
		$prop->setAccessible( true );
		return $prop;
	}

	public function directMethod() {
		return 'direct';
	}

	/** Records every request no earlier filter answered. */
	public function recordStray( $preempt, $args, $url ) {
		if ( false === $preempt ) {
			$this->stray[] = $url;
		}
		return $preempt;
	}

	/** Answers downloads.wordpress.org with a dummy SPIO package. */
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

	private function pluginSource() {
		return "<?php\n/**\n * Plugin Name: EMR Integration Dummy SPIO\n * Description: Test fixture of the Enable Media Replace integration suite.\n * Version: 1.0.0\n */\n";
	}

	private function buildZip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is needed to build the dummy plugin package.' );
		}
		$path = wp_tempnam( 'emr-spio-zip' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addEmptyDir( self::SPIO_FOLDER );
		$zip->addFromString( self::SPIO_FILE, $this->pluginSource() );
		$zip->close();
		return $path;
	}

	/** Refuses to run over a real SPIO folder; records the folder for removal. */
	private function claimSpioFolder() {
		$dir = WP_PLUGIN_DIR . '/' . self::SPIO_FOLDER;
		if ( file_exists( $dir ) ) {
			$this->markTestSkipped( 'The test install already holds ' . self::SPIO_FOLDER . '; the test does not overwrite a real plugin.' );
		}
		$this->pluginDirs[] = $dir;
		return $dir;
	}

	private function removeTree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->removeTree( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Posts $post to $action through the admin-ajax dispatch. Returns the
	 * decoded JSON (object), or null. The stop message and any non-ajax
	 * exception are kept in $stopMessage / $otherException.
	 */
	private function post( $action, array $post ) {
		$_POST                = $post;
		$_GET                 = array();
		$this->_last_response = '';
		$this->stopMessage    = null;
		$this->otherException = null;
		$level                = ob_get_level();
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			$this->stopMessage = $e->getMessage();
		} catch ( Exception $e ) {
			$this->otherException = $e;
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$decoded = json_decode( $this->_last_response );
		return ( is_object( $decoded ) || is_array( $decoded ) ) ? $decoded : null;
	}

	/**
	 * Replays wp-admin/admin-ajax.php's dispatch after admin_init: logged-in
	 * users get `wp_ajax_{action}`, visitors `wp_ajax_nopriv_{action}`, and
	 * an action nobody hooked ends with wp_die( '0', 400 ).
	 *
	 * @return string The message the request ended with.
	 */
	private function adminAjaxDispatch( $action ) {
		$_POST['action']      = $action;
		$_GET['action']       = $action;
		$_REQUEST             = array_merge( $_POST, $_GET );
		$this->_last_response = '';
		$level                = ob_get_level();
		ob_start(); // the die handler collects the buffer.
		$message = null;
		try {
			if ( is_user_logged_in() ) {
				if ( ! has_action( "wp_ajax_{$action}" ) ) {
					wp_die( '0', 400 );
				}
				do_action( "wp_ajax_{$action}" );
			} else {
				if ( ! has_action( "wp_ajax_nopriv_{$action}" ) ) {
					wp_die( '0', 400 );
				}
				do_action( "wp_ajax_nopriv_{$action}" );
			}
			wp_die( '0' );
		} catch ( WPAjaxDieStopException $e ) {
			$message = $e->getMessage();
		} catch ( WPAjaxDieContinueException $e ) {
			$message = 'continue:' . $this->_last_response;
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		return $message;
	}

	/** Stops the next failed nonce verification with an EMR_Test_AjaxStop. */
	private function stopOnFailedNonce() {
		$callback = function () {
			throw new EMR_Test_AjaxStop( 'nonce refused', func_get_args() );
		};
		add_action( 'wp_verify_nonce_failed', $callback, 10, 4 );
		$this->addedHooks[] = array( 'wp_verify_nonce_failed', $callback );
	}

	/** Stops emr_activate() right after the activation, before its bare die. */
	private function stopAfterActivation() {
		$activated = &$this->activated;
		$callback  = function ( $plugin ) use ( &$activated ) {
			$activated[] = $plugin;
			throw new EMR_Test_AjaxStop( 'activated', array( $plugin ) );
		};
		add_action( 'activated_plugin', $callback );
		$this->addedHooks[] = array( 'activated_plugin', $callback );
	}

	/** A JPEG attachment owned by the current administrator. */
	private function attachment() {
		return $this->uploadFixture( 'test-image.jpg' );
	}

	private function removeBackgroundPost( $id, array $background = array( 'type' => 'transparent' ) ) {
		return array(
			'nonce'         => wp_create_nonce( 'emr_remove_background' ),
			'attachment_id' => (string) $id,
			'background'    => $background,
		);
	}

	private function keyPost( array $extra = array() ) {
		return array_merge( array( 'nonce' => wp_create_nonce( 'emr_save_api_key' ) ), $extra );
	}

	private function storeKey() {
		update_option( ApiKeyManager::OPTION_NAME, self::API_KEY );
		$this->resetSingleton( 'EnableMediaReplace\ApiKeyManager' );
	}

	/** Paths of every request MockEmrApi saw, in order. */
	private function mockPaths() {
		return array_map(
			function ( $request ) {
				return $request['path'];
			},
			$this->api->getRequests()
		);
	}

	// ---------------------------------------------------------------------
	// Registration.
	// ---------------------------------------------------------------------

	public function test_every_endpoint_is_hooked_for_logged_in_users_and_none_for_visitors() {
		foreach ( array( 'remove_background', 'save_api_key', 'delete_api_key' ) as $endpoint ) {
			$this->assertNotFalse( has_action( "wp_ajax_emr_{$endpoint}" ), "wp_ajax_emr_{$endpoint} is hooked." );
			$this->assertFalse( has_action( "wp_ajax_nopriv_emr_{$endpoint}" ), "No nopriv hook for emr_{$endpoint}." );
		}
		$this->assertSame( 10, has_action( 'wp_ajax_emr_plugin_install', 'emr_plugin_install' ) );
		$this->assertSame( 10, has_action( 'wp_ajax_emr_plugin_activate', 'emr_activate' ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_emr_plugin_install' ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_emr_plugin_activate' ) );
	}

	/**
	 * @dataProvider provideEndpoints
	 */
	public function test_a_logged_out_visitor_gets_zero_from_admin_ajax_and_reaches_no_handler( $action, $nonceAction ) {
		$id = $this->attachment();
		$this->storeKey();
		$settings = get_option( 'enable_media_replace', 'absent' );
		wp_set_current_user( 0 );
		$this->assertFalse( is_user_logged_in(), 'Precondition: logged out.' );
		$_POST = array(
			'nonce'         => wp_create_nonce( $nonceAction ),
			'attachment_id' => (string) $id,
			'background'    => array( 'type' => 'transparent' ),
			'api_key'       => 'ZZZZEFGHIJKLMNOPwxyz',
			'plugin'        => 'spio',
		);

		// A compat run leaves a real SPIO folder in the shared test install,
		// so "absent" cannot be assumed: assert the state is unchanged.
		$spio_present = file_exists( WP_PLUGIN_DIR . '/' . self::SPIO_FOLDER );

		$message = $this->adminAjaxDispatch( $action );

		$this->assertSame( '0', $message, 'admin-ajax.php ends an unhooked nopriv action with wp_die( "0", 400 ).' );
		$this->assertSame( '', $this->_last_response );
		$this->assertSame( array(), $this->api->getRequests(), 'No handler ran: no API call.' );
		$this->assertSame( array(), $this->downloads );
		$this->assertSame( self::API_KEY, get_option( ApiKeyManager::OPTION_NAME ), 'The key is untouched.' );
		$this->assertSame( $settings, get_option( 'enable_media_replace', 'absent' ), 'The settings are untouched.' );
		$this->assertSame( $spio_present, file_exists( WP_PLUGIN_DIR . '/' . self::SPIO_FOLDER ), 'The plugin folder state is unchanged.' );
	}

	public function provideEndpoints() {
		return array(
			'remove background' => array( 'emr_remove_background', 'emr_remove_background' ),
			'save key'          => array( 'emr_save_api_key', 'emr_save_api_key' ),
			'delete key'        => array( 'emr_delete_api_key', 'emr_save_api_key' ),
			'plugin install'    => array( 'emr_plugin_install', 'emr-plugin-install' ),
			'plugin activate'   => array( 'emr_plugin_activate', 'emr-plugin-activate' ),
		);
	}

	// ---------------------------------------------------------------------
	// emr_remove_background.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider provideWrongRemoveBackgroundNonces
	 */
	public function test_remove_background_with_a_wrong_nonce_stops_at_verification_before_any_work( $nonce ) {
		$id = $this->attachment();
		$this->stopOnFailedNonce();
		$post          = $this->removeBackgroundPost( $id );
		$post['nonce'] = ( null === $nonce ) ? wp_create_nonce( 'emr_save_api_key' ) : $nonce;

		$this->post( 'emr_remove_background', $post );

		$this->assertInstanceOf( EMR_Test_AjaxStop::class, $this->otherException, 'Verification failed (and die("Wrong nonce") would follow).' );
		$this->assertSame( 'emr_remove_background', $this->otherException->hookArgs[1], 'Verified against the posted action.' );
		$this->assertSame( array(), $this->api->getRequests() );
		$this->assertFalse( get_option( 'enable_media_replace' ), 'Nothing saved before the nonce passes.' );
	}

	public function provideWrongRemoveBackgroundNonces() {
		return array(
			'garbage'                  => array( 'not-a-nonce' ),
			'nonce of another action'  => array( null ),
		);
	}

	public function test_remove_background_for_a_user_without_rights_on_the_attachment_answers_no_permission() {
		$id = $this->attachment();
		$this->_setRole( 'subscriber' );

		$response = $this->post( 'emr_remove_background', $this->removeBackgroundPost( $id ) );

		$this->assertIsObject( $response );
		$this->assertEquals(
			(object) array(
				'success' => false,
				'image'   => null,
				'message' => 'No permission for user',
			),
			$response,
			'The raw Api result object, no success/data envelope.'
		);
		$this->assertSame( array(), $this->api->getRequests() );
		$this->assertFalse( get_option( 'enable_media_replace' ), 'The permission check precedes the settings save.' );
	}

	public function test_remove_background_as_administrator_answers_the_raw_result_and_persists_its_effects() {
		$id    = $this->attachment();
		$post  = $this->removeBackgroundPost( $id, array( 'type' => 'transparent' ) );
		$nonce = $post['nonce'];

		$response = $this->post( 'emr_remove_background', $post );

		$this->assertIsObject( $response );
		$this->assertSame( array( 'success', 'image', 'message', 'key', 'url', 'settings' ), array_keys( get_object_vars( $response ) ) );
		$this->assertTrue( $response->success );
		$this->assertNull( $response->message );
		$this->assertSame( array( '/v2/free-reducer.php' ), $this->mockPaths(), 'One free reducer call, nothing else.' );

		$stored = get_transient( 'emr_' . $response->key );
		$this->assertIsString( $stored );
		$this->assertStringStartsWith( MockEmrApi::DOWNLOAD_BASE, $stored );
		$this->assertSame( wp_hash( $nonce . $stored, 'logged_in' ), $response->key, 'Keyed on the posted nonce and the stored URL.' );
		$this->assertMatchesRegularExpression( '/^' . preg_quote( $stored, '/' ) . '\?ts=\d+$/', $response->image );
		$this->assertSame( wp_get_original_image_url( $id ), $response->url );
		$this->assertSame(
			array(
				'bg_type'         => 'transparent',
				'bg_color'        => '#ffffff',
				'bg_transparency' => false,
			),
			get_option( 'enable_media_replace' )
		);
	}

	// ---------------------------------------------------------------------
	// emr_save_api_key.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider provideBadKeyNonces
	 */
	public function test_save_api_key_with_a_bad_nonce_answers_the_error_envelope( $nonceAction ) {
		$post = array( 'api_key' => self::API_KEY );
		if ( null !== $nonceAction ) {
			$post['nonce'] = wp_create_nonce( $nonceAction );
		}

		$response = $this->post( 'emr_save_api_key', $post );

		$this->assertEquals(
			(object) array(
				'success' => false,
				'data'    => (object) array( 'message' => 'Invalid security token. Please reload the page and try again.' ),
			),
			$response
		);
		$this->assertSame( array(), $this->api->getRequests() );
		$this->assertFalse( get_option( ApiKeyManager::OPTION_NAME ) );
	}

	public function provideBadKeyNonces() {
		return array(
			'missing'                 => array( null ),
			'nonce of another action' => array( 'emr_remove_background' ),
		);
	}

	public function test_save_api_key_without_manage_options_answers_the_permission_error() {
		$this->_setRole( 'editor' );

		$response = $this->post( 'emr_save_api_key', $this->keyPost( array( 'api_key' => self::API_KEY ) ) );

		$this->assertFalse( $response->success );
		$this->assertSame( 'You do not have permission to perform this action.', $response->data->message );
		$this->assertSame( array(), $this->api->getRequests() );
		$this->assertFalse( get_option( ApiKeyManager::OPTION_NAME ) );
	}

	public function test_save_api_key_verifies_against_api_status_and_stores_the_key() {
		$response = $this->post( 'emr_save_api_key', $this->keyPost( array( 'api_key' => ' ' . self::API_KEY . ' ' ) ) );

		$this->assertEquals(
			(object) array(
				'success' => true,
				'data'    => (object) array( 'message' => 'API Key saved and verified successfully. You now have unlimited background removal.' ),
			),
			$response
		);
		$this->assertSame( array( '/v2/api-status.php' ), $this->mockPaths() );
		$request = $this->api->lastRequest();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( array( 'key', 'host', 'DomainCheck', 'Info' ), array_keys( $request['request'] ) );
		$this->assertSame( self::API_KEY, $request['request']['key'] );
		$this->assertSame( get_site_url(), $request['request']['DomainCheck'] );
		$this->assertSame( self::API_KEY, get_option( ApiKeyManager::OPTION_NAME ) );
		$this->assertTrue( ApiKeyManager::getInstance()->hasApiKey(), 'The singleton of this request holds the key.' );
	}

	public function test_save_api_key_without_an_unlimited_plan_answers_the_upgrade_error_and_stores_nothing() {
		$this->api->setKnob( 'unlimited', 'false' );

		$response = $this->post( 'emr_save_api_key', $this->keyPost( array( 'api_key' => self::API_KEY ) ) );

		$this->assertFalse( $response->success );
		$this->assertStringContainsString( '<a href="https://shortpixel.com/pricing"', $response->data->message );
		$this->assertCount( 1, $this->api->requestsTo( 'api-status.php' ) );
		$this->assertFalse( get_option( ApiKeyManager::OPTION_NAME ) );
	}

	// ---------------------------------------------------------------------
	// emr_delete_api_key.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider provideBadKeyNonces
	 */
	public function test_delete_api_key_with_a_bad_nonce_keeps_the_key( $nonceAction ) {
		$this->storeKey();
		$post = array();
		if ( null !== $nonceAction ) {
			$post['nonce'] = wp_create_nonce( $nonceAction );
		}

		$response = $this->post( 'emr_delete_api_key', $post );

		$this->assertFalse( $response->success );
		$this->assertSame( 'Invalid security token. Please reload the page and try again.', $response->data->message );
		$this->assertSame( self::API_KEY, get_option( ApiKeyManager::OPTION_NAME ) );
	}

	public function test_delete_api_key_without_manage_options_keeps_the_key() {
		$this->storeKey();
		$this->_setRole( 'editor' );

		$response = $this->post( 'emr_delete_api_key', $this->keyPost() );

		$this->assertFalse( $response->success );
		$this->assertSame( 'You do not have permission to perform this action.', $response->data->message );
		$this->assertSame( self::API_KEY, get_option( ApiKeyManager::OPTION_NAME ) );
	}

	public function test_delete_api_key_removes_the_option_without_calling_the_api() {
		$this->storeKey();

		$response = $this->post( 'emr_delete_api_key', $this->keyPost() );

		$this->assertEquals(
			(object) array(
				'success' => true,
				'data'    => (object) array( 'message' => 'API Key removed.' ),
			),
			$response
		);
		$this->assertFalse( get_option( ApiKeyManager::OPTION_NAME ) );
		$this->assertFalse( ApiKeyManager::getInstance()->hasApiKey() );
		$this->assertSame( array(), $this->api->getRequests() );
	}

	public function test_the_key_saved_and_deleted_through_the_endpoints_switches_the_reducer_endpoint() {
		$id = $this->attachment();

		$this->post( 'emr_save_api_key', $this->keyPost( array( 'api_key' => self::API_KEY ) ) );
		$keyed = $this->post( 'emr_remove_background', $this->removeBackgroundPost( $id ) );
		$this->post( 'emr_delete_api_key', $this->keyPost() );
		$free = $this->post( 'emr_remove_background', $this->removeBackgroundPost( $id ) );

		$this->assertTrue( $keyed->success );
		$this->assertTrue( $free->success );
		$this->assertSame(
			array( '/v2/api-status.php', '/v2/api-status.php', '/v2/cleanup.php', '/v2/reducer.php', '/v2/free-reducer.php' ),
			$this->mockPaths(),
			'save (verify), keyed request (plan check, purge, reducer), delete (no call), free request.'
		);
	}

	// ---------------------------------------------------------------------
	// emr_plugin_install.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider provideBadInstallNonces
	 */
	public function test_install_with_a_bad_nonce_ends_with_the_expired_link_message( $nonceAction ) {
		$post = array( 'plugin' => 'spio' );
		if ( null !== $nonceAction ) {
			$post['nonce'] = wp_create_nonce( $nonceAction );
		}

		$spio_present = file_exists( WP_PLUGIN_DIR . '/' . self::SPIO_FOLDER );

		$response = $this->post( 'emr_plugin_install', $post );

		$this->assertNull( $response, 'No JSON: check_admin_referer() ends the request through wp_nonce_ays().' );
		$this->assertIsString( $this->stopMessage );
		$this->assertStringContainsString( 'The link you followed has expired.', $this->stopMessage );
		$this->assertSame( array(), $this->downloads );
		$this->assertSame( $spio_present, file_exists( WP_PLUGIN_DIR . '/' . self::SPIO_FOLDER ), 'The plugin folder state is unchanged.' );
	}

	public function provideBadInstallNonces() {
		return array(
			'missing'                 => array( null ),
			'nonce of another action' => array( 'emr-plugin-activate' ),
		);
	}

	public function test_install_without_install_plugins_answers_the_permission_array() {
		$this->_setRole( 'editor' );

		$response = $this->post(
			'emr_plugin_install',
			array(
				'plugin' => 'spio',
				'nonce'  => wp_create_nonce( 'emr-plugin-install' ),
			)
		);

		$this->assertEquals(
			(object) array(
				'result'  => false,
				'message' => 'Install permission issue',
			),
			$response
		);
		$this->assertSame( array(), $this->downloads );
	}

	public function test_install_of_spio_downloads_unpacks_and_answers_the_plugin_basename() {
		$dir = $this->claimSpioFolder();

		$response = $this->post(
			'emr_plugin_install',
			array(
				'plugin' => 'spio',
				'nonce'  => wp_create_nonce( 'emr-plugin-install' ),
			)
		);

		$this->assertNull( $this->otherException );
		$this->assertEquals(
			(object) array(
				'success' => true,
				'data'    => (object) array( 'plugin' => self::SPIO_FILE ),
			),
			$response
		);
		$this->assertSame( array( 'https://downloads.wordpress.org/plugin/shortpixel-image-optimiser.zip' ), $this->downloads );
		$this->assertFileExists( $dir . '/wp-shortpixel.php' );
		$this->assertArrayHasKey( self::SPIO_FILE, get_plugins(), 'WordPress lists the new plugin.' );
		$this->assertFalse( is_plugin_active( self::SPIO_FILE ), 'Installing does not activate.' );
		$this->assertSame( array(), $this->api->getRequests(), 'No ShortPixel API call.' );
	}

	// ---------------------------------------------------------------------
	// emr_plugin_activate.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider provideBadActivateNonces
	 */
	public function test_activate_with_a_bad_nonce_activates_nothing( $nonceAction ) {
		$this->stopAfterActivation();
		$post = array( 'plugin' => 'spio' );
		if ( null !== $nonceAction ) {
			$post['nonce'] = wp_create_nonce( $nonceAction );
		}

		$response = $this->post( 'emr_plugin_activate', $post );

		$this->assertNull( $response );
		$this->assertStringContainsString( 'The link you followed has expired.', (string) $this->stopMessage );
		$this->assertNull( $this->otherException );
		$this->assertSame( array(), $this->activated );
	}

	public function provideBadActivateNonces() {
		return array(
			'missing'                 => array( null ),
			'nonce of another action' => array( 'emr-plugin-install' ),
		);
	}

	public function test_activate_without_activate_plugins_answers_result_false() {
		$this->_setRole( 'editor' );
		$this->stopAfterActivation();

		$response = $this->post(
			'emr_plugin_activate',
			array(
				'plugin' => 'spio',
				'nonce'  => wp_create_nonce( 'emr-plugin-activate' ),
			)
		);

		$this->assertEquals( (object) array( 'result' => false ), $response );
		$this->assertSame( array(), $this->activated );
	}

	public function test_activate_of_spio_activates_the_installed_plugin() {
		$dir = $this->claimSpioFolder();
		wp_mkdir_p( $dir );
		file_put_contents( WP_PLUGIN_DIR . '/' . self::SPIO_FILE, $this->pluginSource() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_clean_plugins_cache( false );
		$this->assertSame( 0, validate_plugin( self::SPIO_FILE ), 'Sentinel: WordPress sees the dummy plugin.' );
		$this->stopAfterActivation();

		$this->post(
			'emr_plugin_activate',
			array(
				'plugin' => 'spio',
				'nonce'  => wp_create_nonce( 'emr-plugin-activate' ),
			)
		);

		$this->assertInstanceOf( EMR_Test_AjaxStop::class, $this->otherException, 'Stopped after activation (echo true; die would follow).' );
		$this->assertSame( array( self::SPIO_FILE ), $this->activated );
		$this->assertTrue( is_plugin_active( self::SPIO_FILE ) );
		$this->assertContains( self::SPIO_FILE, get_option( 'active_plugins' ) );
	}
}
