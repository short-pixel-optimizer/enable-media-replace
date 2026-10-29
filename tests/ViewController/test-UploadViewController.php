<?php
/**
 * Tests for EnableMediaReplace\ViewController\UploadViewController
 * (classes/ViewController/UploadViewController.php).
 *
 * The controller is reached through EMR_TestUploadViewController (see
 * ViewControllerTestDoubles.php): viewError() and viewSuccess() run for
 * real up to the loadView('error' / 'success') call, which throws
 * EMR_ViewExit instead of rendering and exiting. getPost(), getUpload(),
 * setView() and updateSettings() have public wrappers, and getUpload() can
 * be replaced by a fixed `[tmp file, name]` pair.
 *
 * load() can go as far as getUpload() in-process: is_uploaded_file() is
 * false for every file a test can create, so the furthest point is
 * ERROR_UPLOAD_FAILED. The successful run (ReplaceController::run(),
 * updateSettings(), viewSuccess()) is covered by the integration phase.
 *
 * Snapshot/restore: the current user, $_GET / $_POST / $_REQUEST / $_FILES,
 * the `enable_media_replace` option, ViewController::$viewsLoaded and
 * UploadViewController::$instance (reflection / public static), every
 * filter a test adds, the attachments and temp files created.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ViewControllerTestDoubles.php';

use EnableMediaReplace\ViewController;
use EnableMediaReplace\ViewController\UploadViewController;
use EnableMediaReplace\Controller\ReplaceController;

class UploadViewControllerTest extends WP_UnitTestCase {

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
		$this->saved_request = array(
			'_GET'     => $_GET,
			'_POST'    => $_POST,
			'_REQUEST' => $_REQUEST,
			'_FILES'   => $_FILES,
		);
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
		$this->saved_option = get_option( self::OPTION, null );
		delete_option( self::OPTION );

		$ref = new ReflectionProperty( ViewController::class, 'viewsLoaded' );
		$ref->setAccessible( true );
		$this->saved_views_loaded = $ref->getValue();
		$ref->setValue( null, array() );

		$this->saved_instance           = UploadViewController::$instance;
		UploadViewController::$instance = null;

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
		$_POST    = $this->saved_request['_POST'];
		$_REQUEST = $this->saved_request['_REQUEST'];
		$_FILES   = $this->saved_request['_FILES'];

		$ref = new ReflectionProperty( ViewController::class, 'viewsLoaded' );
		$ref->setAccessible( true );
		$ref->setValue( null, $this->saved_views_loaded );
		UploadViewController::$instance = $this->saved_instance;

		wp_dequeue_script( 'emr_success' );
		wp_set_current_user( $this->saved_user );
		parent::tear_down();
	}

	/*
	 * Helpers.
	 */

	private function add_hook( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->hooks[] = array( $hook, $callback, $priority );
	}

	private function upload( $author = null ) {
		$copy = get_temp_dir() . 'emr-uvc-' . wp_generate_password( 8, false, false ) . '.jpg';
		copy( dirname( __DIR__ ) . '/fixtures/test-image.jpg', $copy );
		$this->files[] = $copy;
		$id            = self::factory()->attachment->create_upload_object( $copy );
		if ( null !== $author ) {
			wp_update_post( array( 'ID' => $id, 'post_author' => $author ) );
		}
		$this->attachments[] = $id;
		return $id;
	}

	private function tmp_file() {
		$tmp = wp_tempnam( 'emr-uvc' );
		copy( dirname( __DIR__ ) . '/fixtures/test-image.jpg', $tmp );
		$this->files[] = $tmp;
		return $tmp;
	}

	private function user( $role ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * A controller whose getUpload() returns a fixed temp file.
	 */
	private function controller_with_upload() {
		$controller             = new EMR_TestUploadViewController();
		$controller->fakeUpload = array( '/tmp/emr-fake-upload', 'new-name.jpg' );
		return $controller;
	}

	/**
	 * Runs $callback, which must end in viewError() / viewSuccess(), and
	 * returns the EMR_ViewExit.
	 */
	private function expect_exit( $callback ) {
		try {
			call_user_func( $callback );
		} catch ( EMR_ViewExit $exit ) {
			return $exit;
		}
		$this->fail( 'The controller returned instead of ending at a view.' );
	}

	private function load_exit( EMR_TestUploadViewController $controller ) {
		return $this->expect_exit( array( $controller, 'load' ) );
	}

	/** Valid form POST for the current user. */
	private function valid_post( $id ) {
		$_POST = array(
			'emr_nonce' => wp_create_nonce( 'media_replace_upload' ),
			'ID'        => (string) $id,
		);
	}

	/*
	 * Singleton.
	 */

	public function test_get_instance_returns_one_upload_view_controller() {
		$first = UploadViewController::getInstance();

		$this->assertInstanceOf( UploadViewController::class, $first );
		$this->assertInstanceOf( ViewController::class, $first );
		$this->assertSame( $first, UploadViewController::getInstance() );
	}

	/*
	 * load(): request without POST data.
	 */

	public function test_load_without_post_and_with_emr_success_sets_the_view_and_shows_success() {
		$id                        = $this->upload();
		$_GET['emr_success']       = '1';
		$_REQUEST['attachment_id'] = (string) $id;
		$controller                = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( 'success', $exit->template );
		$this->assertTrue( $controller->successCalled );
		$this->assertSame( 0, $controller->errorCalls );
		$this->assertSame( $id, $controller->getView()->post_id );
		$this->assertSame( emr()->uiHelper()->getSuccesRedirect( $id ), $controller->getView()->postUrl );
		$this->assertStringContainsString( 'action=media_replace', $controller->getView()->emrUrl );
		$this->assertStringContainsString( 'attachment_id=' . $id, $controller->getView()->emrUrl );
	}

	public function test_load_without_post_and_without_emr_success_falls_through_to_the_nonce_error() {
		$id                        = $this->upload();
		$_REQUEST['attachment_id'] = (string) $id;
		$controller                = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( ViewController::ERROR_NONCE, $exit->getCode() );
		$this->assertSame( ViewController::ERROR_NONCE, $controller->lastError );
		$this->assertFalse( $controller->successCalled );
		$this->assertSame( $id, $controller->getView()->post_id, 'The view was set before the nonce check.' );
		$this->assertSame( 'Fail to validate form nonce. Please try again', $controller->getView()->errorMessage );
	}

	public function test_load_without_post_and_without_attachment_id_sets_a_null_post_id() {
		$controller = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( ViewController::ERROR_NONCE, $exit->getCode() );
		$this->assertNull( $controller->getView()->post_id );
	}

	public function test_load_with_non_numeric_attachment_id_sets_post_id_zero() {
		$_REQUEST['attachment_id'] = 'abc';
		$controller                = new EMR_TestUploadViewController();

		$this->load_exit( $controller );

		$this->assertSame( 0, $controller->getView()->post_id );
	}

	/*
	 * load(): the checks in order.
	 */

	public function test_load_with_wrong_nonce_shows_the_nonce_error() {
		$id    = $this->upload();
		$_POST = array(
			'emr_nonce' => wp_create_nonce( 'media_remove_background' ),
			'ID'        => (string) $id,
		);
		$controller = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( ViewController::ERROR_NONCE, $exit->getCode() );
		$this->assertSame( 1, $controller->errorCalls );
		$this->assertSame( 0, $controller->uploadCalls );
	}

	public function test_load_with_post_but_no_nonce_shows_the_nonce_error() {
		$_POST      = array( 'ID' => (string) $this->upload() );
		$controller = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( ViewController::ERROR_NONCE, $exit->getCode() );
		$this->assertObjectNotHasProperty( 'post_id', $controller->getView(), 'A POST request does not set the view first.' );
	}

	public function test_load_with_nonce_of_another_user_shows_the_nonce_error() {
		$id    = $this->upload();
		$nonce = wp_create_nonce( 'media_replace_upload' );
		$this->user( 'administrator' );
		$_POST = array( 'emr_nonce' => $nonce, 'ID' => (string) $id );

		$exit = $this->load_exit( new EMR_TestUploadViewController() );

		$this->assertSame( ViewController::ERROR_NONCE, $exit->getCode() );
	}

	public function test_load_without_upload_files_shows_the_upload_permission_error() {
		$id = $this->upload();
		$this->user( 'subscriber' );
		$this->assertFalse( current_user_can( 'upload_files' ), 'Precondition.' );
		$this->valid_post( $id );
		$controller = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( ViewController::ERROR_UPLOAD_PERMISSION, $exit->getCode() );
		$this->assertSame( 0, $controller->uploadCalls );
	}

	public function test_load_without_id_shows_the_form_error() {
		$_POST      = array( 'emr_nonce' => wp_create_nonce( 'media_replace_upload' ) );
		$controller = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( ViewController::ERROR_FORM, $exit->getCode() );
		$this->assertSame( 'The form submitted is missing various fields', $controller->getView()->errorMessage );
	}

	public function test_load_with_non_numeric_id_shows_the_image_permission_error() {
		$this->valid_post( 'not-a-number' );
		$controller = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $exit->getCode() );
	}

	public function test_load_for_a_non_attachment_post_shows_the_image_permission_error() {
		$this->valid_post( self::factory()->post->create() );

		$exit = $this->load_exit( new EMR_TestUploadViewController() );

		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $exit->getCode() );
	}

	public function test_load_for_an_attachment_the_user_cannot_edit_shows_the_image_permission_error() {
		$id = $this->upload( $this->admin_id );
		$this->user( 'author' );
		$this->assertTrue( current_user_can( 'upload_files' ), 'Precondition: authors can upload.' );
		$this->valid_post( $id );
		$controller = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( ViewController::ERROR_IMAGE_PERMISSION, $exit->getCode() );
		$this->assertSame( 0, $controller->uploadCalls );
	}

	public function test_load_with_a_valid_form_reaches_get_upload_and_fails_without_an_uploaded_file() {
		$id = $this->upload();
		$this->valid_post( $id );
		$_FILES['userfile'] = array(
			'name'     => 'replacement.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $this->tmp_file(),
			'error'    => 0,
			'size'     => 100,
		);
		$controller = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( ViewController::ERROR_UPLOAD_FAILED, $exit->getCode() );
		$this->assertSame( 1, $controller->uploadCalls );
		$this->assertSame( 1, $controller->errorCalls );
		$this->assertSame( 'The upload from your browser seem to have failed', $controller->getView()->errorMessage );
		$this->assertNull( get_option( self::OPTION, null ), 'No settings saved on failure.' );
	}

	public function test_load_author_on_own_attachment_passes_the_permission_check() {
		$author = $this->user( 'author' );
		$id     = $this->upload( $author );
		$this->valid_post( $id );
		$_FILES['userfile'] = array( 'name' => 'r.jpg', 'type' => 'image/jpeg', 'tmp_name' => $this->tmp_file(), 'error' => 0, 'size' => 1 );
		$controller         = new EMR_TestUploadViewController();

		$exit = $this->load_exit( $controller );

		$this->assertSame( ViewController::ERROR_UPLOAD_FAILED, $exit->getCode() );
		$this->assertSame( 1, $controller->uploadCalls );
	}

	/*
	 * getPost().
	 */

	public function test_get_post_returns_the_documented_array() {
		$_POST = array(
			'ID'                => '42',
			'replace_type'      => 'replace_and_search',
			'timestamp_replace' => '1',
		);
		$controller = $this->controller_with_upload();

		$params = $controller->callGetPost();

		$this->assertSame(
			array( 'post_id', 'replace_type', 'timestamp_replace', 'new_date', 'new_location', 'location_dir', 'is_custom_date', 'remove_background', 'uploadFile', 'new_filename' ),
			array_keys( $params )
		);
		$this->assertSame( 42, $params['post_id'] );
		$this->assertSame( 'replace_and_search', $params['replace_type'] );
		$this->assertSame( 1, $params['timestamp_replace'] );
		$this->assertFalse( $params['new_location'] );
		$this->assertNull( $params['location_dir'] );
		$this->assertFalse( $params['is_custom_date'] );
		$this->assertFalse( $params['remove_background'] );
		$this->assertSame( '/tmp/emr-fake-upload', $params['uploadFile'] );
		$this->assertSame( 'new-name.jpg', $params['new_filename'] );
		$this->assertSame( 1, $controller->uploadCalls );
	}

	public function test_get_post_casts_the_id_with_intval() {
		$_POST = array( 'ID' => '57abc' );

		$this->assertSame( 57, $this->controller_with_upload()->callGetPost()['post_id'] );
	}

	public function test_get_post_replace_type_is_false_when_absent() {
		$_POST = array( 'ID' => '3' );

		$this->assertFalse( $this->controller_with_upload()->callGetPost()['replace_type'] );
	}

	public function test_get_post_replace_type_is_sanitized() {
		$_POST = array( 'ID' => '3', 'replace_type' => " <b>replace</b>\n" );

		$this->assertSame( 'replace', $this->controller_with_upload()->callGetPost()['replace_type'] );
	}

	public function test_get_post_forces_replace_for_author_restricted_users() {
		$this->user( 'author' );
		$this->assertTrue( emr()->uiHelper()->is_author_restricted(), 'Precondition.' );

		$_POST = array( 'ID' => '3', 'replace_type' => 'replace_and_search' );
		$this->assertSame( 'replace', $this->controller_with_upload()->callGetPost()['replace_type'] );

		$_POST = array( 'ID' => '3' );
		$this->assertSame( 'replace', $this->controller_with_upload()->callGetPost()['replace_type'], 'Absent becomes replace too.' );
	}

	public function test_get_post_default_time_mode_is_update_modified() {
		$_POST = array( 'ID' => '3' );

		$params = $this->controller_with_upload()->callGetPost();

		$this->assertSame( ReplaceController::TIME_UPDATEMODIFIED, $params['timestamp_replace'] );
		$this->assertSame( 2, $params['timestamp_replace'] );
	}

	public function test_get_post_time_modes_one_and_two_use_the_current_local_time() {
		foreach ( array( 1, 2 ) as $mode ) {
			$_POST  = array( 'ID' => '3', 'timestamp_replace' => (string) $mode );
			$before = current_time( 'timestamp' );

			$params = $this->controller_with_upload()->callGetPost();

			$this->assertSame( $mode, $params['timestamp_replace'] );
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params['new_date'] );
			$stamp = strtotime( $params['new_date'] );
			$this->assertGreaterThanOrEqual( $before, $stamp );
			$this->assertLessThanOrEqual( current_time( 'timestamp' ), $stamp );
			$this->assertFalse( $params['is_custom_date'] );
		}
	}

	public function test_get_post_custom_date_is_built_from_the_padded_fields() {
		$_POST = array(
			'ID'                    => '3',
			'timestamp_replace'     => '3',
			'custom_date_formatted' => '2021-03-05',
			'custom_hour'           => '7',
			'custom_minute'         => '5',
		);

		$params = $this->controller_with_upload()->callGetPost();

		$this->assertSame( ReplaceController::TIME_CUSTOM, $params['timestamp_replace'] );
		$this->assertSame( '2021-03-05 07:05:00', $params['new_date'] );
		$this->assertTrue( $params['is_custom_date'] );
	}

	public function test_get_post_custom_date_accepts_two_digit_fields() {
		$_POST = array(
			'ID'                    => '3',
			'timestamp_replace'     => '3',
			'custom_date_formatted' => '1999-12-31',
			'custom_hour'           => '23',
			'custom_minute'         => '59',
		);

		$this->assertSame( '1999-12-31 23:59:00', $this->controller_with_upload()->callGetPost()['new_date'] );
	}

	/**
	 * DateTime::createFromFormat() accepts an out-of-range hour or minute and
	 * rolls the date over instead of failing, so only text it cannot parse
	 * at all reaches ERROR_TIME.
	 */
	public function test_get_post_custom_date_rolls_over_out_of_range_minutes() {
		$_POST = array(
			'ID'                    => '3',
			'timestamp_replace'     => '3',
			'custom_date_formatted' => '2021-03-05',
			'custom_hour'           => '23',
			'custom_minute'         => '75',
		);

		$this->assertSame( '2021-03-06 00:15:00', $this->controller_with_upload()->callGetPost()['new_date'] );
	}

	public function test_get_post_invalid_custom_date_shows_the_time_error() {
		$_POST = array(
			'ID'                    => '3',
			'timestamp_replace'     => '3',
			'custom_date_formatted' => 'not-a-date',
			'custom_hour'           => '10',
			'custom_minute'         => '00',
		);
		$controller = $this->controller_with_upload();

		$exit = $this->expect_exit( array( $controller, 'callGetPost' ) );

		$this->assertSame( ViewController::ERROR_TIME, $exit->getCode() );
		$this->assertSame( 'The custom time format submitted is invalid', $controller->getView()->errorMessage );
		$this->assertSame( 0, $controller->uploadCalls, 'The time error stops before getUpload().' );
	}

	/**
	 * In the TIME_CUSTOM branch getPost() reads `custom_date_formatted`,
	 * `custom_hour` and `custom_minute` without isset() (the docblock says
	 * so), so a custom-date submission missing one of them raises
	 * "Undefined index" (PHP 7.4 notice) / "Undefined array key" (PHP 8
	 * warning). A missing field should be treated as an invalid date and
	 * end in ERROR_TIME.
	 *
	 * How to recognise the fix: no notice / warning is raised; the test
	 * then sees EMR_ViewExit with ERROR_TIME instead and fails.
	 */
	public function test_get_post_custom_date_without_its_fields_raises_undefined_index_pinned_for_deferred_fix() {
		$_POST      = array( 'ID' => '3', 'timestamp_replace' => '3' );
		$controller = $this->controller_with_upload();

		$error = null;
		try {
			$controller->callGetPost();
		} catch ( \PHPUnit\Framework\Error\Error $e ) {
			$error = $e;
		}

		$this->assertInstanceOf( PHP_VERSION_ID >= 80000 ? \PHPUnit\Framework\Error\Warning::class : \PHPUnit\Framework\Error\Notice::class, $error );
		$this->assertMatchesRegularExpression( '/Undefined (index|array key).*custom_date_formatted/', $error->getMessage() );
		$this->assertSame( 0, $controller->errorCalls );
	}

	/**
	 * getPost() has no `default:` in its date-mode switch: a
	 * `timestamp_replace` other than 1, 2 or 3 leaves `$datetime` undefined,
	 * and building the return array raises "Undefined variable"
	 * (PHP 7.4 notice / PHP 8 warning). The intended behaviour is to fall back
	 * to TIME_UPDATEMODIFIED with current_time('mysql').
	 *
	 * How to recognise the fix: no notice / warning is raised, getPost()
	 * returns a `new_date`, and the assertInstanceOf on the error fails.
	 */
	public function test_get_post_unknown_time_mode_leaves_datetime_undefined_pinned_for_deferred_fix() {
		foreach ( array( '7', 'abc' ) as $mode ) {
			$_POST      = array( 'ID' => '3', 'timestamp_replace' => $mode );
			$controller = $this->controller_with_upload();

			$error  = null;
			$result = 'not returned';
			try {
				$result = $controller->callGetPost();
			} catch ( \PHPUnit\Framework\Error\Error $e ) {
				$error = $e;
			}

			$this->assertInstanceOf( PHP_VERSION_ID >= 80000 ? \PHPUnit\Framework\Error\Warning::class : \PHPUnit\Framework\Error\Notice::class, $error, $mode );
			$this->assertMatchesRegularExpression( '/Undefined variable:? \$?datetime/', $error->getMessage() );
			$this->assertSame( 'not returned', $result );
			$this->assertSame( 1, $controller->uploadCalls, 'getUpload() runs before the return array is built.' );
		}
	}

	public function test_get_post_new_location_is_true_only_when_posted() {
		$_POST = array( 'ID' => '3', 'new_location' => '1' );
		$this->assertTrue( $this->controller_with_upload()->callGetPost()['new_location'] );

		$_POST = array( 'ID' => '3' );
		$this->assertFalse( $this->controller_with_upload()->callGetPost()['new_location'] );
	}

	public function test_get_post_file_is_movable_filter_can_turn_new_location_off() {
		$seen = array();
		$this->add_hook(
			'emr/replace/file_is_movable',
			function ( $movable, $id ) use ( &$seen ) {
				$seen[] = array( $movable, $id );
				return false;
			},
			10,
			2
		);
		$_POST = array( 'ID' => '64', 'new_location' => '1' );

		$params = $this->controller_with_upload()->callGetPost();

		$this->assertFalse( $params['new_location'] );
		$this->assertSame( array( array( true, 64 ) ), $seen );
	}

	public function test_get_post_location_dir_is_sanitized() {
		$_POST = array( 'ID' => '3', 'location_dir' => "<b>2021</b>/03 <script>x</script>\n" );

		$this->assertSame( '2021/03', $this->controller_with_upload()->callGetPost()['location_dir'] );
	}

	public function test_get_post_remove_after_progress_sets_remove_background() {
		$_POST = array( 'ID' => '3', 'remove_after_progress' => '3' );

		$this->assertTrue( $this->controller_with_upload()->callGetPost()['remove_background'] );
	}

	/*
	 * getUpload().
	 */

	public function test_get_upload_with_a_file_that_was_not_uploaded_shows_upload_failed() {
		$_FILES['userfile'] = array(
			'name'     => 'replacement.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $this->tmp_file(),
			'error'    => 0,
			'size'     => 100,
		);
		$controller = new EMR_TestUploadViewController();

		$exit = $this->expect_exit( array( $controller, 'callGetUpload' ) );

		$this->assertSame( ViewController::ERROR_UPLOAD_FAILED, $exit->getCode() );
		$this->assertSame( array(), $controller->lastErrorData );
	}

	public function test_get_upload_with_an_empty_file_field_shows_upload_failed() {
		$_FILES['userfile'] = array( 'name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0 );
		$controller         = new EMR_TestUploadViewController();

		$exit = $this->expect_exit( array( $controller, 'callGetUpload' ) );

		$this->assertSame( ViewController::ERROR_UPLOAD_FAILED, $exit->getCode() );
	}

	/**
	 * getUpload() reads `$_FILES['userfile']['tmp_name']` without isset(),
	 * so a POST without the `userfile` field raises "Undefined index"
	 * (PHP 7.4 notice) / "Undefined array key" (PHP 8 warning) before the
	 * documented ERROR_UPLOAD_FAILED. A missing field should end in
	 * ERROR_UPLOAD_FAILED.
	 *
	 * How to recognise the fix: no notice / warning is raised; EMR_ViewExit
	 * with ERROR_UPLOAD_FAILED escapes the catch block and fails the test.
	 */
	public function test_get_upload_without_userfile_raises_undefined_index_pinned_for_deferred_fix() {
		$this->assertArrayNotHasKey( 'userfile', $_FILES, 'Precondition.' );
		$controller = new EMR_TestUploadViewController();

		$error = null;
		try {
			$controller->callGetUpload();
		} catch ( \PHPUnit\Framework\Error\Error $e ) {
			$error = $e;
		}

		$this->assertInstanceOf( PHP_VERSION_ID >= 80000 ? \PHPUnit\Framework\Error\Warning::class : \PHPUnit\Framework\Error\Notice::class, $error );
		$this->assertMatchesRegularExpression( '/Undefined (index|array key).*userfile/', $error->getMessage() );
		$this->assertSame( 0, $controller->errorCalls );
	}

	/**
	 * getUpload() checks `isset($uploadFilearray['error'])` (lower-case "a",
	 * an undefined variable, so always false) and then
	 * `$uploadFileArray['userfile']['error']` (wrong index: the array IS the
	 * userfile entry). The check never fires, so an error set by a
	 * `wp_handle_upload_prefilter` callback is ignored. The branch is only
	 * reachable after is_uploaded_file() succeeds, which no in-process test
	 * can arrange, so this pin reads the source: the behavioural check
	 * belongs to the integration phase. Intended:
	 * `if (isset($uploadFileArray['error']) && $uploadFileArray['error'])`.
	 *
	 * How to recognise the fix: the misspelled variable and the
	 * `['userfile']['error']` access disappear from getUpload() and the
	 * counts below drop to zero.
	 */
	public function test_get_upload_error_check_reads_the_wrong_variable_pinned_for_deferred_fix() {
		$method = new ReflectionMethod( UploadViewController::class, 'getUpload' );
		$lines  = file( $method->getFileName() );
		$source = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
		$tokens = token_get_all( '<?php ' . $source );

		$variables = array();
		$compact   = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				if ( T_VARIABLE === $token[0] ) {
					$variables[] = $token[1];
				}
				if ( T_WHITESPACE !== $token[0] ) {
					$compact .= $token[1];
				}
			} else {
				$compact .= $token;
			}
		}

		$this->assertContains( '$uploadFileArray', $variables, 'Precondition: the real variable is used.' );
		$this->assertSame( 1, count( array_keys( $variables, '$uploadFilearray', true ) ), 'The misspelled variable is read once.' );
		$this->assertSame( 1, substr_count( $compact, "\$uploadFileArray['userfile']['error']" ) );
		$this->assertSame( 1, substr_count( $compact, "isset(\$uploadFilearray['error'])&&\$uploadFileArray['userfile']['error']>0" ) );
	}

	/*
	 * setView().
	 */

	public function test_set_view_sets_post_id_and_both_redirects() {
		$controller = new EMR_TestUploadViewController();

		$controller->callSetView( 81 );

		$view = $controller->getView();
		$this->assertSame( 81, $view->post_id );
		$this->assertSame( admin_url( 'post.php?action=edit&post=81&emr_replaced=1' ), $view->postUrl );
		$this->assertSame( emr()->uiHelper()->getSuccesRedirect( 81 ), $view->postUrl );
		$parts = array();
		wp_parse_str( (string) wp_parse_url( $view->emrUrl, PHP_URL_QUERY ), $parts );
		$this->assertStringStartsWith( admin_url( 'upload.php' ), $view->emrUrl );
		$this->assertSame( 'enable-media-replace/enable-media-replace.php', $parts['page'] );
		$this->assertSame( 'media_replace', $parts['action'] );
		$this->assertSame( '81', $parts['attachment_id'] );
		$this->assertSame( 1, wp_verify_nonce( $parts['_wpnonce'], 'media_replace' ) );
	}

	public function test_set_view_with_remove_background_points_post_url_at_the_background_screen() {
		$controller = new EMR_TestUploadViewController();

		$controller->callSetView( 82, array( 'remove_background' => true ) );

		$this->assertSame( emr()->uiHelper()->getBackgroundRemoveRedirect( 82 ), $controller->getView()->postUrl );
		$this->assertStringContainsString( 'action=emr_prepare_remove', $controller->getView()->postUrl );
		$this->assertStringContainsString( 'action=media_replace', $controller->getView()->emrUrl );
	}

	public function test_set_view_requires_a_strict_true_remove_background() {
		foreach ( array( false, 1, '1', 'yes' ) as $value ) {
			$controller = new EMR_TestUploadViewController();

			$controller->callSetView( 83, array( 'remove_background' => $value ) );

			$this->assertSame( emr()->uiHelper()->getSuccesRedirect( 83 ), $controller->getView()->postUrl, var_export( $value, true ) );
		}
	}

	public function test_set_view_post_url_passes_the_return_url_filter() {
		$this->add_hook(
			'emr_returnurl',
			function ( $url ) {
				return 'https://example.org/after';
			}
		);
		$controller = new EMR_TestUploadViewController();

		$controller->callSetView( 84 );

		$this->assertSame( 'https://example.org/after', $controller->getView()->postUrl );
	}

	/*
	 * updateSettings().
	 */

	private function settings_params( array $overrides = array() ) {
		return array_merge(
			array(
				'post_id'           => 5,
				'replace_type'      => 'replace_and_search',
				'timestamp_replace' => 3,
				'new_date'          => '2020-01-02 03:04:00',
				'new_location'      => true,
				'location_dir'      => '2020/01',
				'is_custom_date'    => true,
				'remove_background' => false,
				'uploadFile'        => '/tmp/x',
				'new_filename'      => 'x.jpg',
			),
			$overrides
		);
	}

	public function test_update_settings_writes_the_form_choices() {
		( new EMR_TestUploadViewController() )->callUpdateSettings( $this->settings_params() );

		$this->assertSame(
			array(
				'replace_type'      => 'replace_and_search',
				'timestamp_replace' => 3,
				'new_location'      => true,
				'new_location_dir'  => '2020/01',
				'custom_date'       => '2020-01-02 03:04:00',
			),
			get_option( self::OPTION )
		);
	}

	public function test_update_settings_writes_custom_date_only_for_a_custom_date() {
		update_option( self::OPTION, array( 'custom_date' => '2011-11-11 11:11:00' ), false );

		( new EMR_TestUploadViewController() )->callUpdateSettings(
			$this->settings_params( array( 'is_custom_date' => false, 'timestamp_replace' => 2, 'new_date' => '2030-01-01 00:00:00' ) )
		);

		$settings = get_option( self::OPTION );
		$this->assertSame( '2011-11-11 11:11:00', $settings['custom_date'] );
		$this->assertSame( 2, $settings['timestamp_replace'] );
	}

	public function test_update_settings_without_custom_date_has_no_custom_date_key() {
		( new EMR_TestUploadViewController() )->callUpdateSettings( $this->settings_params( array( 'is_custom_date' => false ) ) );

		$this->assertArrayNotHasKey( 'custom_date', get_option( self::OPTION ) );
	}

	public function test_update_settings_keeps_the_other_keys() {
		update_option( self::OPTION, array( 'bg_type' => 'solid', 'bg_color' => '#000000', 'replace_type' => 'replace' ), false );

		( new EMR_TestUploadViewController() )->callUpdateSettings( $this->settings_params() );

		$settings = get_option( self::OPTION );
		$this->assertSame( 'solid', $settings['bg_type'] );
		$this->assertSame( '#000000', $settings['bg_color'] );
		$this->assertSame( 'replace_and_search', $settings['replace_type'] );
	}

	public function test_update_settings_stores_the_option_without_autoload() {
		global $wpdb;

		( new EMR_TestUploadViewController() )->callUpdateSettings( $this->settings_params() );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( self::OPTION, wp_load_alloptions() );
	}
}
