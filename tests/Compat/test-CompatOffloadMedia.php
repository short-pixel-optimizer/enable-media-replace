<?php
/**
 * Cross-plugin compatibility: WP Offload Media Lite.
 *
 * Runs with the REAL amazon-s3-and-cloudfront plugin active (bin/test.sh
 * --compat downloads + activates it). The plugin is left UNCONFIGURED: no
 * access keys and no bucket, so is_plugin_setup() is false and it never
 * uploads anything. Covers classes/external/wp-offload.php and the hooks
 * ReplaceController fires for it.
 *
 * Boot order: as3cf fires `as3cf_init` from its own `init` callback
 * (priority 10), registered when its plugin file loads. EMR constructs
 * WPOffload from EnableMediaReplacePlugin::runtime() on the same `init`
 * priority, after as3cf in plugin load order, so the `as3cf_init` listener
 * arrives late; that is pinned below. Every other test constructs WPOffload
 * and calls init( $as3cf ) itself, which is the state the integration is
 * written for.
 *
 * Offloaded state: a real Media_Library_Item row is saved for the
 * attachment (SPIO's compat pattern, no S3 credentials needed). The row
 * has no per-size file records, so the remove-provider handler finds no
 * object to delete and answers "not handled" without reaching the
 * provider client. The as3cf tables are created by
 * tests/Integration/bootstrap.php before the first test transaction; rows
 * written here roll back.
 *
 * Unit coverage with the partner ABSENT (stubbed item class, argument
 * shapes, the isFileMovable / checkScaledUrl pins) lives in
 * tests/Controller/test-WPOffload.php.
 *
 * Snapshot/restore: WPOffload's static instance and prevention registry,
 * the Externals singletons that runtime() constructs, the current user,
 * hooks (WP_UnitTestCase), attachments and files (helpers).
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once dirname( __DIR__ ) . '/ViewController/ViewControllerTestDoubles.php';

use DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item;
use EnableMediaReplace\Externals\WPOffload;

class CompatOffloadMediaTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;

	/** The five EMR-side hooks WPOffload::init() registers, hook => method. */
	const EMR_HOOKS = array(
		'emr_after_remove_current'         => 'removeRemote',
		'emr/file/virtual/translate'       => 'getLocalPath',
		'emr/replace/file_is_movable'      => 'isFileMovable',
		'emr/replace/original_image_path'  => 'checkScaledUrl',
		'enable-media-replace-upload-done' => 'updateOriginalPath',
	);

	/** Singletons runtime() may construct, class => static property. */
	const EXTERNAL_SINGLETONS = array(
		'EnableMediaReplace\Externals\WPOffload'  => 'instance',
		'EnableMediaReplace\Externals\Elementor'  => 'instance',
		'EnableMediaReplace\Externals\WpBakery'   => 'instance',
		'EnableMediaReplace\Externals\SiteOrigin' => 'instance',
	);

	/** @var array Static values before the test: "class::property" => value. */
	private $savedStatics = array();

	/** @var array Results of the remove-provider handler: array( result, source_id ). */
	private $removeResults = array();

	public function set_up() {
		if ( ! class_exists( 'Amazon_S3_And_CloudFront' ) ) {
			$this->markTestSkipped( 'WP Offload Media is not loaded — run via bin/test.sh --compat.' );
		}
		parent::set_up();
		$this->flowSetUp();

		$this->savedStatics = array();
		foreach ( self::EXTERNAL_SINGLETONS as $class => $property ) {
			$this->savedStatics[ $class . '::' . $property ] = $this->staticProp( $class, $property )->getValue();
		}
		$this->savedStatics['offloadPrevented'] = $this->staticProp( WPOffload::class, 'offloadPrevented' )->getValue();
		$this->staticProp( WPOffload::class, 'instance' )->setValue( null, null );

		$this->removeResults = array();
		$results             = &$this->removeResults;
		$this->addHook(
			'as3cf_post_handle_item_remove-provider',
			function ( $result, $item ) use ( &$results ) {
				$results[] = array( $result, $item->source_id() );
			},
			10,
			3
		);
	}

	public function tear_down() {
		$this->flowTearDown();
		foreach ( self::EXTERNAL_SINGLETONS as $class => $property ) {
			$this->staticProp( $class, $property )->setValue( null, $this->savedStatics[ $class . '::' . $property ] );
		}
		$this->staticProp( WPOffload::class, 'offloadPrevented' )->setValue( null, $this->savedStatics['offloadPrevented'] );
		$this->savedStatics = array();
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Plumbing.
	// -------------------------------------------------------------------

	private function staticProp( $class, $name ) {
		$prop = new ReflectionProperty( $class, $name );
		$prop->setAccessible( true );
		return $prop;
	}

	/** The WPOffload singleton after init( $as3cf ), asserted to pass the checks. */
	private function initOffload(): WPOffload {
		$offload = WPOffload::getInstance();
		$this->assertNull( $offload->init( $GLOBALS['as3cf'] ), 'Precondition: init() passes the compatibility checks.' );
		return $offload;
	}

	/** Saves a real as3cf item row marking the attachment as offloaded. */
	private function makeOffloadItem( int $attachment_id ): Media_Library_Item {
		$source_path = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$item        = new Media_Library_Item(
			'aws',
			'us-east-1',
			'emr-test-bucket',
			'wp-content/uploads/' . $source_path,
			false,
			$attachment_id,
			$source_path,
			wp_basename( $source_path )
		);
		$saved       = $item->save();
		$this->assertIsInt( $saved, 'Precondition: the as3cf item row saves.' );
		$this->assertGreaterThan( 0, $saved );
		return $item;
	}

	/** The item row of an attachment, read from the database. */
	private function itemRow( int $attachment_id ) {
		global $wpdb;
		$table = Media_Library_Item::get_table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_type = %s AND source_id = %d", 'media-library', $attachment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Number of item rows of an attachment. */
	private function itemRowCount( int $attachment_id ): int {
		global $wpdb;
		$table = Media_Library_Item::get_table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_id = %d", $attachment_id ) ); // phpcs:ignore WordPress.DB
	}

	// -------------------------------------------------------------------
	// Coexistence and boot.
	// -------------------------------------------------------------------

	public function test_offload_media_loads_alongside_emr_and_fired_as3cf_init_once() {
		$this->assertTrue( defined( 'AS3CF_FILE' ), 'as3cf defines its plugin file constant.' );
		$this->assertInstanceOf( 'Amazon_S3_And_CloudFront', $GLOBALS['as3cf'] );
		$this->assertSame( 1, did_action( 'as3cf_init' ), 'as3cf_init fired exactly once.' );
		$this->assertTrue( class_exists( 'DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item' ), 'The class WPOffload::init() checks for exists.' );
		$this->assertTrue( method_exists( $GLOBALS['as3cf'], 'get_item_handler' ), 'The method WPOffload::init() checks for exists.' );
		$this->assertFalse( $GLOBALS['as3cf']->is_plugin_setup( true ), 'The install is unconfigured: nothing is uploaded.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * WPOffload subscribes to `as3cf_init` in its constructor, and EMR
	 * constructs it from EnableMediaReplacePlugin::runtime() (through
	 * Externals) on `init`. WP Offload Media fires `as3cf_init` from its own
	 * `init` callback at the same priority, registered earlier because its
	 * plugin file loads first (plugin folders load in alphabetical order:
	 * amazon-s3-and-cloudfront before enable-media-replace). By the time
	 * WPOffload listens, `as3cf_init` has fired, so init() never runs:
	 * $as3cf stays null and none of the replace-time hooks
	 * (removeRemote, getLocalPath, isFileMovable, checkScaledUrl,
	 * updateOriginalPath) is registered. Offloaded files can be moved, their
	 * remote copies are not removed and their item paths are not updated.
	 * The constructor (or Externals) should call init( $GLOBALS['as3cf'] )
	 * when did_action( 'as3cf_init' ) is already non-zero.
	 *
	 * How to recognise the fix: after runtime() the WPOffload instance holds
	 * the as3cf object and the hooks are registered, so the assertNull and
	 * assertFalse assertions below fail.
	 */
	public function test_wpoffload_subscribes_after_as3cf_init_fired_so_init_never_runs_pinned_for_deferred_fix() {
		global $wp_filter;
		$order = array();
		foreach ( $wp_filter['init']->callbacks[10] as $callback ) {
			$function = $callback['function'];
			if ( 'as3cf_init' === $function ) {
				$order[] = 'as3cf';
			} elseif ( is_array( $function ) && emr() === $function[0] && 'runtime' === $function[1] ) {
				$order[] = 'emr';
			}
		}
		$this->assertSame( array( 'as3cf', 'emr' ), $order, 'Precondition: on init 10, as3cf_init runs before EMR runtime().' );
		$this->assertSame( 1, did_action( 'as3cf_init' ), 'Precondition: as3cf_init has fired.' );
		$this->assertNull( $this->staticProp( WPOffload::class, 'instance' )->getValue(), 'Precondition: no WPOffload yet.' );

		// What init does for a user with upload_files (the flow administrator).
		emr()->runtime();

		$offload = $this->staticProp( WPOffload::class, 'instance' )->getValue();
		$this->assertInstanceOf( WPOffload::class, $offload, 'runtime() constructed WPOffload.' );
		$this->assertSame( 10, has_action( 'as3cf_init', array( $offload, 'init' ) ), 'It listens to as3cf_init...' );
		$as3cf = new ReflectionProperty( WPOffload::class, 'as3cf' );
		$as3cf->setAccessible( true );
		$this->assertNull( $as3cf->getValue( $offload ), 'Pinned: ...which already fired, so init() never stored the as3cf object.' );
		foreach ( self::EMR_HOOKS as $hook => $method ) {
			$this->assertFalse( has_filter( $hook, array( $offload, $method ) ), "Pinned: $hook -> $method is not registered." );
		}
	}

	/**
	 * init() with the real as3cf object stores it and registers the five
	 * EMR-side hooks at priority 10. With SPIO active (a --compat run) the
	 * `shortpixel/image/urltopath` callback is left out; see
	 * test-CompatShortPixel.php.
	 */
	public function test_init_stores_the_main_object_and_registers_the_five_emr_hooks() {
		$offload = $this->initOffload();

		$as3cf = new ReflectionProperty( WPOffload::class, 'as3cf' );
		$as3cf->setAccessible( true );
		$this->assertSame( $GLOBALS['as3cf'], $as3cf->getValue( $offload ) );
		foreach ( self::EMR_HOOKS as $hook => $method ) {
			$this->assertSame( 10, has_filter( $hook, array( $offload, $method ) ), "$hook -> $method" );
		}
		$this->assertSame( 10, has_action( 'emr/converter/prevent-offload', array( $offload, 'preventOffload' ) ) );
		$this->assertSame( 10, has_action( 'emr/converter/prevent-offload-off', array( $offload, 'preventOffloadOff' ) ) );
		$this->assertSame( 10, has_filter( 'as3cf_pre_update_attachment_metadata', array( $offload, 'preventUpdateMetaData' ) ) );
	}

	/**
	 * EMR's bundled filesystem module asks `emr/image/urltopath` whether a
	 * URL-shaped (remote-only) path belongs to an offloaded file; that is
	 * the only way such a source file can be reported as existing. WPOffload
	 * registers its answer, checkIfOffloaded(), on `shortpixel/image/urltopath`
	 * instead (the module's hook name before the build rewrites the prefix),
	 * and only when SPIO is not active, so nothing answers EMR's filter even
	 * with WP Offload Media active and init() run. checkIfOffloaded() should
	 * be registered on `emr/image/urltopath`.
	 *
	 * How to recognise the fix: has_filter( 'emr/image/urltopath' ) is no
	 * longer false and the first assertion after init() fails.
	 */
	public function test_nothing_answers_the_filesystem_urltopath_filter_pinned_for_deferred_fix() {
		$offload = $this->initOffload();

		$this->assertFalse( has_filter( 'emr/image/urltopath' ), 'Pinned: no callback on the filter EMR fires.' );
		$this->assertFalse( has_filter( 'shortpixel/image/urltopath', array( $offload, 'checkIfOffloaded' ) ), 'EMR registers nothing on the old name either (SPIO is active).' );

		// The filter EMR's filesystem fires for a remote-only path.
		$seen = array();
		$this->addHook(
			'emr/image/urltopath',
			function ( $bool, $url ) use ( &$seen ) {
				$seen[] = $url;
				return $bool;
			},
			10,
			2
		);
		$file = emr()->filesystem()->getFile( 'https://emr-test-bucket.s3.amazonaws.com/wp-content/uploads/2020/01/remote-only.jpg' );
		$this->assertFalse( $file->exists(), 'Unanswered, the remote-only file does not exist for EMR.' );
		$this->assertCount( 1, $seen, 'The filesystem module consulted emr/image/urltopath.' );
		$this->assertStringContainsString( 'remote-only.jpg', $seen[0] );
	}

	// -------------------------------------------------------------------
	// A replace of a local (not offloaded) attachment.
	// -------------------------------------------------------------------

	/**
	 * A plain MODE_REPLACE with as3cf active and WPOffload initialised: the
	 * replace runs end to end, the files stay local, no as3cf item is
	 * created, and removeRemote() finds nothing to remove (the
	 * remove-provider handler never runs).
	 */
	public function test_replace_of_a_local_attachment_stays_local() {
		$this->initOffload();
		$id = $this->uploadJpeg( 'emr-offload-local-' . $this->token() . '.jpg', 800, 600 );
		$this->assertFalse( Media_Library_Item::get_by_source_id( $id ), 'Precondition: not offloaded.' );
		$this->assertTrue( apply_filters( 'emr/replace/file_is_movable', true, $id ), 'A local file is movable.' );
		$this->addHook( 'emr_after_remove_current', $this->record( 'removed_current' ), 9, 5 );

		$this->replaceMode( $id, $this->tmpJpeg( 800, 600, array( 10, 160, 60 ) ) );

		$file = get_attached_file( $id );
		$this->assertFileExists( $file, 'The new main file is local.' );
		foreach ( $this->sizePaths( $id ) as $size => $path ) {
			$this->assertFileExists( $path, "Size $size is local." );
		}
		$this->assertSame( 0, $this->itemRowCount( $id ), 'No as3cf item row was created.' );
		$this->assertSame( array( 'removed_current' ), $this->eventNames(), 'emr_after_remove_current fired once.' );
		$this->assertSame( $id, $this->events[0][1][0] );
		$this->assertSame( array(), $this->removeResults, 'removeRemote() did not call the remove handler.' );
	}

	// -------------------------------------------------------------------
	// An offloaded attachment.
	// -------------------------------------------------------------------

	/**
	 * With an as3cf item row the file is not movable: the replace form's
	 * `new_location` is turned off by UploadViewController::getPost(), so
	 * a replace-and-search that asked for another folder runs in the
	 * file's own folder. There is no error code for this; the request is
	 * not refused, the move is dropped.
	 */
	public function test_offloaded_attachment_is_not_movable_so_a_new_location_is_dropped() {
		$this->initOffload();
		$id = $this->uploadJpeg( 'emr-offload-move-' . $this->token() . '.jpg', 800, 600 );
		$this->makeOffloadItem( $id );
		$old_dir = dirname( get_attached_file( $id ) );
		$this->assertFalse( apply_filters( 'emr/replace/file_is_movable', true, $id ), 'An offloaded file is not movable.' );

		$location = 'emr-offload-target-' . $this->token();
		$_POST    = array(
			'ID'                => (string) $id,
			'replace_type'      => 'replace_and_search',
			'new_location'      => '1',
			'location_dir'      => $location,
			'timestamp_replace' => (string) EnableMediaReplace\Controller\ReplaceController::TIME_UPDATEMODIFIED,
		);
		$new_name                = 'emr-offload-moved-' . $this->token() . '.jpg';
		$controller              = new EMR_TestUploadViewController();
		$controller->fakeUpload  = array( $this->tmpJpeg( 800, 600 ), $new_name );
		$params                  = $controller->callGetPost();
		$_POST                   = array();

		$this->assertFalse( $params['new_location'], 'getPost() dropped the move.' );
		$this->assertSame( $location, $params['location_dir'], 'The folder itself was read.' );

		$this->replaceVia( $id, $params );

		$file = get_attached_file( $id );
		$this->assertSame( $old_dir, dirname( $file ), 'The file stayed in its folder.' );
		$this->assertSame( $new_name, wp_basename( $file ), 'Under the new name.' );
		$this->assertDirectoryDoesNotExist( trailingslashit( wp_upload_dir()['basedir'] ) . $location, 'The requested folder was not created.' );
	}

	/**
	 * MODE_REPLACE on an offloaded attachment runs; `emr_after_remove_current`
	 * reaches WPOffload::removeRemote(), which hands the item to as3cf's
	 * remove-provider handler. The seeded row has no per-size file records,
	 * so the handler's manifest is empty and it answers false ("not
	 * handled") without calling the provider; removeRemote() ignores the
	 * answer. The item row is kept.
	 */
	public function test_replace_of_an_offloaded_attachment_hands_it_to_the_remove_handler() {
		$this->initOffload();
		$id = $this->uploadJpeg( 'emr-offload-replace-' . $this->token() . '.jpg', 800, 600 );
		$this->makeOffloadItem( $id );
		$row_before = $this->itemRow( $id );
		$this->addHook( 'emr_after_remove_current', $this->record( 'removed_current' ), 9, 5 );

		$this->replaceMode( $id, $this->tmpJpeg( 800, 600, array( 90, 20, 160 ) ) );

		$this->assertSame( array( 'removed_current' ), $this->eventNames() );
		$this->assertSame( array( array( false, $id ) ), $this->removeResults, 'The remove handler ran once for the item and answered false.' );
		$row_after = $this->itemRow( $id );
		$this->assertIsArray( $row_after, 'The item row is kept.' );
		$this->assertSame( $row_before['id'], $row_after['id'] );
		$this->assertSame( $row_before['path'], $row_after['path'], 'Same name: the paths are unchanged.' );
		$this->assertSame( $row_before['original_path'], $row_after['original_path'] );
		$this->assertFileExists( get_attached_file( $id ), 'The new file is local.' );
	}

	/**
	 * A replace-and-search to a new name: updateOriginalPath(), on
	 * `enable-media-replace-upload-done`, gives the item's `original_path`
	 * and `original_source_path` the new basename and saves the item. The
	 * `path` and `source_path` (the offloaded object's key) keep the old
	 * name, as documented.
	 */
	public function test_rename_updates_the_item_original_paths() {
		$this->initOffload();
		$old_name = 'emr-offload-old-' . $this->token() . '.jpg';
		$id       = $this->uploadJpeg( $old_name, 800, 600 );
		$this->makeOffloadItem( $id );
		$old_base = wp_basename( get_attached_file( $id ) );
		$new_name = 'emr-offload-new-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 800, 600 ), $new_name );

		$this->assertSame( $new_name, wp_basename( get_attached_file( $id ) ), 'Precondition: WordPress has the new name.' );
		$row = $this->itemRow( $id );
		$this->assertIsArray( $row );
		$this->assertSame( $new_name, wp_basename( $row['original_path'] ), 'original_path has the new name.' );
		$this->assertSame( $new_name, wp_basename( $row['original_source_path'] ), 'original_source_path has the new name.' );
		$this->assertSame( dirname( $row['path'] ), dirname( $row['original_path'] ), 'Same folder.' );
		$this->assertSame( $old_base, wp_basename( $row['path'] ), 'path keeps the old name.' );
		$this->assertSame( $old_base, wp_basename( $row['source_path'] ), 'source_path keeps the old name.' );

		$item = Media_Library_Item::get_by_source_id( $id );
		$this->assertInstanceOf( Media_Library_Item::class, $item );
		$this->assertSame( $new_name, wp_basename( $item->original_path() ), 'The as3cf item API reports the new original path.' );
		$this->assertSame( $new_name, wp_basename( $item->original_source_path() ) );
	}

	// -------------------------------------------------------------------
	// The prevent-offload bracket.
	// -------------------------------------------------------------------

	/**
	 * ReplaceController brackets wp_generate_attachment_metadata() with
	 * `emr/converter/prevent-offload` / `-off`, so WPOffload answers true
	 * (cancel) on `as3cf_pre_update_attachment_metadata` for that
	 * attachment while the new metadata is generated, and passes the value
	 * through again for EMR's own wp_update_attachment_metadata() after it.
	 *
	 * as3cf asks that question from its `wp_update_attachment_metadata`
	 * callback (priority 110), but only once the plugin is set up; this
	 * install is not, so the test asks it at the same point, priority 109,
	 * with as3cf's arguments, and a spy at priority 11 records the answer
	 * after WPOffload's (and SPIO's) callbacks at 10.
	 */
	public function test_prevent_offload_bracket_cancels_as3cf_metadata_updates_during_generation_only() {
		WPOffload::getInstance();
		$id = $this->uploadJpeg( 'emr-offload-bracket-' . $this->token() . '.jpg', 800, 600 );
		$this->assertFalse( $GLOBALS['as3cf']->is_plugin_setup( true ), 'Precondition: as3cf does not ask by itself here.' );

		$answers = array();
		$phase   = 'before';
		$this->addHook(
			'emr/converter/prevent-offload',
			function () use ( &$phase ) {
				$phase = 'generating';
			},
			20,
			1
		);
		$this->addHook(
			'emr/converter/prevent-offload-off',
			function () use ( &$phase ) {
				$phase = 'after';
			},
			20,
			1
		);
		$this->addHook(
			'wp_update_attachment_metadata',
			function ( $data, $post_id ) {
				apply_filters( 'as3cf_pre_update_attachment_metadata', false, $data, $post_id, Media_Library_Item::get_by_source_id( $post_id ) ?: null );
				return $data;
			},
			109,
			2
		);
		$this->addHook(
			'as3cf_pre_update_attachment_metadata',
			function ( $cancel, $data, $post_id ) use ( &$answers, &$phase, $id ) {
				if ( $post_id === $id ) {
					$answers[] = array( $phase, $cancel );
				}
				return $cancel;
			},
			11,
			3
		);

		$this->replaceMode( $id, $this->tmpJpeg( 800, 600, array( 250, 200, 0 ) ) );

		$during = array_values( array_filter( $answers, function ( $a ) { return 'generating' === $a[0]; } ) );
		$after  = array_values( array_filter( $answers, function ( $a ) { return 'after' === $a[0]; } ) );
		$this->assertNotEmpty( $during, 'WordPress updated the metadata while generating it.' );
		foreach ( $during as $answer ) {
			$this->assertTrue( $answer[1], 'Cancelled while the metadata is generated.' );
		}
		$this->assertSame( array( array( 'after', false ) ), $after, "EMR's own update after the bracket is not cancelled." );
		$this->assertSame( array(), array_filter( $answers, function ( $a ) { return 'before' === $a[0]; } ), 'No metadata update before the bracket.' );
		$this->assertFalse( apply_filters( 'as3cf_pre_update_attachment_metadata', false, array(), $id, null ), 'The mark is lifted after the replace.' );
	}
}
