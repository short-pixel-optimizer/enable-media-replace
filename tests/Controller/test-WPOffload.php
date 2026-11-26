<?php
/**
 * Tests for EnableMediaReplace\Externals\WPOffload
 * (classes/external/wp-offload.php) with WP Offload Media absent.
 *
 * WP Offload Media is replaced by stand-ins declared in this file:
 * - EMR_Test_As3cf / EMR_Test_As3cf_Legacy: the main plugin object passed
 *   to `as3cf_init` (with and without get_item_handler()); it hands out
 *   the media item class name through get_source_type_class().
 * - EMR_Test_Offload_Media: the media item class, with the two static
 *   lookups WPOffload uses (get_by_source_id(), get_item_source_by_remote_url()),
 *   answering from static tables and recording every URL it is asked.
 * - EMR_Test_Offload_Item: a media item with original_source_path() and
 *   extra_info().
 * The stand-in object is put into the private `$as3cf` by reflection, so
 * init() is only needed for its own tests. Two of those declare the real
 * `\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item` class name
 * and run in a separate process, so the class never exists for any other
 * test.
 *
 * Snapshot/restore: the WPOffload singleton and its static
 * `$offloadPrevented`, the NoticeController statics and option, the
 * stand-in tables, the attachments created. Nothing here talks HTTP; a
 * fail-fast `pre_http_request` filter at priority 9999 records any request
 * and tear_down() asserts none was made.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Externals\WPOffload;
use EnableMediaReplace\Notices\NoticeController;
use EnableMediaReplace\Notices\NoticeModel;

/**
 * Stand-in for the WP Offload Media main object, current API.
 */
class EMR_Test_As3cf {
	/** @var string[] Source types asked for. */
	public $asked = array();

	public function get_source_type_class( $type ) {
		$this->asked[] = $type;
		return EMR_Test_Offload_Media::$class_name;
	}

	public function get_item_handler( $key ) {
		return null;
	}
}

/**
 * Stand-in for an old WP Offload Media main object without get_item_handler().
 */
class EMR_Test_As3cf_Legacy {
	public function get_source_type_class( $type ) {
		return EMR_Test_Offload_Media::$class_name;
	}
}

/**
 * Stand-in for the media library item class and its static lookups.
 */
class EMR_Test_Offload_Media {
	/** @var string Class name get_source_type_class() returns. */
	public static $class_name = 'EMR_Test_Offload_Media';

	/** @var array<int,mixed> Item per attachment id; missing ids give false. */
	public static $items = array();

	/** @var array<string,array> Answer per remote URL; missing URLs give array(). */
	public static $sources = array();

	/** @var string[] URLs asked through get_item_source_by_remote_url(). */
	public static $asked = array();

	public static function reset() {
		self::$class_name = 'EMR_Test_Offload_Media';
		self::$items      = array();
		self::$sources    = array();
		self::$asked      = array();
	}

	public static function get_by_source_id( $id ) {
		return array_key_exists( $id, self::$items ) ? self::$items[ $id ] : false;
	}

	public static function get_item_source_by_remote_url( $url ) {
		self::$asked[] = $url;
		return isset( self::$sources[ $url ] ) ? self::$sources[ $url ] : array();
	}
}

/**
 * Stand-in for an offloaded media item.
 */
class EMR_Test_Offload_Item {
	public $original_path;
	public $original_source_path;
	public $extra = array();
	public $saved = 0;

	public function __construct( $original_source_path, $extra = array() ) {
		$this->original_path        = $original_source_path;
		$this->original_source_path = $original_source_path;
		$this->extra                = $extra;
	}

	public function original_source_path() {
		return $this->original_source_path;
	}

	public function original_path() {
		return $this->original_path;
	}

	public function path() {
		return $this->original_path;
	}

	public function source_path() {
		return $this->original_source_path;
	}

	public function set_original_path( $path ) {
		$this->original_path = $path;
	}

	public function set_original_source_path( $path ) {
		$this->original_source_path = $path;
	}

	public function save() {
		++$this->saved;
	}

	public function extra_info() {
		return $this->extra;
	}
}

class WPOffloadTest extends WP_UnitTestCase {

	const NOTICE_OPTION = 'EnableMediaReplace-notices';
	const WARNING       = 'Your S3-Offload plugin version doesn\'t seem to be compatible. Please upgrade the S3-Offload plugin';

