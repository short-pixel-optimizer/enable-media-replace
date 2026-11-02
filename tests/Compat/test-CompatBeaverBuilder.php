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
 * the generic postmeta path, Replacer::replaceContent(). It unserializes
 * meta values with `allowed_classes => false`, which turns every node into
 * a __PHP_Incomplete_Class, and returns a value holding an incomplete
 * object unchanged. The URLs inside the nodes are never rewritten (pinned
 * below); the layout itself stays intact and still loads.
 *
 *   - A rename (MODE_SEARCHREPLACE) rewrites post_content and leaves the
 *     published layout and the editor draft on the old URL, a file that no
 *     longer exists.
 *   - A same-name replace (MODE_REPLACE) with other dimensions renames the
 *     medium size; a layout showing the medium size keeps the old size URL
 *     the same way.
 *   - A real Beaver Builder page carries both rows. A rename over both
 *     rows in one replace completes cleanly and clears the page's meta
 *     cache (covered below with the real Beaver Builder shape); the
 *     other replace tests seed one layout row each so the outcome on
 *     that row can be observed.
 *   - Externals::check() holds a commented-out Beaver Builder block that
 *     would disable replace-and-search. With the real plugin loaded, the
 *     replace screen's filters still offer replace-and-search and no notice
 *     is printed (pinned below; the unit suite pins check() itself with a
 *     stand-in class).
 *
 * Ported from SPIO's tests/Compat/test-CompatBeaverBuilder.php: the page
 * seeding through update_layout_data(), the shape sentinel and the
 * replaceContent() pin. SPIO's conversion and rename halves are replaced by
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
	 * The generic replacer returns a Beaver Builder layout byte-identical:
	 * Replacer::replaceContent() unserializes meta values with
	 * `allowed_classes => false`, every node becomes a
	 * __PHP_Incomplete_Class, and a value holding an incomplete object is
	 * returned as the original serialized string. The correct behaviour is
	 * to rewrite the URLs inside plain data objects: unserializing with
	 * `allowed_classes => array( 'stdClass' )` keeps the object-injection
	 * hardening (stdClass has no magic methods) and makes the nodes
	 * replaceable.
	 *
	 * How to recognise the fix: the returned layout carries the new URL, it
	 * still unserializes to the same nodes, and the assertSame below fails.
	 */
	public function test_replacer_returns_the_beaver_builder_layout_unchanged_pinned_for_deferred_fix() {
		list( $id, $old_url ) = $this->standardCompatImage( 'emr-bb-rc' );
		$new_url              = str_replace( '.jpg', '-renamed.jpg', $old_url );
		$post_id              = $this->beaverBuilderPage( $id, $old_url, array( 'published' ) );
		$raw                  = $this->rawPostMeta( $post_id, '_fl_builder_data' );
		$this->assertStringContainsString( $old_url, $raw, 'Sentinel: the layout holds the URL.' );

		$replaced = \EnableMediaReplace\Replacer\Replacer::getInstance()->replaceContent( $raw, array( $old_url ), array( $new_url ), false, false );

		$this->assertIsString( $replaced );
		$this->assertSame( $raw, $replaced, 'Pinned: the layout comes back byte-identical.' );
		$this->assertStringNotContainsString( $new_url, $replaced, 'Pinned: the new URL is not written.' );
	}

	// -------------------------------------------------------------------
	// A real replace
	// -------------------------------------------------------------------

	/**
	 * A rename leaves the Beaver Builder layout (the published layout and
	 * the editor draft alike, one per run) on the old URL while post_content
	 * follows the rename. The old file is gone, so the Photo module shows a
	 * broken image. Same cause as
	 * test_replacer_returns_the_beaver_builder_layout_unchanged_pinned_for_deferred_fix.
	 *
	 * How to recognise the fix: photo_src is the new URL and the pinned
	 * assertSame below fails.
	 *
	 * @dataProvider layoutStatuses
	 */
	public function test_rename_leaves_the_beaver_builder_layout_on_the_old_url_pinned_for_deferred_fix( $status ) {
		list( $id, $url, , $old_base ) = $this->standardCompatImage( 'emr-bb-rn' );
		$old_file                      = get_attached_file( $id );
		$key                           = $this->keyFor( $status );
		$post_id                       = $this->beaverBuilderPage( $id, $url, array( $status ) );
		$raw_before                    = $this->rawPostMeta( $post_id, $key );

		$name = 'emr-bb-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '/' . $name, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertFileDoesNotExist( $old_file, 'Sentinel: the old file is gone.' );
		$this->assertSame( '<img src="' . $new_url . '" />', $this->rawContent( $post_id ), 'Sentinel: the replacer ran, post_content carries the new URL.' );

		$this->assertSame( $raw_before, $this->rawPostMeta( $post_id, $key ), "Pinned: $key is byte-identical." );
		$this->assertSame( $url, $this->photoSrc( $post_id, $key ), "Pinned: $key still points at the old URL." );
		$this->assertStringContainsString( $old_base, $this->photoSrc( $post_id, $key ), 'Pinned: the old file name is still referenced.' );
	}

	/**
	 * A same-name replace keeps the main URL valid, but an upload with
	 * other dimensions renames the medium size. A Photo module showing the
	 * medium size keeps the old size URL in the layout while post_content
	 * follows. Same cause as
	 * test_replacer_returns_the_beaver_builder_layout_unchanged_pinned_for_deferred_fix.
	 *
	 * How to recognise the fix: photo_src is the new medium URL and the
	 * pinned assertSame below fails.
	 */
	public function test_same_name_replace_leaves_a_renamed_size_on_the_old_url_pinned_for_deferred_fix() {
		list( $id, $url, $medium ) = $this->standardCompatImage( 'emr-bb-same' );
		$post_id                   = $this->beaverBuilderPage( $id, $medium, array( 'published' ) );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertSame( $url, wp_get_attachment_url( $id ), 'Sentinel: the main URL is kept.' );
		$this->assertStringEndsWith( '-300x200.jpg', $new_medium, 'Sentinel: the medium size got a new name.' );
		$this->assertSame( '<img src="' . $new_medium . '" />', $this->rawContent( $post_id ), 'Sentinel: post_content carries the new medium URL.' );

		$this->assertSame( $medium, $this->photoSrc( $post_id, '_fl_builder_data' ), 'Pinned: the layout keeps the old medium URL.' );
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
	 * completes without a notice or warning: Replacer::handleMetaData()
	 * keeps the selected column name apart from each row's post id, so the
	 * second row clears the meta cache of its own post. The layout rows
	 * themselves still come back unchanged (the allowed_classes pin above),
	 * so the cleared cache is observed through a plain string row on the
	 * same page, which the replacer does rewrite.
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

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-bb-cache-new-' . $this->token() . '.jpg' );

		$new_url = wp_get_attachment_url( $id );
		$this->assertNotSame( $url, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertSame( 'image:' . $new_url, $this->rawPostMeta( $post_id, '_emr_bb_plain' ), 'Sentinel: the plain row was rewritten in the database.' );
		$this->assertSame( 'image:' . $new_url, get_post_meta( $post_id, '_emr_bb_plain', true ), 'The page reads the rewritten row without a manual cache clean.' );
		$this->assertStringContainsString( $url, $this->rawPostMeta( $post_id, '_fl_builder_data' ), 'Sentinel: the layout row still carries the old URL (allowed_classes pin).' );
	}

	// -------------------------------------------------------------------
	// The replace screen with Beaver Builder active
	// -------------------------------------------------------------------

	/**
	 * Externals::check() should disable replace-and-search when Beaver
	 * Builder is active (the tests above show that a rename leaves every
	 * Beaver Builder layout on the old URL) and print a notice under the
	 * replace options. The block is commented out, so with the real plugin
	 * loaded, the Externals that runtime() constructs for a user with
	 * `upload_files` leaves both screen filters at true and prints nothing.
	 *
	 * How to recognise the fix: `emr_enable_replace_and_search` answers
	 * false and the notice is printed, so the pinned assertions fail. A fix
	 * that removes check() instead keeps this behaviour.
	 */
	public function test_replace_screen_still_offers_replace_and_search_with_beaver_builder_active_pinned_for_deferred_fix() {
		$this->assertTrue( class_exists( 'FLBuilder' ), 'Precondition: Beaver Builder is detected the way check() would detect it.' );
		$this->assertTrue( current_user_can( 'upload_files' ), 'Precondition: the flow admin passes the runtime() capability gate.' );
		remove_all_filters( 'emr_display_replace_type_options' );
		remove_all_filters( 'emr_enable_replace_and_search' );
		remove_all_actions( 'emr_after_replace_type_options' );

		emr()->runtime();

		$this->assertSame( array( '10:get_replacesearch_type' ), $this->callbacksOf( 'emr_enable_replace_and_search', 'EnableMediaReplace\Externals' ), 'Sentinel: runtime() constructed Externals.' );
		$this->assertTrue( apply_filters( 'emr_display_replace_type_options', true ), '"Just replace" stays enabled.' );
		$this->assertTrue( apply_filters( 'emr_enable_replace_and_search', true ), 'Pinned: replace-and-search stays enabled.' );
		ob_start();
		do_action( 'emr_after_replace_type_options' );
		$this->assertSame( '', ob_get_clean(), 'Pinned: no Beaver Builder notice is printed.' );
	}
}
