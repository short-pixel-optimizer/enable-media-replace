<?php
/**
 * Cross-plugin compatibility: Bricks (theme).
 *
 * Runs with the REAL Bricks theme active. Bricks is commercial, so
 * bin/test.sh --compat extracts it from a zip dropped into
 * tests/partner-themes/bricks*.zip and tests/bootstrap.php makes it the
 * active theme; without that zip every test here SKIPS.
 *
 * Bricks stores a page's element tree in `_bricks_page_content_2`
 * (BRICKS_DB_PAGE_CONTENT) and shared components in the `bricks_components`
 * option (BRICKS_DB_COMPONENTS), both as arrays that WordPress serializes.
 * A Nested Slider with custom options keeps them as a JSON string in
 * `settings['options']`, and its render() passes any non-array value to
 * stripslashes().
 *
 * The bundled replacer has no Bricks module: these rows go through the
 * generic postmeta and options passes, Replacer::replaceContent(). When a
 * row contains a URL being replaced, the replacer json_decode()s every
 * JSON-looking string it meets while recursing and encodes it back at the
 * same level, so the slider's options stay the JSON string Bricks passes to
 * stripslashes() (a stdClass there is a fatal, "stripslashes(): Argument #1
 * ($string) must be of type string, stdClass given", on every page that
 * renders the slider). Covered below for an EMR rename, on a page and in a
 * component, and for a same-name replace whose upload renames a size. A
 * same-name replace that changes no URL leaves the row alone and the slider
 * intact.
 *
 * Ported from SPIO's tests/Compat/test-CompatBricks.php: the element
 * arrays, the render helper and the slider assertion.
 * SPIO's rename and PNG conversion are replaced by EMR replaces.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/CompatReplacerModules.php';

class CompatBricksTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_CompatReplacerModules;

	/** The custom Splide options a Nested Slider keeps as a JSON string. */
	const SLIDER_OPTIONS = '{"type":"loop","perPage":3,"gap":"1em"}';

	/** @var array( exists, value ) The components option before the test. */
	private $savedComponents = array( false, null );

	public function set_up() {
		if ( ! defined( 'BRICKS_VERSION' ) || ! class_exists( '\\Bricks\\Frontend' ) ) {
			$this->markTestSkipped( 'Bricks is not the active theme — drop bricks*.zip into tests/partner-themes/ and run bin/test.sh --compat.' );
		}
		parent::set_up();
		// Bricks blocks writes to its page content for users without builder
		// access; flowSetUp() makes an administrator current. The replacer
		// writes with direct SQL and is not subject to that check.
		$this->flowSetUp();
		$this->snapshotModuleSingletons();

		$missing               = new stdClass();
		$value                 = get_option( BRICKS_DB_COMPONENTS, $missing );
		$this->savedComponents = ( $value === $missing ) ? array( false, null ) : array( true, $value );
	}

	public function tear_down() {
		if ( $this->savedComponents[0] ) {
			update_option( BRICKS_DB_COMPONENTS, $this->savedComponents[1] );
		} else {
			delete_option( BRICKS_DB_COMPONENTS );
		}
		$this->restoreModuleSingletons();
		$this->flowTearDown();
		parent::tear_down();
	}

	/**
	 * A Bricks element tree: a Nested Slider with custom options and, next
	 * to it, an Image element showing $url (what makes the replacer match
	 * the row).
	 */
	private function elements( $attachment_id, $url ) {
		return array(
			array(
				'id'       => 'slidr1',
				'name'     => 'slider-nested',
				'parent'   => 0,
				'children' => array(),
				'settings' => array(
					'optionsType' => 'custom',
					'options'     => self::SLIDER_OPTIONS,
				),
			),
			array(
				'id'       => 'image1',
				'name'     => 'image',
				'parent'   => 0,
				'children' => array(),
				'settings' => array(
					'image' => array(
						'id'   => $attachment_id,
						'url'  => $url,
						'size' => 'full',
					),
				),
			),
		);
	}

	/** A published Bricks page whose element tree is $elements. */
	private function bricksPage( array $elements ) {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '' ) );
		update_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, $elements );
		return $post_id;
	}

	/** The page's element tree as WordPress loads it, bypassing the cache. */
	private function pageElements( $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );
		return (array) get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );
	}

	/** The first component's elements, loaded through get_option(). */
	private function componentElements() {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( BRICKS_DB_COMPONENTS, 'options' );
		$components = (array) get_option( BRICKS_DB_COMPONENTS );
		$this->assertArrayHasKey( 0, $components, 'The component survives.' );
		$this->assertSame( 'cmpnt1', $components[0]['id'] );
		return $components[0]['elements'];
	}

	/**
	 * Renders an element tree through Bricks; returns the HTML or the
	 * Throwable. A fatal inside an element leaves Bricks' own output buffers
	 * open, so they are closed here.
	 */
	private function render( array $elements ) {
		$level = ob_get_level();
		try {
			return (string) \Bricks\Frontend::render_data( $elements );
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			return $e;
		}
	}

	/** Asserts that the slider options are the JSON string and the elements render the slider. */
	private function assertSliderIntact( array $elements, $message ) {
		$this->assertSame( self::SLIDER_OPTIONS, $elements[0]['settings']['options'], $message . ': the options are the JSON string.' );
		$html = $this->render( $elements );
		$this->assertIsString( $html, $message . ': the elements render without error.' );
		$this->assertStringContainsString( 'splide', $html, $message . ': the Nested Slider rendered.' );
	}

	// -------------------------------------------------------------------
	// Coexistence
	// -------------------------------------------------------------------

	public function test_bricks_is_the_active_theme() {
		$this->assertSame( 'bricks', get_template(), 'tests/bootstrap.php activates the Bricks theme.' );
		$this->assertSame( '_bricks_page_content_2', BRICKS_DB_PAGE_CONTENT );
		$this->assertSame( 'bricks_components', BRICKS_DB_COMPONENTS );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * Sentinel for the pins below: an untouched Nested Slider with custom
	 * options, stored the way Bricks stores it, loads back with the options
	 * as a JSON string and renders without error.
	 */
	public function test_nested_slider_with_custom_options_renders() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-bricks-shape' );
		$post_id          = $this->bricksPage( $this->elements( $id, $url ) );

		$this->assertStringContainsString( $url, $this->rawPostMeta( $post_id, BRICKS_DB_PAGE_CONTENT ), 'The URL is stored unescaped in the serialized tree.' );
		$this->assertSliderIntact( $this->pageElements( $post_id ), 'Before any replace' );
	}

	// -------------------------------------------------------------------
	// A real rename
	// -------------------------------------------------------------------

	/**
	 * An EMR rename of an image used on a Bricks page rewrites the Image
	 * element and leaves the page's Nested Slider options the JSON string:
	 * Replacer::replaceContent() encodes a nested JSON string back after
	 * walking it, so the page still renders.
	 */
	public function test_rename_keeps_the_nested_slider_on_a_bricks_page() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-bricks-rn' );
		$post_id          = $this->bricksPage( $this->elements( $id, $url ) );
		$this->assertSliderIntact( $this->pageElements( $post_id ), 'Precondition' );

		$name = 'emr-bricks-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$elements = $this->pageElements( $post_id );
		$this->assertStringEndsWith( '/' . $name, wp_get_attachment_url( $id ), 'Sentinel: the attachment was renamed.' );
		$this->assertSame( wp_get_attachment_url( $id ), $elements[1]['settings']['image']['url'], 'Sentinel: the Image element follows the rename.' );

		$this->assertSliderIntact( $elements, 'After the rename' );
	}

	/**
	 * When a shared Bricks component contains the renamed image, the generic
	 * options pass rewrites its Image element in the `bricks_components`
	 * option and its Nested Slider options stay the JSON string, so the
	 * pages that use the component still render.
	 */
	public function test_rename_keeps_the_nested_slider_in_a_shared_component() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-bricks-cmp' );
		update_option(
			BRICKS_DB_COMPONENTS,
			array(
				array(
					'id'       => 'cmpnt1',
					'elements' => $this->elements( $id, $url ),
				),
			)
		);
		$this->assertSliderIntact( $this->componentElements(), 'Precondition' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-bricks-cmp-new-' . $this->token() . '.jpg' );

		$elements = $this->componentElements();
		$this->assertSame( wp_get_attachment_url( $id ), $elements[1]['settings']['image']['url'], 'Sentinel: the component Image element follows the rename.' );

		$this->assertSliderIntact( $elements, 'After the rename' );
	}

	// -------------------------------------------------------------------
	// Same-name replace
	// -------------------------------------------------------------------

	/**
	 * A same-name replace with the same dimensions changes no URL: the
	 * replacer has nothing to search for, does not touch the row, and the
	 * slider keeps its JSON options and renders.
	 */
	public function test_same_name_replace_with_the_same_sizes_leaves_the_slider_intact() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-bricks-same' );
		$post_id          = $this->bricksPage( $this->elements( $id, $url ) );
		$raw_before       = $this->rawPostMeta( $post_id, BRICKS_DB_PAGE_CONTENT );
		$md5_before       = md5_file( get_attached_file( $id ) );

		$this->replaceMode( $id, $this->tmpJpeg( 400, 300, array( 200, 40, 40 ) ) );

		$this->assertNotSame( $md5_before, md5_file( get_attached_file( $id ) ), 'Sentinel: the file was replaced.' );
		$this->assertSame( $url, wp_get_attachment_url( $id ), 'The URL is unchanged.' );
		$this->assertSame( $raw_before, $this->rawPostMeta( $post_id, BRICKS_DB_PAGE_CONTENT ), 'The element tree is untouched.' );
		$this->assertSliderIntact( $this->pageElements( $post_id ), 'After the replace' );
	}

	/**
	 * A same-name replace whose upload has other dimensions renames the
	 * medium size. The page's row matches the attachment's base path (it
	 * shows the main URL, which stays valid), so the replacer walks it; the
	 * slider options come back as the same JSON string and the page renders.
	 */
	public function test_same_name_replace_with_a_renamed_size_keeps_the_nested_slider() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-bricks-size' );
		$post_id          = $this->bricksPage( $this->elements( $id, $url ) );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$this->assertStringEndsWith( '-300x200.jpg', $this->sizeUrl( $id, 'medium' ), 'Sentinel: the medium size got a new name.' );
		$elements = $this->pageElements( $post_id );
		$this->assertSame( $url, $elements[1]['settings']['image']['url'], 'The Image element keeps the (still valid) main URL.' );

		$this->assertSliderIntact( $elements, 'After the replace' );
	}
}
