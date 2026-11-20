<?php
/**
 * Tests for EnableMediaReplace\Controller\MediaLibraryFilterController: the
 * Bulk Enhance dropdown of the Media Library list, the grid script, and the
 * query that keeps only the attachments a bulk operation marked.
 *
 * Snapshots and restores $_GET / $_REQUEST, $pagenow, the current screen and
 * the hooks register() adds; attachments and markers go through
 * EMR_BulkTestHelpers.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/Bulk/BulkTestHelpers.php';

use EnableMediaReplace\Controller\MediaLibraryFilterController;
use EnableMediaReplace\Model\Bulk\BulkImage;

class MediaLibraryFilterControllerTest extends WP_UnitTestCase {

	use EMR_BulkTestHelpers;

	/** @var array */
	private $savedGet;

	/** @var array */
	private $savedRequest;

	/** @var string|null */
	private $savedPagenow;

	/** @var MediaLibraryFilterController */
	private $controller;

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
		$this->savedGet     = $_GET;
		$this->savedRequest = $_REQUEST;
		$this->savedPagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
		$this->controller   = MediaLibraryFilterController::getInstance();
	}

	public function tear_down() {
		$this->unhook();
		$_GET               = $this->savedGet;
		$_REQUEST           = $this->savedRequest;
		$GLOBALS['pagenow'] = $this->savedPagenow;
		set_current_screen( 'front' );
		wp_dequeue_script( 'emr_media_filter' );
		$this->bulkTearDown();
		parent::tear_down();
	}

	private function unhook() {
		remove_action( 'restrict_manage_posts', array( $this->controller, 'addFilterDropdown' ) );
		remove_filter( 'pre_get_posts', array( $this->controller, 'filterListener' ) );
		remove_action( 'wp_enqueue_media', array( $this->controller, 'enqueueGridFilter' ) );
		remove_filter( 'posts_where', array( $this->controller, 'filterAddWhere' ), 10 );
	}

	/** Three attachments: one marked by the background operation, one by the upscale, one untouched. */
	private function library() {
		$background = $this->makeImageAttachment( 100, 100 );
		$upscale    = $this->makeImageAttachment( 100, 100 );
		$plain      = $this->makeImageAttachment( 100, 100 );
		BulkImage::getById( $background )->setMarker( 'background', array( 'ts' => 1 ), '2026/11/a.jpg' );
		BulkImage::getById( $upscale )->setMarker( 'upscale', array( 'ts' => 1, 'factor' => 2 ), '2026/11/b.jpg' );
		return array( $background, $upscale, $plain );
	}

	private function attachmentIds() {
		$query = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
		return array_map( 'intval', $query->posts );
	}

	/** The library page in list mode with the filter set to $value. */
	private function onUploadPage( $value ) {
		$GLOBALS['pagenow'] = 'upload.php';
		$_GET[ MediaLibraryFilterController::QUERY_VAR ]     = $value;
		$_REQUEST[ MediaLibraryFilterController::QUERY_VAR ] = $value;
		$this->controller->register();
	}

	public function test_the_filters_are_all_and_the_two_operations_and_the_url_opens_the_list_filtered() {
		$this->assertSame( array( 'all', 'background', 'upscale' ), array_keys( $this->controller->getFilters() ) );
		$this->assertSame( admin_url( 'upload.php?mode=list&emr_bulk=upscale' ), $this->controller->getUrl( 'upscale' ) );
		$this->assertSame( admin_url( 'upload.php?mode=list&emr_bulk=background' ), $this->controller->getUrl( 'background' ) );
	}

	public function test_the_dropdown_prints_on_the_upload_screen_with_the_selected_value_and_nowhere_else() {
		set_current_screen( 'edit-post' );
		ob_start();
		$this->controller->addFilterDropdown();
		$this->assertSame( '', ob_get_clean(), 'Not on the posts list.' );

		set_current_screen( 'upload' );
		$_REQUEST['emr_bulk'] = 'upscale';
		ob_start();
		$this->controller->addFilterDropdown();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<select name="emr_bulk" id="emr_bulk">', $html );
		$this->assertStringContainsString( '<option value="all">Any Bulk Enhance state</option>', $html );
		$this->assertStringContainsString( '<option value="background">Background removed in bulk</option>', $html );
		$this->assertStringContainsString( "<option value=\"upscale\" selected='selected'>Upscaled in bulk</option>", $html );
		$this->assertStringContainsString( 'class="screen-reader-text">Filter by Bulk Enhance state</label>', $html );
	}

	public function test_the_selected_value_comes_from_the_query_argument_or_the_grid_request_and_unknown_values_mean_all() {
		$this->assertSame( 'all', $this->controller->selectedFilterValue(), 'Nothing selected.' );

		$_REQUEST['emr_bulk'] = 'background';
		$this->assertSame( 'background', $this->controller->selectedFilterValue() );

		$_REQUEST['emr_bulk'] = 'DROP TABLE';
		$this->assertSame( 'all', $this->controller->selectedFilterValue(), 'Unknown values are ignored.' );

		// The grid posts its filters as query[emr_bulk] on admin-ajax.php.
		add_filter( 'wp_doing_ajax', '__return_true' );
		$_REQUEST['emr_bulk'] = 'background';
		$this->assertSame( 'all', $this->controller->selectedFilterValue(), 'In an AJAX request only query[] counts.' );
		$_REQUEST['query'] = array( 'emr_bulk' => 'upscale' );
		$this->assertSame( 'upscale', $this->controller->selectedFilterValue() );
		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	public function test_the_list_shows_only_the_attachments_the_selected_operation_marked() {
		list( $background, $upscale, $plain ) = $this->library();

		$this->onUploadPage( 'upscale' );
		$this->assertSame( array( $upscale ), $this->attachmentIds() );

		$this->unhook();
		$this->onUploadPage( 'background' );
		$this->assertSame( array( $background ), $this->attachmentIds() );

		$this->unhook();
		$this->onUploadPage( 'all' );
		$ids = $this->attachmentIds();
		foreach ( array( $background, $upscale, $plain ) as $id ) {
			$this->assertContains( $id, $ids, 'all filters nothing.' );
		}
	}

	public function test_a_restored_attachment_drops_out_of_the_list() {
		list( $background, $upscale ) = $this->library();
		BulkImage::getById( $upscale )->deleteMarker();

		$this->onUploadPage( 'upscale' );
		$this->assertSame( array(), $this->attachmentIds() );
	}

	public function test_the_filter_touches_neither_other_screens_nor_other_post_types() {
		list( $background, $upscale, $plain ) = $this->library();
		$post = self::factory()->post->create();

		$GLOBALS['pagenow'] = 'edit.php';
		$_GET['emr_bulk'] = $_REQUEST['emr_bulk'] = 'upscale';
		$this->controller->register();
		$ids = $this->attachmentIds();
		$this->assertContains( $plain, $ids, 'Not the Media Library page: unfiltered.' );
		$this->assertFalse( has_filter( 'posts_where', array( $this->controller, 'filterAddWhere' ) ) );

		$this->unhook();
		$this->onUploadPage( 'upscale' );
		$this->assertSame( array( $upscale ), $this->attachmentIds(), 'Precondition: the clause is attached now.' );
		$posts = new WP_Query( array( 'post_type' => 'post', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		$this->assertContains( $post, array_map( 'intval', $posts->posts ), 'A later query of another post type in the same request is untouched.' );
	}

	public function test_the_grid_script_is_enqueued_with_the_filters_for_the_media_views() {
		$this->controller->enqueueGridFilter();

		$this->assertTrue( wp_script_is( 'emr_media_filter', 'enqueued' ) );
		$script = wp_scripts()->registered['emr_media_filter'];
		$this->assertContains( 'media-views', $script->deps );
		$this->assertStringEndsWith( '/js/emr-media-filter.js', $script->src );
		$data = $script->extra['data'];
		$this->assertStringContainsString( 'var emrMediaFilter = ', $data );
		$this->assertStringContainsString( '"queryVar":"emr_bulk"', $data );
		$this->assertStringContainsString( '"upscale":"Upscaled in bulk"', $data );
		$this->assertFileExists( dirname( EMR_ROOT_FILE ) . '/js/emr-media-filter.js' );
	}
}
