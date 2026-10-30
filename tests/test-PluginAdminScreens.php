<?php
/**
 * Tests for the admin-page side of EnableMediaReplace\EnableMediaReplacePlugin
 * (classes/emr-plugin.php): menu(), hide_sub_menu(), setScreen(), route()
 * and admin_scripts().
 *
 * route() ends in exit() for an unknown action, and the view controllers it
 * dispatches to end the request too. The tests only exercise the dispatch
 * wiring: `media_replace` without a valid attachment_id reaches no
 * controller, and for the other actions the controller singletons are
 * replaced by recording doubles whose load() / loadPost() do nothing else.
 * The default branch (`exit('Something went wrong ...')`, also reached by
 * the background and upscale actions when their feature is off) is never
 * executed.
 *
 * setScreen() can fetch notices.php through RemoteNoticeController: most
 * tests prime the `emr_remote_notice` transient so no fetch happens, the one
 * that fetches answers through MockEmrApi, and a fail-fast filter refuses
 * any other request.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/EmrMainTestCase.php';
require_once __DIR__ . '/Integration/Helpers/MockEmrApi.php';

use EnableMediaReplace\Notices\NoticeController;
use EnableMediaReplace\Notices\NoticeModel;
use EnableMediaReplace\ViewController\ReplaceViewController;
use EnableMediaReplace\ViewController\UploadViewController;
use EnableMediaReplace\ViewController\RemoveBackgroundViewController;
use EnableMediaReplace\ViewController\UpscaleViewController;

/** Records route()'s calls instead of rendering the replace screen. */
class EMR_Main_RecordingReplaceView extends ReplaceViewController {
	public $calls = array();
	public function load() {
		$this->calls[] = 'load';
	}
}

/** Records route()'s calls instead of processing the upload. */
class EMR_Main_RecordingUploadView extends UploadViewController {
	public $calls = array();
	public function load() {
		$this->calls[] = 'load';
	}
}

/** Records route()'s calls instead of the remove-background screens. */
class EMR_Main_RecordingRemoveBgView extends RemoveBackgroundViewController {
	public $calls = array();
	public function load() {
		$this->calls[] = 'load';
	}
	public function loadPost() {
		$this->calls[] = 'loadPost';
	}
}

/** Records route()'s calls instead of the upscale screens. */
class EMR_Main_RecordingUpscaleView extends UpscaleViewController {
	public $calls = array();
	public function load() {
		$this->calls[] = 'load';
	}
	public function loadPost() {
		$this->calls[] = 'loadPost';
	}
}

class PluginAdminScreensTest extends EMR_MainTestCase {

	const TRANSIENT = 'emr_remote_notice';

	/** @var mixed Snapshot of the remote notice transient. */
	private $saved_transient;

	/** @var string[] URLs refused by the fail-fast filter. */
	private $blocked = array();

