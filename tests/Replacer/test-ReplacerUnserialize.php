<?php
/**
 * Tests for EnableMediaReplace\Replacer\Libraries\Unserialize\Unserialize
 * (build/shortpixel/replacer2/src/Libraries/Unserialize/Unserialize.php),
 * the unserialize() polyfill the replacer uses with
 * `allowed_classes => false`.
 *
 * Only the native branch runs: the polyfill's own substitution branch
 * (DisallowedClassesSubstitutor) is for PHP below 7.0, and EMR requires
 * PHP 7.4.
 *
 * This file declares the global class EMR_Test_Unserialize_Probe, which only
 * records calls to __wakeup; it stays declared for the rest of the process.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Libraries\Unserialize\Unserialize;

if ( ! class_exists( 'EMR_Test_Unserialize_Probe' ) ) {
	/**
	 * Records unserialize wake-ups.
	 */
	class EMR_Test_Unserialize_Probe {

		/**
		 * Number of __wakeup calls.
		 *
		 * @var int
		 */
		public static $wakeups = 0;

		/**
		 * Payload property.
		 *
		 * @var string
		 */
		public $value = '';

		/** Counts a wake-up. */
		public function __wakeup() {
			self::$wakeups++;
		}
	}
}

/**
 * Libraries\Unserialize\Unserialize.
 */
class ReplacerUnserializeTest extends WP_UnitTestCase {

	/**
	 * Resets the probe.
	 */
	public function set_up() {
		parent::set_up();
		EMR_Test_Unserialize_Probe::$wakeups = 0;
	}

	/**
	 * Resets the probe.
	 */
	public function tear_down() {
		EMR_Test_Unserialize_Probe::$wakeups = 0;
		parent::tear_down();
	}

	/**
	 * A serialized probe object.
	 *
	 * @return string
	 */
	private function probe() {
		$probe        = new EMR_Test_Unserialize_Probe();
		$probe->value = 'kept';
		$serialized   = serialize( $probe );
		EMR_Test_Unserialize_Probe::$wakeups = 0;
		return $serialized;
	}

	public function test_allowed_classes_false_turns_every_object_into_an_incomplete_class() {
		$result = Unserialize::unserialize(
			serialize( array( 'std' => new stdClass(), 'probe' => new EMR_Test_Unserialize_Probe() ) ),
			array( 'allowed_classes' => false )
		);

		$this->assertIsArray( $result );
		$this->assertInstanceOf( '__PHP_Incomplete_Class', $result['std'], 'stdClass included' );
		$this->assertInstanceOf( '__PHP_Incomplete_Class', $result['probe'] );
		$this->assertSame( 0, EMR_Test_Unserialize_Probe::$wakeups, 'No object was woken up' );
	}

	public function test_without_options_objects_are_instantiated() {
		$result = Unserialize::unserialize( $this->probe() );

		$this->assertInstanceOf( EMR_Test_Unserialize_Probe::class, $result );
		$this->assertSame( 'kept', $result->value );
		$this->assertSame( 1, EMR_Test_Unserialize_Probe::$wakeups );
	}

	public function test_allowed_classes_list_instantiates_only_the_listed_classes() {
		$serialized = serialize( array( new stdClass(), new EMR_Test_Unserialize_Probe() ) );

		$result = Unserialize::unserialize( $serialized, array( 'allowed_classes' => array( 'stdClass' ) ) );

		$this->assertInstanceOf( stdClass::class, $result[0] );
		$this->assertInstanceOf( '__PHP_Incomplete_Class', $result[1] );
		$this->assertSame( 0, EMR_Test_Unserialize_Probe::$wakeups );
	}

	public function test_scalars_and_arrays_unserialize_as_usual() {
		$data = array( 'a' => 1, 'b' => array( 'c' => 'd' ), 'e' => null, 'f' => 1.5 );

		$this->assertSame( $data, Unserialize::unserialize( serialize( $data ), array( 'allowed_classes' => false ) ) );
		$this->assertSame( 'text', Unserialize::unserialize( serialize( 'text' ), array( 'allowed_classes' => false ) ) );
	}
}