	private $saved_instance;
	private $saved_prevented;
	private $saved_notice_statics = array();
	private $saved_notice_option;

	/**
	 * @var int[]
	 */
	private $attachments = array();

	/**
	 * @var string[]
	 */
	private $blocked = array();

	public function set_up() {
		parent::set_up();
		$this->saved_instance  = $this->get_static( WPOffload::class, 'instance' );
		$this->saved_prevented = $this->get_static( WPOffload::class, 'offloadPrevented' );
		$this->set_static( WPOffload::class, 'offloadPrevented', array() );
		foreach ( array( 'notices', 'instance', 'cssHookLoaded', 'newNotices' ) as $name ) {
			$this->saved_notice_statics[ $name ] = $this->get_static( NoticeController::class, $name );
		}
		$this->saved_notice_option = get_option( self::NOTICE_OPTION, null );
		$this->set_static( NoticeController::class, 'instance', null );
		$this->set_static( NoticeController::class, 'notices', array() );
		$this->set_static( NoticeController::class, 'newNotices', array() );
		EMR_Test_Offload_Media::reset();
		$this->blocked = array();
		add_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fail_fast' ), 9999 );
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachments = array();
		EMR_Test_Offload_Media::reset();
		$this->set_static( WPOffload::class, 'instance', $this->saved_instance );
		$this->set_static( WPOffload::class, 'offloadPrevented', $this->saved_prevented );
		foreach ( $this->saved_notice_statics as $name => $value ) {
			$this->set_static( NoticeController::class, $name, $value );
		}
		if ( null === $this->saved_notice_option ) {
			delete_option( self::NOTICE_OPTION );
		} else {
			update_option( self::NOTICE_OPTION, $this->saved_notice_option );
		}
		$blocked       = $this->blocked;
		$this->blocked = array();
		parent::tear_down();
		$this->assertSame( array(), $blocked, 'A request reached the fail-fast filter.' );
	}

