<?php
/**
 * Integration tests: the date handling of the replace flow, end to end.
 *
 * Driven through the REAL UploadViewController::load() (POST, nonce,
 * capability and permission checks, getPost(), setView(), ReplaceController,
 * updateSettings(), viewSuccess()) by way of EMR_TestUploadViewController from
 * tests/ViewController/ViewControllerTestDoubles.php: its getUpload() returns
 * a temporary copy of the upload instead of reading `$_FILES` (the only part
 * not exercised is is_uploaded_file()), and the exiting views throw
 * EMR_ViewExit instead of rendering and calling exit().
 *
 * The site runs in Europe/Bucharest (UTC+2, UTC+3 in summer) so that every
 * GMT conversion is visible; `timezone_string`, `gmt_offset`, the
 * superglobals and the current user are restored in tear_down.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ReplaceFlowHelpers.php';
require_once dirname( __DIR__ ) . '/ViewController/ViewControllerTestDoubles.php';

use EnableMediaReplace\Controller\ReplaceController;

class ReplaceDatesTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;

	/** @var array Superglobals saved in set_up. */
	private $savedGlobals = array();

	/** @var array Options saved in set_up. */
	private $savedOptions = array();

	public function set_up() {
		parent::set_up();
		$this->flowSetUp();
		$this->savedGlobals = array( $_POST, $_GET, $_REQUEST, $_FILES );
		foreach ( array( 'timezone_string', 'gmt_offset' ) as $option ) {
			$this->savedOptions[ $option ] = get_option( $option );
		}
		update_option( 'timezone_string', 'Europe/Bucharest' );
		update_option( 'gmt_offset', '' );
	}

	public function tear_down() {
		list( $_POST, $_GET, $_REQUEST, $_FILES ) = $this->savedGlobals;
		foreach ( $this->savedOptions as $option => $value ) {
			update_option( $option, $value );
		}
		$this->flowTearDown();
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/**
	 * A 400x300 JPEG attachment whose dates are set to a known moment in
	 * the past (2015-05-05 10:00 local, 07:00 GMT).
	 */
	private function datedImage() {
		global $wpdb;
		$id = $this->uploadJpeg( 'emr-dates-' . $this->token() . '.jpg', 400, 300 );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'         => '2015-05-05 10:00:00',
				'post_date_gmt'     => '2015-05-05 07:00:00',
				'post_modified'     => '2015-05-05 10:00:00',
				'post_modified_gmt' => '2015-05-05 07:00:00',
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		$this->assertSame( '2015-05-05 10:00:00', get_post( $id )->post_date, 'Sentinel: the dates are in the past.' );
		return $id;
	}

	/** Posts the replace form for $id with $fields over a valid default. */
	private function postForm( $id, array $fields = array() ) {
		$_POST    = array_merge(
			array(
				'emr_nonce'         => wp_create_nonce( 'media_replace_upload' ),
				'ID'                => (string) $id,
				'replace_type'      => 'replace',
				'timestamp_replace' => (string) ReplaceController::TIME_UPDATEMODIFIED,
			),
			$fields
		);
		$_REQUEST = $_POST;
	}

	/**
	 * Runs the real load() with a temporary upload and returns the
	 * controller and the view exit it ended in.
	 *
	 * @return array( EMR_TestUploadViewController, EMR_ViewExit )
	 */
	private function load( $upload_file, $upload_name = 'upload.jpg' ) {
		$controller             = new EMR_TestUploadViewController();
		$controller->fakeUpload = array( $upload_file, $upload_name );
		try {
			$controller->load();
		} catch ( EMR_ViewExit $exit ) {
			return array( $controller, $exit );
		}
		$this->fail( 'load() returned instead of ending at a view.' );
	}

	/** Loads and asserts the success view. */
	private function loadSuccess( $upload_file, $upload_name = 'upload.jpg' ) {
		list( $controller, $exit ) = $this->load( $upload_file, $upload_name );
		$this->assertSame( 'success', $exit->template, 'The flow ends on the success view (error ' . var_export( $controller->lastError, true ) . ').' );
		$this->assertTrue( $controller->successCalled );
		return $controller;
	}

	/** GMT of a Bucharest local MySQL date. */
	private function bucharestToGmt( $local ) {
		$date = new DateTime( $local, new DateTimeZone( 'Europe/Bucharest' ) );
		$date->setTimezone( new DateTimeZone( 'UTC' ) );
		return $date->format( 'Y-m-d H:i:s' );
	}

	/** Asserts a MySQL date lies between two others (inclusive). */
	private function assertDateBetween( $from, $to, $actual, $message ) {
		$this->assertGreaterThanOrEqual( strtotime( $from ), strtotime( $actual ), $message );
		$this->assertLessThanOrEqual( strtotime( $to ), strtotime( $actual ), $message );
	}

	/** The output of the edit-screen date box for $id. */
	private function dateBox( $id ) {
		ob_start();
		emr()->admin_date_replaced_media_on_edit_media_screen( get_post( $id ) );
		return ob_get_clean();
	}

	// ---------------------------------------------------------------------
	// TIME_UPDATEALL.
	// ---------------------------------------------------------------------

	public function test_update_all_sets_both_dates_to_now_with_the_site_offset() {
		$id = $this->datedImage();
		$this->postForm( $id, array( 'timestamp_replace' => (string) ReplaceController::TIME_UPDATEALL ) );
		$from = current_time( 'mysql' );

		$this->loadSuccess( $this->tmpJpeg( 400, 300 ) );

		$to   = current_time( 'mysql' );
		$post = get_post( $id );
		$this->assertDateBetween( $from, $to, $post->post_date, 'post_date is now, local time.' );
		$this->assertSame( $post->post_date, $post->post_modified );
		$this->assertSame( $this->bucharestToGmt( $post->post_date ), $post->post_date_gmt, 'post_date_gmt is local minus the Bucharest offset.' );
		$this->assertSame( $post->post_date_gmt, $post->post_modified_gmt );
		$this->assertNotSame( $post->post_date, $post->post_date_gmt, 'Sentinel: the offset is not zero.' );
	}

	// ---------------------------------------------------------------------
	// TIME_UPDATEMODIFIED.
	// ---------------------------------------------------------------------

	public function test_update_modified_keeps_the_post_date_and_sets_modified_to_now() {
		$id = $this->datedImage();
		$this->postForm( $id, array( 'timestamp_replace' => (string) ReplaceController::TIME_UPDATEMODIFIED ) );
		$from = current_time( 'mysql' );

		$this->loadSuccess( $this->tmpJpeg( 400, 300 ) );

		$to   = current_time( 'mysql' );
		$post = get_post( $id );
		$this->assertSame( '2015-05-05 10:00:00', $post->post_date );
		$this->assertSame( '2015-05-05 07:00:00', $post->post_date_gmt );
		$this->assertDateBetween( $from, $to, $post->post_modified, 'post_modified is now, local time.' );
		$this->assertSame( $this->bucharestToGmt( $post->post_modified ), $post->post_modified_gmt );
	}

	public function test_default_time_mode_is_update_modified() {
		$id = $this->datedImage();
		$this->postForm( $id );
		unset( $_POST['timestamp_replace'] );
		$_REQUEST = $_POST;

		$controller = $this->loadSuccess( $this->tmpJpeg( 400, 300 ) );

		$this->assertSame( '2015-05-05 10:00:00', get_post( $id )->post_date );
		$this->assertNotSame( '2015-05-05 10:00:00', get_post( $id )->post_modified );
		$this->assertSame( ReplaceController::TIME_UPDATEMODIFIED, get_option( 'enable_media_replace' )['timestamp_replace'] );
		$this->assertTrue( $controller->successCalled );
	}

	public function test_revised_box_shows_the_modified_date_after_update_modified() {
		$id = $this->datedImage();
		$this->postForm( $id );

		$this->loadSuccess( $this->tmpJpeg( 400, 300 ) );

		$post = get_post( $id );
		$html = $this->dateBox( $id );
		$this->assertStringContainsString( 'Revised', $html );
		$this->assertStringContainsString( '<b>' . esc_html( date_i18n( __( 'M j, Y @ H:i' ), strtotime( $post->post_modified ) ) ) . '</b>', $html );
		$this->assertStringNotContainsString( 'Replaced By', $html, 'The admin is the author.' );
	}

	// ---------------------------------------------------------------------
	// TIME_CUSTOM.
	// ---------------------------------------------------------------------

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:string,4:string}>
	 *         date field, hour, minute, expected local, expected GMT.
	 */
	public function customDates() {
		return array(
			'summer (UTC+3)'                => array( '2024-07-15', '9', '5', '2024-07-15 09:05:00', '2024-07-15 06:05:00' ),
			'winter (UTC+2)'                => array( '2024-01-15', '9', '5', '2024-01-15 09:05:00', '2024-01-15 07:05:00' ),
			'before the spring switch'      => array( '2024-03-31', '2', '30', '2024-03-31 02:30:00', '2024-03-31 00:30:00' ),
			'after the spring switch'       => array( '2024-03-31', '5', '0', '2024-03-31 05:00:00', '2024-03-31 02:00:00' ),
			'two-digit fields, end of day'  => array( '2023-12-31', '23', '59', '2023-12-31 23:59:00', '2023-12-31 21:59:00' ),
			'midnight, zero-padded to 00:00' => array( '2022-08-01', '0', '0', '2022-08-01 00:00:00', '2022-07-31 21:00:00' ),
		);
	}

	/**
	 * @dataProvider customDates
	 */
	public function test_custom_date_sets_both_dates_in_local_time_and_gmt_with_the_offset_of_that_date( $day, $hour, $minute, $local, $gmt ) {
		$id = $this->datedImage();
		$this->postForm(
			$id,
			array(
				'timestamp_replace'     => (string) ReplaceController::TIME_CUSTOM,
				'custom_date_formatted' => $day,
				'custom_hour'           => $hour,
				'custom_minute'         => $minute,
			)
		);

		$this->loadSuccess( $this->tmpJpeg( 400, 300 ) );

		$post = get_post( $id );
		$this->assertSame( $local, $post->post_date );
		$this->assertSame( $gmt, $post->post_date_gmt );
		$this->assertSame( $local, $post->post_modified );
		$this->assertSame( $gmt, $post->post_modified_gmt );
		$this->assertSame( $this->bucharestToGmt( $local ), $gmt, 'Sentinel: the expectation matches PHP\'s own conversion.' );
	}

	public function test_custom_dates_inside_and_outside_dst_get_different_offsets() {
		$summer = $this->datedImage();
		$winter = $this->datedImage();
		foreach ( array( $summer => '2024-07-15', $winter => '2024-01-15' ) as $id => $day ) {
			$this->postForm(
				$id,
				array(
					'timestamp_replace'     => (string) ReplaceController::TIME_CUSTOM,
					'custom_date_formatted' => $day,
					'custom_hour'           => '12',
					'custom_minute'         => '00',
				)
			);
			$this->loadSuccess( $this->tmpJpeg( 400, 300 ) );
		}

		$summer_offset = strtotime( get_post( $summer )->post_date ) - strtotime( get_post( $summer )->post_date_gmt );
		$winter_offset = strtotime( get_post( $winter )->post_date ) - strtotime( get_post( $winter )->post_date_gmt );
		$this->assertSame( 3 * HOUR_IN_SECONDS, $summer_offset );
		$this->assertSame( 2 * HOUR_IN_SECONDS, $winter_offset );
	}

	public function test_custom_date_is_saved_with_the_form_choices_into_the_settings_option() {
		update_option( 'enable_media_replace', array( 'bg_type' => 'solid', 'custom_date' => '2001-01-01 01:01:00' ), false );
		$id = $this->datedImage();
		$this->postForm(
			$id,
			array(
				'replace_type'          => 'replace_and_search',
				'timestamp_replace'     => (string) ReplaceController::TIME_CUSTOM,
				'custom_date_formatted' => '2024-07-15',
				'custom_hour'           => '9',
				'custom_minute'         => '5',
			)
		);

		$this->loadSuccess( $this->tmpJpeg( 400, 300 ), 'emr-dated-' . $this->token() . '.jpg' );

		$this->assertSame(
			array(
				'bg_type'           => 'solid',
				'custom_date'       => '2024-07-15 09:05:00',
				'replace_type'      => 'replace_and_search',
				'timestamp_replace' => ReplaceController::TIME_CUSTOM,
				'new_location'      => false,
				'new_location_dir'  => null,
			),
			get_option( 'enable_media_replace' )
		);
	}

	public function test_a_later_non_custom_replace_keeps_the_previous_custom_date_in_the_settings() {
		update_option( 'enable_media_replace', array( 'custom_date' => '2024-07-15 09:05:00' ), false );
		$id = $this->datedImage();
		$this->postForm( $id, array( 'timestamp_replace' => (string) ReplaceController::TIME_UPDATEALL ) );

		$this->loadSuccess( $this->tmpJpeg( 400, 300 ) );

		$settings = get_option( 'enable_media_replace' );
		$this->assertSame( '2024-07-15 09:05:00', $settings['custom_date'] );
		$this->assertSame( ReplaceController::TIME_UPDATEALL, $settings['timestamp_replace'] );
	}

	public function test_revised_box_is_absent_when_the_custom_date_sets_both_dates_alike() {
		$id = $this->datedImage();
		$this->postForm(
			$id,
			array(
				'timestamp_replace'     => (string) ReplaceController::TIME_CUSTOM,
				'custom_date_formatted' => '2024-07-15',
				'custom_hour'           => '9',
				'custom_minute'         => '5',
			)
		);

		$this->loadSuccess( $this->tmpJpeg( 400, 300 ) );

		$this->assertSame( get_post( $id )->post_date, get_post( $id )->post_modified, 'Sentinel: both dates are equal.' );
		$this->assertStringNotContainsString( 'Revised', $this->dateBox( $id ) );
	}

	public function test_invalid_custom_date_stops_with_the_time_error_before_anything_changes() {
		update_option( 'enable_media_replace', array( 'replace_type' => 'replace' ), false );
		$id   = $this->datedImage();
		$path = get_attached_file( $id );
		$md5  = md5_file( $path );
		$meta = wp_get_attachment_metadata( $id );
		$tmp  = $this->tmpJpeg( 600, 400 );
		$this->postForm(
			$id,
			array(
				'timestamp_replace'     => (string) ReplaceController::TIME_CUSTOM,
				'custom_date_formatted' => 'next tuesday',
				'custom_hour'           => '9',
				'custom_minute'         => '5',
			)
		);

		list( $controller, $exit ) = $this->load( $tmp );

		$this->assertSame( 'error', $exit->template );
		$this->assertSame( EMR_TestUploadViewController::ERROR_TIME, $controller->lastError );
		$this->assertSame( 0, $controller->uploadCalls, 'The upload is never read.' );
		$this->assertSame( $md5, md5_file( $path ) );
		$this->assertSame( $meta, wp_get_attachment_metadata( $id ) );
		$this->assertSame( '2015-05-05 10:00:00', get_post( $id )->post_date );
		$this->assertSame( '2015-05-05 10:00:00', get_post( $id )->post_modified );
		$this->assertSame( array( 'replace_type' => 'replace' ), get_option( 'enable_media_replace' ), 'Settings are not saved.' );
	}

	// ---------------------------------------------------------------------
	// The whole load() path.
	// ---------------------------------------------------------------------

	public function test_load_search_replace_renames_and_sets_the_success_redirect() {
		$id   = $this->datedImage();
		$path = get_attached_file( $id );
		$name = 'emr-loaded-' . $this->token() . '.jpg';
		$this->postForm( $id, array( 'replace_type' => 'replace_and_search' ) );

		$controller = $this->loadSuccess( $this->tmpJpeg( 400, 300 ), $name );

		$this->assertSame( dirname( $path ) . '/' . $name, get_attached_file( $id ) );
		$this->assertFileDoesNotExist( $path );
		$view = $controller->getView();
		$this->assertSame( $id, $view->post_id );
		$this->assertSame( emr()->uiHelper()->getSuccesRedirect( $id ), $view->postUrl );
	}

	public function test_load_with_an_occupied_new_location_ends_on_the_target_exists_error() {
		$id      = $this->datedImage();
		$path    = get_attached_file( $id );
		$md5     = md5_file( $path );
		$uploads = wp_upload_dir();
		$folder  = 'emr-occupied-' . $this->token();
		$this->flowDirs[] = $uploads['basedir'] . '/' . $folder;
		wp_mkdir_p( $uploads['basedir'] . '/' . $folder );
		$name = 'emr-taken.jpg';
		$this->makeJpeg( $uploads['basedir'] . '/' . $folder . '/' . $name, 20, 20 );
		$this->postForm(
			$id,
			array(
				'replace_type' => 'replace_and_search',
				'new_location' => '1',
				'location_dir' => $folder,
			)
		);

		list( $controller, $exit ) = $this->load( $this->tmpJpeg( 400, 300 ), $name );

		$this->assertSame( 'error', $exit->template );
		$this->assertSame( ReplaceController::ERROR_TARGET_EXISTS, $controller->lastError );
		$this->assertSame( $path, get_attached_file( $id ) );
		$this->assertSame( $md5, md5_file( $path ) );
		$this->assertSame( '2015-05-05 10:00:00', get_post( $id )->post_modified, 'No date was written.' );
	}
}
