<?php
/**
 * Tests for EnableMediaReplace\FileSystem\Model\File\FileModel
 * (build/shortpixel/filesystem/src/Model/File/FileModel.php).
 *
 * The module has few docblocks, so the code is the specification. Every
 * test works in its own sandbox directory under the system temp dir,
 * removed in tear_down(). Filters, the public static $TRUSTED_MODE flag and
 * the hooks a test adds are restored after each test.
 *
 * Not covered here: fileIsRestricted() and its `emr/file/basedir_check`
 * filter only run when open_basedir is set, and open_basedir can only be
 * tightened at runtime, never cleared, so it cannot be enabled for one test
 * without breaking every later one.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\FileSystem\Model\File\FileModel;
use EnableMediaReplace\FileSystem\Model\File\DirectoryModel;

class FileModelTest extends WP_UnitTestCase {

	/**
	 * Sandbox directory for the current test.
	 *
	 * @var string
	 */
	private $sandbox;

	/**
	 * Filters added by the test, as array( hook, callback, priority ).
	 *
	 * @var array
	 */
	private $added_filters = array();

	/**
	 * Value of FileModel::$TRUSTED_MODE before the test.
	 *
	 * @var bool
	 */
	private $saved_trusted_mode;

	public function set_up() {
		parent::set_up();
		$this->sandbox = sys_get_temp_dir() . '/emr-filemodel-' . uniqid();
		mkdir( $this->sandbox, 0755, true );
		$this->saved_trusted_mode = FileModel::$TRUSTED_MODE;
	}

	public function tear_down() {
		FileModel::$TRUSTED_MODE = $this->saved_trusted_mode;
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		if ( is_dir( $this->sandbox ) ) {
			$this->recursive_rmdir( $this->sandbox );
		}
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

	private function add_test_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->added_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Writes a text file under the sandbox and returns its absolute path.
	 */
	private function make_file( $name, $contents = 'hello world' ) {
		$path = $this->sandbox . '/' . $name;
		file_put_contents( $path, $contents );
		return $path;
	}

	/**
	 * Writes a minimal valid JPEG under the sandbox and returns its path.
	 */
	private function make_jpeg( $name = 'photo.jpg' ) {
		$path = $this->sandbox . '/' . $name;
		file_put_contents( $path, hex2bin( 'FFD8FFE000104A46494600010100000100010000FFD9' ) );
		return $path;
	}

	private function invoke_private( FileModel $file, $method, array $args = array() ) {
		$ref = new ReflectionMethod( FileModel::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $file, $args );
	}

	private function get_prop( FileModel $file, $name ) {
		$ref = new ReflectionProperty( FileModel::class, $name );
		$ref->setAccessible( true );
		return $ref->getValue( $file );
	}

	/*
	 * Constructor and basic accessors.
	 */

	public function test_constructor_stores_raw_path_untouched() {
		$path = $this->sandbox . '/some file.jpg';
		$file = new FileModel( $path );
		$this->assertSame( $path, $file->getRawFullPath() );
	}

	/**
	 * A null path stops the constructor early: the full path stays null
	 * (not an empty string) and the raw path is null too.
	 */
	public function test_constructor_null_path_yields_null_fullpath() {
		$file = new FileModel( null );
		$this->assertNull( $file->getRawFullPath() );
		$this->assertSame( '', (string) $file );
		$ref = new ReflectionProperty( FileModel::class, 'fullpath' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->getValue( $file ) );
	}

	public function test_toString_returns_the_fullpath() {
		$path = $this->make_file( 'note.txt' );
		$file = new FileModel( $path );
		$this->assertSame( $path, (string) $file );
	}

	public function test_constructor_trims_surrounding_whitespace() {
		$path   = $this->make_file( 'trim-me.txt' );
		$padded = "  {$path}  \n";
		$file   = new FileModel( $padded );

		$this->assertSame( $path, $file->getFullPath() );
		$this->assertSame( $padded, $file->getRawFullPath() );
	}

	/*
	 * Filename, basename and extension.
	 */

	public function test_getFileName_returns_basename_with_extension() {
		$file = new FileModel( $this->make_file( 'photo.png' ) );
		$this->assertSame( 'photo.png', $file->getFileName() );
	}

	public function test_getFileBase_returns_basename_without_extension() {
		$file = new FileModel( $this->make_file( 'photo.png' ) );
		$this->assertSame( 'photo', $file->getFileBase() );
	}

	public function test_getExtension_returns_lowercase_extension() {
		$file = new FileModel( $this->make_file( 'PICTURE.JPG' ) );
		$this->assertSame( 'jpg', $file->getExtension() );
	}

	public function test_getExtension_only_returns_final_extension_for_double_ext() {
		$file = new FileModel( $this->make_file( 'archive.jpg.webp' ) );
		$this->assertSame( 'webp', $file->getExtension() );
		$this->assertSame( 'archive.jpg', $file->getFileBase() );
	}

	/**
	 * mb_pathinfo() always fills the extension key, so a file without an
	 * extension gets an empty string, not null.
	 */
	public function test_getExtension_returns_empty_string_for_no_extension() {
		$file = new FileModel( $this->make_file( 'no-ext' ) );
		$this->assertSame( '', $file->getExtension() );
		$this->assertSame( 'no-ext', $file->getFileName() );
	}

	/*
	 * mb_pathinfo (private).
	 */

	public function test_mb_pathinfo_returns_full_component_array() {
		$file = new FileModel( $this->sandbox . '/dummy.txt' );
		$out  = $this->invoke_private( $file, 'mb_pathinfo', array( '/tmp/nested/photo.jpg' ) );

		$this->assertSame( '/tmp/nested', $out['dirname'] );
		$this->assertSame( 'photo.jpg', $out['basename'] );
		$this->assertSame( 'photo', $out['filename'] );
		$this->assertSame( 'jpg', $out['extension'] );
	}

	public function test_mb_pathinfo_handles_multibyte_filenames() {
		$file = new FileModel( $this->sandbox . '/dummy.txt' );
		$out  = $this->invoke_private( $file, 'mb_pathinfo', array( '/tmp/фото.png' ) );

		$this->assertSame( 'фото.png', $out['basename'] );
		$this->assertSame( 'фото', $out['filename'] );
		$this->assertSame( 'png', $out['extension'] );
	}

	public function test_mb_pathinfo_option_selects_single_field() {
		$file = new FileModel( $this->sandbox . '/dummy.txt' );
		$this->assertSame( 'jpg', $this->invoke_private( $file, 'mb_pathinfo', array( '/tmp/photo.jpg', PATHINFO_EXTENSION ) ) );
		$this->assertSame( '/tmp', $this->invoke_private( $file, 'mb_pathinfo', array( '/tmp/photo.jpg', 'dirname' ) ) );
		$this->assertSame( 'photo', $this->invoke_private( $file, 'mb_pathinfo', array( '/tmp/photo.jpg', PATHINFO_FILENAME ) ) );
	}

	public function test_mb_pathinfo_no_extension_returns_empty_string() {
		$file = new FileModel( $this->sandbox . '/dummy.txt' );
		$out  = $this->invoke_private( $file, 'mb_pathinfo', array( '/tmp/no-ext' ) );
		$this->assertSame( '', $out['extension'] );
	}

	/*
	 * Existence and file/directory distinction.
	 */

	public function test_exists_true_for_present_file() {
		$file = new FileModel( $this->make_file( 'here.txt' ) );
		$this->assertTrue( $file->exists() );
	}

	public function test_exists_false_for_missing_path() {
		$file = new FileModel( $this->sandbox . '/never-created.txt' );
		$this->assertFalse( $file->exists() );
	}

	public function test_exists_false_for_a_directory() {
		$file = new FileModel( $this->sandbox );
		$this->assertFalse( $file->exists() );
		$this->assertFalse( $file->is_file() );
	}

	public function test_exists_caches_result_across_calls() {
		$path = $this->make_file( 'cached.txt' );
		$file = new FileModel( $path );

		$this->assertTrue( $file->exists() );
		unlink( $path );
		$this->assertTrue( $file->exists() );
		$this->assertFalse( $file->exists( true ), 'forceCheck bypasses the cached value.' );

		file_put_contents( $path, 'back' );
		$file->resetStatus();
		$this->assertTrue( $file->exists() );
	}

	public function test_exists_passes_through_the_exists_filters() {
		$path = $this->make_file( 'filtered.txt' );
		$seen = array();
		$cb   = function ( $exists, $fullpath, $model ) use ( &$seen ) {
			$seen[] = array( $exists, $fullpath, $model );
			return false;
		};
		$this->add_test_filter( 'emr/file/exists', $cb, 10, 3 );

		$file = new FileModel( $path );
		$this->assertFalse( $file->exists() );
		$this->assertCount( 1, $seen );
		$this->assertTrue( $seen[0][0] );
		$this->assertSame( $path, $seen[0][1] );
		$this->assertSame( $file, $seen[0][2] );
	}

	public function test_is_readable_reflects_file_state() {
		$file = new FileModel( $this->make_file( 'readable.txt' ) );
		$this->assertTrue( $file->is_readable() );
		$missing = new FileModel( $this->sandbox . '/missing.txt' );
		$this->assertFalse( $missing->is_readable() );
	}

	public function test_is_writable_true_for_present_writable_file() {
		$file = new FileModel( $this->make_file( 'writable.txt' ) );
		$this->assertTrue( $file->is_writable() );
	}

	/**
	 * For a missing file is_writable() probes by creating and deleting the
	 * file, and leaves nothing behind.
	 */
	public function test_is_writable_for_missing_file_probes_without_leaving_the_file() {
		$path = $this->sandbox . '/probe.txt';
		$file = new FileModel( $path );

		$this->assertTrue( $file->is_writable() );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_is_writable_false_for_file_in_missing_directory() {
		$file = new FileModel( $this->sandbox . '/no-dir/file.txt' );
		$this->assertFalse( $file->is_writable() );
	}

	public function test_is_directory_writable_reflects_the_parent_directory() {
		$file = new FileModel( $this->make_file( 'dirw.txt' ) );
		$this->assertTrue( $file->is_directory_writable() );

		$orphan = new FileModel( $this->sandbox . '/no-dir/file.txt' );
		$this->assertFalse( $orphan->is_directory_writable() );
	}

	public function test_is_virtual_defaults_to_false_for_local_paths() {
		$file = new FileModel( $this->make_file( 'local.txt' ) );
		$this->assertFalse( $file->is_virtual() );
	}

	/**
	 * A path that does not exist on disk still counts as a file (anything can
	 * become one); '.' and '..' never do.
	 */
	public function test_is_file_true_for_missing_path_false_for_dot_entries() {
		$this->assertTrue( ( new FileModel( $this->sandbox . '/future.txt' ) )->is_file() );
		$this->assertFalse( ( new FileModel( $this->sandbox . '/..' ) )->is_file() );
		$this->assertFalse( ( new FileModel( $this->sandbox . '/.' ) )->is_file() );
	}

	/*
	 * Size, mtime, permissions.
	 */

	public function test_getFileSize_returns_byte_count() {
		$file = new FileModel( $this->make_file( 'sized.txt', 'abcdef' ) );
		$this->assertSame( 6, $file->getFileSize() );
	}

	public function test_getFileSize_returns_zero_for_missing_file() {
		$file = new FileModel( $this->sandbox . '/absent.txt' );
		$this->assertSame( 0, $file->getFileSize() );
	}

	public function test_getFileSize_is_cached_until_resetStatus() {
		$path = $this->make_file( 'grow.txt', 'abc' );
		$file = new FileModel( $path );
		$this->assertSame( 3, $file->getFileSize() );

		file_put_contents( $path, 'abcdefgh' );
		clearstatcache();
		$this->assertSame( 3, $file->getFileSize() );

		$file->resetStatus();
		$this->assertSame( 8, $file->getFileSize() );
	}

	public function test_getModified_returns_a_recent_timestamp() {
		$file = new FileModel( $this->make_file( 'stamped.txt' ) );
		$ts   = $file->getModified();
		$this->assertIsInt( $ts );
		$this->assertGreaterThan( time() - 10, $ts );
	}

	public function test_getPermissions_returns_masked_bits() {
		$path = $this->make_file( 'perm.txt' );
		chmod( $path, 0644 );
		$file = new FileModel( $path );

		$this->assertSame( 0644, $file->getPermissions() );
	}

	public function test_getPermissions_is_cached_until_resetStatus() {
		$path = $this->make_file( 'perm-cache.txt' );
		chmod( $path, 0644 );
		$file = new FileModel( $path );
		$this->assertSame( 0644, $file->getPermissions() );

		chmod( $path, 0600 );
		clearstatcache();
		$this->assertSame( 0644, $file->getPermissions() );

		$file->resetStatus();
		$this->assertSame( 0600, $file->getPermissions() );
	}

	public function test_setPermissions_changes_the_underlying_mode() {
		$path = $this->make_file( 'chmod.txt' );
		$file = new FileModel( $path );

		$file->setPermissions( 0600 );
		clearstatcache( true, $path );

		$this->assertSame( 0600, fileperms( $path ) & 0777 );
	}

	/*
	 * Contents and MIME.
	 */

	public function test_getContents_returns_the_file_body() {
		$file = new FileModel( $this->make_file( 'body.txt', 'payload' ) );
		$this->assertSame( 'payload', $file->getContents() );
	}

	public function test_getMime_returns_image_jpeg_for_valid_jpeg() {
		$file = new FileModel( $this->make_jpeg() );
		$this->assertSame( 'image/jpeg', $file->getMime() );
	}

	public function test_getMime_returns_image_png_for_the_png_fixture() {
		$path = $this->sandbox . '/fixture.png';
		copy( dirname( __DIR__ ) . '/fixtures/codeispoetry.png', $path );
		$file = new FileModel( $path );
		$this->assertSame( 'image/png', $file->getMime() );
	}

	/**
	 * Non-images fall back to wp_check_filetype_and_ext().
	 */
	public function test_getMime_returns_application_pdf_for_a_pdf() {
		$path = $this->make_file( 'doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" );
		$file = new FileModel( $path );
		$this->assertSame( 'application/pdf', $file->getMime() );
	}

	public function test_getMime_returns_false_for_missing_file() {
		$file = new FileModel( $this->sandbox . '/ghost.jpg' );
		$this->assertFalse( $file->getMime() );
	}

	/*
	 * Write operations: create, append, delete, copy, move.
	 */

	public function test_create_touches_new_file_when_directory_exists() {
		$path = $this->sandbox . '/created.txt';
		$file = new FileModel( $path );
		$this->assertFalse( $file->exists() );

		$this->assertTrue( $file->create() );
		$this->assertFileExists( $path );
		$this->assertTrue( $file->exists() );
	}

	public function test_create_is_noop_when_file_already_exists() {
		$path = $this->make_file( 'already.txt', 'keep' );
		$file = new FileModel( $path );

		$this->assertFalse( $file->create() );
		$this->assertSame( 'keep', file_get_contents( $path ) );
	}

	public function test_create_returns_false_when_directory_is_missing() {
		$path = $this->sandbox . '/missing-dir/created.txt';
		$file = new FileModel( $path );

		$this->assertFalse( $file->create() );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_append_writes_content_to_existing_file() {
		$path = $this->make_file( 'log.txt', 'line1' );
		$file = new FileModel( $path );

		$this->assertTrue( $file->append( "\nline2" ) );
		$this->assertSame( "line1\nline2", file_get_contents( $path ) );
	}

	public function test_append_creates_file_when_missing_then_writes() {
		$path = $this->sandbox . '/fresh.log';
		$file = new FileModel( $path );

		$this->assertTrue( $file->append( 'first' ) );
		$this->assertSame( 'first', file_get_contents( $path ) );
	}

	public function test_append_returns_false_when_directory_is_missing() {
		$path = $this->sandbox . '/missing-dir/fresh.log';
		$file = new FileModel( $path );

		$this->assertFalse( $file->append( 'first' ) );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_delete_removes_existing_file() {
		$path = $this->make_file( 'todelete.txt' );
		$file = new FileModel( $path );

		$this->assertTrue( $file->delete() );
		$this->assertFileDoesNotExist( $path );
		$this->assertFalse( $file->exists() );
	}

	/**
	 * Deleting a file that is not there counts as success: the result is
	 * "the file is gone".
	 */
	public function test_delete_returns_true_for_missing_file() {
		$file = new FileModel( $this->sandbox . '/never.txt' );
		$this->assertTrue( $file->delete() );
	}

	public function test_delete_goes_through_wp_delete_file_filter() {
		$path = $this->make_file( 'hooked.txt' );
		$seen = array();
		$cb   = function ( $file ) use ( &$seen ) {
			$seen[] = $file;
			return $file;
		};
		$this->add_test_filter( 'wp_delete_file', $cb );

		$this->assertTrue( ( new FileModel( $path ) )->delete() );
		$this->assertSame( array( $path ), $seen );
	}

	public function test_copy_duplicates_contents_to_destination() {
		$source_path = $this->make_file( 'source.txt', 'copyme' );
		$dest_path   = $this->sandbox . '/dest.txt';

		$source = new FileModel( $source_path );
		$dest   = new FileModel( $dest_path );

		$this->assertTrue( $source->copy( $dest ) );
		$this->assertSame( 'copyme', file_get_contents( $dest_path ) );
		$this->assertFileExists( $source_path );
		$this->assertTrue( $dest->exists() );
		$this->assertSame( 'dest.txt', $dest->getFileName() );
	}

	public function test_copy_returns_false_when_source_is_missing() {
		$source = new FileModel( $this->sandbox . '/absent-source.txt' );
		$dest   = new FileModel( $this->sandbox . '/dest.txt' );

		$this->assertFalse( $source->copy( $dest ) );
		$this->assertFileDoesNotExist( $this->sandbox . '/dest.txt' );
	}

	/**
	 * Unlike SPIO's FileModel, EMR's copies zero-byte files.
	 */
	public function test_copy_copies_zero_byte_source() {
		$source_path = $this->sandbox . '/empty.txt';
		touch( $source_path );
		$dest_path = $this->sandbox . '/dest.txt';

		$this->assertTrue( ( new FileModel( $source_path ) )->copy( new FileModel( $dest_path ) ) );
		$this->assertFileExists( $dest_path );
		$this->assertSame( 0, filesize( $dest_path ) );
	}

	public function test_copy_returns_false_for_empty_paths() {
		$source = new FileModel( $this->make_file( 'src.txt' ) );
		$this->assertFalse( $source->copy( new FileModel( '' ) ) );
	}

	public function test_copy_returns_false_when_destination_directory_is_missing() {
		$source    = new FileModel( $this->make_file( 'src.txt' ) );
		$dest_path = $this->sandbox . '/no-dir/dest.txt';

		$this->assertFalse( $source->copy( new FileModel( $dest_path ) ) );
		$this->assertFileDoesNotExist( $dest_path );
	}

	/**
	 * copy() fires emr/filesystem/addfile with one array argument:
	 * destination path, destination model, source model, and whether the
	 * destination is new. It fires on failure too.
	 */
	public function test_copy_fires_addfile_action_with_new_flag() {
		$calls = array();
		$cb    = function ( $args ) use ( &$calls ) {
			$calls[] = $args;
		};
		$this->add_test_filter( 'emr/filesystem/addfile', $cb );

		$source    = new FileModel( $this->make_file( 'src.txt' ) );
		$dest_path = $this->sandbox . '/dst.txt';
		$dest      = new FileModel( $dest_path );
		$source->copy( $dest );
		$source->copy( new FileModel( $dest_path ) );
		$source->copy( new FileModel( $this->sandbox . '/no-dir/x.txt' ) );

		$this->assertCount( 3, $calls );
		$this->assertSame( $dest_path, $calls[0][0] );
		$this->assertSame( $dest, $calls[0][1] );
		$this->assertSame( $source, $calls[0][2] );
		$this->assertTrue( $calls[0][3] );
		$this->assertFalse( $calls[1][3], 'Second copy overwrites an existing destination.' );
		$this->assertSame( $this->sandbox . '/no-dir/x.txt', $calls[2][0] );
	}

	/**
	 * copy() does not carry the source permissions over: a new destination
	 * gets the default mode from the umask, an existing one keeps its own.
	 * The destination's cached permissions are cleared, so getPermissions()
	 * reads the real mode afterwards.
	 */
	public function test_copy_does_not_copy_source_permissions() {
		$source_path = $this->make_file( 'private.txt', 'secret' );
		chmod( $source_path, 0600 );
		$source = new FileModel( $source_path );

		$new_dest = new FileModel( $this->sandbox . '/new.txt' );
		$this->assertTrue( $source->copy( $new_dest ) );
		$this->assertSame( 0666 & ~umask(), $new_dest->getPermissions() );

		$existing_path = $this->make_file( 'existing.txt', 'old' );
		chmod( $existing_path, 0640 );
		$existing = new FileModel( $existing_path );
		$this->assertSame( 0640, $existing->getPermissions() );
		$this->assertTrue( $source->copy( $existing ) );
		$this->assertSame( 0640, $existing->getPermissions() );
		$this->assertSame( 'secret', file_get_contents( $existing_path ) );
		$this->assertSame( 0600, $source->getPermissions() );
	}

	public function test_move_deletes_source_after_copying() {
		$source_path = $this->make_file( 'to-move.txt', 'moved' );
		$dest_path   = $this->sandbox . '/moved.txt';

		$source = new FileModel( $source_path );
		$dest   = new FileModel( $dest_path );

		$this->assertTrue( $source->move( $dest ) );
		$this->assertSame( 'moved', file_get_contents( $dest_path ) );
		$this->assertFileDoesNotExist( $source_path );
		$this->assertFalse( $source->exists() );
		$this->assertTrue( $dest->exists() );
	}

	public function test_move_returns_false_when_source_is_missing() {
		$source = new FileModel( $this->sandbox . '/gone.txt' );
		$this->assertFalse( $source->move( new FileModel( $this->sandbox . '/dest.txt' ) ) );
		$this->assertFileDoesNotExist( $this->sandbox . '/dest.txt' );
	}

	/*
	 * getFileDir.
	 */

	public function test_getFileDir_returns_directory_model_of_parent() {
		$file = new FileModel( $this->make_file( 'child.txt' ) );
		$dir  = $file->getFileDir();

		$this->assertInstanceOf( DirectoryModel::class, $dir );
		$this->assertTrue( $dir->exists() );
		$this->assertSame( $this->sandbox . '/', $dir->getPath() );
		$this->assertSame( $dir, $file->getFileDir(), 'The directory model is built once.' );
	}

	public function test_getFileDir_for_missing_file_points_at_the_would_be_parent() {
		$file = new FileModel( $this->sandbox . '/sub/missing.txt' );
		$dir  = $file->getFileDir();

		$this->assertInstanceOf( DirectoryModel::class, $dir );
		$this->assertSame( $this->sandbox . '/sub/', $dir->getPath() );
		$this->assertFalse( $dir->exists() );
	}

	public function test_getFileDir_returns_null_for_empty_path() {
		$file = new FileModel( '' );
		$this->assertNull( $file->getFileDir() );
	}

	/*
	 * resetStatus.
	 */

	public function test_resetStatus_clears_cached_status_fields() {
		$path = $this->make_file( 'reset.txt' );
		$file = new FileModel( $path );

		$file->exists();
		$file->is_writable();
		$file->is_readable();
		$file->is_directory_writable();
		$file->getFileSize();
		$file->getPermissions();

		$file->resetStatus();

		foreach ( array( 'exists', 'is_writable', 'is_directory_writable', 'is_readable', 'is_file', 'filesize', 'permissions', 'is_virtual', 'is_restricted' ) as $prop ) {
			$this->assertNull( $this->get_prop( $file, $prop ), "Expected {$prop} to be null after resetStatus()." );
		}
		$this->assertTrue( $file->exists() );
	}

	/**
	 * resetStatus() also nulls is_virtual, and is_virtual() turns null into
	 * false, so a remote file stops being virtual after a status reset (copy()
	 * and move() reset the destination). Being remote is a property of the
	 * path, not a status that a file operation changes, so the reset must
	 * keep it (or recompute it from the path).
	 *
	 * Where: FileModel::resetStatus().
	 *
	 * How to recognise the fix: is_virtual() still returns true after
	 * resetStatus() on a remote URL, so the second assertTrue/assertFalse
	 * pair below flips.
	 */
	public function test_resetStatus_forgets_that_a_remote_file_is_virtual_pinned_for_deferred_fix() {
		$file = new FileModel( 'https://cdn.remote.test/media/a.jpg' );
		$this->assertTrue( $file->is_virtual() );
		$this->assertFalse( $file->is_writable() );

		$file->resetStatus();

		$this->assertFalse( $file->is_virtual() );
		$this->assertSame( false, $this->get_prop( $file, 'is_virtual' ) );
	}

	/*
	 * URLs, relative paths and virtual files.
	 */

	/**
	 * A URL on the site's own host is translated to the local path by
	 * getFullPath(); getRawFullPath() keeps the URL.
	 */
	public function test_local_url_resolves_to_local_path() {
		$url  = home_url( '/wp-content/uploads/emr-local-url.jpg' );
		$file = new FileModel( $url );

		$this->assertFalse( $file->is_virtual() );
		$this->assertSame( untrailingslashit( ABSPATH ) . '/wp-content/uploads/emr-local-url.jpg', $file->getFullPath() );
		$this->assertSame( $url, $file->getRawFullPath() );
	}

	public function test_relative_path_is_resolved_against_abspath() {
		$file = new FileModel( 'wp-content/uploads/emr-relative.jpg' );

		$this->assertSame( ABSPATH . 'wp-content/uploads/emr-relative.jpg', $file->getFullPath() );
		$this->assertSame( 'wp-content/uploads/emr-relative.jpg', $file->getRawFullPath() );
	}

	public function test_full_path_passes_through_processFilePath_filter() {
		$seen = array();
		$cb   = function ( $path, $original ) use ( &$seen ) {
			$seen[] = array( $path, $original );
			return $path;
		};
		$this->add_test_filter( 'emr/filesystem/processFilePath', $cb, 10, 2 );

		$file = new FileModel( 'wp-content/uploads/emr-filtered.jpg' );
		$file->getFullPath();

		$this->assertSame( array( array( ABSPATH . 'wp-content/uploads/emr-filtered.jpg', 'wp-content/uploads/emr-filtered.jpg' ) ), $seen );
	}

	/**
	 * A URL on another host is virtual: it does not exist, cannot be written,
	 * reports size -1 and no MIME type, and keeps the URL as its full path.
	 */
	public function test_remote_url_is_virtual_and_not_existing() {
		$url  = 'https://cdn.remote.test/media/a.jpg';
		$file = new FileModel( $url );

		$this->assertTrue( $file->is_virtual() );
		$this->assertFalse( $file->exists() );
		$this->assertFalse( $file->is_readable() );
		$this->assertFalse( $file->is_writable() );
		$this->assertFalse( $file->is_directory_writable() );
		$this->assertSame( $url, $file->getFullPath() );
		$this->assertSame( 'a.jpg', $file->getFileName() );
		$this->assertSame( -1, $file->getFileSize() );
		$this->assertFalse( $file->getMime() );
	}

	public function test_remote_url_urltopath_filter_receives_url_and_raw_path() {
		$seen = array();
		$cb   = function ( $result, $url, $raw ) use ( &$seen ) {
			$seen[] = array( $result, $url, $raw );
			return $result;
		};
		$this->add_test_filter( 'emr/image/urltopath', $cb, 10, 3 );

		new FileModel( 'https://cdn.remote.test/b.jpg' );

		$this->assertCount( 1, $seen );
		$this->assertFalse( $seen[0][0] );
		$this->assertSame( '//cdn.remote.test/b.jpg', $seen[0][1], 'The scheme is stripped before the filter.' );
		$this->assertSame( 'https://cdn.remote.test/b.jpg', $seen[0][2] );
	}

	public function test_remote_url_marked_stateless_is_existing_and_writable() {
		$cb = function () {
			return FileModel::$VIRTUAL_STATELESS;
		};
		$this->add_test_filter( 'emr/image/urltopath', $cb );

		$file = new FileModel( 'https://cdn.remote.test/c.jpg' );

		$this->assertTrue( $file->is_virtual() );
		$this->assertTrue( $file->exists() );
		$this->assertTrue( $file->is_readable() );
		$this->assertTrue( $file->is_writable() );
		$this->assertTrue( $file->is_directory_writable() );
		$this->assertSame( FileModel::$VIRTUAL_STATELESS, $this->get_prop( $file, 'virtual_status' ) );
	}

	public function test_remote_url_marked_remote_exists_but_is_not_writable() {
		$cb = function () {
			return FileModel::$VIRTUAL_REMOTE;
		};
		$this->add_test_filter( 'emr/image/urltopath', $cb );

		$file = new FileModel( 'https://cdn.remote.test/d.jpg' );

		$this->assertTrue( $file->exists() );
		$this->assertFalse( $file->is_writable() );
		$this->assertSame( FileModel::$VIRTUAL_REMOTE, $this->get_prop( $file, 'virtual_status' ) );
		$this->assertSame( -1, $file->getFileSize() );
	}

	/*
	 * Trusted mode.
	 */

	/**
	 * With the public static $TRUSTED_MODE flag on, a new model trusts the
	 * path blindly: it exists, is writable, and its MIME is derived from the
	 * extension even when nothing is on disk.
	 */
	public function test_trusted_mode_skips_filesystem_checks() {
		FileModel::$TRUSTED_MODE = true;
		$path = $this->sandbox . '/not-there.jpg';
		$file = new FileModel( $path );

		$this->assertTrue( $file->exists() );
		$this->assertTrue( $file->is_writable() );
		$this->assertTrue( $file->is_readable() );
		$this->assertSame( 'image/jpg', $this->get_prop( $file, 'mime' ) );
		$this->assertSame( 0, $file->getFileSize() );
		$this->assertSame( $path, $file->getFullPath() );
	}

	/*
	 * __debuginfo.
	 */

	public function test_debuginfo_exposes_key_fields() {
		$file = new FileModel( $this->make_file( 'debug.txt' ) );
		$file->getFileName();
		$info = $file->__debuginfo();

		$this->assertIsArray( $info );
		$this->assertSame( array( 'fullpath', 'filename', 'filebase', 'exists', 'is_writable', 'is_readable', 'is_virtual' ), array_keys( $info ) );
		$this->assertTrue( $info['exists'] );
		$this->assertSame( 'debug.txt', $info['filename'] );
	}
}
