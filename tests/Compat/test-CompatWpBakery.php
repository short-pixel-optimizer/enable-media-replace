<?php
/**
 * Cross-plugin compatibility: WPBakery Page Builder.
 *
 * Runs with the REAL WPBakery (js_composer) plugin active. WPBakery is
 * commercial, so bin/test.sh --compat extracts it from a zip dropped into
 * tests/partner-plugins/js_composer.zip; without that zip every test here
 * SKIPS.
 *
 * Covers the bundled replacer module
 * build/shortpixel/replacer2/src/Modules/WpBakery.php and EMR's own
 * classes/external/wpbakery.php against the real plugin:
 *
 *   - Both detect WPBakery through did_action('vc_plugins_loaded'), with no
 *     `emr/externals/urlencode_is_active` override, and register their
 *     `emr/replacer/custom_replace_query` callback on a fresh construction.
 *   - Both write the same `wpbakery` key. The result is a keyed array, so
 *     the duplicate collapses into ONE urlencoded run: the post content is
 *     rewritten once and correctly, the duplicate is harmless today (the
 *     unit suite pins the double registration itself).
 *   - A rename (MODE_SEARCHREPLACE) rewrites the urlencoded URL of
 *     `[vc_single_image image_url="..."]` and of a `vc_link` parameter
 *     (`url:<rawurlencoded>|title:...`), which WPBakery's own
 *     vc_build_link() then decodes to the new URL.
 *   - A same-name replace (MODE_REPLACE) rewrites only the encoded size
 *     references whose file name changed; the main URL stays.
 *
 * Unit coverage with WPBakery ABSENT: tests/Replacer/test-ReplacerModules.php
 * and tests/Controller/test-Externals.php.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/CompatReplacerModules.php';

class CompatWpBakeryTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_CompatReplacerModules;

	public function set_up() {
		// WPBakery defines WPB_VC_VERSION in its main file and fires
		// vc_plugins_loaded on plugins_loaded via Vc_Manager::pluginsLoaded.
		if ( ! defined( 'WPB_VC_VERSION' ) ) {
			$this->markTestSkipped( 'WPBakery is not loaded — drop js_composer.zip into tests/partner-plugins/ and run bin/test.sh --compat.' );
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

	/** WPBakery's link parser, loaded from the plugin when the editor has not loaded it yet. */
	private function buildLink( $value ) {
		if ( ! function_exists( 'vc_build_link' ) ) {
			require_once WP_PLUGIN_DIR . '/js_composer/include/params/vc_link/vc_link.php';
		}
		return vc_build_link( $value );
	}

	// -------------------------------------------------------------------
	// Coexistence and detection
	// -------------------------------------------------------------------

	public function test_wpbakery_loads_alongside_emr() {
		$this->assertTrue( defined( 'WPB_VC_VERSION' ), 'WPBakery defines its version constant.' );
		$this->assertGreaterThan( 0, did_action( 'vc_plugins_loaded' ), 'WPBakery fired vc_plugins_loaded.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * A fresh Replacer constructs the module, which detects the real plugin
	 * through vc_plugins_loaded, with no override filter. The callback is
	 * registered as `addURLEncoded` (has_filter() compares case-sensitively).
	 */
	public function test_replacer_construction_registers_the_wpbakery_module() {
		$this->assertFalse( has_filter( 'emr/externals/urlencode_is_active' ), 'Precondition: no manual override.' );

		new \EnableMediaReplace\Replacer\Replacer();

		$module = \EnableMediaReplace\Replacer\Modules\WpBakery::getInstance();
		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $module, 'addURLEncoded' ) ) );
	}

	/** Externals::loadBakery() registers EMR's own copy of the query. */
	public function test_emr_external_registers_its_query() {
		$external = \EnableMediaReplace\Externals\WpBakery::getInstance();

		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $external, 'addURLEncoded' ) ) );
	}

	// -------------------------------------------------------------------
	// The duplicate `wpbakery` query item
	// -------------------------------------------------------------------

	/**
	 * With both copies registered, a rename runs the urlencoded replace on
	 * the post content once: the two entries share the `wpbakery` key. The
	 * string filter `emr/replacer/content` sees the post once with
	 * urlencoded search values, and the result is correct.
	 */
	public function test_the_duplicate_wpbakery_query_item_runs_once_and_is_harmless() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-vc-dup' );
		$marker           = 'emr-vc-dup-' . $this->token();
		$post             = $this->makePost( '[vc_single_image image_url="' . urlencode( $url ) . '" el_class="' . $marker . '"]' );
		$external         = \EnableMediaReplace\Externals\WpBakery::getInstance();

		$encoded_passes = 0;
		$this->addHook(
			'emr/replacer/content',
			function ( $content, $search ) use ( $marker, &$encoded_passes ) {
				if ( false !== strpos( $content, $marker ) && false !== strpos( (string) reset( $search ), '%2F' ) ) {
					++$encoded_passes;
				}
				return $content;
			},
			10,
			2
		);

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-vc-dup-new-' . $this->token() . '.jpg' );

		$module = \EnableMediaReplace\Replacer\Modules\WpBakery::getInstance();
		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $external, 'addURLEncoded' ) ), 'EMR\'s copy is registered.' );
		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $module, 'addURLEncoded' ) ), 'The module\'s copy is registered.' );
		$this->assertSame( 1, $encoded_passes, 'The post content goes through one urlencoded run.' );
		$this->assertSame(
			'[vc_single_image image_url="' . urlencode( wp_get_attachment_url( $id ) ) . '" el_class="' . $marker . '"]',
			$this->rawContent( $post ),
			'The single run rewrote the shortcode.'
		);
	}

	// -------------------------------------------------------------------
	// A real replace against WPBakery's storage
	// -------------------------------------------------------------------

	/**
	 * A rename rewrites the urlencoded image URL of a vc_single_image
	 * shortcode, in its encoded form.
	 */
	public function test_rename_rewrites_the_urlencoded_single_image_url() {
		list( $id, $url, , $old_base ) = $this->standardCompatImage( 'emr-vc-rn' );
		$post = $this->makePost( '[vc_row][vc_column][vc_single_image image_url="' . urlencode( $url ) . '"][/vc_column][/vc_row]' );
		$this->assertStringNotContainsString( $url, $this->rawContent( $post ), 'Sentinel: only the encoded form is stored.' );

		$name = 'emr-vc-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '/' . $name, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertSame(
			'[vc_row][vc_column][vc_single_image image_url="' . urlencode( $new_url ) . '"][/vc_column][/vc_row]',
			$this->rawContent( $post )
		);
		$this->assertStringNotContainsString( $old_base, $this->rawContent( $post ) );
	}

	/**
	 * A `vc_link` parameter stores its URL rawurlencoded inside
	 * `url:...|title:...`. After a rename WPBakery's own parser returns the
	 * new URL and keeps the other fields.
	 */
	public function test_rename_rewrites_a_vc_link_that_wpbakery_decodes_to_the_new_url() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-vc-link' );
		$link             = 'url:' . rawurlencode( $url ) . '|title:Download|target:_blank';
		$post             = $this->makePost( '[vc_btn title="Get it" link="' . $link . '"]' );
		$this->assertSame( $url, $this->buildLink( $link )['url'], 'Sentinel: WPBakery decodes the seeded link.' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-vc-link-new-' . $this->token() . '.jpg' );

		$this->assertSame( 1, preg_match( '/link="([^"]*)"/', $this->rawContent( $post ), $match ), 'The shortcode keeps its link attribute.' );
		$parsed = $this->buildLink( $match[1] );
		$this->assertSame( wp_get_attachment_url( $id ), $parsed['url'], 'WPBakery decodes the new URL.' );
		$this->assertSame( 'Download', $parsed['title'] );
		$this->assertSame( '_blank', $parsed['target'] );
	}

	/**
	 * A same-name replace with a new size rewrites the encoded medium
	 * reference whose file name changed and keeps the main URL, which is
	 * still valid.
	 */
	public function test_same_name_replace_rewrites_only_the_changed_encoded_size() {
		list( $id, $url, $medium ) = $this->standardCompatImage( 'emr-vc-same' );
		$post = $this->makePost( '[vc_single_image image_url="' . urlencode( $url ) . '"][vc_single_image image_url="' . urlencode( $medium ) . '"]' );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertStringEndsWith( '-300x200.jpg', $new_medium, 'Sentinel: the medium size got a new name.' );
		$this->assertSame(
			'[vc_single_image image_url="' . urlencode( $url ) . '"][vc_single_image image_url="' . urlencode( $new_medium ) . '"]',
			$this->rawContent( $post )
		);
	}
}
