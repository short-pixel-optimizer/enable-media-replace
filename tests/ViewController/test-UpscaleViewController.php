<?php
/**
 * Tests for EnableMediaReplace\ViewController\UpscaleViewController
 * (classes/ViewController/UpscaleViewController.php) and the screen it
 * renders, views/prepare-upscale.php.
 *
 * The controller inherits load() and loadPost() from
 * RemoveBackGroundViewController and only changes the template and the
 * nonce action, so this file covers what differs: the separate singleton,
 * the upscale screen (factor radios, their disabling by image width, the
 * account box wording, no background options) and the nonce loadPost()
 * accepts. The shared load() / loadPost() checks and the download are
 * covered in test-RemoveBackgroundViewController.php; one end-to-end
 * loadPost() here proves the inherited apply step works through this
 * controller.
 *
 * The controller is reached through EMR_TestUpscaleViewController (see
 * ViewControllerTestDoubles.php): viewError() and viewSuccess() run for
 * real up to the loadView('error' / 'success') call, which throws
 * EMR_ViewExit instead of rendering and exiting. loadView() for the
 * upscale screen is the real one, captured with output buffering.
 *
 * Images are drawn with GD at the widths the factor table needs
 * (UIHelper::getUpscaleFactors(): 2x and 3x up to 1200 px, 4x up to
 * 1024 px); tests/fixtures/test-image.jpg (50x50) is the small case.
 *
 * Snapshot/restore: the current user, $_GET / $_POST / $_REQUEST, the
 * `enable_media_replace` option, the ApiKeyManager key option and
 * singleton, the transients set, ViewController::$viewsLoaded, both
 * controller singletons and the UIHelper singleton, the plugin's feature
 * flags, every hook a test adds, the attachments and files created.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ViewControllerTestDoubles.php';
require_once dirname( __DIR__ ) . '/Integration/Helpers/MockEmrApi.php';

use EnableMediaReplace\ViewController;
use EnableMediaReplace\ViewController\RemoveBackGroundViewController;
use EnableMediaReplace\ViewController\UpscaleViewController;
use EnableMediaReplace\ApiKeyManager;

class UpscaleViewControllerTest extends WP_UnitTestCase {

	const OPTION = 'enable_media_replace';

	/** @var int */
	private $saved_user;

	/** @var array */
	private $saved_request = array();

	/** @var array option name => value|null */
	private $saved_options = array();

	/** @var array */
	private $saved_statics = array();

	/** @var array class => instance */
	private $saved_instances = array();

	/** @var array|null */
	private $saved_features;

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
		$this->saved_instances                    = array(
			UpscaleViewController::class          => UpscaleViewController::$instance,
			RemoveBackGroundViewController::class => RemoveBackGroundViewController::$instance,
		);
		UpscaleViewController::$instance          = null;
		RemoveBackGroundViewController::$instance = null;
		$this->saved_features                     = $this->features_property()->getValue( emr() );

		$this->blocked = array();
		$this->mock    = MockEmrApi::register();
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		emr()->adminInit();
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
		UpscaleViewController::$instance          = $this->saved_instances[ UpscaleViewController::class ];
		RemoveBackGroundViewController::$instance = $this->saved_instances[ RemoveBackGroundViewController::class ];
		$this->features_property()->setValue( emr(), $this->saved_features );

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

	private function features_property() {
		$ref = new ReflectionProperty( get_class( emr() ), 'features' );
		$ref->setAccessible( true );
		return $ref;
	}

	private function add_hook( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->hooks[] = array( $hook, $callback, $priority );
	}

	/** Uploads a copy of tests/fixtures/test-image.jpg (50x50). */
	private function upload( $author = null ) {
		$copy = get_temp_dir() . 'emr-ups-' . wp_generate_password( 8, false, false ) . '.jpg';
		copy( dirname( __DIR__ ) . '/fixtures/test-image.jpg', $copy );
		return $this->upload_file( $copy, $author );
	}

	/** Uploads a GD-drawn JPEG of the given size. */
	private function upload_jpeg( $width, $height = 90, $author = null ) {
		$path  = get_temp_dir() . 'emr-ups-' . wp_generate_password( 8, false, false ) . '.jpg';
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 60, 140, 90 ) );
		imagejpeg( $image, $path, 80 );
		$id = $this->upload_file( $path, $author );
		$this->assertSame( $width, wp_get_attachment_metadata( $id )['width'], 'Precondition: the width is in the metadata.' );
		return $id;
	}

	private function upload_file( $path, $author ) {
		$this->files[] = $path;
		$id            = self::factory()->attachment->create_upload_object( $path );
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
		$controller                = new EMR_TestUpscaleViewController();
		ob_start();
		try {
			$controller->load();
		} finally {
			$output = ob_get_clean();
		}
		return array( $controller, $output );
	}

	/** A valid loadPost() form for the current user. */
	private function valid_post( $id, $key = 'emrupkey' ) {
		$_POST = array(
			'emr_nonce' => wp_create_nonce( 'media_upscale_image' ),
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

	/** JPEG bytes of a 120x90 image, visibly "upscaled" from the 50x50 fixture. */
	private function upscaled_jpeg_bytes() {
		$image = imagecreatetruecolor( 120, 90 );
		imagefilledrectangle( $image, 0, 0, 119, 89, imagecolorallocate( $image, 200, 30, 60 ) );
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

	/** The `<input ... name="upscale" value="N" ...>` tag of a factor. */
	private function radio( $output, $factor ) {
		$this->assertSame( 1, preg_match( '/<input id="upscale_' . $factor . '"[^>]*>/', $output, $m ), 'No radio for ' . $factor . 'x.' );
		return $m[0];
	}

	/*
	 * Singleton.
	 */

	public function test_get_instance_returns_one_upscale_controller_separate_from_the_remove_background_one() {
		$first = UpscaleViewController::getInstance();

		$this->assertInstanceOf( UpscaleViewController::class, $first );
		$this->assertInstanceOf( RemoveBackGroundViewController::class, $first, 'It is a RemoveBackGroundViewController by inheritance.' );
		$this->assertSame( $first, UpscaleViewController::getInstance() );
		$this->assertNull( RemoveBackGroundViewController::$instance, 'The parent singleton is a different property.' );

		$remove = RemoveBackGroundViewController::getInstance();
		$this->assertNotSame( $first, $remove );
		$this->assertNotInstanceOf( UpscaleViewController::class, $remove );
		$this->assertSame( $first, UpscaleViewController::$instance );
	}

	/*
	 * load() and views/prepare-upscale.php.
	 */

	public function test_load_renders_the_upscale_template_with_the_view_data() {
		$id = $this->upload();

		list( $controller, $output ) = $this->load( $id );
		$view                        = $controller->getView();

		$this->assertSame( 0, $controller->errorCalls );
		$this->assertSame( array( 'prepare-upscale' ), $controller->loadedViews );
		$this->assertSame( $id, $view->attachment->ID );
		$this->assertSame( get_attached_file( $id ), $view->sourceFile->getFullPath() );
		$this->assertStringContainsString( '<div class="wrap emr_upload_form emr-upscale-form" id="remove-background-form">', $output );
		$this->assertStringContainsString( 'Upscale Image</div>', $output );
		$this->assertStringContainsString( 'Upscale Options</div>', $output );
	}

	public function test_load_without_upload_files_shows_the_upload_permission_error() {
		$id = $this->upload();
		$this->user( 'subscriber' );
		$_REQUEST['attachment_id'] = (string) $id;
		$controller                = new EMR_TestUpscaleViewController();

		$exit = $this->expect_exit( array( $controller, 'load' ) );

		$this->assertSame( ViewController::ERROR_UPLOAD_PERMISSION, $exit->getCode() );
	}

	public function test_load_without_permission_on_the_attachment_shows_the_image_permission_error() {
		$id = $this->upload( $this->admin_id );
		$this->user( 'author' );
		$_REQUEST['attachment_id'] = (string) $id;
		$controller                = new EMR_TestUpscaleViewController();

		$exit = $this->expect_exit( array( $controller, 'load' ) );

		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $exit->getCode() );
	}

	public function test_screen_has_the_form_nonce_id_and_key() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$action = emr()->uiHelper()->getFormUrl( $id, 'do_upscale_replace' );
		$this->assertStringContainsString( '<form id="emr_replace_form" enctype="multipart/form-data" method="POST" action="' . $action . '" >', $output );
		$this->assertStringContainsString( 'action=do_upscale_replace', $output );
		$this->assertStringNotContainsString( 'do_background_replace', $output );
		preg_match( '/<input type="hidden" id="emr_nonce" name="emr_nonce" value="([a-f0-9]+)" \/>/', $output, $nonce );
		$this->assertNotEmpty( $nonce );
		$this->assertSame( 1, wp_verify_nonce( $nonce[1], 'media_upscale_image' ) );
		$this->assertFalse( wp_verify_nonce( $nonce[1], 'media_remove_background' ), 'The upscale form has its own nonce action.' );
		$this->assertStringContainsString( '<input type="hidden" name="ID" value="' . $id . '" />', $output );
		$this->assertStringContainsString( "<input type='hidden' name='key' value='' />", $output );
	}

	public function test_screen_offers_every_factor_for_a_small_image_with_the_smallest_checked() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		foreach ( array( 2, 3, 4 ) as $factor ) {
			$radio = $this->radio( $output, $factor );
			$this->assertStringContainsString( 'type="radio" name="upscale" value="' . $factor . '"', $radio );
			$this->assertStringNotContainsString( 'disabled', $radio, $factor . 'x is enabled for a 50 px image.' );
			$this->assertStringContainsString( $factor . 'x', $output );
		}
		$this->assertStringContainsString( "checked='checked'", $this->radio( $output, 2 ) );
		$this->assertStringNotContainsString( 'checked', $this->radio( $output, 3 ) );
		$this->assertStringNotContainsString( 'checked', $this->radio( $output, 4 ) );
		$this->assertStringContainsString( 'Enlarges the image with the selected factor using AI upscaling.', $output );
		$this->assertStringNotContainsString( 'This image is too big to be upscaled.', $output );
	}

	public function test_screen_disables_4x_above_1024_pixels_and_checks_the_first_enabled_factor() {
		$id = $this->upload_jpeg( 1100 );

		list( , $output ) = $this->load( $id );

		$this->assertStringNotContainsString( 'disabled', $this->radio( $output, 2 ) );
		$this->assertStringContainsString( "checked='checked'", $this->radio( $output, 2 ) );
		$this->assertStringNotContainsString( 'disabled', $this->radio( $output, 3 ) );
		$this->assertStringContainsString( "disabled='disabled'", $this->radio( $output, 4 ) );
		$this->assertStringNotContainsString( 'checked', $this->radio( $output, 4 ) );
		$this->assertMatchesRegularExpression( '/id="remove_background_button"\s*>Preview<\/button>/', $output );
	}

	public function test_screen_at_exactly_1024_pixels_still_offers_2x_and_3x_only() {
		$id = $this->upload_jpeg( 1024 );

		list( , $output ) = $this->load( $id );

		$this->assertStringNotContainsString( 'disabled', $this->radio( $output, 2 ) );
		$this->assertStringNotContainsString( 'disabled', $this->radio( $output, 3 ) );
		$this->assertStringContainsString( "disabled='disabled'", $this->radio( $output, 4 ), 'The limit is inclusive on the screen.' );
	}

	public function test_screen_for_an_image_at_the_limit_disables_every_factor_and_the_preview_button() {
		$id = $this->upload_jpeg( 1200 );
		$this->assertTrue( emr()->uiHelper()->isUpscalable( get_post( $id ) ), 'Sentinel: the entry points still offer the screen at 1200 px (pinned in the Helper suite).' );

		list( , $output ) = $this->load( $id );

		foreach ( array( 2, 3, 4 ) as $factor ) {
			$radio = $this->radio( $output, $factor );
			$this->assertStringContainsString( "disabled='disabled'", $radio, $factor . 'x' );
			$this->assertStringNotContainsString( 'checked', $radio, $factor . 'x' );
		}
		$this->assertStringContainsString( 'This image is too big to be upscaled.', $output );
		$this->assertStringNotContainsString( 'Enlarges the image with the selected factor', $output );
		$this->assertMatchesRegularExpression( '/id="remove_background_button"\s+disabled=\'disabled\'\s*>Preview<\/button>/', $output );
	}

	public function test_screen_has_no_background_options_and_no_report_link() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringNotContainsString( 'name="background_type"', $output );
		$this->assertStringNotContainsString( 'id="bg_color"', $output );
		$this->assertStringNotContainsString( 'id="bg_transparency"', $output );
		$this->assertStringNotContainsString( 'bad-background-link', $output );
		$this->assertStringNotContainsString( 'emr_prepare_upscale', $output, 'No link to itself.' );
	}

	public function test_screen_has_the_previews_with_the_upscaling_overlay() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertSame( 2, substr_count( $output, "<div class='image_placeholder" ), 'Current and preview boxes.' );
		$this->assertStringContainsString( "<span class='upload-title'>Current</span>", $output );
		$this->assertStringContainsString( "<span class='upload-title'>Preview</span>", $output );
		$this->assertStringContainsString( '<h3>Upscaling image...</h3>', $output );
		$this->assertStringNotContainsString( 'Removing background', $output );
		$this->assertSame( 1, substr_count( $output, 'id="overlay"' ) );
	}

	public function test_screen_has_the_preview_replace_cancel_and_replace_instead_controls() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( 'id="remove_background_button"', $output, 'The id the shared script binds to.' );
		$this->assertStringContainsString( '<button type="submit" class="button button-primary" id="replace_image_button" disabled>Replace</button>', $output );
		$this->assertStringContainsString( '<a class="button" href="javascript:history.back()">Cancel</a>', $output );
		$this->assertStringContainsString( 'Replace this image with another one instead!', $output );
		$this->assertSame( 1, preg_match( '/href="([^"]*action=media_replace[^"]*)"/', $output, $link ) );
		$this->assertSame( 1, preg_match( '/_wpnonce=([0-9a-f]+)/', html_entity_decode( $link[1] ), $nonce ) );
		$this->assertSame( 1, wp_verify_nonce( $nonce[1], 'media_replace' ) );
	}

	public function test_screen_account_box_uses_the_upscale_wording_without_a_key() {
		$id = $this->upload();
		$this->assertFalse( ApiKeyManager::getInstance()->hasApiKey(), 'Precondition.' );

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( 'data-confirm-remove="Remove the saved ShortPixel API Key? Image upscale will fall back to the Free plan."', $output );
		$this->assertStringContainsString( 'Image Upscale is limited on the Free plan. Add your ShortPixel Unlimited or Unlimited AI plan API Key to remove that limit.', $output );
		$this->assertStringNotContainsString( 'Background Removal', $output );
		$this->assertStringNotContainsString( '100 images', $output );
		$this->assertStringContainsString( 'id="emr-new-api-key"', $output );
		$this->assertStringContainsString( 'Activate', $output );
		$this->assertTrue( wp_script_is( 'emr_shortpixel_account', 'enqueued' ) );
	}

	public function test_screen_account_box_uses_the_upscale_wording_with_a_key() {
		$key = 'ABCDEFGHIJ0123456789';
		update_option( ApiKeyManager::OPTION_NAME, $key, false );
		$this->set_static( ApiKeyManager::class, 'instance', null );
		$manager = ApiKeyManager::getInstance();
		$this->assertTrue( $manager->hasApiKey(), 'Precondition.' );
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( 'Your ShortPixel API Key is connected. Image Upscale is unlimited as long as your Unlimited or Unlimited AI plan is active.', $output );
		$this->assertStringContainsString( '<span class="emr-sp-key-value">' . esc_html( $manager->getMaskedKey() ) . '</span>', $output );
		$this->assertStringNotContainsString( $key, $output );
		$this->assertStringNotContainsString( 'Background Removal', $output );
	}

	public function test_screen_hides_the_account_box_without_manage_options() {
		$author = $this->user( 'author' );
		$id     = $this->upload( $author );

		list( $controller, $output ) = $this->load( $id );

		$this->assertSame( 0, $controller->errorCalls, 'load() does not apply the author restriction.' );
		$this->assertStringContainsString( 'id="remove_background_button"', $output );
		$this->assertStringNotContainsString( 'emr-sp-account', $output );
		$this->assertFalse( wp_script_is( 'emr_shortpixel_account', 'enqueued' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_screen_shows_the_upsell_section_inside_the_form() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$section = strpos( $output, "<section class='upsell-wrapper'>" );
		$this->assertIsInt( $section );
		$this->assertGreaterThan( strpos( $output, 'id="replace_image_button"' ), $section );
		$this->assertLessThan( strrpos( $output, '</form>' ), $section );
	}

	/*
	 * loadPost().
	 */

	public function test_load_post_refuses_the_remove_background_nonce() {
		$id    = $this->upload();
		$_POST = array( 'emr_nonce' => wp_create_nonce( 'media_remove_background' ), 'key' => 'k', 'ID' => (string) $id );
		$controller = new EMR_TestUpscaleViewController();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_NONCE, $exit->getCode() );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	public function test_load_post_without_nonce_shows_the_nonce_error() {
		$_POST = array( 'key' => 'k', 'ID' => '5' );

		$exit = $this->expect_exit( array( new EMR_TestUpscaleViewController(), 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_NONCE, $exit->getCode() );
	}

	public function test_load_post_with_the_upscale_nonce_but_no_key_shows_the_key_error() {
		$id = $this->upload();
		$this->valid_post( $id, '' );
		$controller = new EMR_TestUpscaleViewController();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_KEY, $exit->getCode() );
	}

	public function test_load_post_for_an_author_restricted_owner_shows_the_image_permission_error() {
		$author = $this->user( 'author' );
		$id     = $this->upload( $author );
		$this->assertTrue( emr()->checkImagePermission( get_post( $id ) ), 'Precondition: the author may edit their own attachment.' );
		$this->valid_post( $id );
		$controller = new EMR_TestUpscaleViewController();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $exit->getCode() );
		$this->assertObjectNotHasProperty( 'post_id', $controller->getView() );
	}

	public function test_load_post_with_an_unknown_key_shows_download_failed() {
		$id = $this->upload();
		$this->valid_post( $id, 'emr-no-such-key' );
		$controller = new EMR_TestUpscaleViewController();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );

		$this->assertSame( ViewController::ERROR_DOWNLOAD_FAILED, $exit->getCode() );
		$this->assertSame( array(), $this->mock->getRequests() );
	}

	public function test_load_post_downloads_the_result_and_replaces_the_file_in_place() {
		$id       = $this->upload();
		$file     = get_attached_file( $id );
		$original = md5_file( $file );
		$this->assertSame( 50, wp_get_attachment_metadata( $id )['width'], 'Precondition.' );
		$bytes = $this->upscaled_jpeg_bytes();
		$this->stash_result( 'emrupgood', $bytes );
		$this->valid_post( $id, 'emrupgood' );
		$controller = new EMR_TestUpscaleViewController();
		$before     = $this->track_temp_files();

		$exit = $this->expect_exit( array( $controller, 'loadPost' ) );
		$this->untrack( $before );

		$this->assertSame( 'success', $exit->template );
		$this->assertTrue( $controller->successCalled );
		$this->assertSame( 0, $controller->errorCalls );
		$this->assertCount( 1, $this->mock->requestsTo( '/f/' ) );
		clean_post_cache( $id );
		$this->assertSame( $file, get_attached_file( $id ), 'Same file name and location.' );
		$this->assertNotSame( $original, md5_file( $file ) );
		$this->assertSame( md5( $bytes ), md5_file( $file ) );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 120, $meta['width'], 'The metadata follows the new image.' );
		$this->assertSame( 90, $meta['height'] );
		$this->assertSame( emr()->uiHelper()->getSuccesRedirect( $id ), $controller->getView()->postUrl );
	}

	public function test_set_view_redirects_match_the_remove_background_controller() {
		$controller = new EMR_TestUpscaleViewController();

		$controller->callSetView( 93 );

		$view = $controller->getView();
		$this->assertSame( 93, $view->post_id );
		$this->assertSame( emr()->uiHelper()->getSuccesRedirect( 93 ), $view->postUrl );
		$this->assertStringContainsString( 'action=media_replace', $view->emrUrl );
		$this->assertStringNotContainsString( 'emr_prepare_upscale', $view->postUrl, 'The success screen returns to the edit screen, not to the upscale screen.' );
	}
}
