<?php
/**
 * Tests for Replacer2::replaceContent() and the private helpers of
 * EnableMediaReplace\Replacer\Replacer2 (build/shortpixel/replacer2/src/Replacer2.php).
 *
 * replaceContent() is the recursive search/replace over every data shape
 * found in the database: plain strings, serialized data, JSON, arrays (with
 * URLs as keys), objects and WP_Error. Serialized data is unserialized with
 * `allowed_classes => ['stdClass']`: stdClass objects (no magic methods) are
 * instantiated and walked, an object of any other class is never
 * instantiated, and when it is at the top level the original string comes
 * back untouched.
 *
 * The private helpers of Replacer2 (change_key(), isJSON(),
 * checkIncomplete(), setReplaceSettings()) and the protected
 * Classes\Setup::fileIsRestricted() are reached through reflection. The
 * relative URL lists built from the metadata are covered by
 * ReplacerDataTest, the nearest-size fill-in by ReplacerReplaceTest.
 *
 * This file declares the global class EMR_Test_Wakeup_Canary_, which only
 * records calls to its magic methods; it stays declared for the rest of the
 * process.
 *
 * Every test works on its own `new Replacer2()`.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Replacer2;
use EnableMediaReplace\Replacer\Classes\Setup;

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
 * Replacer2::replaceContent() and the private helpers.
 */
class ReplacerTest extends WP_UnitTestCase {

	const OLD_URL   = 'http://example.org/wp-content/uploads/old-image.jpg';
	const NEW_URL   = 'http://example.org/wp-content/uploads/new-image.jpg';
	const COMPONENT = 'emr';

	/**
	 * Filters added by the test, as [hook, callback, priority].
	 *
	 * @var array
	 */
	private $added_filters = array();

	/**
	 * Resets the canary.
	 */
	public function set_up() {
		parent::set_up();
		EMR_Test_Wakeup_Canary_::$calls = array();
	}

	/**
	 * Removes the test's filters and resets the canary.
	 */
	public function tear_down() {
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		EMR_Test_Wakeup_Canary_::$calls = array();
		parent::tear_down();
	}

