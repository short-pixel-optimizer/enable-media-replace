<?php
/**
 * Cross-plugin compatibility: Elementor.
 *
 * Runs with the REAL Elementor plugin active (bin/test.sh --compat
 * downloads + activates it). Covers the bundled replacer module
 * build/shortpixel/replacer2/src/Modules/Elementor.php and EMR's own
 * classes/external/elementor.php against the real plugin:
 *
 *   - Both detect Elementor through `ELEMENTOR_VERSION` without the
 *     `emr/externals/elementor_is_active` override, and register their
 *     hooks on a fresh construction.
 *   - Both register a callback on `emr/replacer/custom_replace_query` that
 *     writes the same `elementor` key. The result is a keyed array, so the
 *     duplicate collapses into ONE extra run: the Elementor row is handed
 *     to the replacer once and rewritten once. The duplicate is harmless
 *     today because both callbacks build the same entry (the unit suite
 *     pins the double registration itself).
 *   - A rename (MODE_SEARCHREPLACE) rewrites `_elementor_data` written
 *     through Elementor's own writer (Document::update_json_meta(), JSON
 *     with escaped slashes, wp_slash'ed), and Elementor still reads the
 *     result as valid JSON. The extra run re-encodes the JSON with
 *     unescaped slashes; Elementor decodes both forms.
 *   - A same-name replace (MODE_REPLACE) rewrites only the size references
 *     whose file name changed, inside `_elementor_data` too; the main URL
 *     stays, as it is still valid.
 *   - Externals\Elementor::removeCache(), on
 *     `enable-media-replace-upload-done`, clears Elementor's generated CSS:
 *     the files under uploads/elementor/css/, the `_elementor_css` post
 *     meta of every post, and fires `elementor/core/files/clear_cache`.
 *
 * Each test seeds at most one matching postmeta row per run (see
 * tests/Integration/test-ReplaceFlow.php for the cache-key defect a second
 * row hits).
 *
 * Unit coverage with Elementor ABSENT: tests/Replacer/test-ReplacerModules.php
 * and tests/Controller/test-Externals.php.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/CompatReplacerModules.php';

class CompatElementorTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_CompatReplacerModules;

	/** @var bool|null Elementor's files manager "cleared this request" flag before the test. */
	private $savedClearedFlag = null;

	public function set_up() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			$this->markTestSkipped( 'Elementor is not loaded — run via bin/test.sh --compat.' );
		}
		parent::set_up();
		$this->flowSetUp();
		$this->snapshotModuleSingletons();
		$this->savedClearedFlag = $this->clearedFlag()->getValue( \Elementor\Plugin::$instance->files_manager );
	}

	public function tear_down() {
		$this->clearedFlag()->setValue( \Elementor\Plugin::$instance->files_manager, $this->savedClearedFlag );
		$this->restoreModuleSingletons();
		$this->flowTearDown();
		parent::tear_down();
	}

	/**
	 * Elementor's files manager clears the cache once per request when its
	 * optimized-CSS experiment is on; the flag would leak between tests.
	 */
	private function clearedFlag() {
		$prop = new ReflectionProperty( get_class( \Elementor\Plugin::$instance->files_manager ), 'has_cleared_cache_this_request' );
		$prop->setAccessible( true );
		return $prop;
	}

	/** Elementor's document for a post, uncached. */
	private function document( $post_id ) {
		$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
		$this->assertIsObject( $document, 'Precondition: Elementor builds a document for the post.' );
		return $document;
	}

	/**
	 * A published post whose `_elementor_data` is written by Elementor's own
	 * writer: one image widget per URL.
	 *
	 * @return int Post id.
	 */
	private function elementorPost( array $urls, $attachment_id ) {
		$post_id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$elements = array();
		foreach ( $urls as $i => $url ) {
			$elements[] = array(
				'id'         => 'w' . ( $i + 1 ),
				'elType'     => 'widget',
				'widgetType' => 'image',
				'settings'   => array( 'image' => array( 'url' => $url, 'id' => $attachment_id ) ),
				'elements'   => array(),
			);
		}
		$this->document( $post_id )->update_json_meta( '_elementor_data', $elements );

		$raw = $this->rawPostMeta( $post_id, '_elementor_data' );
		$this->assertStringContainsString( str_replace( '/', '\\/', wp_make_link_relative( $urls[0] ) ), $raw, 'Sentinel: Elementor stores the URL with escaped slashes.' );
		return $post_id;
	}

	/** The image URLs of the post's widgets, read through Elementor. */
	private function widgetUrls( $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );
		$elements = $this->document( $post_id )->get_json_meta( '_elementor_data' );
		$this->assertIsArray( $elements, 'Elementor reads `_elementor_data` as JSON.' );
		$urls = array();
		foreach ( $elements as $element ) {
			$this->assertSame( 'widget', $element['elType'], 'The widget structure survives.' );
			$urls[] = $element['settings']['image']['url'];
		}
		return $urls;
	}

	// -------------------------------------------------------------------
	// Coexistence and detection
	// -------------------------------------------------------------------

	public function test_elementor_loads_alongside_emr() {
		$this->assertTrue( defined( 'ELEMENTOR_VERSION' ), 'Elementor defines its version constant.' );
		$this->assertGreaterThan( 0, did_action( 'elementor/loaded' ), 'Elementor finished loading.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * A fresh Replacer constructs the module, which detects the real plugin
	 * through ELEMENTOR_VERSION, with no override filter.
	 */
	public function test_replacer_construction_registers_the_elementor_module() {
		$this->assertFalse( has_filter( 'emr/externals/elementor_is_active' ), 'Precondition: no manual override.' );

		new \EnableMediaReplace\Replacer\Replacer();

		$module = \EnableMediaReplace\Replacer\Modules\Elementor::getInstance();
		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $module, 'addElementor' ) ) );
	}

	/**
	 * Externals::loadElementor() (run from runtime() on `init` for users
	 * with upload_files) registers EMR's own copy of the query and the
	 * cache flush.
	 */
	public function test_emr_external_registers_its_query_and_the_cache_flush() {
		$external = \EnableMediaReplace\Externals\Elementor::getInstance();

		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $external, 'addElementor' ) ) );
		$this->assertSame( 10, has_action( 'enable-media-replace-upload-done', array( $external, 'removeCache' ) ) );
	}

	// -------------------------------------------------------------------
	// The duplicate `elementor` query item
	// -------------------------------------------------------------------

	/**
	 * With both copies registered (EMR's external first, as runtime() does
	 * on `init`, then the module when ReplaceController builds its
	 * Replacer), a rename hands the Elementor row to the replacer exactly
	 * once: the two entries share the `elementor` key, so one run is built.
	 * The rewrite is correct, so the duplicate is harmless today.
	 */
	public function test_the_duplicate_elementor_query_item_runs_once_and_is_harmless() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-el-dup' );
		$post    = $this->elementorPost( array( $url ), $id );
		$meta_id = $this->metaIdOf( $post, '_elementor_data' );
		$external = \EnableMediaReplace\Externals\Elementor::getInstance();
		$this->addHook( 'emr/replacer/custom_replace_query', $this->record( 'runs' ), 99, 4 );
		$this->recordMetaRows();

		$name = 'emr-el-dup-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$module = \EnableMediaReplace\Replacer\Modules\Elementor::getInstance();
		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $external, 'addElementor' ) ), 'EMR\'s copy is registered.' );
		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $module, 'addElementor' ) ), 'The module\'s copy is registered.' );
		$this->assertSame( array( 'runs' ), $this->eventNames(), 'The custom runs are collected once.' );
		$this->assertArrayHasKey( 'elementor', $this->events[0][1][0], 'One `elementor` run is built from the two entries.' );
		$this->assertSame( array( 'unset' ), $this->componentsThatSawMetaRow( $meta_id ), 'The Elementor row reaches the replacer once, in the Elementor run.' );
		$this->assertSame( array( wp_get_attachment_url( $id ) ), $this->widgetUrls( $post ), 'The single run rewrote the row.' );
	}

	// -------------------------------------------------------------------
	// A real replace against Elementor's storage
	// -------------------------------------------------------------------

	/**
	 * A rename rewrites the URL inside `_elementor_data` (the main run does
	 * not match the escaped slashes, the Elementor run does), and Elementor
	 * still decodes the row.
	 */
	public function test_rename_rewrites_elementor_data_and_elementor_still_reads_it() {
		list( $id, $url, , $old_base ) = $this->standardCompatImage( 'emr-el-rn' );
		$post = $this->elementorPost( array( $url ), $id );

		$name = 'emr-el-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '/' . $name, $new_url, 'Sentinel: the attachment was renamed.' );
		$raw = $this->rawPostMeta( $post, '_elementor_data' );
		$this->assertStringNotContainsString( $old_base, $raw, 'No reference to the old name is left.' );
		$this->assertNotNull( json_decode( $raw ), 'The row is still valid JSON.' );
		$this->assertSame( array( $new_url ), $this->widgetUrls( $post ), 'Elementor reads the new URL.' );
	}

	/**
	 * A same-name replace with a new size: the main URL is still valid and
	 * stays, the medium reference whose file name changed is rewritten in
	 * `_elementor_data` as well (the Elementor run reuses the thumbnail-only
	 * search arrays).
	 */
	public function test_same_name_replace_rewrites_only_the_changed_size_in_elementor_data() {
		list( $id, $url, $medium ) = $this->standardCompatImage( 'emr-el-same' );
		$post = $this->elementorPost( array( $url, $medium ), $id );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertStringEndsWith( '-300x200.jpg', $new_medium, 'Sentinel: the medium size got a new name.' );
		$this->assertSame( $url, wp_get_attachment_url( $id ), 'Sentinel: the main URL is unchanged.' );
		$this->assertSame( array( $url, $new_medium ), $this->widgetUrls( $post ), 'Main URL kept, medium rewritten.' );
	}

	/**
	 * A same-name replace with the same dimensions changes no URL: the
	 * search arrays are empty, no custom run is built and the row stays
	 * byte-identical.
	 */
	public function test_same_name_replace_with_the_same_sizes_leaves_elementor_data_byte_identical() {
		list( $id, $url, $medium ) = $this->standardCompatImage( 'emr-el-same-dim' );
		$post   = $this->elementorPost( array( $url, $medium ), $id );
		$before = $this->rawPostMeta( $post, '_elementor_data' );

		$this->replaceMode( $id, $this->tmpJpeg( 400, 300, array( 0, 0, 0 ) ) );

		$this->assertSame( $before, $this->rawPostMeta( $post, '_elementor_data' ) );
	}

	/**
	 * Classic markup in post_content is still rewritten by the main run
	 * while the Elementor run is registered.
	 */
	public function test_rename_still_rewrites_classic_post_content_with_elementor_active() {
		list( $id, $url, , $old_base ) = $this->standardCompatImage( 'emr-el-classic' );
		$post = $this->makePost( '<p><img src="' . $url . '" alt="classic" /></p>' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-el-classic-new-' . $this->token() . '.jpg' );

		$this->assertSame( '<p><img src="' . wp_get_attachment_url( $id ) . '" alt="classic" /></p>', $this->rawContent( $post ) );
		$this->assertStringNotContainsString( $old_base, $this->rawContent( $post ) );
	}

	// -------------------------------------------------------------------
	// Elementor's CSS cache
	// -------------------------------------------------------------------

	/**
	 * After a replace, EMR's external calls Elementor's files manager
	 * clear_cache(): the generated CSS files are deleted, the `_elementor_css`
	 * meta is removed from every post and Elementor fires its own
	 * `elementor/core/files/clear_cache` action. Rows written before the
	 * replace roll back with the test; the CSS file is created and removed
	 * here.
	 */
	public function test_replace_clears_elementor_css_cache_through_the_external() {
		$external = \EnableMediaReplace\Externals\Elementor::getInstance();
		$this->assertSame( 10, has_action( 'enable-media-replace-upload-done', array( $external, 'removeCache' ) ), 'Precondition: the flush is hooked.' );
		$this->clearedFlag()->setValue( \Elementor\Plugin::$instance->files_manager, false );

		$css_dir = \Elementor\Core\Files\Base::get_base_uploads_dir() . \Elementor\Core\Files\Base::DEFAULT_FILES_DIR;
		wp_mkdir_p( $css_dir );
		$css_file          = $css_dir . 'post-emr-compat-' . $this->token() . '.css';
		$this->flowFiles[] = $css_file;
		file_put_contents( $css_file, '.emr{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$styled = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $styled, '_elementor_css', array( 'status' => 'file', 'time' => 1 ) );

		list( $id ) = $this->standardCompatImage( 'emr-el-css' );
		$this->addHook( 'elementor/core/files/clear_cache', $this->record( 'elementor_clear_cache' ), 10, 0 );
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ), 5, 3 );

		$this->replaceMode( $id, $this->tmpJpeg( 400, 300 ) );

		$this->assertSame( array( 'upload_done', 'elementor_clear_cache' ), $this->eventNames(), 'Elementor cleared its cache once, after the replace.' );
		$this->assertFileDoesNotExist( $css_file, 'The generated CSS file is deleted.' );
		wp_cache_delete( $styled, 'post_meta' );
		$this->assertSame( '', get_post_meta( $styled, '_elementor_css', true ), 'The `_elementor_css` meta of other posts is removed.' );
	}
}
