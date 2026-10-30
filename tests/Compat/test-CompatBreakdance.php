<?php
/**
 * Cross-plugin compatibility: Breakdance.
 *
 * Runs with the REAL Breakdance plugin active. Breakdance is commercial,
 * so bin/test.sh --compat extracts it from a zip dropped into
 * tests/partner-plugins/breakdance-*.zip; without that zip every test here
 * SKIPS.
 *
 * Covers the bundled replacer module
 * build/shortpixel/replacer2/src/Modules/Breakdance.php, the only
 * coverage of its Breakdance-facing half: it calls the real
 * \Breakdance\Data functions directly.
 *
 *   - Detection: has_action('breakdance_loaded') and the four required
 *     \Breakdance\Data functions. A fresh Replacer construction registers
 *     addBreakdance(), loadContent() and saveContent().
 *   - Storage: `_breakdance_data` is an outer JSON object whose
 *     `tree_json_string` value is itself a JSON string, written by
 *     Breakdance's set_meta() (wp_json_encode, so the URLs are stored
 *     single-escaped, `\/`). The module's LIKE pattern (addSlash()) matches
 *     that form.
 *   - Breakdance alone (Elementor support switched off with
 *     `emr/externals/elementor_is_active`): a rename hands the row to the
 *     Breakdance run with its post_id, loadContent() loads the tree through
 *     get_tree(), the replacer rewrites it and saveContent() writes it back
 *     through save_document(). The same holds for a same-name replace that
 *     renames a size.
 *   - Breakdance with Elementor active (every --compat run): the Elementor
 *     run uses the same LIKE pattern and runs first. It rewrites the row with
 *     the generic replacer, which encodes `tree_json_string` back to a
 *     string, so the document still loads; the Breakdance run then finds
 *     nothing left to match. EMR registers its
 *     Elementor support twice (the module and Externals\Elementor), and the
 *     only switch, `emr/externals/elementor_is_active`, turns both off.
 *   - Breakdance 2.x: saveContent() passes `false` as futureLayerMeta to the
 *     ten-argument save_document(), which stores it unconditionally, so a
 *     replace that rewrites a document wipes `_breakdance_futurelayer_meta`.
 *     Pinned below; Breakdance 3.0 and later skip a false value.
 *
 * Unit coverage with Breakdance ABSENT (registration, the abort fallback,
 * the run's args, pass-through for other runs):
 * tests/Replacer/test-ReplacerModules.php.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/CompatReplacerModules.php';

class CompatBreakdanceTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_CompatReplacerModules;

	public function set_up() {
		if ( ! defined( 'BREAKDANCE_MODE' ) ) {
			$this->markTestSkipped( 'Breakdance is not loaded — drop breakdance-*.zip into tests/partner-plugins/ and run bin/test.sh --compat.' );
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

	/**
	 * A minimal valid Breakdance tree with one image element per URL. The
	 * nodes carry a type because save_document() renders the post's cache,
	 * which reads it.
	 */
	private function breakdanceTree( array $urls ) {
		$children = array();
		foreach ( $urls as $i => $url ) {
			$children[] = array(
				'id'   => 'image-node-' . ( $i + 1 ),
				'data' => array(
					'type'       => 'EssentialElements\\Image',
					'properties' => array( 'image' => array( 'url' => $url ) ),
				),
			);
		}
		return array(
			'root' => array(
				'id'       => 'root-node',
				'data'     => array(
					'type'       => 'root',
					'properties' => array(),
				),
				'children' => $children,
			),
		);
	}

	/**
	 * A published post with a `_breakdance_data` document written by
	 * Breakdance's set_meta(), as save_document() writes it: the editor's
	 * JSON.stringify output (slashes unescaped) as `tree_json_string`.
	 *
	 * @return int Post id.
	 */
	private function breakdancePost( array $urls ) {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'EMR Breakdance document' ) );
		\Breakdance\Data\set_meta(
			$post_id,
			'_breakdance_data',
			array( 'tree_json_string' => wp_json_encode( $this->breakdanceTree( $urls ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) )
		);
		return $post_id;
	}

	/** The image URLs of the document, loaded through Breakdance's get_tree(). */
	private function documentUrls( $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );
		$tree = \Breakdance\Data\get_tree( $post_id );
		$this->assertIsArray( $tree, 'Breakdance loads the document.' );
		$this->assertSame( 'root-node', $tree['root']['id'], 'The tree structure survives.' );
		$urls = array();
		foreach ( $tree['root']['children'] as $child ) {
			$urls[] = $child['data']['properties']['image']['url'];
		}
		return $urls;
	}

	/** The outer `_breakdance_data` payload, decoded straight from the database. */
	private function storedBreakdanceData( $post_id ) {
		return (array) json_decode( $this->rawPostMeta( $post_id, '_breakdance_data' ), true );
	}

	/** Runs the next replace as on a site where EMR's Elementor support is off. */
	private function withoutElementorSupport() {
		$this->addHook( 'emr/externals/elementor_is_active', '__return_false' );
		$this->resetModuleSingletons();
	}

	// -------------------------------------------------------------------
	// Coexistence and detection
	// -------------------------------------------------------------------

	public function test_breakdance_loads_alongside_emr() {
		$this->assertTrue( defined( 'BREAKDANCE_MODE' ), 'Breakdance defines BREAKDANCE_MODE.' );
		$this->assertNotFalse( has_action( 'breakdance_loaded' ), 'Breakdance wired its breakdance_loaded action.' );
		foreach ( array( 'get_tree', 'encode_before_writing_to_wp', 'get_global_option', 'save_document' ) as $function ) {
			$this->assertTrue( function_exists( '\\Breakdance\\Data\\' . $function ), "Breakdance\\Data\\$function exists." );
		}
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * A fresh Replacer constructs the module, which finds the real plugin
	 * and its functions and registers its three callbacks, not the abort
	 * fallback.
	 */
	public function test_replacer_construction_registers_the_breakdance_module() {
		new \EnableMediaReplace\Replacer\Replacer();

		$module = \EnableMediaReplace\Replacer\Modules\Breakdance::getInstance();
		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $module, 'addBreakdance' ) ) );
		$this->assertSame( 10, has_filter( 'emr/replacer/load_meta_value', array( $module, 'loadContent' ) ) );
		$this->assertSame( 10, has_filter( 'emr/replacer/save_meta_value', array( $module, 'saveContent' ) ) );
		$this->assertFalse( has_filter( 'emr/replacer/load_meta_value', array( $module, 'abortOnContent' ) ), 'The abort fallback is not registered.' );
	}

	// -------------------------------------------------------------------
	// Breakdance's storage shape and loadContent()
	// -------------------------------------------------------------------

	/**
	 * Shape sentinel for the replace tests: the document stores its URLs
	 * single-escaped, the form the module's LIKE pattern matches, and
	 * get_tree() decodes the plain URL.
	 */
	public function test_a_breakdance_document_stores_its_urls_single_escaped() {
		$url  = 'http://example.org/wp-content/uploads/2026/10/emr-bd-shape.jpg';
		$post = $this->breakdancePost( array( $url ) );

		$raw = $this->rawPostMeta( $post, '_breakdance_data' );
		$this->assertStringContainsString( str_replace( '/', '\\/', $url ), $raw, 'The URL is stored single-escaped.' );
		$this->assertStringNotContainsString( '\\\\\\/', $raw, 'The URL is not escaped twice.' );
		$this->assertIsString( $this->storedBreakdanceData( $post )['tree_json_string'], 'tree_json_string is a JSON string.' );
		$this->assertSame( array( $url ), $this->documentUrls( $post ) );
	}

	/**
	 * loadContent() replaces the raw meta value of a post meta row with the
	 * tree Breakdance's get_tree() decodes, so the replacer walks the
	 * array instead of the nested JSON string.
	 */
	public function test_load_content_returns_the_tree_breakdance_decodes() {
		$url    = 'http://example.org/wp-content/uploads/2026/10/emr-bd-load.jpg';
		$post   = $this->breakdancePost( array( $url ) );
		$raw    = $this->rawPostMeta( $post, '_breakdance_data' );
		$row    = array( 'meta_id' => (string) $this->metaIdOf( $post, '_breakdance_data' ), 'meta_value' => $raw, 'post_id' => (string) $post );
		$module = \EnableMediaReplace\Replacer\Modules\Breakdance::getInstance();

		$tree = $module->loadContent( $raw, $row, 'breakdance' );

		$this->assertIsArray( $tree, 'The tree, not the raw string.' );
		$this->assertSame( 'root-node', $tree['root']['id'] );
		$this->assertSame( $url, $tree['root']['children'][0]['data']['properties']['image']['url'], 'The URL is reachable in the tree.' );
	}

	// -------------------------------------------------------------------
	// A real replace, Breakdance alone
	// -------------------------------------------------------------------

	/**
	 * With EMR's Elementor support off, a rename hands the document row to
	 * the Breakdance run only, with its post_id; the module loads the tree,
	 * the replacer rewrites it and save_document() stores it. Breakdance
	 * loads the new URL and `tree_json_string` stays a string.
	 */
	public function test_rename_without_elementor_rewrites_the_document_through_breakdance() {
		$this->withoutElementorSupport();
		list( $id, $url, , $old_base ) = $this->standardCompatImage( 'emr-bd-rn' );
		$post    = $this->breakdancePost( array( $url ) );
		$meta_id = $this->metaIdOf( $post, '_breakdance_data' );
		$this->recordMetaRows();

		$name = 'emr-bd-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '/' . $name, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertFalse( has_filter( 'emr/replacer/custom_replace_query', array( \EnableMediaReplace\Replacer\Modules\Elementor::getInstance(), 'addElementor' ) ), 'Sentinel: no Elementor run.' );
		$this->assertSame( array( 'breakdance' ), $this->componentsThatSawMetaRow( $meta_id ), 'Only the Breakdance run reaches the row.' );
		$rows = wp_list_filter( wp_list_pluck( $this->metaRowsSeen, 1 ), array( 'meta_id' => (string) $meta_id ) );
		$this->assertSame( (string) $post, reset( $rows )['post_id'], 'The row carries its post_id.' );
		$this->assertIsString( $this->storedBreakdanceData( $post )['tree_json_string'], 'tree_json_string stays a string.' );
		$this->assertStringNotContainsString( $old_base, $this->rawPostMeta( $post, '_breakdance_data' ), 'No reference to the old name is left.' );
		$this->assertSame( array( $new_url ), $this->documentUrls( $post ), 'Breakdance loads the new URL.' );
	}

	/**
	 * With EMR's Elementor support off, a same-name replace that renames the
	 * medium size rewrites that reference in the document through
	 * Breakdance and keeps the unchanged main URL.
	 */
	public function test_same_name_replace_without_elementor_rewrites_the_changed_size_through_breakdance() {
		$this->withoutElementorSupport();
		list( $id, $url, $medium ) = $this->standardCompatImage( 'emr-bd-same' );
		$post = $this->breakdancePost( array( $url, $medium ) );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertStringEndsWith( '-300x200.jpg', $new_medium, 'Sentinel: the medium size got a new name.' );
		$this->assertSame( array( $url, $new_medium ), $this->documentUrls( $post ), 'Main URL kept, medium rewritten.' );
		$this->assertIsString( $this->storedBreakdanceData( $post )['tree_json_string'], 'tree_json_string stays a string.' );
	}

	/**
	 * With Elementor active, a rename still leaves a loadable Breakdance
	 * document. The Elementor run (the module's and Externals\Elementor's
	 * shared `elementor` entry) builds the same LIKE pattern as the
	 * Breakdance run and runs before it, so the generic replacer handles the
	 * `_breakdance_data` row and the Breakdance run finds nothing left to
	 * match. replaceContent() encodes the nested `tree_json_string` back
	 * after walking it, so it stays a string and get_tree() loads the new
	 * URL.
	 */
	public function test_rename_with_elementor_active_keeps_the_breakdance_document_loadable() {
		$this->assertTrue( defined( 'ELEMENTOR_VERSION' ), 'Precondition: Elementor is active (every --compat run loads it).' );
		list( $id, $url, , $old_base ) = $this->standardCompatImage( 'emr-bd-el' );
		$post    = $this->breakdancePost( array( $url ) );
		$meta_id = $this->metaIdOf( $post, '_breakdance_data' );
		$this->assertIsString( $this->storedBreakdanceData( $post )['tree_json_string'], 'Sentinel: tree_json_string is a string before the replace.' );
		$this->recordMetaRows();

		$name = 'emr-bd-el-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '/' . $name, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertSame( array( 'unset' ), $this->componentsThatSawMetaRow( $meta_id ), 'Only the Elementor run (component `unset`) reaches the row.' );
		$this->assertIsString( $this->storedBreakdanceData( $post )['tree_json_string'], 'tree_json_string stays a string.' );
		$this->assertStringNotContainsString( $old_base, $this->rawPostMeta( $post, '_breakdance_data' ), 'No reference to the old name is left.' );
		$this->assertSame( array( $new_url ), $this->documentUrls( $post ), 'Breakdance loads the new URL.' );
	}

	// -------------------------------------------------------------------
	// Pinned defects
	// -------------------------------------------------------------------

	/**
	 * On Breakdance 2.x, a replace that rewrites a document wipes its
	 * `_breakdance_futurelayer_meta`: Modules\Breakdance::saveContent()
	 * calls the ten-argument save_document() with `false` as
	 * futureLayerMeta, and Breakdance before 3.0 stores that value
	 * unconditionally. The correct behaviour is to pass the stored value,
	 * \Breakdance\Data\get_meta($post_id, '_breakdance_futurelayer_meta').
	 * Breakdance 3.0 and later skip a false value; with such a zip this
	 * test skips, because the defect cannot be observed there.
	 *
	 * How to recognise the fix: the meta keeps its sentinel value and the
	 * pinned assertFalse below fails.
	 */
	public function test_rename_wipes_the_future_layer_meta_on_breakdance_2_pinned_for_deferred_fix() {
		$version = defined( '__BREAKDANCE_VERSION' ) ? constant( '__BREAKDANCE_VERSION' ) : '0';
		if ( version_compare( $version, '3.0.0', '>=' ) ) {
			$this->markTestSkipped( 'Breakdance ' . $version . ' keeps a false futureLayerMeta out of the database; the defect shows on 2.x only.' );
		}
		$reflection = new \ReflectionFunction( 'Breakdance\Data\save_document' );
		$this->assertSame( 10, $reflection->getNumberOfParameters(), 'Sentinel: the ten-argument save_document() the module calls with false.' );

		$this->withoutElementorSupport();
		list( $id, $url ) = $this->standardCompatImage( 'emr-bd-fl' );
		$post = $this->breakdancePost( array( $url ) );
		\Breakdance\Data\set_meta( $post, '_breakdance_futurelayer_meta', '{"sentinel":"keep-me"}' );
		$this->assertSame( '{"sentinel":"keep-me"}', \Breakdance\Data\get_meta( $post, '_breakdance_futurelayer_meta' ), 'Sentinel: the future-layer meta is stored.' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-bd-fl-new-' . $this->token() . '.jpg' );

		$this->assertSame( array( wp_get_attachment_url( $id ) ), $this->documentUrls( $post ), 'Sentinel: the document was rewritten through save_document().' );
		wp_cache_delete( $post, 'post_meta' );
		$this->assertFalse( \Breakdance\Data\get_meta( $post, '_breakdance_futurelayer_meta' ), 'Pinned: the future-layer meta is overwritten with false.' );
	}
}