	/**
	 * Refuses and records every HTTP request nothing else answered.
	 */
	public function fail_fast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->blocked[] = $url;
		return new WP_Error( 'http_blocked', 'Blocked unmocked request to ' . $url );
	}

	private function get_static( $class, $name ) {
		$ref = new ReflectionProperty( $class, $name );
		$ref->setAccessible( true );
		return $ref->getValue();
	}

	private function set_static( $class, $name, $value ) {
		$ref = new ReflectionProperty( $class, $name );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	private function prop( $object, $name ) {
		$ref = new ReflectionProperty( WPOffload::class, $name );
		$ref->setAccessible( true );
		return $ref->getValue( $object );
	}

	/**
	 * A WPOffload with the stand-in main object already stored.
	 */
	private function offload( $as3cf = null ) {
		$offload = new WPOffload();
		$ref     = new ReflectionProperty( WPOffload::class, 'as3cf' );
		$ref->setAccessible( true );
		$ref->setValue( $offload, null === $as3cf ? new EMR_Test_As3cf() : $as3cf );
		return $offload;
	}

	private function call( $object, $method, array $args = array() ) {
		$ref = new ReflectionMethod( WPOffload::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $object, $args );
	}

	private function warnings() {
		return array_values(
			array_filter(
				NoticeController::getInstance()->getNotices(),
				function ( $notice ) {
					return NoticeModel::NOTICE_WARNING === $notice->messageType;
				}
			)
		);
	}

	/*
	 * Constructor, getInstance(), init().
	 */

	public function test_constructor_registers_the_always_on_hooks() {
		$offload = new WPOffload();

		$this->assertSame( 10, has_action( 'as3cf_init', array( $offload, 'init' ) ) );
		$this->assertSame( 10, has_action( 'emr/converter/prevent-offload', array( $offload, 'preventOffload' ) ) );
		$this->assertSame( 10, has_action( 'emr/converter/prevent-offload-off', array( $offload, 'preventOffloadOff' ) ) );
		$this->assertSame( 10, has_filter( 'as3cf_pre_update_attachment_metadata', array( $offload, 'preventUpdateMetaData' ) ) );
		$this->assertFalse( has_filter( 'emr/file/virtual/translate', array( $offload, 'getLocalPath' ) ), 'The replace hooks wait for init().' );
	}

	public function test_get_instance_is_a_singleton() {
		$this->set_static( WPOffload::class, 'instance', null );

		$first = WPOffload::getInstance();

		$this->assertInstanceOf( WPOffload::class, $first );
		$this->assertSame( $first, WPOffload::getInstance() );
	}

	/**
	 * @dataProvider provide_as3cf_classes
	 */
	public function test_init_without_the_media_item_class_warns_and_returns_false( $as3cf_class ) {
		$this->assertFalse( class_exists( '\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item' ), 'Sentinel: WP Offload Media is absent.' );
		$offload = new WPOffload();

		$result = $offload->init( new $as3cf_class() );

		$this->assertFalse( $result );
		$this->assertNull( $this->prop( $offload, 'as3cf' ), 'Nothing is stored.' );
		$warnings = $this->warnings();
		$this->assertCount( 1, $warnings );
		$this->assertSame( self::WARNING, $warnings[0]->message );
		$this->assertFalse( has_filter( 'emr/file/virtual/translate', array( $offload, 'getLocalPath' ) ) );
	}

	public function provide_as3cf_classes() {
		return array(
			'current API' => array( 'EMR_Test_As3cf' ),
			'legacy API'  => array( 'EMR_Test_As3cf_Legacy' ),
		);
	}

	public function test_init_warning_is_unique() {
		$offload = new WPOffload();

		$offload->init( new EMR_Test_As3cf() );
		$offload->init( new EMR_Test_As3cf() );

		$this->assertCount( 1, $this->warnings() );
		$this->assertFalse( $this->warnings()[0]->isPersistent(), 'A unique notice, not a persistent one.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_with_an_old_main_object_stores_it_and_warns() {
		eval( 'namespace DeliciousBrains\WP_Offload_Media\Items; class Media_Library_Item {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		$offload = new WPOffload();
		$legacy  = new EMR_Test_As3cf_Legacy();

		$result = $offload->init( $legacy );

		$this->assertFalse( $result );
		$this->assertSame( $legacy, $this->prop( $offload, 'as3cf' ) );
		$this->assertSame( self::WARNING, $this->warnings()[0]->message );
		$this->assertFalse( has_filter( 'emr/file/virtual/translate', array( $offload, 'getLocalPath' ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_with_a_compatible_version_registers_the_replace_hooks() {
		eval( 'namespace DeliciousBrains\WP_Offload_Media\Items; class Media_Library_Item {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		$offload = new WPOffload();
		$as3cf   = new EMR_Test_As3cf();

		$result = $offload->init( $as3cf );

		$this->assertNull( $result );
		$this->assertSame( $as3cf, $this->prop( $offload, 'as3cf' ) );
		$this->assertSame( array(), $this->warnings() );
		$this->assertFalse( is_plugin_active( 'shortpixel-image-optimiser/wp-shortpixel.php' ), 'Sentinel: SPIO is not active.' );
		$this->assertSame( 10, has_filter( 'shortpixel/image/urltopath', array( $offload, 'checkIfOffloaded' ) ) );
		$this->assertSame( 10, has_action( 'emr_after_remove_current', array( $offload, 'removeRemote' ) ) );
		$this->assertSame( 10, has_filter( 'emr/file/virtual/translate', array( $offload, 'getLocalPath' ) ) );
		$this->assertSame( 10, has_filter( 'emr/replace/file_is_movable', array( $offload, 'isFileMovable' ) ) );
		$this->assertSame( 10, has_filter( 'emr/replace/original_image_path', array( $offload, 'checkScaledUrl' ) ) );
		$this->assertSame( 10, has_action( 'enable-media-replace-upload-done', array( $offload, 'updateOriginalPath' ) ) );
	}

	/*
	 * preventOffload(), preventOffloadOff(), preventUpdateMetaData().
	 */

	public function test_prevent_offload_cancels_the_metadata_update_for_that_attachment_only() {
		$offload = new WPOffload();

		$offload->preventOffload( 55 );

		$this->assertTrue( $offload->preventUpdateMetaData( false, array(), 55, null ) );
		$this->assertFalse( $offload->preventUpdateMetaData( false, array(), 56, null ) );
		$this->assertSame( 'keep', $offload->preventUpdateMetaData( 'keep', array(), 56, null ) );
	}

	public function test_prevent_offload_off_lifts_the_mark() {
		$offload = new WPOffload();
		$offload->preventOffload( 61 );

		$offload->preventOffloadOff( 61 );

		$this->assertFalse( $offload->preventUpdateMetaData( false, array(), 61, null ) );
		$this->assertSame( array(), $this->get_static( WPOffload::class, 'offloadPrevented' ) );
	}

	public function test_prevent_offload_off_for_an_unmarked_id_is_harmless() {
		$offload = new WPOffload();

		$offload->preventOffloadOff( 999 );

		$this->assertSame( array(), $this->get_static( WPOffload::class, 'offloadPrevented' ) );
	}

	public function test_the_registry_is_shared_between_instances_and_driven_by_the_hooks() {
		$first  = new WPOffload();
		$second = new WPOffload();

		do_action( 'emr/converter/prevent-offload', 77 );

		$this->assertSame( array( 77 => true ), $this->get_static( WPOffload::class, 'offloadPrevented' ) );
		$this->assertTrue( $second->preventUpdateMetaData( false, array(), 77, null ) );
		$this->assertTrue( apply_filters( 'as3cf_pre_update_attachment_metadata', false, array(), 77, null ) );

		do_action( 'emr/converter/prevent-offload-off', 77 );

		$this->assertFalse( $first->preventUpdateMetaData( false, array(), 77, null ) );
	}

	/*
	 * checkScaledUrl().
	 */

	/**
	 * @dataProvider provide_scaled_paths
	 */
	public function test_check_scaled_url_strips_scaled( $input, $expected ) {
		$this->assertSame( $expected, ( new WPOffload() )->checkScaledUrl( $input, 12 ) );
	}

	public function provide_scaled_paths() {
		return array(
			'scaled main file'  => array( '/uploads/2024/01/photo-scaled.jpg', '/uploads/2024/01/photo.jpg' ),
			'no scaled'         => array( '/uploads/2024/01/photo.jpg', '/uploads/2024/01/photo.jpg' ),
			'false passes'      => array( false, false ),
		);
	}

	/**
	 * checkScaledUrl() removes every `-scaled` substring anywhere in the
	 * path, not only the `-scaled` suffix WordPress adds before the
	 * extension, so a file or directory that has `-scaled` in its own name
	 * gets the wrong original path. Only a `-scaled.<ext>` ending should be
	 * removed.
	 *
	 * How to recognise the fix: `photo-scaled-x.jpg` and the directory
	 * name keep their `-scaled` and the assertSame calls fail.
	 */
	public function test_check_scaled_url_strips_scaled_anywhere_pinned_for_deferred_fix() {
		$offload = new WPOffload();

		$this->assertSame( '/uploads/2024/01/photo-x.jpg', $offload->checkScaledUrl( '/uploads/2024/01/photo-scaled-x.jpg', 12 ) );
		$this->assertSame( '/uploads/2024/01/a.jpg', $offload->checkScaledUrl( '/uploads-scaled/2024/01/a.jpg', 12 ) );
	}

	/*
	 * checkIfOffloaded().
	 */

	/**
	 * @dataProvider provide_modern_extensions
	 */
	public function test_check_if_offloaded_skips_the_lookup_for_webp_and_avif( $url ) {
		$offload = $this->offload();
		EMR_Test_Offload_Media::$sources[ $url ] = array( 'id' => 31 );

		$this->assertFalse( $offload->checkIfOffloaded( true, $url ) );
		$this->assertSame( array(), EMR_Test_Offload_Media::$asked );
	}

	public function provide_modern_extensions() {
		return array(
			'webp'        => array( 'https://cdn.example.test/u/photo.webp' ),
			'avif'        => array( 'https://cdn.example.test/u/photo.avif' ),
			'double webp' => array( 'https://cdn.example.test/u/photo.jpg.webp' ),
		);
	}

	public function test_check_if_offloaded_is_true_for_a_known_url() {
		$offload = $this->offload();
		$url     = 'https://cdn.example.test/u/photo.jpg';
		EMR_Test_Offload_Media::$sources[ $url ] = array( 'id' => '32' );

		$this->assertTrue( $offload->checkIfOffloaded( false, $url ) );
		$this->assertSame( array( $url ), EMR_Test_Offload_Media::$asked );
	}

	public function test_check_if_offloaded_is_false_for_an_unknown_url_whatever_comes_in() {
		$offload = $this->offload();

		$this->assertFalse( $offload->checkIfOffloaded( true, 'https://cdn.example.test/u/unknown.jpg' ) );
		$this->assertNotEmpty( EMR_Test_Offload_Media::$asked );
	}

	public function test_check_if_offloaded_uses_the_extra_info_cache_for_webp() {
		$offload = $this->offload();
		$url     = 'https://cdn.example.test/u/photo.jpg';
		EMR_Test_Offload_Media::$sources[ $url ] = array( 'id' => 33 );
		EMR_Test_Offload_Media::$items[33]       = new EMR_Test_Offload_Item(
			'2024/01/photo.jpg',
			array(
				'objects' => array(
					'webp'  => array( 'source_file' => 'photo.webp' ),
					'avif'  => array( 'source_file' => 'photo.avif' ),
					'other' => 'not an array',
				),
			)
		);
		$this->assertTrue( $offload->checkIfOffloaded( false, $url ) );
		EMR_Test_Offload_Media::$asked = array();

		$this->assertTrue( $offload->checkIfOffloaded( false, 'https://cdn.example.test/u/photo.webp' ) );
		$this->assertTrue( $offload->checkIfOffloaded( false, 'https://cdn.example.test/u/photo.avif' ) );
		$this->assertSame( array(), EMR_Test_Offload_Media::$asked, 'Answered from the cache.' );
	}

	/*
	 * isFileMovable().
	 */

	/**
	 * @dataProvider provide_bools
	 */
	public function test_is_file_movable_passes_the_value_when_not_offloaded( $value ) {
		$this->assertSame( $value, $this->offload()->isFileMovable( $value, 41 ) );
	}

	public function provide_bools() {
		return array(
			'true'  => array( true ),
			'false' => array( false ),
		);
	}

	public function test_is_file_movable_is_false_for_an_offloaded_item() {
		EMR_Test_Offload_Media::$items[42] = new EMR_Test_Offload_Item( '2024/01/a.jpg' );

		$this->assertFalse( $this->offload()->isFileMovable( true, 42 ) );
	}

	/**
	 * isFileMovable() has no return statement after its two checks, so a
	 * lookup that answers neither false nor an object (here a string, as a
	 * different Offload version or a broken item could) makes the filter
	 * return null instead of the incoming value. A final `return $bool;` is
	 * missing.
	 *
	 * How to recognise the fix: the result is true and the assertNull
	 * fails.
	 */
	public function test_is_file_movable_returns_null_for_a_non_object_item_pinned_for_deferred_fix() {
		EMR_Test_Offload_Media::$items[43] = 'not-an-item';
		$offload                           = $this->offload();
		$this->assertSame( 'not-an-item', EMR_Test_Offload_Media::get_by_source_id( 43 ), 'Sentinel: the lookup answers a string.' );

		$result = $offload->isFileMovable( true, 43 );

		$this->assertNull( $result, 'Pinned: null instead of true.' );
	}

	public function test_media_class_comes_from_the_main_object() {
		$as3cf   = new EMR_Test_As3cf();
		$offload = $this->offload( $as3cf );

		$offload->isFileMovable( true, 44 );

		$this->assertSame( array( 'media-library' ), $as3cf->asked );
	}

	/*
	 * getLocalPath().
	 */

	private function upload_base() {
		return (string) emr()->filesystem()->getWPUploadBase();
	}

	public function test_get_local_path_builds_the_upload_path_of_the_item() {
		EMR_Test_Offload_Media::$items[51] = new EMR_Test_Offload_Item( '2024/01/photo.jpg' );

		$path = $this->offload()->getLocalPath( 'https://cdn.example.test/2024/01/photo.jpg', null, 51 );

		$this->assertSame( $this->upload_base() . '2024/01/photo.jpg', $path );
		$this->assertSame( 0, strpos( $path, wp_upload_dir()['basedir'] ) );
		$this->assertSame( array(), EMR_Test_Offload_Media::$asked );
	}

	public function test_get_local_path_points_a_thumbnail_url_to_its_file() {
		EMR_Test_Offload_Media::$items[52] = new EMR_Test_Offload_Item( '2024/01/photo.jpg' );

		$path = $this->offload()->getLocalPath( 'https://cdn.example.test/2024/01/photo-150x150.jpg', null, 52 );

		$this->assertSame( $this->upload_base() . '2024/01/photo-150x150.jpg', $path );
	}

	public function test_get_local_path_falls_back_to_the_url_lookup() {
		$url = 'https://cdn.example.test/2024/01/other.jpg';
		EMR_Test_Offload_Media::$sources[ $url ] = array( 'id' => 54 );
		EMR_Test_Offload_Media::$items[54]       = new EMR_Test_Offload_Item( '2024/01/other.jpg' );

		$path = $this->offload()->getLocalPath( $url, null, 53 );

		$this->assertSame( $this->upload_base() . '2024/01/other.jpg', $path );
	}

	public function test_get_local_path_is_false_when_no_source_id_is_found() {
		$this->assertFalse( $this->offload()->getLocalPath( 'https://cdn.example.test/2024/01/none.jpg', null, 55 ) );
		$this->assertNotEmpty( EMR_Test_Offload_Media::$asked );
	}

	/**
	 * getLocalPath() falls back to the URL lookup when the item is not found
	 * by id; when the URL lookup returns an id but the item for that id is
	 * still false, it calls `$item->original_source_path()` on false and
	 * PHP throws an Error. It should return false when $item is not an
	 * object.
	 *
	 * How to recognise the fix: getLocalPath() returns false and the
	 * expected Error is not thrown.
	 */
	public function test_get_local_path_calls_a_method_on_false_pinned_for_deferred_fix() {
		$url = 'https://cdn.example.test/2024/01/ghost.jpg';
		EMR_Test_Offload_Media::$sources[ $url ] = array( 'id' => 57 );
		$offload                                 = $this->offload();
		$this->assertFalse( EMR_Test_Offload_Media::get_by_source_id( 57 ), 'Sentinel: no item for the id the URL gives.' );

		$this->expectException( Error::class );
		$this->expectExceptionMessageMatches( '/original_source_path\(\) on (bool|boolean|false)/' );

		$offload->getLocalPath( $url, null, 56 );
	}

	/*
	 * getSourceIDByURL().
	 */

	public function test_source_id_by_url_finds_the_url_as_given_and_caches_it() {
		$offload = $this->offload();
		$url     = 'https://cdn.example.test/u/photo.jpg';
		EMR_Test_Offload_Media::$sources[ $url ] = array( 'id' => '61' );

		$this->assertSame( 61, $this->call( $offload, 'getSourceIDByURL', array( $url ) ) );
		$this->assertSame( 61, $this->call( $offload, 'getSourceIDByURL', array( $url ) ) );

		$this->assertSame( array( $url ), EMR_Test_Offload_Media::$asked, 'The second call is answered from the cache.' );
		$this->assertSame( array( $url => 61 ), $this->prop( $offload, 'sources' ) );
	}

	public function test_source_id_by_url_adds_a_scheme_when_missing() {
		$offload = $this->offload();
		EMR_Test_Offload_Media::$sources['http://cdn.example.test/u/photo.jpg'] = array( 'id' => 62 );

		$this->assertSame( 62, $this->call( $offload, 'getSourceIDByURL', array( 'cdn.example.test/u/photo.jpg' ) ) );
		$this->assertSame( array( 'http://cdn.example.test/u/photo.jpg' ), EMR_Test_Offload_Media::$asked );
	}

	public function test_source_id_by_url_strips_the_thumbnail_suffix() {
		$offload = $this->offload();
		EMR_Test_Offload_Media::$sources['https://cdn.example.test/u/photo.jpg'] = array( 'id' => 63 );

		$result = $this->call( $offload, 'getSourceIDByURL', array( 'https://cdn.example.test/u/photo-150x150.jpg' ) );

		$this->assertSame( 63, $result );
		$this->assertSame( array( 'https://cdn.example.test/u/photo-150x150.jpg', 'https://cdn.example.test/u/photo.jpg' ), EMR_Test_Offload_Media::$asked );
	}

	public function test_source_id_by_url_is_false_when_nothing_matches() {
		$offload = $this->offload();

		$this->assertFalse( $this->call( $offload, 'getSourceIDByURL', array( 'https://cdn.example.test/u/none.jpg' ) ) );
		$this->assertNull( $this->prop( $offload, 'sources' ), 'Nothing is cached on a miss.' );
	}

	/**
	 * The double-extension step of getSourceIDByURL() builds `$checkurl`
	 * (the URL with all extensions but the last removed) but then asks
	 * Offload for `$url` again, so the step never finds anything new. It
	 * also cuts the URL at its first dot, which is in the host name
	 * (`https://cdn` + `.webp`), not in the file name. It should query
	 * `$checkurl`, cut from the file name only.
	 *
	 * How to recognise the fix: the lookup of `.../photo.webp` succeeds,
	 * the result is 64 and the asked-URL list changes.
	 */
	public function test_double_extension_step_queries_the_wrong_url_pinned_for_deferred_fix() {
		$offload = $this->offload();
		$url     = 'https://cdn.example.test/u/photo.jpg.webp';
		EMR_Test_Offload_Media::$sources['https://cdn.example.test/u/photo.webp'] = array( 'id' => 64 );
		EMR_Test_Offload_Media::$sources['https://cdn.webp']                     = array( 'id' => 65 );

		$result = $this->call( $offload, 'getSourceIDByURL', array( $url ) );

		$this->assertFalse( $result, 'Pinned: the intended URL is never asked.' );
		$this->assertSame( array( $url, $url, $url ), EMR_Test_Offload_Media::$asked, 'Pinned: the third lookup asks $url, not $checkurl.' );
	}

	/**
	 * Companion of the pin above: the value the step builds as $checkurl
	 * is only consulted in the cache, so priming the cache with the
	 * first-dot cut (`https://cdn.webp`) is the only way the step answers.
	 *
	 * How to recognise the fix: the cut moves to the file name, the cache
	 * key `https://cdn.webp` is not consulted and the result is false.
	 */
	public function test_double_extension_step_cuts_at_the_first_dot_of_the_host_pinned_for_deferred_fix() {
		$offload = $this->offload();
		$ref     = new ReflectionProperty( WPOffload::class, 'sources' );
		$ref->setAccessible( true );
		$ref->setValue( $offload, array( 'https://cdn.webp' => 66 ) );

		$result = $this->call( $offload, 'getSourceIDByURL', array( 'https://cdn.example.test/u/photo.jpg.webp' ) );

		$this->assertSame( 66, $result, 'Pinned: found through the host-cut cache key.' );
	}

	/*
	 * removeRemote() and updateOriginalPath(), not-offloaded paths.
	 */

	public function test_remove_remote_returns_false_when_not_offloaded() {
		$this->assertFalse( $this->offload()->removeRemote( 71, array(), array(), null, null ) );
	}

	public function test_update_original_path_does_nothing_when_not_offloaded() {
		$this->assertNull( $this->offload()->updateOriginalPath( 'https://t', 'https://s', 72 ) );
	}

	public function test_update_original_path_renames_the_item_paths_after_a_rename() {
		$id                  = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/test-image.jpg' );
		$this->attachments[] = $id;
		$item                = new EMR_Test_Offload_Item( 'emr/2024/01/old-name.jpg' );
		EMR_Test_Offload_Media::$items[ $id ] = $item;

		$this->offload()->updateOriginalPath( 'https://t', 'https://s', $id );

		$basename = wp_basename( wp_get_original_image_path( $id ) );
		$this->assertSame( 'emr/2024/01/' . $basename, $item->original_path );
		$this->assertSame( 'emr/2024/01/' . $basename, $item->original_source_path );
		$this->assertSame( 1, $item->saved );
	}

	public function test_update_original_path_leaves_a_matching_item_alone() {
		$id                  = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/test-image.jpg' );
		$this->attachments[] = $id;
		$path                = 'emr/2024/01/' . wp_basename( wp_get_original_image_path( $id ) );
		$item                = new EMR_Test_Offload_Item( $path );
		EMR_Test_Offload_Media::$items[ $id ] = $item;

		$this->offload()->updateOriginalPath( 'https://t', 'https://s', $id );

		$this->assertSame( $path, $item->original_path );
		$this->assertSame( 0, $item->saved );
	}
}
