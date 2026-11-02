<?php
/**
 * Tests for Replacer::replaceContent() and the private helpers of
 * EnableMediaReplace\Replacer\Replacer (build/shortpixel/replacer2/src/Replacer.php).
 *
 * replaceContent() is the recursive search/replace over every data shape
 * found in the database: plain strings, serialized data, JSON, arrays (with
 * URLs as keys), objects and WP_Error. Serialized data is unserialized with
 * `allowed_classes => false`, so no object is ever instantiated from stored
 * data: a serialized object at the top level comes back as the original
 * string, untouched.
 *
 * The private helpers (fileIsRestricted(), change_key(), getRelativeURLS(),
 * getFilesFromMetadata(), isJSON(), checkIncomplete(), setReplaceSettings())
 * are reached through reflection.
 *
 * This file declares the global class EMR_Test_Wakeup_Canary_, which only
 * records calls to its magic methods; it stays declared for the rest of the
 * process.
 *
 * Every test works on its own `new Replacer()`; the shared instance of
 * getInstance() is only used by the tests ported from the SPIO suite and is
 * reset in set_up() and restored in tear_down().
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Replacer;

if ( ! class_exists( 'EMR_Test_Wakeup_Canary_' ) ) {
	/**
	 * Canary for object injection: records every call to its magic methods.
	 *
	 * A serialized payload of this class must never become a live object
	 * inside the replacer, so neither method may ever be called.
	 */
	class EMR_Test_Wakeup_Canary_ {

		/**
		 * Recorded magic method calls.
		 *
		 * @var string[]
		 */
		public static $calls = array();

		/**
		 * Property the serialized payload sets.
		 *
		 * @var string
		 */
		public $url = '';

		/** Records an unserialize wake-up. */
		public function __wakeup() {
			self::$calls[] = '__wakeup';
		}

		/** Records a destruction. */
		public function __destruct() {
			self::$calls[] = '__destruct';
		}
	}
}

/**
 * Replacer::replaceContent() and the private helpers.
 */
class ReplacerTest extends WP_UnitTestCase {

	const OLD_URL = 'http://example.org/wp-content/uploads/old-image.jpg';
	const NEW_URL = 'http://example.org/wp-content/uploads/new-image.jpg';

	/**
	 * Shared Replacer instance before the test.
	 *
	 * @var Replacer|null
	 */
	private $saved_instance;

	/**
	 * Filters added by the test, as [hook, callback, priority].
	 *
	 * @var array
	 */
	private $added_filters = array();

	/**
	 * Resets the shared instance and the canary.
	 */
	public function set_up() {
		parent::set_up();
		$prop                 = $this->instanceProperty();
		$this->saved_instance = $prop->getValue();
		$prop->setValue( null, null );
		EMR_Test_Wakeup_Canary_::$calls = array();
	}

	/**
	 * Restores the shared instance and removes the test's filters.
	 */
	public function tear_down() {
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		$this->instanceProperty()->setValue( null, $this->saved_instance );
		EMR_Test_Wakeup_Canary_::$calls = array();
		parent::tear_down();
	}

	/**
	 * The private static $instance of Replacer, made accessible.
	 *
	 * @return ReflectionProperty
	 */
	private function instanceProperty() {
		$prop = new ReflectionProperty( Replacer::class, 'instance' );
		$prop->setAccessible( true );
		return $prop;
	}

