<?php
/**
 * Tests for the bundled promo-banner module
 * (build/shortpixel/promo-banner/src/PromoBanner.php) and its use by EMR
 * (EnableMediaReplacePlugin::promoBanner(), isPromoBannerScreen()).
 *
 * The campaign window is tested against fixed moments; the other conditions
 * use EMR_TestPromoBanner, which pins "now" inside the window. Snapshots and
 * restores the current user, the current screen, $_GET, the dismissal meta,
 * the enqueued assets and every filter it adds.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\PromoBanner\PromoBanner;

/** PromoBanner with a fixed "now", so the conditions after the time window can be tested any day. */
class EMR_TestPromoBanner extends PromoBanner {
	/** @var DateTimeImmutable|null */
	public $now;

	public function isInWindow( $now = null ) {
		return parent::isInWindow( null === $now ? $this->now : $now );
	}
}

class PromoBannerTest extends WP_UnitTestCase {

	/** @var int */
	private $savedUser;

	/** @var array */
	private $savedGet;

	/** @var string|null The global $plugin_page before the test. */
	private $savedPluginPage;

	/** @var array [hook, callback, priority] added by the test. */
	private $filters = array();

	public function set_up() {
		parent::set_up();
		$this->savedUser = get_current_user_id();
		$this->savedGet  = $_GET;
		$this->savedPluginPage = isset( $GLOBALS['plugin_page'] ) ? $GLOBALS['plugin_page'] : null;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->filters = array();
		$_GET          = $this->savedGet;
		$GLOBALS['plugin_page'] = $this->savedPluginPage;
		wp_set_current_user( $this->savedUser );
		set_current_screen( 'front' );
		wp_dequeue_style( 'emrtest_promo_banner' );
		wp_dequeue_script( 'emrtest_promo_banner' );
		parent::tear_down();
	}