	public function set_up() {
		parent::set_up();
		$this->saved_transient = get_transient( self::TRANSIENT );
		set_transient( self::TRANSIENT, 'true', DAY_IN_SECONDS );
		$this->blocked = array();
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999 );
		MockEmrApi::unregister();
		if ( false === $this->saved_transient ) {
			delete_transient( self::TRANSIENT );
		} else {
			set_transient( self::TRANSIENT, $this->saved_transient, DAY_IN_SECONDS );
		}
		$blocked       = $this->blocked;
		$this->blocked = array();
		parent::tear_down();
		$this->assertSame( array(), $blocked, 'A request reached the fail-fast filter.' );
	}

	public function fail_fast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->blocked[] = $url;
		return new WP_Error( 'http_blocked', 'Blocked unmocked request to ' . $url );
	}

	/** The EMR entry of $submenu['upload.php'], or null. */
	private function emr_submenu_entry() {
		global $submenu;
		if ( empty( $submenu['upload.php'] ) ) {
			return null;
		}
		foreach ( $submenu['upload.php'] as $entry ) {
			if ( self::PAGE_SLUG === $entry[2] ) {
				return $entry;
			}
		}
		return null;
	}

	/** Prepares a route() call on the EMR page with the given action. */
	private function prepare_route( $action, array $get = array() ) {
		$GLOBALS['plugin_page'] = self::PAGE_SLUG;
		$_GET                   = array_merge( array( 'page' => self::PAGE_SLUG, 'action' => $action ), $get );
		emr()->admin_scripts();
	}

	/** Makes $screen_hook the current screen as WordPress does on an admin page. */
	private function set_screen( $screen_hook ) {
		$GLOBALS['hook_suffix'] = $screen_hook;
		set_current_screen();
		$this->assertSame( $screen_hook, get_current_screen()->id );
	}

	/* ------------------------------------------------------------------
	 * menu() / hide_sub_menu()
	 * ------------------------------------------------------------------ */

	public function test_menu_registers_the_hidden_page_under_media() {
		$GLOBALS['submenu'] = array();
		$this->login_as( 'administrator' );

		$this->assertNull( emr()->menu() );

		$entry = $this->emr_submenu_entry();
		$this->assertIsArray( $entry );
		$this->assertSame( 'Replace media', $entry[0] );
		$this->assertSame( 'upload_files', $entry[1] );
		$this->assertSame( 'Replace media', $entry[3] );
		$hook = get_plugin_page_hookname( self::PAGE_SLUG, 'upload.php' );
		$this->assertSame( 10, has_action( $hook, array( emr(), 'route' ) ) );
	}

	public function test_menu_title_is_remove_background_for_the_prepare_remove_action() {
		$GLOBALS['submenu']  = array();
		$_REQUEST['action'] = 'emr_prepare_remove';
		$this->login_as( 'administrator' );

		emr()->menu();

		$entry = $this->emr_submenu_entry();
		$this->assertSame( 'Remove background', $entry[0] );
		$this->assertSame( 'Remove background', $entry[3] );
	}

	public function test_menu_title_is_upscale_image_for_the_prepare_upscale_action() {
		$GLOBALS['submenu']  = array();
		$_REQUEST['action'] = 'emr_prepare_upscale';
		$this->login_as( 'administrator' );

		emr()->menu();

		$entry = $this->emr_submenu_entry();
		$this->assertSame( 'Upscale image', $entry[0] );
		$this->assertSame( 'Upscale image', $entry[3] );
	}

	public function test_menu_title_stays_replace_media_for_other_actions() {
		$this->login_as( 'administrator' );
		foreach ( array( 'do_background_replace', 'do_upscale_replace', 'media_replace_upload' ) as $action ) {
			$GLOBALS['submenu']  = array();
			$_REQUEST['action'] = $action;

			emr()->menu();

			$this->assertSame( 'Replace media', $this->emr_submenu_entry()[0], $action );
		}
	}

	public function test_menu_for_a_user_without_upload_files_lands_in_the_nopriv_list() {
		$GLOBALS['submenu']            = array();
		$GLOBALS['_wp_submenu_nopriv'] = array();
		$this->login_as( 'subscriber' );

		emr()->menu();

		$this->assertNull( $this->emr_submenu_entry() );
		$this->assertTrue( isset( $GLOBALS['_wp_submenu_nopriv']['upload.php'][ self::PAGE_SLUG ] ) );
	}

	/**
	 * menu() registers the page with the literal capability `upload_files`,
	 * ignoring the capability runtime() checked (stored in $general_cap from
	 * a string or array EMR_CAPABILITY). A user who gets the UI only through
	 * a custom EMR_CAPABILITY sees the buttons but WordPress refuses the
	 * page. Here $general_cap is set to a custom capability, as runtime()
	 * would for `define('EMR_CAPABILITY', 'emr_main_custom_cap')`, and the
	 * page is still registered with `upload_files`.
	 *
	 * How to recognise the fix: the registered capability equals the one
	 * runtime() checked ('emr_main_custom_cap' here). A fix that reads
	 * EMR_CAPABILITY directly instead of $general_cap leaves this pin green;
	 * then the matching test in the IntegrationIsolated suite is the sentinel.
	 */
	public function test_menu_ignores_the_capability_runtime_checked_pinned_for_deferred_fix() {
		$GLOBALS['submenu'] = array();
		$this->login_as( 'administrator' );
		wp_get_current_user()->add_cap( 'emr_main_custom_cap' );
		$this->set_plugin_prop( 'general_cap', 'emr_main_custom_cap' );
		$this->assertTrue( current_user_can( 'emr_main_custom_cap' ) );

		emr()->menu();

		$entry = $this->emr_submenu_entry();
		$this->assertIsArray( $entry );
		$this->assertSame( 'upload_files', $entry[1] );
		$this->assertNotSame( 'emr_main_custom_cap', $entry[1] );
	}

	public function test_hide_sub_menu_on_the_emr_page_highlights_media_and_removes_the_entry() {
		$GLOBALS['submenu'] = array();
		$this->login_as( 'administrator' );
		emr()->menu();
		$this->assertIsArray( $this->emr_submenu_entry() );
		$GLOBALS['plugin_page'] = self::PAGE_SLUG;

		$this->assertSame( 'upload.php', emr()->hide_sub_menu( 'media-new.php' ) );
		$this->assertNull( $this->emr_submenu_entry() );
	}

	public function test_hide_sub_menu_elsewhere_passes_the_file_through_and_still_removes_the_entry() {
		$GLOBALS['submenu'] = array();
		$this->login_as( 'administrator' );
		emr()->menu();
		$GLOBALS['plugin_page'] = 'some-other-plugin';

		$this->assertSame( 'media-new.php', emr()->hide_sub_menu( 'media-new.php' ) );
		$this->assertNull( emr()->hide_sub_menu( null ) );
		$this->assertNull( $this->emr_submenu_entry() );
	}

	public function test_hide_sub_menu_without_a_plugin_page_passes_through() {
		$GLOBALS['submenu']     = array();
		$GLOBALS['plugin_page'] = null;

		$this->assertSame( 'upload.php', emr()->hide_sub_menu( 'upload.php' ) );
		$this->assertSame( '', emr()->hide_sub_menu( '' ) );
	}

	/* ------------------------------------------------------------------
	 * setScreen()
	 * ------------------------------------------------------------------ */

	/**
	 * @dataProvider notice_screens
	 */
	public function test_set_screen_loads_notices_on_the_media_screens( $screen_hook ) {
		$this->login_as( 'administrator' );
		emr()->adminInit();
		$this->set_screen( $screen_hook );
		NoticeModel::$icons = array();

		$this->assertNull( emr()->setScreen() );

		$this->assertIsObject( $this->remote_instance(), 'RemoteNoticeController is instantiated.' );
		$notices = NoticeController::getInstance();
		$this->assertSame( 10, has_action( 'admin_notices', array( $notices, 'admin_notices' ) ) );
		$this->assertNotEmpty( NoticeModel::$icons );
		$this->assertStringContainsString( 'img/notices/robo-scared.png', implode( '', NoticeModel::$icons ) );
	}

	public function notice_screens() {
		return array(
			'media library'  => array( 'upload' ),
			'edit media'     => array( 'attachment' ),
			'the EMR page'   => array( 'media_page_enable-media-replace/enable-media-replace' ),
		);
	}

	public function test_set_screen_does_nothing_on_another_screen() {
		$this->login_as( 'administrator' );
		emr()->adminInit();
		$this->set_screen( 'tools' );
		NoticeModel::$icons = array();

		emr()->setScreen();

		$this->assertNull( $this->remote_instance() );
		$this->assertSame( array(), NoticeModel::$icons );
		$this->assertFalse( has_action( 'admin_notices', array( NoticeController::getInstance(), 'admin_notices' ) ) );
	}

	public function test_set_screen_does_nothing_with_the_remote_notice_feature_off() {
		$this->login_as( 'administrator' );
		$this->add_test_filter( 'emr/feature/remote_notice', '__return_false' );
		emr()->adminInit();
		$this->set_screen( 'upload' );
		NoticeModel::$icons = array();

		emr()->setScreen();

		$this->assertNull( $this->remote_instance() );
		$this->assertSame( array(), NoticeModel::$icons );
	}

	public function test_set_screen_fetches_remote_notices_once_when_the_transient_is_empty() {
		delete_transient( self::TRANSIENT );
		$mock = MockEmrApi::register();
		$mock->reset();
		$mock->setKnob( 'notices', array( array( 'id' => 'emr-main-remote', 'message' => 'Remote hello', 'type' => 'warning' ) ) );
		$this->login_as( 'administrator' );
		emr()->adminInit();
		$this->set_screen( 'upload' );

		emr()->setScreen();

		$this->assertCount( 1, $mock->requestsTo( 'notices.php' ) );
		$notice = NoticeController::getInstance()->getNoticeByID( 'emr-main-remote' );
		$this->assertIsObject( $notice );
		$this->assertSame( 'true', get_transient( self::TRANSIENT ) );
	}

	/* ------------------------------------------------------------------
	 * route()
	 * ------------------------------------------------------------------ */

	public function test_route_on_another_plugin_page_does_nothing() {
		$this->login_as( 'administrator' );
		$GLOBALS['plugin_page'] = 'another-plugin/page.php';
		$_GET['action']         = 'media_replace';
		emr()->admin_scripts();

		$this->assertNull( emr()->route() );

		$this->assertFalse( wp_script_is( 'emr_admin', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'emr_style', 'enqueued' ) );
		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'EMR001' ) );
	}

	public function test_route_media_replace_without_attachment_id_only_enqueues_the_shared_assets() {
		$this->login_as( 'administrator' );
		$double                          = new EMR_Main_RecordingReplaceView();
		ReplaceViewController::$instance = $double;
		$this->prepare_route( 'media_replace', array( 'attachment_id' => '0' ) );

		$output = $this->capture( array( emr(), 'route' ) );

		$this->assertSame( '', $output );
		$this->assertTrue( wp_script_is( 'emr_admin', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'emr_style', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'jquery-ui-datepicker', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'emr_upsell', 'enqueued' ) );
		$this->assertSame( array(), $double->calls );
	}

	public function test_route_media_replace_with_a_non_numeric_attachment_id_reaches_no_controller() {
		$this->login_as( 'administrator' );
		$double                          = new EMR_Main_RecordingReplaceView();
		ReplaceViewController::$instance = $double;
		$this->prepare_route( 'media_replace', array( 'attachment_id' => 'abc' ) );

		emr()->route();

		$this->assertSame( array(), $double->calls );
		$this->assertFalse( wp_script_is( 'emr_upsell', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'emr_admin', 'enqueued' ) );
	}

	public function test_route_media_replace_without_the_attachment_id_key_reaches_no_controller() {
		$this->login_as( 'administrator' );
		$double                          = new EMR_Main_RecordingReplaceView();
		ReplaceViewController::$instance = $double;
		$this->prepare_route( 'media_replace' );

		emr()->route();

		$this->assertSame( array(), $double->calls );
	}

	public function test_route_media_replace_with_an_attachment_id_loads_the_replace_controller() {
		$this->login_as( 'administrator' );
		$double                          = new EMR_Main_RecordingReplaceView();
		ReplaceViewController::$instance = $double;
		$this->prepare_route( 'media_replace', array( 'attachment_id' => '57' ) );

		emr()->route();

		$this->assertSame( array( 'load' ), $double->calls );
		$this->assertTrue( wp_script_is( 'emr_upsell', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'emr_remove_bg', 'enqueued' ) );
	}

	public function test_route_queues_the_feature_notice() {
		$this->login_as( 'administrator' );
		$this->prepare_route( 'media_replace', array( 'attachment_id' => '0' ) );
		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'EMR001' ) );

		emr()->route();

		$this->assertIsObject( NoticeController::getInstance()->getNoticeByID( 'EMR001' ) );
	}

	public function test_route_sanitizes_the_action() {
		$this->login_as( 'administrator' );
		$double                          = new EMR_Main_RecordingReplaceView();
		ReplaceViewController::$instance = $double;
		$this->prepare_route( '<b>media_replace</b> ', array( 'attachment_id' => '57' ) );

		emr()->route();

		$this->assertSame( array( 'load' ), $double->calls );
	}

	public function test_route_media_replace_upload_loads_the_upload_controller() {
		$this->login_as( 'administrator' );
		$double                         = new EMR_Main_RecordingUploadView();
		UploadViewController::$instance = $double;
		$this->prepare_route( 'media_replace_upload' );

		emr()->route();

		$this->assertSame( array( 'load' ), $double->calls );
		$this->assertTrue( wp_script_is( 'emr_admin', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'emr_upsell', 'enqueued' ) );
	}

	public function test_route_emr_prepare_remove_loads_the_remove_background_screen_and_assets() {
		$this->login_as( 'administrator' );
		emr()->adminInit();
		$double                                   = new EMR_Main_RecordingRemoveBgView();
		RemoveBackgroundViewController::$instance = $double;
		$this->prepare_route( 'emr_prepare_remove', array( 'attachment_id' => '57' ) );

		emr()->route();

		$this->assertSame( array( 'load' ), $double->calls );
		$this->assertTrue( wp_script_is( 'emr_remove_bg', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'emr_upsell', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'emr-remove-background', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'emr_style', 'enqueued' ) );
	}

	public function test_route_do_background_replace_calls_load_post() {
		$this->login_as( 'administrator' );
		emr()->adminInit();
		$double                                   = new EMR_Main_RecordingRemoveBgView();
		RemoveBackgroundViewController::$instance = $double;
		$this->prepare_route( 'do_background_replace' );

		emr()->route();

		$this->assertSame( array( 'loadPost' ), $double->calls );
		$this->assertFalse( wp_script_is( 'emr_remove_bg', 'enqueued' ) );
	}

	public function test_route_emr_prepare_upscale_loads_the_upscale_screen_with_the_remove_background_assets() {
		$this->login_as( 'administrator' );
		emr()->adminInit();
		$upscale                                  = new EMR_Main_RecordingUpscaleView();
		$remove                                   = new EMR_Main_RecordingRemoveBgView();
		UpscaleViewController::$instance          = $upscale;
		RemoveBackgroundViewController::$instance = $remove;
		$this->prepare_route( 'emr_prepare_upscale', array( 'attachment_id' => '57' ) );

		emr()->route();

		$this->assertSame( array( 'load' ), $upscale->calls );
		$this->assertSame( array(), $remove->calls, 'The upscale action has its own controller.' );
		$this->assertTrue( wp_script_is( 'emr_remove_bg', 'enqueued' ), 'The upscale screen reuses the remove-background script.' );
		$this->assertTrue( wp_script_is( 'emr_upsell', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'emr-remove-background', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'emr_style', 'enqueued' ) );
	}

	public function test_route_do_upscale_replace_calls_load_post_on_the_upscale_controller() {
		$this->login_as( 'administrator' );
		emr()->adminInit();
		$upscale                                  = new EMR_Main_RecordingUpscaleView();
		$remove                                   = new EMR_Main_RecordingRemoveBgView();
		UpscaleViewController::$instance          = $upscale;
		RemoveBackgroundViewController::$instance = $remove;
		$this->prepare_route( 'do_upscale_replace' );

		emr()->route();

		$this->assertSame( array( 'loadPost' ), $upscale->calls );
		$this->assertSame( array(), $remove->calls );
		$this->assertFalse( wp_script_is( 'emr_remove_bg', 'enqueued' ) );
	}

	public function test_route_background_actions_do_not_touch_the_upscale_controller() {
		$this->login_as( 'administrator' );
		emr()->adminInit();
		$upscale                                  = new EMR_Main_RecordingUpscaleView();
		$remove                                   = new EMR_Main_RecordingRemoveBgView();
		UpscaleViewController::$instance          = $upscale;
		RemoveBackgroundViewController::$instance = $remove;
		$this->prepare_route( 'emr_prepare_remove', array( 'attachment_id' => '57' ) );

		emr()->route();

		$this->assertSame( array( 'load' ), $remove->calls );
		$this->assertSame( array(), $upscale->calls );
	}

	/**
	 * route() enqueues the style handle `jquery-ui-datepicker`, which neither
	 * WordPress core nor EMR registers. WordPress parks the handle in the
	 * private `queued_before_register` list of WP_Dependencies and the call
	 * loads nothing.
	 *
	 * How to recognise the fix: the handle is registered (a stylesheet is
	 * shipped for it), or route() no longer enqueues it (it disappears from
	 * `queued_before_register`).
	 */
	public function test_route_enqueues_an_unregistered_datepicker_style_pinned_for_deferred_fix() {
		$this->login_as( 'administrator' );
		$this->prepare_route( 'media_replace', array( 'attachment_id' => '0' ) );
		$this->assertTrue( wp_script_is( 'jquery-ui-datepicker', 'registered' ), 'The script handle exists in core.' );

		emr()->route();

		$this->assertFalse( wp_style_is( 'jquery-ui-datepicker', 'registered' ) );
		$this->assertFalse( wp_style_is( 'jquery-ui-datepicker', 'enqueued' ) );
		$parked = new ReflectionProperty( WP_Dependencies::class, 'queued_before_register' );
		$parked->setAccessible( true );
		$this->assertArrayHasKey( 'jquery-ui-datepicker', $parked->getValue( wp_styles() ) );
	}

	/* ------------------------------------------------------------------
	 * admin_scripts()
	 * ------------------------------------------------------------------ */

	public function test_admin_scripts_registers_but_never_enqueues() {
		$this->login_as( 'administrator' );

		$this->assertNull( emr()->admin_scripts() );

		foreach ( array( 'emr_style', 'emr_edit-attachment', 'emr-remove-background' ) as $handle ) {
			$this->assertTrue( wp_style_is( $handle, 'registered' ), $handle );
			$this->assertFalse( wp_style_is( $handle, 'enqueued' ), $handle );
		}
		$this->assertFalse( wp_style_is( 'emr-redesign', 'registered' ), 'The redesign rules live in the SCSS sources, no separate stylesheet.' );
		foreach ( array( 'emr_admin', 'emr_upsell', 'emr_remove_bg', 'emr_shortpixel_account', 'emr_success' ) as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'registered' ), $handle );
			$this->assertFalse( wp_script_is( $handle, 'enqueued' ), $handle );
		}
	}

	public function test_admin_scripts_registers_the_ltr_or_rtl_admin_stylesheet() {
		emr()->admin_scripts();
		$this->assertSame( plugins_url( 'css/admin.css', EMR_ROOT_FILE ), wp_styles()->registered['emr_style']->src );

		$GLOBALS['wp_styles']                 = null;
		$GLOBALS['wp_locale']->text_direction = 'rtl';
		$this->assertTrue( is_rtl() );
		emr()->admin_scripts();

		$this->assertSame( plugins_url( 'css/rtl/admin.css', EMR_ROOT_FILE ), wp_styles()->registered['emr_style']->src );
		$this->assertFileExists( dirname( EMR_ROOT_FILE ) . '/css/rtl/admin.css' );
	}

	public function test_admin_scripts_style_sources() {
		emr()->admin_scripts();
		$styles = wp_styles()->registered;

		$this->assertSame( plugins_url( 'css/edit_attachment.css', EMR_ROOT_FILE ), $styles['emr_edit-attachment']->src );
		$this->assertSame( plugins_url( 'css/remove_background.css', EMR_ROOT_FILE ), $styles['emr-remove-background']->src );
		$this->assertArrayNotHasKey( 'emr-redesign', $styles );
		$this->assertFileDoesNotExist( dirname( EMR_ROOT_FILE ) . '/css/redesign.css' );
	}

	public function test_admin_scripts_script_sources_versions_and_dependencies() {
		emr()->admin_scripts();
		$scripts = wp_scripts()->registered;

		$this->assertSame( plugins_url( 'js/emr_admin.js', EMR_ROOT_FILE ), $scripts['emr_admin']->src );
		$this->assertSame( array( 'jquery' ), $scripts['emr_admin']->deps );
		$this->assertSame( EMR_VERSION, $scripts['emr_admin']->ver );
		$this->assertSame( array( 'jquery' ), $scripts['emr_upsell']->deps );
		$this->assertSame( filemtime( dirname( EMR_ROOT_FILE ) . '/js/remove_bg.js' ), $scripts['emr_remove_bg']->ver );
		$this->assertSame( array(), $scripts['emr_success']->deps );
		$this->assertSame( 1, wp_scripts()->get_data( 'emr_admin', 'group' ), 'Registered for the footer.' );
		$this->assertSame( plugins_url( 'js/shortpixel-account.js', EMR_ROOT_FILE ), $scripts['emr_shortpixel_account']->src );
		$this->assertSame( array(), $scripts['emr_shortpixel_account']->deps, 'The account script does not use jQuery.' );
		$this->assertSame( filemtime( dirname( EMR_ROOT_FILE ) . '/js/shortpixel-account.js' ), $scripts['emr_shortpixel_account']->ver );
		$this->assertSame( 1, wp_scripts()->get_data( 'emr_shortpixel_account', 'group' ), 'Registered for the footer.' );
	}

	/**
	 * wp_localize_script() turns scalar values into strings, so maxfilesize
	 * and is_debug arrive as strings in the page.
	 */
	public function test_admin_scripts_localises_emr_options() {
		update_option( 'date_format', 'Y/m/d' );

		emr()->admin_scripts();
		$options = $this->localized( 'emr_admin', 'emr_options' );

		$this->assertSame( 'yy/mm/dd', $options['dateFormat'] );
		$this->assertSame( (string) wp_max_upload_size(), $options['maxfilesize'] );
		$this->assertSame( array_values( get_allowed_mime_types() ), $options['allowed_mime'] );
		$this->assertArrayHasKey( 'is_debug', $options, 'Debug is active under the test bootstrap.' );
		$this->assertSame( '1', $options['is_debug'] );
	}

	public function test_admin_scripts_localises_the_upsell_data() {
		emr()->admin_scripts();
		$data = $this->localized( 'emr_upsell', 'emr_upsell' );

		$this->assertSame( admin_url( 'admin-ajax.php' ), $data['ajax'] );
		$this->assertSame( 'Installing ...', $data['installing'] );
	}

	public function test_admin_scripts_localises_a_valid_remove_background_nonce() {
		$this->login_as( 'administrator' );

		emr()->admin_scripts();
		$data = $this->localized( 'emr_remove_bg', 'emrObject' );

		$this->assertSame( admin_url( 'admin-ajax.php' ), $data['ajax_url'] );
		$this->assertSame( 1, wp_verify_nonce( $data['nonce'], 'emr_remove_background' ) );
		$this->assertFalse( wp_verify_nonce( $data['nonce'], 'media_replace' ) );
	}

	public function test_admin_scripts_localises_the_account_data_with_a_valid_save_key_nonce() {
		$this->login_as( 'administrator' );

		emr()->admin_scripts();
		$data = $this->localized( 'emr_shortpixel_account', 'emrAccount' );

		$this->assertSame( admin_url( 'admin-ajax.php' ), $data['ajax_url'] );
		$this->assertSame( 1, wp_verify_nonce( $data['nonce'], 'emr_save_api_key' ) );
		$this->assertFalse( wp_verify_nonce( $data['nonce'], 'emr_remove_background' ) );
		$this->assertSame( array( 'verifying' => 'Verifying…', 'request_failed' => 'Request failed. Please try again.' ), $data['strings'] );
	}

	public function test_admin_scripts_success_timeout_defaults_to_five_and_is_filterable() {
		emr()->admin_scripts();
		$this->assertSame( '5', $this->localized( 'emr_success', 'emr_success_options' )['timeout'] );

		$GLOBALS['wp_scripts'] = null;
		$this->add_test_filter(
			'emr/success/timeout',
			function () {
				return 12;
			}
		);
		emr()->admin_scripts();

		$this->assertSame( '12', $this->localized( 'emr_success', 'emr_success_options' )['timeout'] );
	}

	/* ------------------------------------------------------------------
	 * convertdate()
	 * ------------------------------------------------------------------ */

	/**
	 * @dataProvider date_formats
	 */
	public function test_convertdate_maps_wordpress_formats_to_datepicker_formats( $php, $expected ) {
		$this->assertSame( $expected, emr()->convertdate( $php ) );
	}

	public function date_formats() {
		return array(
			'F j, Y'          => array( 'F j, Y', 'MM dd, yy' ),
			'Y/m/d'           => array( 'Y/m/d', 'yy/mm/dd' ),
			'm/d/Y'           => array( 'm/d/Y', 'mm/dd/yy' ),
			'd/m/Y'           => array( 'd/m/Y', 'dd/mm/yy' ),
			'Y-m-d (custom)'  => array( 'Y-m-d', 'dd/mm/yy' ),
			'empty'           => array( '', 'dd/mm/yy' ),
			'case differs'    => array( 'y/m/d', 'dd/mm/yy' ),
		);
	}
}
