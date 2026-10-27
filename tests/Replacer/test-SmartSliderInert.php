<?php
/**
 * Test for EnableMediaReplace\Replacer\Modules\SmartSlider
 * (build/shortpixel/replacer2/src/Modules/SmartSlider.php) with Smart
 * Slider 3 detected.
 *
 * The module detects Smart Slider through the NEXTEND_SMARTSLIDER_3
 * constant, and a constant cannot be undefined once defined. The test
 * defines it for its own PHP process only: it runs with
 * runInSeparateProcess, so the constant never reaches any other test.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Replacer;
use EnableMediaReplace\Replacer\Modules\SmartSlider;

/**
 * Modules\SmartSlider with the plugin detected.
 */
class SmartSliderInertTest extends WP_UnitTestCase {

	/**
	 * The SmartSlider constructor returns false right after the constant
	 * check, so even with Smart Slider 3 detected it never stores the
	 * replacer, never reads the upload directory and never registers
	 * doReplaceQueries() on `emr/replacer/replace_urls`: images in the
	 * Smart Slider slides table are not replaced. The correct behaviour,
	 * once the integration is meant to be enabled, is to register the
	 * action.
	 *
	 * How to recognise the fix: has_action() returns 10 and the pinned
	 * assertFalse() below fails.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_smartslider_registers_nothing_even_when_detected_pinned_for_deferred_fix() {
		if ( ! defined( 'NEXTEND_SMARTSLIDER_3' ) ) {
			define( 'NEXTEND_SMARTSLIDER_3', '3.5-test' );
		}
		$instance = new ReflectionProperty( SmartSlider::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$replacer = new Replacer();
		$module   = SmartSlider::getInstance( $replacer );

		$this->assertTrue( defined( 'NEXTEND_SMARTSLIDER_3' ), 'Sentinel: Smart Slider is detected.' );
		$this->assertInstanceOf( SmartSlider::class, $module );
		$this->assertFalse( has_action( 'emr/replacer/replace_urls', array( $module, 'doReplaceQueries' ) ), 'Pinned: the action is not registered.' );
		$stored = new ReflectionProperty( SmartSlider::class, 'replacer' );
		$stored->setAccessible( true );
		$this->assertNull( $stored->getValue( $module ), 'Pinned: the replacer is never stored.' );
	}
}
