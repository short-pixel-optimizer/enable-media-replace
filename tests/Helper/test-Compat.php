<?php
/**
 * Tests for classes/compat.php, the polyfills of three core functions.
 *
 * Every WordPress version the test library installs (5.9 and later) ships
 * wp_delete_attachment_files(), wp_delete_file_from_directory() and
 * wp_get_upload_dir(), so the guarded declarations in compat.php never run.
 * These tests prove that: each function exists and is declared inside
 * wp-includes, not in the plugin's compat file.
 *
 * @package Enable_Media_Replace
 */

class CompatTest extends WP_UnitTestCase {

	/**
	 * Asserts that a function exists and is declared by core.
	 *
	 * @param string $name Function name.
	 */
	private function assert_declared_by_core( $name ) {
		$this->assertTrue( function_exists( $name ), $name . ' must exist.' );

		$file = wp_normalize_path( ( new ReflectionFunction( $name ) )->getFileName() );

		$this->assertStringContainsString( '/' . WPINC . '/', $file, $name . ' must come from core.' );
		$this->assertStringEndsNotWith( 'classes/compat.php', $file );
	}

	public function test_compat_file_is_loaded() {
		$compat = wp_normalize_path( realpath( dirname( dirname( __DIR__ ) ) . '/classes/compat.php' ) );
		$loaded = array_map( 'wp_normalize_path', get_included_files() );

		$this->assertContains( $compat, $loaded );
	}

	public function test_wp_delete_attachment_files_is_the_core_function() {
		$this->assert_declared_by_core( 'wp_delete_attachment_files' );
	}

	public function test_wp_delete_file_from_directory_is_the_core_function() {
		$this->assert_declared_by_core( 'wp_delete_file_from_directory' );
	}

	public function test_wp_get_upload_dir_is_the_core_function() {
		$this->assert_declared_by_core( 'wp_get_upload_dir' );
	}
}
