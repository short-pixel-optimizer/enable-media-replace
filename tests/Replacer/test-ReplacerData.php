<?php
/**
 * Tests for EnableMediaReplace\Replacer\Classes\Data
 * (build/shortpixel/replacer2/src/Classes/Data.php): one side of a replacement, its URL,
 * metadata and extra URLs, and the relative URL paths built from them.
 * Also checks the Utils placeholder.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Classes\Data;

/**
 * Classes\Data, Classes\Utils.
 */
class ReplacerDataTest extends WP_UnitTestCase {

	/**
	 * Runs a callable and returns [result, captured PHP errors].
	 *
	 * @param callable $callable Code to run.
	 * @return array
	 */
	private function capture( $callable ) {
		$errors = array();
		set_error_handler(
			function ( $errno, $errstr ) use ( &$errors ) {
				$errors[] = $errstr;
				return true;
			}
		);
		try {
			$result = $callable();
		} finally {
			restore_error_handler();
		}
		return array( $result, $errors );
	}

	public function test_set_url_keeps_the_url_and_its_path() {
		$data = new Data( 'search' );
		$this->assertFalse( $data->hasUrl(), 'precondition' );

		$this->assertTrue( $data->setUrl( 'http://example.org/wp-content/uploads/2026/10/photo.jpg' ) );

		$this->assertTrue( $data->hasUrl() );
		$this->assertSame( 'http://example.org/wp-content/uploads/2026/10/photo.jpg', $data->getUrl() );
		$this->assertSame( '/wp-content/uploads/2026/10/photo.jpg', $data->getPath() );
		$this->assertSame( array( 'base' => '/wp-content/uploads/2026/10/photo.jpg' ), $data->getUrls() );
	}

	public function test_set_url_again_replaces_the_main_file() {
		$data = new Data( 'search' );
		$data->setUrl( 'http://example.org/uploads/first.jpg' );

		$data->setUrl( 'http://example.org/other/second.png' );

		$this->assertSame( array( 'base' => '/other/second.png' ), $data->getUrls() );
	}

	public function test_set_url_refuses_a_value_without_path_and_keeps_the_previous_url() {
		$data = new Data( 'search' );
		$data->setUrl( 'http://example.org/uploads/photo.jpg' );

		list( $result, $errors ) = $this->capture(
			function () use ( $data ) {
				return array( $data->setUrl( 'http://example.org' ), $data->setUrl( false ), $data->setUrl( null ), $data->setUrl( '' ), $data->setUrl( array( 'x' ) ) );
			}
		);

		$this->assertSame( array( false, false, false, false, false ), $result );
		$this->assertSame( array(), $errors, 'Refused without a PHP error' );
		$this->assertSame( 'http://example.org/uploads/photo.jpg', $data->getUrl() );
	}

	public function test_without_url_there_are_no_urls() {
		$data = new Data( 'replace' );
		$data->setMetaData( array( 'file' => '2026/10/photo.jpg' ) );

		$this->assertFalse( $data->hasUrl() );
		$this->assertSame( array(), $data->getUrls() );
	}

