<?php
/**
 * Tests for EnableMediaReplace\FileSystem\Model\File\DirectoryModel
 * (build/shortpixel/filesystem/src/Model/File/DirectoryModel.php).
 *
 * The module has few docblocks, so the code is the specification. Every
 * test works in its own sandbox directory under the system temp dir (or,
 * for recursiveDelete(), under the uploads base directory, which that
 * method requires), removed in tear_down().
 *
 * Not covered here: fileIsRestricted() and its `emr/file/basedir_check`
 * filter only run when open_basedir is set, which cannot be undone at
 * runtime. The container runs as root, so a directory that is really not
 * writable cannot be produced; the not-writable branch of check() is
 * reached by seeding the cached status by reflection.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\FileSystem\Model\File\DirectoryModel;
use EnableMediaReplace\FileSystem\Model\File\FileModel;

class DirectoryModelTest extends WP_UnitTestCase {

	/**
	 * Sandbox directory for the current test.
	 *
	 * @var string
	 */
	private $sandbox;

	/**
	 * Extra directories created by the test, removed in tear_down().
	 *
	 * @var string[]
	 */
	private $extra_dirs = array();

	public function set_up() {
		parent::set_up();
		$this->sandbox = sys_get_temp_dir() . '/emr-dirmodel-' . uniqid();
		mkdir( $this->sandbox, 0755, true );
	}

	public function tear_down() {
		foreach ( array_merge( array( $this->sandbox ), $this->extra_dirs ) as $dir ) {
			if ( is_dir( $dir ) ) {
				$this->recursive_rmdir( $dir );
			}
		}
		$this->extra_dirs = array();
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

	private function make_file( $rel_path, $contents = 'x' ) {
		$path = $this->sandbox . '/' . $rel_path;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
		file_put_contents( $path, $contents );
		return $path;
	}

	private function make_dir( $rel_path ) {
		$path = $this->sandbox . '/' . $rel_path;
		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0755, true );
		}
		return $path;
	}

	private function invoke_private( DirectoryModel $dir, $method, array $args = array() ) {
		$ref = new ReflectionMethod( DirectoryModel::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $dir, $args );
	}

	private function set_prop( DirectoryModel $dir, $name, $value ) {
		$ref = new ReflectionProperty( DirectoryModel::class, $name );
		$ref->setAccessible( true );
		$ref->setValue( $dir, $value );
	}

	/**
	 * Runs the callback and returns the PHP error the harness turned into an
	 * exception, or null when none was raised.
	 */
	private function capture_php_error( $callback ) {
		try {
			$callback();
		} catch ( \PHPUnit\Framework\Error\Error $e ) {
			return $e;
		}
		return null;
	}

	private function filter_args( array $args ) {
		return array_merge(
			array(
				'include_files' => null,
				'exclude_files' => null,
				'date_newer'    => null,
			),
			$args
		);
	}

	/*
	 * Constructor and path accessors.
	 */

	public function test_getPath_returns_path_with_trailing_slash() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertSame( trailingslashit( $this->sandbox ), $dir->getPath() );
	}

	public function test_getPath_keeps_a_single_trailing_slash_when_given_one() {
		$dir = new DirectoryModel( $this->sandbox . '/' );
		$this->assertSame( $this->sandbox . '/', $dir->getPath() );
	}

	public function test_toString_returns_the_path() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertSame( trailingslashit( $this->sandbox ), (string) $dir );
	}

	public function test_getName_returns_directory_basename() {
		$dir = new DirectoryModel( $this->make_dir( 'my-sub' ) );
		$this->assertSame( 'my-sub', $dir->getName() );
	}

	public function test_getName_handles_non_latin_directory_names() {
		$dir = new DirectoryModel( $this->make_dir( 'фото' ) );
		$this->assertSame( 'фото', $dir->getName() );
	}

	/**
	 * An empty path is not "no directory": realpath('') resolves to the
	 * process's current working directory, so DirectoryModel('') points at
	 * whatever directory PHP runs in, and exists() is true. Any later
	 * operation (getFiles(), check(), delete()) then acts on that directory.
	 * The correct behaviour is an empty path that does not exist.
	 *
	 * Where: DirectoryModel::__construct(), the realpath() fallback.
	 *
	 * How to recognise the fix: getPath() returns '' (or the model reports
	 * exists() false) instead of the working directory.
	 */
	public function test_empty_path_resolves_to_the_working_directory_pinned_for_deferred_fix() {
		$cwd = getcwd();
		$this->assertNotSame( '', $cwd );

		$dir = new DirectoryModel( '' );

		$this->assertSame( trailingslashit( $cwd ), $dir->getPath() );
		$this->assertTrue( $dir->exists() );
	}

	public function test_file_path_input_strips_to_parent_directory() {
		$dir = new DirectoryModel( $this->make_file( 'note.txt' ) );
		$this->assertSame( trailingslashit( $this->sandbox ), $dir->getPath() );
	}

	public function test_missing_file_path_with_extension_strips_to_parent() {
		$dir = new DirectoryModel( $this->sandbox . '/sub/missing.jpg' );
		$this->assertSame( $this->sandbox . '/sub/', $dir->getPath() );
		$this->assertFalse( $dir->exists() );
	}

	public function test_existing_file_without_extension_strips_to_parent() {
		$dir = new DirectoryModel( $this->make_file( 'README' ) );
		$this->assertSame( $this->sandbox . '/', $dir->getPath() );
	}

	public function test_dot_segments_are_resolved_through_realpath() {
		$this->make_dir( 'a/b' );
		$dir = new DirectoryModel( $this->sandbox . '/a/b/../b/..' );
		$this->assertSame( $this->sandbox . '/a/b/../b/../', $dir->getPath(), 'An existing directory path is kept as given.' );

		$missing = new DirectoryModel( $this->sandbox . '/a/../a/not-there' );
		$this->assertSame( $this->sandbox . '/a/../a/not-there/', $missing->getPath(), 'realpath() fails on missing paths, so they stay unresolved.' );
	}

	/*
	 * Existence probes.
	 */

	public function test_exists_true_for_present_directory() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertTrue( $dir->exists() );
	}

	public function test_exists_false_for_missing_directory() {
		$dir = new DirectoryModel( $this->sandbox . '/never-created' );
		$this->assertFalse( $dir->exists() );
	}

	public function test_exists_is_cached() {
		$path = $this->make_dir( 'cached' );
		$dir  = new DirectoryModel( $path );
		$this->assertTrue( $dir->exists() );
		rmdir( $path );
		$this->assertTrue( $dir->exists() );
	}

	public function test_is_readable_reflects_state() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertTrue( $dir->is_readable() );
		$this->assertFalse( ( new DirectoryModel( $this->sandbox . '/nope' ) )->is_readable() );
	}

	public function test_is_writable_reflects_state() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertTrue( $dir->is_writable() );
		$this->assertFalse( ( new DirectoryModel( $this->sandbox . '/nope' ) )->is_writable() );
	}

	public function test_is_virtual_false_for_local_paths() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertFalse( $dir->is_virtual() );
	}

	/**
	 * The URL branch of the constructor sets is_virtual to true, and the
	 * restriction check right after it resets is_virtual to false in its
	 * else branch for every non-restricted path. A URL directory therefore
	 * reports is_virtual() false, while exists() stays true because the URL
	 * branch assumed it. The URL is kept as the path (without the file part).
	 *
	 * Where: DirectoryModel::__construct(), the `else { $this->is_virtual =
	 * false; }` after the fileIsRestricted() check.
	 *
	 * How to recognise the fix: is_virtual() returns true for a URL.
	 */
	public function test_url_directory_is_not_reported_as_virtual_pinned_for_deferred_fix() {
		$dir = new DirectoryModel( 'https://cdn.remote.test/media/photo.jpg' );

		$this->assertSame( 'https://cdn.remote.test/media/', $dir->getPath() );
		$this->assertSame( 'media', $dir->getName() );
		$this->assertTrue( $dir->exists() );
		$this->assertTrue( $dir->is_readable() );
		$this->assertFalse( $dir->is_virtual() );
	}

	/*
	 * Metadata: getModified, getPermissions, getPermissionRecursive.
	 */

	public function test_getModified_returns_a_recent_timestamp() {
		$dir = new DirectoryModel( $this->sandbox );
		$ts  = $dir->getModified();
		$this->assertIsInt( $ts );
		$this->assertGreaterThan( time() - 30, $ts );
	}

	public function test_getPermissions_returns_false_for_missing_directory() {
		$dir = new DirectoryModel( $this->sandbox . '/absent' );
		$this->assertFalse( $dir->getPermissions() );
	}

	/**
	 * Unlike FileModel::getPermissions(), the directory version returns the
	 * full fileperms() value including the file type bits, not the 0777 mask.
	 */
	public function test_getPermissions_returns_full_mode_including_type_bits() {
		chmod( $this->sandbox, 0755 );
		clearstatcache();
		$dir   = new DirectoryModel( $this->sandbox );
		$perms = $dir->getPermissions();

		$this->assertIsInt( $perms );
		$this->assertSame( fileperms( $this->sandbox ), $perms );
		$this->assertSame( 0040000, $perms & 0170000, 'Directory type bit is included.' );
		$this->assertSame( 0755, $perms & 0777 );
	}

	public function test_getPermissionRecursive_returns_ancestor_permissions_when_self_missing() {
		$dir   = new DirectoryModel( $this->sandbox . '/nested/deep/never' );
		$perms = $dir->getPermissionRecursive();
		$this->assertIsInt( $perms );
		$this->assertSame( fileperms( $this->sandbox ), $perms );
	}

	/*
	 * Parent and subfolder relationships.
	 */

	public function test_getParent_returns_directory_model_for_the_parent_dir() {
		$dir    = new DirectoryModel( $this->make_dir( 'child' ) );
		$parent = $dir->getParent();

		$this->assertInstanceOf( DirectoryModel::class, $parent );
		$this->assertSame( trailingslashit( $this->sandbox ), $parent->getPath() );
	}

	public function test_getParent_returns_false_for_the_root() {
		$dir = new DirectoryModel( '/' );
		$this->assertSame( '/', $dir->getPath() );
		$this->assertFalse( $dir->getParent() );
	}

	public function test_isSubFolderOf_true_when_path_starts_with_parent_path() {
		$sub = new DirectoryModel( $this->make_dir( 'child/grand' ) );
		$this->assertTrue( $sub->isSubFolderOf( new DirectoryModel( $this->sandbox ) ) );
	}

	public function test_isSubFolderOf_false_for_identical_paths() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertFalse( $dir->isSubFolderOf( $dir ) );
	}

	public function test_isSubFolderOf_false_for_unrelated_paths() {
		$sub     = new DirectoryModel( $this->make_dir( 'unrelated' ) );
		$another = new DirectoryModel( $this->sandbox . '-different' );
		$this->assertFalse( $sub->isSubFolderOf( $another ) );
	}

	public function test_isSubFolderOf_false_for_sibling_sharing_a_name_prefix() {
		$abc = new DirectoryModel( $this->make_dir( 'abc' ) );
		$ab  = new DirectoryModel( $this->make_dir( 'ab' ) );
		$this->assertFalse( $abc->isSubFolderOf( $ab ) );
	}

	/*
	 * getRelativePath.
	 */

	public function test_getRelativePath_for_uploads_dir_is_relative_to_install() {
		$upload = wp_upload_dir( null, false );
		$dir    = new DirectoryModel( $upload['basedir'] );
		$this->assertSame( 'wp-content/uploads/', $dir->getRelativePath() );
	}

	public function test_getRelativePath_returns_false_outside_the_install() {
		$dir = new DirectoryModel( $this->make_dir( 'outside' ) );
		$this->assertFalse( $dir->getRelativePath() );
	}

	public function test_getRelativePath_falls_back_to_wp_content_segment() {
		$dir = new DirectoryModel( '/srv/elsewhere/wp-content/uploads/2020/01' );
		$this->assertSame( 'wp-content/uploads/2020/01/', $dir->getRelativePath() );
	}

	/*
	 * getFiles, getSubDirectories, getFolderSize.
	 */

	public function test_getFiles_returns_file_models_for_direct_children() {
		$this->make_file( 'a.txt' );
		$this->make_file( 'b.txt' );
		$this->make_dir( 'ignored-subdir' );
		$this->make_file( 'ignored-subdir/c.txt' );

		$files = ( new DirectoryModel( $this->sandbox ) )->getFiles();

		$this->assertIsArray( $files );
		$this->assertCount( 2, $files );
		foreach ( $files as $file ) {
			$this->assertInstanceOf( FileModel::class, $file );
		}
	}

	public function test_getFiles_returns_empty_array_for_empty_directory() {
		$this->assertSame( array(), ( new DirectoryModel( $this->sandbox ) )->getFiles() );
	}

	public function test_getFiles_returns_false_for_missing_directory() {
		$dir = new DirectoryModel( $this->sandbox . '/never' );
		$this->assertFalse( $dir->getFiles() );
	}

	public function test_getFiles_respects_include_files_filter() {
		$this->make_file( 'keep-me.txt' );
		$this->make_file( 'skip-me.txt' );

		$files = ( new DirectoryModel( $this->sandbox ) )->getFiles( array( 'include_files' => array( 'KEEP' ) ) );

		$this->assertCount( 1, $files );
		$this->assertStringContainsString( 'keep-me.txt', $files[0]->getRawFullPath() );
	}

	public function test_getFiles_respects_exclude_files_filter() {
		$this->make_file( 'keep-me.txt' );
		$this->make_file( 'drop-me.txt' );

		$files = ( new DirectoryModel( $this->sandbox ) )->getFiles( array( 'exclude_files' => array( 'drop' ) ) );

		$this->assertCount( 1, $files );
		$this->assertStringContainsString( 'keep-me.txt', $files[0]->getRawFullPath() );
	}

	public function test_getFiles_respects_date_newer_filter() {
		$old = $this->make_file( 'old.txt' );
		touch( $old, time() - DAY_IN_SECONDS );
		$this->make_file( 'new.txt' );

		$files = ( new DirectoryModel( $this->sandbox ) )->getFiles( array( 'date_newer' => time() - HOUR_IN_SECONDS ) );

		$this->assertCount( 1, $files );
		$this->assertSame( 'new.txt', $files[0]->getFileName() );
	}

	public function test_getSubDirectories_returns_directory_models_for_children() {
		$this->make_dir( 'child-a' );
		$this->make_dir( 'child-b' );
		$this->make_file( 'file.txt' );

		$subs = ( new DirectoryModel( $this->sandbox ) )->getSubDirectories();

		$this->assertIsArray( $subs );
		$this->assertCount( 2, $subs );
		foreach ( $subs as $sub ) {
			$this->assertInstanceOf( DirectoryModel::class, $sub );
			$this->assertTrue( $sub->exists() );
		}
	}

	public function test_getSubDirectories_returns_false_for_missing_directory() {
		$dir = new DirectoryModel( $this->sandbox . '/nope' );
		$this->assertFalse( $dir->getSubDirectories() );
	}

	public function test_getFolderSize_sums_direct_and_nested_file_sizes() {
		$this->make_file( 'a.txt', str_repeat( 'a', 100 ) );
		$this->make_file( 'sub/b.txt', str_repeat( 'b', 250 ) );
		$this->make_file( 'sub/deeper/c.txt', str_repeat( 'c', 150 ) );

		$this->assertSame( 500, ( new DirectoryModel( $this->sandbox ) )->getFolderSize() );
	}

	public function test_getFolderSize_is_zero_for_missing_directory() {
		$this->assertSame( 0, ( new DirectoryModel( $this->sandbox . '/nope' ) )->getFolderSize() );
	}

	/*
	 * check().
	 */

	public function test_check_creates_missing_directory() {
		$path = $this->sandbox . '/nested/created-by-check';
		$dir  = new DirectoryModel( $path );
		$this->assertFalse( $dir->exists() );

		$this->assertTrue( $dir->check() );
		$this->assertDirectoryExists( $path );
		$this->assertTrue( $dir->exists() );
	}

	/**
	 * A created directory takes the permission bits of its nearest existing
	 * ancestor.
	 */
	public function test_check_creates_directory_with_ancestor_permissions() {
		$parent = $this->make_dir( 'parent' );
		chmod( $parent, 0750 );
		clearstatcache();
		$path = $parent . '/a/b';

		$this->assertTrue( ( new DirectoryModel( $path ) )->check( true ) );
		clearstatcache();
		$this->assertSame( 0750, fileperms( $path ) & 0777 );
		$this->assertSame( 0750, fileperms( $parent . '/a' ) & 0777 );
	}

	public function test_check_returns_true_for_already_existing_writable_directory() {
		$this->assertTrue( ( new DirectoryModel( $this->sandbox ) )->check( true ) );
	}

	public function test_check_is_idempotent_on_existing_directory() {
		$path = $this->make_dir( 'idempotent' );
		$dir  = new DirectoryModel( $path );

		$this->assertTrue( $dir->check() );
		$this->assertTrue( $dir->check() );
		$this->assertDirectoryExists( $path );
	}

	/**
	 * When mkdir() fails (here: the parent is a regular file), check() calls
	 * chmod() on the missing path without suppressing its warning, so the
	 * caller gets a PHP warning before check() can return false. The mkdir
	 * failure is already logged; the chmod should only run when mkdir
	 * succeeded.
	 *
	 * Where: DirectoryModel::check(), `chmod ($this->path, $permission );`
	 * right after the `@mkdir()`.
	 *
	 * How to recognise the fix: check() returns false without a warning,
	 * so the expected Warning is no longer raised.
	 */
	public function test_check_warns_when_directory_cannot_be_created_pinned_for_deferred_fix() {
		$blocker = $this->make_file( 'blocker.txt' );
		$path    = $blocker . '/sub';
		$dir     = new DirectoryModel( $path );
		$this->assertSame( $path . '/', $dir->getPath() );
		$this->assertFalse( $dir->exists() );

		$error = $this->capture_php_error(
			function () use ( $dir ) {
				$dir->check();
			}
		);
		$this->assertInstanceOf( \PHPUnit\Framework\Error\Warning::class, $error );
		$this->assertMatchesRegularExpression( '/chmod\(\)/', $error->getMessage() );
	}

	/**
	 * When an existing directory is not writable and check(true) is asked
	 * to fix it, the code reads `$this->permission`, a property that does
	 * not exist (the class has `new_directory_permission`). PHP raises an
	 * "Undefined property" notice (7.4) or warning (8.x). The intended
	 * behaviour is to chmod with the recursive/default permission computed
	 * at the top of check().
	 *
	 * The cached is_writable status is seeded to false by reflection: the
	 * container runs as root, where every directory is writable.
	 *
	 * Where: DirectoryModel::check(), `chmod($this->path, $this->permission);`.
	 *
	 * How to recognise the fix: no "Undefined property" error is raised.
	 */
	public function test_check_writable_fix_reads_undefined_property_pinned_for_deferred_fix() {
		$dir = new DirectoryModel( $this->make_dir( 'locked' ) );
		$this->assertTrue( $dir->exists() );
		$this->set_prop( $dir, 'is_writable', false );
		$this->assertFalse( $dir->is_writable() );

		$error = $this->capture_php_error(
			function () use ( $dir ) {
				$dir->check( true );
			}
		);
		$this->assertInstanceOf( PHP_VERSION_ID >= 80000 ? \PHPUnit\Framework\Error\Warning::class : \PHPUnit\Framework\Error\Notice::class, $error );
		$this->assertMatchesRegularExpression( '/Undefined property: .*DirectoryModel::\$permission$/', $error->getMessage() );
	}

	/*
	 * delete() and recursiveDelete().
	 */

	public function test_delete_removes_empty_directory() {
		$path = $this->make_dir( 'to-remove' );
		$this->assertTrue( ( new DirectoryModel( $path ) )->delete() );
		$this->assertDirectoryDoesNotExist( $path );
	}

	public function test_delete_returns_false_for_non_empty_directory() {
		$path = $this->make_dir( 'not-empty' );
		$this->make_file( 'not-empty/leaf.txt' );

		$this->assertFalse( @( new DirectoryModel( $path ) )->delete() );
		$this->assertDirectoryExists( $path );
	}

	/**
	 * recursiveDelete() removes a tree inside the uploads directory. It
	 * returns null on success (there is no return statement after the
	 * delete) and false when it refuses.
	 */
	public function test_recursiveDelete_removes_a_tree_inside_uploads() {
		$upload = wp_upload_dir( null, false );
		$root   = trailingslashit( $upload['basedir'] ) . 'emr-recursive-' . uniqid();
		$this->extra_dirs[] = $root;
		mkdir( $root . '/sub/deeper', 0755, true );
		file_put_contents( $root . '/a.txt', 'a' );
		file_put_contents( $root . '/sub/b.txt', 'b' );
		file_put_contents( $root . '/sub/deeper/c.txt', 'c' );

		$result = ( new DirectoryModel( $root ) )->recursiveDelete();

		$this->assertNull( $result );
		$this->assertDirectoryDoesNotExist( $root );
	}

	public function test_recursiveDelete_refuses_outside_uploads() {
		$path = $this->make_dir( 'outside' );
		$this->make_file( 'outside/keep.txt' );

		$this->assertFalse( ( new DirectoryModel( $path ) )->recursiveDelete() );
		$this->assertFileExists( $path . '/keep.txt' );
	}

	public function test_recursiveDelete_refuses_the_uploads_base_itself() {
		$upload = wp_upload_dir( null, false );
		$this->assertFalse( ( new DirectoryModel( $upload['basedir'] ) )->recursiveDelete() );
		$this->assertDirectoryExists( $upload['basedir'] );
	}

	public function test_recursiveDelete_returns_false_for_missing_directory() {
		$upload = wp_upload_dir( null, false );
		$this->assertFalse( ( new DirectoryModel( trailingslashit( $upload['basedir'] ) . 'emr-missing-' . uniqid() ) )->recursiveDelete() );
	}

	/*
	 * fileFilter (private).
	 */

	public function test_fileFilter_include_files_substring_match() {
		$file = new FileModel( $this->make_file( 'wanted.txt' ) );
		$dir  = new DirectoryModel( $this->sandbox );

		$this->assertTrue( $this->invoke_private( $dir, 'fileFilter', array( $file, $this->filter_args( array( 'include_files' => array( 'wanted' ) ) ) ) ) );
		$this->assertFalse( $this->invoke_private( $dir, 'fileFilter', array( $file, $this->filter_args( array( 'include_files' => array( 'other' ) ) ) ) ) );
	}

	public function test_fileFilter_exclude_files_substring_match() {
		$file = new FileModel( $this->make_file( 'blocked.log' ) );
		$dir  = new DirectoryModel( $this->sandbox );

		$this->assertFalse( $this->invoke_private( $dir, 'fileFilter', array( $file, $this->filter_args( array( 'exclude_files' => array( 'BLOCKED' ) ) ) ) ) );
	}

	public function test_fileFilter_exclude_wins_over_include() {
		$file = new FileModel( $this->make_file( 'both.log' ) );
		$dir  = new DirectoryModel( $this->sandbox );

		$this->assertFalse( $this->invoke_private( $dir, 'fileFilter', array( $file, $this->filter_args( array( 'include_files' => array( 'both' ), 'exclude_files' => array( '.log' ) ) ) ) ) );
	}

	public function test_fileFilter_date_newer_gate() {
		$path = $this->make_file( 'oldish.txt' );
		touch( $path, time() - DAY_IN_SECONDS );
		$file = new FileModel( $path );
		$dir  = new DirectoryModel( $this->sandbox );

		$this->assertFalse( $this->invoke_private( $dir, 'fileFilter', array( $file, $this->filter_args( array( 'date_newer' => time() ) ) ) ) );
	}

	public function test_fileFilter_default_args_keep_the_file() {
		$file = new FileModel( $this->make_file( 'kept.txt' ) );
		$dir  = new DirectoryModel( $this->sandbox );

		$this->assertTrue( $this->invoke_private( $dir, 'fileFilter', array( $file, $this->filter_args( array() ) ) ) );
	}
}
