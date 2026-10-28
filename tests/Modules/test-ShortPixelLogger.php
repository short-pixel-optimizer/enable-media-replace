<?php
/**
 * Tests for EnableMediaReplace\ShortPixelLogger\ShortPixelLogger and
 * DebugItem (build/shortpixel/log/src/), and the debug box view.
 *
 * The module has few docblocks, so the code is the specification. The unit
 * bootstrap defines SHORTPIXEL_DEBUG = 4 (every level, debug included) and
 * SHORTPIXEL_DEBUG_TARGET = true, and EnableMediaReplacePlugin::get()
 * points the singleton at `<uploads basedir>/emr_log`. Constants are never
 * defined here.
 *
 * Each test points the existing singleton at a log file in its own sandbox
 * by setting the logPath/logFile properties directly (setLogPath() leaks the
 * previous handle, see the pin), and tear_down() puts back the original
 * instance, its properties and its file handle. Tests that need a freshly
 * constructed logger (the manual $_REQUEST mode) replace the static
 * instance and tear_down() restores it.
 *
 * Not covered here: the multisite branch of the constructor
 * (msCheckUserLevel() on wp_loaded) needs a multisite install, and
 * SHORTPIXEL_LOG_OVERWRITE is a constant.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger;
use EnableMediaReplace\ShortPixelLogger\DebugItem;

class ShortPixelLoggerTest extends WP_UnitTestCase {

	/**
	 * The singleton before the test.
	 *
	 * @var ShortPixelLogger
	 */
	private $saved_instance;

	/**
	 * Property values of the singleton before the test.
	 *
	 * @var array
	 */
	private $saved_props = array();

	/**
	 * Properties snapshotted and restored on the singleton.
	 *
	 * @var string[]
	 */
	private $props = array( 'logPath', 'logFile', 'logLevel', 'is_active', 'is_manual_request', 'memoryLimit', 'items', 'view' );

	/**
	 * Sandbox directory for the current test.
	 *
	 * @var string
	 */
	private $sandbox;

	/**
	 * The test's log file.
	 *
	 * @var string
	 */
	private $log;

	/**
	 * File handles opened by the test, closed in tear_down().
	 *
	 * @var resource[]
	 */
	private $handles = array();

	/**
	 * Snapshot of $_REQUEST.
	 *
	 * @var array
	 */
	private $saved_request;

	/**
	 * ini settings changed by the test, as name => previous value.
	 *
	 * @var array
	 */
	private $saved_ini = array();

	/**
	 * Filters added by the test, as array( hook, callback ).
	 *
	 * @var array
	 */
	private $added_filters = array();

	public function set_up() {
		parent::set_up();
		$this->saved_request  = $_REQUEST;
		$this->saved_instance = $this->static_instance();
		foreach ( $this->props as $prop ) {
			$this->saved_props[ $prop ] = $this->get_prop( $this->saved_instance, $prop );
		}

		$this->sandbox = sys_get_temp_dir() . '/emr-logger-' . uniqid();
		mkdir( $this->sandbox, 0755, true );
		$this->log = $this->sandbox . '/test.log';
		$this->point_logger_at( $this->saved_instance, $this->log );
		$this->set_prop( $this->saved_instance, 'items', array() );
	}

	public function tear_down() {
		$this->set_static_instance( $this->saved_instance );
		foreach ( $this->saved_props as $prop => $value ) {
			$this->set_prop( $this->saved_instance, $prop, $value );
		}
		$this->saved_props = array();
		foreach ( $this->handles as $handle ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
		}
		$this->handles = array();
		foreach ( $this->saved_ini as $name => $value ) {
			ini_set( $name, $value );
		}
		$this->saved_ini = array();
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1] );
		}
		$this->added_filters = array();
		$_REQUEST = $this->saved_request;
		wp_set_current_user( 0 );
		$this->recursive_rmdir( $this->sandbox );
		parent::tear_down();
	}

	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $name ) {
			$entry = $dir . '/' . $name;
			if ( is_dir( $entry ) ) {
				$this->recursive_rmdir( $entry );
			} else {
				unlink( $entry );
			}
		}
		rmdir( $dir );
	}

	private function static_instance() {
		$ref = new ReflectionProperty( ShortPixelLogger::class, 'instance' );
		$ref->setAccessible( true );
		return $ref->getValue();
	}

	private function set_static_instance( $value ) {
		$ref = new ReflectionProperty( ShortPixelLogger::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	private function get_prop( $logger, $name ) {
		$ref = new ReflectionProperty( ShortPixelLogger::class, $name );
		$ref->setAccessible( true );
		return $ref->getValue( $logger );
	}

	private function set_prop( $logger, $name, $value ) {
		$ref = new ReflectionProperty( ShortPixelLogger::class, $name );
		$ref->setAccessible( true );
		$ref->setValue( $logger, $value );
	}

	/**
	 * Points a logger at a file without going through setLogPath().
	 */
	private function point_logger_at( $logger, $path ) {
		$handle          = fopen( $path, 'a' );
		$this->handles[] = $handle;
		$this->set_prop( $logger, 'logPath', $path );
		$this->set_prop( $logger, 'logFile', $handle );
	}

	private function log_contents() {
		clearstatcache();
		return file_exists( $this->log ) ? file_get_contents( $this->log ) : '';
	}

	private function set_ini( $name, $value ) {
		if ( ! array_key_exists( $name, $this->saved_ini ) ) {
			$this->saved_ini[ $name ] = ini_get( $name );
		}
		ini_set( $name, $value );
	}

	/**
	 * Replaces the singleton with a freshly constructed logger.
	 */
	private function fresh_logger() {
		$this->set_static_instance( null );
		return ShortPixelLogger::getInstance();
	}

	private function make_admin() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
	}

	/*
	 * Singleton and status.
	 */

	public function test_getInstance_returns_the_same_logger() {
		$logger = ShortPixelLogger::getInstance();
		$this->assertInstanceOf( ShortPixelLogger::class, $logger );
		$this->assertSame( $logger, ShortPixelLogger::getInstance() );
		$this->assertSame( $this->saved_instance, $logger );
	}

	public function test_bootstrap_constants_make_the_logger_active_at_debug_level() {
		$this->assertTrue( defined( 'SHORTPIXEL_DEBUG' ) );
		$this->assertSame( 4, SHORTPIXEL_DEBUG );
		$this->assertTrue( ShortPixelLogger::debugIsActive() );
		$this->assertFalse( ShortPixelLogger::isManualDebug() );
		$this->assertSame( DebugItem::LEVEL_DEBUG, ShortPixelLogger::getLogLevel() );
	}

	public function test_a_fresh_logger_reads_the_constants() {
		$logger = $this->fresh_logger();
		$this->assertNotSame( $this->saved_instance, $logger );
		$this->assertTrue( $this->get_prop( $logger, 'is_active' ) );
		$this->assertSame( 4, $this->get_prop( $logger, 'logLevel' ) );
		$this->assertFalse( $this->get_prop( $logger, 'logPath' ) );
		$this->assertSame( 10, has_action( 'init', array( $logger, 'initView' ) ) );
	}

	/**
	 * emr() (EnableMediaReplacePlugin::get()) sets the log path to
	 * `<uploads basedir>/emr_log` on every call while debugging is active.
	 */
	public function test_emr_points_the_logger_at_emr_log_in_uploads() {
		emr();
		$this->handles[] = $this->get_prop( $this->saved_instance, 'logFile' );

		$upload = wp_upload_dir( null, false, false );
		$this->assertSame( trailingslashit( $upload['basedir'] ) . 'emr_log', ShortPixelLogger::getLogPath() );
	}

	/*
	 * Writing lines.
	 */

	public function test_each_level_writes_a_line_with_its_label() {
		ShortPixelLogger::addDebug( 'debug message' );
		ShortPixelLogger::addInfo( 'info message' );
		ShortPixelLogger::addWarn( 'warn message' );
		ShortPixelLogger::addWarning( 'warning alias message' );
		ShortPixelLogger::addError( 'error message' );
		ShortPixelLogger::addTemp( 'temp message' );

		$lines = array_values( array_filter( explode( PHP_EOL, $this->log_contents() ) ) );
		$this->assertCount( 6, $lines );
		$this->assertMatchesRegularExpression( '/ DBG .*debug message/', $lines[0] );
		$this->assertMatchesRegularExpression( '/ INF .*info message/', $lines[1] );
		$this->assertMatchesRegularExpression( '/ WRN .*warn message/', $lines[2] );
		$this->assertMatchesRegularExpression( '/ WRN .*warning alias message/', $lines[3] );
		$this->assertMatchesRegularExpression( '/ ERR .*error message/', $lines[4] );
		$this->assertMatchesRegularExpression( '/ DBG .*temp message/', $lines[5] );
		$this->assertCount( 6, $this->get_prop( $this->saved_instance, 'items' ) );
	}

	public function test_line_format_has_time_colour_codes_and_elapsed_time() {
		ShortPixelLogger::addInfo( 'formatted' );
		$line = $this->log_contents();

		$this->assertMatchesRegularExpression( '/^\[ \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \] /', $line );
		$this->assertStringContainsString( "\033[37m INF \033[0m", $line );
		$this->assertMatchesRegularExpression( '/formatted  \t .* \( [\d.E\-]+ \)' . preg_quote( PHP_EOL, '/' ) . '$/', $line );
	}

	/**
	 * DebugItem records frame 4 of the backtrace as the caller, and writes
	 * that frame's file and line with its function name. For a direct
	 * ShortPixelLogger::addX() call frame 4 is the function that contains
	 * the call, but its file and line are where THAT function was called
	 * from (here PHPUnit's TestCase.php), not where the log call is. The
	 * line should name the file and line of the log call (frame 3) with the
	 * enclosing function (frame 4).
	 *
	 * Where: DebugItem::setCaller().
	 *
	 * How to recognise the fix: the logged caller is this test file, not
	 * TestCase.php.
	 */
	public function test_logged_caller_names_the_wrong_file_pinned_for_deferred_fix() {
		ShortPixelLogger::addInfo( 'who called' );
		$line = $this->log_contents();

		$this->assertStringContainsString( 'TestCase.php in ' . __FUNCTION__ . '(', $line );
		$this->assertStringNotContainsString( basename( __FILE__ ), $line );
	}

	/*
	 * Level gating.
	 */

	public function test_level_gating_drops_messages_above_the_log_level() {
		$this->set_prop( $this->saved_instance, 'logLevel', DebugItem::LEVEL_WARN );

		ShortPixelLogger::addDebug( 'dropped debug' );
		ShortPixelLogger::addInfo( 'dropped info' );
		ShortPixelLogger::addWarn( 'kept warn' );
		ShortPixelLogger::addError( 'kept error' );

		$contents = $this->log_contents();
		$this->assertStringNotContainsString( 'dropped', $contents );
		$this->assertStringContainsString( 'kept warn', $contents );
		$this->assertStringContainsString( 'kept error', $contents );
		$this->assertCount( 2, $this->get_prop( $this->saved_instance, 'items' ), 'Dropped messages are not kept in memory either.' );
	}

	/**
	 * logLevel() logs the change at the old level, then switches. The label
	 * and the level run together ('Changing Log level2').
	 */
	public function test_logLevel_logs_the_change_then_applies_it() {
		ShortPixelLogger::logLevel( DebugItem::LEVEL_ERROR );

		$this->assertSame( DebugItem::LEVEL_ERROR, ShortPixelLogger::getLogLevel() );
		$this->assertStringContainsString( 'Changing Log level1', $this->log_contents() );

		ShortPixelLogger::addWarn( 'now dropped' );
		$this->assertStringNotContainsString( 'now dropped', $this->log_contents() );
	}

	public function test_inactive_logger_writes_nothing() {
		$this->set_prop( $this->saved_instance, 'is_active', false );

		ShortPixelLogger::addError( 'silent' );

		$this->assertSame( '', $this->log_contents() );
		$this->assertFalse( ShortPixelLogger::debugIsActive() );
	}

	/*
	 * Data serialisation.
	 */

	public function test_scalar_data_array_is_printed_below_the_line() {
		ShortPixelLogger::addDebug( 'with data', array( 'alpha', 'beta' ) );
		$contents = $this->log_contents();

		$this->assertStringContainsString( 'with data', $contents );
		$this->assertStringContainsString( '[0] => alpha', $contents );
		$this->assertStringContainsString( '[1] => beta', $contents );
	}

	public function test_nested_data_is_printed_per_item_with_its_type() {
		ShortPixelLogger::addDebug( 'nested', array( 'first' => array( 'x' => 1 ), 'second' => (object) array( 'y' => 2 ) ) );
		$contents = $this->log_contents();

		$this->assertStringContainsString( 'Array: (2)', $contents );
		$this->assertStringContainsString( 'first ( Array) => Array', $contents );
		$this->assertStringContainsString( '[x] => 1', $contents );
		$this->assertStringContainsString( 'second ( Object) => stdClass Object', $contents );
		$this->assertStringContainsString( '[y] => 2', $contents );
	}

	public function test_array_message_becomes_data_with_a_placeholder_message() {
		ShortPixelLogger::addDebug( array( 'logged_key' => 'logged_value' ) );
		$contents = $this->log_contents();

		$this->assertMatchesRegularExpression( '/ DBG .*\[Data\]/', $contents );
		$this->assertStringContainsString( '[logged_key] => logged_value', $contents );
	}

	public function test_non_array_data_is_printed_as_is() {
		ShortPixelLogger::addInfo( 'scalar data', 'plain string payload' );
		$this->assertStringContainsString( PHP_EOL . 'plain string payload' . PHP_EOL, $this->log_contents() );
	}

	public function test_addTrace_logs_the_backtrace_as_data() {
		ShortPixelLogger::addTrace( 'trace here', 3 );
		$contents = $this->log_contents();

		$this->assertMatchesRegularExpression( '/ DBG .*trace here/', $contents );
		$this->assertStringContainsString( 'Array: (3)', $contents );
		$this->assertStringContainsString( '[function] => addTrace', $contents );
	}

	public function test_addMemory_appends_usage_against_the_memory_limit() {
		$this->set_ini( 'memory_limit', '512M' );
		$this->set_prop( $this->saved_instance, 'memoryLimit', null );

		ShortPixelLogger::addMemory( 'memory check' );

		$this->assertSame( 512 * 1024 * 1024, $this->get_prop( $this->saved_instance, 'memoryLimit' ) );
		$this->assertMatchesRegularExpression( '/memory check \( [\d.]+ M \/ 512 M - [\d.]+ %\)/', $this->log_contents() );
	}

	/**
	 * With an unlimited memory limit (memory_limit = -1, the CLI default)
	 * unitToInt() looks up an empty unit with strpos(): PHP 8 treats that as
	 * position 0 and turns -1 into a limit of -1 byte, so the line shows a
	 * limit of 0 and a negative percentage; PHP 7.4 raises an "Empty needle"
	 * warning. The intended output for an unlimited limit is the usage alone
	 * (or "unlimited").
	 *
	 * Where: ShortPixelLogger::addMemoryLog() and unitToInt().
	 *
	 * How to recognise the fix: no warning on 7.4 and no negative
	 * percentage on 8.x.
	 */
	public function test_addMemory_with_unlimited_memory_limit_pinned_for_deferred_fix() {
		$this->set_ini( 'memory_limit', '-1' );
		$this->set_prop( $this->saved_instance, 'memoryLimit', null );

		if ( PHP_VERSION_ID < 80000 ) {
			try {
				ShortPixelLogger::addMemory( 'unlimited' );
				$this->fail( 'Expected an "Empty needle" warning.' );
			} catch ( \PHPUnit\Framework\Error\Warning $e ) {
				$this->assertStringContainsString( 'Empty needle', $e->getMessage() );
			}
			return;
		}

		ShortPixelLogger::addMemory( 'unlimited' );

		$this->assertSame( -1, $this->get_prop( $this->saved_instance, 'memoryLimit' ) );
		$this->assertMatchesRegularExpression( '/unlimited \( [\d.]+ [KMG]? ?\/ 0 - -[\d.]+ %\)/', $this->log_contents() );
	}

	public function test_logHook_logs_the_hook_and_returns_the_value() {
		$logger = ShortPixelLogger::getInstance();
		$result = $logger->logHook( 'emr_test_hook', array( 'v' => 1 ), array( 'ignored-first', 'extra' ) );

		$this->assertSame( array( 'v' => 1 ), $result );
		$contents = $this->log_contents();
		$this->assertStringContainsString( '[Hook] - emr_test_hook with array (', $contents );
		$this->assertStringContainsString( '[0] => extra', $contents );
	}

	/*
	 * Log file handling.
	 */

	public function test_setLogPath_switches_the_target_file() {
		$other = $this->sandbox . '/other.log';
		$logger = ShortPixelLogger::getInstance();
		$logger->setLogPath( $other );
		$this->handles[] = $this->get_prop( $logger, 'logFile' );

		ShortPixelLogger::addInfo( 'to the other file' );

		$this->assertSame( $other, ShortPixelLogger::getLogPath() );
		$this->assertStringContainsString( 'to the other file', file_get_contents( $other ) );
		$this->assertSame( '', $this->log_contents() );
	}

	/**
	 * A log directory that does not exist disables writing: the reason goes
	 * to the PHP error log, nothing is written and nothing is thrown.
	 */
	public function test_setLogPath_to_missing_directory_disables_writing() {
		$php_errors = $this->sandbox . '/php-errors.log';
		$this->set_ini( 'error_log', $php_errors );
		$logger = ShortPixelLogger::getInstance();

		$logger->setLogPath( $this->sandbox . '/missing/emr.log' );
		ShortPixelLogger::addError( 'goes nowhere' );

		$this->assertFalse( $this->get_prop( $logger, 'logFile' ) );
		$this->assertFileDoesNotExist( $this->sandbox . '/missing/emr.log' );
		$this->assertStringContainsString( 'ShortpixelLogger: Log Directory is not writable : ' . $this->sandbox . '/missing', file_get_contents( $php_errors ) );
		$this->assertCount( 1, $this->get_prop( $logger, 'items' ), 'The item is still kept in memory.' );
	}

	/**
	 * setLogPath() reopens the log file but never closes the previous
	 * handle: getWriteFile() closes it only when `is_object($this->logFile)`,
	 * and an fopen() handle is a resource, not an object. Every
	 * EnableMediaReplacePlugin::get() call (each emr()) calls setLogPath()
	 * while debugging is on, so a request leaks one open file handle per
	 * emr() call. The previous handle should be closed with
	 * is_resource().
	 *
	 * Where: ShortPixelLogger::getWriteFile().
	 *
	 * How to recognise the fix: the first handle is closed after the second
	 * setLogPath() (is_resource() returns false).
	 */
	public function test_setLogPath_leaks_the_previous_handle_pinned_for_deferred_fix() {
		$logger = ShortPixelLogger::getInstance();
		$logger->setLogPath( $this->sandbox . '/first.log' );
		$first           = $this->get_prop( $logger, 'logFile' );
		$this->handles[] = $first;
		$this->assertTrue( is_resource( $first ) );
		$this->assertFalse( is_object( $first ) );

		$logger->setLogPath( $this->sandbox . '/second.log' );
		$second          = $this->get_prop( $logger, 'logFile' );
		$this->handles[] = $second;

		$this->assertNotSame( $first, $second );
		$this->assertTrue( is_resource( $first ) );
		$this->assertSame( 'stream', get_resource_type( $first ) );
	}

	/**
	 * With no log path set, the first message picks
	 * `<uploads basedir>/EnableMediaReplace.log`. addLog() assigns the
	 * return value of setLogPath() to logPath, and setLogPath() returns
	 * nothing, so the path is lost right after the file is opened: lines
	 * are written, but getLogPath() returns false and initView() would
	 * build an empty log link. The path should be set without the
	 * assignment.
	 *
	 * Where: ShortPixelLogger::addLog(), `$this->logPath = $this->setLogPath(...)`.
	 *
	 * How to recognise the fix: getLogPath() returns the
	 * EnableMediaReplace.log path.
	 */
	public function test_automatic_log_path_is_lost_after_first_write_pinned_for_deferred_fix() {
		$sandbox = $this->sandbox;
		$cb      = function ( $dirs ) use ( $sandbox ) {
			$dirs['basedir'] = $sandbox;
			return $dirs;
		};
		add_filter( 'upload_dir', $cb );
		$this->added_filters[] = array( 'upload_dir', $cb );
		$logger = ShortPixelLogger::getInstance();
		$this->set_prop( $logger, 'logPath', false );
		$this->set_prop( $logger, 'logFile', null );

		ShortPixelLogger::addInfo( 'automatic path' );
		$this->handles[] = $this->get_prop( $logger, 'logFile' );

		$auto = $this->sandbox . '/EnableMediaReplace.log';
		$this->assertFileExists( $auto );
		$this->assertStringContainsString( 'automatic path', file_get_contents( $auto ) );
		$this->assertNull( $this->get_prop( $logger, 'logPath' ) );
		$this->assertFalse( ShortPixelLogger::getLogPath() );
	}

	/*
	 * Manual debugging through the request.
	 */

	public function test_request_flag_true_from_admin_starts_manual_info_logging() {
		$this->make_admin();
		$_REQUEST['SHORTPIXEL_DEBUG'] = 'true';

		$logger = $this->fresh_logger();

		$this->assertTrue( ShortPixelLogger::isManualDebug() );
		$this->assertTrue( ShortPixelLogger::debugIsActive() );
		$this->assertSame( DebugItem::LEVEL_INFO, $this->get_prop( $logger, 'logLevel' ) );
	}

	public function test_request_flag_number_from_admin_sets_that_level() {
		$this->make_admin();
		$_REQUEST['SHORTPIXEL_DEBUG'] = '2';

		$logger = $this->fresh_logger();

		$this->assertTrue( ShortPixelLogger::isManualDebug() );
		$this->assertSame( 2, $this->get_prop( $logger, 'logLevel' ) );
	}

	public function test_request_flag_from_non_admin_is_ignored() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['SHORTPIXEL_DEBUG'] = '1';

		$logger = $this->fresh_logger();

		$this->assertFalse( ShortPixelLogger::isManualDebug() );
		$this->assertSame( 4, $this->get_prop( $logger, 'logLevel' ), 'The constant applies instead.' );
	}

	public function test_manual_logging_writes_only_for_administrators() {
		$this->make_admin();
		$_REQUEST['SHORTPIXEL_DEBUG'] = 'true';
		$logger = $this->fresh_logger();
		$this->point_logger_at( $logger, $this->log );

		ShortPixelLogger::addInfo( 'admin line' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ShortPixelLogger::addInfo( 'subscriber line' );

		$contents = $this->log_contents();
		$this->assertStringContainsString( 'admin line', $contents );
		$this->assertStringNotContainsString( 'subscriber line', $contents );
	}

	/*
	 * The debug box view.
	 */

	public function test_initView_does_nothing_without_a_manual_request() {
		$this->make_admin();
		$logger = ShortPixelLogger::getInstance();
		$this->set_prop( $logger, 'view', null );

		$logger->initView();

		$this->assertFalse( has_action( 'admin_footer', array( $logger, 'loadView' ) ) );
		$this->assertNull( $this->get_prop( $logger, 'view' ) );
	}

	public function test_debug_box_renders_for_manual_admin_request_with_log_link() {
		$this->make_admin();
		$_REQUEST['SHORTPIXEL_DEBUG'] = 'true';
		$logger = $this->fresh_logger();
		$upload = wp_upload_dir( null, false, false );
		$this->set_prop( $logger, 'logPath', trailingslashit( $upload['basedir'] ) . 'emr-debug-box.log' );

		$logger->initView();
		$this->assertSame( 10, has_action( 'admin_footer', array( $logger, 'loadView' ) ) );

		ob_start();
		$logger->loadView();
		$html = ob_get_clean();

		$this->assertStringContainsString( "<div class='sp_debug_box'>", $html );
		$this->assertStringContainsString( 'EnableMediaReplace Debug Box', $html );
		$this->assertStringContainsString( "href='view-source:" . trailingslashit( $upload['baseurl'] ) . "emr-debug-box.log'", $html );
		$this->assertTrue( wp_script_is( 'jquery-ui-draggable', 'enqueued' ) );
		wp_dequeue_script( 'jquery-ui-draggable' );
	}
}