	/**
	 * Adds a filter and records it for tear_down().
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 */
	private function addFilter( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->added_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Calls a private method of a replacer.
	 *
	 * @param Replacer $replacer Instance.
	 * @param string   $method   Method name.
	 * @param array    $args     Arguments.
	 * @return mixed
	 */
	private function callPrivate( $replacer, $method, array $args = array() ) {
		$ref = new ReflectionMethod( Replacer::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $replacer, $args );
	}

	/**
	 * Reads a private property of a replacer.
	 *
	 * @param Replacer $replacer Instance.
	 * @param string   $name     Property name.
	 * @return mixed
	 */
	private function readPrivate( $replacer, $name ) {
		$ref = new ReflectionProperty( Replacer::class, $name );
		$ref->setAccessible( true );
		return $ref->getValue( $replacer );
	}

	/**
	 * replaceContent() on the shared instance with OLD_URL -> NEW_URL.
	 *
	 * @param mixed $content      Content.
	 * @param bool  $strict_check Strict check flag.
	 * @return mixed
	 */
	private function replaceContent( $content, $strict_check = true ) {
		return Replacer::getInstance()->replaceContent(
			$content,
			array( self::OLD_URL ),
			array( self::NEW_URL ),
			false,
			$strict_check
		);
	}

	/**
	 * replaceContent() on a fresh instance with OLD_URL -> NEW_URL.
	 *
	 * @param mixed $content Content.
	 * @param bool  $in_deep In-deep flag.
	 * @return mixed
	 */
	private function replaceFresh( $content, $in_deep = false ) {
		$replacer = new Replacer();
		return $replacer->replaceContent( $content, array( self::OLD_URL ), array( self::NEW_URL ), $in_deep );
	}

	// ---------------------------------------------------------------------
	// Ported from the SPIO suite (namespace swapped).
	// ---------------------------------------------------------------------

	public function test_plain_string_url_is_replaced() {
		$result = $this->replaceContent( 'Image lives at ' . self::OLD_URL . ' here.' );
		$this->assertSame( 'Image lives at ' . self::NEW_URL . ' here.', $result );
	}

	public function test_serialized_array_urls_are_replaced() {
		$content = serialize(
			array(
				'main'  => self::OLD_URL,
				'other' => 'untouched',
				'deep'  => array( 'thumb' => self::OLD_URL ),
			)
		);

		$result = $this->replaceContent( $content, false );

		$this->assertIsString( $result );
		$unserialized = unserialize( $result );
		$this->assertSame( self::NEW_URL, $unserialized['main'], 'Serialized array values must still be replaced' );
		$this->assertSame( self::NEW_URL, $unserialized['deep']['thumb'], 'Nested serialized array values must still be replaced' );
		$this->assertSame( 'untouched', $unserialized['other'] );
	}

	/**
	 * Serialized objects are bailed on wholesale: allowed_classes=false turns
	 * them into __PHP_Incomplete_Class and the original serialized string
	 * comes back byte-identical, in the non-strict (metadata) path too.
	 */
	public function test_serialized_object_is_returned_unchanged_in_both_check_modes() {
		$payload      = new stdClass();
		$payload->url = self::OLD_URL;
		$serialized   = serialize( $payload );

		$this->assertSame( $serialized, $this->replaceContent( $serialized, true ), 'strict path: serialized objects are returned untouched' );
		$this->assertSame( $serialized, $this->replaceContent( $serialized, false ), 'metadata path: serialized objects are returned untouched too' );
	}

	/**
	 * The classic object-injection shape: a serialized object of a class
	 * with magic methods. It is never instantiated (neither __wakeup nor
	 * __destruct runs) and the stored data is not corrupted.
	 */
	public function test_serialized_object_with_magic_method_is_never_instantiated() {
		$this->assertTrue( class_exists( 'EMR_Test_Wakeup_Canary_' ), 'Sentinel: the canary class exists, so a real unserialize would instantiate it.' );
		$class      = 'EMR_Test_Wakeup_Canary_';
		$serialized = 'O:' . strlen( $class ) . ':"' . $class . '":1:{s:3:"url";s:' . strlen( self::OLD_URL ) . ':"' . self::OLD_URL . '";}';

		$result = $this->replaceContent( $serialized, false );

		$this->assertSame( $serialized, $result, 'A serialized object carrying magic methods is returned unchanged' );
		gc_collect_cycles();
		$this->assertSame( array(), EMR_Test_Wakeup_Canary_::$calls, 'Neither __wakeup nor __destruct of the canary ran' );
	}

	public function test_array_wrapping_a_serialized_object_keeps_the_object_blob_intact() {
		$payload      = new stdClass();
		$payload->url = self::OLD_URL;

		$content = serialize(
			array(
				'plain'  => self::OLD_URL,
				'object' => serialize( $payload ),
			)
		);

		$result       = $this->replaceContent( $content, false );
		$unserialized = unserialize( $result );

		$this->assertSame( self::NEW_URL, $unserialized['plain'], 'Sibling scalar values are still replaced' );
		$this->assertSame( serialize( $payload ), $unserialized['object'], 'The nested serialized-object blob survives untouched (its URL is not replaced)' );
	}

	/**
	 * Bricks-shaped page data: a list of elements, where a nested slider
	 * keeps its options as a JSON string next to an image element that holds
	 * the URL being replaced.
	 *
	 * @param string $options_json Slider options JSON.
	 * @return array
	 */
	private function bricksPageContent( $options_json ) {
		return array(
			array(
				'id'       => 'slider1',
				'name'     => 'slider-nested',
				'settings' => array(
					'optionsType' => 'custom',
					'options'     => $options_json,
				),
			),
			array(
				'id'       => 'image1',
				'name'     => 'image',
				'settings' => array( 'image' => array( 'url' => self::OLD_URL ) ),
			),
		);
	}

	/**
	 * A JSON string nested inside serialized data stays a JSON string.
	 * replaceContent() decodes every JSON-looking string it meets and
	 * encodes it back at the same level, so a builder that reads the nested
	 * value as a string (Bricks passes it to stripslashes()) keeps working.
	 * A JSON string without any search value comes back unchanged.
	 */
	public function test_json_string_nested_in_serialized_data_stays_the_same_json_string() {
		$options = '{"type":"loop","perPage":3,"gap":"1em"}';
		$content = serialize( $this->bricksPageContent( $options ) );

		$result = unserialize( $this->replaceContent( $content, false ) );

		$this->assertSame( self::NEW_URL, $result[1]['settings']['image']['url'], 'Sentinel: the image URL was replaced.' );
		$this->assertSame( $options, $result[0]['settings']['options'], 'The untouched nested JSON string is unchanged.' );
	}

	/**
	 * A nested JSON string that carries the URL is a JSON string with the
	 * new URL after the replace, encoded with the default json_flags
	 * (JSON_UNESCAPED_SLASHES).
	 */
	public function test_json_string_with_the_url_nested_in_serialized_data_stays_a_json_string() {
		$options = wp_json_encode( array( 'cover' => self::OLD_URL ), JSON_UNESCAPED_SLASHES );
		$content = serialize( $this->bricksPageContent( $options ) );

		$result = unserialize( $this->replaceContent( $content, false ) );

		$this->assertSame( '{"cover":"' . self::NEW_URL . '"}', $result[0]['settings']['options'], 'A JSON string with the URL replaced.' );
	}

	// ---------------------------------------------------------------------
	// replaceContent(): data shapes.
	// ---------------------------------------------------------------------

	public function test_every_search_value_is_replaced_by_its_index_matched_replacement() {
		$replacer = new Replacer();
		$result   = $replacer->replaceContent( 'a.jpg b.jpg c.jpg', array( 'a.jpg', 'b.jpg' ), array( 'x.jpg', 'y.jpg' ) );
		$this->assertSame( 'x.jpg y.jpg c.jpg', $result );
	}

	public function test_string_without_a_search_value_is_returned_identical() {
		$this->assertSame( 'nothing to see', $this->replaceFresh( 'nothing to see' ) );
	}

	public function test_array_key_holding_the_url_is_renamed_and_order_kept() {
		$content = array(
			'first'       => 'one',
			self::OLD_URL => 'value at url key',
			'last'        => self::OLD_URL,
		);

		$result = $this->replaceFresh( $content );

		$this->assertIsString( $result, 'A top-level array is serialized' );
		$unserialized = unserialize( $result );
		$this->assertSame( array( 'first', self::NEW_URL, 'last' ), array_keys( $unserialized ), 'The URL key is renamed in place' );
		$this->assertSame( 'value at url key', $unserialized[ self::NEW_URL ] );
		$this->assertSame( self::NEW_URL, $unserialized['last'] );
	}

	public function test_nested_arrays_are_replaced_at_every_depth() {
		$content = array( 'a' => array( 'b' => array( 'c' => array( 'src' => self::OLD_URL ) ) ) );

		$result = unserialize( $this->replaceFresh( $content ) );

		$this->assertSame( self::NEW_URL, $result['a']['b']['c']['src'] );
	}

	public function test_nested_array_is_returned_as_array_when_in_deep() {
		$result = $this->replaceFresh( array( 'src' => self::OLD_URL ), true );

		$this->assertIsArray( $result, 'Below the top level nothing is serialized' );
		$this->assertSame( array( 'src' => self::NEW_URL ), $result );
	}

	/**
	 * Objects are walked by public property. The passed object is modified in
	 * place (objects are handles), and the top-level result is serialized.
	 */
	public function test_object_properties_are_replaced_and_the_object_is_modified_in_place() {
		$object         = new stdClass();
		$object->src    = self::OLD_URL;
		$object->nested = array( 'thumb' => self::OLD_URL );

		$result = $this->replaceFresh( $object );

		$this->assertIsString( $result );
		$unserialized = unserialize( $result );
		$this->assertInstanceOf( stdClass::class, $unserialized );
		$this->assertSame( self::NEW_URL, $unserialized->src );
		$this->assertSame( self::NEW_URL, $unserialized->nested['thumb'] );
		$this->assertSame( self::NEW_URL, $object->src, 'The caller\'s object itself was changed' );
	}

	/**
	 * A WP_Error is not walked: its messages keep the old URL. At the top
	 * level it is still an object, so it is serialized like any other
	 * object; nested, the same instance comes back.
	 */
	public function test_wp_error_is_not_walked() {
		$error = new WP_Error( 'code', 'failed at ' . self::OLD_URL );

		$top    = $this->replaceFresh( $error );
		$nested = $this->replaceFresh( $error, true );

		$this->assertIsString( $top, 'Top level: serialized like every other object' );
		$restored = unserialize( $top );
		$this->assertInstanceOf( WP_Error::class, $restored );
		$this->assertSame( 'failed at ' . self::OLD_URL, $restored->get_error_message() );
		$this->assertSame( $error, $nested, 'Nested: the same instance, untouched' );
		$this->assertSame( 'failed at ' . self::OLD_URL, $nested->get_error_message() );
	}

	public function test_serialized_incomplete_class_is_returned_as_the_original_string() {
		$serialized = 'O:21:"EMR_Class_Not_Present":1:{s:3:"url";s:' . strlen( self::OLD_URL ) . ':"' . self::OLD_URL . '";}';
		$this->assertFalse( class_exists( 'EMR_Class_Not_Present', false ), 'Sentinel: the class does not exist.' );

		$this->assertSame( $serialized, $this->replaceFresh( $serialized ) );
	}

	public function test_json_string_is_re_encoded_without_escaped_slashes_by_default() {
		$json = wp_json_encode( array( 'src' => self::OLD_URL ) );
		$this->assertStringContainsString( '\/', $json, 'Sentinel: the input JSON escapes its slashes.' );

		$result = $this->replaceFresh( $json );

		$this->assertIsString( $result );
		$this->assertSame( '{"src":"' . self::NEW_URL . '"}', $result );
	}

	public function test_json_flags_setting_is_used_when_re_encoding() {
		$replacer = new Replacer();
		$this->callPrivate( $replacer, 'setReplaceSettings', array( array( 'json_flags' => 0 ) ) );

		$result = $replacer->replaceContent( '{"src":"' . self::OLD_URL . '"}', array( self::OLD_URL ), array( self::NEW_URL ) );

		$this->assertSame( '{"src":"' . str_replace( '/', '\/', self::NEW_URL ) . '"}', $result );
	}

	/** Below the top level a JSON string is encoded back too, not returned decoded. */
	public function test_json_string_in_deep_is_returned_as_a_json_string() {
		$result = $this->replaceFresh( '{"src":"' . self::OLD_URL . '"}', true );

		$this->assertSame( '{"src":"' . self::NEW_URL . '"}', $result );
	}

	/**
	 * A JSON string inside a JSON document is encoded back at its own level,
	 * so the document keeps its shape: `inner` is still a string, holding
	 * the new URL.
	 */
	public function test_json_string_inside_json_keeps_its_shape() {
		$inner = '{"u":"' . self::OLD_URL . '"}';
		$json  = wp_json_encode( array( 'inner' => $inner ), JSON_UNESCAPED_SLASHES );
		$this->assertIsString( json_decode( $json )->inner, 'Sentinel: inner is a string in the input.' );

		$result = $this->replaceFresh( $json );

		$this->assertSame( wp_json_encode( array( 'inner' => '{"u":"' . self::NEW_URL . '"}' ), JSON_UNESCAPED_SLASHES ), $result );
		$this->assertSame( '{"u":"' . self::NEW_URL . '"}', json_decode( $result )->inner, 'inner is still a JSON string.' );
	}

	public function test_replace_no_serialize_returns_arrays_and_objects_as_they_are() {
		$replacer = new Replacer();
		$this->callPrivate( $replacer, 'setReplaceSettings', array( array( 'replace_no_serialize' => true ) ) );
		$object      = new stdClass();
		$object->src = self::OLD_URL;

		$array_result  = $replacer->replaceContent( array( 'src' => self::OLD_URL ), array( self::OLD_URL ), array( self::NEW_URL ) );
		$object_result = $replacer->replaceContent( $object, array( self::OLD_URL ), array( self::NEW_URL ) );

		$this->assertSame( array( 'src' => self::NEW_URL ), $array_result );
		$this->assertInstanceOf( stdClass::class, $object_result );
		$this->assertSame( self::NEW_URL, $object_result->src );
	}

	public function test_numeric_string_is_not_treated_as_json() {
		$replacer = new Replacer();
		$result   = $replacer->replaceContent( '12345', array( '234' ), array( 'x' ) );

		$this->assertSame( '1x5', $result, 'Replaced as a plain string, not decoded to an int' );
	}

	public function test_non_string_scalars_and_null_are_returned_untouched() {
		$this->assertSame( 42, $this->replaceFresh( 42 ) );
		$this->assertSame( 1.5, $this->replaceFresh( 1.5 ) );
		$this->assertNull( $this->replaceFresh( null ) );
		$this->assertFalse( $this->replaceFresh( false ) );
	}

	public function test_content_filter_receives_every_string_leaf_and_its_result_is_used() {
		$seen = array();
		$this->addFilter(
			'emr/replacer/content',
			function ( $content, $search, $replace ) use ( &$seen ) {
				$seen[] = array( $content, $search, $replace );
				return $content . '!';
			},
			10,
			3
		);

		$result = $this->replaceFresh( array( 'key' => self::OLD_URL ), true );

		$this->assertSame( array( 'key!' => self::NEW_URL . '!' ), $result, 'Values and keys both pass the filter before str_replace()' );
		$this->assertCount( 2, $seen );
		$this->assertSame( array( self::OLD_URL, array( self::OLD_URL ), array( self::NEW_URL ) ), $seen[0] );
		$this->assertSame( 'key', $seen[1][0] );
	}

	public function test_text_that_looks_partly_serialized_is_replaced_as_a_plain_string() {
		$text = 'a:2:{ is not serialized ' . self::OLD_URL;
		$this->assertFalse( is_serialized( $text ), 'Sentinel: WordPress does not see it as serialized.' );

		$this->assertSame( 'a:2:{ is not serialized ' . self::NEW_URL, $this->replaceFresh( $text ) );
	}

	public function test_replace_content_applies_default_settings_when_none_were_set() {
		$replacer = new Replacer();
		$this->assertNull( $this->readPrivate( $replacer, 'replace_settings' ), 'Sentinel: no settings before the first call.' );

		$replacer->replaceContent( 'x', array( 'a' ), array( 'b' ) );

		$settings = $this->readPrivate( $replacer, 'replace_settings' );
		$this->assertSame( 'unset', $settings['component'] );
		$this->assertSame( JSON_UNESCAPED_SLASHES, $settings['json_flags'] );
		$this->assertTrue( $settings['replacer_do_save'] );
		$this->assertFalse( $settings['replace_no_serialize'] );
	}

	// ---------------------------------------------------------------------
	// Private helpers.
	// ---------------------------------------------------------------------

	public function test_set_replace_settings_merges_over_the_defaults_and_resets_without_arguments() {
		$replacer = new Replacer();

		$this->callPrivate( $replacer, 'setReplaceSettings', array( array( 'component' => 'mine', 'replacer_do_save' => false ) ) );
		$partial = $this->readPrivate( $replacer, 'replace_settings' );
		$this->callPrivate( $replacer, 'setReplaceSettings' );
		$reset = $this->readPrivate( $replacer, 'replace_settings' );

		$this->assertSame( 'mine', $partial['component'] );
		$this->assertFalse( $partial['replacer_do_save'] );
		$this->assertSame( JSON_UNESCAPED_SLASHES, $partial['json_flags'], 'Missing keys take their default' );
		$this->assertSame(
			array(
				'component'            => 'unset',
				'json_flags'           => JSON_UNESCAPED_SLASHES,
				'replacer_do_save'     => true,
				'replace_no_serialize' => false,
			),
			$reset
		);
	}

	public function test_file_is_restricted_is_false_without_open_basedir_and_skips_its_filter() {
		$this->assertSame( '', (string) ini_get( 'open_basedir' ), 'Precondition: open_basedir is not set in the test container.' );
		$calls = 0;
		$this->addFilter(
			'emr/file/basedir_check',
			function ( $restricted ) use ( &$calls ) {
				$calls++;
				return true;
			}
		);

		$result = $this->callPrivate( new Replacer(), 'fileIsRestricted', array( '/any/path' ) );

		$this->assertFalse( $result );
		$this->assertSame( 0, $calls, 'The basedir_check filter only runs when open_basedir is set' );
	}

	/**
	 * With open_basedir set, a path containing an entry is allowed (false),
	 * one containing none is restricted (true), and the filter can override.
	 * open_basedir can only be tightened at runtime, never restored, so this
	 * runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_file_is_restricted_checks_open_basedir_entries_and_its_filter() {
		ini_set( 'open_basedir', '/' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$replacer = new Replacer();

		$allowed    = $this->callPrivate( $replacer, 'fileIsRestricted', array( '/wp-content/uploads/a' ) );
		$restricted = $this->callPrivate( $replacer, 'fileIsRestricted', array( 'relative-path-without-slash' ) );
		$this->addFilter( 'emr/file/basedir_check', '__return_false' );
		$overridden = $this->callPrivate( $replacer, 'fileIsRestricted', array( 'relative-path-without-slash' ) );

		$this->assertFalse( $allowed );
		$this->assertTrue( $restricted );
		$this->assertFalse( $overridden );
	}

	public function test_change_key_renames_matching_keys_at_every_depth_and_keeps_order() {
		$input = array(
			'a'   => 1,
			'old' => array( 'old' => 2, 'b' => 3 ),
			'c'   => 4,
		);

		$result = $this->callPrivate( new Replacer(), 'change_key', array( $input, array( 'old' => 'new' ) ) );

		$this->assertSame(
			array(
				'a'   => 1,
				'new' => array( 'new' => 2, 'b' => 3 ),
				'c'   => 4,
			),
			$result
		);
	}

	public function test_change_key_returns_non_array_input_unchanged() {
		$replacer = new Replacer();
		$this->assertSame( 'string', $this->callPrivate( $replacer, 'change_key', array( 'string', array( 'a' => 'b' ) ) ) );
		$this->assertSame( array( 'a' => 1 ), $this->callPrivate( $replacer, 'change_key', array( array( 'a' => 1 ), 'not-a-map' ) ) );
	}

	public function test_get_files_from_metadata_lists_file_and_sizes_with_a_file_key() {
		$meta = array(
			'file'  => '2026/10/photo.jpg',
			'width' => 800,
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150 ),
				'broken'    => array( 'width' => 10 ),
			),
		);

		$result = $this->callPrivate( new Replacer(), 'getFilesFromMetadata', array( $meta ) );

		$this->assertSame( array( 'file' => '2026/10/photo.jpg', 'thumbnail' => 'photo-150x150.jpg' ), $result );
		$this->assertSame( array(), $this->callPrivate( new Replacer(), 'getFilesFromMetadata', array( array() ) ) );
	}

	public function test_get_relative_urls_builds_paths_in_the_main_file_directory() {
		$replacer = new Replacer();
		$replacer->setSource( 'http://example.org/wp-content/uploads/2020/01/photo.jpg' );
		$replacer->setTarget( 'https://cdn.example.com/wp-content/uploads/2026/10/other.png' );
		$replacer->setSourceMeta(
			array(
				'file'  => '2020/01/photo.jpg',
				'sizes' => array( 'thumbnail' => array( 'file' => 'photo-150x150.jpg' ) ),
			)
		);
		$replacer->setTargetMeta( array( 'file' => '2026/10/other.png' ) );

		$result = $this->callPrivate( $replacer, 'getRelativeURLS' );

		$this->assertSame(
			array(
				'source' => array(
					'base'      => '/wp-content/uploads/2020/01/photo.jpg',
					'file'      => '/wp-content/uploads/2020/01/photo.jpg',
					'thumbnail' => '/wp-content/uploads/2020/01/photo-150x150.jpg',
				),
				'target' => array(
					'base' => '/wp-content/uploads/2026/10/other.png',
					'file' => '/wp-content/uploads/2026/10/other.png',
				),
			),
			$result,
			'Scheme and host are dropped; files are placed in the directory of the main file'
		);
	}

	public function test_is_json_boundaries() {
		$replacer = new Replacer();
		$cases    = array(
			array( '{"a":1}', true ),
			array( '[1,2]', true ),
			array( '"abc"', true ),
			array( '123', false ),
			array( '0', false ),
			array( '[]', false ),
			array( 'true', false ),
			array( 'not json', false ),
			array( array( 'a' ), false ),
			array( null, false ),
		);
		foreach ( $cases as $case ) {
			$this->assertSame( $case[1], $this->callPrivate( $replacer, 'isJSON', array( $case[0] ) ), 'isJSON(' . wp_json_encode( $case[0] ) . ')' );
		}
		$this->assertFalse( $this->callPrivate( $replacer, 'isJSON', array( new stdClass() ) ) );
	}

	public function test_check_incomplete_is_true_only_for_incomplete_objects() {
		$replacer   = new Replacer();
		$incomplete = unserialize( serialize( new stdClass() ), array( 'allowed_classes' => false ) );

		$this->assertTrue( $this->callPrivate( $replacer, 'checkIncomplete', array( $incomplete ) ) );
		$this->assertFalse( $this->callPrivate( $replacer, 'checkIncomplete', array( new stdClass() ) ) );
		$this->assertFalse( $this->callPrivate( $replacer, 'checkIncomplete', array( 'O:8:"stdClass":0:{}' ) ) );
	}
}
