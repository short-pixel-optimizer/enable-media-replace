<?php
/**
 * Tests for EnableMediaReplace\ViewController\BulkViewController
 * (classes/ViewController/BulkViewController.php) and the Media > Bulk
 * page registration in EnableMediaReplacePlugin::menu().
 *
 * load() is called directly with an administrator; the rendered HTML is
 * captured and inspected for the panels and the data attributes the screen
 * script binds to. The assets are checked through the WP_Scripts and
 * WP_Styles queues.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Controller/Bulk/BulkTestHelpers.php';

use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\EnableMediaReplacePlugin;
use EnableMediaReplace\Model\Bulk\BulkImage;
use EnableMediaReplace\ViewController\BulkViewController;

class BulkViewControllerTest extends WP_UnitTestCase {

	/**
	 * Creates the shared `shortpixel_queue` table once, outside the test
	 * transaction. ShortQ installs it with CREATE TABLE and ALTER TABLE on
	 * first use; inside a test WP_UnitTestCase rewrites the CREATE into a
	 * TEMPORARY table (invisible to SHOW TABLES, dropped per connection) and
	 * the ALTER commits the transaction, which leaks every option written
	 * before it into later tests. Installed up front, the in-test install is
	 * a no-op (dbDelta sees the table, the unique key exists).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		( new \EnableMediaReplace\ShortQ\ShortQ( \EnableMediaReplace\InstallHelper::QUEUE_SLUG ) )->getQueue( \EnableMediaReplace\InstallHelper::QUEUE_NAME )->install();
	}

	use EMR_BulkTestHelpers;

	/** @var int */
	private $savedUser;

	/** @var array */
	private $savedGet;

	/** @var array */
	private $savedViewsLoaded;

	/** ViewController::loadView() includes a template once per process; each test starts fresh. */
	private function viewsLoaded() {
		$prop = new ReflectionProperty( \EnableMediaReplace\ViewController::class, 'viewsLoaded' );
		$prop->setAccessible( true );
		return $prop;
	}

	public function set_up() {
		parent::set_up();
		$this->savedViewsLoaded = $this->viewsLoaded()->getValue( null );
		$this->viewsLoaded()->setValue( null, array() );
		$this->bulkSetUp();
		( new BulkQueue() )->resetQueue();
		$this->savedUser = get_current_user_id();
		$this->savedGet  = $_GET;
		$prop            = new ReflectionProperty( BulkViewController::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		emr()->admin_scripts();
	}

	public function tear_down() {
		$this->viewsLoaded()->setValue( null, $this->savedViewsLoaded );
		wp_set_current_user( $this->savedUser );
		$_GET = $this->savedGet;
		wp_dequeue_style( 'emr_bulk' );
		wp_dequeue_script( 'emr_bulk_processor' );
		wp_dequeue_script( 'emr_bulk_screen' );
		wp_dequeue_script( 'emr_shortpixel_account' );
		$this->bulkTearDown();
		parent::tear_down();
	}

	private function render() {
		ob_start();
		BulkViewController::getInstance()->load();
		return ob_get_clean();
	}

	public function test_menu_registers_a_visible_bulk_page_under_media_for_administrators() {
		global $submenu;
		$submenu = array();
		emr()->menu();
		$entries = isset( $submenu['upload.php'] ) ? $submenu['upload.php'] : array();
		$bulk    = null;
		foreach ( $entries as $entry ) {
			if ( EnableMediaReplacePlugin::BULK_PAGE_SLUG === $entry[2] ) {
				$bulk = $entry;
			}
		}
		$this->assertNotNull( $bulk, 'The bulk page is registered under Media.' );
		$this->assertSame( 'Bulk', $bulk[0] );
		$this->assertSame( 'manage_options', $bulk[1] );
		$this->assertSame( 'upload.php', emr()->hide_sub_menu( 'upload.php' ) );
		$remaining = array_filter( $GLOBALS['submenu']['upload.php'], function ( $entry ) {
			return EnableMediaReplacePlugin::BULK_PAGE_SLUG === $entry[2];
		} );
		$this->assertCount( 1, $remaining, 'hide_sub_menu() removes the hidden EMR page, not the bulk page.' );
	}

	public function test_load_renders_every_panel_with_the_data_attributes_the_script_binds_to() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$html = $this->render();

		foreach ( array( 'dashboard', 'selection', 'summary', 'process', 'finished', 'restore' ) as $panel ) {
			$this->assertStringContainsString( 'data-panel="' . $panel . '"', $html, $panel );
		}
		$this->assertStringContainsString( 'class="wrap emr-bulk-wrapper"', $html );
		$this->assertStringContainsString( 'data-action="CreateBulk"', $html );
		$this->assertStringContainsString( 'data-action="StartBulk"', $html );
		$this->assertStringContainsString( 'data-action="FinishBulk"', $html );
		$this->assertStringContainsString( 'data-stats-media="percentage_done"', $html );
		$this->assertStringContainsString( 'name="emr_bulk_operation" value="background"', $html );
		$this->assertStringContainsString( 'name="emr_bulk_operation" value="upscale"', $html );
		$this->assertStringContainsString( 'id="emr-bulk-threshold"', $html );
		$this->assertStringContainsString( 'value="1200"', $html, 'The default threshold.' );
		$this->assertStringContainsString( 'max="1200"', $html, 'Capped to the widest source of the factor table.' );
		$this->assertStringNotContainsString( '<script', $html, 'No inline scripts.' );
		$this->assertStringNotContainsString( 'style="', $html, 'No inline styles.' );
		$this->assertStringContainsString( 'emr-sp-account', $html, 'The API key box is included.' );
		$this->assertStringContainsString( 'img/bulk/placeholder.svg', $html, 'SPIO\'s placeholder image, shipped with EMR.' );
		$this->assertFileExists( dirname( dirname( __DIR__ ) ) . '/img/bulk/placeholder.svg' );
	}

	public function test_load_enqueues_the_bulk_assets_without_jquery_and_localizes_the_data() {
		$_GET['page'] = EnableMediaReplacePlugin::BULK_PAGE_SLUG;
		emr()->admin_scripts();
		$this->render();

		$this->assertTrue( wp_style_is( 'emr_bulk', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'emr_bulk_processor', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'emr_bulk_screen', 'enqueued' ) );
		$scripts = wp_scripts();
		$this->assertSame( array(), $scripts->registered['emr_bulk_processor']->deps );
		$this->assertSame( array( 'emr_bulk_processor' ), $scripts->registered['emr_bulk_screen']->deps );
		$this->assertStringEndsWith( 'js/bulk/emr-processor.js', $scripts->registered['emr_bulk_processor']->src );

		$data = emr()->bulkScriptData();
		$this->assertStringEndsWith( 'admin-ajax.php', $data['ajaxUrl'] );
		$this->assertStringEndsWith( 'js/bulk/emr-worker.js', $data['workerURL'] );
		$this->assertTrue( $data['isBulkPage'] );
		$this->assertIsObject( $data['startData'] );
		$this->assertSame( 1, wp_verify_nonce( $data['nonce_process'], 'emr_bulk_process' ) );
		$this->assertSame( 1, wp_verify_nonce( $data['nonce_request'], 'emr_bulk_request' ) );
		$this->assertSame( 1, wp_verify_nonce( $data['nonce_settings'], 'emr_bulk_settings' ) );
		$this->assertSame( 3000, $data['interval'] );
		$this->assertSame( 60000, $data['deferInterval'] );
		$this->assertFalse( $data['bulkSecret'] );
		$this->assertArrayHasKey( 'endBulk', $data['strings'] );
		$this->assertSame( 1200, $data['settings']['upscale_threshold'] );

		unset( $_GET['page'] );
		$this->assertFalse( emr()->bulkScriptData()['startData'], 'No stats off the bulk page.' );
	}

	public function test_load_without_a_key_shows_the_warning_and_disables_the_start_buttons() {
		delete_option( 'emr_shortpixel_api_key' );
		$html = $this->render();
		$this->assertStringContainsString( 'API key required', $html );
		$this->assertMatchesRegularExpression( '/data-select-operation="upscale"[^>]*disabled/', $html );
	}

	public function test_load_shows_the_woocommerce_reason_and_the_counts_per_operation() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->makeImageAttachment( 100, 100 );
		$this->makeImageAttachment( 100, 100, 'png' );
		$html = $this->render();

		$this->assertStringContainsString( 'WooCommerce is not active', $html );
		$this->assertMatchesRegularExpression( '/data-operation="background" data-available="0"/', $html );
		$this->assertMatchesRegularExpression( '/data-operation="upscale" data-available="1"/', $html );
		$this->assertStringContainsString( '<strong>2</strong> images to scan', $html );
	}

	public function test_load_offers_restore_for_images_a_bulk_processed() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$image = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$image->setMarker( 'upscale', array( 'ts' => time() ), BackupController::backup( $image ) );
		$html = $this->render();

		$this->assertStringContainsString( 'Restore 1 original', $html );
		$this->assertStringContainsString( 'data-action="BulkRestore" data-operation="upscale"', $html );
	}

	public function test_load_lists_the_history_with_a_link_to_the_log_of_a_run_with_errors() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$dir = BackupController::getBackupDirectory()->getPath();
		file_put_contents( $dir . 'bulk_background_1700000000.log', 'x|y|1|err;' );
		update_option( BulkController::LOG_OPTION, array(
			array( 'logfile' => 'bulk_background_1700000000.log', 'date' => 1700000000, 'type' => 'background', 'processed' => 7, 'fatal_errors' => 2 ),
			array( 'logfile' => 'bulk_upscale_1700000100.log', 'date' => 1700000100, 'type' => 'upscale', 'processed' => 3, 'fatal_errors' => 0, 'operation' => BulkQueue::CUSTOM_OP_RESTORE ),
		), false );

		$logs = BulkViewController::getInstance()->getLogs();
		$this->assertCount( 2, $logs );
		$this->assertStringContainsString( 'Restore originals', $logs[0]['name'], 'Newest first.' );
		$this->assertFalse( $logs[0]['file'], 'No file on disk: no link.' );
		$this->assertSame( 'bulk_background_1700000000', $logs[1]['file'] );

		$html = $this->render();
		$this->assertStringContainsString( 'data-action="OpenLog" data-file="bulk_background_1700000000"', $html );
		$this->assertStringContainsString( 'id="emr-bulk-log-modal"', $html );
	}

	public function test_load_shows_the_current_error_log_of_a_running_bulk() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$dir = BackupController::getBackupDirectory()->getPath();
		file_put_contents( $dir . BulkController::currentLogName( 'background' ), '2026-10-30 10:00|img.jpg| 5|Invalid <image> format;' );
		$html = $this->render();
		$this->assertStringContainsString( 'Invalid &lt;image&gt; format (file img.jpg)', $html );
	}

	public function test_load_refuses_a_user_without_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$controller = new class() extends BulkViewController {
			public $code = null;
			protected function viewError( $errorCode, $errorData = array() ) {
				$this->code = $errorCode;
				throw new RuntimeException( 'exit' );
			}
		};
		try {
			$controller->load();
			$this->fail( 'viewError() must end the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( BulkViewController::ERROR_UPLOAD_PERMISSION, $controller->code );
		}
	}
}
