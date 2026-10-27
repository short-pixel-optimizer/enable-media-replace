<?php
/**
 * Tests for EnableMediaReplace\UIHelper (classes/uihelper.php).
 *
 * The singleton holds per-request preview state (preview size, full source
 * size), so it is reset by reflection before and after every test. Images
 * are uploaded from tests/fixtures/ or generated with GD into the temp
 * directory and uploaded from there; every attachment is deleted with its
 * files in tear_down(). Placeholder HTML is asserted on its structure
 * (classes, attributes, text), not on the exact markup.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\UIHelper;
use EnableMediaReplace\Notices\NoticeController;

class UIHelperTest extends WP_UnitTestCase {

	/**
	 * Attachment IDs created by the test, deleted in tear_down().
	 *
	 * @var int[]
	 */
	private $attachments = array();

	/**
	 * Temporary files created by the test, deleted in tear_down().
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Snapshot of $_REQUEST.
	 *
	 * @var array
	 */
	private $saved_request = array();

	/**
	 * Snapshot of the plugin's private feature flags.
	 *
	 * @var array
	 */
	private $saved_features = array();

	/**
	 * Filters added by the test, as array( hook, callback ).
	 *
	 * @var array
	 */
	private $added_filters = array();

	public function set_up() {
		parent::set_up();
		$this->reset_ui_helper();
		$this->reset_notice_controller();
		$this->saved_request  = $_REQUEST;
		$this->saved_features = $this->features();
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1] );
		}
		$this->added_filters = array();
		remove_image_size( 'emr_test_preview' );
		remove_image_size( 'emr_test_zero' );
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachments = array();
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();
		$_REQUEST         = $this->saved_request;
		$this->set_features( $this->saved_features );
		wp_set_current_user( 0 );
		$this->reset_notice_controller();
		$this->reset_ui_helper();
		parent::tear_down();
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** Sets the private static singleton back to null. */
	private function reset_ui_helper() {
		$prop = new ReflectionProperty( UIHelper::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/** Clears the static state of the notices module. */
	private function reset_notice_controller() {
		$values = array(
			'instance'   => null,
			'notices'    => array(),
			'newNotices' => array(),
		);
		foreach ( $values as $name => $value ) {
			$prop = new ReflectionProperty( NoticeController::class, $name );
			$prop->setAccessible( true );
			$prop->setValue( null, $value );
		}
	}

	/** Returns the plugin's private feature flags. */
	private function features() {
		$prop = new ReflectionProperty( get_class( emr() ), 'features' );
		$prop->setAccessible( true );
		return $prop->getValue( emr() );
	}

	/**
	 * Replaces the plugin's private feature flags.
	 *
	 * @param array $features Flags.
	 */
	private function set_features( $features ) {
		$prop = new ReflectionProperty( get_class( emr() ), 'features' );
		$prop->setAccessible( true );
		$prop->setValue( emr(), $features );
	}

	/**
	 * Sets the background feature flag, as adminInit() would.
	 *
	 * @param bool $enabled Flag value.
	 */
	private function set_background_feature( $enabled ) {
		$this->set_features(
			array(
				'replace'       => true,
				'background'    => $enabled,
				'remote_notice' => true,
			)
		);
	}

	/**
	 * Reads a protected property of a UIHelper.
	 *
	 * @param UIHelper $helper Instance.
	 * @param string   $name   Property.
	 * @return mixed
	 */
	private function prop( UIHelper $helper, $name ) {
		$prop = new ReflectionProperty( UIHelper::class, $name );
		$prop->setAccessible( true );
		return $prop->getValue( $helper );
	}

	/**
	 * Writes a protected property of a UIHelper.
	 *
	 * @param UIHelper $helper Instance.
	 * @param string   $name   Property.
	 * @param mixed    $value  Value.
	 */
	private function set_prop( UIHelper $helper, $name, $value ) {
		$prop = new ReflectionProperty( UIHelper::class, $name );
		$prop->setAccessible( true );
		$prop->setValue( $helper, $value );
	}

	/**
	 * Calls the protected getImageSizes().
	 *
	 * @param int    $attach_id Attachment ID.
	 * @param string $size      Size name.
	 * @return array|false
	 */
	private function image_sizes( $attach_id, $size ) {
		$helper = UIHelper::getInstance();
		$method = new ReflectionMethod( UIHelper::class, 'getImageSizes' );
		$method->setAccessible( true );
		return $method->invoke( $helper, $attach_id, $size );
	}

	/**
	 * Adds a filter that tear_down() removes.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 */
	private function filter( $hook, $callback ) {
		add_filter( $hook, $callback );
		$this->added_filters[] = array( $hook, $callback );
	}

	/**
	 * Uploads a copy of a fixture as an attachment.
	 *
	 * @param string $fixture File name under tests/fixtures/.
	 * @return int Attachment ID.
	 */
	private function upload_fixture( $fixture ) {
		return $this->upload( dirname( __DIR__ ) . '/fixtures/' . $fixture );
	}

	/**
	 * Uploads a file as an attachment.
	 *
	 * @param string $path Absolute path; the file is copied, not moved.
	 * @return int Attachment ID.
	 */
	private function upload( $path ) {
		$id = self::factory()->attachment->create_upload_object( $path );
		$this->assertIsInt( $id, 'Precondition: upload of ' . basename( $path ) . ' succeeded.' );
		$this->attachments[] = $id;
		return $id;
	}

	/**
	 * Generates a JPEG of the given size with GD and uploads it.
	 *
	 * @param int  $width          Width in pixels.
	 * @param int  $height         Height in pixels.
	 * @param bool $with_subsizes  Whether WordPress generates the registered sizes.
	 * @return int Attachment ID.
	 */
	private function upload_generated( $width, $height, $with_subsizes = false ) {
		$path = get_temp_dir() . 'emr-uihelper-' . $width . 'x' . $height . '.jpg';
		$image = imagecreatetruecolor( $width, $height );
		imagejpeg( $image, $path );
		$this->temp_files[] = $path;

		if ( ! $with_subsizes ) {
			$this->filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		}
		$id = $this->upload( $path );
		if ( ! $with_subsizes ) {
			remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		}
		return $id;
	}

	/**
	 * Returns a FileModel of an attachment's file.
	 *
	 * @param int $attach_id Attachment ID.
	 * @return object
	 */
	private function file_of( $attach_id ) {
		return emr()->filesystem()->getFile( get_attached_file( $attach_id ) );
	}

	/**
	 * Parses the query string of a URL.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	private function query_of( $url ) {
		$args = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return $args;
	}

	/* ------------------------------------------------------------------
	 * Singleton and URLs
	 * ------------------------------------------------------------------ */

	public function test_get_instance_returns_the_singleton_used_by_emr() {
		$helper = UIHelper::getInstance();

		$this->assertInstanceOf( UIHelper::class, $helper );
		$this->assertSame( $helper, UIHelper::getInstance() );
		$this->assertSame( $helper, emr()->uiHelper() );
	}

	public function test_get_form_url_defaults_to_the_upload_action() {
		$url  = UIHelper::getInstance()->getFormUrl( 37 );
		$args = $this->query_of( $url );

		$this->assertIsString( $url );
		$this->assertStringStartsWith( admin_url( 'upload.php' ) . '?', $url );
		$this->assertSame( 'enable-media-replace/enable-media-replace.php', $args['page'] );
		$this->assertSame( 'media_replace_upload', $args['action'] );
		$this->assertSame( '37', $args['attachment_id'] );
		$this->assertArrayNotHasKey( '_wpnonce', $args );
		$this->assertArrayNotHasKey( 'SHORTPIXEL_DEBUG', $args );
	}

	public function test_get_form_url_uses_a_custom_action() {
		$args = $this->query_of( UIHelper::getInstance()->getFormUrl( 38, 'do_background_replace' ) );

		$this->assertSame( 'do_background_replace', $args['action'] );
		$this->assertSame( '38', $args['attachment_id'] );
	}

	public function test_get_form_url_carries_a_numeric_debug_level_as_int() {
		$_REQUEST['SHORTPIXEL_DEBUG'] = '2.9';

		$args = $this->query_of( UIHelper::getInstance()->getFormUrl( 39 ) );

		$this->assertSame( '2', $args['SHORTPIXEL_DEBUG'] );
	}

	public function test_get_form_url_turns_a_non_numeric_debug_value_into_3() {
		$_REQUEST['SHORTPIXEL_DEBUG'] = 'true"><script>';

		$url  = UIHelper::getInstance()->getFormUrl( 40 );
		$args = $this->query_of( $url );

		$this->assertSame( '3', $args['SHORTPIXEL_DEBUG'] );
		$this->assertStringNotContainsString( 'script', $url );
	}

	public function test_get_succes_redirect_points_at_the_edit_screen_with_emr_replaced() {
		$url  = UIHelper::getInstance()->getSuccesRedirect( 41 );
		$args = $this->query_of( $url );

		$this->assertStringStartsWith( admin_url( 'post.php' ) . '?', $url );
		$this->assertSame( 'edit', $args['action'] );
		$this->assertSame( '41', $args['post'] );
		$this->assertSame( '1', $args['emr_replaced'] );
		$this->assertArrayNotHasKey( 'SHORTPIXEL_DEBUG', $args );
	}

	public function test_get_succes_redirect_carries_the_debug_level() {
		$_REQUEST['SHORTPIXEL_DEBUG'] = '4';
		$this->assertSame( '4', $this->query_of( UIHelper::getInstance()->getSuccesRedirect( 42 ) )['SHORTPIXEL_DEBUG'] );

		$_REQUEST['SHORTPIXEL_DEBUG'] = 'on';
		$this->assertSame( '3', $this->query_of( UIHelper::getInstance()->getSuccesRedirect( 42 ) )['SHORTPIXEL_DEBUG'] );
	}

	public function test_get_succes_redirect_passes_through_the_emr_returnurl_filter() {
		$received = array();
		$this->filter(
			'emr_returnurl',
			function ( $url ) use ( &$received ) {
				$received[] = $url;
				return 'https://example.org/emr-custom-return';
			}
		);

		$url = UIHelper::getInstance()->getSuccesRedirect( 43 );

		$this->assertSame( 'https://example.org/emr-custom-return', $url );
		$this->assertCount( 1, $received );
		$this->assertSame( '43', $this->query_of( $received[0] )['post'] );
	}

	public function test_get_background_remove_redirect_points_at_the_prepare_screen() {
		$_REQUEST['SHORTPIXEL_DEBUG'] = '4';

		$url  = UIHelper::getInstance()->getBackgroundRemoveRedirect( 44 );
		$args = $this->query_of( $url );

		$this->assertStringStartsWith( admin_url( 'upload.php' ) . '?', $url );
		$this->assertSame( 'enable-media-replace/enable-media-replace.php', $args['page'] );
		$this->assertSame( 'emr_prepare_remove', $args['action'] );
		$this->assertSame( '44', $args['attachment_id'] );
		$this->assertArrayNotHasKey( '_wpnonce', $args );
		$this->assertArrayNotHasKey( 'SHORTPIXEL_DEBUG', $args );
	}

	public function test_get_failed_redirect_carries_a_valid_media_replace_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$url  = UIHelper::getInstance()->getFailedRedirect( 45 );
		$args = $this->query_of( $url );

		$this->assertStringStartsWith( admin_url( 'upload.php' ) . '?', $url );
		$this->assertSame( 'media_replace', $args['action'] );
		$this->assertSame( '45', $args['attachment_id'] );
		$this->assertArrayHasKey( '_wpnonce', $args );
		$this->assertSame( 1, wp_verify_nonce( $args['_wpnonce'], 'media_replace' ) );
		$this->assertFalse( wp_verify_nonce( $args['_wpnonce'], 'media_replace_upload' ), 'The nonce is bound to the media_replace action.' );
	}

	public function test_get_failed_redirect_passes_through_the_emr_returnurl_failed_filter() {
		$received = array();
		$this->filter(
			'emr_returnurl_failed',
			function ( $url ) use ( &$received ) {
				$received[] = $url;
				return 'https://example.org/emr-failed';
			}
		);

		$this->assertSame( 'https://example.org/emr-failed', UIHelper::getInstance()->getFailedRedirect( 46 ) );
		$this->assertCount( 1, $received );
		$this->assertSame( '46', $this->query_of( $received[0] )['attachment_id'] );
	}

	/* ------------------------------------------------------------------
	 * Author restriction
	 * ------------------------------------------------------------------ */

	public function test_users_with_edit_others_posts_are_not_restricted() {
		foreach ( array( 'administrator', 'editor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

			$this->assertFalse( UIHelper::getInstance()->is_author_restricted(), $role . ' must not be restricted.' );
		}
	}

	public function test_users_without_edit_others_posts_are_restricted() {
		foreach ( array( 'author', 'contributor', 'subscriber' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

			$this->assertTrue( UIHelper::getInstance()->is_author_restricted(), $role . ' must be restricted.' );
		}
	}

	public function test_a_logged_out_visitor_is_restricted() {
		$this->assertSame( 0, get_current_user_id(), 'Precondition: logged out.' );

		$this->assertTrue( UIHelper::getInstance()->is_author_restricted() );
	}

	/* ------------------------------------------------------------------
	 * Preview size lookup
	 * ------------------------------------------------------------------ */

	public function test_find_image_size_by_max_picks_the_widest_core_size_that_fits() {
		$result = UIHelper::getInstance()->findImageSizeByMax( 600 );

		$this->assertCount( 3, $result );
		$this->assertSame( 'medium', $result[0] );
		$this->assertEquals( 300, $result[1] );
		$this->assertEquals( 300, $result[2] );
	}

	public function test_find_image_size_by_max_accepts_an_exact_width_match() {
		$result = UIHelper::getInstance()->findImageSizeByMax( 150 );

		$this->assertSame( 'thumbnail', $result[0] );
		$this->assertEquals( 150, $result[1] );
	}

	public function test_find_image_size_by_max_picks_a_custom_registered_size() {
		add_image_size( 'emr_test_preview', 555, 444 );

		$this->assertSame( array( 'emr_test_preview', 555, 444 ), UIHelper::getInstance()->findImageSizeByMax( 600 ) );
	}

	public function test_find_image_size_by_max_returns_empty_when_nothing_fits() {
		add_image_size( 'emr_test_zero', 0, 500 );

		$this->assertSame( array( '', 0, 0 ), UIHelper::getInstance()->findImageSizeByMax( 100 ), 'A zero-width size never matches.' );
	}

	public function test_set_preview_sizes_stores_the_size_found_for_600_pixels() {
		add_image_size( 'emr_test_preview', 555, 444 );
		$helper = UIHelper::getInstance();
		$this->assertSame( '', $this->prop( $helper, 'preview_size' ), 'Precondition: empty until set.' );

		$this->assertNull( $helper->setPreviewSizes() );

		$this->assertSame( 'emr_test_preview', $this->prop( $helper, 'preview_size' ) );
		$this->assertSame( 555, $this->prop( $helper, 'preview_width' ) );
		$this->assertSame( 444, $this->prop( $helper, 'preview_height' ) );
	}

	/* ------------------------------------------------------------------
	 * Source sizes and getImageSizes()
	 * ------------------------------------------------------------------ */

	public function test_set_source_sizes_stores_the_full_dimensions() {
		$id     = $this->upload_fixture( 'codeispoetry.png' );
		$helper = UIHelper::getInstance();

		$helper->setSourceSizes( $id );

		$this->assertSame( 190, $this->prop( $helper, 'full_width' ) );
		$this->assertSame( 15, $this->prop( $helper, 'full_height' ) );
	}

	public function test_set_source_sizes_leaves_the_sizes_untouched_without_image_data() {
		$helper = UIHelper::getInstance();
		$this->set_prop( $helper, 'full_width', 77 );
		$this->set_prop( $helper, 'full_height', 66 );

		$helper->setSourceSizes( 987654 );

		$this->assertSame( 77, $this->prop( $helper, 'full_width' ) );
		$this->assertSame( 66, $this->prop( $helper, 'full_height' ) );
	}

	public function test_get_image_sizes_reads_a_registered_size_from_the_metadata() {
		$id   = $this->upload_generated( 1200, 800, true );
		$data = $this->image_sizes( $id, 'medium' );

		$this->assertIsArray( $data );
		$this->assertSame( 300, $data[1] );
		$this->assertSame( 200, $data[2] );
		$this->assertStringEndsWith( '-300x200.jpg', $data[0] );
	}

	public function test_get_image_sizes_falls_back_to_the_full_image() {
		$id   = $this->upload_fixture( 'test-image.jpg' );
		$data = $this->image_sizes( $id, 'full' );

		$this->assertIsArray( $data );
		$this->assertSame( wp_get_attachment_url( $id ), $data[0] );
		$this->assertSame( 50, $data[1] );
		$this->assertSame( 50, $data[2] );

		$meta = wp_get_attachment_metadata( $id );
		$this->assertArrayNotHasKey( 'medium', $meta['sizes'], 'Precondition: 50x50 has no medium size.' );
		$medium = $this->image_sizes( $id, 'medium' );
		$this->assertSame( 50, $medium[1], 'A size missing from the metadata falls back to the full image.' );
		$this->assertSame( 50, $medium[2] );
	}

	public function test_get_image_sizes_returns_false_for_a_missing_attachment() {
		$this->assertFalse( $this->image_sizes( 987654, 'full' ) );
	}

	/**
	 * UIHelper::getImageSizes() decides whether to read an SVG's size from the
	 * file (fixSVGSize()) by testing the MIME type from wp_get_image_mime().
	 * That function detects types with exif_imagetype() / getimagesize(),
	 * which do not recognise SVG before PHP 8.5 and return false, so on those
	 * PHP versions the SVG branch never runs: an SVG attachment comes back as
	 * 0 x 0 although its root element declares 321 x 123. PHP 8.5's
	 * getimagesize() recognises SVG, so there the branch runs and the
	 * dimensions are correct; this test asserts the correct values on that
	 * version. The correct behaviour on every version is to take the MIME
	 * type from the attachment (get_post_mime_type() or wp_check_filetype())
	 * so that fixSVGSize() fills in 321 and 123.
	 *
	 * How to recognise the fix: on PHP below 8.5 $data[1] and $data[2] become
	 * 321 and 123 and the assertSame( 0, ... ) assertions fail.
	 */
	public function test_get_image_sizes_ignores_the_svg_dimensions_pinned_for_deferred_fix() {
		$this->filter(
			'upload_mimes',
			function ( $mimes ) {
				$mimes['svg'] = 'image/svg+xml';
				return $mimes;
			}
		);
		$id = $this->upload_fixture( 'test-vector.svg' );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ), 'Precondition: uploaded as SVG.' );
		$detected = wp_get_image_mime( get_attached_file( $id ) );

		$data = $this->image_sizes( $id, 'full' );

		$this->assertIsArray( $data );
		if ( false === $detected ) {
			// PHP below 8.5: getimagesize() does not know SVG, the branch is skipped.
			$this->assertSame( 0, $data[1] );
			$this->assertSame( 0, $data[2] );
		} else {
			$this->assertSame( 'image/svg+xml', $detected, 'Precondition: PHP detects SVG.' );
			$this->assertSame( 321, $data[1] );
			$this->assertSame( 123, $data[2] );
		}
	}

	/* ------------------------------------------------------------------
	 * Preview boxes
	 * ------------------------------------------------------------------ */

	public function test_get_preview_image_renders_a_found_image() {
		$id     = $this->upload_fixture( 'test-image.jpg' );
		$helper = UIHelper::getInstance();

		$html = $helper->getPreviewImage( $id, $this->file_of( $id ) );

		$this->assertMatchesRegularExpression( "/<div class='image_placeholder is_image\s*' data-filetype=\"image\/jpeg\"/", $html );
		$this->assertStringContainsString( "<img src='" . esc_url( wp_get_attachment_url( $id ) ) . "'", $html );
		$this->assertStringContainsString( "<span class='upload-title'>Current</span>", $html );
		$this->assertStringContainsString( "<div class='image_size'>2 KB</div>", $html );
		$this->assertStringContainsString( "width='150' height='150'", $html, 'The 50x50 image is raised to 150.' );
		$this->assertStringContainsString( 'width:150px; height:150px', $html );
		$this->assertSame( 50, $this->prop( $helper, 'preview_width' ), 'The stored preview size is the unscaled one.' );
		$this->assertSame( 50, $this->prop( $helper, 'preview_height' ) );
	}

	public function test_get_preview_image_scales_a_wide_image_to_600_pixels() {
		$id     = $this->upload_generated( 1200, 800 );
		$helper = UIHelper::getInstance();

		$html = $helper->getPreviewImage( $id, $this->file_of( $id ) );

		$this->assertStringContainsString( "width='600' height='400'", $html );
		$this->assertStringContainsString( 'width:600px; height:400px', $html );
		$this->assertSame( 600.0, $this->prop( $helper, 'preview_width' ) );
		$this->assertSame( 400.0, $this->prop( $helper, 'preview_height' ) );
	}

	public function test_get_preview_image_scales_by_the_larger_ratio() {
		$id     = $this->upload_generated( 900, 1500 );
		$helper = UIHelper::getInstance();

		$html = $helper->getPreviewImage( $id, $this->file_of( $id ) );

		$this->assertStringContainsString( "width='360' height='600'", $html );
		$this->assertSame( 360.0, $this->prop( $helper, 'preview_width' ) );
		$this->assertSame( 600.0, $this->prop( $helper, 'preview_height' ) );
	}

	public function test_get_preview_image_does_not_scale_exactly_600_pixels() {
		$id     = $this->upload_generated( 600, 300 );
		$helper = UIHelper::getInstance();

		$html = $helper->getPreviewImage( $id, $this->file_of( $id ) );

		$this->assertStringContainsString( "width='600' height='300'", $html );
		$this->assertSame( 600, $this->prop( $helper, 'preview_width' ) );
	}

	public function test_get_preview_image_uses_the_preview_size() {
		$id     = $this->upload_generated( 1200, 800, true );
		$helper = UIHelper::getInstance();
		$helper->setPreviewSizes();
		$this->assertSame( 'medium', $this->prop( $helper, 'preview_size' ), 'Precondition: medium is the preview size.' );

		$html = $helper->getPreviewImage( $id, $this->file_of( $id ) );

		$this->assertStringContainsString( '-300x200.jpg', $html );
		$this->assertStringContainsString( "width='300' height='200'", $html );
	}

	public function test_get_preview_image_args_override_the_defaults() {
		$id = $this->upload_fixture( 'test-image.jpg' );

		$html = UIHelper::getInstance()->getPreviewImage( $id, $this->file_of( $id ), array( 'remove_bg_ui' => true ) );

		$this->assertStringContainsString( "<span class='upload-title'>Preview</span>", $html );
		$this->assertStringContainsString( 'id="overlay"', $html );
	}

	public function test_get_preview_image_for_attach_id_minus_one_is_an_empty_placeholder() {
		$file = emr()->filesystem()->getFile( get_temp_dir() . 'emr-missing-file.jpg' );

		$html = UIHelper::getInstance()->getPreviewImage( -1, $file );

		$this->assertMatchesRegularExpression( "/<div class='image_placeholder not_image\s*'\s+style=/", $html );
		$this->assertStringContainsString( "<div class='dashicons '>", $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'data-filetype', $html );
	}

	public function test_get_preview_image_with_a_missing_file_shows_the_error_icon() {
		$id   = $this->upload_fixture( 'test-image.jpg' );
		$file = emr()->filesystem()->getFile( get_temp_dir() . 'emr-missing-file.jpg' );
		$this->assertFalse( $file->exists(), 'Precondition: the file does not exist.' );

		$html = UIHelper::getInstance()->getPreviewImage( $id, $file );

		$this->assertStringContainsString( 'not_image', $html );
		$this->assertStringContainsString( "<div class='dashicons dashicons-no'>", $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_get_preview_image_falls_back_to_the_file_preview_for_a_pdf() {
		$id   = self::factory()->attachment->create_object(
			array(
				'file'           => 'emr-document.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);
		$file = emr()->filesystem()->getFile( get_temp_dir() . 'emr-document.pdf' );

		$html = UIHelper::getInstance()->getPreviewImage( $id, $file );

		$this->assertStringContainsString( 'is_document', $html );
		$this->assertStringContainsString( 'data-filetype="application/pdf"', $html );
		$this->assertStringContainsString( 'width:300px; height:300px', $html );
		$this->assertStringContainsString( 'dashicons-media-document', $html );
	}

	public function test_get_preview_error_is_a_non_image_box_with_the_error_icon() {
		$helper = UIHelper::getInstance();
		$this->set_prop( $helper, 'preview_width', 300 );
		$this->set_prop( $helper, 'preview_height', 100 );

		$html = $helper->getPreviewError( -1 );

		$this->assertStringContainsString( 'not_image', $html );
		$this->assertStringContainsString( "<div class='dashicons dashicons-no'>", $html );
		$this->assertStringContainsString( 'width:300px; height:150px', $html );
	}

	public function test_get_preview_file_shows_name_mime_and_size() {
		$id     = $this->upload_fixture( 'test-image.jpg' );
		$helper = UIHelper::getInstance();
		$helper->setSourceSizes( $id );
		$file = $this->file_of( $id );

		$html = $helper->getPreviewFile( $id, $file );

		$this->assertStringContainsString( 'not_image is_document', $html );
		$this->assertStringContainsString( 'data-filetype="image/jpeg"', $html );
		$this->assertStringContainsString( "<span class='textlayer'>" . $file->getFileName() . '</span>', $html );
		$this->assertStringContainsString( "<div class='image_size'>2 KB</div>", $html );
		$this->assertStringContainsString( 'width:300px; height:300px', $html );
	}

	public function test_get_preview_file_falls_back_to_the_post_mime_type() {
		$path = get_temp_dir() . 'emr-notes.emrtest';
		file_put_contents( $path, 'not a known file type' );
		$this->temp_files[] = $path;
		$id   = self::factory()->attachment->create_object(
			array(
				'file'           => $path,
				'post_mime_type' => 'application/x-emr-test',
			)
		);
		$file = emr()->filesystem()->getFile( $path );
		$this->assertFalse( $file->getMime(), 'Precondition: the server cannot detect the type.' );

		$html = UIHelper::getInstance()->getPreviewFile( $id, $file );

		$this->assertStringContainsString( 'data-filetype="application/x-emr-test"', $html );
	}

	public function test_get_preview_file_as_upload_zone() {
		$file = emr()->filesystem()->getFile( get_temp_dir() . 'emr-missing-file.jpg' );

		$html = UIHelper::getInstance()->getPreviewFile( -1, $file, array( 'is_upload' => true ) );

		$this->assertStringContainsString( 'upload-file-action', $html );
		$this->assertStringContainsString( '<input type="file" name="userfile" id="upload-file" />', $html );
		$this->assertStringContainsString( "<span class='upload-title'>New</span>", $html );
	}

	/* ------------------------------------------------------------------
	 * getPlaceHolder()
	 * ------------------------------------------------------------------ */

	public function test_placeholder_defaults() {
		$html = UIHelper::getInstance()->getPlaceHolder( array() );

		$this->assertIsString( $html );
		$this->assertMatchesRegularExpression( "/^<div class='image_placeholder is_image\s*'\s+style='width:150px; height:150px'>/", $html );
		$this->assertStringContainsString( "<span class='upload-title'>Current</span>", $html );
		$this->assertStringContainsString( "<div class='dashicons dashicons-media-document'>", $html );
		$this->assertStringContainsString( "<div class='image_size'></div>", $html );
		$this->assertStringNotContainsString( 'data-filetype', $html );
		$this->assertStringNotContainsString( 'textlayer', $html );
		$this->assertStringNotContainsString( 'overlay', $html );
		$this->assertStringEndsWith( '</div>', $html );
	}

	public function test_placeholder_enforces_a_minimum_of_150_pixels() {
		$helper = UIHelper::getInstance();

		$this->assertStringContainsString( 'width:150px; height:150px', $helper->getPlaceHolder( array( 'width' => 10, 'height' => 149 ) ) );
		$this->assertStringContainsString( 'width:400px; height:151px', $helper->getPlaceHolder( array( 'width' => 400, 'height' => 151 ) ) );
	}

	public function test_placeholder_classes_follow_the_flags() {
		$helper = UIHelper::getInstance();

		$this->assertStringContainsString( "image_placeholder not_image'", $helper->getPlaceHolder( array( 'is_image' => false ) ) );
		$this->assertStringContainsString( "image_placeholder is_image is_document'", $helper->getPlaceHolder( array( 'is_document' => true ) ) );
		$this->assertStringContainsString( "image_placeholder not_image is_document upload-file-action'", $helper->getPlaceHolder( array( 'is_image' => false, 'is_document' => true, 'is_upload' => true ) ) );
	}

	public function test_placeholder_upload_interface_needs_is_upload_strictly_true() {
		$html = UIHelper::getInstance()->getPlaceHolder( array( 'is_upload' => 1, 'image' => '<img class="emr-probe" />' ) );

		$this->assertStringContainsString( 'upload-file-action', $html, 'The class uses a loose comparison.' );
		$this->assertStringNotContainsString( 'name="userfile"', $html, 'The upload interface uses a strict comparison.' );
		$this->assertStringContainsString( '<img class="emr-probe" />', $html );
	}

	public function test_placeholder_upload_interface_replaces_title_and_image() {
		$html = UIHelper::getInstance()->getPlaceHolder( array( 'is_upload' => true, 'image' => '<img class="emr-probe" />' ) );

		$this->assertStringContainsString( "<span class='upload-title'>New</span>", $html );
		$this->assertStringContainsString( 'name="userfile"', $html );
		$this->assertStringContainsString( 'drag-and-drop-title', $html );
		$this->assertStringNotContainsString( 'emr-probe', $html );
		$this->assertStringNotContainsString( 'Current', $html );
	}

	public function test_placeholder_writes_the_mime_type_to_data_filetype() {
		$html = UIHelper::getInstance()->getPlaceHolder( array( 'mime_type' => 'image/png' ) );

		$this->assertStringContainsString( 'data-filetype="image/png"', $html );
	}

	public function test_placeholder_shows_the_layer_only_when_full_sizes_are_known() {
		$helper = UIHelper::getInstance();
		$this->assertStringNotContainsString( 'textlayer', $helper->getPlaceHolder( array( 'layer' => 'emr-layer' ) ) );

		$this->set_prop( $helper, 'full_width', 640 );
		$this->assertStringNotContainsString( 'textlayer', $helper->getPlaceHolder( array() ), 'Width alone is not enough.' );

		$this->set_prop( $helper, 'full_height', 480 );
		$this->assertStringContainsString( "<span class='textlayer'>640 x 480</span>", $helper->getPlaceHolder( array() ) );
		$this->assertStringContainsString( "<span class='textlayer'>emr-layer</span>", $helper->getPlaceHolder( array( 'layer' => 'emr-layer' ) ) );
	}

	public function test_placeholder_remove_bg_ui_adds_the_overlay_and_preview_title() {
		$html = UIHelper::getInstance()->getPlaceHolder( array( 'remove_bg_ui' => true ) );

		$this->assertStringContainsString( '<div class="overlay" id="overlay">', $html );
		$this->assertStringContainsString( 'lds-spinner', $html );
		$this->assertStringContainsString( '<h3>Removing background...</h3>', $html );
		$this->assertStringContainsString( "<span class='upload-title'>Preview</span>", $html );
	}

	public function test_placeholder_formats_the_file_size() {
		$helper = UIHelper::getInstance();

		$this->assertStringContainsString( "<div class='image_size'>1 KB</div>", $helper->getPlaceHolder( array( 'file_size' => 1024 ) ) );
		$this->assertStringContainsString( "<div class='image_size'>3 MB</div>", $helper->getPlaceHolder( array( 'file_size' => 3 * MB_IN_BYTES ) ) );
		$this->assertStringContainsString( "<div class='image_size'></div>", $helper->getPlaceHolder( array( 'file_size' => 0 ) ) );
	}

	/**
	 * UIHelper::getPlaceHolder() prints `mime_type` into the data-filetype
	 * attribute without esc_attr(). A value containing a double quote ends the
	 * attribute and injects markup. The correct behaviour is
	 * esc_attr( $args['mime_type'] ).
	 *
	 * How to recognise the fix: the quote comes out as &quot; and the
	 * assertStringContainsString on the raw attribute fails.
	 */
	public function test_placeholder_prints_the_mime_type_unescaped_pinned_for_deferred_fix() {
		$html = UIHelper::getInstance()->getPlaceHolder( array( 'mime_type' => 'image/x" data-emr-injected="1' ) );

		$this->assertStringContainsString( 'data-filetype="image/x" data-emr-injected="1"', $html );
		$this->assertStringNotContainsString( '&quot;', $html );
	}

	/**
	 * UIHelper::getPlaceHolder() prints `layer` (a file name when it comes
	 * from getPreviewFile()) without esc_html(). A file name with markup ends
	 * up as markup in the admin screen. The correct behaviour is
	 * esc_html( $args['layer'] ).
	 *
	 * How to recognise the fix: the layer comes out as &lt;b&gt; and the
	 * assertStringContainsString on the raw tag fails.
	 */
	public function test_placeholder_prints_the_layer_unescaped_pinned_for_deferred_fix() {
		$helper = UIHelper::getInstance();
		$this->set_prop( $helper, 'full_width', 640 );
		$this->set_prop( $helper, 'full_height', 480 );

		$html = $helper->getPlaceHolder( array( 'layer' => '<b class="emr-injected">x"y.pdf</b>' ) );

		$this->assertStringContainsString( "<span class='textlayer'><b class=\"emr-injected\">x\"y.pdf</b></span>", $html );
		$this->assertStringNotContainsString( '&lt;', $html );
	}

	/**
	 * UIHelper::getPlaceHolder() prints `icon` into a single-quoted class
	 * attribute without esc_attr(). A value containing a single quote ends the
	 * attribute and injects markup. The correct behaviour is
	 * esc_attr( $icon ).
	 *
	 * How to recognise the fix: the quote comes out as &#039; and the
	 * assertStringContainsString on the raw attribute fails.
	 */
	public function test_placeholder_prints_the_icon_unescaped_pinned_for_deferred_fix() {
		$html = UIHelper::getInstance()->getPlaceHolder( array( 'icon' => "dashicons-no' data-emr-injected='1" ) );

		$this->assertStringContainsString( "<div class='dashicons dashicons-no' data-emr-injected='1'>", $html );
		$this->assertStringNotContainsString( '&#039;', $html );
	}

	/* ------------------------------------------------------------------
	 * isBackgroundRemovable()
	 * ------------------------------------------------------------------ */

	/**
	 * Creates an attachment post with a given file name and MIME type.
	 *
	 * @param string $file File name.
	 * @param string $mime MIME type.
	 * @return int
	 */
	private function attachment_object( $file, $mime ) {
		return self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => $mime,
			)
		);
	}

	public function test_background_removable_for_jpeg_and_png_images() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_background_feature( true );
		$helper = UIHelper::getInstance();

		foreach ( array( 'emr-bg.jpg' => 'image/jpeg', 'emr-bg.jpeg' => 'image/jpeg', 'emr-bg.png' => 'image/png', 'emr-bg-legacy.jpg' => 'image/jpg' ) as $file => $mime ) {
			$id = $this->attachment_object( $file, $mime );
			$this->assertTrue( wp_attachment_is_image( $id ), 'Precondition: ' . $file . ' is an image.' );

			$this->assertTrue( $helper->isBackgroundRemovable( $id ), $mime . ' must be removable.' );
		}
		$this->assertTrue( $helper->isBackgroundRemovable( get_post( $id ) ), 'A WP_Post is accepted as well as an ID.' );
	}

	public function test_background_not_removable_for_gif_and_webp_images() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_background_feature( true );
		$helper = UIHelper::getInstance();

		foreach ( array( 'emr-bg.gif' => 'image/gif', 'emr-bg.webp' => 'image/webp' ) as $file => $mime ) {
			$id = $this->attachment_object( $file, $mime );
			$this->assertTrue( wp_attachment_is_image( $id ), 'Precondition: ' . $file . ' is an image.' );

			$this->assertFalse( $helper->isBackgroundRemovable( $id ), $mime . ' must not be removable.' );
		}
	}

	public function test_background_not_removable_for_a_non_image() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_background_feature( true );
		$id = $this->attachment_object( 'emr-bg.pdf', 'application/pdf' );

		$this->assertFalse( UIHelper::getInstance()->isBackgroundRemovable( $id ) );
	}

	public function test_background_not_removable_when_the_feature_is_off() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_background_feature( false );
		$id = $this->attachment_object( 'emr-bg-off.jpg', 'image/jpeg' );

		$this->assertFalse( UIHelper::getInstance()->isBackgroundRemovable( $id ) );
	}

	public function test_background_not_removable_for_an_author_restricted_user() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->set_background_feature( true );
		$id = $this->attachment_object( 'emr-bg-author.jpg', 'image/jpeg' );

		$this->assertFalse( UIHelper::getInstance()->isBackgroundRemovable( $id ) );
	}

	/* ------------------------------------------------------------------
	 * getRelPathNow() and featureNotice()
	 * ------------------------------------------------------------------ */

	public function test_get_rel_path_now_strips_the_leading_slash_of_the_subdir() {
		$this->filter(
			'upload_dir',
			function ( $dir ) {
				$dir['subdir'] = '/2024/05';
				return $dir;
			}
		);

		$this->assertSame( '2024/05', UIHelper::getInstance()->getRelPathNow() );
	}

	public function test_get_rel_path_now_matches_the_current_month_folder() {
		$this->assertMatchesRegularExpression( '#^\d{4}/\d{2}$#', UIHelper::getInstance()->getRelPathNow() );
	}

	public function test_get_rel_path_now_is_empty_without_month_folders() {
		$this->filter(
			'upload_dir',
			function ( $dir ) {
				$dir['subdir'] = '';
				return $dir;
			}
		);

		$this->assertSame( '', UIHelper::getInstance()->getRelPathNow() );
	}

	public function test_get_rel_path_now_is_false_without_a_subdir_entry() {
		$this->filter(
			'upload_dir',
			function ( $dir ) {
				unset( $dir['subdir'] );
				return $dir;
			}
		);

		$this->assertFalse( UIHelper::getInstance()->getRelPathNow() );
	}

	public function test_feature_notice_adds_a_persistent_notice_with_id_emr001() {
		UIHelper::getInstance()->featureNotice();

		$notice = NoticeController::getInstance()->getNoticeByID( UIHelper::NOTICE_NEW_FEATURE );

		$this->assertSame( 'EMR001', UIHelper::NOTICE_NEW_FEATURE );
		$this->assertIsObject( $notice );
		$this->assertTrue( $notice->isPersistent() );
		$this->assertSame( 2 * YEAR_IN_SECONDS, $notice->_debug_getvar( 'suppress_period' ) );
		$stored = get_option( 'EnableMediaReplace-notices' );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'EMR001', $stored[0]->getID() );
	}

	public function test_feature_notice_is_idempotent() {
		$helper = UIHelper::getInstance();

		$helper->featureNotice();
		$helper->featureNotice();
		$helper->featureNotice();

		$this->assertSame( 1, NoticeController::getInstance()->countNotices() );
		$this->assertCount( 1, get_option( 'EnableMediaReplace-notices' ) );
	}

	public function test_feature_notice_still_announces_a_new_beta_feature() {
		UIHelper::getInstance()->featureNotice();

		$notice = NoticeController::getInstance()->getNoticeByID( 'EMR001' );

		$this->assertStringContainsString( 'New Beta Feature!', $notice->message );
		$this->assertStringContainsString( 'remove the background', $notice->message );
	}
}
