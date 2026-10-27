<?php
/**
 * Tests for the integration modules of the replacer
 * (build/shortpixel/replacer2/src/Modules/): Elementor, WpBakery, YoastSeo,
 * Breakdance and SmartSlider, with the third-party plugins ABSENT (this
 * suite never loads partner plugins; the real plugins are covered by the
 * Compat suite).
 *
 * Every module is a singleton that decides in its constructor whether to
 * register its hooks. set_up() snapshots the five private static $instance
 * properties (and the Replacer's) and nulls them so each test constructs
 * fresh modules; tear_down() removes the hooks any fresh module registered
 * and restores the snapshots. The override filters
 * `emr/externals/elementor_is_active` and `emr/externals/urlencode_is_active`
 * stand in for the real plugins.
 *
 * SmartSlider with its constant defined is tested in
 * test-SmartSliderInert.php, in a separate process.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Replacer;
use EnableMediaReplace\Replacer\Modules\Elementor;
use EnableMediaReplace\Replacer\Modules\WpBakery;
use EnableMediaReplace\Replacer\Modules\YoastSeo;
use EnableMediaReplace\Replacer\Modules\Breakdance;
use EnableMediaReplace\Replacer\Modules\SmartSlider;

/**
 * Modules\*.
 */
class ReplacerModulesTest extends WP_UnitTestCase {

	const SOURCE_URL = 'http://example.org/wp-content/uploads/2020/01/source.jpg';
	const TARGET_URL = 'http://example.org/wp-content/uploads/2020/01/target.jpg';

	/**
	 * The hooks each module can register, as [hook, method].
	 *
	 * @var array
	 */
	private static $module_hooks = array(
		Elementor::class   => array( array( 'emr/replacer/custom_replace_query', 'addElementor' ) ),
		WpBakery::class    => array( array( 'emr/replacer/custom_replace_query', 'addURLEncoded' ) ),
		YoastSeo::class    => array( array( 'emr/replacer/replace_urls', 'removeIndexes' ) ),
		Breakdance::class  => array(
			array( 'emr/replacer/custom_replace_query', 'addBreakdance' ),
			array( 'emr/replacer/load_meta_value', 'loadContent' ),
			array( 'emr/replacer/load_meta_value', 'abortOnContent' ),
			array( 'emr/replacer/save_meta_value', 'saveContent' ),
		),
		SmartSlider::class => array( array( 'emr/replacer/replace_urls', 'doReplaceQueries' ) ),
		Replacer::class    => array(),
	);

	/**
	 * Singleton snapshots, keyed by class.
	 *
	 * @var array
	 */
	private $saved = array();

	/**
	 * Filters and actions added by the test, as [hook, callback, priority].
	 *
	 * @var array
	 */
	private $added_hooks = array();

