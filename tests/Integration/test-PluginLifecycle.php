<?php
/**
 * Integration tests: the plugin's life cycle in a WordPress install.
 *
 * Activation (no hook, no options written on load), deactivation (the
 * registered `deactivate_<basename>` action), uninstall (the callable
 * stored in the `uninstall_plugins` option), upgrade (there is no upgrade
 * routine), and the visitor-facing runtime: the `[file_modified]`
 * shortcode and the hooks a logged-out front-end request gets.
 *
 * The plugin is loaded by the test bootstrap from /opt/plugin, outside
 * WP_PLUGIN_DIR, so plugin_basename( EMR_ROOT_FILE ) is the path-shaped
 * 'opt/plugin/enable-media-replace.php'. Every hook name below is built
 * from plugin_basename(), as register_*_hook() builds it.
 *
 * Uninstall: wp-admin/includes/plugin.php::uninstall_plugin() cannot be
 * called as is, because for the hook form it include_once()s
 * WP_PLUGIN_DIR . '/' . $basename, which does not exist in this layout.
 * The test does the rest of what it does for a plugin without an
 * uninstall.php: read the callable from the `uninstall_plugins` option,
 * add it to `uninstall_<basename>` and fire that action. No
 * WP_UNINSTALL_PLUGIN constant is needed on this path (it is only defined
 * for uninstall.php).
 *
 * Snapshot/restore: base class (EMR options, singletons, mock), the
 * NoticeController statics and the notices option, the current user,
 * the shortcode registration, hooks (WP_UnitTestCase), attachments
 * (EMR_ReplaceFlowHelpers).
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ReplaceFlowHelpers.php';

use EnableMediaReplace\Notices\NoticeController;
use EnableMediaReplace\UIHelper;

class PluginLifecycleTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;

	const NOTICES_OPTION = 'EnableMediaReplace-notices';

	/** @var array */
	private $savedNoticeStatics = array();

	/** @var array|null */
	private $savedFeatures;

	/** @var array */
	private $savedGlobals = array();

	public function set_up() {
		parent::set_up();
		foreach ( array( 'instance', 'notices', 'newNotices', 'cssHookLoaded' ) as $name ) {
			$this->savedNoticeStatics[ $name ] = $this->noticeStatic( $name )->getValue();
		}
		$this->savedFeatures = $this->featuresProperty()->getValue( emr() );
		foreach ( array( 'current_screen', 'hook_suffix' ) as $name ) {
			$this->savedGlobals[ $name ] = array_key_exists( $name, $GLOBALS ) ? array( $GLOBALS[ $name ] ) : null;
		}
		$this->freshNoticeRequest();
		$this->flowSetUp();
	}

	public function tear_down() {
		$this->flowTearDown();
		foreach ( $this->savedNoticeStatics as $name => $value ) {
			$this->noticeStatic( $name )->setValue( null, $value );
		}
		$this->featuresProperty()->setValue( emr(), $this->savedFeatures );
		foreach ( $this->savedGlobals as $name => $saved ) {
			if ( null === $saved ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = $saved[0];
			}
		}
		add_shortcode( 'file_modified', array( emr(), 'get_modified_date' ) );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Plumbing.
	// ---------------------------------------------------------------------

	private function basename() {
		return plugin_basename( EMR_ROOT_FILE );
	}

	private function noticeStatic( $name ) {
		$prop = new ReflectionProperty( NoticeController::class, $name );
		$prop->setAccessible( true );
		return $prop;
	}

	private function featuresProperty() {
		$prop = new ReflectionProperty( get_class( emr() ), 'features' );
		$prop->setAccessible( true );
		return $prop;
	}

	/** Drops the in-memory notices, as a new PHP request starts. */
	private function freshNoticeRequest() {
		$this->noticeStatic( 'instance' )->setValue( null, null );
		$this->noticeStatic( 'notices' )->setValue( null, array() );
		$this->noticeStatic( 'newNotices' )->setValue( null, array() );
	}

	/** Option rows that exist, read from the database. */
	private function existingOptions( array $names ) {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders) ORDER BY option_name", $names ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** A full admin request as far as EMR is concerned: init, admin_init, current_screen. */
	private function adminRequestCycle( $screen ) {
		emr()->runtime();
		emr()->adminInit();
		$GLOBALS['hook_suffix'] = $screen;
		set_current_screen();
		emr()->setScreen();
	}

	/** Every EMR callback (class in the EnableMediaReplace namespace) on $hook. */
	private function emrCallbacksOn( $hook ) {
		global $wp_filter;
		$found = array();
		if ( empty( $wp_filter[ $hook ] ) ) {
			return $found;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				$name     = is_array( $function ) ? ( is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0] ) : ( is_string( $function ) ? $function : '' );
				if ( false !== stripos( ltrim( $name, '\\' ), 'EnableMediaReplace' ) || 0 === strpos( $name, 'emr' ) ) {
					$found[] = $name;
				}
			}
		}
		return $found;
	}

	// ---------------------------------------------------------------------
	// Activation.
	// ---------------------------------------------------------------------

	public function test_the_plugin_registers_no_activation_hook_but_a_deactivation_and_an_uninstall_hook() {
		$base = $this->basename();

		$this->assertFalse( has_action( 'activate_' . $base ), 'No activation routine.' );
		$this->assertSame( 10, has_action( 'deactivate_' . $base, array( '\EnableMediaReplace\InstallHelper', 'deactivatePlugin' ) ) );
		$uninstallable = (array) get_option( 'uninstall_plugins' );
		$this->assertArrayHasKey( $base, $uninstallable, 'register_uninstall_hook() stored the callable on load.' );
		$this->assertSame( array( '\EnableMediaReplace\InstallHelper', 'uninstallPlugin' ), $uninstallable[ $base ] );
		$this->assertTrue( is_callable( $uninstallable[ $base ] ), 'The case-different method name resolves.' );
	}

	public function test_loading_and_a_full_admin_request_create_none_of_the_plugin_options() {
		$names = array( 'emr_news', 'emr_shortpixel_api_key', 'emr_url_cache', 'enable_media_replace' );
		$this->assertSame( array(), $this->existingOptions( $names ), 'Fresh install state.' );

		$this->adminRequestCycle( 'dashboard' );

		$this->assertSame( array(), $this->existingOptions( $names ), 'Created only when used.' );
		$this->assertSame( array(), $this->api->getRequests(), 'No remote call outside the media screens.' );
	}

	// ---------------------------------------------------------------------
	// Deactivation.
	// ---------------------------------------------------------------------

	public function test_deactivation_clears_the_stored_notices_and_keeps_the_settings_and_the_key() {
		$notice = NoticeController::addWarning( 'Lifecycle notice' );
		NoticeController::makePersistent( $notice, 'emr-lifecycle', HOUR_IN_SECONDS );
		update_option( 'enable_media_replace', array( 'replace_type' => 'replace' ) );
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->assertIsArray( get_option( self::NOTICES_OPTION ), 'Sentinel: the notice is stored.' );

		do_action( 'deactivate_' . $this->basename() );

		$this->assertFalse( get_option( self::NOTICES_OPTION ) );
		$this->freshNoticeRequest();
		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'emr-lifecycle' ), 'The next request loads no notice.' );
		$this->assertSame( array( 'replace_type' => 'replace' ), get_option( 'enable_media_replace' ) );
		$this->assertSame( 'abcdEFGHIJKLMNOPwxyz', get_option( 'emr_shortpixel_api_key' ) );
	}

	public function test_a_feature_notice_dismissed_before_deactivation_shows_again_after_reactivation() {
		emr()->uiHelper()->featureNotice();
		$notice = NoticeController::getInstance()->getNoticeByID( UIHelper::NOTICE_NEW_FEATURE );
		$notice->dismiss();
		NoticeController::getInstance()->update();
		$this->freshNoticeRequest();
		$this->assertTrue( NoticeController::getInstance()->getNoticeByID( UIHelper::NOTICE_NEW_FEATURE )->isDismissed(), 'Sentinel: stored as dismissed.' );

		do_action( 'deactivate_' . $this->basename() );
		$this->freshNoticeRequest();
		emr()->uiHelper()->featureNotice();

		$shown = array_map(
			function ( $item ) {
				return $item->getID();
			},
			NoticeController::getInstance()->getNoticesForDisplay()
		);
		$this->assertSame( array( UIHelper::NOTICE_NEW_FEATURE ), $shown );
	}

	// ---------------------------------------------------------------------
	// Uninstall.
	// ---------------------------------------------------------------------

	/**
	 * The full uninstall effect, run as uninstall_plugin() runs the hook
	 * form. What uninstall leaves behind (the API key option, the
	 * `emr_remote_notice` transient, the `EnableMediaReplace-notices`
	 * option and the `_emr_replace_author` post meta) is pinned in
	 * tests/Helper/test-InstallHelper.php and not repeated here.
	 */
	public function test_uninstall_through_the_stored_callable_deletes_the_three_options() {
		update_option( 'enable_media_replace', array( 'bg_type' => 'solid' ) );
		update_option( 'emr_news', 'yes' );
		update_option( 'emr_url_cache', array( 'x' => 'y' ) );
		$this->assertSame( array( 'emr_news', 'emr_url_cache', 'enable_media_replace' ), $this->existingOptions( array( 'enable_media_replace', 'emr_news', 'emr_url_cache' ) ), 'Sentinel: seeded.' );
		$file          = $this->basename();
		$uninstallable = (array) get_option( 'uninstall_plugins' );

		add_action( "uninstall_{$file}", $uninstallable[ $file ] );
		do_action( "uninstall_{$file}" );

		$this->assertSame( array(), $this->existingOptions( array( 'enable_media_replace', 'emr_news', 'emr_url_cache' ) ) );
	}

	// ---------------------------------------------------------------------
	// Upgrade.
	// ---------------------------------------------------------------------

	/**
	 * There is no upgrade routine: nothing stores the installed version
	 * and nothing compares EMR_VERSION with a stored one, so a future
	 * upgrade step has no "previous version" to read and no hook of the
	 * plugin's own to run from.
	 */
	public function test_no_version_is_stored_and_no_upgrade_hook_exists_after_a_full_request() {
		global $wpdb;
		$this->api->setKnob( 'notices', array() );

		$this->adminRequestCycle( 'upload' );

		$this->assertCount( 1, $this->api->requestsTo( 'notices.php' ), 'Sentinel: the media screen ran the remote check.' );
		$versioned = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_value = %s OR ( option_name LIKE %s AND option_name LIKE %s ) OR option_name LIKE %s",
				EMR_VERSION,
				'%emr%',
				'%version%',
				'%enable_media_replace%version%'
			)
		);
		$this->assertSame( array(), $versioned );
		$this->assertSame( array(), $this->emrCallbacksOn( 'upgrader_process_complete' ) );
		$this->assertSame( array(), $this->emrCallbacksOn( 'activated_plugin' ) );
	}

	// ---------------------------------------------------------------------
	// Front end.
	// ---------------------------------------------------------------------

	public function test_a_visitor_request_registers_the_shortcode_and_no_admin_hook() {
		remove_shortcode( 'file_modified' );
		remove_action( 'admin_menu', array( emr(), 'menu' ) );
		remove_action( 'current_screen', array( emr(), 'setScreen' ) );
		remove_filter( 'media_row_actions', array( emr(), 'add_media_action' ), 10 );
		wp_set_current_user( 0 );

		emr()->runtime();

		$this->assertFalse( is_admin() );
		$this->assertTrue( shortcode_exists( 'file_modified' ) );
		$this->assertFalse( has_action( 'admin_menu', array( emr(), 'menu' ) ) );
		$this->assertFalse( has_action( 'current_screen', array( emr(), 'setScreen' ) ) );
		$this->assertFalse( has_filter( 'media_row_actions', array( emr(), 'add_media_action' ) ) );
	}

	/**
	 * clearstatcache() stands for the next request: PHP caches filemtime()
	 * within one process.
	 */
	public function test_the_file_modified_shortcode_shows_the_new_file_time_after_a_replace() {
		$id   = $this->uploadJpeg( 'emr-life-' . $this->token() . '.jpg', 400, 300 );
		$path = get_attached_file( $id );
		$old  = strtotime( '2001-02-03 04:05:06 UTC' );
		touch( $path, $old );
		clearstatcache();
		$shortcode = '[file_modified id="' . $id . '" format="Y-m-d H:i:s"]';

		wp_set_current_user( 0 );
		$before = do_shortcode( $shortcode );
		wp_set_current_user( $this->adminId );
		$this->replaceMode( $id, $this->tmpJpeg( 400, 300, array( 10, 200, 10 ) ) );
		clearstatcache();
		wp_set_current_user( 0 );
		$after = do_shortcode( $shortcode );

		$this->assertSame( wp_date( 'Y-m-d H:i:s', $old ), $before );
		$this->assertSame( wp_date( 'Y-m-d H:i:s', filemtime( $path ) ), $after );
		$this->assertNotSame( $before, $after );
	}

	public function test_the_file_modified_shortcode_renders_nothing_without_an_id_or_for_a_missing_attachment() {
		wp_set_current_user( 0 );
		$missing = self::factory()->post->create() + 1000;

		$this->assertSame( '', do_shortcode( '[file_modified]' ) );
		$this->assertSame( 'a  b', do_shortcode( 'a [file_modified id="' . $missing . '"] b' ) );
		$this->assertFalse( emr()->get_modified_date( array( 'id' => (string) $missing ) ) );
		$this->assertFalse( emr()->get_modified_date( '' ) );
	}
}
