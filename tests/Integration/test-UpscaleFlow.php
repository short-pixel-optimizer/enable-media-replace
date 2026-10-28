<?php
/**
 * Integration tests: upscale end to end, the way the browser drives it.
 *
 * 1. GET the prepare screen: UpscaleViewController::load() (inherited from
 *    RemoveBackGroundViewController) renders views/prepare-upscale.php.
 * 2. AJAX `emr_remove_background` (the Preview button, js/remove_bg.js,
 *    which also posts the checked `upscale` factor): Ajax::remove_background()
 *    -> Api::request() -> MockEmrApi's reducer with `upscale` instead of
 *    `bg_remove`; the result URL is stored in the `emr_<key>` transient.
 * 3. POST `do_upscale_replace` (the Replace button): loadPost() verifies
 *    the `media_upscale_image` nonce, reads the key back, downloads the
 *    result from the mock's `/f/` URL and replaces the attachment's file
 *    in place through ReplaceController.
 *
 * Step 3 runs on EMR_TestUpscaleViewController (see
 * tests/ViewController/ViewControllerTestDoubles.php): viewSuccess() and
 * viewError() run for real up to their loadView('success' / 'error'),
 * which throws EMR_ViewExit instead of rendering and exiting.
 *
 * The attachment is a GD-built 400x300 JPEG, so every factor is offered;
 * the mock serves the bytes of tests/fixtures/test-image.jpg (50x50)
 * whatever the factor, so "replaced" is visible in the bytes, the
 * dimensions and the generated sizes.
 *
 * The feature-off case does not call route(): with `emr/feature/upscale`
 * false, route() sends both upscale actions to its default branch, which
 * calls exit(). It is asserted through useFeature() and the absence of
 * the UI entry points instead.
 *
 * Snapshot/restore: base class (EMR options, singletons, attachments, mock)
 * plus $_POST/$_GET/$_REQUEST, the feature flags, ViewController's
 * `$viewsLoaded`, the two controller singletons, the transients created,
 * temp files the download leaves, hooks added, the current user.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ReplaceFlowHelpers.php';
require_once dirname( __DIR__ ) . '/ViewController/ViewControllerTestDoubles.php';

use EnableMediaReplace\ViewController;
use EnableMediaReplace\ViewController\RemoveBackGroundViewController;
use EnableMediaReplace\ViewController\UpscaleViewController;
use EnableMediaReplace\ApiKeyManager;

class UpscaleFlowTest extends EMR_AjaxTestCase {

	use EMR_ReplaceFlowHelpers;

	const API_KEY = 'abcdEFGHIJKLMNOPwxyz';

	/** @var array */
	private $savedRequest = array();

	/** @var array|null */
	private $savedFeatures;

	/** @var array */
	private $savedViewsLoaded = array();

	/** @var array */
	private $savedInstances = array();

	/** @var string[] Transient keys created by the flow. */
	private $keys = array();

	/** @var string[] Temp dir listing before the test. */
	private $tempBefore = array();

	public function set_up() {
		parent::set_up();
		$this->savedRequest     = array( $_POST, $_GET, $_REQUEST );
		$this->savedFeatures    = $this->featuresProperty()->getValue( emr() );
		$this->savedViewsLoaded = $this->viewsLoaded()->getValue();
		$this->viewsLoaded()->setValue( null, array() );
		$this->savedInstances   = array( UpscaleViewController::$instance, RemoveBackGroundViewController::$instance );
		UpscaleViewController::$instance          = null;
		RemoveBackGroundViewController::$instance = null;
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		$this->tempBefore = $this->tempListing();

		$this->flowSetUp();
		emr()->adminInit();
	}

	public function tear_down() {
		foreach ( $this->keys as $key ) {
			delete_transient( 'emr_' . $key );
		}
		$this->keys = array();
		$this->flowTearDown();
		foreach ( array_diff( $this->tempListing(), $this->tempBefore ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$this->viewsLoaded()->setValue( null, $this->savedViewsLoaded );
		$this->featuresProperty()->setValue( emr(), $this->savedFeatures );
		list( UpscaleViewController::$instance, RemoveBackGroundViewController::$instance ) = $this->savedInstances;
		list( $_POST, $_GET, $_REQUEST ) = $this->savedRequest;
		wp_dequeue_script( 'emr_success' );
		wp_dequeue_script( 'emr_shortpixel_account' );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Plumbing.
	// ---------------------------------------------------------------------

	private function featuresProperty() {
		$prop = new ReflectionProperty( get_class( emr() ), 'features' );
		$prop->setAccessible( true );
		return $prop;
	}

	private function viewsLoaded() {
		$prop = new ReflectionProperty( ViewController::class, 'viewsLoaded' );
		$prop->setAccessible( true );
		return $prop;
	}

	private function tempListing() {
		$files = glob( trailingslashit( get_temp_dir() ) . '*' );
		return $files ? $files : array();
	}

	/** A 400x300 JPEG attachment owned by the current user; returns ( id, path ). */
	private function image() {
		$id = $this->uploadJpeg( 'emr-ups-' . $this->token() . '.jpg', 400, 300 );
		return array( $id, get_attached_file( $id ) );
	}

	/** The AJAX step, as the upscale screen posts it. Returns the decoded result object. */
	private function preview( $id, $factor = '2' ) {
		$_POST    = array(
			'nonce'         => wp_create_nonce( 'emr_remove_background' ),
			'attachment_id' => (string) $id,
			'upscale'       => (string) $factor,
		);
		$response = $this->doAjax( 'emr_remove_background' );
		if ( is_object( $response ) && isset( $response->key ) ) {
			$this->keys[] = $response->key;
		}
		return $response;
	}

	/**
	 * The POST step. Returns the controller and the EMR_ViewExit it ended
	 * with (null when it returned).
	 */
	private function apply( $id, $key, $nonce_action = 'media_upscale_image' ) {
		$_POST                      = array(
			'emr_nonce' => wp_create_nonce( $nonce_action ),
			'key'       => $key,
			'ID'        => (string) $id,
		);
		$_REQUEST                   = $_POST;
		$controller                 = new EMR_TestUpscaleViewController();
		$exit                       = null;
		$level                      = ob_get_level();
		ob_start();
		try {
			$controller->loadPost();
		} catch ( EMR_ViewExit $e ) {
			$exit = $e;
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		return array( $controller, $exit );
	}

	/** Renders the prepare screen for $id with the plugin's own controller. */
	private function prepareScreen( $id ) {
		$_REQUEST['attachment_id'] = (string) $id;
		$_GET                      = array(
			'page'          => 'enable-media-replace/enable-media-replace.php',
			'action'        => 'emr_prepare_upscale',
			'attachment_id' => (string) $id,
		);
		ob_start();
		try {
			UpscaleViewController::getInstance()->load();
		} finally {
			$output = ob_get_clean();
		}
		return $output;
	}

	private function saveKeyThroughTheEndpoint() {
		$_POST    = array(
			'nonce'   => wp_create_nonce( 'emr_save_api_key' ),
			'api_key' => self::API_KEY,
		);
		$response = $this->doAjax( 'emr_save_api_key' );
		$this->assertTrue( $response->success, 'Sentinel: the key is saved.' );
		$this->assertSame( self::API_KEY, get_option( ApiKeyManager::OPTION_NAME ) );
	}

	private function mockPaths() {
		return array_map(
			function ( $request ) {
				return $request['path'];
			},
			$this->api->getRequests()
		);
	}

	private function fixtureMd5() {
		return md5_file( $this->fixturePath( 'test-image.jpg' ) );
	}

	/** An author who owns a 400x300 JPEG; returns ( author id, attachment id, path ). */
	private function authorWithImage() {
		list( $id, $path ) = $this->image();
		$author            = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $author,
			)
		);
		wp_set_current_user( $author );
		$this->assertTrue( emr()->uiHelper()->is_author_restricted(), 'Sentinel: an author is author restricted.' );
		$this->assertTrue( emr()->checkImagePermission( get_post( $id ) ), 'Sentinel: the author may edit their own attachment.' );
		return array( $author, $id, $path );
	}

	// ---------------------------------------------------------------------
	// Step 1: the prepare screen.
	// ---------------------------------------------------------------------

	public function test_prepare_screen_renders_the_upscale_form_with_every_factor_for_a_400_pixel_image() {
		list( $id ) = $this->image();

		$output = $this->prepareScreen( $id );

		$this->assertStringContainsString( 'action=do_upscale_replace', $output );
		$this->assertStringContainsString( '<input type="hidden" name="ID" value="' . $id . '" />', $output );
		$this->assertStringContainsString( "<input type='hidden' name='key' value='' />", $output );
		$this->assertSame( 1, preg_match( '/name="emr_nonce" value="([a-f0-9]+)"/', $output, $nonce ) );
		$this->assertSame( 1, wp_verify_nonce( $nonce[1], 'media_upscale_image' ) );
		$this->assertMatchesRegularExpression( '/<input id="upscale_2" type="radio" name="upscale" value="2"\s+checked=\'checked\'\s*>/', $output );
		$this->assertMatchesRegularExpression( '/<input id="upscale_3" type="radio" name="upscale" value="3"\s*>/', $output );
		$this->assertMatchesRegularExpression( '/<input id="upscale_4" type="radio" name="upscale" value="4"\s*>/', $output );
		$this->assertStringContainsString( '<h3>Upscaling image...</h3>', $output );
		$this->assertStringNotContainsString( 'name="background_type"', $output );
		$this->assertSame( array(), $this->api->getRequests(), 'Rendering the screen calls no API.' );
	}

	public function test_prepare_screen_links_back_to_the_replace_screen_and_the_replace_screen_links_here() {
		list( $id ) = $this->image();
		$post       = get_post( $id );

		$upscale = $this->prepareScreen( $id );
		ob_start();
		emr()->replace_meta_box( $post );
		$box = ob_get_clean();

		$this->assertStringContainsString( 'action=media_replace', $upscale );
		$this->assertSame( 1, preg_match( '/href="([^"]*action=emr_prepare_upscale[^"]*)"/', $box, $link ) );
		$this->assertSame( 1, preg_match( '/_wpnonce=([0-9a-f]+)/', html_entity_decode( $link[1] ), $nonce ) );
		$this->assertSame( 1, wp_verify_nonce( $nonce[1], 'emr_prepare_upscale' ) );
	}

	// ---------------------------------------------------------------------
	// Step 2: the AJAX preview.
	// ---------------------------------------------------------------------

	public function test_preview_sends_the_factor_instead_of_bg_remove_to_the_free_reducer() {
		list( $id ) = $this->image();

		$this->preview( $id, '3' );

		$this->assertSame( array( '/v2/free-reducer.php' ), $this->mockPaths() );
		$request = $this->api->lastRequest();
		$this->assertSame( 'http://api.shortpixel.com/v2/free-reducer.php', $request['url'] );
		$this->assertSame(
			array(
				'plugin_version' => 'EMR4.2.0',
				'urllist'        => array( urlencode( esc_url( wp_get_original_image_url( $id ) ) ) ),
				'lossy'          => 0,
				'refresh'        => true,
				'upscale'        => 3,
			),
			$request['request']
		);
	}

	public function test_preview_answers_key_image_url_settings_and_stores_the_result_url_under_the_key() {
		list( $id ) = $this->image();

		$result = $this->preview( $id, '2' );

		$this->assertTrue( $result->success );
		$this->assertIsString( $result->key );
		$stored = get_transient( 'emr_' . $result->key );
		$this->assertStringStartsWith( MockEmrApi::DOWNLOAD_BASE, $stored );
		$this->assertMatchesRegularExpression( '/^' . preg_quote( $stored, '/' ) . '\?ts=\d+$/', $result->image );
		$this->assertSame( wp_get_original_image_url( $id ), $result->url );
		$this->assertSame( ' plugin_version:EMR4.2.0  lossy:0  upscale:2 ', $result->settings );
	}

	public function test_preview_leaves_the_remove_background_settings_alone() {
		list( $id ) = $this->image();
		update_option( 'enable_media_replace', array( 'bg_type' => 'solid', 'bg_color' => '#00ff00' ) );

		$result = $this->preview( $id, '4' );

		$this->assertTrue( $result->success );
		$this->assertSame( array( 'bg_type' => 'solid', 'bg_color' => '#00ff00' ), get_option( 'enable_media_replace' ) );
	}

	public function test_preview_with_a_factor_outside_the_set_is_refused_without_a_request() {
		list( $id ) = $this->image();

		$result = $this->preview( $id, '5' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'Invalid upscale factor', $result->message );
		$this->assertObjectNotHasProperty( 'key', $result );
		$this->assertSame( array(), $this->api->getRequests() );
	}

	public function test_preview_on_the_free_tier_with_the_quota_used_up_reports_quota_exceeded_for_upscaling() {
		list( $id ) = $this->image();
		$this->api->setKnob( 'quotaExceeded', true );

		$result = $this->preview( $id );

		$this->assertFalse( $result->success );
		$this->assertTrue( $result->quota_exceeded );
		$this->assertSame( 'Quota exceeded. Please purchase the Unlimited or Unlimited AI plan and add your API key to continue upscaling images.', $result->message );
		$this->assertObjectNotHasProperty( 'key', $result );
	}

	public function test_with_a_saved_key_the_preview_purges_once_then_uses_the_keyed_reducer_with_item_id_and_factor() {
		list( $id ) = $this->image();
		$this->saveKeyThroughTheEndpoint();
		$this->api->reset();

		$result = $this->preview( $id, '3' );

		$this->assertTrue( $result->success );
		$this->assertSame( array( '/v2/api-status.php', '/v2/cleanup.php', '/v2/reducer.php' ), $this->mockPaths() );
		$reducer = $this->api->requestsTo( 'reducer.php' );
		$this->assertSame( 'https://api.shortpixel.com/v2/reducer.php', $reducer[0]['url'] );
		$this->assertSame( self::API_KEY, $reducer[0]['request']['key'] );
		$this->assertSame( $id, $reducer[0]['request']['item_id'] );
		$this->assertSame( 3, $reducer[0]['request']['upscale'] );
		$this->assertArrayNotHasKey( 'bg_remove', $reducer[0]['request'] );
		$this->assertSame( ' plugin_version:EMR4.2.0  lossy:0  upscale:3  key:' . self::API_KEY . '  item_id:' . $id . ' ', $result->settings );
	}

	// ---------------------------------------------------------------------
	// Step 3: apply.
	// ---------------------------------------------------------------------

	public function test_apply_replaces_the_file_in_place_with_the_downloaded_bytes() {
		list( $id, $path ) = $this->image();
		$before            = md5_file( $path );
		$result            = $this->preview( $id );

		list( $controller, $exit ) = $this->apply( $id, $result->key );

		$this->assertInstanceOf( EMR_ViewExit::class, $exit );
		$this->assertSame( 'success', $exit->template );
		$this->assertTrue( $controller->successCalled );
		$this->assertSame( 0, $controller->errorCalls );
		$this->assertCount( 1, $this->api->requestsTo( '/f/' ), 'One download of the stored result.' );
		clean_post_cache( $id );
		$this->assertSame( $path, get_attached_file( $id ), 'Same file name and location.' );
		$this->assertNotSame( $before, md5_file( $path ) );
		$this->assertSame( $this->fixtureMd5(), md5_file( $path ), 'The bytes the mock served.' );
		$this->assertSame( emr()->uiHelper()->getSuccesRedirect( $id ), $controller->getView()->postUrl );
	}

	public function test_apply_regenerates_the_metadata_from_the_new_image() {
		list( $id ) = $this->image();
		$oldSizes   = $this->sizePaths( $id );
		$this->assertNotEmpty( $oldSizes, 'Sentinel: the 400x300 original has sizes.' );
		$result = $this->preview( $id );

		$this->apply( $id, $result->key );

		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 50, $meta['width'] );
		$this->assertSame( 50, $meta['height'] );
		$this->assertSame( array(), $meta['sizes'] );
		foreach ( $oldSizes as $size => $file ) {
			$this->assertFileDoesNotExist( $file, "The old $size file is removed." );
		}
	}

	public function test_apply_refuses_the_remove_background_nonce_and_leaves_the_attachment() {
		list( $id, $path ) = $this->image();
		$before            = md5_file( $path );
		$result            = $this->preview( $id );

		list( $controller, $exit ) = $this->apply( $id, $result->key, 'media_remove_background' );

		$this->assertSame( 'error', $exit->template );
		$this->assertSame( ViewController::ERROR_NONCE, $controller->lastError );
		$this->assertSame( array(), $this->api->requestsTo( '/f/' ) );
		$this->assertSame( $before, md5_file( $path ) );
	}

	public function test_a_key_previewed_through_the_remove_background_screen_applies_through_the_upscale_form() {
		list( $id, $path ) = $this->image();
		$_POST    = array(
			'nonce'         => wp_create_nonce( 'emr_remove_background' ),
			'attachment_id' => (string) $id,
			'background'    => array( 'type' => 'transparent' ),
		);
		$result = $this->doAjax( 'emr_remove_background' );
		$this->keys[] = $result->key;
		$this->assertSame( '1', $this->api->lastRequest()['request']['bg_remove'], 'Sentinel: a background removal was requested.' );

		list( $controller, $exit ) = $this->apply( $id, $result->key );

		$this->assertSame( 'success', $exit->template, 'The key is not bound to the operation that produced it.' );
		$this->assertSame( $this->fixtureMd5(), md5_file( $path ) );
		$this->assertSame( 0, $controller->errorCalls );
	}

	// ---------------------------------------------------------------------
	// Author restriction and the feature filter.
	// ---------------------------------------------------------------------

	public function test_an_author_gets_no_upscale_entry_point_and_loadpost_refuses() {
		list( , $id, $path ) = $this->authorWithImage();
		$before              = md5_file( $path );
		$post                = get_post( $id );
		set_transient( 'emr_upauthorkey', $this->api->stashImage(), 60 );
		$this->keys[] = 'upauthorkey';

		$actions = emr()->add_media_action( array(), $post );
		ob_start();
		emr()->replace_meta_box( $post );
		$box    = ob_get_clean();
		$fields = emr()->attachment_editor( array(), $post );
		list( $controller, $exit ) = $this->apply( $id, 'upauthorkey' );

		$this->assertFalse( emr()->useFeature( 'upscale' ) );
		$this->assertFalse( emr()->uiHelper()->isUpscalable( $post ) );
		$this->assertSame( array( 'media_replace' ), array_keys( $actions ) );
		$this->assertStringNotContainsString( 'Upscale image', $box );
		$this->assertSame( array( 'enable-media-replace' ), array_keys( $fields ) );
		$this->assertSame( 'error', $exit->template );
		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $controller->lastError );
		$this->assertSame( array(), $this->api->requestsTo( '/f/' ) );
		$this->assertSame( $before, md5_file( $path ) );
	}

	/**
	 * Not driven through route(): with the feature off, route() sends
	 * `emr_prepare_upscale` and `do_upscale_replace` to its default branch,
	 * which calls exit(). The remove-background entry points stay: they are
	 * governed by `emr/feature/background`.
	 */
	public function test_with_the_feature_filter_off_no_entry_point_offers_upscale() {
		list( $id ) = $this->image();
		$post       = get_post( $id );
		$this->addHook( 'emr/feature/upscale', '__return_false' );
		emr()->adminInit();

		$fields  = emr()->attachment_editor( array(), $post );
		$actions = emr()->add_media_action( array(), $post );
		ob_start();
		emr()->replace_meta_box( $post );
		$box = ob_get_clean();

		$this->assertFalse( emr()->useFeature( 'upscale' ) );
		$this->assertTrue( emr()->useFeature( 'background' ) );
		$this->assertFalse( emr()->uiHelper()->isUpscalable( $post ) );
		$this->assertSame( array( 'enable-media-replace', 'emr-remove-background' ), array_keys( $fields ) );
		$this->assertSame( array( 'media_replace', 'remove_background' ), array_keys( $actions ) );
		$this->assertStringContainsString( 'Remove background', $box );
		$this->assertStringNotContainsString( 'Upscale image', $box );
	}

	/**
	 * The same gap the remove-background flow pins for its own flag: the
	 * shared AJAX endpoint consults neither useFeature('background') nor
	 * useFeature('upscale'), so with `emr/feature/upscale` false the UI
	 * hides every upscale entry point while the endpoint still sends an
	 * upscale request to the reducer (spending the free quota or the
	 * site's Unlimited key) and hands back a usable result key. The
	 * endpoint should refuse an `upscale` request when the feature is off.
	 *
	 * How to recognise the fix: `success` is false, no reducer request is
	 * logged, and the assertions on the served request fail.
	 */
	public function test_the_ajax_preview_upscales_with_the_feature_filter_off_pinned_for_deferred_fix() {
		list( $id ) = $this->image();
		$this->addHook( 'emr/feature/upscale', '__return_false' );
		emr()->adminInit();
		$this->assertFalse( emr()->useFeature( 'upscale' ), 'Precondition: the feature is off.' );

		$result = $this->preview( $id, '2' );

		$this->assertTrue( $result->success, 'Pinned: the request is served.' );
		$this->assertCount( 1, $this->api->requestsTo( 'free-reducer.php' ) );
		$this->assertSame( 2, $this->api->lastRequest()['request']['upscale'] );
		$this->assertIsString( get_transient( 'emr_' . $result->key ) );
	}
}
