<?php
/**
 * Cross-plugin compatibility: SiteOrigin Page Builder.
 *
 * Runs with the REAL siteorigin-panels plugin active (wordpress.org,
 * fetched by bin/test.sh --compat); without it every test here SKIPS.
 *
 * SiteOrigin keeps a page layout in the `panels_data` post meta, and a
 * Layout Builder widget (id base `siteorigin-panels-builder`) keeps its
 * nested layout in the `widget_siteorigin-panels-builder` option. Both are
 * plain nested arrays that WordPress serializes; each widget's settings sit
 * next to a `panels_info` array naming the widget class.
 *
 * EMR's classes/external/siteorigin.php registers addOption() on
 * `emr/replacer/option_fields` when SiteOrigin is active, to add that
 * option to the replacer's search. The bundled replacer never applies that
 * filter (the unit suite pins its absence from the sources). With the real
 * plugin this suite shows the consequence:
 *
 *   - the filter is registered and answers with the option name, and a
 *     rename never applies it;
 *   - the rename still rewrites the image in both storages, through the
 *     generic paths: the postmeta pass for `panels_data` and the options
 *     pass, which searches every row of the options table, for the widget
 *     option. The arrays hold no objects and no JSON, so replaceContent()
 *     rewrites and re-serializes them, and SiteOrigin renders the new URL.
 *
 * So the integration has no effect on a replace, and none is needed for
 * these storage shapes.
 *
 * Unit coverage with SiteOrigin ABSENT: tests/Controller/test-Externals.php.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/CompatReplacerModules.php';

class CompatSiteOriginTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_CompatReplacerModules;

	/** The option EMR's SiteOrigin integration names. */
	const WIDGET_OPTION = 'widget_siteorigin-panels-builder';

	/** @var array( exists, value ) The widget option before the test. */
	private $savedWidgetOption = array( false, null );

	/** @var mixed The Externals\SiteOrigin singleton before the test. */
	private $savedExternal = null;

	public function set_up() {
		if ( ! defined( 'SITEORIGIN_PANELS_VERSION' ) ) {
			$this->markTestSkipped( 'SiteOrigin Page Builder is not loaded — run bin/test.sh --compat.' );
		}
		parent::set_up();
		$this->flowSetUp();
		$this->snapshotModuleSingletons();

		$missing                 = new stdClass();
		$value                   = get_option( self::WIDGET_OPTION, $missing );
		$this->savedWidgetOption = ( $value === $missing ) ? array( false, null ) : array( true, $value );
		$this->savedExternal     = $this->moduleInstanceProperty( 'EnableMediaReplace\Externals\SiteOrigin' )->getValue();
	}

	public function tear_down() {
		$this->moduleInstanceProperty( 'EnableMediaReplace\Externals\SiteOrigin' )->setValue( null, $this->savedExternal );
		if ( $this->savedWidgetOption[0] ) {
			update_option( self::WIDGET_OPTION, $this->savedWidgetOption[1] );
		} else {
			delete_option( self::WIDGET_OPTION );
		}
		$this->restoreModuleSingletons();
		$this->flowTearDown();
		parent::tear_down();
	}

	/** A fresh Externals\SiteOrigin, constructed against the real plugin. */
	private function freshExternal() {
		$this->moduleInstanceProperty( 'EnableMediaReplace\Externals\SiteOrigin' )->setValue( null, null );
		return \EnableMediaReplace\Externals\SiteOrigin::getInstance();
	}

	/**
	 * A SiteOrigin layout with one row, one cell and one core Text widget
	 * showing $url in an image tag, the shape SiteOrigin saves.
	 */
	private function panelsData( $url ) {
		return array(
			'widgets'    => array(
				array(
					'title'       => '',
					'text'        => '<img class="emr-so-image" src="' . $url . '" alt="" />',
					'filter'      => true,
					'visual'      => true,
					'panels_info' => array(
						'class'     => 'WP_Widget_Text',
						'raw'       => false,
						'grid'      => 0,
						'cell'      => 0,
						'id'        => 0,
						'widget_id' => 'emr-so-widget-1',
						'style'     => array(),
					),
				),
			),
			'grids'      => array(
				array(
					'cells' => 1,
					'style' => array(),
				),
			),
			'grid_cells' => array(
				array(
					'grid'   => 0,
					'index'  => 0,
					'weight' => 1,
					'style'  => array(),
				),
			),
		);
	}

	/** The image URL inside a stored layout's Text widget. */
	private function widgetImageUrl( array $panels_data ) {
		$this->assertSame( 'WP_Widget_Text', $panels_data['widgets'][0]['panels_info']['class'], 'The layout structure survives.' );
		$this->assertSame( 1, preg_match( '/src="([^"]+)"/', $panels_data['widgets'][0]['text'], $match ), 'The widget still holds an image tag.' );
		return $match[1];
	}

	/** The page layout as WordPress loads it, bypassing the cache. */
	private function pagePanelsData( $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );
		$data = get_post_meta( $post_id, 'panels_data', true );
		$this->assertIsArray( $data, 'panels_data loads as an array.' );
		return $data;
	}

	/** The Layout Builder widget's nested layout, loaded through get_option(). */
	private function widgetOptionPanelsData() {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::WIDGET_OPTION, 'options' );
		$option = get_option( self::WIDGET_OPTION );
		$this->assertIsArray( $option, 'The widget option loads as an array.' );
		$this->assertArrayHasKey( 2, $option, 'The widget instance survives.' );
		return $option[2]['panels_data'];
	}

	/** A published page with $url in its SiteOrigin layout. */
	private function siteOriginPage( $url ) {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'EMR SiteOrigin page' ) );
		update_post_meta( $post_id, 'panels_data', $this->panelsData( $url ) );
		return $post_id;
	}

	/** A Layout Builder widget instance (number 2) whose nested layout shows $url. */
	private function seedLayoutBuilderWidget( $url ) {
		update_option(
			self::WIDGET_OPTION,
			array(
				2              => array(
					'panels_data' => $this->panelsData( $url ),
					'builder_id'  => 'emrsobuilder',
				),
				'_multiwidget' => 1,
			)
		);
	}

	/** Renders a layout through SiteOrigin's renderer, under a render id of its own. */
	private function render( array $panels_data ) {
		$layout_data = array();
		return (string) \SiteOrigin_Panels::renderer()->render( 'emr-so-' . $this->token(), false, $panels_data, $layout_data );
	}

	// -------------------------------------------------------------------
	// Coexistence and registration
	// -------------------------------------------------------------------

	public function test_siteorigin_loads_alongside_emr() {
		$this->assertTrue( defined( 'SITEORIGIN_PANELS_VERSION' ), 'SiteOrigin defines its version constant.' );
		$this->assertTrue( class_exists( 'SiteOrigin_Panels' ), 'SiteOrigin declares its main class.' );
		$this->assertTrue( class_exists( 'SiteOrigin_Panels_Widgets_Layout' ), 'SiteOrigin declares the Layout Builder widget.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * With the real plugin, a fresh Externals\SiteOrigin registers
	 * addOption() on `emr/replacer/option_fields`, and the filter answers
	 * with the Layout Builder widget option.
	 */
	public function test_external_registers_the_option_fields_filter_with_siteorigin_active() {
		$external = $this->freshExternal();

		$this->assertSame( 10, has_filter( 'emr/replacer/option_fields', array( $external, 'addOption' ) ) );
		$this->assertSame( array( self::WIDGET_OPTION ), apply_filters( 'emr/replacer/option_fields', array() ) );
	}

	// -------------------------------------------------------------------
	// A real rename
	// -------------------------------------------------------------------

	/**
	 * Shape sentinel: SiteOrigin's layout is a serialized array of arrays,
	 * with the URL unescaped and no object or JSON inside, and SiteOrigin
	 * renders the image from it.
	 */
	public function test_a_siteorigin_layout_is_stored_as_plain_serialized_arrays() {
		$url     = 'http://example.org/wp-content/uploads/2026/10/emr-so-shape.jpg';
		$post_id = $this->siteOriginPage( $url );

		$raw = $this->rawPostMeta( $post_id, 'panels_data' );
		$this->assertStringStartsWith( 'a:3:{', $raw, 'panels_data is a serialized array.' );
		$this->assertStringNotContainsString( 'O:', $raw, 'No object inside.' );
		$this->assertStringContainsString( $url, $raw, 'The URL is stored unescaped.' );
		$this->assertSame( $url, $this->widgetImageUrl( $this->pagePanelsData( $post_id ) ) );
		$this->assertStringContainsString( 'src="' . $url . '"', $this->render( $this->pagePanelsData( $post_id ) ), 'SiteOrigin renders the image.' );
	}

	/**
	 * A rename never applies `emr/replacer/option_fields`, and still
	 * rewrites the image in the page's `panels_data` (postmeta pass) and in
	 * the Layout Builder widget option (options pass): SiteOrigin loads and
	 * renders the new URL from both, and no reference to the old name is
	 * left.
	 */
	public function test_rename_rewrites_the_page_layout_and_the_widget_option_without_the_option_fields_filter() {
		$this->freshExternal();
		list( $id, $url, , $old_base ) = $this->standardCompatImage( 'emr-so-rn' );
		$post_id                       = $this->siteOriginPage( $url );
		$this->seedLayoutBuilderWidget( $url );
		$this->assertStringContainsString( $old_base, $this->rawOption( self::WIDGET_OPTION ), 'Sentinel: the widget option holds the old name.' );
		$applied_before = did_filter( 'emr/replacer/option_fields' );
		$this->addHook( 'emr/replacer/option_fields', $this->record( 'option_fields' ), 5 );

		$name = 'emr-so-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '/' . $name, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertSame( array(), $this->eventNames(), 'The replace never applies emr/replacer/option_fields.' );
		$this->assertSame( $applied_before, did_filter( 'emr/replacer/option_fields' ), 'The filter count is unchanged.' );

		$this->assertStringNotContainsString( $old_base, $this->rawPostMeta( $post_id, 'panels_data' ), 'panels_data no longer references the old name.' );
		$page_layout = $this->pagePanelsData( $post_id );
		$this->assertSame( $new_url, $this->widgetImageUrl( $page_layout ), 'The page layout points at the new URL.' );
		$this->assertStringContainsString( 'src="' . $new_url . '"', $this->render( $page_layout ), 'SiteOrigin renders the new URL.' );

		$this->assertStringNotContainsString( $old_base, $this->rawOption( self::WIDGET_OPTION ), 'The widget option no longer references the old name.' );
		$widget_layout = $this->widgetOptionPanelsData();
		$this->assertSame( $new_url, $this->widgetImageUrl( $widget_layout ), 'The Layout Builder widget points at the new URL.' );
		$this->assertStringContainsString( 'src="' . $new_url . '"', $this->render( $widget_layout ), 'SiteOrigin renders the widget layout with the new URL.' );
	}

	/**
	 * A same-name replace with other dimensions renames the medium size;
	 * the layout showing the medium size follows through the same generic
	 * postmeta pass.
	 */
	public function test_same_name_replace_rewrites_a_renamed_size_in_the_page_layout() {
		list( $id, $url, $medium ) = $this->standardCompatImage( 'emr-so-same' );
		$post_id                   = $this->siteOriginPage( $medium );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertSame( $url, wp_get_attachment_url( $id ), 'Sentinel: the main URL is kept.' );
		$this->assertStringEndsWith( '-300x200.jpg', $new_medium, 'Sentinel: the medium size got a new name.' );
		$this->assertSame( $new_medium, $this->widgetImageUrl( $this->pagePanelsData( $post_id ) ), 'The page layout points at the new medium URL.' );
	}
}
