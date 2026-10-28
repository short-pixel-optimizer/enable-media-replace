<?php
/**
 * Tests for EnableMediaReplace\Notices\NoticeController
 * (build/shortpixel/notices/src/NoticeController.php).
 *
 * The module has few docblocks, so the code is the specification. The
 * controller keeps its notices in static properties and in the
 * `EnableMediaReplace-notices` option. set_up() snapshots the four static
 * properties, NoticeModel::$icons and the option, then starts every test
 * from an empty controller; tear_down() puts everything back. The AJAX
 * dismiss endpoint is covered in test-NoticeControllerAjax.php.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Notices\NoticeController;
use EnableMediaReplace\Notices\NoticeModel;

class NoticeControllerTest extends WP_UnitTestCase {

	const OPTION = 'EnableMediaReplace-notices';

	/**
	 * Static properties of NoticeController before the test.
	 *
	 * @var array
	 */
	private $saved_statics = array();

	/**
	 * NoticeModel::$icons before the test.
	 *
	 * @var array
	 */
	private $saved_icons;

	/**
	 * The notices option before the test (null when absent).
	 *
	 * @var mixed
	 */
	private $saved_option;

	public function set_up() {
		parent::set_up();
		foreach ( array( 'notices', 'instance', 'cssHookLoaded', 'newNotices' ) as $name ) {
			$this->saved_statics[ $name ] = $this->get_static( $name );
		}
		$this->saved_icons  = NoticeModel::$icons;
		$this->saved_option = get_option( self::OPTION, null );
		delete_option( self::OPTION );
		$this->reset_controller();
	}

	public function tear_down() {
		foreach ( $this->saved_statics as $name => $value ) {
			$this->set_static( $name, $value );
		}
		NoticeModel::$icons = $this->saved_icons;
		if ( null === $this->saved_option ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $this->saved_option );
		}
		parent::tear_down();
	}

	private function get_static( $name ) {
		$ref = new ReflectionProperty( NoticeController::class, $name );
		$ref->setAccessible( true );
		return $ref->getValue();
	}

	private function set_static( $name, $value ) {
		$ref = new ReflectionProperty( NoticeController::class, $name );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	private function reset_controller() {
		$this->set_static( 'instance', null );
		$this->set_static( 'notices', array() );
		$this->set_static( 'newNotices', array() );
		$this->set_static( 'cssHookLoaded', false );
	}

	/**
	 * Simulates a new request: a new controller that loads from the option.
	 */
	private function reload() {
		$this->reset_controller();
		return NoticeController::getInstance();
	}

	private function render( NoticeController $controller ) {
		ob_start();
		$controller->admin_notices();
		return ob_get_clean();
	}

	private function stored() {
		return get_option( self::OPTION, 'absent' );
	}

	/*
	 * Instance and loading.
	 */

	public function test_getInstance_returns_a_singleton_with_the_ajax_action() {
		$controller = NoticeController::getInstance();

		$this->assertInstanceOf( NoticeController::class, $controller );
		$this->assertSame( $controller, NoticeController::getInstance() );
		$this->assertSame( 10, has_action( 'wp_ajax_' . self::OPTION, array( $controller, 'ajax_action' ) ) );
		$this->assertSame( 0, $controller->notice_count );
		$this->assertSame( array(), $controller->getNotices() );
	}

	public function test_loading_keeps_only_notice_models_from_the_option() {
		$model = new NoticeModel( 'stored message', NoticeModel::NOTICE_WARNING );
		update_option( self::OPTION, array( $model, 'junk', (object) array( 'message' => 'fake' ) ) );

		$controller = $this->reload();

		$this->assertCount( 1, $controller->getNotices() );
		$this->assertSame( 'stored message', $controller->getNotices()[0]->message );
		$this->assertSame( 1, $controller->notice_count );
	}

	public function test_loading_a_non_array_option_starts_empty() {
		update_option( self::OPTION, 'not an array' );
		$this->assertSame( array(), $this->reload()->getNotices() );
	}

	/*
	 * Adding notices.
	 */

	public function test_each_add_method_creates_its_type_and_stores_it() {
		$normal  = NoticeController::addNormal( 'normal msg' );
		$warning = NoticeController::addWarning( 'warning msg' );
		$error   = NoticeController::addError( 'error msg' );
		$success = NoticeController::addSuccess( 'success msg' );

		$this->assertInstanceOf( NoticeModel::class, $normal );
		$this->assertSame( NoticeModel::NOTICE_NORMAL, $normal->messageType );
		$this->assertSame( NoticeModel::NOTICE_WARNING, $warning->messageType );
		$this->assertSame( NoticeModel::NOTICE_ERROR, $error->messageType );
		$this->assertSame( NoticeModel::NOTICE_SUCCESS, $success->messageType );
		$this->assertSame( 'error msg', $error->message );

		$controller = NoticeController::getInstance();
		$this->assertSame( array( $normal, $warning, $error, $success ), $controller->getNotices() );
		$this->assertSame( array( $normal, $warning, $error, $success ), $controller->getNewNotices() );
		$this->assertSame( 4, $controller->notice_count );

		$stored = $this->stored();
		$this->assertIsArray( $stored );
		$this->assertCount( 4, $stored );
		$this->assertSame( 'success msg', $stored[3]->message );
	}

	public function test_unique_flag_returns_the_existing_notice() {
		$first  = NoticeController::addNormal( 'only once', true );
		$second = NoticeController::addNormal( 'only once', true );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, NoticeController::getInstance()->getNotices() );
	}

	/**
	 * The unique check compares `$nitem->code == $notice->code`, but the
	 * constructor stores the type in messageType and `code` is never set
	 * (always null). Uniqueness therefore ignores the type: addError() with
	 * the text of an existing normal notice returns that normal notice, and
	 * the caller's error is shown as an info notice. The check should
	 * compare messageType.
	 *
	 * Where: NoticeController::addNotice().
	 *
	 * How to recognise the fix: addError() returns a new notice of type
	 * NOTICE_ERROR and the list holds two notices.
	 */
	public function test_unique_flag_ignores_the_notice_type_pinned_for_deferred_fix() {
		$normal = NoticeController::addNormal( 'same words', true );
		$this->assertNull( $normal->code );

		$error = NoticeController::addError( 'same words', true );

		$this->assertSame( $normal, $error );
		$this->assertSame( NoticeModel::NOTICE_NORMAL, $error->messageType );
		$this->assertCount( 1, NoticeController::getInstance()->getNotices() );
	}

	public function test_without_unique_flag_duplicates_are_added() {
		NoticeController::addNormal( 'twice' );
		NoticeController::addNormal( 'twice' );
		$this->assertCount( 2, NoticeController::getInstance()->getNotices() );
	}

	public function test_addDetail_adds_unique_details_and_stores_them() {
		$notice = NoticeController::addWarning( 'with details' );
		NoticeController::addDetail( $notice, 'file-a.jpg' );
		NoticeController::addDetail( $notice, 'file-b.jpg' );
		NoticeController::addDetail( $notice, 'file-a.jpg' );

		$this->assertSame( array( 'file-a.jpg', 'file-b.jpg' ), $notice->details );
		$this->assertSame( array( 'file-a.jpg', 'file-b.jpg' ), $this->stored()[0]->details );
	}

	/*
	 * Persistent notices and lookup by ID.
	 */

	public function test_makePersistent_stores_the_notice_under_its_id() {
		$notice = NoticeController::addError( 'persistent msg' );
		NoticeController::makePersistent( $notice, 'emr-test-key', HOUR_IN_SECONDS );

		$this->assertTrue( $notice->isPersistent() );
		$this->assertSame( 'emr-test-key', $notice->getID() );
		$this->assertSame( HOUR_IN_SECONDS, $notice->_debug_getvar( 'suppress_period' ) );

		$found = $this->reload()->getNoticeByID( 'emr-test-key' );
		$this->assertInstanceOf( NoticeModel::class, $found );
		$this->assertSame( 'persistent msg', $found->message );
		$this->assertTrue( $found->isPersistent() );
	}

	public function test_getNoticeByID_returns_false_for_unknown_id() {
		$notice = NoticeController::addNormal( 'x' );
		NoticeController::makePersistent( $notice, 'known-key' );
		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'unknown-key' ) );
	}

	/**
	 * getNoticeByID() compares with `==`, and a non-persistent notice has a
	 * null ID, so looking up an empty string (or null) returns the first
	 * non-persistent notice instead of false. The AJAX dismiss handler
	 * passes the posted ID straight in, so an empty `id` dismisses an
	 * arbitrary notice. The comparison should be strict and skip notices
	 * without an ID.
	 *
	 * Where: NoticeController::getNoticeByID().
	 *
	 * How to recognise the fix: getNoticeByID('') returns false.
	 */
	public function test_getNoticeByID_matches_empty_id_to_a_plain_notice_pinned_for_deferred_fix() {
		$persistent = NoticeController::addNormal( 'persistent' );
		NoticeController::makePersistent( $persistent, 'some-key' );
		$plain = NoticeController::addWarning( 'plain one' );
		$this->assertNull( $plain->getID() );

		$found = NoticeController::getInstance()->getNoticeByID( '' );

		$this->assertSame( $plain, $found );
	}

	/**
	 * Making a second notice persistent under an existing key drops the new
	 * notice and keeps the original object.
	 */
	public function test_makePersistent_with_existing_key_drops_the_duplicate() {
		$first = NoticeController::addWarning( 'same text' );
		NoticeController::makePersistent( $first, 'dup-key' );

		$second = NoticeController::addWarning( 'same text' );
		NoticeController::makePersistent( $second, 'dup-key' );

		$notices = NoticeController::getInstance()->getNotices();
		$this->assertSame( array( $first ), $notices );
		$this->assertFalse( $second->isPersistent() );
		$this->assertCount( 1, $this->stored() );
	}

	public function test_makePersistent_with_existing_key_updates_the_message() {
		$first = NoticeController::addWarning( 'old text' );
		NoticeController::makePersistent( $first, 'upd-key' );

		$second = NoticeController::addWarning( 'new text' );
		NoticeController::makePersistent( $second, 'upd-key' );

		$controller = NoticeController::getInstance();
		$this->assertCount( 1, $controller->getNotices() );
		$this->assertSame( 'new text', $controller->getNoticeByID( 'upd-key' )->message );
		$this->assertSame( 'new text', $this->stored()[0]->message );
	}

	/**
	 * A closure callback (the parameter is documented as "Callable
	 * function") cannot be stored: update() writes the notice to the option
	 * and serialising a Closure throws. The notice is already in memory when
	 * that happens. Either the callback must be restricted to serialisable
	 * callables (and documented so) or kept out of the stored notice.
	 *
	 * Where: NoticeController::makePersistent() / NoticeModel::setPersistent().
	 *
	 * How to recognise the fix: no exception is thrown and the notice is
	 * stored.
	 */
	public function test_makePersistent_with_closure_callback_throws_pinned_for_deferred_fix() {
		$notice = NoticeController::addNormal( 'closure callback' );

		try {
			NoticeController::makePersistent(
				$notice,
				'closure-key',
				-1,
				function () {
					return true;
				}
			);
			$this->fail( 'Expected the closure serialisation to throw.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( "Serialization of 'Closure' is not allowed", $e->getMessage() );
		}
		$this->assertTrue( $notice->isPersistent() );
	}

	/*
	 * Removing and resetting.
	 */

	public function test_removeNoticeByID_removes_the_notice_and_updates_the_option() {
		$keep   = NoticeController::addNormal( 'keep' );
		$remove = NoticeController::addNormal( 'remove' );
		NoticeController::makePersistent( $remove, 'remove-key' );

		NoticeController::removeNoticeByID( 'remove-key' );

		$this->assertSame( array( $keep ), NoticeController::getInstance()->getNotices() );
		$this->assertCount( 1, $this->stored() );
		$this->assertSame( 'keep', $this->stored()[0]->message );
	}

	/**
	 * update() deletes the option for an empty notice list only when the
	 * notices were loaded from the option at construction (has_stored).
	 * When the option did not exist at the start of the request and the
	 * last notice is removed in the same request, the option keeps the
	 * removed notice and it comes back on the next request. Removing the
	 * last notice must always clear the option.
	 *
	 * Where: NoticeController::update(), the has_stored guard.
	 *
	 * How to recognise the fix: the option is gone (or empty) after the
	 * removal and the reloaded controller has no notices.
	 */
	public function test_removing_the_last_notice_keeps_it_stored_pinned_for_deferred_fix() {
		$this->assertSame( 'absent', $this->stored() );
		$notice = NoticeController::addError( 'last one' );
		NoticeController::makePersistent( $notice, 'last-key' );

		NoticeController::removeNoticeByID( 'last-key' );

		$this->assertSame( array(), NoticeController::getInstance()->getNotices() );
		$this->assertIsArray( $this->stored() );
		$this->assertCount( 1, $this->stored() );
		$this->assertSame( 'last one', $this->reload()->getNoticeByID( 'last-key' )->message );
	}

	public function test_removing_the_last_notice_clears_a_loaded_option() {
		$notice = NoticeController::addError( 'last one' );
		NoticeController::makePersistent( $notice, 'last-key' );
		$this->reload();

		NoticeController::removeNoticeByID( 'last-key' );

		$this->assertSame( 'absent', $this->stored() );
	}

	/**
	 * resetNotices() deletes the option but leaves the notices of the
	 * running controller in memory, and the next update() (any add, display
	 * or dismiss in the same request) writes them all back. Its docblock
	 * says it resets "all notices"; it only works when no controller has
	 * loaded yet. It should also empty the in-memory list.
	 *
	 * Where: NoticeController::resetNotices().
	 *
	 * How to recognise the fix: getNotices() is empty right after
	 * resetNotices(), and the later add stores one notice, not two.
	 */
	public function test_resetNotices_leaves_memory_untouched_pinned_for_deferred_fix() {
		NoticeController::addNormal( 'before reset' );
		$this->assertIsArray( $this->stored() );

		NoticeController::resetNotices();

		$this->assertSame( 'absent', $this->stored() );
		$this->assertCount( 1, NoticeController::getInstance()->getNotices() );

		NoticeController::addNormal( 'after reset' );
		$this->assertCount( 2, $this->stored() );
	}

	/*
	 * Display.
	 */

	public function test_getNoticesForDisplay_skips_dismissed_and_repeats_persistent_once() {
		$plain      = NoticeController::addNormal( 'plain' );
		$dismissed  = NoticeController::addNormal( 'dismissed' );
		$persistent = NoticeController::addWarning( 'persistent' );
		NoticeController::makePersistent( $dismissed, 'dismissed-key' );
		NoticeController::makePersistent( $persistent, 'persistent-key' );
		$dismissed->dismiss();

		$controller = NoticeController::getInstance();
		$first      = $controller->getNoticesForDisplay();
		$second     = $controller->getNoticesForDisplay();

		$this->assertSame( array( $plain, $persistent ), $first );
		$this->assertSame( self::OPTION, $persistent->notice_action );
		$this->assertSame( array( $plain ), $second, 'A persistent notice is handed out once per controller.' );
	}

	/**
	 * admin_notices() prints the message as given, without escaping: the
	 * notice API takes HTML.
	 */
	public function test_admin_notices_prints_message_html_with_type_class_and_icon() {
		NoticeController::getInstance()->loadIcons( array( 'error' => '<img class="test-icon">' ) );
		NoticeController::addError( 'Replace <strong>failed</strong> & stopped' );

		$html = $this->render( NoticeController::getInstance() );

		$this->assertStringContainsString( 'Replace <strong>failed</strong> & stopped', $html );
		$this->assertStringContainsString( 'class="shortpixel shortpixel-notice notice-error is-dismissible "', $html );
		$this->assertStringContainsString( '<span class="icon"><img class="test-icon"></span>', $html );
		$this->assertStringContainsString( 'class="notice-dismiss"', $html );
		$this->assertSame( 10, has_action( 'admin_print_footer_scripts', array( NoticeController::getInstance(), 'printNoticeStyle' ) ) );
	}

	public function test_admin_notices_drops_displayed_plain_notices() {
		NoticeController::addSuccess( 'shown once' );
		$controller = NoticeController::getInstance();

		$this->assertStringContainsString( 'shown once', $this->render( $controller ) );

		$this->assertSame( array(), $controller->getNotices() );
		$this->assertSame( array(), $this->stored() );
		$this->assertSame( '', $this->render( $controller ) );
	}

	public function test_admin_notices_keeps_persistent_notices_after_display() {
		$notice = NoticeController::addWarning( 'stays' );
		NoticeController::makePersistent( $notice, 'stay-key' );

		$html = $this->render( NoticeController::getInstance() );

		$this->assertStringContainsString( 'id="stay-key"', $html );
		$this->assertStringContainsString( 'is-persistent', $html );
		$this->assertStringContainsString( 'stays', $this->render( $this->reload() ) );
	}

	public function test_admin_notices_without_notices_prints_nothing_and_adds_no_style() {
		$controller = NoticeController::getInstance();
		$this->assertSame( '', $this->render( $controller ) );
		$this->assertFalse( has_action( 'admin_print_footer_scripts', array( $controller, 'printNoticeStyle' ) ) );
	}

	public function test_printNoticeStyle_prints_the_bundled_css() {
		ob_start();
		NoticeController::getInstance()->printNoticeStyle();
		$html = ob_get_clean();

		$this->assertStringStartsWith( '<style>', $html );
		$this->assertStringContainsString( 'shortpixel-notice', $html );
		$this->assertStringEndsWith( '</style>', $html );
	}

	public function test_loadIcons_maps_names_to_types() {
		NoticeModel::$icons = array();
		NoticeController::getInstance()->loadIcons(
			array(
				'normal'  => 'N',
				'success' => 'S',
				'warning' => 'W',
				'error'   => 'E',
				'other'   => 'O',
			)
		);

		$this->assertSame(
			array(
				NoticeModel::NOTICE_NORMAL  => 'O',
				NoticeModel::NOTICE_SUCCESS => 'S',
				NoticeModel::NOTICE_WARNING => 'W',
				NoticeModel::NOTICE_ERROR   => 'E',
			),
			NoticeModel::$icons,
			'An unknown name maps to normal and overwrites it.'
		);
	}

	/*
	 * Dismissal and expiry.
	 */

	public function test_dismissed_persistent_notice_is_kept_but_hidden_while_suppressed() {
		$notice = NoticeController::addWarning( 'suppressed' );
		NoticeController::makePersistent( $notice, 'suppress-key', HOUR_IN_SECONDS );
		$controller = NoticeController::getInstance();
		$this->render( $controller );

		$notice->dismiss();
		$controller->update();

		$reloaded = $this->reload();
		$stored   = $reloaded->getNoticeByID( 'suppress-key' );
		$this->assertTrue( $stored->isDismissed() );
		$this->assertTrue( $stored->isPersistent() );
		$this->assertSame( '', $this->render( $reloaded ) );
	}

	/**
	 * Once the suppress period of a dismissed notice is over, isDone()
	 * un-persists it and update() drops it. The setPersistent() docblock
	 * says a dismissed notice is not shown "again for X amount of time",
	 * which reads as "shown again afterwards"; the code removes it for good.
	 */
	public function test_dismissed_notice_with_expired_suppress_period_is_dropped() {
		$notice = NoticeController::addWarning( 'expiring' );
		NoticeController::makePersistent( $notice, 'expire-key', HOUR_IN_SECONDS );
		$controller = NoticeController::getInstance();
		$this->render( $controller );
		$notice->dismiss();
		$notice->setDismissedUntil( time() - 10 );

		$this->assertSame( 0, $controller->update() );
		$this->assertFalse( $controller->getNoticeByID( 'expire-key' ) );
		$this->assertFalse( $this->reload()->getNoticeByID( 'expire-key' ) );
	}

	/**
	 * The default suppress period of -1 puts the end of the suppression in
	 * the past at dismissal time, so a displayed persistent notice is gone
	 * after the next update.
	 */
	public function test_dismissing_with_default_suppress_period_drops_the_notice() {
		$notice = NoticeController::addError( 'one shot' );
		NoticeController::makePersistent( $notice, 'one-shot-key' );
		$controller = NoticeController::getInstance();
		$this->render( $controller );

		$notice->dismiss();

		$this->assertSame( 0, $controller->update() );
		$this->assertFalse( $controller->getNoticeByID( 'one-shot-key' ) );
	}
}
