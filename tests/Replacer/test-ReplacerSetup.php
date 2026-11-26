<?php
/**
 * Tests for EnableMediaReplace\Replacer\Classes\Setup
 * (build/shortpixel/replacer2/src/Classes/Setup.php) and for the way Replacer2::replace()
 * relies on it.
 *
 * Setup holds one Data object per side (search(), replace()) and validates
 * the pairing of both into the arrays replace() runs on. replace() always validates itself.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Replacer2;
use EnableMediaReplace\Replacer\Classes\Setup;
use EnableMediaReplace\Replacer\Classes\Data;
use EnableMediaReplace\Replacer\Classes\Finder;
use EnableMediaReplace\Replacer\Classes\Updater;

/**
 * Classes\Setup.
 */
class ReplacerSetupTest extends WP_UnitTestCase {

	/**
	 * Fills both sides of a Setup with an image replaced by a smaller one.
	 *
	 * @param Setup $setup Setup to fill.
	 * @return Setup
	 */
	private function fill( Setup $setup ) {
		$setup->search()->setUrl( 'http://example.org/wp-content/uploads/2026/10/old.jpg' );
		$setup->search()->setMetaData(
			array(
				'file'  => '2026/10/old.jpg',
				'width' => 1200,
				'sizes' => array(
					'thumbnail' => array( 'file' => 'old-150x150.jpg', 'width' => 150 ),
					'medium'    => array( 'file' => 'old-300x200.jpg', 'width' => 300 ),
				),
			)
		);
		$setup->replace()->setUrl( 'http://example.org/wp-content/uploads/2026/11/new.jpg' );
		$setup->replace()->setMetaData(
			array(
				'file'  => '2026/11/new.jpg',
				'width' => 1000,
				'sizes' => array(
					'thumbnail' => array( 'file' => 'new-150x150.jpg', 'width' => 150 ),
					'medium'    => array( 'file' => 'new-300x200.jpg', 'width' => 300 ),
				),
			)
		);
		return $setup;
	}

	public function test_replacer_hands_out_one_setup_and_new_finders_and_updaters() {
		$replacer = new Replacer2( 'test' );

		$this->assertInstanceOf( Setup::class, $replacer->Setup() );
		$this->assertSame( $replacer->Setup(), $replacer->Setup(), 'Data set through Setup() is what replace() uses' );
		$this->assertInstanceOf( Finder::class, $replacer->Finder( array( 'base_url' => '/x' ) ) );
		$this->assertInstanceOf( Updater::class, $replacer->Updater() );
		$this->assertNotSame( $replacer->Finder(), $replacer->Finder() );
	}

	public function test_each_side_is_one_data_object() {
		$setup = new Setup();

		$this->assertInstanceOf( Data::class, $setup->search() );
		$this->assertInstanceOf( Data::class, $setup->replace() );
		$this->assertSame( $setup->search(), $setup->search() );
		$this->assertNotSame( $setup->search(), $setup->replace() );
	}

	public function test_validate_data_fails_without_main_urls_and_reports_each_side() {
		$setup = new Setup();
		$setup->search()->setMetaData( array( 'file' => '2026/10/old.jpg' ) );

		$this->assertFalse( $setup->validateData() );
		$this->assertCount( 2, $setup->getValidationErrors() );
		$this->assertStringContainsString( 'search', $setup->getValidationErrors()[0] );
		$this->assertStringContainsString( 'replace', $setup->getValidationErrors()[1] );
		$this->assertFalse( $setup->getValidatedData() );
	}

	public function test_validate_data_combines_base_metadata_and_extra_pairs() {
		$setup = $this->fill( new Setup() );
		$setup->search()->addUrls( array( 'webp' => '/wp-content/uploads/2026/10/old.webp' ) );
		$setup->replace()->addUrls( array( 'webp' => '/wp-content/uploads/2026/11/new.webp' ) );

		$this->assertTrue( $setup->validateData() );
		$this->assertSame( array(), $setup->getValidationErrors() );
		$this->assertSame(
			array(
				'search'   => array(
					'base'      => '/wp-content/uploads/2026/10/old.jpg',
					'file'      => '/wp-content/uploads/2026/10/old.jpg',
					'thumbnail' => '/wp-content/uploads/2026/10/old-150x150.jpg',
					'medium'    => '/wp-content/uploads/2026/10/old-300x200.jpg',
					'webp'      => '/wp-content/uploads/2026/10/old.webp',
				),
				'replace'  => array(
					'base'      => '/wp-content/uploads/2026/11/new.jpg',
					'file'      => '/wp-content/uploads/2026/11/new.jpg',
					'thumbnail' => '/wp-content/uploads/2026/11/new-150x150.jpg',
					'medium'    => '/wp-content/uploads/2026/11/new-300x200.jpg',
					'webp'      => '/wp-content/uploads/2026/11/new.webp',
				),
				'base_url' => '/wp-content/uploads/2026/10/old',
			),
			$setup->getValidatedData()
		);
	}

