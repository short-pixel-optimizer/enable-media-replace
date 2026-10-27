<?php
/**
 * Tests for EnableMediaReplace\Controller\RemoteNoticeController
 * (classes/Controller/RemoteNoticeController.php).
 *
 * The constructor does all the work, so every test builds the controller
 * through getInstance() after resetting its singleton. notices.php is
 * answered by MockEmrApi; a fail-fast filter at priority 9999 refuses and
 * records anything else, and tear_down() asserts nothing was refused. The
 * `emr_remote_notice` transient, the notices option and the static state of
 * the notices module are snapshotted and restored.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Controller\RemoteNoticeController;
use EnableMediaReplace\Notices\NoticeController;
use EnableMediaReplace\Notices\NoticeModel;

require_once dirname( __DIR__ ) . '/Integration/Helpers/MockEmrApi.php';

class RemoteNoticeControllerTest extends WP_UnitTestCase {

	const TRANSIENT     = 'emr_remote_notice';
	const NOTICE_OPTION = 'EnableMediaReplace-notices';

	/**
	 * @var MockEmrApi
	 */
	private $mock;

	/**
	 * @var mixed
	 */
	private $saved_transient;

	/**
	 * @var mixed
	 */
	private $saved_notice_option;

	/**
	 * Static properties of NoticeController before the test.
	 *
	 * @var array
	 */
	private $saved_statics = array();

	/**
	 * URLs refused by the fail-fast filter.
	 *
	 * @var array
	 */
	private $blocked = array();

	public function set_up() {
		parent::set_up();
		$this->saved_transient     = get_transient( self::TRANSIENT );
		$this->saved_notice_option = get_option( self::NOTICE_OPTION, null );
		foreach ( array( 'notices', 'instance', 'cssHookLoaded', 'newNotices' ) as $name ) {
			$this->saved_statics[ $name ] = $this->notice_static( $name );
		}

		delete_transient( self::TRANSIENT );
		delete_option( self::NOTICE_OPTION );
		$this->reset_notices();
		$this->reset_remote();

		$this->mock = MockEmrApi::register();
		$this->mock->reset();
		$this->blocked = array();
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999 );
		MockEmrApi::unregister();
		$this->reset_remote();

		foreach ( $this->saved_statics as $name => $value ) {
			$this->notice_static( $name, $value, true );
		}
		if ( null === $this->saved_notice_option ) {
			delete_option( self::NOTICE_OPTION );
		} else {
			update_option( self::NOTICE_OPTION, $this->saved_notice_option );
		}
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

	private function notice_static( $name, $value = null, $set = false ) {
		$ref = new ReflectionProperty( NoticeController::class, $name );
		$ref->setAccessible( true );
		if ( $set ) {
			$ref->setValue( null, $value );
			return null;
		}
		return $ref->getValue();
	}

	private function reset_notices() {
		$this->notice_static( 'instance', null, true );
		$this->notice_static( 'notices', array(), true );
		$this->notice_static( 'newNotices', array(), true );
		$this->notice_static( 'cssHookLoaded', false, true );
	}

	private function reset_remote() {
		$ref = new ReflectionProperty( RemoteNoticeController::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * Simulates the next page load: fresh singletons that reload from storage.
	 */
	private function next_request() {
		$this->reset_remote();
		$this->reset_notices();
		return RemoteNoticeController::getInstance();
	}

	private function notices() {
		return NoticeController::getInstance()->getNotices();
	}

	private function notice( $id, $message, $type = null ) {
		$item = array(
			'id'      => $id,
			'message' => $message,
		);
		if ( null !== $type ) {
			$item['type'] = $type;
		}
		return $item;
	}

	private function suppress_period( NoticeModel $notice ) {
		$ref = new ReflectionProperty( NoticeModel::class, 'suppress_period' );
		$ref->setAccessible( true );
		return $ref->getValue( $notice );
	}

	/*
	 * Fetching.
	 */

	public function test_getInstance_returns_a_singleton_and_fetches_once() {
		$first = RemoteNoticeController::getInstance();

		$this->assertInstanceOf( RemoteNoticeController::class, $first );
		$this->assertSame( $first, RemoteNoticeController::getInstance() );
		$this->assertCount( 1, $this->mock->getRequests() );
	}

	public function test_without_transient_fetches_the_endpoint_with_query_args() {
		RemoteNoticeController::getInstance();

		$requests = $this->mock->requestsTo( 'notices.php' );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'GET', $requests[0]['method'] );
		$this->assertStringStartsWith( 'https://api.shortpixel.com/v2/notices.php?', $requests[0]['url'] );
		$this->assertSame(
			array(
				'version' => (string) EMR_VERSION,
				'plugin'  => 'enable-media-replace',
				'target'  => '4',
			),
			$requests[0]['request']
		);
	}

	public function test_none_transient_fetches_again() {
		set_transient( self::TRANSIENT, 'none', DAY_IN_SECONDS );

		RemoteNoticeController::getInstance();

		$this->assertCount( 1, $this->mock->requestsTo( 'notices.php' ) );
	}

	public function test_a_cached_array_is_processed_without_a_request() {
		set_transient( self::TRANSIENT, array( (object) $this->notice( 'cached-1', 'From cache' ) ), DAY_IN_SECONDS );

		RemoteNoticeController::getInstance();

		$this->assertSame( array(), $this->mock->getRequests() );
		$this->assertCount( 1, $this->notices() );
		$this->assertSame( 'cached-1', $this->notices()[0]->getID() );
	}

	/*
	 * Adding notices.
	 */

	public function test_each_type_becomes_a_persistent_notice_with_its_id() {
		$this->mock->setKnob(
			'notices',
			array(
				$this->notice( 'remote-n', 'Normal text', 'notice' ),
				$this->notice( 'remote-w', 'Warning text', 'warning' ),
				$this->notice( 'remote-e', 'Error text', 'error' ),
			)
		);

		RemoteNoticeController::getInstance();

		$notices = $this->notices();
		$this->assertCount( 3, $notices );
		$expected = array(
			array( 'remote-n', 'Normal text', NoticeModel::NOTICE_NORMAL ),
			array( 'remote-w', 'Warning text', NoticeModel::NOTICE_WARNING ),
			array( 'remote-e', 'Error text', NoticeModel::NOTICE_ERROR ),
		);
		foreach ( $expected as $i => $row ) {
			$this->assertInstanceOf( NoticeModel::class, $notices[ $i ] );
			$this->assertSame( $row[0], $notices[ $i ]->getID() );
			$this->assertSame( $row[1], $notices[ $i ]->message );
			$this->assertSame( $row[2], $notices[ $i ]->messageType );
			$this->assertTrue( $notices[ $i ]->isPersistent() );
			$this->assertSame( MONTH_IN_SECONDS, $this->suppress_period( $notices[ $i ] ) );
		}
	}

	public function test_missing_or_unknown_type_becomes_a_normal_notice() {
		$this->mock->setKnob(
			'notices',
			array(
				$this->notice( 'no-type', 'No type' ),
				$this->notice( 'odd-type', 'Odd type', 'success' ),
			)
		);

		RemoteNoticeController::getInstance();

		$notices = $this->notices();
		$this->assertCount( 2, $notices );
		$this->assertSame( NoticeModel::NOTICE_NORMAL, $notices[0]->messageType );
		$this->assertSame( NoticeModel::NOTICE_NORMAL, $notices[1]->messageType );
	}

	public function test_message_is_escaped_and_id_sanitized() {
		$this->mock->setKnob( 'notices', array( $this->notice( " id-<i>7</i>\n", '<b>Bold</b> & "quoted"' ) ) );

		RemoteNoticeController::getInstance();

		$notices = $this->notices();
		$this->assertCount( 1, $notices );
		$this->assertSame( 'id-7', $notices[0]->getID() );
		$this->assertSame( '&lt;b&gt;Bold&lt;/b&gt; &amp; &quot;quoted&quot;', $notices[0]->message );
	}

	public function test_notices_are_stored_in_the_notices_option() {
		$this->mock->setKnob( 'notices', array( $this->notice( 'stored-id', 'Stored' ) ) );

		RemoteNoticeController::getInstance();

		$stored = get_option( self::NOTICE_OPTION );
		$this->assertIsArray( $stored );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'stored-id', $stored[0]->getID() );
	}

	public function test_a_notice_already_registered_is_not_added_again() {
		$existing = NoticeController::addWarning( 'Old text' );
		NoticeController::makePersistent( $existing, 'dup-id', MONTH_IN_SECONDS );
		$this->mock->setKnob(
			'notices',
			array(
				$this->notice( 'dup-id', 'New text', 'error' ),
				$this->notice( 'fresh-id', 'Fresh' ),
			)
		);

		RemoteNoticeController::getInstance();

		$notices = $this->notices();
		$this->assertCount( 2, $notices );
		$this->assertSame( $existing, $notices[0] );
		$this->assertSame( 'Old text', $notices[0]->message );
		$this->assertSame( NoticeModel::NOTICE_WARNING, $notices[0]->messageType );
		$this->assertSame( 'fresh-id', $notices[1]->getID() );
	}

	public function test_non_array_body_adds_nothing() {
		foreach ( array( 'not json', '{"id":"x","message":"object, not array"}', '' ) as $body ) {
			delete_transient( self::TRANSIENT );
			$this->mock->setKnob( 'notices', $body );

			$this->next_request();

			$this->assertSame( array(), $this->notices(), 'Body: ' . $body );
			$this->assertSame( 'true', get_transient( self::TRANSIENT ) );
		}
		$this->assertCount( 3, $this->mock->requestsTo( 'notices.php' ) );
	}

	public function test_empty_array_adds_nothing() {
		RemoteNoticeController::getInstance();

		$this->assertSame( array(), $this->notices() );
		$this->assertCount( 1, $this->mock->requestsTo( 'notices.php' ) );
	}

	/*
	 * Transient semantics.
	 */

	/**
	 * After a successful fetch the transient holds the string 'true' instead
	 * of the fetched notices, so the notices are only processed on the
	 * request that fetched them: for a day, every later request reads 'true'
	 * (not an array), makes no call and adds nothing, and a notice that was
	 * removed from the notices store in between is not restored. The
	 * transient should hold the decoded notices (or 'none').
	 *
	 * Where: RemoteNoticeController::get_remote_notices().
	 *
	 * How to recognise the fix: the transient holds an array and the second
	 * request re-adds the notice from it.
	 */
	public function test_successful_fetch_caches_the_string_true_pinned_for_deferred_fix() {
		$this->mock->setKnob( 'notices', array( $this->notice( 'remote-7', 'Seven' ) ) );
		RemoteNoticeController::getInstance();
		$this->assertCount( 1, $this->notices() );

		$transient = get_transient( self::TRANSIENT );
		$this->assertIsString( $transient );
		$this->assertSame( 'true', $transient );

		delete_option( self::NOTICE_OPTION );
		$this->mock->setKnob( 'notices', array( $this->notice( 'remote-8', 'Eight' ) ) );
		$this->next_request();

		$this->assertCount( 1, $this->mock->requestsTo( 'notices.php' ) );
		$this->assertSame( array(), $this->notices() );
	}

	/**
	 * After a WP_Error the method calls set_transient( ..., false ). The
	 * docblock says this equals "no transient" so the next request fetches
	 * again, but without a persistent object cache the transient is stored
	 * in the options table and comes back as the empty string '', which is
	 * neither false nor 'none': for a day no request fetches and the method
	 * returns '' (nothing is added). With a persistent object cache the
	 * stored false reads back as false and every request fetches again. The
	 * result of a failed fetch depends on the cache backend; it should store
	 * an explicit marker with a deliberate retry policy.
	 *
	 * Where: RemoteNoticeController::get_remote_notices(), the WP_Error branch.
	 *
	 * How to recognise the fix: get_transient() no longer returns the empty
	 * string after a failed fetch.
	 */
	public function test_failed_fetch_stores_an_empty_string_pinned_for_deferred_fix() {
		$this->mock->setKnob( 'wpErrorMessage', 'remote down' );

		RemoteNoticeController::getInstance();

		$this->assertCount( 1, $this->mock->requestsTo( 'notices.php' ) );
		$this->assertSame( array(), $this->notices() );
		$transient = get_transient( self::TRANSIENT );
		$this->assertIsString( $transient );
		$this->assertSame( '', $transient );

		$this->mock->setKnob( 'wpErrorMessage', null );
		$this->mock->setKnob( 'notices', array( $this->notice( 'after-error', 'Back' ) ) );
		$this->next_request();

		$this->assertCount( 1, $this->mock->requestsTo( 'notices.php' ) );
		$this->assertSame( array(), $this->notices() );
	}

	public function test_once_the_transient_expires_the_next_request_fetches_again() {
		$this->mock->setKnob( 'wpErrorMessage', 'remote down' );
		RemoteNoticeController::getInstance();

		delete_transient( self::TRANSIENT );
		$this->mock->setKnob( 'wpErrorMessage', null );
		$this->mock->setKnob( 'notices', array( $this->notice( 'after-error', 'Back' ) ) );
		$this->next_request();

		$this->assertCount( 2, $this->mock->requestsTo( 'notices.php' ) );
		$this->assertSame( 'true', get_transient( self::TRANSIENT ) );
		$this->assertCount( 1, $this->notices() );
		$this->assertSame( 'after-error', $this->notices()[0]->getID() );
	}

	public function test_registered_notices_survive_the_next_request() {
		$this->mock->setKnob( 'notices', array( $this->notice( 'keeps', 'Persistent' ) ) );
		RemoteNoticeController::getInstance();

		$this->next_request();

		$notices = $this->notices();
		$this->assertCount( 1, $notices );
		$this->assertSame( 'keeps', $notices[0]->getID() );
		$this->assertTrue( $notices[0]->isPersistent() );
	}

	/*
	 * The entry guard.
	 */

	/**
	 * The guard `! isset(id) && ! isset(message)` ends with `return`, so an
	 * entry missing both fields stops the loop and every later, valid entry
	 * is dropped. A bad entry should be skipped with `continue`.
	 *
	 * Where: RemoteNoticeController::doRemoteNotices().
	 *
	 * How to recognise the fix: the notice 'after-bad' is registered and
	 * the list holds two notices.
	 */
	public function test_entry_without_id_and_message_stops_the_loop_pinned_for_deferred_fix() {
		$this->mock->setKnob(
			'notices',
			array(
				$this->notice( 'before-bad', 'Before' ),
				array( 'type' => 'warning' ),
				$this->notice( 'after-bad', 'After' ),
			)
		);

		RemoteNoticeController::getInstance();

		$notices = $this->notices();
		$this->assertCount( 1, $notices );
		$this->assertSame( 'before-bad', $notices[0]->getID() );
		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'after-bad' ) );
	}

	/**
	 * The guard uses `&&`, so an entry with an id but no message passes it
	 * and esc_html() reads the undefined `message` property: a warning on
	 * PHP 8 (a notice on 7.4), which the test harness turns into an
	 * exception. The guard should use `||` and skip the entry.
	 *
	 * Where: RemoteNoticeController::doRemoteNotices().
	 *
	 * How to recognise the fix: no warning / notice is raised.
	 */
	public function test_entry_with_id_only_reads_an_undefined_property_pinned_for_deferred_fix() {
		$this->mock->setKnob( 'notices', array( array( 'id' => 'id-only' ) ) );

		$this->assert_undefined_property( 'message' );
	}

	/**
	 * An entry with a message but no id passes the `&&` guard and
	 * sanitize_text_field() reads the undefined `id` property (a warning on
	 * PHP 8, a notice on 7.4, an exception under the test harness).
	 *
	 * Where: RemoteNoticeController::doRemoteNotices().
	 *
	 * How to recognise the fix: no warning / notice is raised.
	 */
	public function test_entry_with_message_only_reads_an_undefined_property_pinned_for_deferred_fix() {
		$this->mock->setKnob( 'notices', array( array( 'message' => 'Message only' ) ) );

		$this->assert_undefined_property( 'id' );
	}

	/**
	 * What a production site (warnings not fatal) does with a message-only
	 * entry: the id becomes '', and makePersistent() then finds the brand-new,
	 * still id-less notice through getNoticeByID('') (null == '') and removes
	 * it as a duplicate, so the notice is silently dropped.
	 *
	 * Where: RemoteNoticeController::doRemoteNotices() with
	 * NoticeController::makePersistent().
	 *
	 * How to recognise the fix: entries without an id are skipped before
	 * any notice is created (the `newNotices` list stays empty).
	 */
	public function test_message_only_entry_is_created_then_dropped_pinned_for_deferred_fix() {
		$this->mock->setKnob( 'notices', array( array( 'message' => 'Message only' ) ) );

		$previous = error_reporting( E_ALL & ~E_WARNING & ~E_NOTICE );
		try {
			RemoteNoticeController::getInstance();
		} finally {
			error_reporting( $previous );
		}

		$this->assertSame( array(), $this->notices() );
		$new = NoticeController::getInstance()->getNewNotices();
		$this->assertCount( 1, $new );
		$this->assertSame( 'Message only', $new[0]->message );
		$this->assertNull( $new[0]->getID() );
	}

	/**
	 * Builds the controller and asserts the build raised the harness'
	 * exception for reading an undefined property of a notice entry.
	 */
	private function assert_undefined_property( $property ) {
		$error = null;
		try {
			RemoteNoticeController::getInstance();
		} catch ( \PHPUnit\Framework\Error\Error $e ) {
			$error = $e;
		}
		$this->assertInstanceOf( PHP_VERSION_ID >= 80000 ? \PHPUnit\Framework\Error\Warning::class : \PHPUnit\Framework\Error\Notice::class, $error );
		$this->assertMatchesRegularExpression( '/Undefined property: stdClass::\$' . $property . '$/', $error->getMessage() );
		$this->assertSame( array(), $this->notices() );
	}
}
