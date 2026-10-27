<?php
/**
 * Tests for the AJAX dismiss endpoint of
 * EnableMediaReplace\Notices\NoticeController::ajax_action(), registered as
 * `wp_ajax_EnableMediaReplace-notices`.
 *
 * ajax_action() ends in wp_send_json(); WP_Ajax_UnitTestCase turns the die
 * into an exception and keeps the JSON in $this->_last_response, so no
 * exit() is reached. The controller's static state and the notices option
 * are snapshotted and restored like in test-NoticeController.php.
 *
 * _handleAjax() fires admin_init first, where WordPress core schedules its
 * update checks (HTTP to wordpress.org). The admin_init chain is dropped
 * for these tests (the hooks are restored by WP_UnitTestCase) and every
 * HTTP request is refused, so nothing reaches the network.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Notices\NoticeController;
use EnableMediaReplace\Notices\NoticeModel;

class NoticeControllerAjaxTest extends WP_Ajax_UnitTestCase {

	const OPTION = 'EnableMediaReplace-notices';

	/**
	 * Static properties of NoticeController before the test.
	 *
	 * @var array
	 */
	private $saved_statics = array();

	/**
	 * The notices option before the test (null when absent).
	 *
	 * @var mixed
	 */
	private $saved_option;

	/**
	 * Snapshot of $_POST.
	 *
	 * @var array
	 */
	private $saved_post;

	public function set_up() {
		parent::set_up();
		foreach ( array( 'notices', 'instance', 'cssHookLoaded', 'newNotices' ) as $name ) {
			$this->saved_statics[ $name ] = $this->get_static( $name );
		}
		$this->saved_option = get_option( self::OPTION, null );
		$this->saved_post   = $_POST;
		delete_option( self::OPTION );
		$this->set_static( 'instance', null );
		$this->set_static( 'notices', array() );
		$this->set_static( 'newNotices', array() );
		$this->set_static( 'cssHookLoaded', false );
		$this->_setRole( 'administrator' );
		remove_all_actions( 'admin_init' );
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 9999 );
		NoticeController::getInstance();
	}

	/**
	 * Refuses every HTTP request. Public because WordPress calls it.
	 */
	public function block_http() {
		return new WP_Error( 'emr_test_no_network', 'HTTP is blocked in this test.' );
	}

	public function tear_down() {
		foreach ( $this->saved_statics as $name => $value ) {
			$this->set_static( $name, $value );
		}
		if ( null === $this->saved_option ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $this->saved_option );
		}
		$_POST = $this->saved_post;
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), 9999 );
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

	/**
	 * Posts to the endpoint and returns the decoded JSON response.
	 */
	private function dismiss_request( array $post ) {
		$_POST = $post;
		try {
			$this->_handleAjax( self::OPTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		return json_decode( $this->_last_response, true );
	}

	private function persistent( $message, $key, $suppress = HOUR_IN_SECONDS ) {
		$notice = NoticeController::addWarning( $message );
		NoticeController::makePersistent( $notice, $key, $suppress );
		return $notice;
	}

	public function test_wrong_nonce_is_refused() {
		$notice = $this->persistent( 'guarded', 'guard-key' );

		$response = $this->dismiss_request(
			array(
				'nonce'         => 'not-a-nonce',
				'plugin_action' => 'dismiss',
				'id'            => 'guard-key',
			)
		);

		$this->assertSame( array( 'result' => false, 'reason' => 'wrong nonce' ), $response );
		$this->assertFalse( $notice->isDismissed() );
	}

	public function test_dismiss_marks_the_persistent_notice_dismissed_and_stores_it() {
		$notice = $this->persistent( 'dismiss me', 'dismiss-key' );

		$response = $this->dismiss_request(
			array(
				'nonce'         => wp_create_nonce( 'dismiss' ),
				'plugin_action' => 'dismiss',
				'id'            => 'dismiss-key',
			)
		);

		$this->assertSame( array( 'result' => true, 'reason' => '', 'dismissed' => true ), $response );
		$this->assertTrue( $notice->isDismissed() );
		$stored = get_option( self::OPTION );
		$this->assertCount( 1, $stored );
		$this->assertTrue( $stored[0]->isDismissed() );
	}

	public function test_remove_type_deletes_the_notice() {
		$keep = NoticeController::addNormal( 'keep' );
		$this->persistent( 'remove me', 'remove-key' );

		$response = $this->dismiss_request(
			array(
				'nonce'         => wp_create_nonce( 'dismiss' ),
				'plugin_action' => 'dismiss',
				'dismisstype'   => 'remove',
				'id'            => 'remove-key',
			)
		);

		$this->assertSame( array( 'result' => true, 'reason' => '', 'removed' => true ), $response );
		$this->assertSame( array( $keep ), NoticeController::getInstance()->getNotices() );
		$this->assertCount( 1, get_option( self::OPTION ) );
	}

	public function test_unknown_id_reports_not_found() {
		$this->persistent( 'other', 'other-key' );

		$response = $this->dismiss_request(
			array(
				'nonce'         => wp_create_nonce( 'dismiss' ),
				'plugin_action' => 'dismiss',
				'id'            => 'missing-key',
			)
		);

		$this->assertSame( array( 'result' => false, 'reason' => ' Notice missing-key not found. ' ), $response );
	}

	/**
	 * A valid nonce with a plugin_action other than 'dismiss' does nothing
	 * and answers result false with an empty reason.
	 */
	public function test_other_plugin_action_does_nothing() {
		$notice = $this->persistent( 'untouched', 'untouched-key' );

		$response = $this->dismiss_request(
			array(
				'nonce'         => wp_create_nonce( 'dismiss' ),
				'plugin_action' => 'something-else',
				'id'            => 'untouched-key',
			)
		);

		$this->assertSame( array( 'result' => false, 'reason' => '' ), $response );
		$this->assertFalse( $notice->isDismissed() );
	}

	/**
	 * An empty posted id is looked up with getNoticeByID(''), which matches
	 * the first notice without an ID (loose comparison with null), so the
	 * request dismisses an unrelated, non-persistent notice and reports
	 * success. The handler should refuse an empty id.
	 *
	 * Where: NoticeController::ajax_action() and getNoticeByID().
	 *
	 * How to recognise the fix: the response has result false and the
	 * plain notice stays undismissed.
	 */
	public function test_empty_id_dismisses_an_unrelated_notice_pinned_for_deferred_fix() {
		$plain = NoticeController::addError( 'unrelated plain notice' );
		$this->persistent( 'the persistent one', 'real-key' );

		$response = $this->dismiss_request(
			array(
				'nonce'         => wp_create_nonce( 'dismiss' ),
				'plugin_action' => 'dismiss',
				'id'            => '',
			)
		);

		$this->assertSame( array( 'result' => true, 'reason' => '', 'dismissed' => true ), $response );
		$this->assertTrue( $plain->isDismissed() );
		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'real-key' )->isDismissed() );
	}
}