	public function test_validate_data_without_target_sizes_uses_the_target_main_file_without_warnings() {
		$setup = new Setup();
		$setup->search()->setUrl( 'http://example.org/uploads/old.jpg' );
		$setup->search()->setMetaData(
			array(
				'file'  => 'old.jpg',
				'width' => 800,
				'sizes' => array( 'thumbnail' => array( 'file' => 'old-150x150.jpg', 'width' => 150 ) ),
			)
		);
		$setup->replace()->setUrl( 'http://example.org/uploads/new.jpg' );
		$setup->replace()->setMetaData( array( 'file' => 'new.jpg', 'width' => 100 ) );

		$errors = array();
		set_error_handler(
			function ( $errno, $errstr ) use ( &$errors ) {
				$errors[] = $errstr;
				return true;
			}
		);
		try {
			$validated = $setup->validateData();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $errors, 'A target without sizes raises no warning' );
		$this->assertTrue( $validated );
		$this->assertSame( '/uploads/new.jpg', $setup->getValidatedData()['replace']['thumbnail'] );
	}

	public function test_a_failed_validation_drops_the_earlier_result() {
		$setup = $this->fill( new Setup() );
		$this->assertTrue( $setup->validateData(), 'precondition' );
		$this->assertIsArray( $setup->getValidatedData(), 'precondition' );

		$setup->replace()->setMetaData( 'not metadata' );

		$this->assertFalse( $setup->validateData() );
		$this->assertFalse( $setup->getValidatedData() );
	}

	public function test_thumbnails_only_leaves_the_main_file_out_without_changing_the_sides() {
		$setup = $this->fill( new Setup() );

		$this->assertTrue( $setup->validateData( array( 'thumbnails_only' => true ) ) );
		$data = $setup->getValidatedData();

		$this->assertSame( array( 'thumbnail', 'medium' ), array_keys( $data['search'] ) );
		$this->assertSame( array( 'thumbnail', 'medium' ), array_keys( $data['replace'] ) );
		$this->assertSame( '/wp-content/uploads/2026/10/old', $data['base_url'] );
		$this->assertArrayHasKey( 'base', $setup->search()->getUrls(), 'The side keeps its main file' );
	}

	/**
	 * In thumbnails-only mode the `file` pair stays when the source's
	 * metadata file differs from its main file (a `-scaled` image): a
	 * replace with a smaller image drops the `-scaled` file, so its
	 * references are rewritten to the target's main file. `base` is still
	 * left out.
	 */
	public function test_thumbnails_only_keeps_a_scaled_metadata_file_that_differs_from_the_main_file() {
		$setup = $this->fill( new Setup() );
		$setup->search()->setMetaData(
			array(
				'file'  => '2026/10/old-scaled.jpg',
				'width' => 2560,
				'sizes' => array(
					'thumbnail' => array( 'file' => 'old-150x150.jpg', 'width' => 150 ),
					'medium'    => array( 'file' => 'old-300x200.jpg', 'width' => 300 ),
				),
			)
		);
		$this->assertSame( '/wp-content/uploads/2026/10/old-scaled.jpg', $setup->search()->getUrls()['file'], 'Sentinel: the metadata file is the -scaled file.' );
		$this->assertNotSame( $setup->search()->getUrls()['file'], $setup->search()->getPath(), 'Sentinel: it differs from the main file.' );

		$this->assertTrue( $setup->validateData( array( 'thumbnails_only' => true ) ) );
		$data = $setup->getValidatedData();

		$this->assertSame( array( 'file', 'thumbnail', 'medium' ), array_keys( $data['search'] ) );
		$this->assertSame( array_keys( $data['search'] ), array_keys( $data['replace'] ) );
		$this->assertSame( '/wp-content/uploads/2026/10/old-scaled.jpg', $data['search']['file'] );
		$this->assertSame( '/wp-content/uploads/2026/11/new.jpg', $data['replace']['file'] );
	}

	public function test_errors_of_an_earlier_run_are_cleared() {
		$setup = new Setup();
		$this->assertFalse( $setup->validateData(), 'precondition: fails without URLs' );
		$this->assertNotEmpty( $setup->getValidationErrors() );

		$this->fill( $setup );

		$this->assertTrue( $setup->validateData() );
		$this->assertSame( array(), $setup->getValidationErrors() );
	}

