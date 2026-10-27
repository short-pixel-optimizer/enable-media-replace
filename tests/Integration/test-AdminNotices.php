<?php
/**
 * Integration tests: EMR's admin notices end to end on the media screens.
 *
 * Remote notices: EnableMediaReplacePlugin::setScreen() on a media screen
 * -> RemoteNoticeController fetches notices.php from MockEmrApi -> the
 * notices module stores them as persistent notices in the
 * `EnableMediaReplace-notices` option -> NoticeController::admin_notices()
 * prints them on `admin_notices` with the icons setScreen() loaded. The
 * dismiss button posts to `wp_ajax_EnableMediaReplace-notices`, which is
 * driven here through the admin-ajax dispatch, so the class extends
 * EMR_AjaxTestCase rather than EMR_IntegrationTestCase (the admin_init
 * chain it drops is replaced by an explicit emr()->adminInit()).
 *
 * The feature notice: route() queues the persistent EMR001 notice while
 * the EMR page renders, which is after `admin_notices` has fired, so it
 * shows on the next page load.
 *
 * "Next request" is simulated by dropping everything PHP keeps in memory
 * for one request: the NoticeController instance and its static notice
 * lists, the RemoteNoticeController instance, the notice icons and the
 * notice hooks. What survives is what the database holds (the notices
 * option and the `emr_remote_notice` transient), as between two real
 * requests.
 *
 * The failed fetch and the 'true' transient are pinned in
 * tests/Api/test-RemoteNoticeController.php and not repeated here.
 *
 * Snapshot/restore: base class (EMR options, singletons, mock, transient
 * cleared) plus the NoticeController statics, NoticeModel::$icons, the
 * feature flags, $_GET/$_POST/$_REQUEST, the screen and page globals,
 * the script and style registries, the current user.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Notices\NoticeController;
use EnableMediaReplace\Notices\NoticeModel;
use EnableMediaReplace\UIHelper;

class AdminNoticesTest extends EMR_AjaxTestCase {

	const OPTION    = 'EnableMediaReplace-notices';
	const PAGE_SLUG = 'enable-media-replace/enable-media-replace.php';
	const EMR_PAGE  = 'media_page_enable-media-replace/enable-media-replace';

	/** @var array */
	private $savedNoticeStatics = array();

	/** @var array */
	private $savedIcons = array();

	/** @var array|null */
	private $savedFeatures;

	/** @var array */
	private $savedRequest = array();

	/** @var array name => array( value ) | null when unset */
	private $savedGlobals = array();

	/** @var int */
	private $savedUser = 0;

	/** @var array Filters added by a test: array( hook, callback ). */
	private $addedFilters = array();

	public function set_up() {
		parent::set_up();
		foreach ( array( 'instance', 'notices', 'newNotices', 'cssHookLoaded' ) as $name ) {
			$this->savedNoticeStatics[ $name ] = $this->noticeStatic( $name )->getValue();
		}
		$this->savedIcons    = NoticeModel::$icons;
		$this->savedFeatures = $this->featuresProperty()->getValue( emr() );
		$this->savedRequest  = array( $_GET, $_POST, $_REQUEST );
		foreach ( array( 'current_screen', 'hook_suffix', 'plugin_page', 'wp_scripts', 'wp_styles' ) as $name ) {
			$this->savedGlobals[ $name ] = array_key_exists( $name, $GLOBALS ) ? array( $GLOBALS[ $name ] ) : null;
		}
		$this->savedUser = get_current_user_id();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		$this->_setRole( 'administrator' );
		$this->api->setKnob(
			'notices',
			array(
				array(
					'id'      => 'emr-x1',
					'message' => 'Hello <b>x</b>',
					'type'    => 'warning',
				),
				array(
					'id'      => 'emr-x2',
					'message' => 'Second',
					'type'    => 'error',
				),
				array(
					'id'      => 'emr-x3',
					'message' => 'Third',
				),
			)
		);
		$this->nextRequest();
		emr()->adminInit();
	}

	public function tear_down() {
		foreach ( $this->addedFilters as $filter ) {
			remove_filter( $filter[0], $filter[1] );
		}
		$this->addedFilters = array();
		foreach ( $this->savedNoticeStatics as $name => $value ) {
			$this->noticeStatic( $name )->setValue( null, $value );
		}
		NoticeModel::$icons = $this->savedIcons;
		$this->featuresProperty()->setValue( emr(), $this->savedFeatures );
		list( $_GET, $_POST, $_REQUEST ) = $this->savedRequest;
		foreach ( $this->savedGlobals as $name => $saved ) {
			if ( null === $saved ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = $saved[0];
			}
		}
		wp_set_current_user( $this->savedUser );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Plumbing.
	// ---------------------------------------------------------------------

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

	/** Forgets everything PHP holds in memory for one request (see the class docblock). */
	private function nextRequest() {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'admin_print_footer_scripts' );
		remove_all_actions( 'wp_ajax_' . self::OPTION );
		$this->noticeStatic( 'instance' )->setValue( null, null );
		$this->noticeStatic( 'notices' )->setValue( null, array() );
		$this->noticeStatic( 'newNotices' )->setValue( null, array() );
		$this->noticeStatic( 'cssHookLoaded' )->setValue( null, false );
		NoticeModel::$icons = array();
		$this->resetSingleton( 'EnableMediaReplace\Controller\RemoteNoticeController' );
		$GLOBALS['current_screen'] = null;
		// plugin_actions() loads the controller (and its dismiss hook) on every admin request.
		NoticeController::getInstance();
	}

	/** Makes $screen current and runs EMR's current_screen callback. */
	private function onScreen( $screen ) {
		$GLOBALS['hook_suffix'] = $screen;
		set_current_screen();
		$this->assertSame( $screen, get_current_screen()->id, 'Sentinel: the screen is set.' );
		emr()->setScreen();
	}

	/** Output of the admin_notices action. */
	private function renderNotices() {
		ob_start();
		do_action( 'admin_notices' );
		return (string) ob_get_clean();
	}

	/** The ids of the notice divs in $html, in order. */
	private function renderedIds( $html ) {
		preg_match_all( '/<div id="([^"]+)" class="shortpixel shortpixel-notice /', $html, $matches );
		return $matches[1];
	}

	/** Posts the notice dismiss button for $id. */
	private function dismiss( $id ) {
		$_POST = array(
			'nonce'         => wp_create_nonce( 'dismiss' ),
			'plugin_action' => 'dismiss',
			'id'            => $id,
		);
		return $this->doAjax( self::OPTION );
	}

	/** Runs route() on the EMR page for a replace screen without attachment (no exit, no controller). */
	private function routeEmrPage() {
		$GLOBALS['plugin_page'] = self::PAGE_SLUG;
		$_GET                   = array(
			'page'   => self::PAGE_SLUG,
			'action' => 'media_replace',
		);
		emr()->route();
	}

	/** Stored notices with the given id. */
	private function storedWithId( $id ) {
		return array_values(
			array_filter(
				(array) get_option( self::OPTION, array() ),
				function ( $notice ) use ( $id ) {
					return $notice->getID() === $id;
				}
			)
		);
	}

	// ---------------------------------------------------------------------
	// Remote notices.
	// ---------------------------------------------------------------------

	public function test_the_media_library_screen_fetches_the_remote_notices_once_with_version_plugin_and_target() {
		$this->onScreen( 'upload' );

		$requests = $this->api->requestsTo( 'notices.php' );
		$this->assertCount( 1, $requests );
		$this->assertCount( 1, $this->api->getRequests(), 'No other API call.' );
		$this->assertSame( 'GET', $requests[0]['method'] );
		$this->assertSame(
			array(
				'version' => EMR_VERSION,
				'plugin'  => 'enable-media-replace',
				'target'  => '4',
			),
			$requests[0]['request']
		);
		$this->assertSame( 'true', get_transient( 'emr_remote_notice' ) );
	}

	public function test_the_fetched_notices_are_stored_as_persistent_notices_of_their_types() {
		$this->onScreen( 'upload' );
		$this->nextRequest();

		$controller = NoticeController::getInstance();
		$types      = array();
		foreach ( array( 'emr-x1', 'emr-x2', 'emr-x3' ) as $id ) {
			$notice = $controller->getNoticeByID( $id );
			$this->assertInstanceOf( NoticeModel::class, $notice, "$id is loaded from the option." );
			$this->assertTrue( $notice->isPersistent() );
			$this->assertFalse( $notice->isDismissed() );
			$types[ $id ] = $notice->messageType;
		}
		$this->assertSame(
			array(
				'emr-x1' => NoticeModel::NOTICE_WARNING,
				'emr-x2' => NoticeModel::NOTICE_ERROR,
				'emr-x3' => NoticeModel::NOTICE_NORMAL,
			),
			$types
		);
		$this->assertSame( 'Hello &lt;b&gt;x&lt;/b&gt;', $controller->getNoticeByID( 'emr-x1' )->message, 'Escaped at fetch time.' );
		$this->assertCount( 3, get_option( self::OPTION ) );
	}

	public function test_admin_notices_prints_the_three_with_literal_markup_type_classes_and_icons() {
		$this->onScreen( 'upload' );

		$html = $this->renderNotices();

		$this->assertSame( array( 'emr-x1', 'emr-x2', 'emr-x3' ), $this->renderedIds( $html ) );
		$this->assertStringContainsString( 'Hello &lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'Hello <b>x</b>', $html );
		$scared = plugins_url( 'img/notices/robo-scared.png', EMR_ROOT_FILE );
		$slider = plugins_url( 'img/notices/slider.png', EMR_ROOT_FILE );
		$this->assertMatchesRegularExpression( '/<div id="emr-x1" class="[^"]*notice-warning[^"]*"><span class="icon"><img class="emr-notice-icon" src="' . preg_quote( $scared, '/' ) . '"><\/span>/', $html );
		$this->assertMatchesRegularExpression( '/<div id="emr-x2" class="[^"]*notice-error[^"]*"><span class="icon"><img class="emr-notice-icon" src="' . preg_quote( $scared, '/' ) . '"><\/span>/', $html );
		$this->assertMatchesRegularExpression( '/<div id="emr-x3" class="[^"]*notice-info[^"]*"><span class="icon"><img class="emr-notice-icon" src="' . preg_quote( $slider, '/' ) . '"><\/span><span class="content">Third/', $html );
	}

	public function test_a_second_set_screen_in_the_same_request_fetches_nothing_and_each_notice_prints_once() {
		$this->onScreen( 'upload' );
		emr()->setScreen();

		$html = $this->renderNotices();

		$this->assertCount( 1, $this->api->requestsTo( 'notices.php' ) );
		$this->assertSame( array( 'emr-x1', 'emr-x2', 'emr-x3' ), $this->renderedIds( $html ) );
		$this->assertCount( 3, get_option( self::OPTION ), 'No duplicate stored.' );
	}

	public function test_dismissing_one_notice_through_admin_ajax_hides_it_from_the_next_render() {
		$this->onScreen( 'upload' );
		$this->renderNotices();
		$this->nextRequest();

		$response = $this->dismiss( 'emr-x2' );
		$this->nextRequest();
		$this->onScreen( 'upload' );
		$html = $this->renderNotices();

		$this->assertEquals(
			(object) array(
				'result'    => true,
				'reason'    => '',
				'dismissed' => true,
			),
			$response
		);
		$this->assertSame( array( 'emr-x1', 'emr-x3' ), $this->renderedIds( $html ) );
		$this->assertCount( 1, $this->api->requestsTo( 'notices.php' ), 'The later request is throttled by the transient.' );
		$stored = $this->storedWithId( 'emr-x2' );
		$this->assertCount( 1, $stored );
		$this->assertTrue( $stored[0]->isDismissed() );
	}

	public function test_a_dismissed_remote_notice_stays_hidden_when_the_fetch_runs_again() {
		$this->onScreen( 'upload' );
		$this->nextRequest();
		$this->dismiss( 'emr-x2' );
		delete_transient( 'emr_remote_notice' ); // a day later.
		$this->nextRequest();

		$this->onScreen( 'upload' );
		$html = $this->renderNotices();

		$this->assertCount( 2, $this->api->requestsTo( 'notices.php' ) );
		$this->assertSame( array( 'emr-x1', 'emr-x3' ), $this->renderedIds( $html ) );
		$this->assertCount( 1, $this->storedWithId( 'emr-x2' ), 'Not added a second time.' );
		$this->assertCount( 3, get_option( self::OPTION ) );
	}

	public function test_with_the_remote_notice_feature_off_nothing_is_fetched_and_admin_notices_is_not_hooked() {
		add_filter( 'emr/feature/remote_notice', '__return_false' );
		$this->addedFilters[] = array( 'emr/feature/remote_notice', '__return_false' );
		emr()->adminInit();

		$this->onScreen( 'upload' );

		$this->assertSame( array(), $this->api->getRequests() );
		$this->assertFalse( has_action( 'admin_notices', array( NoticeController::getInstance(), 'admin_notices' ) ) );
		$this->assertSame( '', $this->renderNotices() );
		$this->assertFalse( get_option( self::OPTION ) );
	}

	public function test_the_dashboard_fetches_nothing_and_hooks_nothing() {
		$this->onScreen( 'dashboard' );

		$this->assertSame( array(), $this->api->getRequests() );
		$this->assertFalse( has_action( 'admin_notices', array( NoticeController::getInstance(), 'admin_notices' ) ) );
		$this->assertSame( array(), NoticeModel::$icons );
		$this->assertFalse( get_transient( 'emr_remote_notice' ) );
	}

	// ---------------------------------------------------------------------
	// The feature notice (EMR001).
	// ---------------------------------------------------------------------

	public function test_the_feature_notice_queued_by_route_prints_on_the_next_page() {
		$this->api->setKnob( 'notices', array() );
		$this->onScreen( self::EMR_PAGE );
		$first = $this->renderNotices();
		$this->routeEmrPage();
		$this->nextRequest();

		$this->onScreen( self::EMR_PAGE );
		$next = $this->renderNotices();

		$this->assertSame( array(), $this->renderedIds( $first ), 'Not on the page that queued it.' );
		$this->assertSame( array( UIHelper::NOTICE_NEW_FEATURE ), $this->renderedIds( $next ) );
		$this->assertStringContainsString( '<h3> New Beta Feature! </h3>', $next );
		$this->assertMatchesRegularExpression( '/class="[^"]*notice-info[^"]*is-dismissible[^"]*is-persistent/', $next );
	}

	public function test_the_feature_notice_is_reloaded_from_the_option_and_not_duplicated_by_later_routes() {
		$this->api->setKnob( 'notices', array() );
		$this->routeEmrPage();
		$this->nextRequest();
		$this->routeEmrPage();
		$this->nextRequest();

		$this->onScreen( self::EMR_PAGE );
		$html = $this->renderNotices();

		$this->assertCount( 1, $this->storedWithId( UIHelper::NOTICE_NEW_FEATURE ) );
		$this->assertCount( 1, get_option( self::OPTION ), 'Only the feature notice is stored.' );
		$this->assertSame( array( UIHelper::NOTICE_NEW_FEATURE ), $this->renderedIds( $html ) );
	}

	public function test_a_dismissed_feature_notice_stays_hidden_on_later_pages() {
		$this->api->setKnob( 'notices', array() );
		$this->routeEmrPage();
		$this->nextRequest();
		$response = $this->dismiss( UIHelper::NOTICE_NEW_FEATURE );
		$this->nextRequest();
		$this->routeEmrPage();
		$this->nextRequest();

		$this->onScreen( self::EMR_PAGE );
		$html = $this->renderNotices();

		$this->assertTrue( $response->dismissed );
		$this->assertSame( array(), $this->renderedIds( $html ) );
		$stored = $this->storedWithId( UIHelper::NOTICE_NEW_FEATURE );
		$this->assertCount( 1, $stored );
		$this->assertTrue( $stored[0]->isDismissed() );
	}
}
