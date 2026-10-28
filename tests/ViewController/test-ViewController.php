<?php
/**
 * Tests for the abstract EnableMediaReplace\ViewController
 * (classes/ViewController.php) and the two views it renders itself,
 * views/error.php and views/success.php.
 *
 * The base class is reached through EMR_TestViewController (see
 * ViewControllerTestDoubles.php): load() is a no-op and loadView() throws
 * EMR_ViewExit for 'error' and 'success', so viewError() and viewSuccess()
 * run for real up to the line before their exit() and never reach it.
 * renderView() calls the real loadView() to include a template on purpose.
 *
 * Both views end with `require_once ABSPATH . 'wp-admin/admin-footer.php'`.
 * That file is required once per process, so the in-process tests assert
 * the view markup only, and two tests run in a separate process to assert
 * that the admin footer renders after the view.
 *
 * Snapshot/restore: the current user, $GLOBALS['hook_suffix'] and
 * $GLOBALS['current_screen'], the static ViewController::$viewsLoaded
 * (emptied by reflection in set_up() and restored in tear_down()), the
 * script queue entry 'emr_success', and every filter a test adds.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ViewControllerTestDoubles.php';

use EnableMediaReplace\ViewController;

class ViewControllerTest extends WP_UnitTestCase {

	/** @var int */
	private $saved_user;

	/** @var array */
	private $saved_views_loaded;

	/** @var array */
	private $saved_globals = array();

	/** @var array Hooks added by a test: array( hook, callback, priority ). */
	private $hooks = array();

	/** @var array Text domains recorded by the gettext spy, keyed by text. */
	private $domains = array();

	public function set_up() {
		parent::set_up();
		$this->saved_user = get_current_user_id();
		foreach ( array( 'hook_suffix', 'current_screen' ) as $global ) {
			$this->saved_globals[ $global ] = array_key_exists( $global, $GLOBALS ) ? array( $GLOBALS[ $global ] ) : null;
		}
		$this->saved_views_loaded = $this->views_loaded();
		$this->set_views_loaded( array() );
		$this->domains = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		foreach ( $this->hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$this->hooks = array();
		wp_dequeue_script( 'emr_success' );
		wp_deregister_script( 'emr_success' );
		$this->set_views_loaded( $this->saved_views_loaded );
		foreach ( $this->saved_globals as $global => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $global ] );
			} else {
				$GLOBALS[ $global ] = $value[0];
			}
		}
		wp_set_current_user( $this->saved_user );
		parent::tear_down();
	}

	/*
	 * Helpers.
	 */

	private function views_loaded() {
		$ref = new ReflectionProperty( ViewController::class, 'viewsLoaded' );
		$ref->setAccessible( true );
		return $ref->getValue();
	}

	private function set_views_loaded( array $value ) {
		$ref = new ReflectionProperty( ViewController::class, 'viewsLoaded' );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	private function add_hook( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->hooks[] = array( $hook, $callback, $priority );
	}

	/**
	 * Calls viewError() and returns the EMR_ViewExit it ends with.
	 */
	private function error_exit( EMR_TestViewController $controller, $code, $data = array() ) {
		try {
			$controller->callViewError( $code, $data );
		} catch ( EMR_ViewExit $exit ) {
			return $exit;
		}
		$this->fail( 'viewError() returned instead of ending at the error view.' );
	}

	/**
	 * Runs $callback with output buffering and returns the output, closing
	 * the buffer even when the callback throws.
	 */
	private function capture( $callback ) {
		ob_start();
		try {
			call_user_func( $callback );
		} finally {
			$output = ob_get_clean();
		}
		return $output;
	}

	/**
	 * Admin context the success and error views need for the admin footer.
	 */
	private function admin_context() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'upload' );
		$GLOBALS['hook_suffix'] = 'upload.php';
	}

	public function record_domain( $translation, $text, $domain ) {
		$this->domains[ $text ][] = $domain;
		return $translation;
	}

	/*
	 * Constants.
	 */

	public function test_error_constants_have_the_documented_values() {
		$expected = array(
			'ERROR_UPLOAD_PERMISSION'  => 1,
			'ERROR_IMAGE_PERMISSION'   => 2,
			'ERROR_FORM'               => 3,
			'ERROR_TIME'               => 4,
			'ERROR_UPDATE_FAILED'      => 5,
			'ERROR_SECURITY'           => 6,
			'ERROR_UPLOAD_FAILED'      => 7,
			'ERROR_NONCE'              => 8,
			'ERROR_KEY'                => 9,
			'ERROR_TARGET_EXISTS'      => 20,
			'ERROR_DESTINATION_FAIL'   => 21,
			'ERROR_COPY_FAILED'        => 22,
			'ERROR_UPDATE_POST'        => 23,
			'ERROR_DIRECTORY_SECURITY' => 24,
			'ERROR_DIRECTORY_NOTEXIST' => 25,
			'ERROR_DOWNLOAD_FAILED'    => 31,
		);
		foreach ( $expected as $name => $value ) {
			$this->assertSame( $value, constant( ViewController::class . '::' . $name ), $name );
		}
	}

	public function test_shared_error_codes_match_replace_controller() {
		$shared = array( 'ERROR_TARGET_EXISTS', 'ERROR_DESTINATION_FAIL', 'ERROR_COPY_FAILED', 'ERROR_UPDATE_POST', 'ERROR_DIRECTORY_SECURITY', 'ERROR_DIRECTORY_NOTEXIST' );
		foreach ( $shared as $name ) {
			$this->assertSame(
				constant( '\EnableMediaReplace\Controller\ReplaceController::' . $name ),
				constant( ViewController::class . '::' . $name ),
				$name
			);
		}
	}

	public function test_constructor_creates_an_empty_view_object() {
		$controller = new EMR_TestViewController();

		$this->assertInstanceOf( stdClass::class, $controller->getView() );
		$this->assertSame( array(), get_object_vars( $controller->getView() ) );
	}

	/*
	 * viewError(): the message table.
	 */

	public function error_message_provider() {
		return array(
			'upload permission'  => array( 1, "You don't have permission to upload images. Please refer to your administrator", null ),
			'image permission'   => array( 2, "You don't have permission to edit this image", null ),
			'form'               => array( 3, 'The form submitted is missing various fields', null ),
			'time'               => array( 4, 'The custom time format submitted is invalid', null ),
			'update failed'      => array( 5, 'Updating the WordPress attachment failed', null ),
			'security'           => array( 6, 'The file upload has been rejected for security reason. WordPress might not allow uploading this extension or filetype', null ),
			'upload failed'      => array( 7, 'The upload from your browser seem to have failed', null ),
			'nonce'              => array( 8, 'Fail to validate form nonce. Please try again', 'This can happen when the window is open for a long time and/or there has been a timeout.  You can go back to previous screen and try again. If this happens each time when replacing, contact us' ),
			'target exists'      => array( 20, 'The target file already exists in this directory. Please try another name / directory', 'This error is shown because you try to move the image to another folder, which already has this file' ),
			'destination fail'   => array( 21, 'Something went wrong while writing the file or directory', null ),
			'copy failed'        => array( 22, 'Copying replacement file to destination failed', null ),
			'update post'        => array( 23, 'Error updating WordPress post in the database', null ),
			'directory security' => array( 24, 'Specified directory is outside the upload directory. This is not allowed for security reasons', null ),
			'directory notexist' => array( 25, 'Specified new directory does not exist. Path must be a relative path from the upload directory and exist', null ),
			'download failed'    => array( 31, 'Replacement Image could not be downloaded or does not exist', null ),
			'unknown code'       => array( 99, 'An unknown error has occured', null ),
			'zero'               => array( 0, 'An unknown error has occured', null ),
			'null'               => array( null, 'An unknown error has occured', null ),
		);
	}

	/**
	 * @dataProvider error_message_provider
	 */
	public function test_view_error_sets_the_message_and_description_and_ends_at_the_error_view( $code, $message, $description ) {
		$controller = new EMR_TestViewController();

		$exit = $this->error_exit( $controller, $code );

		$this->assertSame( 'error', $exit->template );
		$this->assertSame( (int) $code, $exit->getCode() );
		$this->assertSame( array( 'error' ), $controller->loadedViews );
		$this->assertSame( $code, $controller->lastError );
		$this->assertSame( $message, $controller->getView()->errorMessage );
		if ( null === $description ) {
			$this->assertObjectNotHasProperty( 'errorDescription', $controller->getView() );
		} else {
			$this->assertSame( $description, $controller->getView()->errorDescription );
		}
	}

	/**
	 * ERROR_KEY (9) is documented in the class table as "remove-background
	 * download key missing", but viewError() has no case for it, so
	 * RemoveBackGroundViewController::loadPost() with an empty key shows the
	 * generic "An unknown error has occured" message. ERROR_KEY should get
	 * its own message (the key of the processed image is missing, run the
	 * preview again).
	 *
	 * How to recognise the fix: the message is no longer the unknown-error
	 * text and the assertSame on it fails.
	 */
	public function test_error_key_shows_the_unknown_error_message_pinned_for_deferred_fix() {
		$controller = new EMR_TestViewController();

		$exit = $this->error_exit( $controller, ViewController::ERROR_KEY );

		$this->assertSame( 9, $exit->getCode() );
		$this->assertSame( 'An unknown error has occured', $controller->getView()->errorMessage );
		$this->assertObjectNotHasProperty( 'errorDescription', $controller->getView() );
	}

	public function test_view_error_overwrites_an_earlier_error_message() {
		$controller                           = new EMR_TestViewController();
		$controller->getView()->errorMessage = 'Set by the caller';

		$this->error_exit( $controller, ViewController::ERROR_FORM );

		$this->assertSame( 'Set by the caller', $controller->errorMessageBefore );
		$this->assertSame( 'The form submitted is missing various fields', $controller->getView()->errorMessage );
	}

	public function test_directory_security_with_path_and_basedir_adds_the_description() {
		$controller = new EMR_TestViewController();

		$this->error_exit(
			$controller,
			ViewController::ERROR_DIRECTORY_SECURITY,
			array( 'path' => '/etc/emr-outside', 'basedir' => '/var/uploads/' )
		);

		$this->assertSame( 'Path: /etc/emr-outside is not within basedir reported as: /var/uploads/', $controller->getView()->errorDescription );
		$this->assertSame( array( 'path' => '/etc/emr-outside', 'basedir' => '/var/uploads/' ), $controller->lastErrorData );
	}

	public function test_directory_security_without_both_keys_has_no_description() {
		foreach ( array( array(), array( 'path' => '/etc/x' ), array( 'basedir' => '/var/uploads/' ) ) as $data ) {
			$controller = new EMR_TestViewController();

			$this->error_exit( $controller, ViewController::ERROR_DIRECTORY_SECURITY, $data );

			$this->assertSame( 'Specified directory is outside the upload directory. This is not allowed for security reasons', $controller->getView()->errorMessage );
			$this->assertObjectNotHasProperty( 'errorDescription', $controller->getView(), wp_json_encode( $data ) );
		}
	}

	public function test_error_data_is_ignored_for_other_codes() {
		$controller = new EMR_TestViewController();

		$this->error_exit( $controller, ViewController::ERROR_COPY_FAILED, array( 'path' => '/a', 'basedir' => '/b' ) );

		$this->assertObjectNotHasProperty( 'errorDescription', $controller->getView() );
	}

	public function test_error_messages_use_the_emr_text_domain() {
		$this->add_hook( 'gettext', array( $this, 'record_domain' ), 10, 3 );
		$controller = new EMR_TestViewController();

		$this->error_exit( $controller, ViewController::ERROR_NONCE );

		$this->assertSame( array( 'enable-media-replace' ), $this->domains['Fail to validate form nonce. Please try again'] );
		$this->assertSame( array( 'enable-media-replace' ), $this->domains['This can happen when the window is open for a long time and/or there has been a timeout.  You can go back to previous screen and try again. If this happens each time when replacing, contact us'] );
	}

	/**
	 * viewError() translates the ERROR_DIRECTORY_SECURITY description
	 * ("Path: %s is not within basedir reported as: %s") with the
	 * 'shortpixel-image-optimiser' text domain instead of
	 * 'enable-media-replace', so EMR's translations never apply to it. The
	 * message above it uses the right domain.
	 *
	 * How to recognise the fix: the recorded domain becomes
	 * 'enable-media-replace' and the assertSame on
	 * 'shortpixel-image-optimiser' fails.
	 */
	public function test_directory_security_description_uses_the_spio_text_domain_pinned_for_deferred_fix() {
		$this->add_hook( 'gettext', array( $this, 'record_domain' ), 10, 3 );
		$controller = new EMR_TestViewController();

		$this->error_exit( $controller, ViewController::ERROR_DIRECTORY_SECURITY, array( 'path' => '/p', 'basedir' => '/b' ) );

		$this->assertSame( 'Path: /p is not within basedir reported as: /b', $controller->getView()->errorDescription );
		$this->assertSame( array( 'enable-media-replace' ), $this->domains['Specified directory is outside the upload directory. This is not allowed for security reasons'] );
		$this->assertSame( array( 'shortpixel-image-optimiser' ), $this->domains['Path: %s is not within basedir reported as: %s'] );
		$this->assertNotContains( 'enable-media-replace', $this->domains['Path: %s is not within basedir reported as: %s'] );
	}

	/*
	 * viewSuccess().
	 */

	public function test_view_success_enqueues_the_success_script_and_ends_at_the_success_view() {
		// The plugin registers the handle on admin_enqueue_scripts, which does
		// not fire here; register a stand-in so the enqueue is observable.
		$this->assertFalse( wp_script_is( 'emr_success', 'registered' ), 'Precondition: not registered.' );
		wp_register_script( 'emr_success', 'https://example.org/emr_success.js', array(), '1', true );
		$controller = new EMR_TestViewController();
		$this->assertFalse( wp_script_is( 'emr_success', 'enqueued' ), 'Precondition: not queued yet.' );

		$exit = null;
		try {
			$controller->callViewSuccess();
		} catch ( EMR_ViewExit $e ) {
			$exit = $e;
		}

		$this->assertInstanceOf( EMR_ViewExit::class, $exit );
		$this->assertSame( 'success', $exit->template );
		$this->assertTrue( $controller->successCalled );
		$this->assertSame( array( 'success' ), $controller->loadedViews );
		$this->assertTrue( wp_script_is( 'emr_success', 'enqueued' ) );
	}

	/*
	 * loadView().
	 */

	public function test_load_view_returns_false_for_a_null_or_blank_template() {
		$controller = new EMR_TestViewController();

		foreach ( array( null, '', '   ', "\t" ) as $template ) {
			$result = null;
			$output = $this->capture(
				function () use ( $controller, $template, &$result ) {
					$result = $controller->renderView( $template );
				}
			);
			$this->assertFalse( $result, var_export( $template, true ) );
			$this->assertSame( '', $output );
		}
		$this->assertSame( array(), $this->views_loaded() );
	}

	public function test_load_view_of_a_missing_template_includes_nothing() {
		$controller = new EMR_TestViewController();
		$this->assertFileDoesNotExist( emr()->plugin_path( 'views/emr-no-such-view.php' ) );

		$result = 'unset';
		$output = $this->capture(
			function () use ( $controller, &$result ) {
				$result = $controller->renderView( 'emr-no-such-view' );
			}
		);

		$this->assertNull( $result );
		$this->assertSame( '', $output );
		$this->assertSame( array(), $this->views_loaded() );
	}

	public function test_load_view_includes_a_template_once_unless_unique_is_false() {
		$calls = 0;
		$spy   = function ( $value ) use ( &$calls ) {
			$calls++;
			return false; // upsell.php returns right after this filter.
		};
		$this->add_hook( 'emr/upsell', $spy, 10 );
		$controller = new EMR_TestViewController();

		$first = $this->capture(
			function () use ( $controller ) {
				$this->assertNull( $controller->renderView( 'upsell' ) );
			}
		);
		$this->assertSame( 1, $calls, 'The template ran.' );
		$this->assertSame( array( 'upsell' ), $this->views_loaded() );

		$controller->renderView( 'upsell' );
		$this->assertSame( 1, $calls, 'A unique template is not included twice.' );

		$controller->renderView( 'upsell', false );
		$this->assertSame( 2, $calls, '$unique false includes it again.' );
		$this->assertSame( array( 'upsell', 'upsell' ), $this->views_loaded() );
		$this->assertSame( '', $first );
	}

	public function test_loaded_views_are_shared_between_controller_classes() {
		$calls = 0;
		$spy   = function ( $value ) use ( &$calls ) {
			$calls++;
			return false;
		};
		$this->add_hook( 'emr/upsell', $spy, 10 );

		( new EMR_TestViewController() )->renderView( 'upsell' );
		( new EMR_TestReplaceViewController() )->renderView( 'upsell' );
		( new EMR_TestUploadViewController() )->renderView( 'upsell' );

		$this->assertSame( 1, $calls );
		$this->assertSame( array( 'upsell' ), $this->views_loaded() );
	}

	/*
	 * views/error.php.
	 */

	public function test_error_view_prints_the_controllers_view_message_and_description_escaped() {
		$this->admin_context();
		$controller = new EMR_TestViewController();
		$view       = new stdClass();

		$view->errorMessage     = 'Bad <b>thing</b> & more';
		$view->errorDescription = 'See <script>x</script>';
		$controller->setViewData( $view );

		$output = $this->capture(
			function () use ( $controller ) {
				$controller->renderView( 'error', false );
			}
		);

		$this->assertStringContainsString( "<div class='enable-media-replace emr-screen error-screen'>", $output );
		$this->assertStringContainsString( '<h1>An error occured</h1>', $output );
		$this->assertStringContainsString( '<p class="error-message"> Bad &lt;b&gt;thing&lt;/b&gt; &amp; more </p>', $output );
		$this->assertStringContainsString( '<p class="description">See &lt;script&gt;x&lt;/script&gt;</p>', $output );
		$this->assertStringNotContainsString( '<b>thing</b>', $output );
		$this->assertStringNotContainsString( '<script>x</script>', $output );
		$this->assertStringContainsString( '<a href="javascript:history.back()">', $output );
		$this->assertStringContainsString( 'https://shortpixel.com/knowledge-base/category/308-enable-media-replace', $output );
	}

	public function test_error_view_without_description_prints_no_description_paragraph() {
		$this->admin_context();
		$controller = new EMR_TestViewController();
		$this->error_exit( $controller, ViewController::ERROR_FORM );

		$output = $this->capture(
			function () use ( $controller ) {
				$controller->renderView( 'error', false );
			}
		);

		$this->assertStringContainsString( '<p class="error-message"> The form submitted is missing various fields </p>', $output );
		$this->assertStringNotContainsString( '<p class="description">', $output );
	}

	/**
	 * Renders views/error.php in a fresh process, so the admin footer it
	 * requires is included for the first time and renders after the view.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_error_view_renders_the_admin_footer_after_the_message() {
		$this->admin_context();
		$controller = new EMR_TestViewController();
		$this->error_exit( $controller, ViewController::ERROR_NONCE );

		$output = $this->capture(
			function () use ( $controller ) {
				$controller->renderView( 'error', false );
			}
		);

		$message = strpos( $output, '<p class="error-message"> Fail to validate form nonce. Please try again </p>' );
		$footer  = strpos( $output, '<div id="wpfooter" role="contentinfo">' );
		$this->assertIsInt( $message );
		$this->assertIsInt( $footer );
		$this->assertGreaterThan( $message, $footer );
		$this->assertStringContainsString( '<p class="description">This can happen', $output );
		$this->assertStringContainsString( '</html>', $output );
	}

	/*
	 * views/success.php.
	 */

	public function test_success_view_shows_the_redirect_link_and_counter() {
		$this->admin_context();
		$controller                      = new EMR_TestViewController();
		$controller->getView()->postUrl = 'https://example.org/wp-admin/post.php?action=edit&post=7&emr_replaced=1';

		$output = $this->capture(
			function () use ( $controller ) {
				$controller->renderView( 'success', false );
			}
		);

		$this->assertStringContainsString( "<div class='enable-media-replace emr-screen success-screen'>", $output );
		$this->assertStringContainsString( '<h1>Your image has been replaced!</h1>', $output );
		$this->assertStringContainsString( '<span id="redirect_counter"></span>', $output );
		$this->assertStringContainsString( '<a id="redirect_url" href="https://example.org/wp-admin/post.php?action=edit&#038;post=7&#038;emr_replaced=1">', $output );
	}

	public function test_success_view_escapes_the_redirect_url() {
		$this->admin_context();
		$controller                      = new EMR_TestViewController();
		$controller->getView()->postUrl = 'javascript:alert(1)';

		$output = $this->capture(
			function () use ( $controller ) {
				$controller->renderView( 'success', false );
			}
		);

		$this->assertStringContainsString( '<a id="redirect_url" href="">', $output );
		$this->assertStringNotContainsString( 'javascript:alert', $output );
	}

	public function test_success_view_shows_the_spio_line_for_users_who_can_install_plugins() {
		$this->admin_context();
		$this->assertTrue( current_user_can( 'install_plugins' ), 'Precondition: administrator.' );
		$controller                      = new EMR_TestViewController();
		$controller->getView()->postUrl = admin_url( 'post.php' );

		$output = $this->capture(
			function () use ( $controller ) {
				$controller->renderView( 'success', false );
			}
		);

		$this->assertStringContainsString( 'Did you know that you can also optimize the images', $output );
		$this->assertStringContainsString( '<p>Try the <a href="https://wordpress.org/plugins/shortpixel-image-optimiser/" target="_blank">ShortPixel Image Optimizer</a> plugin!</p>', $output );
	}

	public function test_success_view_hides_the_spio_line_when_the_upsell_filter_is_false() {
		$this->admin_context();
		$this->add_hook( 'emr/upsell', '__return_false' );
		$controller                      = new EMR_TestViewController();
		$controller->getView()->postUrl = admin_url( 'post.php' );

		$output = $this->capture(
			function () use ( $controller ) {
				$controller->renderView( 'success', false );
			}
		);

		$this->assertStringContainsString( '<span id="redirect_counter"></span>', $output );
		$this->assertStringNotContainsString( 'Did you know that you can also optimize', $output );
		$this->assertStringNotContainsString( 'shortpixel-image-optimiser', $output );
	}

	public function test_success_view_hides_the_spio_line_for_users_who_cannot_install_plugins() {
		$this->admin_context();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$controller                      = new EMR_TestViewController();
		$controller->getView()->postUrl = admin_url( 'post.php' );

		$output = $this->capture(
			function () use ( $controller ) {
				$controller->renderView( 'success', false );
			}
		);

		$this->assertStringContainsString( '<h1>Your image has been replaced!</h1>', $output );
		$this->assertStringNotContainsString( 'Did you know that you can also optimize', $output );
	}

	/**
	 * Renders views/success.php in a fresh process, so the admin footer it
	 * requires is included for the first time and renders after the view.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_success_view_renders_the_admin_footer_after_the_redirect_link() {
		$this->admin_context();
		$controller                      = new EMR_TestViewController();
		$controller->getView()->postUrl = admin_url( 'post.php?post=3' );

		$output = $this->capture(
			function () use ( $controller ) {
				$controller->renderView( 'success', false );
			}
		);

		$link   = strpos( $output, '<a id="redirect_url"' );
		$footer = strpos( $output, '<div id="wpfooter" role="contentinfo">' );
		$this->assertIsInt( $link );
		$this->assertIsInt( $footer );
		$this->assertGreaterThan( $link, $footer );
		$this->assertStringContainsString( '</html>', $output );
	}
}