	public function test_invalid_metadata_blocks_validation() {
		$setup = $this->fill( new Setup() );
		$this->assertFalse( $setup->replace()->setMetaData( 'not metadata' ), 'precondition: refused' );

		$this->assertFalse( $setup->validateData() );
		$this->assertCount( 1, $setup->getValidationErrors() );
		$this->assertStringContainsString( 'replace', $setup->getValidationErrors()[0] );
	}

	public function test_a_directory_as_search_base_fails_validation() {
		$dir   = untrailingslashit( sys_get_temp_dir() );
		$this->assertDirectoryExists( $dir, 'precondition' );
		$setup = $this->fill( new Setup() );
		$setup->search()->setUrl( 'http://example.org' . $dir . '.jpg' );

		$this->assertFalse( $setup->validateData() );
		$this->assertCount( 1, $setup->getValidationErrors() );
		$this->assertStringContainsString( 'directory', $setup->getValidationErrors()[0] );
	}

	public function test_replace_without_source_or_target_returns_false() {
		$replacer = new Replacer2( 'test' );
		$replacer->Setup()->search()->setUrl( 'http://example.org/uploads/old.jpg' );

		$this->assertFalse( $replacer->replace() );
	}

	public function test_replace_validates_itself_when_the_caller_did_not() {
		$replacer = new Replacer2( 'test' );
		$this->fill( $replacer->Setup() );
		$this->assertFalse( $replacer->Setup()->getValidatedData(), 'precondition: not validated' );

		$seen = array();
		add_action(
			'emr/replacer/replace_urls',
			function ( $search, $replace, $base ) use ( &$seen ) {
				$seen[] = array( $search, $replace, $base );
			},
			10,
			3
		);

		$result = $replacer->replace();

		$this->assertIsInt( $result );
		$this->assertCount( 1, $seen );
		$this->assertCount( 4, $seen[0][0] );
		$this->assertSame( array_keys( $seen[0][0] ), array_keys( $seen[0][1] ) );
		$this->assertSame( '/wp-content/uploads/2026/10/old', $seen[0][2] );
	}

	public function test_replace_validates_again_with_its_own_arguments() {
		$replacer = new Replacer2( 'test' );
		$this->fill( $replacer->Setup() );
		$this->assertTrue( $replacer->Setup()->validateData(), 'precondition: validated without thumbnails_only' );

		$seen = array();
		add_action(
			'emr/replacer/replace_urls',
			function ( $search ) use ( &$seen ) {
				$seen[] = array_keys( $search );
			}
		);

		$replacer->replace( array( 'thumbnails_only' => true ) );

		$this->assertSame( array( array( 'thumbnail', 'medium' ) ), $seen );
		$this->assertArrayHasKey( 'base', $replacer->Setup()->search()->getUrls(), 'replace() does not change the sides' );
	}

	public function test_replace_returns_the_errors_and_runs_nothing_when_validation_fails() {
		$replacer = new Replacer2( 'test' );
		$this->fill( $replacer->Setup() );
		$replacer->Setup()->search()->setMetaData( 42 );

		$fired = 0;
		add_action(
			'emr/replacer/replace_urls',
			function () use ( &$fired ) {
				$fired++;
			}
		);

		$result = $replacer->replace();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 0, $fired );
	}

	public function test_incomplete_or_unbalanced_custom_runs_are_skipped() {
		self::factory()->post->create( array( 'post_content' => 'nothing to replace here' ) );

		$replacer = new Replacer2( 'test' );
		$this->fill( $replacer->Setup() );

		add_filter(
			'emr/replacer/custom_replace_query',
			function ( $runs ) {
				$runs['empty_base'] = array( 'base_url' => '', 'search_urls' => array( 'a' ), 'replace_urls' => array( 'b' ) );
				$runs['unbalanced'] = array( 'base_url' => 'nothing', 'search_urls' => array( 'a', 'c' ), 'replace_urls' => array( 'b' ) );
				$runs['no_arrays']  = array( 'base_url' => 'nothing' );
				$runs['valid']      = array( 'base_url' => 'nothing to replace', 'search_urls' => array( 'zzz' ), 'replace_urls' => array( 'yyy' ) );
				return $runs;
			}
		);

		$result = $replacer->replace();

		$this->assertSame( 1, $result, 'Only the valid custom run finds the post; the empty base would have matched far more' );
	}
}
