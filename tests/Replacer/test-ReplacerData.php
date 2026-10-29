<?php
/**
 * Tests for the data classes of the newer search/replace API in
 * build/shortpixel/replacer2/src/Classes/: Data (abstract base), Url,
 * MetaData, and the stray global Utils class.
 *
 * Errors raised by the code under test are captured with a local error
 * handler: the harness's own handler in tests/bootstrap.php keeps PHPUnit
 * from converting notices and warnings into exceptions.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Classes\MetaData;
use EnableMediaReplace\Replacer\Classes\Url;

/**
 * Classes\Data, Classes\Url, Classes\MetaData, Classes\Utils.
 */
class ReplacerDataTest extends WP_UnitTestCase {

	/**
	 * Reads a protected property of an object.
	 *
	 * @param object $object Instance.
	 * @param string $name   Property name.
	 * @return mixed
	 */
	private function readProperty( $object, $name ) {
		$prop = new ReflectionProperty( get_class( $object ), $name );
		$prop->setAccessible( true );
		return $prop->getValue( $object );
	}

	/**
	 * Runs a callable and returns [result or Throwable, captured errors].
	 *
	 * @param callable $callable Code to run.
	 * @return array
	 */
	private function capture( $callable ) {
		$errors = array();
		set_error_handler(
			function ( $errno, $errstr ) use ( &$errors ) {
				$errors[] = array( $errno, $errstr );
				return true;
			}
		);
		try {
			$result = $callable();
		} catch ( Throwable $e ) {
			$result = $e;
		} finally {
			restore_error_handler();
		}
		return array( $result, $errors );
	}

	public function test_url_add_data_appends_and_the_base_url_comes_from_the_first_url() {
		$url = new Url( 'search' );

		$url->addData( 'http://example.org/wp-content/uploads/2026/10/photo.jpg' );
		$url->addData( 'http://example.org/wp-content/uploads/2026/10/other.png' );

		$this->assertSame(
			array( 'http://example.org/wp-content/uploads/2026/10/photo.jpg', 'http://example.org/wp-content/uploads/2026/10/other.png' ),
			$this->readProperty( $url, 'data' )
		);
		$this->assertSame( '/wp-content/uploads/2026/10/photo', $url->getBaseURL() );
	}

	/**
	 * getBaseURL() removes the extension with str_replace(), so the same
	 * text earlier in the path goes too (`a.jpg-dir/a.jpg` becomes
	 * `a-dir/a`), and for a URL without an extension every dot in the path
	 * is removed. Either way the LIKE fragment matches nothing. The correct
	 * behaviour is to strip only the trailing extension.
	 *
	 * How to recognise the fix: getBaseURL() returns
	 * `/wp-content/uploads/a.jpg-dir/a` and `/uploads/v1.2/file`, and the
	 * pinned assertSame() calls below fail.
	 */
	public function test_get_base_url_strips_the_extension_everywhere_in_the_path_pinned_for_deferred_fix() {
		$with_extension = new Url( 'search' );
		$with_extension->addData( 'http://example.org/wp-content/uploads/a.jpg-dir/a.jpg' );
		$without_extension = new Url( 'search' );
		$without_extension->addData( 'http://example.org/uploads/v1.2/file' );

		$this->assertSame( '/wp-content/uploads/a-dir/a', $with_extension->getBaseURL(), 'Pinned: the directory lost its ".jpg".' );
		$this->assertSame( '/uploads/v12/file', $without_extension->getBaseURL(), 'Pinned: the dot in the directory is gone.' );
	}

	public function test_data_has_no_errors_by_default() {
		$this->assertFalse( ( new Url( 'replace' ) )->hasErrors() );
		$this->assertFalse( ( new MetaData( 'replace' ) )->hasErrors() );
	}

