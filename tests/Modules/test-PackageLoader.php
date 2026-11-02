<?php
/**
 * Tests for EnableMediaReplace\Build\PackageLoader
 * (build/shortpixel/PackageLoader.php), the generated autoloader that
 * build/shortpixel/autoload.php registers for the bundled modules.
 *
 * The module has no docblocks, so the code is the specification. The
 * loader registers one anonymous closure per PSR-4 prefix; the tests find
 * those closures in spl_autoload_functions() through their bound static
 * variables and call them directly. Loaders a test registers itself are
 * unregistered in tear_down(); classes cannot be unloaded, so every class
 * a test defines gets a per-run unique namespace.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Build\PackageLoader;

class PackageLoaderTest extends WP_UnitTestCase {

	/**
	 * Sandbox directory for the current test.
	 *
	 * @var string
	 */
	private $sandbox;

	/**
	 * Autoloaders registered by the test.
	 *
	 * @var callable[]
	 */
	private $registered = array();

	public function set_up() {
		parent::set_up();
		$this->sandbox = sys_get_temp_dir() . '/emr-pkgloader-' . uniqid();
		mkdir( $this->sandbox, 0755, true );
	}

	public function tear_down() {
		foreach ( $this->registered as $loader ) {
			spl_autoload_unregister( $loader );
		}
		$this->registered = array();
		$this->recursive_rmdir( $this->sandbox );
		parent::tear_down();
	}

	private function recursive_rmdir( $dir ) {
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $name ) {
			$entry = $dir . '/' . $name;
			if ( is_dir( $entry ) ) {
				$this->recursive_rmdir( $entry );
			} else {
				unlink( $entry );
			}
		}
		rmdir( $dir );
	}

	private function build_dir() {
		return dirname( dirname( __DIR__ ) ) . '/build/shortpixel';
	}

	/**
	 * The closures the bundled autoload.php registered, keyed by namespace.
	 *
	 * @return array namespace => array( closure, static variables )
	 */
	private function module_loaders() {
		$found = array();
		foreach ( spl_autoload_functions() as $function ) {
			if ( ! $function instanceof Closure ) {
				continue;
			}
			$ref  = new ReflectionFunction( $function );
			$vars = $ref->getStaticVariables();
			if ( isset( $vars['namespace'], $vars['dir'] ) && $this->build_dir() === $vars['dir'] ) {
				$found[ $vars['namespace'] ] = array( $function, $vars );
			}
		}
		return $found;
	}

	/**
	 * Calls every module loader with the class name; returns the files that
	 * got included during the calls.
	 */
	private function run_module_loaders( $class ) {
		$before = get_included_files();
		foreach ( $this->module_loaders() as $loader ) {
			$this->assertNull( $loader[0]( $class ) );
		}
		return array_values( array_diff( get_included_files(), $before ) );
	}

	/**
	 * Registers the loaders a fresh PackageLoader adds during the callback,
	 * so tear_down() can remove them.
	 */
	private function track_registrations( $callback ) {
		$before = spl_autoload_functions();
		$callback();
		foreach ( spl_autoload_functions() as $function ) {
			if ( ! in_array( $function, $before, true ) ) {
				$this->registered[] = $function;
			}
		}
	}

	/**
	 * The `use` targets of a PHP file, read with the tokenizer (works for
	 * the PHP 7.4 and the PHP 8 name tokens alike).
	 */
	private function use_statements( $file ) {
		$tokens = token_get_all( file_get_contents( $file ) );
		$uses   = array();
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_USE !== $tokens[ $i ][0] ) {
				continue;
			}
			$name = '';
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$token = $tokens[ $j ];
				if ( ! is_array( $token ) || T_AS === $token[0] ) {
					break;
				}
				if ( T_WHITESPACE !== $token[0] ) {
					$name .= $token[1];
				}
			}
			$uses[] = $name;
		}
		return $uses;
	}

	/*
	 * The bundled registration.
	 */

	/**
	 * The order of the PSR-4 map follows the build tool's directory walk
	 * and changes between rebuilds, so only the set of modules is asserted.
	 */
	public function test_autoload_registers_one_psr4_loader_per_module() {
		$loaders = $this->module_loaders();

		$this->assertEqualsCanonicalizing(
			array( 'EnableMediaReplace\FileSystem', 'EnableMediaReplace\Notices', 'EnableMediaReplace\ShortPixelLogger', 'EnableMediaReplace\Replacer' ),
			array_keys( $loaders )
		);
		$this->assertCount( 4, $loaders );
		$this->assertSame( array( 'filesystem/src' ), $loaders['EnableMediaReplace\FileSystem'][1]['classpaths'] );
		$this->assertSame( array( 'replacer2/src' ), $loaders['EnableMediaReplace\Replacer'][1]['classpaths'] );
		$this->assertTrue( $loaders['EnableMediaReplace\Notices'][1]['psr4'] );
	}

	public function test_module_loader_includes_a_class_not_loaded_yet() {
		$candidates = array(
			'EnableMediaReplace\Replacer\Classes\Url',
			'EnableMediaReplace\Replacer\Classes\Setup',
			'EnableMediaReplace\Replacer\Classes\Finder',
			'EnableMediaReplace\Replacer\Classes\MetaData',
			'EnableMediaReplace\Replacer\Libraries\Unserialize\DisallowedClassesSubstitutor',
		);
		$class = null;
		foreach ( $candidates as $candidate ) {
			if ( ! class_exists( $candidate, false ) ) {
				$class = $candidate;
				break;
			}
		}
		if ( null === $class ) {
			$this->markTestSkipped( 'Every candidate replacer class is already loaded in this process, so there is nothing left to autoload.' );
		}

		$included = $this->run_module_loaders( $class );

		$this->assertTrue( class_exists( $class, false ) );
		$expected = $this->build_dir() . '/replacer2/src/' . str_replace( '\\', '/', substr( $class, strlen( 'EnableMediaReplace\Replacer\\' ) ) ) . '.php';
		$this->assertContains( $expected, $included );
	}

	public function test_module_loaders_ignore_classes_outside_their_prefixes() {
		$this->assertSame( array(), $this->run_module_loaders( 'EnableMediaReplace\NotAModule\Thing' ) );
		$this->assertSame( array(), $this->run_module_loaders( 'Some\Vendor\Thing' ) );
		$this->assertFalse( class_exists( 'Some\Vendor\Thing', false ) );
	}

	public function test_module_loader_skips_a_missing_file_within_its_prefix() {
		$this->assertSame( array(), $this->run_module_loaders( 'EnableMediaReplace\Replacer\Classes\NoSuchClass' ) );
		$this->assertFalse( class_exists( 'EnableMediaReplace\Replacer\Classes\NoSuchClass', false ) );
	}

	/**
	 * The three filesystem module files import the logger as
	 * `EnableMediaReplace\ShortpixelLogger\ShortPixelLogger` (lower-case
	 * "p" in the namespace), while the logger lives in
	 * `EnableMediaReplace\ShortPixelLogger`. PHP class names are
	 * case-insensitive, so the import works only because the logger class
	 * is already loaded by the time a filesystem class logs: the
	 * PackageLoader prefix match (preg_match without the i flag) is
	 * case-sensitive and cannot load that spelling. The first Log:: call
	 * from the filesystem module in a request where nothing else loaded the
	 * logger first is a fatal "Class not found". The imports should use the
	 * real namespace spelling.
	 *
	 * Where: build/shortpixel/filesystem/src/Model/File/FileModel.php,
	 * DirectoryModel.php and Controller/FileSystemController.php (generated
	 * from the filesystem module source).
	 *
	 * How to recognise the fix: the use statements read
	 * `EnableMediaReplace\ShortPixelLogger\ShortPixelLogger`.
	 */
	public function test_filesystem_module_imports_the_logger_with_wrong_case_pinned_for_deferred_fix() {
		$wrong = 'EnableMediaReplace\ShortpixelLogger\ShortPixelLogger';
		$right = 'EnableMediaReplace\ShortPixelLogger\ShortPixelLogger';
		$files = array(
			'filesystem/src/Model/File/FileModel.php',
			'filesystem/src/Model/File/DirectoryModel.php',
			'filesystem/src/Controller/FileSystemController.php',
		);

		foreach ( $files as $file ) {
			$uses = $this->use_statements( $this->build_dir() . '/' . $file );
			$this->assertContains( $wrong, $uses, $file );
			$this->assertNotContains( $right, $uses, $file );
		}

		// The loader cannot resolve the lower-case spelling.
		$this->assertSame( array(), $this->run_module_loaders( $wrong ) );

		// It works only because the class is already loaded under its real
		// name, and class names are case-insensitive.
		$this->assertTrue( class_exists( $right, false ) );
		$this->assertTrue( class_exists( $wrong, false ) );
		$this->assertSame( $right, ( new ReflectionClass( $wrong ) )->getName() );
	}

	/*
	 * PackageLoader on its own composer file.
	 */

	private function write_package( $namespace, $class, array $autoload ) {
		mkdir( $this->sandbox . '/src/Sub', 0755, true );
		file_put_contents( $this->sandbox . '/src/Sub/' . $class . '.php', "<?php\nnamespace {$namespace}\\Sub;\nclass {$class} {}\n" );
		file_put_contents( $this->sandbox . '/composer.json', wp_json_encode( array( 'autoload' => $autoload ) ) );
	}

	public function test_load_registers_psr4_prefix_and_includes_files() {
		$ns = 'EmrPkgTest' . str_replace( '.', '', uniqid( '', true ) );
		$this->write_package( $ns, 'Widget', array() );
		file_put_contents( $this->sandbox . '/boot.php', "<?php\n\$GLOBALS['emr_pkg_boot_" . $ns . "'] = true;\n" );
		file_put_contents(
			$this->sandbox . '/composer.json',
			wp_json_encode(
				array(
					'autoload' => array(
						'psr-4' => array( $ns => 'src' ),
						'files' => array( 'boot.php', 'missing.php' ),
					),
				)
			)
		);

		$loader = new PackageLoader();
		$this->track_registrations(
			function () use ( $loader ) {
				$loader->load( $this->sandbox );
			}
		);

		$this->assertSame( $this->sandbox, $loader->dir );
		$this->assertCount( 1, $this->registered );
		$this->assertTrue( $GLOBALS[ 'emr_pkg_boot_' . $ns ] );
		unset( $GLOBALS[ 'emr_pkg_boot_' . $ns ] );
		$this->assertTrue( class_exists( $ns . '\Sub\Widget' ) );
	}

	public function test_load_with_psr0_keeps_the_namespace_in_the_path() {
		$ns = 'EmrPkgZero' . str_replace( '.', '', uniqid( '', true ) );
		mkdir( $this->sandbox . '/lib/' . $ns, 0755, true );
		file_put_contents( $this->sandbox . '/lib/' . $ns . '/Gadget.php', "<?php\nnamespace {$ns};\nclass Gadget {}\n" );
		file_put_contents( $this->sandbox . '/composer.json', wp_json_encode( array( 'autoload' => array( 'psr-0' => array( $ns => array( 'lib' ) ) ) ) ) );

		$loader = new PackageLoader();
		$this->track_registrations(
			function () use ( $loader ) {
				$loader->load( $this->sandbox );
			}
		);

		$this->assertCount( 1, $this->registered );
		$this->assertTrue( class_exists( $ns . '\Gadget' ) );
	}

	public function test_setComposerFile_takes_precedence_over_the_directory_file() {
		$other = $this->sandbox . '/other.json';
		file_put_contents( $other, wp_json_encode( array( 'name' => 'from-set' ) ) );
		file_put_contents( $this->sandbox . '/composer.json', wp_json_encode( array( 'name' => 'from-dir' ) ) );

		$loader = new PackageLoader();
		$loader->setComposerFile( $other );
		$loader->dir = $this->sandbox;

		$this->assertSame( array( 'name' => 'from-set' ), $loader->getComposerFile() );
	}

	public function test_getComposerFile_reads_composer_json_from_dir_once() {
		file_put_contents( $this->sandbox . '/composer.json', wp_json_encode( array( 'name' => 'first' ) ) );
		$loader      = new PackageLoader();
		$loader->dir = $this->sandbox;

		$this->assertSame( array( 'name' => 'first' ), $loader->getComposerFile() );
		file_put_contents( $this->sandbox . '/composer.json', wp_json_encode( array( 'name' => 'second' ) ) );
		$this->assertSame( array( 'name' => 'first' ), $loader->getComposerFile(), 'The parsed file is cached.' );
	}

	public function test_load_without_autoload_section_registers_nothing() {
		file_put_contents( $this->sandbox . '/composer.json', wp_json_encode( array( 'name' => 'empty' ) ) );
		$loader = new PackageLoader();
		$this->track_registrations(
			function () use ( $loader ) {
				$loader->load( $this->sandbox );
			}
		);
		$this->assertSame( array(), $this->registered );
	}
}
