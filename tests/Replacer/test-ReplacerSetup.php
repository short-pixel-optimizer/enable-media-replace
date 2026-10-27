<?php
/**
 * Tests for EnableMediaReplace\Replacer\Classes\Setup
 * (build/shortpixel/replacer2/src/Classes/Setup.php) and for the accessors
 * Replacer::Setup(), Replacer::Finder() and Replacer::Updater() that hand
 * out the objects of the newer search/replace API.
 *
 * Setup is a fluent collector: forSearch() / forReplace() pick a side, then
 * MetaData() / URL() lazily create one data object per side and type.
 * Nothing here touches the database or fires hooks.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Replacer;
use EnableMediaReplace\Replacer\Classes\Setup;
use EnableMediaReplace\Replacer\Classes\MetaData;
use EnableMediaReplace\Replacer\Classes\Url;
use EnableMediaReplace\Replacer\Classes\Finder;
use EnableMediaReplace\Replacer\Classes\Updater;

/**
 * Classes\Setup.
 */
class ReplacerSetupTest extends WP_UnitTestCase {

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

	public function test_get_instance_returns_a_new_instance_on_every_call() {
		$first  = Setup::getInstance();
		$second = Setup::getInstance();

		$this->assertInstanceOf( Setup::class, $first );
		$this->assertInstanceOf( Setup::class, $second );
		$this->assertNotSame( $first, $second );
	}

	public function test_replacer_accessors_hand_out_new_api_objects() {
		$replacer = new Replacer();

		$this->assertInstanceOf( Setup::class, $replacer->Setup() );
		$this->assertNotSame( $replacer->Setup(), $replacer->Setup(), 'Every call starts with empty data' );
		$this->assertInstanceOf( Finder::class, $replacer->Finder( array( 'base_url' => '/x' ) ) );
		$this->assertInstanceOf( Updater::class, $replacer->Updater() );
		$this->assertNotSame( $replacer->Finder(), $replacer->Finder() );
	}

	public function test_side_selectors_return_the_same_setup_for_chaining() {
		$setup = Setup::getInstance();

		$this->assertSame( $setup, $setup->forSearch() );
		$this->assertSame( 'search', $this->readProperty( $setup, 'new_action' ) );
		$this->assertSame( $setup, $setup->forReplace() );
		$this->assertSame( 'replace', $this->readProperty( $setup, 'new_action' ) );
	}

	public function test_metadata_is_created_once_per_side_with_its_side_as_type() {
		$setup = Setup::getInstance();

		$search       = $setup->forSearch()->MetaData();
		$search_again = $setup->forSearch()->MetaData();
		$replace      = $setup->forReplace()->MetaData();

		$this->assertInstanceOf( MetaData::class, $search );
		$this->assertSame( $search, $search_again, 'Created lazily, then reused' );
		$this->assertNotSame( $search, $replace );
		$this->assertSame( 'search', $this->readProperty( $search, 'type' ) );
		$this->assertSame( 'replace', $this->readProperty( $replace, 'type' ) );
		$this->assertSame( $search, $this->readProperty( $setup, 'searchMetaData' ) );
		$this->assertSame( $replace, $this->readProperty( $setup, 'replaceMetaData' ) );
	}

	public function test_url_is_created_once_per_side_and_separate_from_metadata() {
		$setup = Setup::getInstance();

		$search  = $setup->forSearch()->URL();
		$replace = $setup->forReplace()->URL();

		$this->assertInstanceOf( Url::class, $search );
		$this->assertSame( $search, $setup->forSearch()->URL() );
		$this->assertNotSame( $search, $replace );
		$this->assertSame( 'replace', $this->readProperty( $replace, 'type' ) );
		$this->assertSame( $search, $this->readProperty( $setup, 'searchURLData' ) );
		$this->assertNull( $this->readProperty( $setup, 'searchMetaData' ), 'URL() does not create a MetaData object' );
	}

	/**
	 * Without forSearch() / forReplace() the property name resolves to the
	 * undeclared `MetaData`, as the docblock warns: reading it raises an
	 * undefined-property notice (PHP 7.4) or warning (PHP 8). The object is
	 * still created, with no side.
	 */
	public function test_metadata_without_a_side_reads_an_undeclared_property() {
		$setup  = Setup::getInstance();
		$errors = array();
		set_error_handler(
			function ( $errno, $errstr ) use ( &$errors ) {
				$errors[] = array( $errno, $errstr );
				return true;
			}
		);
		try {
			$metadata = $setup->MetaData();
		} finally {
			restore_error_handler();
		}

		$this->assertInstanceOf( MetaData::class, $metadata );
		$this->assertNull( $this->readProperty( $metadata, 'type' ) );
		$undefined = array_values(
			array_filter(
				$errors,
				function ( $error ) {
					return false !== strpos( $error[1], 'Undefined property' );
				}
			)
		);
		$this->assertCount( 1, $undefined );
		$this->assertSame( PHP_VERSION_ID >= 80000 ? E_WARNING : E_NOTICE, $undefined[0][0] );
		$this->assertStringContainsString( '$MetaData', $undefined[0][1] );
	}

	public function test_validate_data_is_an_empty_placeholder() {
		$setup = Setup::getInstance()->forSearch();
		$setup->URL()->addData( 'http://example.org/a.jpg' );

		$this->assertNull( $setup->validateData() );
	}
}
