<?php
/**
 * Tests for EnableMediaReplace\Notices\NoticeModel
 * (build/shortpixel/notices/src/NoticeModel.php).
 *
 * The module has few docblocks, so the code is the specification. Models
 * are built directly; the static icon map and the private static
 * $jsDismissLoaded flag are snapshotted and restored.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Notices\NoticeModel;

class NoticeModelTest extends WP_UnitTestCase {

	/**
	 * NoticeModel::$icons before the test.
	 *
	 * @var array
	 */
	private $saved_icons;

	/**
	 * NoticeModel::$jsDismissLoaded before the test.
	 *
	 * @var mixed
	 */
	private $saved_js_loaded;

	public function set_up() {
		parent::set_up();
		$this->saved_icons     = NoticeModel::$icons;
		$this->saved_js_loaded = $this->js_loaded_prop()->getValue();
		NoticeModel::$icons    = array();
	}

	public function tear_down() {
		NoticeModel::$icons = $this->saved_icons;
		$this->js_loaded_prop()->setValue( null, $this->saved_js_loaded );
		parent::tear_down();
	}

	private function js_loaded_prop() {
		$ref = new ReflectionProperty( NoticeModel::class, 'jsDismissLoaded' );
		$ref->setAccessible( true );
		return $ref;
	}

	private function set_prop( NoticeModel $notice, $name, $value ) {
		$ref = new ReflectionProperty( NoticeModel::class, $name );
		$ref->setAccessible( true );
		$ref->setValue( $notice, $value );
	}

	public function test_constructor_defaults() {
		$notice = new NoticeModel( 'hello' );

		$this->assertSame( 'hello', $notice->message );
		$this->assertSame( NoticeModel::NOTICE_NORMAL, $notice->messageType );
		$this->assertNull( $notice->getID() );
		$this->assertFalse( $notice->isPersistent() );
		$this->assertFalse( $notice->isDismissed() );
		$this->assertTrue( $notice->is_removable );
		$this->assertSame( array(), $notice->details );
		$this->assertFalse( $notice->isDone() );
		$this->assertSame( array( 1, 2, 3, 4 ), array( NoticeModel::NOTICE_NORMAL, NoticeModel::NOTICE_ERROR, NoticeModel::NOTICE_SUCCESS, NoticeModel::NOTICE_WARNING ) );
	}

	public function test_setPersistent_sets_id_and_suppress_period() {
		$notice = new NoticeModel( 'p' );
		$notice->setPersistent( 'model-key', 300, '__return_true' );

		$this->assertSame( 'model-key', $notice->getID() );
		$this->assertTrue( $notice->isPersistent() );
		$this->assertSame( 300, $notice->_debug_getvar( 'suppress_period' ) );
		$this->assertSame( '__return_true', $notice->_debug_getvar( 'callback' ) );
		$this->assertNull( $notice->_debug_getvar( 'no_such_property' ) );
	}

	public function test_setPersistent_ignores_a_non_callable_callback() {
		$notice = new NoticeModel( 'p' );
		$notice->setPersistent( 'model-key', -1, 'emr_no_such_function' );
		$this->assertNull( $notice->_debug_getvar( 'callback' ) );
	}

	public function test_dismiss_and_unDismiss() {
		$notice = new NoticeModel( 'd' );
		$notice->setPersistent( 'd-key', 600 );
		$before = time();

		$notice->dismiss();

		$this->assertTrue( $notice->isDismissed() );
		$until = $notice->_debug_getvar( 'suppress_until' );
		$this->assertGreaterThanOrEqual( $before + 600, $until );
		$this->assertLessThanOrEqual( time() + 600, $until );

		$notice->unDismiss();
		$this->assertFalse( $notice->isDismissed() );
	}

	public function test_isDone_after_viewing_depends_on_persistence() {
		$plain = new NoticeModel( 'plain' );
		$plain->getForDisplay();
		$this->assertTrue( $plain->isDone() );

		$persistent = new NoticeModel( 'persistent' );
		$persistent->setPersistent( 'done-key' );
		$persistent->getForDisplay();
		$this->assertFalse( $persistent->isDone() );
	}

	public function test_isDone_unpersists_a_dismissed_notice_after_its_suppress_time() {
		$notice = new NoticeModel( 'x' );
		$notice->setPersistent( 'x-key', 600 );
		$notice->getForDisplay();
		$notice->dismiss();
		$this->assertFalse( $notice->isDone() );
		$this->assertTrue( $notice->isPersistent() );

		$notice->setDismissedUntil( time() - 1 );

		$this->assertTrue( $notice->isDone() );
		$this->assertFalse( $notice->isPersistent() );
	}

	public function test_addDetail_skips_duplicates_and_can_clean() {
		$notice = new NoticeModel( 'd' );
		$notice->addDetail( 'one' );
		$notice->addDetail( 'two' );
		$notice->addDetail( 'one' );
		$this->assertSame( array( 'one', 'two' ), $notice->details );

		$notice->addDetail( 'three', true );
		$this->assertSame( array( 'three' ), $notice->details );
	}

	public function test_checkScreen_without_limits_allows_every_screen() {
		$this->assertTrue( ( new NoticeModel( 's' ) )->checkScreen( 'upload' ) );
	}

	public function test_limitScreens_include_and_exclude() {
		$notice = new NoticeModel( 's' );
		$notice->limitScreens( 'include', 'upload' );
		$notice->limitScreens( 'include', array( 'attachment' ) );
		$notice->limitScreens( 'exclude', array( 'dashboard' ) );

		$this->assertTrue( $notice->checkScreen( 'upload' ) );
		$this->assertTrue( $notice->checkScreen( 'attachment' ) );
		$this->assertFalse( $notice->checkScreen( 'edit-post' ), 'Not included.' );
		$this->assertFalse( $notice->checkScreen( 'dashboard' ) );

		$exclude_only = new NoticeModel( 'e' );
		$exclude_only->limitScreens( 'exclude', 'dashboard' );
		$this->assertFalse( $exclude_only->checkScreen( 'dashboard' ) );
		$this->assertTrue( $exclude_only->checkScreen( 'upload' ) );
	}

	public function test_setIcon_maps_type_names() {
		NoticeModel::setIcon( 'error', 'E' );
		NoticeModel::setIcon( 'success', 'S' );
		NoticeModel::setIcon( 'warning', 'W' );
		NoticeModel::setIcon( 'normal', 'N' );

		$this->assertSame(
			array(
				NoticeModel::NOTICE_ERROR   => 'E',
				NoticeModel::NOTICE_SUCCESS => 'S',
				NoticeModel::NOTICE_WARNING => 'W',
				NoticeModel::NOTICE_NORMAL  => 'N',
			),
			NoticeModel::$icons
		);
	}

	/*
	 * getForDisplay.
	 */

	public function test_getForDisplay_classes_per_type() {
		$expected = array(
			NoticeModel::NOTICE_NORMAL  => 'notice-info',
			NoticeModel::NOTICE_ERROR   => 'notice-error',
			NoticeModel::NOTICE_SUCCESS => 'notice-success',
			NoticeModel::NOTICE_WARNING => 'notice-warning',
			99                          => 'notice-info',
		);
		foreach ( $expected as $type => $class ) {
			$html = ( new NoticeModel( 'typed', $type ) )->getForDisplay();
			$this->assertStringContainsString( 'class="shortpixel shortpixel-notice ' . $class . ' is-dismissible "', $html );
		}
	}

	public function test_getForDisplay_plain_notice_markup() {
		NoticeModel::setIcon( 'success', '<i>ok</i>' );
		$notice = new NoticeModel( 'All <em>done</em>', NoticeModel::NOTICE_SUCCESS );

		$html = $notice->getForDisplay();

		$this->assertMatchesRegularExpression( '/^<div id="([0-9a-f]+)" class="[^"]+"><span class="icon"><i>ok<\/i><\/span><span class="content">All <em>done<\/em><\/span>/', $html );
		$this->assertStringContainsString( '<span class="screen-reader-text">Dismiss this notice</span>', $html );
		$this->assertStringContainsString( 'data-dismiss="-1"', $html );
		$this->assertStringContainsString( "document.getElementById('button-", $html, 'A plain notice removes itself client side.' );
		$this->assertStringNotContainsString( 'shortpixel_notice_dismiss', $html );
		$this->assertTrue( $notice->isDone(), 'Displaying marks it viewed.' );
	}

	public function test_getForDisplay_not_removable_has_no_dismiss_button() {
		$notice               = new NoticeModel( 'fixed' );
		$notice->is_removable = false;
		$html                 = $notice->getForDisplay();

		$this->assertStringNotContainsString( 'notice-dismiss', $html );
		$this->assertStringNotContainsString( 'is-dismissible', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_getForDisplay_details_block() {
		$notice = new NoticeModel( 'with details' );
		$notice->setPersistent( 'det-key' );
		$notice->addDetail( 'a.jpg' );
		$notice->addDetail( 'b.jpg' );

		$html = $notice->getForDisplay();

		$this->assertStringContainsString( 'id="check-det-key"', $html );
		$this->assertStringContainsString( '<p class="detail-content">a.jpg<BR>b.jpg</p>', $html );
		$this->assertStringContainsString( 'See Details', $html );
		$this->assertStringContainsString( 'Hide Details', $html );
	}

	public function test_getForDisplay_persistent_notice_wires_the_ajax_dismiss() {
		$notice = new NoticeModel( 'persistent' );
		$notice->setPersistent( 'ajax-key', 120 );
		$notice->notice_action = 'EnableMediaReplace-notices';

		$html = $notice->getForDisplay();

		$this->assertStringContainsString( 'id="ajax-key"', $html );
		$this->assertStringContainsString( 'is-persistent', $html );
		$this->assertStringContainsString( 'data-dismiss="120"', $html );
		$this->assertStringContainsString( "'action' : 'EnableMediaReplace-notices'", $html );
		$this->assertStringContainsString( "'nonce' : '" . wp_create_nonce( 'dismiss' ) . "'", $html );
		$this->assertStringContainsString( wp_json_encode( admin_url( 'admin-ajax.php' ) ), $html );
		$this->assertStringContainsString( 'jQuery("#ajax-key").find(".notice-dismiss, .notice-dismiss-action").on("click", shortpixel_notice_dismiss);', $html );
	}

	/**
	 * getDismissJS() defines the shortpixel_notice_dismiss() function only
	 * when $jsDismissLoaded is null, but never sets it, so every persistent
	 * notice on a page redefines the same global function. It should set
	 * the flag after the first definition.
	 *
	 * Where: NoticeModel::getDismissJS().
	 *
	 * How to recognise the fix: the second notice's output no longer
	 * contains the function definition.
	 */
	public function test_dismiss_function_is_defined_for_every_notice_pinned_for_deferred_fix() {
		$this->js_loaded_prop()->setValue( null, null );
		$first = new NoticeModel( 'one' );
		$first->setPersistent( 'one-key' );
		$second = new NoticeModel( 'two' );
		$second->setPersistent( 'two-key' );

		$html_one = $first->getForDisplay();
		$html_two = $second->getForDisplay();

		$this->assertStringContainsString( 'function shortpixel_notice_dismiss(event)', $html_one );
		$this->assertStringContainsString( 'function shortpixel_notice_dismiss(event)', $html_two );
		$this->assertNull( $this->js_loaded_prop()->getValue() );
	}

	public function test_callback_returning_false_suppresses_the_notice() {
		$notice = new NoticeModel( 'hidden' );
		$notice->setPersistent( 'cb-key', -1, '__return_false' );
		$this->assertNull( $notice->getForDisplay() );

		$shown = new NoticeModel( 'shown' );
		$shown->setPersistent( 'cb2-key', -1, '__return_true' );
		$this->assertStringContainsString( 'shown', $shown->getForDisplay() );
	}

	/**
	 * A stored array callback whose object could not be restored (an
	 * incomplete class after unserialize) suppresses the notice.
	 */
	public function test_incomplete_class_callback_returns_false() {
		$incomplete = unserialize( 'O:18:"EmrNoSuchNoticeCls":0:{}' );
		$this->assertInstanceOf( '__PHP_Incomplete_Class', $incomplete );
		$notice = new NoticeModel( 'broken callback' );
		$this->set_prop( $notice, 'callback', array( $incomplete, 'render' ) );

		$this->assertFalse( $notice->getForDisplay() );
	}

	/**
	 * For an object callback (a Closure or an invokable object) the
	 * incomplete-class check reads `$part`, a variable that only exists in
	 * the array branch above it, so PHP raises "Undefined variable $part"
	 * (a notice on 7.4, a warning on 8.x). The check should test
	 * $this->callback itself.
	 *
	 * Where: NoticeModel::getForDisplay(), the `elseif (is_object(...))`
	 * branch.
	 *
	 * How to recognise the fix: no error is raised and the notice renders.
	 */
	public function test_object_callback_reads_undefined_variable_pinned_for_deferred_fix() {
		$notice = new NoticeModel( 'closure' );
		$notice->setPersistent(
			'closure-key',
			-1,
			function () {
				return true;
			}
		);
		$this->assertIsObject( $notice->_debug_getvar( 'callback' ) );

		$error = null;
		try {
			$notice->getForDisplay();
		} catch ( \PHPUnit\Framework\Error\Error $e ) {
			$error = $e;
		}

		$this->assertInstanceOf( PHP_VERSION_ID >= 80000 ? \PHPUnit\Framework\Error\Warning::class : \PHPUnit\Framework\Error\Notice::class, $error );
		$this->assertMatchesRegularExpression( '/Undefined variable:? \$?part/', $error->getMessage() );
	}
}
