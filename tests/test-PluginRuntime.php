<?php
/**
 * Tests for the bootstrap side of EnableMediaReplace\EnableMediaReplacePlugin
 * (classes/emr-plugin.php): get(), runtime(), plugin_actions(), adminInit(),
 * useFeature() and the helper accessors.
 *
 * runtime() already ran once on `init` during the test bootstrap, for a
 * logged-out user. Each test logs in the user it needs and calls runtime()
 * again; the hooks it adds and the private properties it writes are
 * restored by EMR_MainTestCase.
 *
 * Only the default EMR_CAPABILITY (false) can be exercised here; the string
 * and array shapes need the constant defined before the plugin loads.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/EmrMainTestCase.php';

use EnableMediaReplace\EnableMediaReplacePlugin;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger;
use EnableMediaReplace\FileSystem\Controller\FileSystemController;
use EnableMediaReplace\UIHelper;
use EnableMediaReplace\Helper\EnvHelper;

class PluginRuntimeTest extends EMR_MainTestCase {

	/** Number of callbacks on a hook, over all priorities. */
	private function callback_count( $hook ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			$count += count( $callbacks );
		}
		return $count;
	}

	/** The admin hooks plugin_actions() registers, with their priority. */
	private function admin_hooks() {
		$plugin = emr();
		return array(
			array( 'admin_menu', array( $plugin, 'menu' ), 10 ),
			array( 'submenu_file', array( $plugin, 'hide_sub_menu' ), 10 ),
			array( 'current_screen', array( $plugin, 'setScreen' ), 10 ),
			array( 'admin_enqueue_scripts', array( $plugin, 'admin_scripts' ), 10 ),
			array( 'media_row_actions', array( $plugin, 'add_media_action' ), 10 ),
			array( 'attachment_submitbox_misc_actions', array( $plugin, 'admin_date_replaced_media_on_edit_media_screen' ), 91 ),
			array( 'add_meta_boxes_attachment', array( $plugin, 'add_meta_boxes' ), 10 ),
			array( 'attachment_fields_to_edit', array( $plugin, 'attachment_editor' ), 10 ),
		);
	}

	/** Asserts that runtime() stopped after the shortcode. */
	private function assert_turned_away( $externals_before ) {
		$this->assertTrue( shortcode_exists( 'file_modified' ) );
		foreach ( $this->admin_hooks() as $hook ) {
			$this->assertFalse( has_action( $hook[0], $hook[1] ), $hook[0] . ' must not be hooked.' );
		}
		$this->assertSame( $externals_before, $this->callback_count( 'emr_display_replace_type_options' ), 'Externals must not be instantiated.' );
	}

	/* ------------------------------------------------------------------
	 * get()
	 * ------------------------------------------------------------------ */

	public function test_get_returns_the_same_instance_every_time() {
		$first = EnableMediaReplacePlugin::get();

		$this->assertInstanceOf( EnableMediaReplacePlugin::class, $first );
		$this->assertSame( $first, EnableMediaReplacePlugin::get() );
		$this->assertSame( $first, emr() );
	}

	public function test_get_points_the_logger_at_emr_log_in_uploads_when_debug_is_active() {
		$this->assertTrue( ShortPixelLogger::debugIsActive(), 'SHORTPIXEL_DEBUG is set by the test bootstrap.' );
		$logger = ShortPixelLogger::getInstance();
		$prop   = new ReflectionProperty( ShortPixelLogger::class, 'logPath' );
		$prop->setAccessible( true );
		$saved = $prop->getValue( $logger );
		$prop->setValue( $logger, false );

		try {
			EnableMediaReplacePlugin::get();
			$path = $prop->getValue( $logger );
		} finally {
			$prop->setValue( $logger, $saved );
		}

		$uploads = wp_upload_dir( null, false, false );
		$this->assertIsString( $path );
		$this->assertSame( trailingslashit( $uploads['basedir'] ) . 'emr_log', $path );
	}

	/* ------------------------------------------------------------------
	 * runtime()
	 * ------------------------------------------------------------------ */

	public function test_runtime_for_a_logged_out_user_only_registers_the_shortcode() {
		remove_shortcode( 'file_modified' );
		$externals = $this->callback_count( 'emr_display_replace_type_options' );

		$this->assertNull( emr()->runtime() );

		$this->assert_turned_away( $externals );
	}

	public function test_runtime_for_a_subscriber_without_upload_files_stops_after_the_shortcode() {
		$this->login_as( 'subscriber' );
		$this->assertFalse( current_user_can( 'upload_files' ) );
		$externals = $this->callback_count( 'emr_display_replace_type_options' );

		emr()->runtime();

		$this->assert_turned_away( $externals );
	}

	public function test_runtime_with_the_default_capability_leaves_both_stored_caps_false() {
		$this->login_as( 'administrator' );
		$this->set_plugin_prop( 'general_cap', 'leftover' );

		emr()->runtime();

		$this->assertFalse( $this->plugin_prop( 'user_cap' ) );
		$this->assertSame( 'leftover', $this->plugin_prop( 'general_cap' ), 'runtime() only writes the caps for the string and array shapes.' );
	}

	public function test_runtime_for_an_author_registers_the_admin_ui() {
		$this->login_as( 'author' );
		$externals = $this->callback_count( 'emr_display_replace_type_options' );

		emr()->runtime();

		foreach ( $this->admin_hooks() as $hook ) {
			$this->assertSame( $hook[2], has_action( $hook[0], $hook[1] ), $hook[0] . ' must be hooked at priority ' . $hook[2] . '.' );
		}
		$this->assertSame( $externals + 1, $this->callback_count( 'emr_display_replace_type_options' ), 'Externals is instantiated once.' );
		$this->assertFalse( has_filter( 'wp_get_attachment_image_src', array( emr(), 'attempt_uncache_image' ) ) );
	}

	public function test_runtime_for_an_administrator_registers_the_admin_ui_and_the_notices_controller() {
		$this->login_as( 'administrator' );

		emr()->runtime();

		foreach ( $this->admin_hooks() as $hook ) {
			$this->assertSame( $hook[2], has_action( $hook[0], $hook[1] ) );
		}
		$this->assertIsObject( $this->notice_static( 'instance' ), 'plugin_actions() loads the notices controller.' );
		$this->assertSame( trailingslashit( dirname( EMR_ROOT_FILE ) ), $this->plugin_prop( 'plugin_path' ) );
	}

	public function test_runtime_with_emr_replaced_adds_the_cache_busting_filters() {
		$this->login_as( 'administrator' );
		$_GET['emr_replaced'] = '1';

		emr()->runtime();

		$this->assertSame( 10, has_filter( 'wp_get_attachment_image_src', array( emr(), 'attempt_uncache_image' ) ) );
		$this->assertTrue( has_filter( 'postbox_classes_attachment_emr-showthumbs-box' ) );
		$this->assertSame( array( 'existing', 'closed' ), apply_filters( 'postbox_classes_attachment_emr-showthumbs-box', array( 'existing' ) ) );
	}

	public function test_runtime_with_another_emr_replaced_value_adds_no_cache_busting() {
		$this->login_as( 'administrator' );
		$_GET['emr_replaced'] = '2';

		emr()->runtime();

		$this->assertSame( 10, has_action( 'admin_menu', array( emr(), 'menu' ) ) );
		$this->assertFalse( has_filter( 'wp_get_attachment_image_src', array( emr(), 'attempt_uncache_image' ) ) );
		$this->assertFalse( has_filter( 'postbox_classes_attachment_emr-showthumbs-box' ) );
	}

	/**
	 * The `emr_replaced` check in plugin_actions() and add_meta_boxes() is
	 * written `intval($_GET['emr_replaced'] == 1)`: the parenthesis closes
	 * after the loose comparison, so intval() wraps a bool. The correct form
	 * is `intval($_GET['emr_replaced']) === 1`. For every realistic input the
	 * two forms agree ('1', '01', '1.0' pass both, '2', 'abc' fail both), so
	 * the defect is not observable through behaviour and is pinned on the
	 * token sequence of classes/emr-plugin.php instead.
	 *
	 * How to recognise the fix: the sequence
	 * `intval ( $_GET [ 'emr_replaced' ] == 1 )` no longer occurs, so the
	 * count drops from 2 to 0.
	 */
	public function test_emr_replaced_check_wraps_the_comparison_in_intval_pinned_for_deferred_fix() {
		$tokens = array();
		foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/classes/emr-plugin.php' ) ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$tokens[] = $token[1];
			} else {
				$tokens[] = $token;
			}
		}
		$needle = array( 'intval', '(', '$_GET', '[', "'emr_replaced'", ']', '==', '1', ')' );
		$found  = 0;
		$last   = count( $tokens ) - count( $needle );
		for ( $i = 0; $i <= $last; $i++ ) {
			if ( array_slice( $tokens, $i, count( $needle ) ) === $needle ) {
				$found++;
			}
		}

		$this->assertGreaterThan( 100, count( $tokens ), 'The source file was tokenised.' );
		$this->assertSame( 2, $found );
	}

	/* ------------------------------------------------------------------
	 * adminInit()
	 * ------------------------------------------------------------------ */

	public function test_admin_init_fills_the_feature_flags_with_their_defaults() {
		$this->set_plugin_prop( 'features', array() );

		$this->assertNull( emr()->adminInit() );

		$this->assertSame(
			array(
				'replace'       => true,
				'background'    => true,
				'upscale'       => true,
				'remote_notice' => true,
			),
			$this->plugin_prop( 'features' )
		);
	}

	public function test_admin_init_reads_the_three_feature_filters() {
		$this->add_test_filter( 'emr/feature/background', '__return_false' );
		$this->add_test_filter( 'emr/feature/upscale', '__return_false' );
		$this->add_test_filter( 'emr/feature/remote_notice', '__return_false' );

		emr()->adminInit();

		$features = $this->plugin_prop( 'features' );
		$this->assertTrue( $features['replace'] );
		$this->assertFalse( $features['background'] );
		$this->assertFalse( $features['upscale'] );
		$this->assertFalse( $features['remote_notice'] );
	}

	public function test_admin_init_reads_the_background_and_upscale_filters_independently() {
		$this->add_test_filter( 'emr/feature/background', '__return_false' );

		emr()->adminInit();
		$features = $this->plugin_prop( 'features' );
		$this->assertFalse( $features['background'] );
		$this->assertTrue( $features['upscale'] );

		remove_filter( 'emr/feature/background', '__return_false' );
		$this->add_test_filter( 'emr/feature/upscale', '__return_false' );

		emr()->adminInit();
		$features = $this->plugin_prop( 'features' );
		$this->assertTrue( $features['background'] );
		$this->assertFalse( $features['upscale'] );
	}

	public function test_admin_init_registers_the_ajax_endpoints_again_on_every_call() {
		$before = $this->callback_count( 'wp_ajax_emr_remove_background' );
		$this->assertGreaterThanOrEqual( 1, $before, 'classes/ajax.php registers the endpoint at require time.' );

		emr()->adminInit();
		$this->assertSame( $before + 1, $this->callback_count( 'wp_ajax_emr_remove_background' ) );

		emr()->adminInit();
		$this->assertSame( $before + 2, $this->callback_count( 'wp_ajax_emr_remove_background' ) );
	}

	/* ------------------------------------------------------------------
	 * useFeature()
	 * ------------------------------------------------------------------ */

	public function test_use_feature_fills_empty_flags_through_admin_init() {
		$this->set_plugin_prop( 'features', array() );
		$this->login_as( 'administrator' );
		$before = $this->callback_count( 'wp_ajax_emr_remove_background' );

		$this->assertTrue( emr()->useFeature( 'remote_notice' ) );

		$this->assertCount( 4, $this->plugin_prop( 'features' ) );
		$this->assertSame( $before + 1, $this->callback_count( 'wp_ajax_emr_remove_background' ), 'The lazy adminInit() also creates an Ajax instance.' );
	}

	public function test_use_feature_does_not_rerun_admin_init_once_flags_exist() {
		emr()->adminInit();
		$before = $this->callback_count( 'wp_ajax_emr_remove_background' );

		emr()->useFeature( 'remote_notice' );

		$this->assertSame( $before, $this->callback_count( 'wp_ajax_emr_remove_background' ) );
	}

	public function test_use_feature_background_is_true_for_an_administrator() {
		$this->login_as( 'administrator' );
		emr()->adminInit();

		$this->assertTrue( emr()->useFeature( 'background' ) );
	}

	public function test_use_feature_background_is_false_for_an_author_restricted_user() {
		$this->login_as( 'author' );
		emr()->adminInit();
		$this->assertTrue( UIHelper::getInstance()->is_author_restricted() );

		$this->assertFalse( emr()->useFeature( 'background' ) );
	}

	public function test_use_feature_background_follows_the_filter_for_an_administrator() {
		$this->login_as( 'administrator' );
		$this->add_test_filter( 'emr/feature/background', '__return_false' );
		emr()->adminInit();

		$this->assertFalse( emr()->useFeature( 'background' ) );
	}

	public function test_use_feature_remote_notice_passes_the_flag_through() {
		$this->login_as( 'author' );
		$this->add_test_filter( 'emr/feature/remote_notice', '__return_false' );
		emr()->adminInit();
		$this->assertFalse( emr()->useFeature( 'remote_notice' ) );

		$this->set_plugin_prop( 'features', array( 'replace' => true, 'background' => true, 'upscale' => true, 'remote_notice' => true ) );
		$this->assertTrue( emr()->useFeature( 'remote_notice' ) );
	}

	public function test_use_feature_upscale_is_true_for_an_administrator() {
		$this->login_as( 'administrator' );
		emr()->adminInit();

		$this->assertTrue( emr()->useFeature( 'upscale' ) );
	}

	public function test_use_feature_upscale_is_false_for_an_author_restricted_user() {
		$this->login_as( 'author' );
		emr()->adminInit();
		$this->assertTrue( UIHelper::getInstance()->is_author_restricted() );
		$this->assertTrue( $this->plugin_prop( 'features' )['upscale'], 'The flag itself stays on; the restriction is applied by useFeature().' );

		$this->assertFalse( emr()->useFeature( 'upscale' ) );
	}

	public function test_use_feature_upscale_follows_the_filter_for_an_administrator() {
		$this->login_as( 'administrator' );
		$this->add_test_filter( 'emr/feature/upscale', '__return_false' );
		emr()->adminInit();

		$this->assertFalse( emr()->useFeature( 'upscale' ) );
		$this->assertTrue( emr()->useFeature( 'background' ), 'The background feature is not affected.' );
	}

	public function test_use_feature_upscale_stays_on_when_only_the_background_filter_is_off() {
		$this->login_as( 'administrator' );
		$this->add_test_filter( 'emr/feature/background', '__return_false' );
		emr()->adminInit();

		$this->assertFalse( emr()->useFeature( 'background' ) );
		$this->assertTrue( emr()->useFeature( 'upscale' ) );
	}

	public function test_use_feature_returns_false_for_an_unknown_or_the_replace_feature() {
		$this->login_as( 'administrator' );
		emr()->adminInit();

		$this->assertFalse( emr()->useFeature( 'unknown' ) );
		$this->assertFalse( emr()->useFeature( 'replace' ), "'replace' is a flag but not a feature useFeature() knows." );
		$this->assertFalse( emr()->useFeature( '' ) );
	}

	/* ------------------------------------------------------------------
	 * Accessors
	 * ------------------------------------------------------------------ */

	public function test_filesystem_returns_a_new_controller_each_call() {
		$first  = emr()->filesystem();
		$second = emr()->filesystem();

		$this->assertInstanceOf( FileSystemController::class, $first );
		$this->assertInstanceOf( FileSystemController::class, $second );
		$this->assertNotSame( $first, $second );
	}

	public function test_ui_helper_and_env_return_the_singletons() {
		$this->assertInstanceOf( UIHelper::class, emr()->uiHelper() );
		$this->assertSame( UIHelper::getInstance(), emr()->uiHelper() );
		$this->assertInstanceOf( EnvHelper::class, emr()->env() );
		$this->assertSame( EnvHelper::getInstance(), emr()->env() );
	}

	public function test_plugin_path_and_url_helpers_resolve_inside_the_plugin() {
		$root = trailingslashit( dirname( EMR_ROOT_FILE ) );

		$this->assertSame( $root, emr()->plugin_path() );
		$this->assertSame( $root . 'css/admin.css', emr()->plugin_path( 'css/admin.css' ) );
		$this->assertSame( plugins_url( 'js/emr_admin.js', EMR_ROOT_FILE ), emr()->getPluginURL( 'js/emr_admin.js' ) );
	}
}
