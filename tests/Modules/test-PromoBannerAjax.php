<?php
/**
 * Tests for the dismiss endpoint of the bundled promo-banner module
 * (PromoBanner::ajaxDismiss(), action `<prefix>_promo_banner_dismiss`).
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\PromoBanner\PromoBanner;

class PromoBannerAjaxTest extends WP_Ajax_UnitTestCase {

	/** @var PromoBanner */
	private $banner;

	/** @var array */
	private $savedPost;

	public function set_up() {
		parent::set_up();
		$this->savedPost = $_POST;
		$this->banner    = new PromoBanner( array( 'prefix' => 'emrtest' ) );
		$this->banner->register();
	}

	public function tear_down() {
		remove_action( 'wp_ajax_emrtest_promo_banner_dismiss', array( $this->banner, 'ajaxDismiss' ) );
		remove_action( 'admin_notices', array( $this->banner, 'render' ) );
		remove_action( 'admin_enqueue_scripts', array( $this->banner, 'enqueue' ) );
		$_POST = $this->savedPost;
		parent::tear_down();
	}

	private function call() {
		try {
			$this->_handleAjax( 'emrtest_promo_banner_dismiss' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		return json_decode( $this->_last_response, true );
	}

	public function test_a_valid_request_stores_the_dismissal_for_the_user() {
		$this->_setRole( 'administrator' );
		$this->assertFalse( $this->banner->isDismissed(), 'Precondition.' );
		$_POST['nonce'] = wp_create_nonce( 'emrtest_promo_banner_dismiss' );

		$answer = $this->call();

		$this->assertTrue( $answer['success'] );
		$this->assertTrue( $this->banner->isDismissed() );
		$this->assertIsNumeric( get_user_meta( get_current_user_id(), 'shortpixel_promo_dismissed_black-friday-2026', true ) );
	}

	public function test_a_bad_nonce_stores_nothing() {
		$this->_setRole( 'administrator' );
		$_POST['nonce'] = 'not-a-nonce';

		$answer = $this->call();

		$this->assertFalse( $answer['success'] );
		$this->assertFalse( $this->banner->isDismissed() );
	}

	public function test_a_user_without_the_capability_stores_nothing() {
		$this->_setRole( 'editor' );
		$_POST['nonce'] = wp_create_nonce( 'emrtest_promo_banner_dismiss' );

		$answer = $this->call();

		$this->assertFalse( $answer['success'] );
		$this->assertFalse( $this->banner->isDismissed() );
	}
}
