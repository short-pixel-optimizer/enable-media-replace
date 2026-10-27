<?php
/**
 * Tests for EnableMediaReplace\Externals (classes/externals.php) and the
 * Elementor, WPBakery and SiteOrigin integrations under classes/external/,
 * with the third-party plugins absent. The paths with the real plugins
 * loaded belong to the compat suite.
 *
 * The integrations are singletons; each test snapshots their `$instance`
 * (and the bundled replacer modules' `$instance` where a test resets them)
 * and puts them back in tear_down(). Hooks the constructors register are
 * removed by WP_UnitTestCase's hook backup. The `vc_plugins_loaded` action
 * counter a test increments is restored by hand. Nothing here talks HTTP;
 * a fail-fast `pre_http_request` filter at priority 9999 records any
 * request and tear_down() asserts none was made.
 *
 * Two tests declare a stand-in class (`FLBuilder`) and run in a separate
 * process so the class never exists for any other test.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Externals;
use EnableMediaReplace\Externals\Elementor;
use EnableMediaReplace\Externals\WpBakery;
use EnableMediaReplace\Externals\SiteOrigin;
use EnableMediaReplace\Externals\WPOffload;

class ExternalsTest extends WP_UnitTestCase {

	/**
	 * Classes whose static $instance is snapshotted.
	 *
	 * @var string[]
	 */
	private static $singletons = array(
		Elementor::class,
		WpBakery::class,
		SiteOrigin::class,
		WPOffload::class,
		'EnableMediaReplace\Replacer\Modules\Elementor',
		'EnableMediaReplace\Replacer\Modules\WpBakery',
	);

	/**
	 * @var array<string,mixed>
	 */
	private $saved_instances = array();

	/**
	 * @var int|null
	 */
	private $saved_vc_count;

	/**
	 * @var string[]
	 */
	private $blocked = array();

	public function set_up() {
		parent::set_up();
		foreach ( self::$singletons as $class ) {
			$this->saved_instances[ $class ] = $this->instance( $class );
		}
		global $wp_actions;
		$this->saved_vc_count = isset( $wp_actions['vc_plugins_loaded'] ) ? $wp_actions['vc_plugins_loaded'] : null;
		$this->blocked        = array();
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999 );
		foreach ( $this->saved_instances as $class => $instance ) {
			$this->set_instance( $class, $instance );
		}
		global $wp_actions;
		if ( null === $this->saved_vc_count ) {
			unset( $wp_actions['vc_plugins_loaded'] );
		} else {
			$wp_actions['vc_plugins_loaded'] = $this->saved_vc_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$blocked       = $this->blocked;
		$this->blocked = array();
		parent::tear_down();
		$this->assertSame( array(), $blocked, 'A request reached the fail-fast filter.' );
	}

	/**
	 * Refuses and records every HTTP request nothing else answered.
	 */
	public function fail_fast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->blocked[] = $url;
		return new WP_Error( 'http_blocked', 'Blocked unmocked request to ' . $url );
	}

	private function instance( $class ) {
		$ref = new ReflectionProperty( $class, 'instance' );
		$ref->setAccessible( true );
		return $ref->getValue();
	}

	private function set_instance( $class, $value ) {
		$ref = new ReflectionProperty( $class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	private function reset_singletons() {
		foreach ( self::$singletons as $class ) {
			$this->set_instance( $class, null );
		}
	}

	private function prop( $object, $name ) {
		$ref = new ReflectionProperty( get_class( $object ), $name );
		$ref->setAccessible( true );
		return $ref->getValue( $object );
	}

	/**
	 * Callbacks at priority 10 on a hook whose object is an instance of $class.
	 */
	private function callbacks_of( $hook, $class ) {
		global $wp_filter;
		$found = array();
		if ( ! isset( $wp_filter[ $hook ]->callbacks[10] ) ) {
			return $found;
		}
		foreach ( $wp_filter[ $hook ]->callbacks[10] as $callback ) {
			if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) && get_class( $callback['function'][0] ) === $class ) {
				$found[] = $callback['function'];
			}
		}
		return $found;
	}

	private function sample_query() {
		return array(
			'/wp-content/uploads/2024/01/photo',
			array( 'https://example.org/wp-content/uploads/2024/01/photo.jpg', 'https://example.org/wp-content/uploads/2024/01/photo-150x150.jpg' ),
			array( 'https://example.org/wp-content/uploads/2024/01/new photo.jpg', 'https://example.org/wp-content/uploads/2024/01/new photo-150x150.jpg' ),
		);
	}

	/*
	 * Externals.
	 */

	public function test_constructor_registers_the_three_hooks() {
		$externals = new Externals();

		$this->assertSame( 10, has_filter( 'emr_display_replace_type_options', array( $externals, 'get_replace_type' ) ) );
		$this->assertSame( 10, has_filter( 'emr_enable_replace_and_search', array( $externals, 'get_replacesearch_type' ) ) );
		$this->assertSame( 10, has_action( 'emr_after_replace_type_options', array( $externals, 'get_messages' ) ) );
	}

	public function test_constructor_loads_every_integration_singleton() {
		$this->reset_singletons();

		new Externals();

		$this->assertInstanceOf( Elementor::class, $this->instance( Elementor::class ) );
		$this->assertInstanceOf( WpBakery::class, $this->instance( WpBakery::class ) );
		$this->assertInstanceOf( SiteOrigin::class, $this->instance( SiteOrigin::class ) );
		$this->assertInstanceOf( WPOffload::class, $this->instance( WPOffload::class ) );
	}

	/**
	 * @dataProvider provide_bools
	 */
	public function test_replace_type_filters_pass_the_value_through( $value ) {
		$externals = new Externals();

		$this->assertSame( $value, $externals->get_replace_type( $value ) );
		$this->assertSame( $value, $externals->get_replacesearch_type( $value ) );
		$this->assertSame( $value, apply_filters( 'emr_display_replace_type_options', $value ) );
		$this->assertSame( $value, apply_filters( 'emr_enable_replace_and_search', $value ) );
	}

	public function provide_bools() {
		return array(
			'true'  => array( true ),
			'false' => array( false ),
		);
	}

	public function test_get_messages_prints_nothing() {
		$externals = new Externals();

		ob_start();
		$externals->get_messages();
		do_action( 'emr_after_replace_type_options' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertSame( array(), $this->prop( $externals, 'messages' ) );
	}

	/**
	 * Externals::check() has an empty body: the Beaver Builder check that
	 * should disable "replace and search" (and add a notice) is commented
	 * out and garbled. With Beaver Builder present (its `FLBuilder` class
	 * declared here), replaceSearchType stays null and the option stays
	 * enabled. The check should be restored or removed.
	 *
	 * How to recognise the fix: replaceSearchType becomes false, the filter
	 * returns false and the assertions on null / true fail.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_check_ignores_beaver_builder_pinned_for_deferred_fix() {
		if ( ! class_exists( 'FLBuilder', false ) ) {
			eval( 'class FLBuilder {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		$externals = new Externals();

		$this->assertTrue( class_exists( 'FLBuilder', false ), 'Sentinel: Beaver Builder is detected.' );
		$this->assertNull( $this->prop( $externals, 'replaceSearchType' ), 'Pinned: nothing is disabled.' );
		$this->assertSame( array(), $this->prop( $externals, 'messages' ) );
		$this->assertTrue( apply_filters( 'emr_enable_replace_and_search', true ) );
	}

	/*
	 * Elementor (EMR side).
	 */

	public function test_elementor_inactive_registers_nothing() {
		$this->assertFalse( defined( 'ELEMENTOR_VERSION' ), 'Sentinel: Elementor is absent.' );
		$this->set_instance( Elementor::class, null );

		$elementor = Elementor::getInstance();

		$this->assertFalse( has_filter( 'emr/replacer/custom_replace_query', array( $elementor, 'addElementor' ) ) );
		$this->assertFalse( has_action( 'enable-media-replace-upload-done', array( $elementor, 'removeCache' ) ) );
	}

	public function test_elementor_override_filter_registers_the_hooks() {
		$this->set_instance( Elementor::class, null );
		add_filter( 'emr/externals/elementor_is_active', '__return_true' );

		$elementor = Elementor::getInstance();

		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $elementor, 'addElementor' ) ) );
		$this->assertSame( 10, has_action( 'enable-media-replace-upload-done', array( $elementor, 'removeCache' ) ) );
	}

	public function test_elementor_get_instance_is_a_singleton() {
		$this->set_instance( Elementor::class, null );

		$this->assertSame( Elementor::getInstance(), Elementor::getInstance() );
	}

	public function test_add_elementor_adds_the_escaped_base_url_and_keeps_the_urls() {
		$elementor = new Elementor();
		list( $base, $search, $replace ) = $this->sample_query();

		$items = $elementor->addElementor( array( 'other' => array( 'x' ) ), $base, $search, $replace );

		$this->assertSame( array( 'other', 'elementor' ), array_keys( $items ) );
		$this->assertSame(
			array(
				// `\/` (JSON slash) with its backslash escaped again by esc_like().
				'base_url'     => 'wp-content\\\\/uploads\\\\/2024\\\\/01\\\\/photo',
				'search_urls'  => $search,
				'replace_urls' => $replace,
			),
			$items['elementor']
		);
	}

	/**
	 * The `\/` that replaces each slash passes through esc_like() as well,
	 * so its backslash is doubled: the LIKE pattern then matches a literal
	 * `\/` in the JSON.
	 *
	 * @dataProvider provide_slash_values
	 */
	public function test_add_slash( $input, $expected ) {
		$elementor = new Elementor();

		$this->assertSame( $expected, $elementor->addSlash( $input ) );
	}

	public function provide_slash_values() {
		return array(
			'leading slash stripped' => array( '/wp-content/uploads/a', 'wp-content\\\\/uploads\\\\/a' ),
			'no leading slash'       => array( 'uploads/a', 'uploads\\\\/a' ),
			'like wildcards escaped' => array( '/up_loads/100%', 'up\\_loads\\\\/100\\%' ),
			'empty'                  => array( '', '' ),
			'only slashes'           => array( '//', '' ),
		);
	}

	/**
	 * EMR's Externals\Elementor and the bundled replacer's
	 * Modules\Elementor both register a callback on
	 * `emr/replacer/custom_replace_query` that writes the `elementor` key,
	 * so the run is built twice and the later callback silently overwrites
	 * the earlier one's entry. The two produce identical values today, so
	 * the overwrite is invisible in the result; only one side should own the
	 * integration.
	 *
	 * How to recognise the fix: only one of the two classes has a callback
	 * on the filter and the count assertion on the module's callbacks (1)
	 * fails.
	 */
	public function test_elementor_query_is_registered_twice_under_one_key_pinned_for_deferred_fix() {
		$module_class = 'EnableMediaReplace\Replacer\Modules\Elementor';
		$this->set_instance( Elementor::class, null );
		$this->set_instance( $module_class, null );
		add_filter( 'emr/externals/elementor_is_active', '__return_true' );

		$emr    = Elementor::getInstance();
		$module = $module_class::getInstance();
		list( $base, $search, $replace ) = $this->sample_query();

		$items = apply_filters( 'emr/replacer/custom_replace_query', array(), $base, $search, $replace );

		$this->assertCount( 1, $this->callbacks_of( 'emr/replacer/custom_replace_query', Elementor::class ) );
		$this->assertCount( 1, $this->callbacks_of( 'emr/replacer/custom_replace_query', $module_class ), 'Pinned: the module registers as well.' );
		$this->assertSame( array( 'elementor' ), array_keys( $items ) );
		$this->assertSame( $module->addElementor( array(), $base, $search, $replace )['elementor'], $items['elementor'], 'The module, registered last, wins.' );
		$this->assertSame( $emr->addElementor( array(), $base, $search, $replace )['elementor'], $items['elementor'], 'Its values equal EMR\'s.' );
	}

	/*
	 * WpBakery.
	 */

	/**
	 * The callback is registered as `addURLEncoded`; has_filter() compares
	 * the method name case-sensitively, so the tests look it up with that
	 * spelling.
	 */
	public function test_wpbakery_inactive_registers_nothing() {
		$this->assertSame( 0, did_action( 'vc_plugins_loaded' ), 'Sentinel: WPBakery is absent.' );
		$this->assertFalse( defined( 'VCV_VERSION' ) );
		$this->set_instance( WpBakery::class, null );

		$bakery = WpBakery::getInstance();

		$this->assertFalse( has_filter( 'emr/replacer/custom_replace_query', array( $bakery, 'addURLEncoded' ) ) );
	}

	public function test_wpbakery_is_detected_through_vc_plugins_loaded() {
		do_action( 'vc_plugins_loaded' );
		$this->set_instance( WpBakery::class, null );

		$bakery = WpBakery::getInstance();

		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $bakery, 'addURLEncoded' ) ) );
	}

	public function test_wpbakery_override_filter_registers_and_unregisters() {
		add_filter( 'emr/externals/urlencode_is_active', '__return_true' );
		$this->set_instance( WpBakery::class, null );
		$on = WpBakery::getInstance();
		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $on, 'addURLEncoded' ) ) );

		do_action( 'vc_plugins_loaded' );
		add_filter( 'emr/externals/urlencode_is_active', '__return_false', 20 );
		$off = new WpBakery();
		$this->assertFalse( has_filter( 'emr/replacer/custom_replace_query', array( $off, 'addURLEncoded' ) ) );
	}

	public function test_add_url_encoded_encodes_base_search_and_replace() {
		$bakery = new WpBakery();
		list( $base, $search, $replace ) = $this->sample_query();

		$items = $bakery->addUrlEncoded( array(), $base, $search, $replace );

		$this->assertSame(
			array(
				'wpbakery' => array(
					'base_url'     => '%2Fwp-content%2Fuploads%2F2024%2F01%2Fphoto',
					'search_urls'  => array_map( 'urlencode', $search ),
					'replace_urls' => array_map( 'urlencode', $replace ),
				),
			),
			$items
		);
		$this->assertStringContainsString( 'new+photo', $items['wpbakery']['replace_urls'][0] );
	}

	public function test_add_url_encoded_keeps_array_keys() {
		$bakery = new WpBakery();

		$items = $bakery->addUrlEncoded( array(), '/a', array( 'full' => 'https://x.test/a b.jpg' ), array( 'full' => 'https://x.test/c.jpg' ) );

		$this->assertSame( array( 'full' => 'https%3A%2F%2Fx.test%2Fa+b.jpg' ), $items['wpbakery']['search_urls'] );
	}

	/**
	 * @dataProvider provide_encode_values
	 */
	public function test_add_encode( $input, $expected ) {
		$this->assertSame( $expected, ( new WpBakery() )->addEncode( $input ) );
	}

	public function provide_encode_values() {
		return array(
			'url'     => array( 'https://example.org/a b.jpg', 'https%3A%2F%2Fexample.org%2Fa+b.jpg' ),
			'plain'   => array( 'abc', 'abc' ),
			'empty'   => array( '', '' ),
			'unicode' => array( 'ü', '%C3%BC' ),
		);
	}

	/**
	 * Same defect as the Elementor pin: EMR's Externals\WpBakery and the
	 * bundled Modules\WpBakery both write the `wpbakery` key on
	 * `emr/replacer/custom_replace_query`; the later one overwrites the
	 * earlier one's identical entry.
	 *
	 * How to recognise the fix: only one of the two classes has a callback
	 * on the filter and one of the count assertions fails.
	 */
	public function test_wpbakery_query_is_registered_twice_under_one_key_pinned_for_deferred_fix() {
		$module_class = 'EnableMediaReplace\Replacer\Modules\WpBakery';
		$this->set_instance( WpBakery::class, null );
		$this->set_instance( $module_class, null );
		add_filter( 'emr/externals/urlencode_is_active', '__return_true' );

		$emr    = WpBakery::getInstance();
		$module = $module_class::getInstance();
		list( $base, $search, $replace ) = $this->sample_query();

		$items = apply_filters( 'emr/replacer/custom_replace_query', array(), $base, $search, $replace );

		$this->assertCount( 1, $this->callbacks_of( 'emr/replacer/custom_replace_query', WpBakery::class ) );
		$this->assertCount( 1, $this->callbacks_of( 'emr/replacer/custom_replace_query', $module_class ), 'Pinned: the module registers as well.' );
		$this->assertSame( array( 'wpbakery' ), array_keys( $items ) );
		$this->assertSame( $module->addUrlEncoded( array(), $base, $search, $replace )['wpbakery'], $items['wpbakery'] );
		$this->assertSame( $emr->addUrlEncoded( array(), $base, $search, $replace )['wpbakery'], $items['wpbakery'] );
	}

	/*
	 * SiteOrigin.
	 */

	public function test_siteorigin_inactive_registers_nothing() {
		$this->assertFalse( defined( 'SITEORIGIN_PANELS_VERSION' ), 'Sentinel: SiteOrigin is absent.' );
		$this->set_instance( SiteOrigin::class, null );

		$siteorigin = SiteOrigin::getInstance();

		$this->assertInstanceOf( SiteOrigin::class, $siteorigin );
		$this->assertSame( $siteorigin, SiteOrigin::getInstance() );
		$this->assertFalse( has_filter( 'emr/replacer/option_fields', array( $siteorigin, 'addOption' ) ) );
	}

	public function test_add_option_appends_the_panels_widget_option() {
		$siteorigin = SiteOrigin::getInstance();

		$this->assertSame( array( 'widget_siteorigin-panels-builder' ), $siteorigin->addOption( array() ) );
		$this->assertSame( array( 'a', 'b', 'widget_siteorigin-panels-builder' ), $siteorigin->addOption( array( 'a', 'b' ) ) );
	}

	/**
	 * SiteOrigin registers on `emr/replacer/option_fields`, a filter the
	 * bundled replacer never applies, so the
	 * `widget_siteorigin-panels-builder` option is never searched even with
	 * SiteOrigin active. The replacer should apply the filter in an
	 * options pass, or the integration should go.
	 *
	 * How to recognise the fix: the filter name appears in the replacer
	 * sources and the assertSame( array(), ... ) fails.
	 */
	public function test_option_fields_filter_is_never_applied_pinned_for_deferred_fix() {
		$root  = dirname( __DIR__, 2 ) . '/build/shortpixel/replacer2/src';
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		$found = array();
		$count = 0;
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			++$count;
			if ( false !== strpos( file_get_contents( $file->getPathname() ), 'option_fields' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$found[] = $file->getFilename();
			}
		}

		$this->assertGreaterThan( 3, $count, 'Sentinel: the replacer sources were scanned.' );
		$this->assertSame( array(), $found, 'Pinned: no replacer file mentions option_fields.' );
	}
}