	/**
	 * A new replacer for the test component.
	 *
	 * @return Replacer2
	 */
	private function newReplacer() {
		return new Replacer2( self::COMPONENT );
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
	 * Calls a private or protected method.
	 *
	 * @param object $object Instance (a Replacer2 or a Setup).
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function callPrivate( $object, $method, array $args = array() ) {
		$ref = new ReflectionMethod( get_class( $object ), $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $object, $args );
	}

	/**
	 * Reads a private property of a replacer.
	 *
	 * @param Replacer2 $replacer Instance.
	 * @param string    $name     Property name.
	 * @return mixed
	 */
	private function readPrivate( $replacer, $name ) {
		$ref = new ReflectionProperty( Replacer2::class, $name );
		$ref->setAccessible( true );
		return $ref->getValue( $replacer );
	}

	/**
	 * replaceContent() on a new replacer with OLD_URL -> NEW_URL, in the
	 * strict (post content) or the non-strict (metadata) check mode.
	 *
	 * @param mixed $content      Content.
	 * @param bool  $strict_check Strict check flag.
	 * @return mixed
	 */
	private function replaceContent( $content, $strict_check = true ) {
		return $this->newReplacer()->replaceContent(
			$content,
			array( self::OLD_URL ),
			array( self::NEW_URL ),
			false,
			$strict_check
		);
	}

	/**
	 * replaceContent() on a new replacer with OLD_URL -> NEW_URL.
	 *
	 * @param mixed $content Content.
	 * @param bool  $in_deep In-deep flag.
	 * @return mixed
	 */
	private function replaceFresh( $content, $in_deep = false ) {
		return $this->newReplacer()->replaceContent( $content, array( self::OLD_URL ), array( self::NEW_URL ), $in_deep );
	}

	// ---------------------------------------------------------------------
	// Serialized data and nested JSON.
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
	 * stdClass is the one allowed class: a serialized stdClass is
	 * unserialized, its properties are replaced and it is serialized back as
	 * a stdClass, in the strict and the non-strict (metadata) path alike.
	 */
	public function test_serialized_stdclass_is_replaced_and_serialized_back_in_both_check_modes() {
		$payload      = new stdClass();
		$payload->url = self::OLD_URL;
		$payload->tag = 'untouched';
		$serialized   = serialize( $payload );

		foreach ( array( 'strict' => true, 'metadata' => false ) as $label => $strict ) {
			$result = $this->replaceContent( $serialized, $strict );

			$this->assertIsString( $result, $label . ': serialized back to a string' );
			$this->assertStringStartsWith( 'O:8:"stdClass":2:', $result, $label . ': still a serialized stdClass' );
			$unserialized = unserialize( $result );
			$this->assertSame( self::NEW_URL, $unserialized->url, $label . ': the URL is replaced' );
			$this->assertSame( 'untouched', $unserialized->tag, $label . ': other properties are kept' );
		}
	}

	/**
	 * A stdClass holding an object of another class: the stdClass is walked,
	 * the foreign object stays __PHP_Incomplete_Class (never instantiated)
	 * and serialize() writes it back byte-identical under its own class
	 * name, URL included.
	 */
	public function test_stdclass_wrapping_an_object_of_another_class_keeps_that_object_intact() {
		$this->assertTrue( class_exists( 'EMR_Test_Wakeup_Canary_' ), 'Sentinel: the canary class exists, so a real unserialize would instantiate it.' );
		$class   = 'EMR_Test_Wakeup_Canary_';
		$canary  = 'O:' . strlen( $class ) . ':"' . $class . '":1:{s:3:"url";s:' . strlen( self::OLD_URL ) . ':"' . self::OLD_URL . '";}';
		$wrapper = 'O:8:"stdClass":2:{s:3:"url";s:' . strlen( self::OLD_URL ) . ':"' . self::OLD_URL . '";s:5:"inner";' . $canary . '}';

		$result = $this->replaceContent( $wrapper, false );

		$expected = 'O:8:"stdClass":2:{s:3:"url";s:' . strlen( self::NEW_URL ) . ':"' . self::NEW_URL . '";s:5:"inner";' . $canary . '}';
		$this->assertSame( $expected, $result, 'The stdClass URL is replaced, the canary payload is kept byte-identical' );
		gc_collect_cycles();
		$this->assertSame( array(), EMR_Test_Wakeup_Canary_::$calls, 'Neither __wakeup nor __destruct of the canary ran' );
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

	/**
	 * A serialized stdClass STRING nested in serialized data (the value was
	 * serialized twice) is unserialized at the inner level, walked, and
	 * serialized back there, so the array keeps a string at that key.
	 */
	public function test_serialized_stdclass_string_nested_in_serialized_data_stays_a_string() {
		$payload      = new stdClass();
		$payload->url = self::OLD_URL;

		$content = serialize(
			array(
				'plain'  => self::OLD_URL,
				'object' => serialize( $payload ),
			)
		);
		$this->assertIsString( unserialize( $content )['object'], 'Sentinel: the nested value is a string in the input.' );

		$result       = $this->replaceContent( $content, false );
		$unserialized = unserialize( $result );

		$this->assertSame( self::NEW_URL, $unserialized['plain'], 'Sibling scalar values are replaced' );
		$this->assertIsString( $unserialized['object'], 'The nested value is still a string' );
		$inner = unserialize( $unserialized['object'] );
		$this->assertInstanceOf( stdClass::class, $inner, 'The nested string still unserializes' );
		$this->assertSame( self::NEW_URL, $inner->url, 'With its URL replaced' );
	}

	/**
	 * A serialized object STRING stored with a second serialization layer
	 * (how WordPress stores a meta value or option that is a string looking
	 * serialized) keeps that outer layer. The inner object is of a class
	 * that is not allowed, so it comes back byte for byte: the URL in it is
	 * not replaced and the value is never turned into a raw serialized
	 * object that a later read would instantiate.
	 */
	public function test_double_serialized_object_of_a_disallowed_class_keeps_both_layers() {
		$class  = 'EMR_Test_Wakeup_Canary_';
		$inner  = 'O:' . strlen( $class ) . ':"' . $class . '":1:{s:3:"url";s:' . strlen( self::OLD_URL ) . ':"' . self::OLD_URL . '";}';
		$stored = serialize( $inner );
		$this->assertStringStartsWith( 's:', $stored, 'Sentinel: stored with the outer layer.' );

		$result = $this->replaceContent( $stored, false );

		$this->assertSame( $stored, $result, 'Both layers are returned unchanged' );
		$this->assertSame( $inner, maybe_unserialize( $result ), 'A WordPress read returns the inner string, not an object' );
		gc_collect_cycles();
		$this->assertSame( array(), EMR_Test_Wakeup_Canary_::$calls, 'Neither __wakeup nor __destruct of the canary ran' );
	}

	/**
	 * A serialized stdClass STRING stored with a second serialization layer
	 * is processed at both layers: the URL is replaced inside the inner
	 * value and both string lengths are written for the new URL, so the
	 * value still unserializes twice when the replacement is longer.
	 */
	public function test_double_serialized_stdclass_is_replaced_with_valid_lengths_at_both_layers() {
		$long_url = 'http://example.org/wp-content/uploads/a-much-longer-new-image-name.jpg';
		$this->assertNotSame( strlen( self::OLD_URL ), strlen( $long_url ), 'Sentinel: the URLs differ in length.' );
		$payload       = new stdClass();
		$payload->url  = self::OLD_URL;
		$expected      = new stdClass();
		$expected->url = $long_url;

		$result = $this->newReplacer()->replaceContent( serialize( serialize( $payload ) ), array( self::OLD_URL ), array( $long_url ), false, false );

		$this->assertSame( serialize( serialize( $expected ) ), $result );
		$this->assertIsString( maybe_unserialize( $result ), 'A WordPress read returns the inner string' );
		$this->assertEquals( $expected, unserialize( maybe_unserialize( $result ) ), 'The inner string unserializes to the replaced object' );
	}

	/**
	 * A value that looks serialized but does not unserialize (here a
	 * string length that does not match) is returned unchanged instead of
	 * being written back as a serialized false.
	 */
	public function test_value_that_looks_serialized_but_does_not_unserialize_is_returned_unchanged() {
		$broken = 's:3:"' . self::OLD_URL . '";';
		$this->assertTrue( is_serialized( $broken ), 'Sentinel: WordPress takes it for serialized.' );

		$errors = array();
		set_error_handler(
			function ( $errno, $errstr ) use ( &$errors ) {
				if ( E_WARNING === $errno || E_NOTICE === $errno ) {
					$errors[] = $errstr;
					return true;
				}
				return false;
			}
		);
		try {
			$result = $this->replaceContent( $broken, false );
		} finally {
			restore_error_handler();
		}

		$this->assertNotEmpty( $errors, 'Sentinel: unserialize() reported the broken value.' );
		$this->assertSame( $broken, $result, 'The broken value is returned byte for byte' );
	}

	/**
	 * A serialized false is a valid value and comes back as itself.
	 */
	public function test_serialized_false_is_returned_as_serialized_false() {
		$this->assertSame( 'b:0;', $this->replaceContent( 'b:0;', false ) );
	}

	/**
	 * Every serialized scalar and a serialized null come back in their
	 * serialized form, not as the bare PHP value; a serialized string has
	 * its URL replaced and its length rewritten.
	 */
	public function test_serialized_scalars_and_null_come_back_serialized() {
		$this->assertSame( 'N;', $this->replaceContent( 'N;', false ) );
		$this->assertSame( 'b:1;', $this->replaceContent( 'b:1;', false ) );
		$this->assertSame( 'i:5;', $this->replaceContent( 'i:5;', false ) );
		$this->assertSame( serialize( 0.5 ), $this->replaceContent( serialize( 0.5 ), false ) );

		$long_url = 'http://example.org/wp-content/uploads/a-much-longer-new-image-name.jpg';
		$result   = $this->newReplacer()->replaceContent( serialize( 'see ' . self::OLD_URL ), array( self::OLD_URL ), array( $long_url ) );
		$this->assertSame( serialize( 'see ' . $long_url ), $result );
	}

	/**
	 * A serialized ARRAY string nested in serialized data (serialized
	 * twice) stays a string at its key, with the URL replaced and a length
	 * that still unserializes.
	 */
	public function test_serialized_array_string_nested_in_serialized_data_stays_a_string() {
		$content = serialize(
			array(
				'plain'  => self::OLD_URL,
				'nested' => serialize( array( 'src' => self::OLD_URL, 'n' => 3 ) ),
			)
		);

		$unserialized = unserialize( $this->replaceContent( $content, false ) );

		$this->assertSame( self::NEW_URL, $unserialized['plain'], 'Sentinel: the sibling value is replaced.' );
		$this->assertIsString( $unserialized['nested'] );
		$this->assertSame( array( 'src' => self::NEW_URL, 'n' => 3 ), unserialize( $unserialized['nested'] ) );
	}

	/**
	 * A serialized JSON string (`s:N:"{...}";`) is unserialized, decoded,
	 * walked and encoded back as JSON, but the JSON branch then skips the
	 * serialize() of the values this call unserialized, so the value loses
	 * its serialization layer (Replacer2::replaceContent(), the `$isJson`
	 * branch before the `$was_unserialized` one). It happens at the top
	 * level and nested, and also for a value in which nothing is replaced,
	 * because the "unchanged" comparison decodes the serialized original,
	 * which gives null. A consumer that unserializes the nested value
	 * itself then gets false.
	 *
	 * How to recognise the fix: both results below are serialized strings
	 * again (`s:` prefix) that unserialize to the JSON text.
	 */
	public function test_serialized_json_string_loses_its_serialization_layer_pinned_for_deferred_fix() {
		$json_with_url = '{"cover":"' . self::OLD_URL . '"}';
		$untouched     = '{"type":"loop","perPage":3}';
		$this->assertTrue( is_serialized( serialize( $json_with_url ) ), 'Sentinel: the value is a serialized string.' );

		$top = $this->replaceContent( serialize( $json_with_url ), false );

		$this->assertSame( '{"cover":"' . self::NEW_URL . '"}', $top, 'Pinned: the bare JSON text comes back, without the serialization layer' );

		$content = serialize(
			array(
				'image'   => self::OLD_URL,
				'options' => serialize( $untouched ),
			)
		);

		$nested = unserialize( $this->replaceContent( $content, false ) );

		$this->assertSame( self::NEW_URL, $nested['image'], 'Sentinel: the sibling URL was replaced.' );
		$this->assertSame( $untouched, $nested['options'], 'Pinned: the untouched nested value lost its layer too' );
		$this->assertFalse( is_serialized( $nested['options'] ) );
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
	 * replaceContent() json_decode()s every JSON-looking string it meets and
	 * encodes it back at the same level, so a builder that reads the nested
	 * value as a string (Bricks passes it to stripslashes()) keeps working.
	 */
	public function test_json_string_nested_in_serialized_data_stays_a_json_string() {
		$options = '{"type":"loop","perPage":3,"gap":"1em"}';
		$content = serialize( $this->bricksPageContent( $options ) );

		$result = unserialize( $this->replaceContent( $content, false ) );

		$this->assertSame( self::NEW_URL, $result[1]['settings']['image']['url'], 'Sentinel: the image URL was replaced.' );
		$this->assertIsString( $result[0]['settings']['options'], 'The untouched nested JSON string is still a string.' );
		$this->assertSame( json_decode( $options, true ), json_decode( $result[0]['settings']['options'], true ), 'Its content is unchanged.' );
	}

	/**
	 * Same for a nested JSON string that does carry the URL: the URL is
	 * replaced inside it and the value is still a JSON string.
	 */
	public function test_json_string_with_the_url_nested_in_serialized_data_is_replaced_and_stays_a_string() {
		$options = wp_json_encode( array( 'cover' => self::OLD_URL ), JSON_UNESCAPED_SLASHES );
		$content = serialize( $this->bricksPageContent( $options ) );

		$result = unserialize( $this->replaceContent( $content, false ) );

		$this->assertSame( self::NEW_URL, $result[1]['settings']['image']['url'], 'Sentinel: the plain image URL was replaced.' );
		$this->assertIsString( $result[0]['settings']['options'], 'The nested JSON string is still a string.' );
		$this->assertSame( self::NEW_URL, json_decode( $result[0]['settings']['options'] )->cover, 'The URL inside it was replaced.' );
	}

	// ---------------------------------------------------------------------
	// replaceContent(): data shapes.
	// ---------------------------------------------------------------------

	public function test_every_search_value_is_replaced_by_its_index_matched_replacement() {
		$replacer = $this->newReplacer();
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
		$class      = 'EMR_Class_Not_Present';
		$serialized = 'O:' . strlen( $class ) . ':"' . $class . '":1:{s:3:"url";s:' . strlen( self::OLD_URL ) . ':"' . self::OLD_URL . '";}';
		$this->assertFalse( class_exists( $class, false ), 'Sentinel: the class does not exist.' );

		$this->assertSame( $serialized, $this->replaceFresh( $serialized ) );
	}

	/**
	 * Changed JSON is re-encoded with the default flags, at the top level and
	 * below it: no escaped slashes, literal non-ASCII.
	 */
	public function test_json_array_of_strings_is_re_encoded_with_the_default_flags() {
		$json = wp_json_encode( array( self::OLD_URL, 'café' ) );
		$this->assertStringContainsString( '\/', $json, 'Sentinel: the input JSON escapes its slashes.' );
		$this->assertStringContainsString( '\u00e9', $json, 'Sentinel: the input JSON escapes non-ASCII.' );

		$expected = '["' . self::NEW_URL . '","café"]';
		$this->assertSame( $expected, $this->replaceFresh( $json ) );
		$this->assertSame( $expected, $this->replaceFresh( $json, true ), 'JSON met below the top level is encoded back at that level' );
	}

	public function test_json_flags_setting_is_used_when_re_encoding() {
		$replacer = $this->newReplacer();
		$this->callPrivate( $replacer, 'setReplaceSettings', array( array( 'json_flags' => 0 ) ) );

		$result = $replacer->replaceContent( '["' . self::OLD_URL . '"]', array( self::OLD_URL ), array( self::NEW_URL ) );

		$this->assertSame( '["' . str_replace( '/', '\/', self::NEW_URL ) . '"]', $result );
	}

	/**
	 * JSON without a match is returned byte for byte, escaping included,
	 * instead of being decoded and re-encoded with the `json_flags`.
	 */
	public function test_json_without_a_match_is_returned_unchanged() {
		$json = wp_json_encode( array( 'src' => 'http://example.org/other.jpg', 'list' => array( 'a' ) ) );
		$this->assertStringContainsString( '\/', $json, 'Sentinel: the input JSON escapes its slashes.' );

		$this->assertSame( $json, $this->replaceFresh( $json ) );
		$this->assertSame( $json, $this->replaceFresh( $json, true ) );
	}

	/**
	 * A JSON object carrying the URL is re-encoded with the new URL, at the
	 * top level and below it. The change is detected by value: json_decode()
	 * yields stdClass objects that are rewritten in place, so the comparison
	 * runs against a second decode of the input.
	 */
	public function test_json_object_with_the_url_is_re_encoded_without_escaped_slashes() {
		$json = wp_json_encode( array( 'src' => self::OLD_URL ) );
		$this->assertStringContainsString( '\/', $json, 'Sentinel: the input JSON escapes its slashes.' );

		$expected = '{"src":"' . self::NEW_URL . '"}';
		$this->assertSame( $expected, $this->replaceFresh( $json ) );
		$this->assertSame( $expected, $this->replaceFresh( $json, true ), 'JSON met below the top level is encoded back at that level' );
	}

	/**
	 * Same for a JSON array of objects, the shape of Elementor data.
	 */
	public function test_json_array_of_objects_with_the_url_is_re_encoded() {
		$json = wp_json_encode( array( array( 'url' => self::OLD_URL ) ) );
		$this->assertStringContainsString( '\/', $json, 'Sentinel: the input JSON escapes its slashes.' );

		$this->assertSame( '[{"url":"' . self::NEW_URL . '"}]', $this->replaceFresh( $json ) );
	}

	/**
	 * A JSON string inside a JSON document is decoded at the inner level and
	 * encoded back there, so the document keeps its shape: `inner` is still
	 * a string, with only the URL changed.
	 */
	public function test_json_string_inside_json_keeps_its_shape() {
		$inner = '{"u":"' . self::OLD_URL . '"}';
		$json  = wp_json_encode( array( 'inner' => $inner ), JSON_UNESCAPED_SLASHES );
		$this->assertIsString( json_decode( $json )->inner, 'Sentinel: inner is a string in the input.' );

		$result = $this->replaceFresh( $json );

		$this->assertIsString( $result );
		$decoded = json_decode( $result );
		$this->assertIsString( $decoded->inner, 'The inner JSON string is still a string.' );
		$this->assertSame( self::NEW_URL, json_decode( $decoded->inner )->u, 'Only the URL inside it changed.' );
	}

	public function test_replace_no_serialize_returns_arrays_and_objects_as_they_are() {
		$replacer = $this->newReplacer();
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
		$replacer = $this->newReplacer();
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

	/**
	 * The constructor applies the default settings with its component, so
	 * replaceContent() called directly runs with them.
	 */
	public function test_constructor_applies_the_default_settings_with_its_component() {
		$replacer = $this->newReplacer();

		$settings = $this->readPrivate( $replacer, 'replace_settings' );
		$this->assertSame(
			array(
				'component'            => self::COMPONENT,
				'json_flags'           => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
				'replacer_do_save'     => true,
				'replace_no_serialize' => false,
			),
			$settings
		);

		$replacer->replaceContent( 'x', array( 'a' ), array( 'b' ) );
		$this->assertSame( $settings, $this->readPrivate( $replacer, 'replace_settings' ), 'replaceContent() keeps the settings it found' );
	}

	// ---------------------------------------------------------------------
	// Private helpers (Replacer2) and Setup::fileIsRestricted().
	// ---------------------------------------------------------------------

	public function test_set_replace_settings_merges_over_the_defaults_and_resets_without_arguments() {
		$replacer = $this->newReplacer();

		$this->callPrivate( $replacer, 'setReplaceSettings', array( array( 'component' => 'mine', 'replacer_do_save' => false ) ) );
		$partial = $this->readPrivate( $replacer, 'replace_settings' );
		$this->callPrivate( $replacer, 'setReplaceSettings' );
		$reset = $this->readPrivate( $replacer, 'replace_settings' );

		$this->assertSame( 'mine', $partial['component'] );
		$this->assertFalse( $partial['replacer_do_save'] );
		$this->assertSame( JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, $partial['json_flags'], 'Missing keys take their default' );
		$this->assertSame(
			array(
				'component'            => 'unset',
				'json_flags'           => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
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

		$result = $this->callPrivate( new Setup(), 'fileIsRestricted', array( '/any/path' ) );

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
		$setup = new Setup();

		$allowed    = $this->callPrivate( $setup, 'fileIsRestricted', array( '/wp-content/uploads/a' ) );
		$restricted = $this->callPrivate( $setup, 'fileIsRestricted', array( 'relative-path-without-slash' ) );
		$this->addFilter( 'emr/file/basedir_check', '__return_false' );
		$overridden = $this->callPrivate( $setup, 'fileIsRestricted', array( 'relative-path-without-slash' ) );

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

		$result = $this->callPrivate( $this->newReplacer(), 'change_key', array( $input, array( 'old' => 'new' ) ) );

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
		$replacer = $this->newReplacer();
		$this->assertSame( 'string', $this->callPrivate( $replacer, 'change_key', array( 'string', array( 'a' => 'b' ) ) ) );
		$this->assertSame( array( 'a' => 1 ), $this->callPrivate( $replacer, 'change_key', array( array( 'a' => 1 ), 'not-a-map' ) ) );
	}

	public function test_is_json_boundaries() {
		$replacer = $this->newReplacer();
		$cases    = array(
			array( '{"a":1}', true ),
			array( '[1,2]', true ),
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

	/**
	 * A text wrapped in double quotes, such as `"abc"`, is a quotation
	 * somebody typed with the quotes (a page builder heading) or a string a
	 * plugin stored JSON-encoded. isJSON() reads it as a JSON document,
	 * because json_decode() gives a string that differs from the input, so
	 * replaceContent() decodes it and encodes it again (see the two tests
	 * below).
	 *
	 * Where: Replacer2::isJSON() in the bundled build
	 * (build/shortpixel/replacer2/src/Replacer2.php). The module source
	 * already answers false for everything that decodes to a scalar; the
	 * bundled copy gets that with the next `composer run buildSP`.
	 *
	 * How to recognise the fix: isJSON() answers false for a quoted text.
	 */
	public function test_quoted_text_counts_as_a_json_document_pinned_for_deferred_fix() {
		$replacer = $this->newReplacer();
		$this->assertSame( 'abc', json_decode( '"abc"' ), 'Sentinel: the quoted text decodes to a string, not to a document.' );

		$this->assertTrue( $this->callPrivate( $replacer, 'isJSON', array( '"abc"' ) ), 'Pinned: a quoted text is taken for a JSON document.' );
	}

	/**
	 * Because a quoted text counts as a JSON document (see the test above),
	 * replaceContent() decodes it, replaces in the decoded text and encodes
	 * it again. The URL is replaced, but the rest of the value is rewritten
	 * too: the whitespace around the quotes is dropped, `\/` and `\uXXXX`
	 * escapes are written as plain characters and an inner `\"` becomes
	 * `\u0022`. A value in which nothing is replaced is returned as stored.
	 *
	 * Where: Replacer2::replaceContent(), the JSON branch, in the bundled
	 * build. The module source replaces inside the literal and keeps its
	 * bytes; the bundled copy gets that with the next
	 * `composer run buildSP`.
	 *
	 * How to recognise the fix: each result is the stored value with only
	 * the URL changed.
	 */
	public function test_quoted_text_with_the_url_is_rewritten_as_json_pinned_for_deferred_fix() {
		$replacer = $this->newReplacer();
		$replace  = function ( $content ) use ( $replacer ) {
			return $replacer->replaceContent( $content, array( self::OLD_URL ), array( self::NEW_URL ) );
		};

		$untouched = " \n\"Caf\u{00e9}\"\n\t";
		$this->assertSame( $untouched, $replace( $untouched ), 'Sentinel: without a match the value is returned as stored.' );

		$this->assertSame(
			"\"Caf\u{00e9}: " . self::NEW_URL . '"',
			$replace( " \n\"Caf\u{00e9}: " . self::OLD_URL . "\"\n\t" ),
			'Pinned: the whitespace around the quotes is dropped.'
		);

		$literal = json_encode( "Caf\u{00e9} " . self::OLD_URL );
		$this->assertSame( '"Caf\u00e9 http:\/\/example.org\/wp-content\/uploads\/old-image.jpg"', $literal, 'Sentinel: slashes and non-ASCII are escaped.' );
		$this->assertSame(
			"\"Caf\u{00e9} " . self::NEW_URL . '"',
			$replace( $literal ),
			'Pinned: the escapes of the stored literal are written as plain characters.'
		);

		$quotes = '"say \"hi\" in C:\\\\dir: ' . self::OLD_URL . '"';
		$this->assertSame( 'say "hi" in C:\\dir: ' . self::OLD_URL, json_decode( $quotes ), 'Sentinel: a literal with escaped quotes and a backslash.' );
		$this->assertSame(
			'"say \u0022hi\u0022 in C:\\\\dir: ' . self::NEW_URL . '"',
			$replace( $quotes ),
			'Pinned: the inner quotes are written as \u0022.'
		);
	}

	/**
	 * A serialized quoted text (`s:N:""...";`) is unserialized, taken for a
	 * JSON document, replaced and encoded back as JSON, and the JSON branch
	 * skips the serialize() of the value this call unserialized: the bare
	 * quoted text comes back, without its serialization layer.
	 *
	 * Where: Replacer2::replaceContent() in the bundled build, the `$isJson`
	 * branch before the `$was_unserialized` one. The module source handles a
	 * quoted text as a string, which is serialized back; the bundled copy
	 * gets that with the next `composer run buildSP`.
	 *
	 * How to recognise the fix: the result is the serialized quoted text
	 * with the new URL and a valid length.
	 */
	public function test_serialized_quoted_text_loses_its_serialization_layer_pinned_for_deferred_fix() {
		$stored = serialize( '"' . self::OLD_URL . '"' );
		$this->assertTrue( is_serialized( $stored ), 'Sentinel: the value is a serialized string.' );

		$result = $this->newReplacer()->replaceContent( $stored, array( self::OLD_URL ), array( self::NEW_URL ) );

		$this->assertSame( '"' . self::NEW_URL . '"', $result, 'Pinned: the bare quoted text comes back.' );
		$this->assertFalse( is_serialized( $result ), 'Pinned: the serialization layer is gone.' );
	}

	public function test_check_incomplete_is_true_only_for_incomplete_objects() {
		$replacer   = $this->newReplacer();
		$incomplete = unserialize( serialize( new stdClass() ), array( 'allowed_classes' => false ) );

		$this->assertTrue( $this->callPrivate( $replacer, 'checkIncomplete', array( $incomplete ) ) );
		$this->assertFalse( $this->callPrivate( $replacer, 'checkIncomplete', array( new stdClass() ) ) );
		$this->assertFalse( $this->callPrivate( $replacer, 'checkIncomplete', array( 'O:8:"stdClass":0:{}' ) ) );
	}
}