	public function test_get_urls_places_the_metadata_files_next_to_the_main_file() {
		$data = new Data( 'search' );
		$data->setUrl( 'http://example.org/wp-content/uploads/2026/10/photo.jpg' );
		$data->setMetaData(
			array(
				'file'  => '2026/10/photo-scaled.jpg',
				'width' => 2560,
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150 ),
					'no_file'   => array( 'width' => 300 ),
					'array'     => array( 'file' => array( 'photo-300x200.jpg' ) ),
				),
			)
		);

		$this->assertSame(
			array(
				'base'      => '/wp-content/uploads/2026/10/photo.jpg',
				'file'      => '/wp-content/uploads/2026/10/photo-scaled.jpg',
				'thumbnail' => '/wp-content/uploads/2026/10/photo-150x150.jpg',
			),
			$data->getUrls(),
			'Sizes without a string file are left out'
		);
	}

	public function test_get_urls_cut_the_directory_at_the_last_slash() {
		$sizes = array( 'sizes' => array( 'thumbnail' => array( 'file' => 'photo-150x150.jpg' ) ) );

		$repeated = new Data( 'search' );
		$repeated->setUrl( 'http://example.org/uploads/photo.jpg-dir/photo.jpg' );
		$repeated->setMetaData( $sizes );
		$encoded = new Data( 'search' );
		$encoded->setUrl( 'http://example.org/uploads/my%20photo.jpg' );
		$encoded->setMetaData( $sizes );

		$this->assertSame( '/uploads/photo.jpg-dir/photo-150x150.jpg', $repeated->getUrls()['thumbnail'], 'The basename in a directory name does not cut the directory' );
		$this->assertSame( '/uploads/photo-150x150.jpg', $encoded->getUrls()['thumbnail'], 'A percent-encoded basename does not stay in the directory' );
	}

	public function test_set_metadata_accepts_no_metadata_and_refuses_other_types() {
		foreach ( array( false, null, '', array() ) as $none ) {
			$data = new Data( 'replace' );
			$this->assertTrue( $data->setMetaData( $none ), 'No metadata is valid: ' . var_export( $none, true ) );
			$this->assertSame( array(), $data->getMetaData() );
			$this->assertSame( array(), $data->getErrors() );
		}

		foreach ( array( 'a:0:{}', new WP_Error( 'x', 'y' ), 42 ) as $invalid ) {
			$data = new Data( 'replace' );
			$this->assertFalse( $data->setMetaData( $invalid ) );
			$this->assertCount( 1, $data->getErrors() );
			$this->assertStringContainsString( 'replace', $data->getErrors()[0] );
			$this->assertSame( array(), $data->getMetaData() );
		}
	}

	public function test_add_urls_come_last_and_override_by_key() {
		$data = new Data( 'search' );
		$data->setUrl( 'http://example.org/uploads/photo.jpg' );
		$data->setMetaData( array( 'sizes' => array( 'thumbnail' => array( 'file' => 'photo-150x150.jpg' ) ) ) );

		$this->assertTrue( $data->addUrls( array( 'webp' => '/uploads/photo.webp' ) ) );
		$this->assertTrue( $data->addUrls( array( 'webp' => '/uploads/photo-2.webp', 'thumbnail' => '/uploads/custom.jpg' ) ) );

		$this->assertSame(
			array( 'base' => '/uploads/photo.jpg', 'thumbnail' => '/uploads/custom.jpg', 'webp' => '/uploads/photo-2.webp' ),
			$data->getUrls()
		);
	}

	public function test_add_urls_refuses_bad_input_as_a_whole() {
		$data = new Data( 'replace' );
		$data->setUrl( 'http://example.org/uploads/photo.jpg' );

		$this->assertFalse( $data->addUrls( 'http://example.org/uploads/photo.webp' ), 'Not an array' );
		$this->assertFalse( $data->addUrls( array( 'base' => '/uploads/other.jpg' ) ), 'Reserved key' );
		$this->assertFalse( $data->addUrls( array( 'webp' => '/uploads/photo.webp', 'avif' => '' ) ), 'Empty value' );
		$this->assertFalse( $data->addUrls( array( 'webp' => '/uploads/photo.webp', 'avif' => false ) ), 'Non-string value' );

		$this->assertSame( array( 'base' => '/uploads/photo.jpg' ), $data->getUrls(), 'Nothing of a refused call is added' );
	}

	public function test_utils_is_a_namespaced_class() {
		$file = dirname( dirname( __DIR__ ) ) . '/build/shortpixel/replacer2/src/Classes/Utils.php';
		$this->assertFileExists( $file );

		$namespaces = 0;
		foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
			if ( is_array( $token ) && T_NAMESPACE === $token[0] ) {
				$namespaces++;
			}
		}

		$this->assertSame( 1, $namespaces );
		$this->assertTrue( class_exists( 'EnableMediaReplace\\Replacer\\Classes\\Utils' ) );
	}
}
