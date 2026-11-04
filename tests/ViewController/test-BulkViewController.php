<?php
/**
 * Tests for EnableMediaReplace\ViewController\BulkViewController
 * (classes/ViewController/BulkViewController.php) and the Media > Bulk
 * Enhance page registration in EnableMediaReplacePlugin::menu().
 *
 * load() is called directly with an administrator; the rendered HTML is
 * captured and inspected for the panels and the data attributes the screen
 * script binds to. The assets are checked through the WP_Scripts and
 * WP_Styles queues.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Controller/Bulk/BulkTestHelpers.php';
require_once dirname( __DIR__ ) . '/Integration/Helpers/MockEmrApi.php';

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

	/** @var MockEmrApi api-status.php for the AI credits of the account row and the summary. */
	private $mock;

	/** @var mixed The AI credits transient before the test (restored in tear_down). */
	private $savedCredits;

	/** Blocks any HTTP request the mock does not answer. */
	public function fail_fast( $preempt, $args, $url ) {
		return ( false !== $preempt ) ? $preempt : new WP_Error( 'http_blocked', 'Blocked unmocked request to ' . $url );
	}

	/** A fresh ApiKeyManager, so a key written to the option in the test is read. */
	private function fresh_key_manager() {
		$prop = new ReflectionProperty( \EnableMediaReplace\ApiKeyManager::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

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
		$this->savedCredits = get_transient( \EnableMediaReplace\ApiKeyManager::CREDITS_TRANSIENT );
		delete_transient( \EnableMediaReplace\ApiKeyManager::CREDITS_TRANSIENT );
		$this->mock = MockEmrApi::register();
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );
		$this->fresh_key_manager();
	}

	public function tear_down() {
		$this->viewsLoaded()->setValue( null, $this->savedViewsLoaded );
		wp_set_current_user( $this->savedUser );
		$_GET = $this->savedGet;
		wp_dequeue_style( 'emr_bulk' );
		wp_dequeue_script( 'emr_bulk_processor' );
		wp_dequeue_script( 'emr_bulk_screen' );
		wp_dequeue_script( 'emr_shortpixel_account' );
		remove_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999 );
		MockEmrApi::unregister();
		delete_transient( \EnableMediaReplace\ApiKeyManager::CREDITS_TRANSIENT );
		if ( false !== $this->savedCredits ) {
			set_transient( \EnableMediaReplace\ApiKeyManager::CREDITS_TRANSIENT, $this->savedCredits );
		}
		$this->fresh_key_manager();
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
		$this->assertSame( 'Bulk Enhance', $bulk[0] );
		$this->assertSame( 'Bulk Enhance', $bulk[3], 'The page title.' );
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
		$this->assertMatchesRegularExpression( '/data-action="BulkRestore" data-operation="upscale" disabled>Restore upscaled images</', $html, 'The button names what comes back.' );
		$this->assertStringNotContainsString( 'Restore:', $html );
	}

	public function test_load_offers_restore_of_background_removals_with_its_own_button_text() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$image = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$image->setMarker( 'background', array( 'ts' => time() ), BackupController::backup( $image ) );
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/data-action="BulkRestore" data-operation="background" disabled>Restore background removals</', $html );
		$this->assertStringNotContainsString( 'Restore upscaled images', $html );
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

	public function test_load_renders_the_header_the_step_bar_and_the_operation_texts() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$html = $this->render();

		$this->assertStringContainsString( '<h1>Enable Media Replace - Bulk Enhance</h1>', $html );
		$this->assertSame( 4, substr_count( $html, '<ol class="emr-bulk-steps">' ), 'Selection, summary, process and finished carry the step bar.' );
		$this->assertMatchesRegularExpression( '/emr-bulk-step-selection active current" aria-current="step"/', $html );
		$this->assertMatchesRegularExpression( '/emr-bulk-step-finished active current" aria-current="step"/', $html );
		$this->assertMatchesRegularExpression( '/class="emr-bulk-step emr-bulk-step-summary"/', $html, 'A step after the current one is not active.' );
		$this->assertStringContainsString( 'What do you want to do?', $html );
		$this->assertStringContainsString( 'Opacity (%)', $html );
		$this->assertStringContainsString( 'Solid background', $html );
		$this->assertStringNotContainsString( 'colour', strtolower( wp_strip_all_tags( $html ) ), 'US spelling on the page.' );
		$this->assertStringContainsString( 'Keep going in the background if I close this page', $html );
		$this->assertStringContainsString( 'Each image is enlarged 2×, 3× or 4×, whichever is the largest', $html );
		$this->assertStringContainsString( '(up to 4×)', $html );
		$this->assertStringContainsString( 'All done', $html );
		$this->assertStringContainsString( 'data-action="FinishAndRestore"', $html );
		$this->assertStringContainsString( 'Latest result:', $html );
	}

	public function test_load_shows_the_backup_folder_relative_to_the_wordpress_root() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$html     = $this->render();
		$expected = substr( wp_normalize_path( untrailingslashit( BackupController::getBackupDirectory()->getPath() ) ), strlen( wp_normalize_path( untrailingslashit( ABSPATH ) ) ) );

		$this->assertStringStartsWith( '/', $expected, 'Precondition: the backup folder is under ABSPATH.' );
		$this->assertStringEndsWith( 'EMR_Backups', $expected );
		$this->assertStringContainsString( '<code>' . esc_html( $expected ) . '</code>', $html );
		$this->assertStringNotContainsString( '<code>' . esc_html( wp_normalize_path( ABSPATH ) ), $html, 'No absolute server path.' );
	}

	public function test_load_shows_only_the_texts_of_the_current_operation() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$html = $this->render();

		$this->assertSame( 'background', ( new BulkQueue() )->getOperation(), 'Precondition: an idle queue reports the background operation.' );
		$this->assertMatchesRegularExpression( '/<span data-operation-only="background">Removing backgrounds/', $html );
		$this->assertMatchesRegularExpression( '/<span data-operation-only="upscale" hidden>Upscaling images/', $html );
		$this->assertMatchesRegularExpression( '/<span data-operation-only="restore" hidden>Restoring originals/', $html );
	}

	public function test_load_collapses_the_account_row_while_a_key_is_stored() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<details class="emr-sp-account emr-collapsible emr-sp-account-bulk"[^>]*data-confirm-remove="Remove the API key\? Bulk Enhance will stop working until you add one\."\s*>/', $html, 'Collapsed: no open attribute.' );
		$this->assertStringContainsString( 'Connected', $html );
		// AI credits (mock: 1000 a month, none used, not Unlimited AI).
		$this->assertStringContainsString( '<span class="emr-sp-account-credits">1,000 AI credits left</span>', $html );
		$this->assertStringContainsString( 'AI credits: <strong>1,000</strong> of 1,000 left this month', $html );
		$this->assertStringContainsString( '<progress class="emr-ai-credits-meter" max="1000" value="1000"></progress>', $html );
		$this->assertStringContainsString( 'href="https://shortpixel.com/pricing?utm_source=plugin&#038;utm_medium=emr&#038;utm_campaign=emr_unlimited_ai"', $html );
		// SPIO's "Buy Unlimited AI credits" button: its classes, the ShortPixel icon and the label.
		$this->assertMatchesRegularExpression( '#<a class="button button-primary unlimited emr-ai-upgrade" href="[^"]+" target="_blank" rel="noopener"><img class="icon" src="[^"]*/img/icons/shortpixel\.svg" width="30" height="30" alt=""><span>Buy Unlimited AI credits</span></a>#', $html );
		$this->assertStringNotContainsString( 'have no limit while your plan is active', $html, 'No "unlimited" promise for an Unlimited (non-AI) plan.' );
		$this->assertStringContainsString( 'abcd************wxyz', $html );
		$this->assertStringContainsString( '>Change key<', $html );
		$this->assertStringContainsString( '>Remove key<', $html );
		$this->assertTrue( wp_script_is( 'emr_shortpixel_account', 'enqueued' ) );
	}

	public function test_unlimited_ai_shows_no_credit_count_no_upgrade_button_and_no_summary_warning() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->mock->setKnob( 'planType', 'Unlimited AI' );
		$html = $this->render();

		$this->assertStringContainsString( '<span class="emr-sp-account-plan">Unlimited AI</span>', $html );
		$this->assertStringContainsString( 'This site is on the ShortPixel Unlimited AI plan, so you do not have to worry about AI credits. Enjoy!', $html );
		$this->assertStringNotContainsString( 'emr-ai-upgrade', $html );
		$this->assertStringNotContainsString( 'emr-sp-account-credits', $html );
		$this->assertStringNotContainsString( 'data-ai-remaining', $html );
	}

	public function test_the_summary_shows_the_credits_and_a_warning_for_more_images_than_credits() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->mock->setKnob( 'captionsMade', 997 );
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<div class="emr-bulk-summary-box emr-bulk-credits" data-operation-only="background upscale">/', $html );
		$this->assertStringContainsString( 'AI credits: <strong>3</strong> of 1,000 left this month', $html );
		$this->assertMatchesRegularExpression( '/<p class="emr-bulk-credits-warning" data-ai-remaining="3" hidden>/', $html, 'Hidden until the script compares it with the queue.' );
		$this->assertStringContainsString( 'but only <strong>3</strong> AI credits are left this month', $html );
		$this->assertCount( 1, $this->mock->requestsTo( 'api-status.php' ), 'One API call for the account row and the summary: the second read is cached.' );
	}

	public function test_credits_that_cannot_be_loaded_are_said_so() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$this->mock->setKnob( 'wpErrorMessage', 'down' );
		$html = $this->render();

		$this->assertStringContainsString( 'The AI credits of this API key could not be loaded right now.', $html );
		$this->assertStringContainsString( '<span class="emr-sp-account-plan">Unlimited</span>', $html );
		$this->assertStringNotContainsString( 'emr-sp-account-credits', $html );
		$this->assertStringNotContainsString( 'data-ai-remaining', $html );
	}

	public function test_load_opens_the_account_row_with_the_sign_up_link_without_a_key() {
		delete_option( 'emr_shortpixel_api_key' );
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<details class="emr-sp-account[^>]* open>/', $html );
		$this->assertStringContainsString( 'Not connected', $html );
		$this->assertStringContainsString( 'The Free plan includes 10 background removals and image upscales.', $html );
		$this->assertStringContainsString( 'placeholder="Paste your API key"', $html );
		$this->assertStringContainsString( 'utm_source=', BulkViewController::SIGNUP_URL );
		// esc_url() writes & as &#038;, wp_kses() (the warning) normalises it to &amp;.
		$signup = '/href="' . str_replace( '&', '&(?:#038|amp);', preg_quote( BulkViewController::SIGNUP_URL, '/' ) ) . '"/';
		$this->assertSame( 2, preg_match_all( $signup, $html ), 'The warning and the account row link to the sign-up page with UTM parameters.' );
		$this->assertStringContainsString( 'Bulk Enhance needs a ShortPixel API key on an Unlimited plan.', $html );
	}

	public function test_load_offers_no_restore_choice_without_originals() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$html = $this->render();

		$this->assertStringNotContainsString( 'data-action="BulkRestore"', $html );
		$this->assertStringContainsString( 'There are no originals to restore.', $html );
	}

	public function test_the_history_is_a_collapsible_details_element() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		update_option( BulkController::LOG_OPTION, array(
			array( 'logfile' => 'bulk_upscale_1700000100.log', 'date' => 1700000100, 'type' => 'upscale', 'processed' => 3, 'fatal_errors' => 0 ),
		), false );
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<details class="emr-collapsible emr-bulk-history">\s*<summary>/', $html );
		$this->assertStringContainsString( 'Bulk history', $html );
	}

	public function test_the_bulk_settings_default_to_an_opaque_solid_color() {
		$option = get_option( BulkController::SETTINGS_OPTION, array() );
		$this->assertFalse( is_array( $option ) && array_key_exists( 'bulk_bg_transparency', $option ), 'Precondition: no stored opacity.' );
		$this->assertSame( 100, BulkController::getSettings()['bg_transparency'] );
	}

	public function test_without_a_key_the_disabled_start_buttons_carry_a_tooltip() {
		delete_option( 'emr_shortpixel_api_key' );
		$html = $this->render();

		$this->assertSame( 2, substr_count( $html, '<span class="emr-bulk-tooltip" data-tooltip="Add a ShortPixel API key on an Unlimited plan to start."><button' ), 'One per operation.' );
		$this->assertMatchesRegularExpression( '/data-tooltip="[^"]+"><button[^>]*data-select-operation="upscale"[^>]*disabled/', $html );
	}

	public function test_with_a_key_only_an_unavailable_operation_carries_a_tooltip() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$html = $this->render();

		$this->assertFalse( post_type_exists( 'product' ), 'Precondition: no WooCommerce product type, the background operation is unavailable.' );
		$this->assertMatchesRegularExpression( '/<span class="emr-bulk-tooltip" data-tooltip="[^"]*WooCommerce[^"]*"><button[^>]*data-select-operation="background"/', $html );
		$this->assertMatchesRegularExpression( '/<span class="emr-bulk-tooltip"><button[^>]*data-select-operation="upscale"/', $html, 'The available operation has no tooltip.' );
	}

	public function test_the_operation_cards_show_the_svg_icons() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$html = $this->render();

		foreach ( array( 'icon-background-removal.svg', 'icon-image-upscale.svg' ) as $icon ) {
			$this->assertFileExists( dirname( EMR_ROOT_FILE ) . '/img/icons/' . $icon );
			$this->assertMatchesRegularExpression( '#<img class="emr-bulk-operation-icon" src="[^"]*/img/icons/' . preg_quote( $icon, '#' ) . '"#', $html );
		}
	}

	public function test_counts_filled_in_by_the_script_say_image_s() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$html = $this->render();

		$this->assertStringContainsString( ' image(s)</strong> will be processed.', $html );
		$this->assertStringContainsString( ' image(s) queued so far', $html );
		$this->assertStringContainsString( ' image(s) upscaled', $html );
		$this->assertStringContainsString( ' original(s) restored', $html );
		$this->assertStringContainsString( ' error(s)', $html );
	}

	public function test_the_restore_choice_counts_a_single_original_in_the_singular() {
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		$image = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$image->setMarker( 'upscale', array( 'ts' => time() ), BackupController::backup( $image ) );
		$html = $this->render();

		$this->assertStringContainsString( 'I want to restore the 1 original of &quot;Upscale images&quot;.', $html );
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