	/**
	 * Snapshots and nulls the singletons.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( array_keys( self::$module_hooks ) as $class ) {
			$prop                  = $this->instanceProperty( $class );
			$this->saved[ $class ] = $prop->getValue();
			$prop->setValue( null, null );
		}
	}

	/**
	 * Removes the hooks of fresh modules and restores the singletons.
	 */
	public function tear_down() {
		foreach ( self::$module_hooks as $class => $hooks ) {
			$prop    = $this->instanceProperty( $class );
			$current = $prop->getValue();
			if ( null !== $current && $current !== $this->saved[ $class ] ) {
				foreach ( $hooks as $hook ) {
					remove_filter( $hook[0], array( $current, $hook[1] ), 10 );
				}
			}
			$prop->setValue( null, $this->saved[ $class ] );
		}
		foreach ( $this->added_hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$this->added_hooks = array();
		parent::tear_down();
	}

	/**
	 * The private static $instance of a class, made accessible.
	 *
	 * @param string $class Class name.
	 * @return ReflectionProperty
	 */
	private function instanceProperty( $class ) {
		$prop = new ReflectionProperty( $class, 'instance' );
		$prop->setAccessible( true );
		return $prop;
	}

	/**
	 * Adds a filter or action and records it for tear_down().
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function addHook( $hook, $callback, $priority = 10 ) {
		add_filter( $hook, $callback, $priority );
		$this->added_hooks[] = array( $hook, $callback, $priority );
	}

	/**
	 * A replacer from SOURCE_URL to TARGET_URL without metadata.
	 *
	 * @return Replacer
	 */
	private function makeReplacer() {
		$replacer = new Replacer();
		$replacer->setSource( self::SOURCE_URL );
		$replacer->setTarget( self::TARGET_URL );
		return $replacer;
	}

	// ---------------------------------------------------------------------
	// Detection with the third party absent.
	// ---------------------------------------------------------------------

	public function test_replacer_construction_instantiates_every_module_singleton() {
		new Replacer();

		foreach ( array( Elementor::class, WpBakery::class, YoastSeo::class, Breakdance::class, SmartSlider::class ) as $class ) {
			$this->assertInstanceOf( $class, $this->instanceProperty( $class )->getValue(), $class );
		}
		$this->assertNull( $this->instanceProperty( Replacer::class )->getValue(), 'new Replacer() does not set the shared instance' );
	}

	public function test_elementor_registers_nothing_when_absent() {
		$this->assertFalse( defined( 'ELEMENTOR_VERSION' ), 'Precondition: Elementor is not loaded.' );

		$module = Elementor::getInstance();

		$this->assertSame( $module, Elementor::getInstance() );
		$this->assertFalse( has_filter( 'emr/replacer/custom_replace_query', array( $module, 'addElementor' ) ) );
	}

	public function test_elementor_override_filter_registers_the_custom_run() {
		$this->addHook( 'emr/externals/elementor_is_active', '__return_true' );

		$module = Elementor::getInstance();

		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $module, 'addElementor' ) ) );
	}

	public function test_wpbakery_registers_nothing_when_absent() {
		$this->assertSame( 0, did_action( 'vc_plugins_loaded' ), 'Precondition: WPBakery is not loaded.' );
		$this->assertFalse( defined( 'VCV_VERSION' ), 'Precondition: Visual Composer is not loaded.' );

		$module = WpBakery::getInstance();

		$this->assertFalse( has_filter( 'emr/replacer/custom_replace_query', array( $module, 'addURLEncoded' ) ) );
	}

	public function test_wpbakery_override_filter_registers_the_custom_run() {
		$this->addHook( 'emr/externals/urlencode_is_active', '__return_true' );

		$module = WpBakery::getInstance();

		$this->assertSame( 10, has_filter( 'emr/replacer/custom_replace_query', array( $module, 'addURLEncoded' ) ) );
	}

	public function test_yoast_registers_nothing_when_absent() {
		$this->assertFalse( defined( 'WPSEO_VERSION' ), 'Precondition: Yoast SEO is not loaded.' );

		$module = YoastSeo::getInstance();

		$this->assertFalse( has_action( 'emr/replacer/replace_urls', array( $module, 'removeIndexes' ) ) );
		$table = new ReflectionProperty( YoastSeo::class, 'yoastTable' );
		$table->setAccessible( true );
		$this->assertNull( $table->getValue( $module ) );
	}

	public function test_breakdance_registers_nothing_when_absent() {
		$this->assertFalse( has_action( 'breakdance_loaded' ), 'Precondition: Breakdance is not loaded.' );

		$module = Breakdance::getInstance();

		foreach ( self::$module_hooks[ Breakdance::class ] as $hook ) {
			$this->assertFalse( has_filter( $hook[0], array( $module, $hook[1] ) ), $hook[1] );
		}
		$this->assertFalse( $module->checkRequiredFunctions() );
	}

	public function test_breakdance_without_its_functions_only_registers_the_abort_fallback() {
		$this->addHook( 'breakdance_loaded', '__return_null' );
		$this->assertFalse( function_exists( '\Breakdance\Data\get_tree' ), 'Precondition: the Breakdance functions are missing.' );

		$module = Breakdance::getInstance();

		$this->assertSame( 10, has_filter( 'emr/replacer/load_meta_value', array( $module, 'abortOnContent' ) ) );
		$this->assertFalse( has_filter( 'emr/replacer/custom_replace_query', array( $module, 'addBreakdance' ) ) );
		$this->assertFalse( has_filter( 'emr/replacer/load_meta_value', array( $module, 'loadContent' ) ) );
		$this->assertFalse( has_filter( 'emr/replacer/save_meta_value', array( $module, 'saveContent' ) ) );
	}

	public function test_smartslider_registers_nothing_when_absent() {
		$this->assertFalse( defined( 'NEXTEND_SMARTSLIDER_3' ), 'Precondition: Smart Slider is not loaded.' );
		$replacer = new Replacer();

		$module = SmartSlider::getInstance( $replacer );

		$this->assertFalse( has_action( 'emr/replacer/replace_urls', array( $module, 'doReplaceQueries' ) ) );
		$prop = new ReflectionProperty( SmartSlider::class, 'replacer' );
		$prop->setAccessible( true );
		$this->assertNull( $prop->getValue( $module ) );
	}

	// ---------------------------------------------------------------------
	// Module methods.
	// ---------------------------------------------------------------------

	public function test_add_slash_produces_the_escaped_json_like_fragment() {
		$expected = 'wp-content\\\\/uploads\\\\/a\\_b%.jpg';
		$expected = str_replace( '%', '\\%', $expected );

		$this->assertSame( $expected, Elementor::getInstance()->addSlash( '/wp-content/uploads/a_b%.jpg' ) );
		$this->assertSame( $expected, Breakdance::getInstance()->addSlash( '/wp-content/uploads/a_b%.jpg' ), 'Breakdance has the same helper' );
	}

	public function test_add_elementor_adds_a_run_without_args() {
		$runs = Elementor::getInstance()->addElementor( array( 'other' => array() ), '/a/b', array( '/a/b.jpg' ), array( '/a/c.jpg' ) );

		$this->assertSame(
			array(
				'other'     => array(),
				'elementor' => array(
					'base_url'     => 'a\\\\/b',
					'search_urls'  => array( '/a/b.jpg' ),
					'replace_urls' => array( '/a/c.jpg' ),
				),
			),
			$runs
		);
	}

	public function test_wpbakery_encodes_base_search_and_replace() {
		$module = WpBakery::getInstance();

		$runs = $module->addUrlEncoded( array(), '/a b/c', array( 'k' => '/a b/c.jpg' ), array( 'k' => '/a b/d.jpg' ) );

		$this->assertSame( '%2Fa+b%2Fc', $module->addEncode( '/a b/c' ) );
		$this->assertSame(
			array(
				'wpbakery' => array(
					'base_url'     => '%2Fa+b%2Fc',
					'search_urls'  => array( 'k' => '%2Fa+b%2Fc.jpg' ),
					'replace_urls' => array( 'k' => '%2Fa+b%2Fd.jpg' ),
				),
			),
			$runs
		);
	}

	public function test_add_breakdance_adds_a_run_that_saves_through_breakdance() {
		$runs = Breakdance::getInstance()->addBreakdance( array(), '/a/b', array( '/a/b.jpg' ), array( '/a/c.jpg' ) );

		$this->assertSame( 'a\\\\/b', $runs['breakdance']['base_url'] );
		$this->assertSame(
			array(
				'component'            => 'breakdance',
				'replacer_do_save'     => false,
				'replace_no_serialize' => true,
			),
			$runs['breakdance']['args']
		);
	}

	public function test_breakdance_abort_on_content_skips_only_its_own_run() {
		$module = Breakdance::getInstance();
		$row    = array( 'meta_id' => '5', 'meta_value' => 'v', 'post_id' => '9' );

		$this->assertNull( $module->abortOnContent( 'v', $row, 'breakdance' ) );
		$this->assertSame( 'v', $module->abortOnContent( 'v', $row, 'emr' ) );
	}

	public function test_breakdance_load_and_save_pass_through_other_runs_and_rows_without_post_id() {
		$module = Breakdance::getInstance();
		$row    = array( 'meta_id' => '5', 'meta_value' => 'v', 'post_id' => '9' );
		$option = array( 'option_id' => '5', 'option_value' => 'v', 'main_id' => null );

		$this->assertSame( 'v', $module->loadContent( 'v', $row, 'emr' ) );
		$this->assertSame( 'v', $module->loadContent( 'v', $option, 'breakdance' ), 'isset() is false for a null main_id' );
		$this->assertSame( 'v', $module->saveContent( 'v', $row, 'unset' ) );
		$this->assertSame( 'v', $module->saveContent( 'v', $option, 'breakdance' ) );
	}

	// ---------------------------------------------------------------------
	// Active modules through replace().
	// ---------------------------------------------------------------------

	public function test_active_elementor_module_rewrites_slash_escaped_json_in_postmeta() {
		$this->addHook( 'emr/externals/elementor_is_active', '__return_true' );
		$replacer = $this->makeReplacer();
		$carrier  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$json     = wp_json_encode( array( array( 'settings' => array( 'image' => array( 'url' => self::SOURCE_URL ) ) ) ) );
		$this->assertStringContainsString( '\/wp-content\/', $json, 'Sentinel: slashes are escaped.' );
		add_post_meta( $carrier, '_elementor_data', wp_slash( $json ) );
		$this->assertSame( $json, get_post_meta( $carrier, '_elementor_data', true ), 'Sentinel: stored as escaped JSON.' );

		$result = $replacer->replace();

		$after = get_post_meta( $carrier, '_elementor_data', true );
		$this->assertSame( 1, $result, 'Only the Elementor run matched the row' );
		$this->assertSame( '[{"settings":{"image":{"url":"' . self::TARGET_URL . '"}}}]', $after, 'Re-encoded with unescaped slashes' );
	}

	public function test_active_wpbakery_module_rewrites_urlencoded_urls_in_post_content() {
		global $wpdb;
		$this->addHook( 'emr/externals/urlencode_is_active', '__return_true' );
		$replacer = $this->makeReplacer();
		$post_id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$wpdb->update( $wpdb->posts, array( 'post_content' => '[vc_single_image image_url="' . urlencode( self::SOURCE_URL ) . '"]' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		$result = $replacer->replace();

		$this->assertSame( 1, $result );
		$this->assertSame( '[vc_single_image image_url="' . urlencode( self::TARGET_URL ) . '"]', get_post( $post_id )->post_content );
	}
}
