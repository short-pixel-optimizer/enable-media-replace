<?php
/**
 * Cross-plugin compatibility: Smart Slider 3.
 *
 * Runs with the REAL smart-slider-3 plugin active (wordpress.org, fetched
 * by bin/test.sh --compat); without it every test here SKIPS.
 *
 * Smart Slider keeps its slides in its own table,
 * `{prefix}nextend2_smartslider3_slides`, and stores an image as a
 * resource path, `$upload$/<path inside uploads>`, that its
 * ResourceTranslator resolves against the uploads directory: in the
 * `thumbnail` column, in the slide's `params` JSON (`backgroundImage`) and
 * in the layer JSON of the `slide` column.
 *
 * The bundled replacer module Modules\SmartSlider is documented as inert:
 * its constructor returns right after detecting `NEXTEND_SMARTSLIDER_3`,
 * so doReplaceQueries() is never registered on
 * `emr/replacer/replace_urls`. The generic passes search post content, the
 * meta tables and the options table only. With the real plugin this suite
 * shows that:
 *
 *   - a fresh construction registers nothing (ported from SPIO's
 *     "deliberately disabled" test in
 *     tests/Integration/test-ConversionReplacement.php);
 *   - a rename fires `emr/replacer/replace_urls` with no Smart Slider
 *     listener, rewrites post content, and leaves every slide column on the
 *     old `$upload$` path, a file that no longer exists (pinned).
 *
 * Unit coverage with the constant defined by the test (no plugin):
 * tests/Replacer/test-SmartSliderInert.php.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/CompatReplacerModules.php';

class CompatSmartSliderTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_CompatReplacerModules;

	const MODULE = 'EnableMediaReplace\Replacer\Modules\SmartSlider';

	/** @var mixed The Modules\SmartSlider singleton before the test. */
	private $savedModule = null;

	public function set_up() {
		if ( ! defined( 'NEXTEND_SMARTSLIDER_3' ) ) {
			$this->markTestSkipped( 'Smart Slider 3 is not loaded — run bin/test.sh --compat.' );
		}
		// DDL auto-commits: create a missing table before the test transaction opens.
		self::ensureSlidesTable();
		parent::set_up();
		$this->flowSetUp();
		$this->snapshotModuleSingletons();
		$this->savedModule = $this->moduleInstanceProperty( self::MODULE )->getValue();
		$this->moduleInstanceProperty( self::MODULE )->setValue( null, null );
	}

	public function tear_down() {
		$this->moduleInstanceProperty( self::MODULE )->setValue( null, $this->savedModule );
		$this->restoreModuleSingletons();
		$this->flowTearDown();
		parent::tear_down();
	}

	private static function slidesTable() {
		global $wpdb;
		return $wpdb->prefix . 'nextend2_smartslider3_slides';
	}

	/**
	 * Smart Slider's activation (fired once by the integration bootstrap)
	 * creates the slides table. When it is missing, create the columns the
	 * tests use, in Smart Slider's own shape.
	 */
	private static function ensureSlidesTable() {
		global $wpdb;
		$table = self::slidesTable();
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return;
		}
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `$table` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`title` TEXT NOT NULL,
				`slider` INT(11) NOT NULL,
				`publish_up` DATETIME NOT NULL default '1970-01-01 00:00:00',
				`publish_down` DATETIME NOT NULL default '1970-01-01 00:00:00',
				`published` TINYINT(1) NOT NULL,
				`first` INT(11) NOT NULL,
				`slide` LONGTEXT,
				`description` TEXT NOT NULL,
				`thumbnail` TEXT NOT NULL,
				`params` TEXT NOT NULL,
				`ordering` INT(11) NOT NULL,
				`generator_id` INT(11) NOT NULL,
				PRIMARY KEY (`id`)
			) {$wpdb->get_charset_collate()}"
		); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Smart Slider's resource path for a URL, through its own translator. */
	private function toResource( $url ) {
		\Nextend\Framework\ResourceTranslator\ResourceTranslator::getInstance();
		return \Nextend\Framework\ResourceTranslator\ResourceTranslator::urlToResource( $url );
	}

	/** The file a resource path resolves to, through Smart Slider's translator. */
	private function resourcePath( $resource ) {
		\Nextend\Framework\ResourceTranslator\ResourceTranslator::getInstance();
		return \Nextend\Framework\ResourceTranslator\ResourceTranslator::toPath( $resource );
	}

	/**
	 * Inserts a slide whose thumbnail, background image and image layer
	 * show $resource, encoded the way Smart Slider's Data::toJSON() encodes
	 * (json_encode() with default flags). Rolled back with the test.
	 *
	 * @return int Slide id.
	 */
	private function insertSlide( $resource ) {
		global $wpdb;
		$layers = array(
			array(
				'type'   => 'content',
				'layers' => array(
					array(
						'type' => 'layer',
						'item' => array(
							'type'   => 'image',
							'values' => array( 'image' => $resource ),
						),
					),
				),
			),
		);
		$wpdb->insert(
			self::slidesTable(),
			array(
				'title'        => 'EMR slide',
				'slider'       => 4242,
				'published'    => 1,
				'first'        => 0,
				'slide'        => json_encode( $layers ),
				'description'  => '',
				'thumbnail'    => $resource,
				'params'       => json_encode( array( 'backgroundImage' => $resource, 'backgroundImageOpacity' => 100 ) ),
				'ordering'     => 0,
				'generator_id' => 0,
			)
		);
		$this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'Sentinel: the slide row was inserted.' );
		return (int) $wpdb->insert_id;
	}

	/** A slide row, straight from the database. */
	private function slideRow( $slide_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT thumbnail, params, slide FROM `' . self::slidesTable() . '` WHERE id = %d', $slide_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$this->assertIsArray( $row, 'The slide row exists.' );
		return $row;
	}

	// -------------------------------------------------------------------
	// Coexistence and the inert module
	// -------------------------------------------------------------------

	public function test_smart_slider_loads_alongside_emr() {
		global $wpdb;
		$this->assertTrue( defined( 'NEXTEND_SMARTSLIDER_3' ), 'Smart Slider defines NEXTEND_SMARTSLIDER_3.' );
		$this->assertTrue( class_exists( '\Nextend\Framework\ResourceTranslator\ResourceTranslator' ), 'Smart Slider loads its framework.' );
		$this->assertSame( self::slidesTable(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::slidesTable() ) ) ), 'The slides table exists.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * With the real plugin, a fresh Replacer constructs the module, which
	 * detects Smart Slider and still registers nothing: no listener on
	 * `emr/replacer/replace_urls`, no replacer stored. The module is
	 * documented as inert (an unconditional return in its constructor); the
	 * day it is enabled, this test fails and the pin below has to flip.
	 */
	public function test_module_registers_nothing_with_smart_slider_active() {
		$replacer = new \EnableMediaReplace\Replacer\Replacer();
		$module   = $this->moduleInstanceProperty( self::MODULE )->getValue();

		$this->assertInstanceOf( self::MODULE, $module, 'The Replacer constructed the module.' );
		$this->assertFalse( has_action( 'emr/replacer/replace_urls', array( $module, 'doReplaceQueries' ) ), 'No doReplaceQueries() listener.' );
		$this->assertSame( array(), $this->callbacksOf( 'emr/replacer/replace_urls', self::MODULE ), 'No module callback on the hook at all.' );
		$stored = new ReflectionProperty( self::MODULE, 'replacer' );
		$stored->setAccessible( true );
		$this->assertNull( $stored->getValue( $module ), 'The replacer is never stored.' );
		$this->assertInstanceOf( 'EnableMediaReplace\Replacer\Replacer', $replacer );
	}

	// -------------------------------------------------------------------
	// Storage shape and a real rename
	// -------------------------------------------------------------------

	/**
	 * Shape sentinel: Smart Slider's translator turns an uploads URL into
	 * the `$upload$/` resource path and back to the file on disk.
	 */
	public function test_smart_slider_stores_an_uploads_image_as_an_upload_resource_path() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-ss-shape' );

		$resource = $this->toResource( $url );

		$this->assertSame( '$upload$/' . get_post_meta( $id, '_wp_attached_file', true ), $resource );
		$this->assertSame( get_attached_file( $id ), $this->resourcePath( $resource ), 'The resource resolves to the attachment file.' );
		$slide = $this->slideRow( $this->insertSlide( $resource ) );
		$this->assertSame( $resource, $slide['thumbnail'] );
		$this->assertSame( $resource, json_decode( $slide['params'], true )['backgroundImage'] );
	}

	/**
	 * A rename leaves a Smart Slider slide on the old image. The replacer
	 * fires `emr/replacer/replace_urls`, but Modules\SmartSlider never
	 * registered doReplaceQueries() on it (its constructor returns before),
	 * and the generic passes do not search Smart Slider's table. The
	 * thumbnail, the background image and the image layer keep the old
	 * `$upload$` path, which resolves to a file that is gone, while post
	 * content follows the rename. The correct behaviour is that the module
	 * rewrites the three columns (its doReplaceQueries() converts the URLs
	 * to the `$upload$` form for that).
	 *
	 * How to recognise the fix: the slide columns carry the new resource
	 * path and the pinned assertSame calls below fail.
	 */
	public function test_rename_leaves_the_smart_slider_slide_on_the_old_image_pinned_for_deferred_fix() {
		list( $id, $url, , $old_base ) = $this->standardCompatImage( 'emr-ss-rn' );
		$old_file                      = get_attached_file( $id );
		$old_resource                  = $this->toResource( $url );
		$slide_id                      = $this->insertSlide( $old_resource );
		$before                        = $this->slideRow( $slide_id );
		$post_id                       = $this->makePost( '<img src="' . $url . '" />' );
		$this->addHook( 'emr/replacer/replace_urls', $this->record( 'replace_urls' ), 10, 3 );

		$name = 'emr-ss-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '/' . $name, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertSame( '<img src="' . $new_url . '" />', $this->rawContent( $post_id ), 'Sentinel: the replacer ran, post content follows.' );
		$this->assertContains( 'replace_urls', $this->eventNames(), 'Sentinel: the replacer fired emr/replacer/replace_urls.' );
		$this->assertSame( array(), $this->callbacksOf( 'emr/replacer/replace_urls', self::MODULE ), 'Pinned: no Smart Slider listener was there.' );

		$after = $this->slideRow( $slide_id );
		$this->assertSame( $before, $after, 'Pinned: the slide row is untouched.' );
		$this->assertSame( $old_resource, $after['thumbnail'], 'Pinned: the thumbnail keeps the old path.' );
		$this->assertSame( $old_resource, json_decode( $after['params'], true )['backgroundImage'], 'Pinned: the background keeps the old path.' );
		$this->assertSame( $old_resource, json_decode( $after['slide'], true )[0]['layers'][0]['item']['values']['image'], 'Pinned: the image layer keeps the old path.' );
		$this->assertStringContainsString( $old_base, $after['thumbnail'] );
		$this->assertSame( $old_file, $this->resourcePath( $after['thumbnail'] ), 'The slide resolves to the old file.' );
		$this->assertFileDoesNotExist( $old_file, 'Pinned: the file the slide shows is gone.' );
	}
}