	/**
	 * MetaData::addData() hands Data::getRelativeURLS() the flat map of
	 * name => file name, while that method expects items with `url` and
	 * `metadata` keys. Each item is a string, so the `metadata` / `url`
	 * lookups fail: on PHP 7.4 every lookup raises an "Illegal string
	 * offset" warning, on PHP 8 the first one throws a TypeError. The method
	 * is unusable for its only caller. The correct behaviour is to pass
	 * `[ side => ['url' => <main file URL>, 'metadata' => $files] ]`.
	 *
	 * How to recognise the fix: addData() returns an array of relative URLs
	 * without errors and the assertions on the failure below fail.
	 */
	public function test_metadata_add_data_fails_on_the_flat_file_map_pinned_for_deferred_fix() {
		$meta     = array(
			'file'  => '2026/10/photo.jpg',
			'sizes' => array( 'thumbnail' => array( 'file' => 'photo-150x150.jpg' ) ),
		);
		$metadata = new MetaData( 'search' );

		list( $result, $errors ) = $this->capture(
			function () use ( $metadata, $meta ) {
				return $metadata->addData( $meta );
			}
		);

		$this->assertSame( $meta, $this->readProperty( $metadata, 'data' ), 'Sentinel: the metadata is stored before the failure.' );
		if ( PHP_VERSION_ID >= 80000 ) {
			$this->assertInstanceOf( TypeError::class, $result, 'Pinned: the string offset lookup throws.' );
			$this->assertStringContainsString( 'on string', $result->getMessage() );
		} else {
			$this->assertNotEmpty( $errors, 'Pinned: the string offset lookups raise warnings.' );
			$this->assertStringContainsString( 'Illegal string offset', $errors[0][1] );
			$this->assertIsArray( $result );
		}
	}

	/**
	 * MetaData::findNearestSize() is a copy of the Replacer method that
	 * reads $source_metadata, $target_metadata and $target_url, none of which
	 * exist on MetaData, and nothing calls it. Called directly it reads an
	 * undefined property and returns false for any input. The correct
	 * behaviour is to remove it or rewrite it against data this class has.
	 *
	 * How to recognise the fix: the method is gone (the ReflectionMethod
	 * below throws) or it no longer reads an undefined property.
	 */
	public function test_metadata_find_nearest_size_reads_properties_it_does_not_have_pinned_for_deferred_fix() {
		$metadata = new MetaData( 'search' );
		$method   = new ReflectionMethod( MetaData::class, 'findNearestSize' );
		$method->setAccessible( true );

		list( $result, $errors ) = $this->capture(
			function () use ( $method, $metadata ) {
				return $method->invoke( $metadata, 'thumbnail' );
			}
		);

		$this->assertFalse( $result, 'Pinned: always false.' );
		$undefined = array_values(
			array_filter(
				$errors,
				function ( $error ) {
					return false !== strpos( $error[1], 'Undefined property' );
				}
			)
		);
		$this->assertCount( 1, $undefined, 'Pinned: reads an undefined property.' );
		$this->assertStringContainsString( '$target_url', $undefined[0][1] );
	}

	/**
	 * Classes/Utils.php declares an empty class with no namespace, so it is
	 * the global `Utils` and collides with any other global class of that
	 * name; the namespace rewrite of the build tool does not reach it. The
	 * file is checked with the tokenizer: requiring it would declare the
	 * global class for the rest of the process. The correct behaviour is a
	 * namespaced class, or no file at all.
	 *
	 * How to recognise the fix: the file declares a namespace (or is gone)
	 * and the pinned assertions below fail.
	 */
	public function test_utils_is_an_empty_class_in_the_global_namespace_pinned_for_deferred_fix() {
		$file = dirname( dirname( __DIR__ ) ) . '/build/shortpixel/replacer2/src/Classes/Utils.php';
		$this->assertFileExists( $file );
		$tokens = token_get_all( file_get_contents( $file ) );

		$namespaces = 0;
		$classes    = array();
		$count      = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) ) {
				continue;
			}
			if ( T_NAMESPACE === $tokens[ $i ][0] ) {
				$namespaces++;
			}
			if ( T_CLASS === $tokens[ $i ][0] && isset( $tokens[ $i + 2 ] ) && is_array( $tokens[ $i + 2 ] ) ) {
				$classes[] = $tokens[ $i + 2 ][1];
			}
		}

		$this->assertSame( array( 'Utils' ), $classes, 'Sentinel: the file declares Utils.' );
		$this->assertSame( 0, $namespaces, 'Pinned: no namespace, so the class is the global Utils.' );
		$this->assertFalse( class_exists( 'EnableMediaReplace\Replacer\Classes\Utils' ), 'Pinned: the namespaced name does not resolve.' );
	}
}