	private function add_test_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->filters[] = array( $hook, $callback, $priority );
	}

	private function utc( $time ) {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}

	/** A banner shown on the `upload` screen, with "now" inside the default window. */
	private function banner( array $args = array() ) {
		$banner      = new EMR_TestPromoBanner( array_merge( array(
			'prefix'     => 'emrtest',
			'assets_url' => 'https://example.org/assets/',
			'screens'    => array( 'upload' ),
			'url'        => 'https://shortpixel.com/pricing?a=1&b=2',
		), $args ) );
		$banner->now = $this->utc( '2026-11-25 12:00:00' );
		set_current_screen( 'upload' );
		return $banner;
	}

	/* ------------------------------------------------------------------
	 * Time window: 23 to 30 November 2026, US Eastern (EST = UTC-5 that week)
	 * ------------------------------------------------------------------ */

	public function test_the_window_starts_at_midnight_us_eastern_on_november_23() {
		$banner = new PromoBanner();

		$this->assertFalse( $banner->isInWindow( $this->utc( '2026-11-23 04:59:59' ) ), '23:59:59 EST on the 22nd.' );
		$this->assertTrue( $banner->isInWindow( $this->utc( '2026-11-23 05:00:00' ) ), '00:00 EST on the 23rd.' );
	}

	public function test_the_window_ends_after_the_last_second_of_november_30_us_eastern() {
		$banner = new PromoBanner();

		$this->assertTrue( $banner->isInWindow( $this->utc( '2026-12-01 04:59:59' ) ), '23:59:59 EST on the 30th, inclusive.' );
		$this->assertFalse( $banner->isInWindow( $this->utc( '2026-12-01 05:00:00' ) ), '00:00 EST on 1 December.' );
	}

	public function test_the_window_ignores_the_site_timezone_setting() {
		$saved = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$banner = new PromoBanner();

		$this->assertFalse( $banner->isInWindow( $this->utc( '2026-11-22 20:00:00' ) ), 'Already the 23rd in Tokyo, still the 22nd in New York.' );
		update_option( 'timezone_string', $saved );
	}

	public function test_a_bad_date_or_timezone_never_shows() {
		$this->assertFalse( ( new PromoBanner( array( 'timezone' => 'Not/AZone' ) ) )->isInWindow( $this->utc( '2026-11-25 12:00:00' ) ) );
		$this->assertFalse( ( new PromoBanner( array( 'start' => 'garbage' ) ) )->isInWindow( $this->utc( '2026-11-25 12:00:00' ) ) );
	}

	/* ------------------------------------------------------------------
	 * The other conditions (now pinned inside the window)
	 * ------------------------------------------------------------------ */

	public function test_shows_to_an_administrator_on_a_configured_screen_inside_the_window() {
		$this->assertTrue( $this->banner()->shouldShow() );
	}

	public function test_does_not_show_outside_the_window() {
		$banner      = $this->banner();
		$banner->now = $this->utc( '2026-11-04 12:00:00' );

		$this->assertFalse( $banner->shouldShow() );
	}

	public function test_needs_manage_options_unless_the_capability_filter_says_otherwise() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$banner = $this->banner();
		$this->assertFalse( $banner->shouldShow(), 'An editor has no manage_options.' );

		$this->add_test_filter( 'emr/promo_banner/capability', function () {
			return 'upload_files';
		} );
		$this->assertTrue( $banner->shouldShow() );
	}

	public function test_does_not_show_on_other_screens_unless_the_condition_matches() {
		$banner = $this->banner();
		set_current_screen( 'edit-post' );
		$this->assertFalse( $banner->shouldShow() );

		$conditional = $this->banner( array( 'screens' => array(), 'condition' => '__return_true' ) );
		set_current_screen( 'edit-post' );
		$this->assertTrue( $conditional->shouldShow(), 'The condition callable alone is enough.' );
	}

	public function test_a_dismissal_hides_it_and_is_shared_by_every_shortpixel_plugin() {
		$banner = $this->banner();
		$this->assertSame( 'shortpixel_promo_dismissed_black-friday-2026', $banner->getMetaKey(), 'No plugin prefix in the meta key.' );

		update_user_meta( get_current_user_id(), $banner->getMetaKey(), time() );

		$this->assertTrue( $banner->isDismissed() );
		$this->assertFalse( $banner->shouldShow() );
		$this->assertFalse( $this->banner( array( 'prefix' => 'spio' ) )->shouldShow(), 'Another plugin\'s copy honours it too.' );
	}

	public function test_the_no_banner_filter_switches_it_off() {
		$this->add_test_filter( 'emr/settings/no_banner', '__return_true' );

		$this->assertFalse( $this->banner()->shouldShow() );
	}

	public function test_the_show_filter_has_the_last_word() {
		$banner      = $this->banner();
		$banner->now = $this->utc( '2026-11-04 12:00:00' );
		$this->add_test_filter( 'emr/promo_banner/show', function ( $show, $id ) {
			return 'black-friday-2026' === $id;
		}, 10, 2 );

		$this->assertTrue( $banner->shouldShow() );
	}

	/* ------------------------------------------------------------------
	 * Output and assets
	 * ------------------------------------------------------------------ */

	public function test_the_markup_has_the_offer_the_link_and_the_dismiss_data_without_inline_code() {
		$banner = $this->banner();
		$html   = $banner->getMarkup();

		$this->assertStringStartsWith( '<div class="notice shortpixel-bf" role="region" aria-label="ShortPixel Black Friday offer"', $html );
		$this->assertStringContainsString( 'data-action="emrtest_promo_banner_dismiss"', $html );
		$this->assertSame( 1, preg_match( '/data-nonce="([0-9a-f]+)"/', $html, $nonce ) );
		$this->assertSame( 1, wp_verify_nonce( $nonce[1], 'emrtest_promo_banner_dismiss' ) );
		$this->assertStringContainsString( '<a class="shortpixel-bf__btn" href="https://shortpixel.com/pricing?a=1&#038;b=2" target="_blank" rel="noopener">Claim Deal', $html );
		$this->assertStringContainsString( '<strong>25% OFF</strong> any yearly plan for life', $html );
		$this->assertStringContainsString( '<strong>2x</strong> One-Time Credits', $html );
		$this->assertStringContainsString( 'Offer ends <strong>November 30, 2026</strong>', $html );
		foreach ( array( 'robot.svg', 'forever.png', 'black-friday-sale.png' ) as $image ) {
			$this->assertStringContainsString( 'src="https://example.org/assets/img/' . $image . '"', $html );
		}
		$this->assertStringContainsString( '<button type="button" class="shortpixel-bf__close" aria-label="Dismiss">&times;</button>', $html );
		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}

	public function test_without_a_hook_it_is_printed_by_the_plugin_without_the_notice_class() {
		$banner = $this->banner( array( 'hook' => false ) );
		$banner->register();

		$this->assertFalse( has_action( 'admin_notices', array( $banner, 'render' ) ), 'Not on admin_notices.' );
		$this->assertSame( 10, has_action( 'wp_ajax_emrtest_promo_banner_dismiss', array( $banner, 'ajaxDismiss' ) ), 'The dismiss endpoint still is.' );
		$this->assertStringStartsWith( '<div class="shortpixel-bf" role="region"', $banner->getMarkup(), 'No `notice` class: WordPress does not move it.' );
		remove_action( 'wp_ajax_emrtest_promo_banner_dismiss', array( $banner, 'ajaxDismiss' ) );
		remove_action( 'admin_enqueue_scripts', array( $banner, 'enqueue' ) );
	}

	public function test_the_default_hook_is_admin_notices() {
		$banner = $this->banner();
		$banner->register();

		$this->assertSame( 10, has_action( 'admin_notices', array( $banner, 'render' ) ) );
		remove_action( 'admin_notices', array( $banner, 'render' ) );
		remove_action( 'wp_ajax_emrtest_promo_banner_dismiss', array( $banner, 'ajaxDismiss' ) );
		remove_action( 'admin_enqueue_scripts', array( $banner, 'enqueue' ) );
	}

	public function test_the_url_filter_changes_the_link() {
		$this->add_test_filter( 'emr/promo_banner/url', function () {
			return 'https://shortpixel.com/black-friday';
		} );

		$this->assertStringContainsString( 'href="https://shortpixel.com/black-friday"', $this->banner()->getMarkup() );
	}

	public function test_render_prints_only_when_it_should_show() {
		$banner = $this->banner();
		ob_start();
		$banner->render();
		$this->assertStringContainsString( 'shortpixel-bf', ob_get_clean() );

		$banner->now = $this->utc( '2026-12-02 12:00:00' );
		ob_start();
		$banner->render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_assets_are_enqueued_only_when_it_shows() {
		$banner      = $this->banner();
		$banner->now = $this->utc( '2026-11-04 12:00:00' );
		$banner->enqueue();
		$this->assertFalse( wp_style_is( 'emrtest_promo_banner', 'enqueued' ) );

		$banner->now = $this->utc( '2026-11-25 12:00:00' );
		$banner->enqueue();
		$this->assertTrue( wp_style_is( 'emrtest_promo_banner', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'emrtest_promo_banner', 'enqueued' ) );
		$this->assertSame( 'https://example.org/assets/promo-banner.css', wp_styles()->registered['emrtest_promo_banner']->src );
		$this->assertSame( 'https://example.org/assets/promo-banner.js', wp_scripts()->registered['emrtest_promo_banner']->src );
	}

	public function test_the_bundled_assets_exist() {
		$dir = dirname( EMR_ROOT_FILE ) . '/build/shortpixel/promo-banner/assets/';
		$this->assertFileExists( $dir . 'promo-banner.css' );
		$this->assertFileExists( $dir . 'promo-banner.js' );
		foreach ( array( 'robot.svg', 'forever.png', 'black-friday-sale.png' ) as $image ) {
			$this->assertFileExists( $dir . 'img/' . $image );
		}
	}

	/* ------------------------------------------------------------------
	 * EMR's banner
	 * ------------------------------------------------------------------ */

	public function test_emr_configures_the_campaign_its_pages_and_the_utm_link() {
		$banner = emr()->promoBanner();
		$prop   = new ReflectionProperty( PromoBanner::class, 'args' );
		$prop->setAccessible( true );
		$args = $prop->getValue( $banner );

		$this->assertSame( '2026-11-23 00:00:00', $args['start'] );
		$this->assertSame( '2026-11-30 23:59:59', $args['end'] );
		$this->assertSame( 'America/New_York', $args['timezone'] );
		$this->assertSame( array( 'media_page_emr-bulk' ), $args['screens'] );
		$this->assertSame( 'manage_options', $args['capability'] );
		$this->assertFalse( $args['hook'], 'Printed by the views right after the header, not on admin_notices.' );
		$this->assertFalse( has_action( 'admin_notices', array( $banner, 'render' ) ) );
		$this->assertStringContainsString( 'utm_medium=emr', $args['url'] );
		$this->assertStringEndsWith( '/build/shortpixel/promo-banner/assets/', $args['assets_url'] );
		$this->assertSame( 'emr_promo_banner_dismiss', $banner->getAjaxAction() );
		$this->assertSame( $banner, emr()->promoBanner(), 'Built once.' );
	}

	public function test_emr_shows_it_on_replace_remove_background_and_upscale_only() {
		$GLOBALS['plugin_page'] = 'enable-media-replace/enable-media-replace.php';
		foreach ( array( 'media_replace', 'emr_prepare_remove', 'emr_prepare_upscale' ) as $action ) {
			$_GET['action'] = $action;
			$this->assertTrue( emr()->isPromoBannerScreen(), $action );
		}
		foreach ( array( 'media_replace_upload', 'do_background_replace', 'do_upscale_replace', '' ) as $action ) {
			$_GET['action'] = $action;
			$this->assertFalse( emr()->isPromoBannerScreen(), $action . ' (POST handlers and the success screen)' );
		}
		$GLOBALS['plugin_page'] = null;
		$_GET['action']         = 'media_replace';
		$this->assertFalse( emr()->isPromoBannerScreen(), 'Not on the Media Library.' );
	}
}
