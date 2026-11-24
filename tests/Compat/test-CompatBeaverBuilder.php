<?php
/**
 * Cross-plugin compatibility: Beaver Builder Lite.
 *
 * Runs with the REAL Beaver Builder Lite plugin active (wordpress.org,
 * fetched by bin/test.sh --compat); without it every test here SKIPS.
 *
 * Beaver Builder stores a layout in `_fl_builder_data` (published) and
 * `_fl_builder_draft` (editor) as an array of stdClass nodes, written by
 * FLBuilderModel::update_layout_data(), which WordPress serializes. A Photo
 * module renders `settings->photo_src` directly, so that URL must follow a
 * rename.
 *
 * The bundled replacer has no Beaver Builder module: these rows go through
 * the generic postmeta path, Replacer2::replaceContent(). It unserializes
 * meta values with `allowed_classes => ['stdClass']`, so the nodes are
 * walked as plain data objects, the URLs inside them are rewritten and the
 * layout is serialized back as stdClass nodes.
 *
 *   - A rename (MODE_SEARCHREPLACE) rewrites post_content, the published
 *     layout and the editor draft.
 *   - A same-name replace (MODE_REPLACE) with other dimensions renames the
 *     medium size; a layout showing the medium size follows.
 *   - A real Beaver Builder page carries both rows. A rename over both
 *     rows in one replace completes cleanly and clears the page's meta
 *     cache (covered below with the real Beaver Builder shape); the
 *     other replace tests seed one layout row each so the outcome on
 *     that row can be observed.
 *   - Externals::check() holds a commented-out Beaver Builder block that
 *     would disable replace-and-search. With the real plugin loaded, the
 *     replace screen's filters offer replace-and-search and no notice is
 *     printed (the unit suite covers check() itself with a stand-in
 *     class).
 *
 * Ported from SPIO's tests/Compat/test-CompatBeaverBuilder.php: the page
 * seeding through update_layout_data(), the shape sentinel and the
 * replaceContent() test. SPIO's conversion and rename halves are replaced by
 * an EMR rename and an EMR same-name replace.
 *
 * Unit coverage with Beaver Builder ABSENT: tests/Controller/test-Externals.php.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/CompatReplacerModules.php';

class CompatBeaverBuilderTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_CompatReplacerModules;

	public function set_up() {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			$this->markTestSkipped( 'Beaver Builder is not loaded — run bin/test.sh --compat.' );
		}
		parent::set_up();
		$this->flowSetUp();
		$this->snapshotModuleSingletons();
	}

	public function tear_down() {
		$this->restoreModuleSingletons();
		$this->flowTearDown();
		parent::tear_down();
	}

	/** The Beaver Builder layout: a row, a column group, a column and one Photo module showing $url. */
	private function layout( $attachment_id, $url ) {
		return array(
			'row1'   => (object) array( 'node' => 'row1', 'type' => 'row', 'parent' => null, 'position' => 0, 'settings' => (object) array() ),
			'group1' => (object) array( 'node' => 'group1', 'type' => 'column-group', 'parent' => 'row1', 'position' => 0, 'settings' => null ),
			'col1'   => (object) array( 'node' => 'col1', 'type' => 'column', 'parent' => 'group1', 'position' => 0, 'settings' => (object) array( 'size' => 100 ) ),
			'photo1' => (object) array(
				'node'     => 'photo1',
				'type'     => 'module',
				'parent'   => 'col1',
				'position' => 0,
				'settings' => (object) array(
					'type'         => 'photo',
					'photo_source' => 'library',
					'photo'        => $attachment_id,
					'photo_src'    => $url,
				),
			),
		);
	}

	/**
	 * A published Beaver Builder page with one Photo module showing $url,
	 * saved through Beaver Builder's own update_layout_data() for each of
	 * $statuses ('published' writes `_fl_builder_data`, 'draft' writes
	 * `_fl_builder_draft`). post_content carries the same URL, as Beaver
	 * Builder's rendered fallback content does, so the tests can tell that
	 * the replacer ran.
	 *
	 * @return int Post id.
	 */
	private function beaverBuilderPage( $attachment_id, $url, array $statuses ) {
		$post_id = $this->makePost( '<img src="' . $url . '" />', 'publish', 'page' );
		foreach ( $statuses as $status ) {
			\FLBuilderModel::update_layout_data( $this->layout( $attachment_id, $url ), $status, $post_id );
		}
		update_post_meta( $post_id, '_fl_builder_enabled', true );
		return $post_id;
	}

	/** The Photo module's photo_src as WordPress loads it, bypassing the cache. */
	private function photoSrc( $post_id, $key ) {
		wp_cache_delete( $post_id, 'post_meta' );
		$layout = get_post_meta( $post_id, $key, true );
		$this->assertIsArray( $layout, "$key loads as an array." );
		$this->assertInstanceOf( 'stdClass', $layout['photo1'], "$key still holds stdClass nodes." );
		return $layout['photo1']->settings->photo_src;
	}

	/** The layout meta key Beaver Builder writes for a status. */
	private function keyFor( $status ) {
		return 'published' === $status ? '_fl_builder_data' : '_fl_builder_draft';
	}

	/** @return array[] status => array( status ) */
	public function layoutStatuses() {
		return array(
			'published layout' => array( 'published' ),
			'editor draft'     => array( 'draft' ),
		);
	}

	// -------------------------------------------------------------------
	// Coexistence
	// -------------------------------------------------------------------

	public function test_beaver_builder_loads_alongside_emr() {
		$this->assertTrue( defined( 'FL_BUILDER_VERSION' ), 'Beaver Builder defines its version constant.' );
		$this->assertTrue( class_exists( 'FLBuilder' ), 'Beaver Builder declares FLBuilder, the class Externals::check() would look for.' );
		$this->assertTrue( method_exists( 'FLBuilderModel', 'update_layout_data' ), 'Beaver Builder exposes its layout writer.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	// -------------------------------------------------------------------
	// Storage shape and the replacer
	// -------------------------------------------------------------------

	/**
	 * Shape sentinel for the tests below: Beaver Builder stores its layout
	 * as serialized stdClass nodes, with the Photo URL inside a node.
	 */
	public function test_layout_is_stored_as_serialized_stdclass_nodes() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-bb-shape' );
		$post_id          = $this->beaverBuilderPage( $id, $url, array( 'published', 'draft' ) );

		foreach ( array( '_fl_builder_data', '_fl_builder_draft' ) as $key ) {
			$raw = $this->rawPostMeta( $post_id, $key );
			$this->assertStringStartsWith( 'a:', $raw, "$key is a serialized array." );
			$this->assertStringContainsString( 'O:8:"stdClass"', $raw, "$key holds stdClass nodes." );
			$this->assertStringContainsString( $url, $raw, "$key stores the URL unescaped." );
			$this->assertSame( $url, $this->photoSrc( $post_id, $key ), "$key loads with the Photo URL." );
		}
	}

	/**
	 * The generic replacer rewrites a Beaver Builder layout:
	 * Replacer2::replaceContent() unserializes meta values with
	 * `allowed_classes => ['stdClass']`, walks the nodes and serializes the
	 * layout back. The result holds the new URL and still unserializes to
	 * the same stdClass nodes.
	 */
	public function test_replacer_rewrites_the_url_inside_the_beaver_builder_layout() {
		list( $id, $old_url ) = $this->standardCompatImage( 'emr-bb-rc' );
		$new_url              = str_replace( '.jpg', '-renamed.jpg', $old_url );
		$post_id              = $this->beaverBuilderPage( $id, $old_url, array( 'published' ) );
		$raw                  = $this->rawPostMeta( $post_id, '_fl_builder_data' );
		$this->assertStringContainsString( $old_url, $raw, 'Sentinel: the layout holds the URL.' );

		$replaced = ( new \EnableMediaReplace\Replacer\Replacer2( 'emr' ) )->replaceContent( $raw, array( $old_url ), array( $new_url ), false, false );

		$this->assertIsString( $replaced );
		$this->assertStringNotContainsString( $old_url, $replaced, 'The old URL is gone.' );
		$before = unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$after  = unserialize( $replaced ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$this->assertSame( array_keys( $before ), array_keys( $after ), 'The same nodes, in the same order.' );
		$this->assertInstanceOf( 'stdClass', $after['photo1'] );
		$this->assertSame( $new_url, $after['photo1']->settings->photo_src );
		$before['photo1']->settings->photo_src = $new_url;
		$this->assertEquals( $before, $after, 'Nothing but the URL changed.' );
	}

	// -------------------------------------------------------------------
	// A real replace
	// -------------------------------------------------------------------

	/**
	 * A rename is followed by the Beaver Builder layout (the published
	 * layout and the editor draft alike, one per run) as well as by
	 * post_content, so the Photo module shows the new file.
	 *
	 * @dataProvider layoutStatuses
	 */
	public function test_rename_rewrites_the_beaver_builder_layout( $status ) {
		list( $id, $url, , $old_base ) = $this->standardCompatImage( 'emr-bb-rn' );
		$old_file                      = get_attached_file( $id );
		$key                           = $this->keyFor( $status );
		$post_id                       = $this->beaverBuilderPage( $id, $url, array( $status ) );
		$this->assertSame( $url, $this->photoSrc( $post_id, $key ), "Sentinel: $key points at the old URL." );

		$name = 'emr-bb-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '/' . $name, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertFileDoesNotExist( $old_file, 'Sentinel: the old file is gone.' );
		$this->assertSame( '<img src="' . $new_url . '" />', $this->rawContent( $post_id ), 'post_content carries the new URL.' );

		$this->assertSame( $new_url, $this->photoSrc( $post_id, $key ), "$key points at the new URL." );
		$this->assertStringNotContainsString( $old_base, $this->rawPostMeta( $post_id, $key ), 'The old file name is no longer referenced.' );
	}

	/**
	 * A same-name replace keeps the main URL valid, but an upload with
	 * other dimensions renames the medium size. A Photo module showing the
	 * medium size follows, like post_content.
	 */
	public function test_same_name_replace_rewrites_a_renamed_size_in_the_layout() {
		list( $id, $url, $medium ) = $this->standardCompatImage( 'emr-bb-same' );
		$post_id                   = $this->beaverBuilderPage( $id, $medium, array( 'published' ) );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertSame( $url, wp_get_attachment_url( $id ), 'Sentinel: the main URL is kept.' );
		$this->assertStringEndsWith( '-300x200.jpg', $new_medium, 'Sentinel: the medium size got a new name.' );
		$this->assertNotSame( $medium, $new_medium );
		$this->assertSame( '<img src="' . $new_medium . '" />', $this->rawContent( $post_id ), 'post_content carries the new medium URL.' );

		$this->assertSame( $new_medium, $this->photoSrc( $post_id, '_fl_builder_data' ), 'The layout carries the new medium URL.' );
	}

	/**
	 * A same-name replace with the same dimensions changes no URL: the
	 * replacer finds nothing to search for and the layout stays as it is,
	 * pointing at a file that still exists.
	 */
	public function test_same_name_replace_with_the_same_sizes_leaves_the_layout_valid() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-bb-keep' );
		$post_id          = $this->beaverBuilderPage( $id, $url, array( 'published', 'draft' ) );
		$raw_before       = $this->rawPostMeta( $post_id, '_fl_builder_data' );
		$md5_before       = md5_file( get_attached_file( $id ) );

		$this->replaceMode( $id, $this->tmpJpeg( 400, 300, array( 200, 40, 40 ) ) );

		$this->assertNotSame( $md5_before, md5_file( get_attached_file( $id ) ), 'Sentinel: the file was replaced.' );
		$this->assertSame( $url, wp_get_attachment_url( $id ), 'The URL is unchanged.' );
		$this->assertSame( $raw_before, $this->rawPostMeta( $post_id, '_fl_builder_data' ), 'The layout is untouched.' );
		$this->assertSame( $url, $this->photoSrc( $post_id, '_fl_builder_draft' ), 'The draft still shows the (valid) URL.' );
		$this->assertFileExists( get_attached_file( $id ), 'The referenced file exists.' );
	}

	/**
	 * A rename of an image shown on a real Beaver Builder page (published
	 * layout and editor draft, two matching postmeta rows in one replace)
	 * completes without a notice or warning: every record the replacer
	 * finds carries its own row, so every row clears the meta cache of its
	 * own post. Both layout rows and
	 * a plain string row on the same page are read back through
	 * get_post_meta() without a manual cache clean.
	 *
	 * The harness turns a notice or warning into an exception, so a
	 * regression fails on the rename call itself.
	 */
	public function test_rename_on_a_beaver_builder_page_clears_the_meta_cache_of_every_row() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-bb-cache' );
		$post_id          = $this->beaverBuilderPage( $id, $url, array( 'published', 'draft' ) );
		$this->assertGreaterThan( 0, $this->metaIdOf( $post_id, '_fl_builder_data' ), 'Sentinel: the published layout row exists.' );
		$this->assertGreaterThan( 0, $this->metaIdOf( $post_id, '_fl_builder_draft' ), 'Sentinel: the draft row exists.' );
		add_post_meta( $post_id, '_emr_bb_plain', 'image:' . $url );
		$this->assertSame( 'image:' . $url, get_post_meta( $post_id, '_emr_bb_plain', true ), 'Sentinel: the meta cache of the page is primed.' );
		$this->assertSame( $url, get_post_meta( $post_id, '_fl_builder_data', true )['photo1']->settings->photo_src, 'Sentinel: the layout is read from the primed cache.' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-bb-cache-new-' . $this->token() . '.jpg' );

		$new_url = wp_get_attachment_url( $id );
		$this->assertNotSame( $url, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertSame( 'image:' . $new_url, $this->rawPostMeta( $post_id, '_emr_bb_plain' ), 'Sentinel: the plain row was rewritten in the database.' );
		$this->assertSame( 'image:' . $new_url, get_post_meta( $post_id, '_emr_bb_plain', true ), 'The page reads the rewritten row without a manual cache clean.' );
		foreach ( array( '_fl_builder_data', '_fl_builder_draft' ) as $key ) {
			$this->assertStringNotContainsString( $url, $this->rawPostMeta( $post_id, $key ), "$key was rewritten in the database." );
			$this->assertSame( $new_url, get_post_meta( $post_id, $key, true )['photo1']->settings->photo_src, "$key is read back with the new URL." );
		}
	}

	// -------------------------------------------------------------------
	// The replace screen with Beaver Builder active
	// -------------------------------------------------------------------

	/**
	 * A rename is followed by every Beaver Builder layout (the tests
	 * above), so replace-and-search stays available with Beaver Builder
	 * active: the block of Externals::check() that would disable it is
	 * commented out. With the real plugin loaded, the Externals that
	 * runtime() constructs for a user with `upload_files` leaves both
	 * screen filters at true and prints nothing.
	 */
	public function test_replace_screen_offers_replace_and_search_with_beaver_builder_active() {
		$this->assertTrue( class_exists( 'FLBuilder' ), 'Precondition: Beaver Builder is detected the way check() would detect it.' );
		$this->assertTrue( current_user_can( 'upload_files' ), 'Precondition: the flow admin passes the runtime() capability gate.' );
		remove_all_filters( 'emr_display_replace_type_options' );
		remove_all_filters( 'emr_enable_replace_and_search' );
		remove_all_actions( 'emr_after_replace_type_options' );

		emr()->runtime();

		$this->assertSame( array( '10:get_replacesearch_type' ), $this->callbacksOf( 'emr_enable_replace_and_search', 'EnableMediaReplace\Externals' ), 'Sentinel: runtime() constructed Externals.' );
		$this->assertTrue( apply_filters( 'emr_display_replace_type_options', true ), '"Just replace" stays enabled.' );
		$this->assertTrue( apply_filters( 'emr_enable_replace_and_search', true ), 'Replace-and-search stays enabled.' );
		ob_start();
		do_action( 'emr_after_replace_type_options' );
		$this->assertSame( '', ob_get_clean(), 'No Beaver Builder notice is printed.' );
	}
}
