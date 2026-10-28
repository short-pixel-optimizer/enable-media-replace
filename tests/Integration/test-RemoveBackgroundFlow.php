<?php
/**
 * Integration tests: remove background end to end, the way the browser
 * drives it.
 *
 * 1. GET the prepare screen: RemoveBackGroundViewController::load() renders
 *    views/prepare-remove-background.php for the attachment.
 * 2. AJAX `emr_remove_background` (the Preview button, js/remove_bg.js):
 *    Ajax::remove_background() -> Api::request() -> MockEmrApi's reducer;
 *    the result URL is stored in the `emr_<key>` transient and the choice
 *    in the `enable_media_replace` option.
 * 3. POST `do_background_replace` (the Replace button): loadPost() reads
 *    the key back, downloads the result from the mock's `/f/` URL and
 *    replaces the attachment's file in place through ReplaceController.
 *
 * Step 3 runs on EMR_TestRemoveBackgroundViewController (see
 * tests/ViewController/ViewControllerTestDoubles.php): viewSuccess() and
 * viewError() run for real up to their loadView('success' / 'error'),
 * which throws EMR_ViewExit instead of rendering and exiting.
 *
 * The attachment is a GD-built 400x300 JPEG; the mock serves the bytes of
 * tests/fixtures/test-image.jpg (50x50), so "replaced" is visible in the
 * bytes, the dimensions and the generated sizes.
 *
 * Request lifecycle: EMR_AjaxTestCase drops `admin_init`; set_up() calls
 * emr()->adminInit() once to fill the feature flags as a real admin
 * request does.
 *
 * The feature-off case does not call route(): with `emr/feature/background`
 * false, route() sends both background actions to its default branch,
 * which calls exit(). It is asserted through useFeature() and the absence
 * of the UI entry points instead.
 *
 * Snapshot/restore: base class (EMR options, singletons, attachments, mock)
 * plus $_POST/$_GET/$_REQUEST, the feature flags, ViewController's
 * `$viewsLoaded`, the transients created, temp files the download leaves,
 * hooks added, the current user (EMR_ReplaceFlowHelpers).
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ReplaceFlowHelpers.php';
require_once dirname( __DIR__ ) . '/ViewController/ViewControllerTestDoubles.php';

use EnableMediaReplace\ViewController;
use EnableMediaReplace\ViewController\RemoveBackGroundViewController;
use EnableMediaReplace\ApiKeyManager;

class RemoveBackgroundFlowTest extends EMR_AjaxTestCase {

	use EMR_ReplaceFlowHelpers;

	const API_KEY = 'abcdEFGHIJKLMNOPwxyz';

	/** @var array */
	private $savedRequest = array();

	/** @var array|null */
	private $savedFeatures;

	/** @var array */
	private $savedViewsLoaded = array();

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
		list( $_POST, $_GET, $_REQUEST ) = $this->savedRequest;
		wp_dequeue_script( 'emr_success' );
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
		$id = $this->uploadJpeg( 'emr-rbg-' . $this->token() . '.jpg', 400, 300 );
		return array( $id, get_attached_file( $id ) );
	}

	/** Gives the attachment old dates, so a change of either is visible. */
	private function ageDates( $id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'         => '2001-02-03 04:05:06',
				'post_date_gmt'     => '2001-02-03 04:05:06',
				'post_modified'     => '2001-02-03 04:05:06',
				'post_modified_gmt' => '2001-02-03 04:05:06',
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
	}

	/** The AJAX step. Returns the decoded result object (null when not JSON). */
	private function preview( $id, array $background = array( 'type' => 'transparent' ) ) {
		$_POST    = array(
			'nonce'         => wp_create_nonce( 'emr_remove_background' ),
			'attachment_id' => (string) $id,
			'background'    => $background,
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
	private function apply( $id, $key ) {
		$_POST                      = array(
			'emr_nonce' => wp_create_nonce( 'media_remove_background' ),
			'key'       => $key,
			'ID'        => (string) $id,
		);
		$_REQUEST                   = $_POST;
		$controller                 = new EMR_TestRemoveBackgroundViewController();
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
			'action'        => 'emr_prepare_remove',
			'attachment_id' => (string) $id,
		);
		ob_start();
		try {
			RemoveBackGroundViewController::getInstance()->load();
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

	public function test_prepare_screen_renders_the_form_with_id_nonce_empty_key_and_the_saved_settings() {
		list( $id ) = $this->image();
		update_option(
			'enable_media_replace',
			array(
				'bg_type'         => 'solid',
				'bg_color'        => '#00ff00',
				'bg_transparency' => '40',
			)
		);

		$output = $this->prepareScreen( $id );

		$this->assertStringContainsString( 'action=do_background_replace', $output );
		$this->assertStringContainsString( '<input type="hidden" name="ID" value="' . $id . '" />', $output );
		$this->assertStringContainsString( "<input type='hidden' name='key' value='' />", $output );
		$this->assertSame( 1, preg_match( '/name="emr_nonce" value="([a-f0-9]+)"/', $output, $nonce ) );
		$this->assertSame( 1, wp_verify_nonce( $nonce[1], 'media_remove_background' ) );
		$this->assertMatchesRegularExpression( '/id="solid_background" type="radio" name="background_type" value="solid"\s+checked=\'checked\'/', $output );
		$this->assertStringContainsString( '<input type="hidden"  value="#00ff00" name="bg_color" id="bg_color" />', $output );
		$this->assertStringContainsString( 'value="40" id="bg_transparency"', $output );
		$this->assertSame( array(), $this->api->getRequests(), 'Rendering the screen calls no API.' );
	}

	// ---------------------------------------------------------------------
	// Step 2: the AJAX preview.
	// ---------------------------------------------------------------------

	public function test_preview_with_a_solid_colour_sends_colour_and_alpha_and_the_urlencoded_url_to_the_free_reducer() {
		list( $id ) = $this->image();

		$this->preview(
			$id,
			array(
				'type'         => 'solid',
				'color'        => '#ff0000',
				'transparency' => '50',
			)
		);

		$this->assertSame( array( '/v2/free-reducer.php' ), $this->mockPaths() );
		$request = $this->api->lastRequest();
		$this->assertSame( 'http://api.shortpixel.com/v2/free-reducer.php', $request['url'] );
		$this->assertSame(
			array(
				'plugin_version' => 'EMR4.2.0',
				'bg_remove'      => '#ff000050',
				'urllist'        => array( urlencode( esc_url( wp_get_original_image_url( $id ) ) ) ),
				'lossy'          => 0,
				'refresh'        => true,
			),
			$request['request'],
			'The alpha is the decimal transparency as sent today (see the Api suite pin).'
		);
	}

	public function test_preview_answers_key_image_url_settings_and_stores_the_result_url_under_the_key() {
		list( $id ) = $this->image();

		$result = $this->preview(
			$id,
			array(
				'type'         => 'solid',
				'color'        => '#ff0000',
				'transparency' => '50',
			)
		);

		$this->assertTrue( $result->success );
		$this->assertIsString( $result->key );
		$stored = get_transient( 'emr_' . $result->key );
		$this->assertStringStartsWith( MockEmrApi::DOWNLOAD_BASE, $stored );
		$this->assertMatchesRegularExpression( '/^' . preg_quote( $stored, '/' ) . '\?ts=\d+$/', $result->image );
		$this->assertSame( wp_get_original_image_url( $id ), $result->url );
		$this->assertSame( ' plugin_version:EMR4.2.0  bg_remove:#ff000050  lossy:0 ', $result->settings );
	}

	public function test_preview_saves_the_choice_and_the_next_prepare_screen_shows_it() {
		list( $id ) = $this->image();

		$this->preview(
			$id,
			array(
				'type'         => 'solid',
				'color'        => '#ff0000',
				'transparency' => '50',
			)
		);
		$output = $this->prepareScreen( $id );

		$this->assertSame(
			array(
				'bg_type'         => 'solid',
				'bg_color'        => '#ff0000',
				'bg_transparency' => '50',
			),
			get_option( 'enable_media_replace' )
		);
		$this->assertMatchesRegularExpression( '/value="solid"\s+checked=\'checked\'/', $output );
		$this->assertStringContainsString( 'value="#ff0000" name="bg_color"', $output );
		$this->assertStringContainsString( 'value="50" id="bg_transparency"', $output );
	}

	public function test_preview_on_the_free_tier_with_the_quota_used_up_reports_quota_exceeded() {
		list( $id ) = $this->image();
		$this->api->setKnob( 'quotaExceeded', true );

		$result = $this->preview( $id );

		$this->assertFalse( $result->success );
		$this->assertTrue( $result->quota_exceeded );
		$this->assertSame( 'Quota exceeded. Please purchase the Unlimited or Unlimited AI plan and add your API key to continue removing backgrounds.', $result->message );
		$this->assertObjectNotHasProperty( 'key', $result );
		$this->assertSame( array( '/v2/free-reducer.php' ), $this->mockPaths() );
	}

	// ---------------------------------------------------------------------
	// Keyed path.
	// ---------------------------------------------------------------------

	public function test_with_a_saved_key_the_preview_purges_once_then_uses_the_keyed_reducer_with_item_id() {
		list( $id ) = $this->image();
		$this->saveKeyThroughTheEndpoint();

		$result = $this->preview( $id );

		$this->assertTrue( $result->success );
		$this->assertSame(
			array( '/v2/api-status.php', '/v2/api-status.php', '/v2/cleanup.php', '/v2/reducer.php' ),
			$this->mockPaths(),
			'Save verifies, the preview re-checks the plan, purges once, then calls the reducer.'
		);
		$url     = esc_url_raw( wp_get_original_image_url( $id ) );
		$cleanup = $this->api->requestsTo( 'cleanup.php' );
		$this->assertSame( array( $url ), $cleanup[0]['request']['urllist'] );
		$this->assertSame( $id, $cleanup[0]['request']['item_id'] );
		$reducer = $this->api->lastRequest();
		$this->assertSame( 'https://api.shortpixel.com/v2/reducer.php', $reducer['url'] );
		$this->assertSame( self::API_KEY, $reducer['request']['key'] );
		$this->assertSame( $id, $reducer['request']['item_id'] );
		$this->assertSame( array( $url ), $reducer['request']['urllist'], 'Raw URL on the keyed path.' );
	}

	public function test_with_a_saved_key_the_result_is_applied_like_on_the_free_path() {
		list( $id, $path ) = $this->image();
		$this->saveKeyThroughTheEndpoint();
		$result = $this->preview( $id );

		list( $controller, $exit ) = $this->apply( $id, $result->key );

		$this->assertSame( 'success', $exit->template );
		$this->assertTrue( $controller->successCalled );
		$this->assertSame( $this->fixtureMd5(), md5_file( $path ) );
	}

	public function test_with_an_expired_plan_at_request_time_the_preview_reports_plan_expired_without_reducer_call() {
		list( $id ) = $this->image();
		$this->saveKeyThroughTheEndpoint();
		$this->api->setKnob( 'unlimited', 'false' );

		$result = $this->preview( $id );

		$this->assertFalse( $result->success );
		$this->assertTrue( $result->plan_expired );
		$this->assertStringContainsString( 'Unlimited plan is no longer active', $result->message );
		$this->assertSame( array( '/v2/api-status.php', '/v2/api-status.php' ), $this->mockPaths(), 'No purge, no reducer.' );
		$this->assertSame( self::API_KEY, get_option( ApiKeyManager::OPTION_NAME ), 'The key is kept.' );
		$this->assertSame( 'transparent', get_option( 'enable_media_replace' )['bg_type'], 'The choice is saved before the plan check.' );
	}

	// ---------------------------------------------------------------------
	// Step 3: the replacement.
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
		$this->assertSame( array(), $meta['sizes'], 'A 50x50 image gets no sub-size.' );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $id ) );
		foreach ( $oldSizes as $size => $file ) {
			$this->assertFileDoesNotExist( $file, "The old $size file is removed." );
		}
	}

	public function test_apply_updates_post_modified_and_keeps_post_date() {
		list( $id ) = $this->image();
		$this->ageDates( $id );
		$result = $this->preview( $id );
		$start  = current_time( 'timestamp' );

		$this->apply( $id, $result->key );

		$post = get_post( $id );
		$this->assertSame( '2001-02-03 04:05:06', $post->post_date );
		$this->assertNotSame( '2001-02-03 04:05:06', $post->post_modified );
		$this->assertGreaterThanOrEqual( $start - 60, strtotime( $post->post_modified ) );
		$this->assertLessThanOrEqual( $start + 60, strtotime( $post->post_modified ) );
	}

	public function test_apply_with_an_expired_key_records_download_failed_and_leaves_the_attachment() {
		list( $id, $path ) = $this->image();
		$before            = md5_file( $path );
		$meta              = wp_get_attachment_metadata( $id );
		$result            = $this->preview( $id );
		delete_transient( 'emr_' . $result->key ); // the 30-minute lifetime has passed.

		list( $controller, $exit ) = $this->apply( $id, $result->key );

		$this->assertSame( 'error', $exit->template );
		$this->assertSame( ViewController::ERROR_DOWNLOAD_FAILED, $controller->lastError );
		$this->assertFalse( $controller->successCalled );
		$this->assertSame( array(), $this->api->requestsTo( '/f/' ), 'Nothing to download.' );
		$this->assertSame( $before, md5_file( $path ) );
		$this->assertSame( $meta, wp_get_attachment_metadata( $id ) );
	}

	/**
	 * Api::handleDownload() documents that the transient is not deleted
	 * after use: the same key applies again within its 30 minutes.
	 */
	public function test_a_key_applied_twice_downloads_and_replaces_twice() {
		list( $id, $path ) = $this->image();
		$result            = $this->preview( $id );
		$this->apply( $id, $result->key );

		list( $controller, $exit ) = $this->apply( $id, $result->key );

		$this->assertSame( 'success', $exit->template );
		$this->assertTrue( $controller->successCalled );
		$this->assertCount( 2, $this->api->requestsTo( '/f/' ) );
		$this->assertIsString( get_transient( 'emr_' . $result->key ), 'Still stored.' );
		$this->assertSame( $this->fixtureMd5(), md5_file( $path ) );
	}

	/**
	 * loadPost() documents that the key is not bound to the attachment: a
	 * result previewed for one attachment can be applied to another.
	 */
	public function test_a_key_previewed_for_one_attachment_applies_to_another() {
		list( $first ) = $this->image();
		list( $other, $otherPath ) = $this->image();
		$before = md5_file( $otherPath );
		$result = $this->preview( $first );

		list( $controller, $exit ) = $this->apply( $other, $result->key );

		$this->assertSame( 'success', $exit->template );
		$this->assertNotSame( $before, md5_file( $otherPath ) );
		$this->assertSame( $this->fixtureMd5(), md5_file( $otherPath ) );
	}

	// ---------------------------------------------------------------------
	// Author restriction and the feature filter.
	// ---------------------------------------------------------------------

	public function test_an_author_gets_no_remove_background_entry_point_and_loadpost_refuses() {
		list( , $id, $path ) = $this->authorWithImage();
		$before              = md5_file( $path );
		$post                = get_post( $id );
		set_transient( 'emr_authorkey', $this->api->stashImage(), 60 );
		$this->keys[] = 'authorkey';

		$actions = emr()->add_media_action( array(), $post );
		ob_start();
		emr()->replace_meta_box( $post );
		$box    = ob_get_clean();
		$fields = emr()->attachment_editor( array(), $post );
		list( $controller, $exit ) = $this->apply( $id, 'authorkey' );

		$this->assertFalse( emr()->useFeature( 'background' ) );
		$this->assertFalse( emr()->useFeature( 'upscale' ), 'The author restriction covers upscale as well.' );
		$this->assertSame( array( 'media_replace' ), array_keys( $actions ) );
		$this->assertStringContainsString( 'Upload a new file', $box );
		$this->assertStringNotContainsString( 'Remove background', $box );
		$this->assertSame( array( 'enable-media-replace' ), array_keys( $fields ) );
		$this->assertSame( 'error', $exit->template );
		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $controller->lastError );
		$this->assertSame( array(), $this->api->requestsTo( '/f/' ) );
		$this->assertSame( $before, md5_file( $path ) );
	}

	/**
	 * The AJAX endpoint is part of the remove-background feature, but
	 * neither Ajax::remove_background() nor Api::request() consults
	 * useFeature('background'): an author-restricted user, for whom
	 * useFeature('background') is false and every UI entry point is
	 * hidden, still gets the remote request sent (spending the free quota
	 * or the site's Unlimited key) and a usable result key back; only the
	 * apply step (loadPost()) refuses. The endpoint should answer an error
	 * without calling the API when useFeature('background') is false.
	 *
	 * How to recognise the fix: the response has `success` false, no
	 * reducer request is logged, and the assertions on the successful
	 * result fail.
	 */
	public function test_the_ajax_preview_serves_an_author_restricted_user_pinned_for_deferred_fix() {
		list( , $id ) = $this->authorWithImage();
		$this->assertFalse( emr()->useFeature( 'background' ), 'Precondition: the feature is off for this user.' );

		$result = $this->preview( $id );

		$this->assertTrue( $result->success, 'Pinned: the request is served.' );
		$this->assertCount( 1, $this->api->requestsTo( 'free-reducer.php' ) );
		$this->assertIsString( get_transient( 'emr_' . $result->key ) );
	}

	/**
	 * Not driven through route(): with the feature off, route() sends
	 * `emr_prepare_remove` and `do_background_replace` to its default
	 * branch, which calls exit(). The upscale entry points stay: they are
	 * governed by `emr/feature/upscale`.
	 */
	public function test_with_the_feature_filter_off_no_entry_point_offers_remove_background() {
		list( $id ) = $this->image();
		$post       = get_post( $id );
		$this->addHook( 'emr/feature/background', '__return_false' );
		emr()->adminInit();

		$fields  = emr()->attachment_editor( array(), $post );
		$actions = emr()->add_media_action( array(), $post );

		$this->assertFalse( emr()->useFeature( 'background' ) );
		$this->assertTrue( emr()->useFeature( 'upscale' ) );
		$this->assertFalse( emr()->uiHelper()->isBackgroundRemovable( $post ) );
		$this->assertSame( array( 'enable-media-replace', 'emr-upscale' ), array_keys( $fields ) );
		$this->assertSame( array( 'media_replace', 'upscale_image' ), array_keys( $actions ) );
	}

	/**
	 * Same gap as the author case: `emr/feature/background` false switches
	 * the UI off, but the registered AJAX endpoint still sends the image
	 * to the reducer and stores a result key. The endpoint should refuse
	 * without calling the API when the feature is disabled.
	 *
	 * How to recognise the fix: `success` is false and no reducer request
	 * is logged.
	 */
	public function test_the_ajax_preview_runs_with_the_feature_filter_off_pinned_for_deferred_fix() {
		list( $id ) = $this->image();
		$this->addHook( 'emr/feature/background', '__return_false' );
		emr()->adminInit();
		$this->assertFalse( emr()->useFeature( 'background' ), 'Precondition: the feature is off.' );

		$result = $this->preview( $id );

		$this->assertTrue( $result->success, 'Pinned: the request is served.' );
		$this->assertCount( 1, $this->api->requestsTo( 'free-reducer.php' ) );
	}
}
