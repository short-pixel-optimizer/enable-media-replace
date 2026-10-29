<?php
/**
 * Tests for the media-library side of EnableMediaReplace\EnableMediaReplacePlugin
 * (classes/emr-plugin.php): checkImagePermission(), the URL builders, the
 * attachment meta boxes, the attachment fields, the row actions, the
 * "Revised" / "Replaced By" lines, attempt_uncache_image() and the
 * `[file_modified]` shortcode (get_modified_date()).
 *
 * Attachments are created as database rows with crafted metadata, without
 * files, except for get_modified_date(), which needs a real file and
 * uploads a copy of tests/fixtures/test-image.jpg (deleted in tear_down()).
 * An attachment made without metadata has no width, so
 * UIHelper::isUpscalable() offers the upscale entry point for it; the
 * "too wide" cases write a width of 1201 px into the metadata.
 *
 * The string and array shapes of EMR_CAPABILITY are simulated in the
 * checkImagePermission() tests by writing $general_cap / $user_cap the way
 * runtime() would; runtime() itself only sees the default constant here.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/EmrMainTestCase.php';

class PluginMediaUITest extends EMR_MainTestCase {

	/** @var array Snapshot of the date / time options. */
	private $saved_options = array();

	/** @var array Text domains recorded by the gettext spy, keyed by text. */
	private $gettext_domains = array();

	public function set_up() {
		parent::set_up();
		foreach ( array( 'date_format', 'time_format', 'timezone_string', 'gmt_offset' ) as $name ) {
			$this->saved_options[ $name ] = get_option( $name );
		}
		$this->gettext_domains = array();
	}

	public function tear_down() {
		foreach ( $this->saved_options as $name => $value ) {
			update_option( $name, $value );
		}
		parent::tear_down();
	}

	/** gettext filter recording the domain each text was translated with. */
	public function gettext_spy( $translation, $text, $domain ) {
		$this->gettext_domains[ $text ][] = $domain;
		return $translation;
	}

	/** Logs in an administrator and fills the feature flags. */
	private function as_admin() {
		$id = $this->login_as( 'administrator' );
		emr()->adminInit();
		return $id;
	}

	/** Extracts the _wpnonce query argument from an HTML fragment. */
	private function nonce_in( $html ) {
		$this->assertSame( 1, preg_match( '/_wpnonce=([0-9a-f]+)/', $html, $m ), 'No nonce in: ' . $html );
		return $m[1];
	}

	/** Grants edit_post on post ID 0 (map_meta_cap filter). */
	public function grant_edit_post_zero( $caps, $cap, $user_id, $args ) {
		if ( 'edit_post' === $cap && isset( $args[0] ) && 0 === $args[0] ) {
			return array( 'exist' );
		}
		return $caps;
	}

	/**
	 * An image attachment whose attached file is a `-scaled` copy, with two
	 * sizes in the metadata (plus any $extra_sizes).
	 */
	private function make_scaled_image( array $extra_sizes = array(), $with_original = true ) {
		$id   = $this->make_attachment( 'emr-main-photo-scaled.jpg' );
		$meta = array(
			'width'  => 2560,
			'height' => 1920,
			'file'   => 'emr-main-photo-scaled.jpg',
			'sizes'  => array_merge(
				array(
					'thumbnail'    => array( 'file' => 'emr-main-photo-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg' ),
					'medium_large' => array( 'file' => 'emr-main-photo-768x576.jpg', 'width' => 768, 'height' => 576, 'mime-type' => 'image/jpeg' ),
				),
				$extra_sizes
			),
		);
		if ( $with_original ) {
			$meta['original_image'] = 'emr-main-photo.jpg';
		}
		wp_update_attachment_metadata( $id, $meta );
		return $id;
	}

	/* ------------------------------------------------------------------
	 * checkImagePermission()
	 * ------------------------------------------------------------------ */

	public function test_check_image_permission_rejects_non_objects() {
		$this->as_admin();

		$this->assertFalse( emr()->checkImagePermission( null ) );
		$this->assertFalse( emr()->checkImagePermission( 57 ) );
		$this->assertFalse( emr()->checkImagePermission( array( 'ID' => 57 ) ) );
	}

	public function test_check_image_permission_rejects_a_post_that_is_not_an_attachment() {
		$this->as_admin();
		$post_id = self::factory()->post->create();

		$this->assertFalse( emr()->checkImagePermission( get_post( $post_id ) ) );
	}

	public function test_check_image_permission_grants_an_administrator() {
		$this->as_admin();
		$id = $this->make_attachment();

		$this->assertTrue( emr()->checkImagePermission( get_post( $id ) ) );
	}

	public function test_check_image_permission_grants_an_author_on_their_own_attachment_only() {
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$author = $this->login_as( 'author' );
		$own    = $this->make_attachment( 'emr-main-own.jpg', 'image/jpeg', array( 'post_author' => $author ) );
		$theirs = $this->make_attachment( 'emr-main-theirs.jpg', 'image/jpeg', array( 'post_author' => $other ) );

		$this->assertTrue( emr()->checkImagePermission( get_post( $own ) ) );
		$this->assertFalse( emr()->checkImagePermission( get_post( $theirs ) ) );
	}

	public function test_check_image_permission_denies_a_logged_out_visitor() {
		$id = $this->make_attachment();

		$this->assertFalse( emr()->checkImagePermission( get_post( $id ) ) );
	}

	/**
	 * Simulates a string EMR_CAPABILITY: a user holding $general_cap may
	 * replace every attachment, without a per-post check.
	 */
	public function test_check_image_permission_with_a_general_cap_grants_every_attachment() {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->login_as( 'subscriber' );
		wp_get_current_user()->add_cap( 'emr_main_replace_all' );
		$this->set_plugin_prop( 'general_cap', 'emr_main_replace_all' );
		$id = $this->make_attachment( 'emr-main-photo.jpg', 'image/jpeg', array( 'post_author' => $other ) );

		$this->assertFalse( current_user_can( 'edit_post', $id ) );
		$this->assertTrue( emr()->checkImagePermission( get_post( $id ) ) );
	}

	/**
	 * Simulates an array EMR_CAPABILITY: the second capability grants only
	 * attachments whose post_author is the current user.
	 */
	public function test_check_image_permission_with_a_user_cap_grants_own_attachments_only() {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$me    = $this->login_as( 'subscriber' );
		wp_get_current_user()->add_cap( 'emr_main_replace_own' );
		$this->set_plugin_prop( 'general_cap', 'emr_main_replace_all' );
		$this->set_plugin_prop( 'user_cap', 'emr_main_replace_own' );
		$own    = $this->make_attachment( 'emr-main-own.jpg', 'image/jpeg', array( 'post_author' => $me ) );
		$theirs = $this->make_attachment( 'emr-main-theirs.jpg', 'image/jpeg', array( 'post_author' => $other ) );

		$this->assertTrue( emr()->checkImagePermission( get_post( $own ) ) );
		$this->assertFalse( emr()->checkImagePermission( get_post( $theirs ) ) );
	}

	public function test_check_image_permission_with_stored_caps_the_user_lacks_denies() {
		$this->as_admin();
		$this->set_plugin_prop( 'general_cap', 'emr_main_replace_all' );
		$this->set_plugin_prop( 'user_cap', 'emr_main_replace_own' );
		$id = $this->make_attachment( 'emr-main-own.jpg', 'image/jpeg', array( 'post_author' => get_current_user_id() ) );

		$this->assertFalse( emr()->checkImagePermission( get_post( $id ) ), 'edit_post is not consulted once a cap is stored.' );
	}

	public function test_check_image_permission_for_attachment_id_zero_without_a_grant_is_false() {
		$this->as_admin();
		$post = new WP_Post( (object) array( 'ID' => 0, 'post_type' => 'attachment', 'post_author' => get_current_user_id() ) );

		$this->assertFalse( emr()->checkImagePermission( $post ) );
	}

	/**
	 * The ID guard in checkImagePermission() reads `intval($post_id) > ! 0`.
	 * `! 0` is true, so the int is compared with a bool and the condition is
	 * never true: the guard never rejects an ID, and an attachment object
	 * with ID 0 falls through to current_user_can('edit_post', 0). With a
	 * map_meta_cap filter that grants edit_post on ID 0, the method returns
	 * true; the guard should have returned false before asking.
	 *
	 * How to recognise the fix: with the guard written
	 * `intval($post_id) <= 0`, the result for ID 0 is false even though the
	 * filter grants the capability.
	 */
	public function test_check_image_permission_id_guard_never_rejects_pinned_for_deferred_fix() {
		$this->as_admin();
		$this->add_test_filter( 'map_meta_cap', array( $this, 'grant_edit_post_zero' ), 10, 4 );
		$post = new WP_Post( (object) array( 'ID' => 0, 'post_type' => 'attachment', 'post_author' => get_current_user_id() ) );
		$this->assertTrue( current_user_can( 'edit_post', 0 ), 'Precondition: the filter grants edit_post on ID 0.' );

		$result = emr()->checkImagePermission( $post );

		$this->assertIsBool( $result );
		$this->assertTrue( $result );
	}

	/* ------------------------------------------------------------------
	 * URL builders
	 * ------------------------------------------------------------------ */

	public function test_media_replace_url_carries_page_action_and_attachment_id_without_nonce() {
		$url = $this->call_protected( 'getMediaReplaceURL', array( 57 ) );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( admin_url( 'upload.php' ), $url );
		$this->assertSame(
			array(
				'page'          => self::PAGE_SLUG,
				'action'        => 'media_replace',
				'attachment_id' => '57',
			),
			$query
		);
	}

	public function test_remove_bg_url_carries_the_prepare_remove_action() {
		$url = $this->call_protected( 'getRemoveBgURL', array( 63 ) );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( admin_url( 'upload.php' ), $url );
		$this->assertSame( 'emr_prepare_remove', $query['action'] );
		$this->assertSame( '63', $query['attachment_id'] );
		$this->assertSame( self::PAGE_SLUG, $query['page'] );
		$this->assertArrayNotHasKey( '_wpnonce', $query );
	}

	public function test_upscale_url_carries_the_prepare_upscale_action() {
		$url = $this->call_protected( 'getUpscaleURL', array( 64 ) );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( admin_url( 'upload.php' ), $url );
		$this->assertSame( 'emr_prepare_upscale', $query['action'] );
		$this->assertSame( '64', $query['attachment_id'] );
		$this->assertSame( self::PAGE_SLUG, $query['page'] );
		$this->assertArrayNotHasKey( '_wpnonce', $query );
	}

	/** Writes a metadata width (and height) for an attachment created without files. */
	private function set_width( $id, $width, $height = 100 ) {
		wp_update_attachment_metadata( $id, array( 'width' => $width, 'height' => $height, 'file' => 'emr-main-photo.jpg', 'sizes' => array() ) );
		$this->assertSame( $width, wp_get_attachment_metadata( $id )['width'], 'Precondition: the width is stored.' );
	}

	/* ------------------------------------------------------------------
	 * add_meta_boxes()
	 * ------------------------------------------------------------------ */

	public function test_add_meta_boxes_returns_false_without_a_post_object() {
		$this->as_admin();
		$GLOBALS['wp_meta_boxes'] = array();

		$this->assertFalse( emr()->add_meta_boxes( null ) );
		$this->assertFalse( emr()->add_meta_boxes( 57 ) );
		$this->assertSame( array(), $GLOBALS['wp_meta_boxes'] );
	}

	public function test_add_meta_boxes_without_permission_adds_nothing() {
		$GLOBALS['wp_meta_boxes'] = array();
		$id                       = $this->make_attachment();

		$this->assertNull( emr()->add_meta_boxes( get_post( $id ) ) );
		$this->assertSame( array(), $GLOBALS['wp_meta_boxes'] );
	}

	public function test_add_meta_boxes_registers_the_replace_box() {
		$this->as_admin();
		$GLOBALS['wp_meta_boxes'] = array();
		$id                       = $this->make_attachment();

		emr()->add_meta_boxes( get_post( $id ) );

		$low = $GLOBALS['wp_meta_boxes']['attachment']['side']['low'];
		$this->assertArrayHasKey( 'emr-replace-box', $low );
		$this->assertSame( 'Replace Media', $low['emr-replace-box']['title'] );
		$this->assertSame( array( emr(), 'replace_meta_box' ), $low['emr-replace-box']['callback'] );
		$this->assertArrayNotHasKey( 'emr-showthumbs-box', $low );
	}

	public function test_add_meta_boxes_adds_the_thumbnails_box_after_a_replacement() {
		$this->as_admin();
		$GLOBALS['wp_meta_boxes'] = array();
		$_GET['emr_replaced']     = '1';
		$id                       = $this->make_attachment();

		emr()->add_meta_boxes( get_post( $id ) );

		$low = $GLOBALS['wp_meta_boxes']['attachment']['side']['low'];
		$this->assertArrayHasKey( 'emr-showthumbs-box', $low );
		$this->assertSame( 'Replaced Thumbnails Preview', $low['emr-showthumbs-box']['title'] );
		$this->assertSame( array( emr(), 'show_thumbs_box' ), $low['emr-showthumbs-box']['callback'] );
	}

	/* ------------------------------------------------------------------
	 * replace_meta_box()
	 * ------------------------------------------------------------------ */

	public function test_replace_meta_box_prints_all_three_buttons_for_a_jpeg() {
		$this->as_admin();
		$id = $this->make_attachment();

		$html = $this->capture(
			function () use ( $id ) {
				emr()->replace_meta_box( get_post( $id ) );
			}
		);

		$this->assertStringContainsString( 'Upload a new file', $html );
		$this->assertStringContainsString( 'Remove background', $html );
		$this->assertStringContainsString( 'Upscale image', $html );
		$this->assertSame( 1, preg_match( '/href="([^"]*action=media_replace[^"]*)"/', $html, $replace ) );
		$this->assertSame( 1, wp_verify_nonce( $this->nonce_in( $replace[1] ), 'media_replace' ) );
		$this->assertStringContainsString( 'attachment_id=' . $id, $replace[1] );
		$this->assertSame( 1, preg_match( '/href="([^"]*action=emr_prepare_remove[^"]*)"/', $html, $remove ) );
		$this->assertSame( 1, wp_verify_nonce( $this->nonce_in( $remove[1] ), 'emr_prepare_remove' ) );
		$this->assertSame( 1, preg_match( '/href="([^"]*action=emr_prepare_upscale[^"]*)"/', $html, $upscale ) );
		$this->assertSame( 1, wp_verify_nonce( $this->nonce_in( $upscale[1] ), 'emr_prepare_upscale' ) );
		$this->assertStringContainsString( 'attachment_id=' . $id, $upscale[1] );
		$this->assertStringContainsString( 'To enlarge a small image, click the link and select the options.', $html );
	}

	public function test_replace_meta_box_omits_remove_background_and_upscale_for_a_pdf() {
		$this->as_admin();
		$id = $this->make_attachment( 'emr-main-doc.pdf', 'application/pdf' );

		$html = $this->capture(
			function () use ( $id ) {
				emr()->replace_meta_box( get_post( $id ) );
			}
		);

		$this->assertStringContainsString( 'Upload a new file', $html );
		$this->assertStringNotContainsString( 'Remove background', $html );
		$this->assertStringNotContainsString( 'emr_prepare_remove', $html );
		$this->assertStringNotContainsString( 'Upscale image', $html );
		$this->assertStringNotContainsString( 'emr_prepare_upscale', $html );
	}

	public function test_replace_meta_box_omits_upscale_for_an_image_wider_than_the_limit() {
		$this->as_admin();
		$id = $this->make_attachment();
		$this->set_width( $id, 1201 );

		$html = $this->capture(
			function () use ( $id ) {
				emr()->replace_meta_box( get_post( $id ) );
			}
		);

		$this->assertStringContainsString( 'Remove background', $html, 'Remove background has no size limit.' );
		$this->assertStringNotContainsString( 'Upscale image', $html );
		$this->assertStringNotContainsString( 'emr_prepare_upscale', $html );
	}

	public function test_replace_meta_box_omits_upscale_when_the_upscale_feature_is_off() {
		$this->add_test_filter( 'emr/feature/upscale', '__return_false' );
		$this->as_admin();
		$id = $this->make_attachment();

		$html = $this->capture(
			function () use ( $id ) {
				emr()->replace_meta_box( get_post( $id ) );
			}
		);

		$this->assertStringContainsString( 'Remove background', $html );
		$this->assertStringNotContainsString( 'Upscale image', $html );
	}

	/* ------------------------------------------------------------------
	 * show_thumbs_box()
	 * ------------------------------------------------------------------ */

	public function test_show_thumbs_box_without_permission_prints_nothing() {
		$id = $this->make_scaled_image();

		$result = null;
		$html   = $this->capture(
			function () use ( $id, &$result ) {
				$result = emr()->show_thumbs_box( get_post( $id ) );
			}
		);

		$this->assertNull( $result );
		$this->assertSame( '', $html );
	}

	public function test_show_thumbs_box_without_sizes_says_thumbnails_were_not_generated() {
		$this->as_admin();
		$id = $this->make_attachment();
		wp_update_attachment_metadata( $id, array( 'width' => 10, 'height' => 10, 'file' => 'emr-main-photo.jpg' ) );

		$result = null;
		$html   = $this->capture(
			function () use ( $id, &$result ) {
				$result = emr()->show_thumbs_box( get_post( $id ) );
			}
		);

		$this->assertFalse( $result );
		$this->assertSame( 'Thumbnails were not generated', $html );
	}

	public function test_show_thumbs_box_prints_one_preview_per_size_and_enqueues_its_style() {
		$this->as_admin();
		emr()->admin_scripts();
		$id = $this->make_scaled_image( array(), false );

		$html = $this->capture(
			function () use ( $id ) {
				emr()->show_thumbs_box( get_post( $id ) );
			}
		);

		$this->assertSame( 2, substr_count( $html, 'previewwrapper' ) );
		$this->assertStringContainsString( "<div class='thumbnail previewwrapper'>", $html );
		$this->assertStringContainsString( "<span class='label'>Medium large</span>", $html );
		$this->assertStringContainsString( 'emr-main-photo-150x150.jpg', $html );
		$this->assertStringNotContainsString( "class='original", $html, 'No Original block when the original URL equals the attachment URL.' );
		$this->assertTrue( wp_style_is( 'emr_edit-attachment', 'enqueued' ) );
	}

	/**
	 * show_thumbs_box() labels the wp_get_attachment_url() image "Original",
	 * but on a scaled upload that URL is the `-scaled` file; the real
	 * original (wp_get_original_image_url()) is never shown. The label also
	 * uses __('Original') without the plugin text domain, so it is looked up
	 * in WordPress core's strings ('default').
	 *
	 * How to recognise the fix: the Original block shows
	 * wp_get_original_image_url() (`emr-main-photo.jpg`) instead of the
	 * `-scaled` file, and the gettext spy records 'enable-media-replace' for
	 * 'Original'.
	 */
	public function test_show_thumbs_box_labels_the_scaled_file_original_pinned_for_deferred_fix() {
		$this->as_admin();
		$id           = $this->make_scaled_image();
		$scaled_url   = wp_get_attachment_url( $id );
		$original_url = wp_get_original_image_url( $id );
		$this->assertNotSame( $scaled_url, $original_url, 'Precondition: the attachment was scaled.' );
		$this->assertStringEndsWith( '-scaled.jpg', $scaled_url );
		$this->add_test_filter( 'gettext', array( $this, 'gettext_spy' ), 10, 3 );

		$html = $this->capture(
			function () use ( $id ) {
				emr()->show_thumbs_box( get_post( $id ) );
			}
		);

		$this->assertSame( 3, substr_count( $html, 'previewwrapper' ), 'Two sizes plus the Original block.' );
		$this->assertStringContainsString( "<div class='original previewwrapper'><img src='" . $scaled_url . "'><span class='label'>Original</span></div>", $html );
		$this->assertStringNotContainsString( $original_url, $html );
		$this->assertSame( array( 'default' ), $this->gettext_domains['Original'] );
	}

	/**
	 * show_thumbs_box() prints the size names (metadata keys) and the image
	 * URLs without esc_attr() / esc_url() / esc_html(). A size key carrying a
	 * quote breaks out of the class attribute.
	 *
	 * How to recognise the fix: the raw `' onmouseover='` sequence no longer
	 * appears; the key comes out entity-encoded (`&#039;`).
	 */
	public function test_show_thumbs_box_prints_size_names_unescaped_pinned_for_deferred_fix() {
		$this->as_admin();
		$key = "emr' onmouseover='alert(1)";
		$id  = $this->make_scaled_image(
			array( $key => array( 'file' => 'emr-main-photo-300x225.jpg', 'width' => 300, 'height' => 225, 'mime-type' => 'image/jpeg' ) ),
			false
		);

		$html = $this->capture(
			function () use ( $id ) {
				emr()->show_thumbs_box( get_post( $id ) );
			}
		);

		$this->assertSame( 3, substr_count( $html, 'previewwrapper' ) );
		$this->assertStringContainsString( "<div class='emr' onmouseover='alert(1) previewwrapper'>", $html );
		$this->assertStringContainsString( "<span class='label'>Emr' onmouseover='alert(1)</span>", $html );
		$this->assertStringNotContainsString( '&#039;', $html );
	}

	/* ------------------------------------------------------------------
	 * attachment_editor()
	 * ------------------------------------------------------------------ */

	public function test_attachment_editor_without_permission_returns_the_fields_untouched() {
		$id     = $this->make_attachment();
		$fields = array( 'title' => array( 'label' => 'Title' ) );

		$this->assertSame( $fields, emr()->attachment_editor( $fields, get_post( $id ) ) );
	}

	public function test_attachment_editor_on_the_edit_media_screen_returns_the_fields_untouched() {
		$this->as_admin();
		set_current_screen( 'attachment' );
		$this->assertSame( 'attachment', get_current_screen()->id );
		$id     = $this->make_attachment();
		$fields = array( 'title' => array( 'label' => 'Title' ) );

		$this->assertSame( $fields, emr()->attachment_editor( $fields, get_post( $id ) ) );
	}

	public function test_attachment_editor_adds_all_three_buttons_for_an_administrator_on_a_jpeg() {
		$this->as_admin();
		set_current_screen( 'upload' );
		$id = $this->make_attachment();

		$fields = emr()->attachment_editor( array( 'title' => array( 'label' => 'Title' ) ), get_post( $id ) );

		$this->assertSame( array( 'title', 'enable-media-replace', 'emr-remove-background', 'emr-upscale' ), array_keys( $fields ) );
		$this->assertSame( 'Replace media', $fields['enable-media-replace']['label'] );
		$this->assertSame( 'html', $fields['enable-media-replace']['input'] );
		$this->assertSame( 1, wp_verify_nonce( $this->nonce_in( $fields['enable-media-replace']['html'] ), 'media_replace' ) );
		$this->assertStringContainsString( 'attachment_id=' . $id, $fields['enable-media-replace']['html'] );
		$this->assertSame( 'Remove background', $fields['emr-remove-background']['label'] );
		$this->assertSame( 1, wp_verify_nonce( $this->nonce_in( $fields['emr-remove-background']['html'] ), 'emr_prepare_remove' ) );
		$this->assertSame( 'Upscale image', $fields['emr-upscale']['label'] );
		$this->assertSame( 'html', $fields['emr-upscale']['input'] );
		$this->assertSame( 'To enlarge a small image, click the link.', $fields['emr-upscale']['helps'] );
		$this->assertSame( 1, wp_verify_nonce( $this->nonce_in( $fields['emr-upscale']['html'] ), 'emr_prepare_upscale' ) );
		$this->assertStringContainsString( 'action=emr_prepare_upscale', $fields['emr-upscale']['html'] );
		$this->assertStringContainsString( 'attachment_id=' . $id, $fields['emr-upscale']['html'] );
	}

	public function test_attachment_editor_omits_upscale_for_an_image_wider_than_the_limit() {
		$this->as_admin();
		$id = $this->make_attachment();
		$this->set_width( $id, 1201 );

		$fields = emr()->attachment_editor( array(), get_post( $id ) );

		$this->assertSame( array( 'enable-media-replace', 'emr-remove-background' ), array_keys( $fields ) );
	}

	public function test_attachment_editor_omits_upscale_when_the_upscale_feature_is_off() {
		$this->add_test_filter( 'emr/feature/upscale', '__return_false' );
		$this->as_admin();
		$id = $this->make_attachment();

		$fields = emr()->attachment_editor( array(), get_post( $id ) );

		$this->assertSame( array( 'enable-media-replace', 'emr-remove-background' ), array_keys( $fields ) );
	}

	public function test_attachment_editor_without_a_screen_still_adds_the_buttons() {
		$this->as_admin();
		$id = $this->make_attachment();

		$fields = emr()->attachment_editor( array(), get_post( $id ) );

		$this->assertArrayHasKey( 'enable-media-replace', $fields );
	}

	public function test_attachment_editor_omits_remove_background_and_upscale_for_a_pdf() {
		$this->as_admin();
		$id = $this->make_attachment( 'emr-main-doc.pdf', 'application/pdf' );

		$fields = emr()->attachment_editor( array(), get_post( $id ) );

		$this->assertSame( array( 'enable-media-replace' ), array_keys( $fields ) );
	}

	public function test_attachment_editor_omits_remove_background_and_upscale_for_an_author_restricted_user() {
		$author = $this->login_as( 'author' );
		emr()->adminInit();
		$id = $this->make_attachment( 'emr-main-own.jpg', 'image/jpeg', array( 'post_author' => $author ) );

		$fields = emr()->attachment_editor( array(), get_post( $id ) );

		$this->assertSame( array( 'enable-media-replace' ), array_keys( $fields ) );
	}

	/* ------------------------------------------------------------------
	 * add_media_action()
	 * ------------------------------------------------------------------ */

	public function test_add_media_action_without_permission_returns_the_actions_untouched() {
		$id      = $this->make_attachment();
		$actions = array( 'edit' => '<a>Edit</a>' );

		$this->assertSame( $actions, emr()->add_media_action( $actions, get_post( $id ) ) );
	}

	public function test_add_media_action_appends_replace_remove_background_and_upscale() {
		$this->as_admin();
		$id = $this->make_attachment();

		$actions = emr()->add_media_action( array( 'edit' => '<a>Edit</a>' ), get_post( $id ) );

		$this->assertSame( array( 'edit', 'media_replace', 'remove_background', 'upscale_image' ), array_keys( $actions ) );
		$this->assertSame( '<a>Edit</a>', $actions['edit'] );
		$this->assertStringContainsString( 'action=media_replace', $actions['media_replace'] );
		$this->assertStringContainsString( 'attachment_id=' . $id, $actions['media_replace'] );
		$this->assertStringContainsString( 'aria-label="Replace media"', $actions['media_replace'] );
		$this->assertSame( 1, wp_verify_nonce( $this->nonce_in( $actions['remove_background'] ), 'emr_prepare_remove' ) );
		$this->assertStringContainsString( 'action=emr_prepare_upscale', $actions['upscale_image'] );
		$this->assertStringContainsString( 'attachment_id=' . $id, $actions['upscale_image'] );
		$this->assertStringContainsString( 'aria-label="Upscale image"', $actions['upscale_image'] );
		$this->assertStringContainsString( '>Upscale image</a>', $actions['upscale_image'] );
		$this->assertSame( 1, wp_verify_nonce( $this->nonce_in( $actions['upscale_image'] ), 'emr_prepare_upscale' ) );
		$this->assertFalse( wp_verify_nonce( $this->nonce_in( $actions['upscale_image'] ), 'emr_prepare_remove' ), 'Each action has its own nonce.' );
	}

	public function test_add_media_action_omits_upscale_for_an_image_wider_than_the_limit() {
		$this->as_admin();
		$id = $this->make_attachment();
		$this->set_width( $id, 1201 );

		$actions = emr()->add_media_action( array(), get_post( $id ) );

		$this->assertSame( array( 'media_replace', 'remove_background' ), array_keys( $actions ) );
	}

	public function test_add_media_action_keeps_upscale_when_only_the_background_feature_is_off() {
		$this->add_test_filter( 'emr/feature/background', '__return_false' );
		$this->as_admin();
		$id = $this->make_attachment();

		$actions = emr()->add_media_action( array(), get_post( $id ) );

		$this->assertSame( array( 'media_replace', 'upscale_image' ), array_keys( $actions ) );
	}

	public function test_add_media_action_omits_upscale_when_the_upscale_feature_is_off() {
		$this->add_test_filter( 'emr/feature/upscale', '__return_false' );
		$this->as_admin();
		$id = $this->make_attachment();

		$actions = emr()->add_media_action( array(), get_post( $id ) );

		$this->assertSame( array( 'media_replace', 'remove_background' ), array_keys( $actions ) );
	}

	/**
	 * Documented behaviour: the replace row action carries no nonce; the
	 * replace screen does not check one.
	 */
	public function test_add_media_action_replace_link_has_no_nonce() {
		$this->as_admin();
		$id = $this->make_attachment();

		$actions = emr()->add_media_action( array(), get_post( $id ) );

		$this->assertStringNotContainsString( '_wpnonce', $actions['media_replace'] );
	}

	public function test_add_media_action_only_replace_for_a_pdf_or_an_author() {
		$this->as_admin();
		$pdf = $this->make_attachment( 'emr-main-doc.pdf', 'application/pdf' );
		$this->assertSame( array( 'media_replace' ), array_keys( emr()->add_media_action( array(), get_post( $pdf ) ) ) );

		$author = $this->login_as( 'author' );
		$own    = $this->make_attachment( 'emr-main-own.jpg', 'image/jpeg', array( 'post_author' => $author ) );
		$this->assertSame( array( 'media_replace' ), array_keys( emr()->add_media_action( array(), get_post( $own ) ) ) );
	}

	/**
	 * The remove-background row action uses "Remove  background" (two
	 * spaces) for its label and aria-label, a different translation string
	 * from the "Remove background" used everywhere else.
	 *
	 * How to recognise the fix: the aria-label and the link text read
	 * "Remove background" with a single space.
	 */
	public function test_add_media_action_label_has_a_double_space_pinned_for_deferred_fix() {
		$this->as_admin();
		$id = $this->make_attachment();

		$actions = emr()->add_media_action( array(), get_post( $id ) );

		$this->assertArrayHasKey( 'remove_background', $actions );
		$this->assertStringContainsString( 'aria-label="Remove  background"', $actions['remove_background'] );
		$this->assertStringContainsString( '>Remove  background</a>', $actions['remove_background'] );
		$this->assertStringNotContainsString( 'Remove background', $actions['remove_background'] );
	}

	/* ------------------------------------------------------------------
	 * admin_date_replaced_media_on_edit_media_screen()
	 * ------------------------------------------------------------------ */

	public function test_replaced_date_without_a_post_or_global_returns_false() {
		$this->assertFalse( emr()->admin_date_replaced_media_on_edit_media_screen( null ) );
		$this->assertFalse( emr()->admin_date_replaced_media_on_edit_media_screen( '' ) );
	}

	public function test_replaced_date_prints_revised_when_modified_differs_from_date() {
		$post = new WP_Post(
			(object) array(
				'ID'            => 999991,
				'post_date'     => '2021-03-04 09:15:00',
				'post_modified' => '2021-05-06 10:20:00',
			)
		);

		$html = $this->capture(
			function () use ( $post ) {
				emr()->admin_date_replaced_media_on_edit_media_screen( $post );
			}
		);

		$this->assertStringContainsString( 'Revised', $html );
		$this->assertStringContainsString( '<b>May 6, 2021 @ 10:20</b>', $html );
		$this->assertStringNotContainsString( 'Replaced By', $html );
	}

	public function test_replaced_date_prints_nothing_for_an_unmodified_post() {
		$id = $this->make_attachment();

		$html = $this->capture(
			function () use ( $id ) {
				emr()->admin_date_replaced_media_on_edit_media_screen( get_post( $id ) );
			}
		);

		$this->assertSame( get_post( $id )->post_date, get_post( $id )->post_modified );
		$this->assertSame( '', trim( $html ) );
	}

	public function test_replaced_date_prints_the_escaped_display_name_of_the_replace_author() {
		global $wpdb;
		$user = self::factory()->user->create();
		// Written directly: wp_insert_user() strips the tags on save.
		$wpdb->update( $wpdb->users, array( 'display_name' => 'Emr <i>Replacer</i>' ), array( 'ID' => $user ) );
		clean_user_cache( $user );
		$id   = $this->make_attachment();
		update_post_meta( $id, '_emr_replace_author', $user );

		$html = $this->capture(
			function () use ( $id ) {
				emr()->admin_date_replaced_media_on_edit_media_screen( get_post( $id ) );
			}
		);

		$this->assertStringContainsString( 'Replaced By', $html );
		$this->assertStringContainsString( '<b>Emr &lt;i&gt;Replacer&lt;/i&gt;</b>', $html );
	}

	public function test_replaced_date_falls_back_to_the_global_post() {
		$GLOBALS['post'] = new WP_Post(
			(object) array(
				'ID'            => 999992,
				'post_date'     => '2021-03-04 09:15:00',
				'post_modified' => '2022-07-08 11:30:00',
			)
		);

		$html = $this->capture(
			function () {
				emr()->admin_date_replaced_media_on_edit_media_screen( null );
			}
		);

		$this->assertStringContainsString( '<b>Jul 8, 2022 @ 11:30</b>', $html );
	}

	/**
	 * The date format __('M j, Y @ H:i') is translated without a text
	 * domain, so only WordPress core's strings ('default') are consulted.
	 *
	 * How to recognise the fix: the gettext spy records
	 * 'enable-media-replace' for the format string.
	 */
	public function test_replaced_date_format_has_no_text_domain_pinned_for_deferred_fix() {
		$this->add_test_filter( 'gettext', array( $this, 'gettext_spy' ), 10, 3 );
		$post = new WP_Post(
			(object) array(
				'ID'            => 999993,
				'post_date'     => '2021-03-04 09:15:00',
				'post_modified' => '2021-05-06 10:20:00',
			)
		);

		$html = $this->capture(
			function () use ( $post ) {
				emr()->admin_date_replaced_media_on_edit_media_screen( $post );
			}
		);

		$this->assertStringContainsString( 'Revised', $html );
		$this->assertArrayHasKey( 'M j, Y @ H:i', $this->gettext_domains );
		$this->assertSame( array( 'default' ), $this->gettext_domains['M j, Y @ H:i'] );
		$this->assertSame( array( 'enable-media-replace' ), $this->gettext_domains['Revised'] );
	}

	/* ------------------------------------------------------------------
	 * attempt_uncache_image()
	 * ------------------------------------------------------------------ */

	public function test_attempt_uncache_image_passes_false_through() {
		$this->assertFalse( emr()->attempt_uncache_image( false, 57, 'thumbnail', false ) );
	}

	public function test_attempt_uncache_image_adds_a_time_argument_to_the_url_only() {
		$before = time();
		$image  = emr()->attempt_uncache_image( array( 'http://example.org/a.jpg?ver=2', 150, 100, true ), 57, 'thumbnail', false );
		$after  = time();

		$this->assertIsArray( $image );
		wp_parse_str( (string) wp_parse_url( $image[0], PHP_URL_QUERY ), $query );
		$this->assertSame( '2', $query['ver'] );
		$this->assertGreaterThanOrEqual( $before, (int) $query['time'] );
		$this->assertLessThanOrEqual( $after, (int) $query['time'] );
		$this->assertSame( array( 150, 100, true ), array_slice( $image, 1 ) );
	}

	public function test_attempt_uncache_image_works_as_the_registered_filter() {
		$this->as_admin();
		$_GET['emr_replaced'] = '1';
		emr()->runtime();
		$id = $this->make_scaled_image( array(), false );

		$src = wp_get_attachment_image_src( $id, 'thumbnail' );

		$this->assertStringContainsString( 'emr-main-photo-150x150.jpg?time=', $src[0] );
	}

	/* ------------------------------------------------------------------
	 * get_modified_date() / [file_modified]
	 * ------------------------------------------------------------------ */

	public function test_modified_date_without_an_id_returns_false() {
		$this->assertFalse( emr()->get_modified_date( '' ) );
		$this->assertFalse( emr()->get_modified_date( array() ) );
		$this->assertFalse( emr()->get_modified_date( array( 'format' => 'Y' ) ) );
	}

	public function test_modified_date_for_a_missing_file_returns_false() {
		$id = $this->make_attachment( 'emr-main-missing.jpg' );
		$this->assertFileDoesNotExist( get_attached_file( $id ) );

		$this->assertFalse( emr()->get_modified_date( array( 'id' => $id ) ) );
		$this->assertFalse( emr()->get_modified_date( array( 'id' => 'abc' ) ) );
	}

	public function test_modified_date_uses_the_date_and_time_options_by_default() {
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		$id    = $this->upload_fixture();
		$mtime = filemtime( get_attached_file( $id ) );

		$result = emr()->get_modified_date( array( 'id' => (string) $id ) );

		$this->assertIsString( $result );
		$this->assertSame( wp_date( 'Y-m-d H:i', $mtime ), $result );
	}

	public function test_modified_date_uses_a_sanitized_custom_format() {
		$id    = $this->upload_fixture();
		$mtime = filemtime( get_attached_file( $id ) );

		$this->assertSame( wp_date( 'U', $mtime ), emr()->get_modified_date( array( 'id' => $id, 'format' => 'U' ) ) );
		$this->assertSame( wp_date( 'Y', $mtime ), emr()->get_modified_date( array( 'id' => $id, 'format' => '<b>Y</b>' ) ) );
	}

	/**
	 * The @todo on get_modified_date() suspects the server date is returned
	 * without the site timezone. wp_date() converts to the site timezone:
	 * with Asia/Tokyo (UTC+9) the hour differs from the UTC hour.
	 */
	public function test_modified_date_is_in_the_site_timezone() {
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$id    = $this->upload_fixture();
		$mtime = filemtime( get_attached_file( $id ) );

		$result = emr()->get_modified_date( array( 'id' => $id, 'format' => 'Y-m-d H:i T' ) );

		$tokyo = new DateTime( '@' . $mtime );
		$tokyo->setTimezone( new DateTimeZone( 'Asia/Tokyo' ) );
		$this->assertSame( $tokyo->format( 'Y-m-d H:i T' ), $result );
		$this->assertNotSame( gmdate( 'Y-m-d H:i', $mtime ), substr( $result, 0, 16 ) );
	}

	public function test_file_modified_shortcode_renders_through_do_shortcode() {
		$id    = $this->upload_fixture();
		$mtime = filemtime( get_attached_file( $id ) );

		$this->assertTrue( shortcode_exists( 'file_modified' ) );
		$this->assertSame( wp_date( 'Y', $mtime ), do_shortcode( '[file_modified id="' . $id . '" format="Y"]' ) );
		$this->assertSame( '', do_shortcode( '[file_modified]' ), 'false renders as an empty string.' );
	}
}
