<?php
/**
 * Isolated integration tests for the plugin's constants.
 *
 * This file is the only member of the `IntegrationIsolated` testsuite. It
 * lives apart from the `Integration` suite because tests that `define()`
 * wp-config style constants (such as EMR_CAPABILITY) poison every test that
 * runs after them in the same PHP process. Anything that needs to define a
 * constant belongs here, and nothing here may assume another test ran first.
 *
 * The constants this file reads are defined by enable-media-replace.php at
 * load time, so the assertions describe the defaults a plain install gets.
 *
 * @package Enable_Media_Replace
 */

class ConstantsAndFiltersTest extends EMR_IntegrationTestCase {

	public function set_up() {
		parent::set_up();
		// useFeature('background') is false for users without edit_others_posts
		// (the author restriction), so the feature checks need an administrator.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_capability_constant_defaults_to_false() {
		$this->assertTrue( defined( 'EMR_CAPABILITY' ), 'EMR_CAPABILITY is defined by the root plugin file.' );
		$this->assertFalse( EMR_CAPABILITY, 'Without a wp-config override the capability constant is boolean false.' );
	}

	public function test_version_constant_is_a_semantic_version_string() {
		$this->assertIsString( EMR_VERSION );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+(\.\d+)?$/', EMR_VERSION );
	}

	public function test_root_file_constant_is_an_existing_plugin_file() {
		$this->assertFileExists( EMR_ROOT_FILE );
		$this->assertSame( 'enable-media-replace.php', basename( EMR_ROOT_FILE ) );
	}

	public function test_feature_filters_default_to_enabled() {
		$this->assertTrue( apply_filters( 'emr/feature/background', true ) );
		$this->assertTrue( apply_filters( 'emr/feature/upscale', true ) );
		$this->assertTrue( apply_filters( 'emr/feature/remote_notice', true ) );
		$this->assertTrue( emr()->useFeature( 'background' ) );
		$this->assertTrue( emr()->useFeature( 'upscale' ) );
		$this->assertTrue( emr()->useFeature( 'remote_notice' ) );
		$this->assertFalse( emr()->useFeature( 'unknown' ) );
	}

	public function test_upscale_feature_filter_can_disable_the_feature_on_its_own() {
		add_filter( 'emr/feature/upscale', '__return_false' );

		emr()->adminInit();
		$this->assertFalse( emr()->useFeature( 'upscale' ) );
		$this->assertTrue( emr()->useFeature( 'background' ) );

		remove_filter( 'emr/feature/upscale', '__return_false' );
		emr()->adminInit();
		$this->assertTrue( emr()->useFeature( 'upscale' ) );
	}

	public function test_background_feature_filter_can_disable_the_feature() {
		add_filter( 'emr/feature/background', '__return_false' );

		// useFeature() reads the flags resolved on admin_init; re-run the resolution.
		emr()->adminInit();
		$this->assertFalse( emr()->useFeature( 'background' ) );

		remove_filter( 'emr/feature/background', '__return_false' );
		emr()->adminInit();
		$this->assertTrue( emr()->useFeature( 'background' ) );
	}
}
