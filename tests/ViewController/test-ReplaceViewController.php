<?php
/**
 * Tests for EnableMediaReplace\ViewController\ReplaceViewController
 * (classes/ViewController/ReplaceViewController.php) and the replace
 * screen it renders, views/screen.php.
 *
 * The controller is reached through EMR_TestReplaceViewController (see
 * ViewControllerTestDoubles.php): viewError() runs for real up to the
 * loadView('error') call, which throws EMR_ViewExit instead of rendering
 * and exiting, so the unreachable wp_die() after it is never reached
 * either. loadView('screen') is the real one: load() renders
 * views/screen.php, captured with output buffering (the screen does not
 * require the admin footer).
 *
 * screen.php includes views/upsell.php with include_once, so the upsell
 * section renders at most once per PHP process. The upsell tests run in a
 * separate process; the in-process tests never assert on the upsell.
 *
 * Snapshot/restore: the current user, $_GET / $_REQUEST, the
 * `enable_media_replace` option, ViewController::$viewsLoaded,
 * ReplaceViewController::$instance and the UIHelper singleton, every hook
 * a test adds, the attachments and files created.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ViewControllerTestDoubles.php';

use EnableMediaReplace\ViewController;
use EnableMediaReplace\ViewController\ReplaceViewController;
use EnableMediaReplace\FileSystem\Model\File\FileModel;

class ReplaceViewControllerTest extends WP_UnitTestCase {

	const OPTION = 'enable_media_replace';

	/** @var int */
	private $saved_user;

	/** @var array */
	private $saved_request = array();

	/** @var mixed */
	private $saved_option;

	/** @var array */
	private $saved_views_loaded;

	/** @var mixed */
	private $saved_instance;

	/** @var mixed */
	private $saved_uihelper;

	/** @var int */
	private $admin_id;

	/** @var int[] */
	private $attachments = array();

	/** @var string[] */
	private $files = array();

	/** @var array */
	private $hooks = array();

	public function set_up() {
		parent::set_up();
		$this->saved_user    = get_current_user_id();
		$this->saved_request = array( '_GET' => $_GET, '_REQUEST' => $_REQUEST );
		$_GET                = array();
		$_REQUEST            = array();
		$this->saved_option  = get_option( self::OPTION, null );
		delete_option( self::OPTION );

		$this->saved_views_loaded = $this->static_value( ViewController::class, 'viewsLoaded' );
		$this->set_static( ViewController::class, 'viewsLoaded', array() );
		$this->saved_uihelper = $this->static_value( \EnableMediaReplace\UIHelper::class, 'instance' );
		$this->set_static( \EnableMediaReplace\UIHelper::class, 'instance', null );

		$this->saved_instance            = ReplaceViewController::$instance;
		ReplaceViewController::$instance = null;

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down() {
		foreach ( $this->hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$this->hooks = array();
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachments = array();
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();

		if ( null === $this->saved_option ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $this->saved_option, false );
		}
		$_GET     = $this->saved_request['_GET'];
		$_REQUEST = $this->saved_request['_REQUEST'];

		$this->set_static( ViewController::class, 'viewsLoaded', $this->saved_views_loaded );
		$this->set_static( \EnableMediaReplace\UIHelper::class, 'instance', $this->saved_uihelper );
		ReplaceViewController::$instance = $this->saved_instance;

		wp_set_current_user( $this->saved_user );
		parent::tear_down();
	}

	/*
	 * Helpers.
	 */

	private function static_value( $class, $name ) {
		$ref = new ReflectionProperty( $class, $name );
		$ref->setAccessible( true );
		return $ref->getValue();
	}

	private function set_static( $class, $name, $value ) {
		$ref = new ReflectionProperty( $class, $name );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	private function add_hook( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->hooks[] = array( $hook, $callback, $priority );
	}

	private function upload( $author = null ) {
		$copy = get_temp_dir() . 'emr-rvc-' . wp_generate_password( 8, false, false ) . '.jpg';
		copy( dirname( __DIR__ ) . '/fixtures/test-image.jpg', $copy );
		$this->files[] = $copy;
		$id            = self::factory()->attachment->create_upload_object( $copy );
		if ( null !== $author ) {
			wp_update_post( array( 'ID' => $id, 'post_author' => $author ) );
		}
		$this->attachments[] = $id;
		return $id;
	}

	private function user( $role ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Runs load() for $id and returns array( controller, output ).
	 */
	private function load( $id ) {
		$_GET['attachment_id'] = (string) $id;
		$controller            = new EMR_TestReplaceViewController();
		ob_start();
		try {
			$controller->load();
		} finally {
			$output = ob_get_clean();
		}
		return array( $controller, $output );
	}

	private function load_exit( $id ) {
		$_GET['attachment_id'] = (string) $id;
		$controller            = new EMR_TestReplaceViewController();
		ob_start();
		try {
			$controller->load();
		} catch ( EMR_ViewExit $exit ) {
			ob_end_clean();
			return array( $controller, $exit );
		}
		ob_end_clean();
		$this->fail( 'load() returned instead of ending at the error view.' );
	}

	/*
	 * Singleton.
	 */

	public function test_get_instance_returns_one_replace_view_controller() {
		$first = ReplaceViewController::getInstance();

		$this->assertInstanceOf( ReplaceViewController::class, $first );
		$this->assertSame( $first, ReplaceViewController::getInstance() );
	}

	/*
	 * load(): permission.
	 */

	public function test_load_without_permission_on_the_attachment_shows_the_image_permission_error() {
		$id = $this->upload( $this->admin_id );
		$this->user( 'author' );

		list( $controller, $exit ) = $this->load_exit( $id );

		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $exit->getCode() );
		$this->assertSame( array( 'error' ), $controller->loadedViews );
		$this->assertObjectNotHasProperty( 'attachment', $controller->getView() );
	}

	public function test_load_for_a_missing_or_non_attachment_post_shows_the_image_permission_error() {
		foreach ( array( 0, 987654, self::factory()->post->create() ) as $id ) {
			list( $controller, $exit ) = $this->load_exit( $id );

			$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $exit->getCode(), (string) $id );
		}
	}

	/*
	 * load(): view data.
	 */

	public function test_load_fills_the_view_and_renders_the_screen() {
		$id = $this->upload();

		list( $controller, $output ) = $this->load( $id );
		$view                        = $controller->getView();

		$this->assertSame( 0, $controller->errorCalls );
		$this->assertSame( array( 'screen' ), $controller->loadedViews );
		$this->assertInstanceOf( WP_Post::class, $view->attachment );
		$this->assertSame( $id, $view->attachment->ID );
		$this->assertInstanceOf( FileModel::class, $view->sourceFile );
		$this->assertSame( get_attached_file( $id ), $view->sourceFile->getFullPath() );
		$this->assertSame( 'image/jpeg', $view->sourceMime );
		$this->assertFalse( $view->is_author_restricted );
		$this->assertTrue( $view->is_movable );
		$uploads = wp_upload_dir();
		$this->assertSame( trailingslashit( $uploads['basedir'] ), $view->custom_basedir );
		$this->assertStringEndsWith( '/', $view->custom_basedir );
		$this->assertStringContainsString( '<div class="wrap emr_upload_form">', $output );
	}

	public function test_load_settings_default_to_the_documented_values() {
		$id = $this->upload();

		list( $controller ) = $this->load( $id );
		$settings           = $controller->getView()->settings;

		$this->assertSame( array( 'replace_type', 'timestamp_replace', 'custom_date', 'new_location', 'new_location_dir' ), array_keys( $settings ) );
		$this->assertSame( 'replace', $settings['replace_type'] );
		$this->assertSame( 2, $settings['timestamp_replace'] );
		$this->assertFalse( $settings['new_location'] );
		$this->assertFalse( $settings['new_location_dir'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $settings['custom_date'] );
		$this->assertLessThanOrEqual( 5, abs( time() - strtotime( $settings['custom_date'] . ' UTC' ) ), 'date() is server (UTC) time.' );
	}

	public function test_load_merges_the_stored_option_over_the_defaults() {
		update_option(
			self::OPTION,
			array(
				'replace_type'      => 'replace_and_search',
				'timestamp_replace' => 3,
				'custom_date'       => '2020-02-03 04:05:00',
				'bg_type'           => 'solid',
			),
			false
		);
		$id = $this->upload();

		list( $controller ) = $this->load( $id );
		$settings           = $controller->getView()->settings;

		$this->assertSame( 'replace_and_search', $settings['replace_type'] );
		$this->assertSame( 3, $settings['timestamp_replace'] );
		$this->assertSame( '2020-02-03 04:05:00', $settings['custom_date'] );
		$this->assertFalse( $settings['new_location'], 'Missing keys come from the defaults.' );
		$this->assertFalse( $settings['new_location_dir'] );
		$this->assertSame( 'solid', $settings['bg_type'], 'Other keys pass through.' );
	}

	public function test_load_forces_replace_for_author_restricted_users() {
		update_option( self::OPTION, array( 'replace_type' => 'replace_and_search' ), false );
		$author = $this->user( 'author' );
		$id     = $this->upload( $author );

		list( $controller, $output ) = $this->load( $id );

		$this->assertTrue( $controller->getView()->is_author_restricted );
		$this->assertSame( 'replace', $controller->getView()->settings['replace_type'] );
		$this->assertMatchesRegularExpression( '/<div class="option searchreplace disabled">/', $output );
	}

	public function test_load_default_type_filter_overrides_the_stored_type() {
		update_option( self::OPTION, array( 'replace_type' => 'replace' ), false );
		$seen = array();
		$this->add_hook(
			'emr/replace/default_type',
			function ( $type, $attachment_id ) use ( &$seen ) {
				$seen[] = array( $type, $attachment_id );
				return 'replace_and_search';
			},
			10,
			2
		);
		$id = $this->upload();

		list( $controller ) = $this->load( $id );

		$this->assertSame( 'replace_and_search', $controller->getView()->settings['replace_type'] );
		$this->assertSame( array( array( 'replace', $id ) ), $seen );
	}

	/**
	 * Documented current behaviour: `emr/replace/default_type` runs after the
	 * author restriction and can set 'replace_and_search' for a restricted
	 * user. The screen still disables that radio for them, and getPost()
	 * forces 'replace' on submit.
	 */
	public function test_load_default_type_filter_overrides_the_author_restriction() {
		$this->add_hook(
			'emr/replace/default_type',
			function () {
				return 'replace_and_search';
			}
		);
		$author = $this->user( 'author' );
		$id     = $this->upload( $author );

		list( $controller, $output ) = $this->load( $id );

		$this->assertTrue( $controller->getView()->is_author_restricted );
		$this->assertSame( 'replace_and_search', $controller->getView()->settings['replace_type'] );
		$this->assertMatchesRegularExpression( '/<input id="replace_type_2"\s+checked=\'checked\' type="radio" name="replace_type" value="replace_and_search" disabled >/', $output );
	}

	public function test_load_is_movable_comes_from_the_file_is_movable_filter() {
		$seen = array();
		$this->add_hook(
			'emr/replace/file_is_movable',
			function ( $movable, $attachment_id ) use ( &$seen ) {
				$seen[] = array( $movable, $attachment_id );
				return false;
			},
			10,
			2
		);
		$id = $this->upload();

		list( $controller, $output ) = $this->load( $id );

		$this->assertFalse( $controller->getView()->is_movable );
		$this->assertSame( array( array( true, $id ) ), $seen );
		$this->assertStringContainsString( "File is offloaded and can't be moved to other directory", $output );
		$this->assertStringNotContainsString( 'name="new_location"', $output );
		$this->assertStringNotContainsString( 'name="location_dir"', $output );
	}

	/*
	 * views/screen.php.
	 */

	public function test_screen_form_posts_to_the_upload_url_with_the_nonce_and_id() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$action = esc_url( emr()->uiHelper()->getFormUrl( $id ) );
		$this->assertStringContainsString( '<form enctype="multipart/form-data" method="POST" action="' . $action . '">', $output );
		$this->assertStringContainsString( 'action=media_replace_upload', $output );
		$this->assertMatchesRegularExpression( '/<input type="hidden" id="emr_nonce" name="emr_nonce" value="([a-f0-9]+)" \/>/', $output );
		preg_match( '/name="emr_nonce" value="([a-f0-9]+)"/', $output, $nonce );
		$this->assertSame( 1, wp_verify_nonce( $nonce[1], 'media_replace_upload' ) );
		$this->assertStringContainsString( '<input type="hidden" name="ID" value="' . $id . '" />', $output );
		$this->assertStringContainsString( '<input id="submit" type="submit" class="button button-primary" disabled="disabled" value="Upload" />', $output );
	}

	public function test_screen_has_both_replace_type_radios_with_the_setting_checked() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertMatchesRegularExpression( '/<input\s+checked=\'checked\' id="replace_type_1" type="radio" name="replace_type" value="replace"\s+>/', $output );
		$this->assertMatchesRegularExpression( '/<input id="replace_type_2"\s+type="radio" name="replace_type" value="replace_and_search"\s+>/', $output );
		$this->assertStringContainsString( "<div class='option replace '>", $output );
		$this->assertStringContainsString( '<div class="option searchreplace ">', $output );
	}

	public function test_screen_has_the_three_time_modes_and_the_custom_date_inputs() {
		update_option( self::OPTION, array( 'timestamp_replace' => 3, 'custom_date' => '2019-08-07 06:05:00' ), false );
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( "<input type='radio'  name='timestamp_replace' value='1' />", $output );
		$this->assertStringContainsString( "<input type='radio'  name='timestamp_replace' value='2'  />", $output );
		$this->assertStringContainsString( "<input type='radio'  checked='checked' name='timestamp_replace' value='3' />", $output );
		$this->assertStringContainsString( 'name="custom_date" value="' . ( new DateTime( '2019-08-07 06:05:00' ) )->format( get_option( 'date_format' ) ) . '"', $output );
		$this->assertStringContainsString( 'name="custom_hour" class=\'emr_hour\' id=\'emr_hour\' value="06"', $output );
		$this->assertStringContainsString( 'name="custom_minute" class=\'emr_minute\' id=\'emr_minute\' value="05"', $output );
		$this->assertStringContainsString( '<input type="hidden" name="custom_date_formatted" value="2019-08-07" />', $output );
	}

	public function test_screen_shows_the_location_inputs_when_movable() {
		$id = $this->upload();
		$this->assertNotSame( '', emr()->uiHelper()->getRelPathNow(), 'Precondition: year/month uploads.' );

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( '<input type="checkbox" name="new_location" value="1"', $output );
		$this->assertStringContainsString( '<input type="text" name="location_dir" value="' . esc_attr( emr()->uiHelper()->getRelPathNow() ) . '" />', $output );
		$uploads = wp_upload_dir();
		$this->assertStringContainsString( trailingslashit( $uploads['basedir'] ) . ' <input type="text" name="location_dir"', $output );
	}

	public function test_screen_location_dir_shows_the_stored_dir_when_new_location_is_set() {
		update_option( self::OPTION, array( 'new_location' => true, 'new_location_dir' => '2001/02' ), false );
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( '<input type="text" name="location_dir" value="2001/02" />', $output );
		$this->assertMatchesRegularExpression( "/name=\"new_location\" value=\"1\"\s+checked='checked'/", $output );
	}

	public function test_screen_display_replace_type_options_false_disables_the_replace_option() {
		$this->add_hook( 'emr_display_replace_type_options', '__return_false' );
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( "<div class='option replace disabled'>", $output );
		$this->assertMatchesRegularExpression( '/name="replace_type" value="replace" disabled >/', $output );
		$this->assertStringContainsString( '<div class="option searchreplace ">', $output );
	}

	public function test_screen_enable_replace_and_search_false_disables_the_search_option() {
		$this->add_hook( 'emr_enable_replace_and_search', '__return_false' );
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( '<div class="option searchreplace disabled">', $output );
		$this->assertMatchesRegularExpression( '/name="replace_type" value="replace_and_search" disabled >/', $output );
		$this->assertStringContainsString( "<div class='option replace '>", $output );
	}

	public function test_screen_fires_the_replace_type_actions_in_order() {
		$fired = array();
		foreach ( array( 'emr_before_replace_type_options', 'emr_after_search_type_options', 'emr_after_replace_type_options' ) as $action ) {
			$this->add_hook(
				$action,
				function () use ( $action, &$fired ) {
					$fired[] = $action;
					echo '<!-- marker:' . $action . ' -->';
				}
			);
		}
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertSame( array( 'emr_before_replace_type_options', 'emr_after_search_type_options', 'emr_after_replace_type_options' ), $fired );
		$before = strpos( $output, '<!-- marker:emr_before_replace_type_options -->' );
		$radio  = strpos( $output, 'id="replace_type_1"' );
		$search = strpos( $output, '<!-- marker:emr_after_search_type_options -->' );
		$radio2 = strpos( $output, 'id="replace_type_2"' );
		$after  = strpos( $output, '<!-- marker:emr_after_replace_type_options -->' );
		$this->assertTrue( $before < $radio && $radio < $search && $search < $radio2 && $radio2 < $after );
	}

	public function test_screen_shows_the_current_preview_image() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( "<div class='image_previews'>", $output );
		$this->assertStringContainsString( 'Current', $output );
		$this->assertStringContainsString( wp_basename( get_attached_file( $id ) ), $output );
	}

	public function test_screen_offers_remove_background_to_unrestricted_users() {
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( '<input type="checkbox" id="remove_after_progress" name="remove_after_progress" value="' . $id . '">', $output );
		$this->assertStringContainsString( 'action=emr_prepare_remove', $output );
	}

	public function test_screen_hides_remove_background_from_author_restricted_users() {
		$author = $this->user( 'author' );
		$id     = $this->upload( $author );

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( 'name="replace_type" value="replace"', $output );
		$this->assertStringNotContainsString( 'remove_after_progress', $output );
	}

	/*
	 * Upsell, in a fresh process each (include_once).
	 */

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_screen_shows_the_upsell_section_to_users_who_can_install_plugins() {
		$this->assertTrue( current_user_can( 'install_plugins' ), 'Precondition.' );
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( "<section class='upsell-wrapper'>", $output );
		$this->assertStringContainsString( "<input type=\"hidden\" id='upsell-nonce'", $output );
		$section = strpos( $output, "<section class='upsell-wrapper'>" );
		$this->assertGreaterThan( strpos( $output, '<input id="submit"' ), $section );
		$this->assertLessThan( strrpos( $output, '</form>' ), $section, 'The upsell sits inside the form.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_screen_hides_the_upsell_section_when_the_upsell_filter_is_false() {
		$this->add_hook( 'emr/upsell', '__return_false' );
		$id = $this->upload();

		list( , $output ) = $this->load( $id );

		$this->assertStringContainsString( '<div class="wrap emr_upload_form">', $output );
		$this->assertStringNotContainsString( 'upsell-wrapper', $output );
		$this->assertStringNotContainsString( 'upsell-nonce', $output );
	}
}
