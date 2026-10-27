<?php
/**
 * Shared base class of the EMR Main suite (tests/test-*.php).
 *
 * Not a test file (no `test-` prefix, so PHPUnit does not collect it); each
 * test file requires it.
 *
 * EnableMediaReplacePlugin is a singleton created when the plugin loads, so
 * the tests work on the real instance (emr()) and snapshot everything its
 * methods touch:
 * - its private `features`, `general_cap` and `user_cap` properties;
 * - the request superglobals and the admin globals that menu(), route(),
 *   setScreen() and the meta boxes read or write ($plugin_page,
 *   $current_screen, $hook_suffix, $post, $submenu, $wp_meta_boxes, ...);
 * - the script and style registries (wp_scripts() / wp_styles() start from
 *   a fresh instance in every test and are dropped afterwards);
 * - the static state of the notices module, RemoteNoticeController and the
 *   three view controller singletons;
 * - the text direction of $wp_locale;
 * - the current user.
 * Hooks registered by runtime() / plugin_actions() / setScreen() are
 * removed explicitly in tear_down() (on top of the hook backup that
 * WP_UnitTestCase restores), and so is every filter added through
 * add_test_filter().
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\EnableMediaReplacePlugin;
use EnableMediaReplace\Notices\NoticeController;
use EnableMediaReplace\Notices\NoticeModel;
use EnableMediaReplace\Controller\RemoteNoticeController;
use EnableMediaReplace\ViewController\ReplaceViewController;
use EnableMediaReplace\ViewController\UploadViewController;
use EnableMediaReplace\ViewController\RemoveBackgroundViewController;

if ( ! function_exists( 'add_meta_box' ) ) {
	require_once ABSPATH . 'wp-admin/includes/template.php';
}
if ( ! class_exists( 'WP_Screen', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
}
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

abstract class EMR_MainTestCase extends WP_UnitTestCase {

	/** Slug of the hidden EMR admin page. */
	const PAGE_SLUG = 'enable-media-replace/enable-media-replace.php';

	/** Globals snapshotted and restored around every test. */
	const GLOBALS_TO_RESTORE = array(
		'plugin_page',
		'current_screen',
		'hook_suffix',
		'post',
		'submenu',
		'_wp_submenu_nopriv',
		'_registered_pages',
		'_parent_pages',
		'admin_page_hooks',
		'wp_meta_boxes',
	);

	/** @var array Snapshot of the globals above (missing ones are not stored). */
	private $saved_globals = array();

	/** @var array Snapshot of the request superglobals. */
	private $saved_request = array();

	/** @var array Snapshot of the plugin's private properties. */
	private $saved_plugin_props = array();

	/** @var array Snapshot of the notices statics. */
	private $saved_notice_statics = array();

	/** @var array Snapshot of NoticeModel::$icons. */
	private $saved_icons = array();

	/** @var array Snapshot of the view controller singletons. */
	private $saved_view_instances = array();

	/** @var mixed Snapshot of the RemoteNoticeController singleton. */
	private $saved_remote_instance;

	/** @var string|null Snapshot of $wp_locale->text_direction. */
	private $saved_text_direction;

	/** @var array Filters added by the test, as array( hook, callback, priority ). */
	private $added_filters = array();

	/** @var int[] Attachments deleted with their files in tear_down(). */
	protected $attachments = array();

	public function set_up() {
		parent::set_up();

		foreach ( self::GLOBALS_TO_RESTORE as $name ) {
			if ( array_key_exists( $name, $GLOBALS ) ) {
				$this->saved_globals[ $name ] = $GLOBALS[ $name ];
			}
		}
		$this->saved_request = array(
			'get'     => $_GET,
			'post'    => $_POST,
			'request' => $_REQUEST,
		);
		foreach ( array( 'features', 'general_cap', 'user_cap' ) as $name ) {
			$this->saved_plugin_props[ $name ] = $this->plugin_prop( $name );
		}
		foreach ( array( 'instance', 'notices', 'newNotices', 'cssHookLoaded' ) as $name ) {
			$this->saved_notice_statics[ $name ] = $this->notice_static( $name );
		}
		$this->saved_icons            = NoticeModel::$icons;
		$this->saved_view_instances   = array(
			ReplaceViewController::class          => ReplaceViewController::$instance,
			UploadViewController::class           => UploadViewController::$instance,
			RemoveBackgroundViewController::class => RemoveBackgroundViewController::$instance,
		);
		$this->saved_remote_instance  = $this->remote_instance();
		$this->saved_text_direction   = isset( $GLOBALS['wp_locale'] ) ? $GLOBALS['wp_locale']->text_direction : null;

		$this->reset_notices();
		$this->set_remote_instance( null );
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		$GLOBALS['current_screen'] = null;
		unset( $GLOBALS['post'] );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		$this->remove_plugin_hooks();

		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachments = array();

		foreach ( self::GLOBALS_TO_RESTORE as $name ) {
			if ( array_key_exists( $name, $this->saved_globals ) ) {
				$GLOBALS[ $name ] = $this->saved_globals[ $name ];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		$this->saved_globals = array();
		$_GET     = $this->saved_request['get'];
		$_POST    = $this->saved_request['post'];
		$_REQUEST = $this->saved_request['request'];

		foreach ( $this->saved_plugin_props as $name => $value ) {
			$this->set_plugin_prop( $name, $value );
		}
		foreach ( $this->saved_notice_statics as $name => $value ) {
			$this->notice_static( $name, $value, true );
		}
		NoticeModel::$icons = $this->saved_icons;
		ReplaceViewController::$instance          = $this->saved_view_instances[ ReplaceViewController::class ];
		UploadViewController::$instance           = $this->saved_view_instances[ UploadViewController::class ];
		RemoveBackgroundViewController::$instance = $this->saved_view_instances[ RemoveBackgroundViewController::class ];
		$this->set_remote_instance( $this->saved_remote_instance );
		if ( null !== $this->saved_text_direction ) {
			$GLOBALS['wp_locale']->text_direction = $this->saved_text_direction;
		}

		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/* ------------------------------------------------------------------
	 * Plugin state
	 * ------------------------------------------------------------------ */

	/** The plugin singleton. */
	protected function plugin() {
		return emr();
	}

	/**
	 * Reads (or writes, with $set) a private property of the plugin singleton.
	 */
	protected function plugin_prop( $name ) {
		$prop = new ReflectionProperty( EnableMediaReplacePlugin::class, $name );
		$prop->setAccessible( true );
		return $prop->getValue( emr() );
	}

	protected function set_plugin_prop( $name, $value ) {
		$prop = new ReflectionProperty( EnableMediaReplacePlugin::class, $name );
		$prop->setAccessible( true );
		$prop->setValue( emr(), $value );
	}

	/** Calls a protected method of the plugin singleton. */
	protected function call_protected( $method, array $args = array() ) {
		$ref = new ReflectionMethod( EnableMediaReplacePlugin::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( emr(), $args );
	}

	/**
	 * Removes every hook runtime(), plugin_actions() and setScreen() can add
	 * for the plugin singleton. The postbox_classes closure cannot be removed
	 * by reference; WP_UnitTestCase's hook backup drops it.
	 */
	protected function remove_plugin_hooks() {
		$plugin = emr();
		remove_action( 'admin_menu', array( $plugin, 'menu' ) );
		remove_action( 'submenu_file', array( $plugin, 'hide_sub_menu' ) );
		remove_action( 'current_screen', array( $plugin, 'setScreen' ) );
		remove_action( 'admin_enqueue_scripts', array( $plugin, 'admin_scripts' ) );
		remove_filter( 'media_row_actions', array( $plugin, 'add_media_action' ), 10 );
		remove_action( 'attachment_submitbox_misc_actions', array( $plugin, 'admin_date_replaced_media_on_edit_media_screen' ), 91 );
		remove_action( 'add_meta_boxes_attachment', array( $plugin, 'add_meta_boxes' ), 10 );
		remove_filter( 'attachment_fields_to_edit', array( $plugin, 'attachment_editor' ), 10 );
		remove_filter( 'wp_get_attachment_image_src', array( $plugin, 'attempt_uncache_image' ), 10 );
		$notices = $this->notice_static( 'instance' );
		if ( is_object( $notices ) ) {
			remove_action( 'admin_notices', array( $notices, 'admin_notices' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Notices and remote notices
	 * ------------------------------------------------------------------ */

	protected function notice_static( $name, $value = null, $set = false ) {
		$ref = new ReflectionProperty( NoticeController::class, $name );
		$ref->setAccessible( true );
		if ( $set ) {
			$ref->setValue( null, $value );
			return null;
		}
		return $ref->getValue();
	}

	protected function reset_notices() {
		$this->notice_static( 'instance', null, true );
		$this->notice_static( 'notices', array(), true );
		$this->notice_static( 'newNotices', array(), true );
		$this->notice_static( 'cssHookLoaded', false, true );
	}

	protected function remote_instance() {
		$ref = new ReflectionProperty( RemoteNoticeController::class, 'instance' );
		$ref->setAccessible( true );
		return $ref->getValue();
	}

	protected function set_remote_instance( $value ) {
		$ref = new ReflectionProperty( RemoteNoticeController::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	/* ------------------------------------------------------------------
	 * Filters, users, fixtures
	 * ------------------------------------------------------------------ */

	/** add_filter() that tear_down() undoes. */
	protected function add_test_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->added_filters[] = array( $hook, $callback, $priority );
	}

	/** Creates a user with the role and logs them in; returns the ID. */
	protected function login_as( $role ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Creates an attachment row without a file on disk.
	 *
	 * @param string $file  Attached file path (relative to uploads).
	 * @param string $mime  Mime type.
	 * @param array  $extra Extra post fields.
	 * @return int Attachment ID.
	 */
	protected function make_attachment( $file = 'emr-main-photo.jpg', $mime = 'image/jpeg', array $extra = array() ) {
		return self::factory()->attachment->create_object(
			array_merge(
				array(
					'file'           => $file,
					'post_mime_type' => $mime,
					'post_parent'    => 0,
				),
				$extra
			)
		);
	}

	/** Uploads a copy of a fixture; the attachment and its files go in tear_down(). */
	protected function upload_fixture( $name = 'test-image.jpg' ) {
		$id                  = self::factory()->attachment->create_upload_object( __DIR__ . '/fixtures/' . $name );
		$this->attachments[] = $id;
		return $id;
	}

	/** Runs $callback and returns what it printed. */
	protected function capture( $callback ) {
		ob_start();
		try {
			call_user_func( $callback );
		} finally {
			$output = ob_get_clean();
		}
		return $output;
	}

	/**
	 * Decodes the object a wp_localize_script() call attached to a handle.
	 *
	 * @param string $handle Script handle.
	 * @param string $name   JavaScript object name.
	 * @return array
	 */
	protected function localized( $handle, $name ) {
		$data = wp_scripts()->get_data( $handle, 'data' );
		$this->assertIsString( $data, 'No localised data on ' . $handle );
		$this->assertSame( 1, preg_match( '/var ' . preg_quote( $name, '/' ) . ' = (\{.*\});/s', $data, $m ), 'No object ' . $name . ' in the data of ' . $handle );
		return json_decode( $m[1], true );
	}
}
