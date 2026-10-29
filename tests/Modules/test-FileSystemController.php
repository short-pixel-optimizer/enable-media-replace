<?php
/**
 * Tests for EnableMediaReplace\FileSystem\Controller\FileSystemController
 * (build/shortpixel/filesystem/src/Controller/FileSystemController.php),
 * reachable as emr()->filesystem().
 *
 * The module has few docblocks, so the code is the specification. HTTP is
 * mocked through `pre_http_request`. A short-circuited request skips the
 * transport, so the mock does what WP_Http does for a streamed request:
 * it writes the body to $args['filename'] (whatever the status code) and
 * returns that path in the response's 'filename' key, and it returns a
 * WP_Error when the target directory cannot be opened. Every request is
 * answered by the mock; none reaches the network.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\FileSystem\Controller\FileSystemController;
use EnableMediaReplace\FileSystem\Model\File\FileModel;
use EnableMediaReplace\FileSystem\Model\File\DirectoryModel;

class FileSystemControllerTest extends WP_UnitTestCase {

	/**
	 * @var FileSystemController
	 */
	private $fs;

	/**
	 * Sandbox directory for test files, with a trailing slash.
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
	 * Requests seen by the HTTP mock, as array( url, args ).
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Queue of responses for the HTTP mock: each entry is 'error' or an
	 * array( code, body ). The last entry repeats when the queue runs out.
	 *
	 * @var array
	 */
	private $responses = array();

	public function set_up() {
		parent::set_up();
		$this->fs      = new FileSystemController();
		$this->sandbox = sys_get_temp_dir() . '/emr-fsc-' . uniqid() . '/';
		wp_mkdir_p( $this->sandbox );
		$this->requests  = array();
		$this->responses = array();
	}

	public function tear_down() {
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		if ( is_dir( $this->sandbox ) ) {
			$this->recursive_rmdir( rtrim( $this->sandbox, '/' ) );
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
	 * Installs the HTTP mock with the given response queue.
	 */
	private function mock_http( array $responses ) {
		$this->responses = $responses;
		$this->add_test_filter( 'pre_http_request', array( $this, 'http_mock' ), 10, 3 );
	}

	/**
	 * The pre_http_request callback. Public because WordPress calls it.
	 */
	public function http_mock( $pre, $args, $url ) {
		$this->requests[] = array( $url, $args );
		$next             = count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];

		if ( 'error' === $next ) {
			return new WP_Error( 'http_request_failed', 'Mocked failure.' );
		}

		$filename = null;
		if ( ! empty( $args['stream'] ) ) {
			$filename = $args['filename'];
			if ( ! is_dir( dirname( $filename ) ) ) {
				return new WP_Error( 'http_request_failed', 'Could not open handle for fopen() to ' . $filename );
			}
			file_put_contents( $filename, $next[1] );
		}

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $next[0],
				'message' => get_status_header_desc( $next[0] ),
			),
			'cookies'  => array(),
			'filename' => $filename,
		);
	}

	/*
	 * getFile.
	 */

	public function test_getFile_returns_a_FileModel_instance() {
		$this->assertInstanceOf( FileModel::class, $this->fs->getFile( $this->sandbox . 'dummy.jpg' ) );
	}

	public function test_getFile_returns_model_for_nonexistent_path_without_error() {
		$file = $this->fs->getFile( $this->sandbox . 'ghost.jpg' );

		$this->assertInstanceOf( FileModel::class, $file );
		$this->assertFalse( $file->exists() );
	}

	public function test_getFile_reflects_the_correct_path_for_a_real_file() {
		$path = $this->sandbox . 'real.txt';
		file_put_contents( $path, 'hello' );

		$file = $this->fs->getFile( $path );

		$this->assertTrue( $file->exists() );
		$this->assertSame( 'real.txt', $file->getFileName() );
		$this->assertSame( $path, $file->getFullPath() );
	}

	public function test_getFile_resolves_a_relative_path_against_abspath() {
		$file = $this->fs->getFile( 'wp-content/uploads/emr-fsc-relative.jpg' );
		$this->assertSame( ABSPATH . 'wp-content/uploads/emr-fsc-relative.jpg', $file->getFullPath() );
	}

	public function test_getFile_with_remote_url_returns_virtual_model() {
		$file = $this->fs->getFile( 'https://cdn.remote.test/x.png' );
		$this->assertTrue( $file->is_virtual() );
		$this->assertFalse( $file->exists() );
	}

	/*
	 * getDirectory.
	 */

	public function test_getDirectory_returns_a_DirectoryModel_instance() {
		$this->assertInstanceOf( DirectoryModel::class, $this->fs->getDirectory( $this->sandbox ) );
	}

	public function test_getDirectory_existing_directory_reports_exists_true() {
		$this->assertTrue( $this->fs->getDirectory( $this->sandbox )->exists() );
	}

	public function test_getDirectory_nonexistent_directory_reports_exists_false() {
		$this->assertFalse( $this->fs->getDirectory( $this->sandbox . 'no-such-subdir/' )->exists() );
	}

	public function test_getDirectory_with_url_keeps_url_as_path() {
		$dir = $this->fs->getDirectory( 'https://cdn.remote.test/media/x.png' );
		$this->assertSame( 'https://cdn.remote.test/media/', $dir->getPath() );
	}

	/*
	 * pathIsUrl.
	 */

	public function test_pathIsUrl_returns_true_for_http_scheme() {
		$this->assertTrue( $this->fs->pathIsUrl( 'http://example.com/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_true_for_https_scheme() {
		$this->assertTrue( $this->fs->pathIsUrl( 'https://example.com/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_true_for_protocol_relative_url() {
		$this->assertTrue( $this->fs->pathIsUrl( '//example.com/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_true_for_custom_scheme_with_triple_slash() {
		$this->assertTrue( $this->fs->pathIsUrl( 's3://my-bucket/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_false_for_absolute_filesystem_path() {
		$this->assertFalse( $this->fs->pathIsUrl( '/var/www/html/wp-content/uploads/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_false_for_relative_path() {
		$this->assertFalse( $this->fs->pathIsUrl( 'wp-content/uploads/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_false_for_empty_string() {
		$this->assertFalse( $this->fs->pathIsUrl( '' ) );
	}

	/**
	 * The check is a plain prefix test: anything starting with "http" counts,
	 * including a relative path to a directory named that way.
	 */
	public function test_pathIsUrl_treats_any_http_prefix_as_url() {
		$this->assertTrue( $this->fs->pathIsUrl( 'httpdocs/wp-content/uploads/image.jpg' ) );
		$this->assertTrue( $this->fs->pathIsUrl( 'HTTP://EXAMPLE.COM/a.jpg' ), 'Upper case passes through the :// check.' );
	}

	/*
	 * getWPUploadBase.
	 */

	public function test_getWPUploadBase_returns_a_DirectoryModel() {
		$this->assertInstanceOf( DirectoryModel::class, $this->fs->getWPUploadBase() );
	}

	public function test_getWPUploadBase_points_to_an_existing_directory() {
		$this->assertTrue( $this->fs->getWPUploadBase()->exists() );
	}

	public function test_getWPUploadBase_path_matches_wp_upload_dir_basedir() {
		$upload = wp_upload_dir( null, false );
		$this->assertSame( trailingslashit( $upload['basedir'] ), $this->fs->getWPUploadBase()->getPath() );
	}

	public function test_getWPUploadBase_follows_the_upload_dir_filter() {
		$sandbox = $this->sandbox;
		$cb      = function ( $dirs ) use ( $sandbox ) {
			$dirs['basedir'] = rtrim( $sandbox, '/' );
			return $dirs;
		};
		$this->add_test_filter( 'upload_dir', $cb );

		$this->assertSame( $this->sandbox, $this->fs->getWPUploadBase()->getPath() );
	}

	/*
	 * getWPAbsPath.
	 */

	public function test_getWPAbsPath_returns_a_DirectoryModel() {
		$this->assertInstanceOf( DirectoryModel::class, $this->fs->getWPAbsPath() );
	}

	public function test_getWPAbsPath_returns_a_non_empty_path() {
		$path = $this->fs->getWPAbsPath()->getPath();
		$this->assertIsString( $path );
		$this->assertNotEmpty( $path );
	}

	public function test_getWPAbsPath_contains_the_abspath_root() {
		$path = $this->fs->getWPAbsPath()->getPath();
		$this->assertStringContainsString( rtrim( $path, '/' ), WP_CONTENT_DIR );
		$this->assertSame( ABSPATH, $path, 'With the standard layout the content root is ABSPATH.' );
	}

	public function test_getWPAbsPath_follows_the_abspath_filter() {
		$sandbox = $this->sandbox;
		$seen    = array();
		$cb      = function ( $abspath ) use ( $sandbox, &$seen ) {
			$seen[] = $abspath;
			return $sandbox;
		};
		$this->add_test_filter( 'emr/filesystem/abspath', $cb );

		$this->assertSame( $this->sandbox, $this->fs->getWPAbsPath()->getPath() );
		$this->assertSame( array( ABSPATH ), $seen );
	}

	/*
	 * getFullPathForWP.
	 */

	/**
	 * getFullPathForWP() is an unfinished stub: it reads the file path and
	 * the content root, runs an `if` with an empty body and returns nothing.
	 * A caller gets null for every file. It should either return the path
	 * relative to (or within) the WordPress root, or be removed.
	 *
	 * Where: FileSystemController::getFullPathForWP().
	 *
	 * How to recognise the fix: the method returns a string for a file
	 * inside the uploads directory (or the method no longer exists).
	 */
	public function test_getFullPathForWP_returns_null_pinned_for_deferred_fix() {
		$this->assertTrue( method_exists( $this->fs, 'getFullPathForWP' ) );
		$upload = wp_upload_dir( null, false );
		$file   = new FileModel( trailingslashit( $upload['basedir'] ) . 'emr-fullpath.jpg' );

		$this->assertNull( $this->fs->getFullPathForWP( $file ) );
	}

	/*
	 * pathToUrl.
	 */

	public function test_pathToUrl_maps_an_uploads_file_to_the_uploads_url() {
		$upload = wp_upload_dir( null, false );
		$file   = new FileModel( trailingslashit( $upload['basedir'] ) . '2024/05/emr-photo.jpg' );

		$this->assertSame( trailingslashit( $upload['baseurl'] ) . '2024/05/emr-photo.jpg', $this->fs->pathToUrl( $file ) );
	}

	public function test_pathToUrl_maps_a_file_elsewhere_in_the_install_to_the_site_url() {
		$file = new FileModel( WP_CONTENT_DIR . '/plugins/emr-sample/readme.txt' );
		$this->assertSame( trailingslashit( get_site_url() ) . 'wp-content/plugins/emr-sample/readme.txt', $this->fs->pathToUrl( $file ) );
	}

	/**
	 * A path outside the WordPress install is appended to the uploads URL as
	 * is (the "newly uploaded, relative to basedir" fallback), which yields
	 * a URL with the absolute filesystem path inside it, not false.
	 */
	public function test_pathToUrl_appends_a_foreign_absolute_path_to_the_uploads_url() {
		$upload = wp_upload_dir( null, false );
		$path   = $this->sandbox . 'outside.jpg';
		file_put_contents( $path, 'x' );

		$this->assertSame( $upload['baseurl'] . '/' . $path, $this->fs->pathToUrl( new FileModel( $path ) ) );
	}

	/*
	 * sortFiles.
	 */

	public function test_sortFiles_returns_empty_array_unchanged() {
		$this->assertSame( array(), $this->fs->sortFiles( array() ) );
	}

	public function test_sortFiles_sorts_DirectoryModel_objects_by_name() {
		foreach ( array( 'zebra', 'apple', 'mango' ) as $name ) {
			mkdir( $this->sandbox . $name );
		}
		$dirs = array(
			new DirectoryModel( $this->sandbox . 'zebra' ),
			new DirectoryModel( $this->sandbox . 'apple' ),
			new DirectoryModel( $this->sandbox . 'mango' ),
		);

		$sorted = $this->fs->sortFiles( $dirs );

		$names = array_map(
			function ( $dir ) {
				return $dir->getName();
			},
			$sorted
		);
		$this->assertSame( array( 'apple', 'mango', 'zebra' ), $names );
	}

	/**
	 * sortFiles() detects a FileModel array by comparing get_class() with
	 * 'EnableMediaReplace\FileModel', a class that does not exist (the
	 * build tool rewrote SPIO's namespace prefix, not the model's real
	 * location `EnableMediaReplace\FileSystem\Model\File\FileModel`). Every
	 * array of FileModel objects is therefore sorted as directories, through
	 * getName(), which FileModel does not have, and the call dies with an
	 * Error. The intended behaviour is sorting files by getFileName().
	 *
	 * Where: FileSystemController::sortFiles(), the $is_files comparison.
	 *
	 * How to recognise the fix: no Error is thrown and the files come back
	 * sorted by name.
	 */
	public function test_sortFiles_crashes_on_FileModel_arrays_pinned_for_deferred_fix() {
		foreach ( array( 'zebra.jpg', 'apple.jpg' ) as $name ) {
			file_put_contents( $this->sandbox . $name, '' );
		}
		$files = array(
			new FileModel( $this->sandbox . 'zebra.jpg' ),
			new FileModel( $this->sandbox . 'apple.jpg' ),
		);
		$this->assertSame( 'EnableMediaReplace\FileSystem\Model\File\FileModel', get_class( $files[0] ) );

		$this->expectException( \Error::class );
		$this->expectExceptionMessageMatches( '/undefined method .*FileModel::getName\(\)/' );
		$this->fs->sortFiles( $files );
	}

	/*
	 * getFilesRecursive.
	 */

	public function test_getFilesRecursive_collects_files_from_every_level() {
		mkdir( $this->sandbox . 'a/b', 0755, true );
		file_put_contents( $this->sandbox . 'top.txt', '1' );
		file_put_contents( $this->sandbox . 'a/mid.txt', '2' );
		file_put_contents( $this->sandbox . 'a/b/deep.txt', '3' );

		$files = $this->fs->getFilesRecursive( new DirectoryModel( $this->sandbox ) );

		$names = array_map(
			function ( $file ) {
				return $file->getFileName();
			},
			$files
		);
		sort( $names );
		$this->assertSame( array( 'deep.txt', 'mid.txt', 'top.txt' ), $names );
		$this->assertContainsOnlyInstancesOf( FileModel::class, $files );
	}

	public function test_getFilesRecursive_applies_filters_at_every_level() {
		mkdir( $this->sandbox . 'a', 0755, true );
		file_put_contents( $this->sandbox . 'keep.jpg', '1' );
		file_put_contents( $this->sandbox . 'drop.txt', '2' );
		file_put_contents( $this->sandbox . 'a/keep2.jpg', '3' );
		file_put_contents( $this->sandbox . 'a/drop2.txt', '4' );

		$files = $this->fs->getFilesRecursive( new DirectoryModel( $this->sandbox ), array( 'include_files' => array( '.jpg' ) ) );

		$this->assertCount( 2, $files );
	}

	public function test_getFilesRecursive_returns_empty_array_for_missing_directory() {
		$this->assertSame( array(), $this->fs->getFilesRecursive( new DirectoryModel( $this->sandbox . 'missing' ) ) );
	}

	/*
	 * downloadFile.
	 */

	public function test_downloadFile_writes_the_body_to_the_target_path() {
		$this->mock_http( array( array( 200, 'IMAGEBYTES' ) ) );
		$target = $this->sandbox . 'downloaded.jpg';

		$result = $this->fs->downloadFile( 'https://files.remote.test/a.jpg', $target );

		$this->assertTrue( $result );
		$this->assertSame( 'IMAGEBYTES', file_get_contents( $target ) );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://files.remote.test/a.jpg', $this->requests[0][0] );
		$this->assertTrue( $this->requests[0][1]['stream'] );
		$this->assertSame( $target, $this->requests[0][1]['filename'] );
	}

	/**
	 * When the first request fails, downloadFile() retries once through
	 * download_url(); both failing yields false.
	 */
	public function test_downloadFile_returns_false_when_both_attempts_fail() {
		$this->mock_http( array( 'error' ) );
		$target = $this->sandbox . 'never.jpg';

		$this->assertFalse( $this->fs->downloadFile( 'https://files.remote.test/b.jpg', $target ) );
		$this->assertFileDoesNotExist( $target );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'https://files.remote.test/b.jpg', $this->requests[1][0] );
		$this->assertNotSame( $target, $this->requests[1][1]['filename'], 'The retry streams into a temp file.' );
	}

	public function test_downloadFile_falls_back_to_download_url_and_moves_the_temp_file() {
		$this->mock_http( array( 'error', array( 200, 'SECONDTRY' ) ) );
		$target = $this->sandbox . 'retry.jpg';

		$this->assertTrue( $this->fs->downloadFile( 'https://files.remote.test/c.jpg', $target ) );
		$this->assertSame( 'SECONDTRY', file_get_contents( $target ) );
		$this->assertCount( 2, $this->requests );
		$this->assertFileDoesNotExist( $this->requests[1][1]['filename'], 'The temp file is moved, not copied.' );
	}

	/**
	 * With a missing target directory the first (streamed) request fails,
	 * the download_url() retry succeeds into a temp file, and moving that
	 * file to the target fails, so the call returns false. The temp file
	 * from download_url() is then left behind in the temp directory: move()
	 * only deletes the source after a successful copy, and downloadFile()
	 * never cleans up. The temp file should be deleted when the move fails.
	 *
	 * Where: FileSystemController::downloadFile(), the download_url() branch.
	 *
	 * How to recognise the fix: the temp file no longer exists after the
	 * call.
	 */
	public function test_downloadFile_missing_target_dir_leaves_temp_file_pinned_for_deferred_fix() {
		$this->mock_http( array( array( 200, 'BYTES' ) ) );
		$target = $this->sandbox . 'no-dir/file.jpg';

		$result = $this->fs->downloadFile( 'https://files.remote.test/d.jpg', $target );
		$temp   = isset( $this->requests[1] ) ? $this->requests[1][1]['filename'] : null;
		$leaked = ( null !== $temp && file_exists( $temp ) );
		if ( $leaked ) {
			unlink( $temp );
		}

		$this->assertFalse( $result );
		$this->assertFileDoesNotExist( $target );
		$this->assertCount( 2, $this->requests );
		$this->assertIsString( $temp );
		$this->assertNotSame( $target, $temp );
		$this->assertTrue( $leaked );
	}

	/**
	 * downloadFile() decides success only by "does the target file exist
	 * now". WP_Http streams the response body to the target whatever the
	 * status code, so a 404 or 500 page is written to the target and the
	 * method returns true, leaving an HTML error page where the caller
	 * expects an image. The status code of the first response must be
	 * checked (and the file removed) like download_url() does.
	 *
	 * Where: FileSystemController::downloadFile(), the success branch.
	 *
	 * How to recognise the fix: the call returns false for a 404 and the
	 * target file is gone.
	 */
	public function test_downloadFile_returns_true_for_an_error_status_pinned_for_deferred_fix() {
		$this->mock_http( array( array( 404, '<html>Not Found</html>' ) ) );
		$target = $this->sandbox . 'missing-remote.jpg';

		$result = $this->fs->downloadFile( 'https://files.remote.test/404.jpg', $target );

		$this->assertTrue( $result );
		$this->assertSame( '<html>Not Found</html>', file_get_contents( $target ) );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * A non-200 response that writes nothing (no body) leaves no target, so
	 * the call returns false.
	 */
	public function test_downloadFile_returns_false_for_non_200_without_body_file() {
		$sandbox = $this->sandbox;
		$cb      = function ( $pre, $args, $url ) {
			$this->requests[] = array( $url, $args );
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
				'cookies'  => array(),
				'filename' => $args['filename'],
			);
		};
		$this->add_test_filter( 'pre_http_request', $cb, 10, 3 );

		$this->assertFalse( $this->fs->downloadFile( 'https://files.remote.test/500.jpg', $sandbox . 'nothing.jpg' ) );
		$this->assertFileDoesNotExist( $sandbox . 'nothing.jpg' );
	}

	/*
	 * url_exists.
	 */

	/**
	 * url_exists() uses cURL directly (no WordPress HTTP API, so it cannot be
	 * mocked through pre_http_request). A file:// URL keeps it off the
	 * network: there is no HTTP status, so the answer is false even for an
	 * existing file. Only a 200 counts.
	 */
	public function test_url_exists_is_false_without_an_http_200() {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->assertNull( $this->fs->url_exists( 'file:///nothing' ) );
			return;
		}
		$path = $this->sandbox . 'local.txt';
		file_put_contents( $path, 'local' );

		ob_start();
		$result = $this->fs->url_exists( 'file://' . $path );
		ob_end_clean();

		$this->assertFalse( $result );
	}

	/*
	 * The emr()->filesystem() accessor.
	 */

	public function test_emr_filesystem_returns_a_new_controller_each_call() {
		$a = emr()->filesystem();
		$b = emr()->filesystem();

		$this->assertInstanceOf( FileSystemController::class, $a );
		$this->assertNotSame( $a, $b );
	}
}
