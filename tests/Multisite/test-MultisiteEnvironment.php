<?php
/**
 * Multisite: the environment flags and the suite's own preconditions.
 *
 * Runs against a NETWORK WordPress test install (bin/test.sh --ms). Proves
 * that the harness really built a network, that EnvHelper reports it, and
 * that a subsite created by the test factory gets its own uploads directory
 * under `sites/N/`, which every other test in this suite relies on.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Helper\EnvHelper;

class MultisiteEnvironmentTest extends EMR_IntegrationTestCase {

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite test install — run via bin/test.sh --ms.' );
		}
		parent::set_up();
	}

	public function test_the_test_install_is_a_network() {
		$this->assertTrue( is_multisite(), 'The install is a network.' );
		$this->assertTrue( is_main_site(), 'Tests start on the main site.' );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_env_helper_reports_the_network_flags() {
		$env = EnvHelper::getInstance();
		$this->assertTrue( $env->is_multisite );
		$this->assertTrue( $env->is_mainsite );
		$this->assertFalse( $env->is_network_admin, 'A plain test request is not the network admin.' );
	}

	public function test_a_subsite_uploads_into_its_sites_directory() {
		$blog_id = self::factory()->blog->create();
		$this->assertGreaterThan( 1, $blog_id );

		switch_to_blog( $blog_id );
		try {
			$uploads = wp_upload_dir();
			$this->assertStringContainsString( '/sites/' . $blog_id . '/', trailingslashit( $uploads['basedir'] ), 'The subsite uploads under sites/N/.' );
			$this->assertStringContainsString( '/sites/' . $blog_id . '/', trailingslashit( $uploads['baseurl'] ) );

			$this->resetSingleton( 'EnableMediaReplace\Helper\EnvHelper' );
			$env = EnvHelper::getInstance();
			$this->assertTrue( $env->is_multisite );
			$this->assertFalse( $env->is_mainsite, 'EnvHelper built on the subsite is not the main site.' );
		} finally {
			restore_current_blog();
		}

		$this->resetSingleton( 'EnableMediaReplace\Helper\EnvHelper' );
		$this->assertTrue( EnvHelper::getInstance()->is_mainsite, 'Back on the main site after restore_current_blog().' );
	}
}
