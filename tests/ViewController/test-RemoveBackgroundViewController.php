<?php
/**
 * Tests for EnableMediaReplace\ViewController\RemoveBackGroundViewController
 * (classes/ViewController/RemoveBackgroundViewController.php) and the
 * screen it renders, views/prepare-remove-background.php.
 *
 * The controller is reached through EMR_TestRemoveBackgroundViewController
 * (see ViewControllerTestDoubles.php): viewError() and viewSuccess() run for
 * real up to the loadView('error' / 'success') call, which throws
 * EMR_ViewExit instead of rendering and exiting. loadView() for the
 * remove-background screen is the real one, captured with output buffering.
 *
 * loadPost() runs end to end: the processed image is "downloaded" from
 * MockEmrApi's `/f/` endpoint (the URL is stored in the `emr_<key>`
 * transient the way Api::request() stores it), and ReplaceController
 * replaces the attachment's file for real. A fail-fast `pre_http_request`
 * filter at priority 9999 refuses and records any request the mock does
 * not answer; tear_down() asserts there was none.
 *
 * The prepare screen includes views/upsell.php with include_once, so the
 * upsell section is asserted in a separate process.
 *
 * Snapshot/restore: the current user, $_GET / $_POST / $_REQUEST, the
 * `enable_media_replace` option, the ApiKeyManager key option and
 * singleton, the transients set, ViewController::$viewsLoaded,
 * RemoveBackGroundViewController::$instance and the UIHelper singleton,
 * every hook a test adds, the attachments and files created.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ViewControllerTestDoubles.php';
require_once dirname( __DIR__ ) . '/Integration/Helpers/MockEmrApi.php';

use EnableMediaReplace\ViewController;
use EnableMediaReplace\ViewController\RemoveBackGroundViewController;
use EnableMediaReplace\FileSystem\Model\File\FileModel;
use EnableMediaReplace\ApiKeyManager;

class RemoveBackgroundViewControllerTest extends WP_UnitTestCase {

	const OPTION = 'enable_media_replace';

	/** @var int */
	private $saved_user;

	/** @var array */
	private $saved_request = array();

	/** @var array option name => value|null */
	private $saved_options = array();

	/** @var array */
	private $saved_statics = array();

	/** @var mixed */
	private $saved_instance;

	/** @var int */
	private $admin_id;

	/** @var int[] */
	private $attachments = array();

	/** @var string[] */
	private $files = array();

	/** @var string[] */
	private $transients = array();

	/** @var array */
	private $hooks = array();

	/** @var string[] */
	private $blocked = array();

	/** @var MockEmrApi */
	private $mock;

	public function set_up() {
		parent::set_up();
		$this->saved_user    = get_current_user_id();
		$this->saved_request = array( '_GET' => $_GET, '_POST' => $_POST, '_REQUEST' => $_REQUEST );
		$_GET                = array();
		$_POST               = array();
		$_REQUEST            = array();
		foreach ( array( self::OPTION, ApiKeyManager::OPTION_NAME ) as $option ) {
			$this->saved_options[ $option ] = get_option( $option, null );
			delete_option( $option );
		}

		foreach ( $this->statics() as $name => $spec ) {
			$this->saved_statics[ $name ] = $this->static_value( $spec[0], $spec[1] );
			$this->set_static( $spec[0], $spec[1], $spec[2] );
		}
		$this->saved_instance                     = RemoveBackGroundViewController::$instance;
		RemoveBackGroundViewController::$instance = null;

		$this->blocked = array();
		$this->mock    = MockEmrApi::register();
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999 );
		MockEmrApi::unregister();
		foreach ( $this->hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$this->hooks = array();
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachments = array();
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();
		foreach ( $this->transients as $transient ) {
			delete_transient( $transient );
		}
		$this->transients = array();

		foreach ( $this->saved_options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value, false );
			}
		}
		$_GET     = $this->saved_request['_GET'];
		$_POST    = $this->saved_request['_POST'];
		$_REQUEST = $this->saved_request['_REQUEST'];

		foreach ( $this->statics() as $name => $spec ) {
			$this->set_static( $spec[0], $spec[1], $this->saved_statics[ $name ] );
		}
		RemoveBackGroundViewController::$instance = $this->saved_instance;

		wp_dequeue_script( 'emr_success' );
		wp_dequeue_script( 'emr_shortpixel_account' );
		wp_set_current_user( $this->saved_user );

		$blocked       = $this->blocked;
		$this->blocked = array();
		parent::tear_down();
		$this->assertSame( array(), $blocked, 'A request reached the fail-fast filter.' );
	}

	/**
	 * Statics reset per test: name => array( class, property, value in the test ).
	 */
	private function statics() {
		return array(
			'views'    => array( ViewController::class, 'viewsLoaded', array() ),
			'uihelper' => array( \EnableMediaReplace\UIHelper::class, 'instance', null ),
			'apikey'   => array( ApiKeyManager::class, 'instance', null ),
		);
	}

	public function fail_fast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->blocked[] = $url;
		return new WP_Error( 'http_blocked', 'Blocked unmocked request to ' . $url );
	}

	/*
	 * Helpers.
	 */

	private function static_value( $class, $name ) {
		$ref = new ReflectionProperty( $class, $name );
		$ref->setAccessible( true );
		return $ref->getValue();
	}

	private function set_static( $class, $name, $value ) {
		$ref = new ReflectionProperty( $class, $name );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	private function add_hook( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->hooks[] = array( $hook, $callback, $priority );
	}

	private function upload( $author = null ) {
		$copy = get_temp_dir() . 'emr-rbg-' . wp_generate_password( 8, false, false ) . '.jpg';
		copy( dirname( __DIR__ ) . '/fixtures/test-image.jpg', $copy );
		$this->files[] = $copy;
		$id            = self::factory()->attachment->create_upload_object( $copy );
		if ( null !== $author ) {
			wp_update_post( array( 'ID' => $id, 'post_author' => $author ) );
		}
		$this->attachments[] = $id;
		return $id;
	}

	private function user( $role ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Runs $callback, which must end in viewError() / viewSuccess(); returns
	 * the EMR_ViewExit. Output is discarded.
	 */
	private function expect_exit( $callback ) {
		ob_start();
		try {
			call_user_func( $callback );
		} catch ( EMR_ViewExit $exit ) {
			ob_end_clean();
			return $exit;
		}
		ob_end_clean();
		$this->fail( 'The controller returned instead of ending at a view.' );
	}

	/** Runs load() for $id; returns array( controller, output ). */
	private function load( $id ) {
		$_REQUEST['attachment_id'] = (string) $id;
		$controller                = new EMR_TestRemoveBackgroundViewController();
		ob_start();
		try {
			$controller->load();
		} finally {
			$output = ob_get_clean();
		}
		return array( $controller, $output );
	}

	/** A valid loadPost() form for the current user. */
	private function valid_post( $id, $key = 'emrtestkey' ) {
		$_POST = array(
			'emr_nonce' => wp_create_nonce( 'media_remove_background' ),
			'key'       => $key,
			'ID'        => (string) $id,
		);
	}

	/** Stores a download URL under the transient key, as Api::request() does. */
	private function stash_result( $key, $bytes = null ) {
		$url = $this->mock->stashImage( $bytes );
		set_transient( 'emr_' . $key, $url, 60 );
		$this->transients[] = 'emr_' . $key;
		return $url;
	}

	/** JPEG bytes that differ from test-image.jpg. */
	private function other_jpeg_bytes() {
		$image = imagecreatetruecolor( 40, 30 );
		imagefilledrectangle( $image, 0, 0, 39, 29, imagecolorallocate( $image, 200, 30, 60 ) );
		ob_start();
		imagejpeg( $image, null, 80 );
		return (string) ob_get_clean();
	}

	/** Records temp files the download creates, so tear_down() removes them. */
	private function track_temp_files() {
		$before = glob( get_temp_dir() . '*' );
		return $before ? $before : array();
	}

	private function untrack( array $before ) {
		$after = glob( get_temp_dir() . '*' );
		foreach ( array_diff( $after ? $after : array(), $before ) as $file ) {
			if ( is_file( $file ) ) {
				$this->files[] = $file;
			}
		}
	}

	/*
	 * Singleton.
	 */

	public function test_get_instance_returns_one_controller() {
		$first = RemoveBackGroundViewController::getInstance();

		$this->assertInstanceOf( RemoveBackGroundViewController::class, $first );
		$this->assertInstanceOf( \EnableMediaReplace\ViewController\RemoveBackgroundViewController::class, $first, 'Class names are case-insensitive.' );
		$this->assertSame( $first, RemoveBackGroundViewController::getInstance() );
	}

	/*
	 * load().
	 */

	public function test_load_without_upload_files_shows_the_upload_permission_error() {
		$id = $this->upload();
		$this->user( 'subscriber' );
		$_REQUEST['attachment_id'] = (string) $id;
		$controller                = new EMR_TestRemoveBackgroundViewController();

		$exit = $this->expect_exit( array( $controller, 'load' ) );

		$this->assertSame( ViewController::ERROR_UPLOAD_PERMISSION, $exit->getCode() );
		$this->assertSame( 1, $controller->errorCalls );
	}

	public function test_load_without_permission_on_the_attachment_shows_the_image_permission_error() {
		$id = $this->upload( $this->admin_id );
		$this->user( 'author' );
		$_REQUEST['attachment_id'] = (string) $id;
		$controller                = new EMR_TestRemoveBackgroundViewController();

		$exit = $this->expect_exit( array( $controller, 'load' ) );

		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $exit->getCode() );
		$this->assertObjectNotHasProperty( 'attachment', $controller->getView() );
	}

	public function test_load_does_not_check_the_author_restriction() {
		$author = $this->user( 'author' );
		$id     = $this->upload( $author );

		list( $controller, $output ) = $this->load( $id );

		$this->assertSame( 0, $controller->errorCalls );
		$this->assertSame( array( 'prepare-remove-background' ), $controller->loadedViews );
		$this->assertStringContainsString( '<form id="emr_replace_form"', $output );
	}

	public function test_load_fills_the_view_with_defaults() {
		$id = $this->upload();

		list( $controller ) = $this->load( $id );
		$view               = $controller->getView();

		$this->assertSame( 0, $controller->errorCalls );
		$this->assertInstanceOf( WP_Post::class, $view->attachment );
		$this->assertSame( $id, $view->attachment->ID );
		$this->assertInstanceOf( FileModel::class, $view->sourceFile );
		$this->assertSame( get_attached_file( $id ), $view->sourceFile->getFullPath() );
		$this->assertSame( array( 'bg_type' => 'transparent', 'bg_color' => '#ffffff', 'bg_transparency' => 100 ), $view->settings );
	}

	public function test_load_merges_the_stored_option_over_the_defaults() {
		update_option( self::OPTION, array( 'bg_type' => 'solid', 'bg_color' => '#123456', 'replace_type' => 'replace' ), false );
		$id = $this->upload();

		list( $controller, $output ) = $this->load( $id );
		$settings                    = $controller->getView()->settings;

		$this->assertSame( 'solid', $settings['bg_type'] );
		$this->assertSame( '#123456', $settings['bg_color'] );
		$this->assertSame( 100, $settings['bg_transparency'] );
		$this->assertSame( 'replace', $settings['replace_type'] );
		$this->assertMatchesRegularExpression( "/id=\"solid_background\" type=\"radio\" name=\"background_type\" value=\"solid\"\s+checked='checked'/", $output );
		$this->assertStringContainsString( '<input type="hidden"  value="#123456" name="bg_color" id="bg_color" />', $output );
	}

	/*
	 * views/prepare-remove-background.php.
	 */

	public function test_prepare_screen_has_the_form_nonce_id_and_key() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$action = emr()->uiHelper()->getFormUrl( $id, 'do_background_replace' );
		$this->assertStringContainsString( '<form id="emr_replace_form" enctype="multipart/form-data" method="POST" action="' . $action . '" >', $output );
		$this->assertStringContainsString( 'action=do_background_replace', $output );
		preg_match( '/<input type="hidden" id="emr_nonce" name="emr_nonce" value="([a-f0-9]+)" \/>/', $output, $nonce );
		$this->assertNotEmpty( $nonce );
		$this->assertSame( 1, wp_verify_nonce( $nonce[1], 'media_remove_background' ) );
		$this->assertStringContainsString( '<input type="hidden" name="ID" value="' . $id . '" />', $output );
		$this->assertStringContainsString( "<input type='hidden' name='key' value='' />", $output );
	}

	public function test_prepare_screen_has_the_background_options() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertMatchesRegularExpression( "/id=\"transparent_background\" type=\"radio\" name=\"background_type\" value=\"transparent\"\s+checked='checked'/", $output );
		$this->assertMatchesRegularExpression( '/id="solid_background" type="radio" name="background_type" value="solid"\s*>/', $output );
		$this->assertStringContainsString( '<input type="color" value="#ffffff" name="bg_display_picker" id="bg_display_picker" />', $output );
		$this->assertStringContainsString( '<input type="hidden"  value="#ffffff" name="bg_color" id="bg_color" />', $output );
		$this->assertStringContainsString( '<input type="range" min="0" max="100" value="100" id="bg_transparency" />', $output );
	}

	public function test_prepare_screen_has_the_preview_replace_and_cancel_buttons() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( '<button type="button" class="button button-primary" id="remove_background_button">Preview</button>', $output );
		$this->assertStringContainsString( '<button type="submit" class="button button-primary" id="replace_image_button" disabled>Replace</button>', $output );
		$this->assertStringContainsString( '<a class="button" href="javascript:history.back()">Cancel</a>', $output );
		$this->assertStringContainsString( 'action=media_replace', $output, 'The "replace with another image" link.' );
	}

	public function test_prepare_screen_without_api_key_shows_the_key_input() {
		$id = $this->upload();
		$this->assertFalse( ApiKeyManager::getInstance()->hasApiKey(), 'Precondition.' );

		list( , $output ) = $this->load( $id );

		$this->assertMatchesRegularExpression( '/<section class="emr-sp-account wrapper" data-confirm-remove="[^"]+">/', $output );
		$this->assertStringContainsString( 'data-confirm-remove="Remove the saved ShortPixel API Key? Background removal will fall back to the Free plan (100 image limit)."', $output );
		$this->assertStringContainsString( 'Background Removal is limited to 100 images on the Free plan.', $output );
		$this->assertStringContainsString( 'id="emr-new-api-key"', $output );
		$this->assertStringContainsString( 'Activate', $output );
		$this->assertStringNotContainsString( '<script', $output, 'The key box is driven by js/shortpixel-account.js, not an inline script.' );
		$this->assertStringContainsString( 'https://shortpixel.com/free-sign-up', $output );
		$this->assertStringNotContainsString( '<span class="emr-sp-key-value">', $output );
	}

	public function test_prepare_screen_with_api_key_shows_the_masked_key() {
		$key = 'ABCDEFGHIJ0123456789';
		update_option( ApiKeyManager::OPTION_NAME, $key, false );
		$this->set_static( ApiKeyManager::class, 'instance', null );
		$manager = ApiKeyManager::getInstance();
		$this->assertTrue( $manager->hasApiKey(), 'Precondition.' );
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( '<span class="emr-sp-key-value">' . esc_html( $manager->getMaskedKey() ) . '</span>', $output );
		$this->assertStringNotContainsString( $key, $output );
		$this->assertStringContainsString( 'id="emr-remove-key"', $output );
		$this->assertStringNotContainsString( 'Enter your 20-character API Key', $output );
	}

	public function test_prepare_screen_hides_the_key_section_without_manage_options() {
		$author = $this->user( 'author' );
		$id     = $this->upload( $author );

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( 'id="remove_background_button"', $output );
		$this->assertStringNotContainsString( 'emr-sp-account', $output );
		$this->assertStringNotContainsString( 'emr_save_api_key', $output );
		$this->assertFalse( wp_script_is( 'emr_shortpixel_account', 'enqueued' ), 'The account script is only enqueued with the box.' );
	}

	public function test_prepare_screen_enqueues_the_account_script_for_an_administrator() {
		$id = $this->upload();
		emr()->admin_scripts();
		$this->assertFalse( wp_script_is( 'emr_shortpixel_account', 'enqueued' ), 'Precondition.' );

		$this->load( $id );

		$this->assertTrue( wp_script_is( 'emr_shortpixel_account', 'enqueued' ) );
	}

	public function test_prepare_screen_links_to_the_upscale_screen_for_an_upscalable_image() {
		$id = $this->upload();
		$this->assertTrue( emr()->uiHelper()->isUpscalable( get_post( $id ) ), 'Precondition: a 50x50 JPEG is upscalable.' );

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( 'New! Click here to upscale/enlarge this image using AI!', $output );
		$this->assertSame( 1, preg_match( '/href="([^"]*action=emr_prepare_upscale[^"]*)"/', $output, $link ) );
		$this->assertSame( 1, preg_match( '/_wpnonce=([0-9a-f]+)/', html_entity_decode( $link[1] ), $nonce ) );
		$this->assertSame( 1, wp_verify_nonce( $nonce[1], 'emr_prepare_upscale' ) );
		$this->assertStringContainsString( 'attachment_id=' . $id, $link[1] );
	}

	public function test_prepare_screen_has_no_upscale_link_when_the_upscale_feature_is_off() {
		$id = $this->upload();
		$this->add_hook( 'emr/feature/upscale', '__return_false' );
		emr()->adminInit();
		$this->assertFalse( emr()->useFeature( 'upscale' ), 'Precondition.' );

		try {
			list( , $output ) = $this->load( $id );

			$this->assertStringContainsString( 'id="remove_background_button"', $output );
			$this->assertStringNotContainsString( 'emr_prepare_upscale', $output );
		} finally {
			remove_filter( 'emr/feature/upscale', '__return_false' );
			emr()->adminInit();
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_prepare_screen_shows_the_upsell_section_inside_the_form() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$section = strpos( $output, "<section class='upsell-wrapper'>" );
		$this->assertIsInt( $section );
		$this->assertGreaterThan( strpos( $output, 'id="replace_image_button"' ), $section );
		$this->assertLessThan( strrpos( $output, '</form>' ), $section );
		$this->assertStringContainsString( "id='upsell-nonce-activate'", $output );
	}

	/*
	 * loadPost(): the checks in order.
	 */

	public function test_load_post_with_bad_nonce_shows_the_nonce_error() {
		$id    = $this->upload();
		$_POST = array( 'emr_nonce' => wp_create_nonce( 'media_replace_upload' ), 'key' => 'k', 'ID' => (string) $id );
		$controller = new EMR_TestRemoveBackgroundViewController();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_NONCE, $exit->getCode() );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	public function test_load_post_without_nonce_shows_the_nonce_error() {
		$_POST = array( 'key' => 'k', 'ID' => '5' );

		$exit = $this->expect_exit( array( new EMR_TestRemoveBackgroundViewController(), 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_NONCE, $exit->getCode() );
	}

	public function test_load_post_with_empty_or_missing_key_shows_the_key_error() {
		$id = $this->upload();
		foreach ( array( '', '<b></b>', null ) as $key ) {
			$this->valid_post( $id, $key );
			if ( null === $key ) {
				unset( $_POST['key'] );
			}
			$controller = new EMR_TestRemoveBackgroundViewController();

			$exit = $this->expect_exit( array( $controller, 'loadPost' ) );

			$this->assertSame( ViewController::ERROR_KEY, $exit->getCode(), var_export( $key, true ) );
		}
	}

	public function test_load_post_without_id_shows_the_form_error() {
		$this->valid_post( 1 );
		unset( $_POST['ID'] );

		$exit = $this->expect_exit( array( new EMR_TestRemoveBackgroundViewController(), 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_FORM, $exit->getCode() );
	}

	public function test_load_post_without_permission_shows_the_image_permission_error() {
		$id = $this->upload( $this->admin_id );
		$this->user( 'subscriber' );
		$this->valid_post( $id );
		$controller = new EMR_TestRemoveBackgroundViewController();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $exit->getCode() );
		$this->assertObjectNotHasProperty( 'post_id', $controller->getView() );
	}

	public function test_load_post_for_an_author_restricted_owner_shows_the_image_permission_error() {
		$author = $this->user( 'author' );
		$id     = $this->upload( $author );
		$this->assertTrue( emr()->checkImagePermission( get_post( $id ) ), 'Precondition: the author may edit their own attachment.' );
		$this->valid_post( $id );
		$controller = new EMR_TestRemoveBackgroundViewController();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $exit->getCode() );
		$this->assertSame( 1, $controller->errorCalls );
		$this->assertObjectNotHasProperty( 'post_id', $controller->getView() );
	}

	public function test_load_post_with_an_unknown_key_shows_download_failed() {
		$id = $this->upload();
		$this->valid_post( $id, 'emr-no-such-key' );
		$this->assertFalse( get_transient( 'emr_emr-no-such-key' ), 'Precondition.' );
		$controller = new EMR_TestRemoveBackgroundViewController();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_DOWNLOAD_FAILED, $exit->getCode() );
		$this->assertSame( $id, $controller->getView()->post_id, 'setView() ran before the download.' );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	/**
	 * loadPost() copies the download error from Api::handleDownload() into
	 * `$this->view->errorMessage` and then calls
	 * viewError(ERROR_DOWNLOAD_FAILED), which overwrites it with the generic
	 * "Replacement Image could not be downloaded or does not exist". The
	 * API's message ("This file seems not available anymore. Please try
	 * again") never reaches the error screen. It should be shown, for
	 * instance as the error description.
	 *
	 * How to recognise the fix: the view ends with the API message in
	 * errorMessage or errorDescription, and the assertions on the generic
	 * message / missing description fail.
	 */
	public function test_load_post_download_error_message_is_overwritten_pinned_for_deferred_fix() {
		$id = $this->upload();
		$this->valid_post( $id, 'emr-expired-key' );
		$controller = new EMR_TestRemoveBackgroundViewController();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_DOWNLOAD_FAILED, $exit->getCode() );
		$this->assertSame( ViewController::ERROR_DOWNLOAD_FAILED, $controller->lastError );
		$this->assertSame( 'This file seems not available anymore. Please try again', $controller->errorMessageBefore, 'Set from the API result before viewError().' );
		$this->assertSame( 'Replacement Image could not be downloaded or does not exist', $controller->getView()->errorMessage );
		$this->assertObjectNotHasProperty( 'errorDescription', $controller->getView() );
	}

	public function test_load_post_downloads_the_result_and_replaces_the_file_in_place() {
		$id       = $this->upload();
		$file     = get_attached_file( $id );
		$original = md5_file( $file );
		$this->set_post_dates( $id, '2001-02-03 04:05:06' );
		$bytes = $this->other_jpeg_bytes();
		$this->stash_result( 'emrgoodkey', $bytes );
		$this->valid_post( $id, 'emrgoodkey' );
		$controller = new EMR_TestRemoveBackgroundViewController();
		$before     = $this->track_temp_files();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );
		$this->untrack( $before );

		$this->assertSame( 'success', $exit->template );
		$this->assertTrue( $controller->successCalled );
		$this->assertSame( 0, $controller->errorCalls );
		$this->assertCount( 1, $this->mock->requestsTo( '/f/' ) );
		clean_post_cache( $id );
		$this->assertSame( $file, get_attached_file( $id ), 'Same file name and location.' );
		$this->assertFileExists( $file );
		$this->assertNotSame( $original, md5_file( $file ) );
		$this->assertSame( md5( $bytes ), md5_file( $file ) );
		$post = get_post( $id );
		$this->assertNotSame( '2001-02-03 04:05:06', $post->post_modified );
		$this->assertGreaterThanOrEqual( strtotime( '-1 minute', current_time( 'timestamp' ) ), strtotime( $post->post_modified ) );
		$this->assertSame( '2001-02-03 04:05:06', $post->post_date, 'TIME_UPDATEMODIFIED keeps the post date.' );
		$this->assertSame( emr()->uiHelper()->getSuccesRedirect( $id ), $controller->getView()->postUrl );
	}

	/*
	 * setView() and replaceBackground().
	 */

	public function test_set_view_sets_post_id_and_both_redirects_and_ignores_params() {
		$controller = new EMR_TestRemoveBackgroundViewController();

		$controller->callSetView( 91, array( 'remove_background' => true ) );

		$view = $controller->getView();
		$this->assertSame( 91, $view->post_id );
		$this->assertSame( emr()->uiHelper()->getSuccesRedirect( 91 ), $view->postUrl );
		$this->assertStringContainsString( 'action=media_replace', $view->emrUrl );
		$this->assertStringContainsString( 'attachment_id=91', $view->emrUrl );
		$this->assertStringNotContainsString( 'emr_prepare_remove', $view->postUrl );
	}

	public function test_replace_background_downloads_to_a_temp_file() {
		$bytes = $this->other_jpeg_bytes();
		$url   = $this->stash_result( 'emrdownload', $bytes );
		$before = $this->track_temp_files();

		$result = ( new EMR_TestRemoveBackgroundViewController() )->callReplaceBackground( 12345, 'emrdownload' );
		$this->untrack( $before );

		$this->assertInstanceOf( stdClass::class, $result );
		$this->assertTrue( $result->success );
		$this->assertNull( $result->message );
		$this->assertIsString( $result->image );
		$this->assertFileExists( $result->image );
		$this->assertSame( md5( $bytes ), md5_file( $result->image ) );
		$this->assertSame( $url, $this->mock->lastRequest()['url'] );
	}

	public function test_replace_background_with_unknown_key_returns_the_failure() {
		$result = ( new EMR_TestRemoveBackgroundViewController() )->callReplaceBackground( 1, 'emr-none' );

		$this->assertFalse( $result->success );
		$this->assertNull( $result->image );
		$this->assertSame( 'This file seems not available anymore. Please try again', $result->message );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	private function set_post_dates( $id, $date ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'         => $date,
				'post_date_gmt'     => $date,
				'post_modified'     => $date,
				'post_modified_gmt' => $date,
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
	}
}
