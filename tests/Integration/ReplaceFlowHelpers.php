<?php
/**
 * Helpers shared by the replace-flow integration tests
 * (test-ReplaceFlow.php, test-ReplaceDates.php, test-SpioContract.php).
 *
 * Not a test file (no `test-` prefix, so PHPUnit does not collect it); each
 * test file requires it.
 *
 * The driver is the one UploadViewController::load() uses after its
 * validation: a `$params` array shaped like getPost()'s result, then
 * `new ReplaceController($id)`, setupParams() and run(), as an
 * administrator. The "uploaded" file is a copy of a fixture (or a GD-built
 * image) in a temporary path, because run() deletes it.
 *
 * Cleanup: attachments go through the base case's uploadFile() list
 * (wp_delete_attachment(..., true) in tear_down, which follows a renamed or
 * moved file because the attached file and metadata are updated), plus the
 * extra files and directories recorded here. Hooks added through addHook()
 * are removed in tear_down. The current user is restored.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Controller\ReplaceController;

trait EMR_ReplaceFlowHelpers {

	/** @var int Administrator created for the test. */
	protected $adminId = 0;

	/** @var int User current before the test. */
	protected $savedUserId = 0;

	/** @var string[] Files to remove in tear_down (if still there). */
	protected $flowFiles = array();

	/** @var string[] Directories to remove recursively in tear_down. */
	protected $flowDirs = array();

	/** @var array Hooks added by the test: array( hook, callback, priority ). */
	protected $flowHooks = array();

	/** @var array Events recorded by record(): array( name, args ). */
	protected $events = array();

	/** Call from set_up() after parent::set_up(). */
	protected function flowSetUp() {
		$this->savedUserId = get_current_user_id();
		$this->adminId     = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'EMR Flow Admin',
			)
		);
		wp_set_current_user( $this->adminId );
		$this->events = array();
	}

	/** Call from tear_down() before parent::tear_down(). */
	protected function flowTearDown() {
		foreach ( $this->flowHooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$this->flowHooks = array();

		// Attachments first (they delete their own files), then leftovers.
		foreach ( $this->uploadedAttachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->uploadedAttachments = array();

		foreach ( $this->flowFiles as $file ) {
			if ( file_exists( $file ) && ! is_dir( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$this->flowFiles = array();

		foreach ( array_reverse( $this->flowDirs ) as $dir ) {
			if ( is_dir( $dir ) ) {
				$this->removeTree( $dir );
			}
		}
		$this->flowDirs = array();

		wp_set_current_user( $this->savedUserId );
	}

	/** Adds a filter/action and records it for tear_down. */
	protected function addHook( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->flowHooks[] = array( $hook, $callback, $priority );
	}

	/**
	 * A recording callback: stores ( name, args ) and returns the first
	 * argument, so it works for filters and actions alike.
	 */
	protected function record( $name ) {
		$events = &$this->events;
		return function () use ( $name, &$events ) {
			$args     = func_get_args();
			$events[] = array( $name, $args );
			return isset( $args[0] ) ? $args[0] : null;
		};
	}

	/** Names of the recorded events, in order. */
	protected function eventNames() {
		return array_map(
			function ( $event ) {
				return $event[0];
			},
			$this->events
		);
	}

	/** Recursively removes a directory. */
	protected function removeTree( $dir ) {
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->removeTree( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** A short random lower-case token for unique file names. */
	protected function token() {
		return strtolower( wp_generate_password( 6, false, false ) );
	}

	// -------------------------------------------------------------------
	// Image and file builders.
	// -------------------------------------------------------------------

	/** Writes a solid-colour JPEG of the given size with GD. */
	protected function makeJpeg( $path, $width, $height, $rgb = array( 30, 120, 200 ) ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is needed to build test images of a given size.' );
		}
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] ) );
		imagejpeg( $image, $path, 80 );
		$this->freeGdImage( $image );
		return $path;
	}

	/**
	 * Frees a GD image on PHP 7.x only: since PHP 8.0 GdImage objects are
	 * garbage-collected and imagedestroy() is a no-op that PHP 8.5
	 * deprecates.
	 */
	protected function freeGdImage( $image ) {
		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $image );
		}
	}

	/** Writes a solid-colour PNG of the given size with GD. */
	protected function makePng( $path, $width, $height ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is needed to build test images of a given size.' );
		}
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 200, 60, 40 ) );
		imagepng( $image, $path );
		$this->freeGdImage( $image );
		return $path;
	}

	/**
	 * Writes a JPEG carrying IPTC title (2#005) and caption (2#120), which
	 * wp_read_image_metadata() turns into image_meta title and caption.
	 */
	protected function makeJpegWithIptc( $path, $width, $height, $title, $caption ) {
		$this->makeJpeg( $path, $width, $height );
		$iptc = $this->iptcTag( 2, 5, $title ) . $this->iptcTag( 2, 120, $caption );
		$data = iptcembed( $iptc, $path );
		$this->assertIsString( $data, 'Sentinel: iptcembed() produced a JPEG.' );
		file_put_contents( $path, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $path;
	}

	/** One IPTC dataset (short form, values under 32 KB). */
	private function iptcTag( $record, $dataset, $value ) {
		$length = strlen( $value );
		return chr( 0x1C ) . chr( $record ) . chr( $dataset ) . chr( $length >> 8 ) . chr( $length & 0xFF ) . $value;
	}

	/** A temporary directory (outside uploads), removed in tear_down. */
	protected function tempDir() {
		$dir = trailingslashit( get_temp_dir() ) . 'emr-flow-' . $this->token();
		wp_mkdir_p( $dir );
		$this->flowDirs[] = $dir;
		return $dir;
	}

	/**
	 * A temporary "uploaded" file, as PHP leaves it in its tmp dir: a copy of
	 * a fixture (by fixture name) or of a file on disk (absolute path).
	 */
	protected function tmpUpload( $source ) {
		$path = ( 0 === strpos( $source, '/' ) ) ? $source : $this->fixturePath( $source );
		$tmp  = wp_tempnam( 'emr-upload' );
		copy( $path, $tmp );
		$this->flowFiles[] = $tmp;
		return $tmp;
	}

	/** A temporary "uploaded" JPEG built with GD. */
	protected function tmpJpeg( $width, $height, $rgb = array( 30, 120, 200 ) ) {
		$tmp               = wp_tempnam( 'emr-upload' );
		$this->flowFiles[] = $tmp;
		return $this->makeJpeg( $tmp, $width, $height, $rgb );
	}

	/** Uploads a GD-built JPEG under the given name; returns the attachment id. */
	protected function uploadJpeg( $name, $width, $height ) {
		$path = $this->makeJpeg( $this->tempDir() . '/' . $name, $width, $height, array( 220, 220, 20 ) );
		return $this->uploadFile( $path );
	}

	/** Uploads a copy of a fixture under another name. */
	protected function uploadFixtureAs( $fixture, $name ) {
		$path = $this->tempDir() . '/' . $name;
		copy( $this->fixturePath( $fixture ), $path );
		return $this->uploadFile( $path );
	}

	/** Uploads a text file with the given body under the given name. */
	protected function uploadText( $name, $body = "EMR integration text file\n" ) {
		$path = $this->tempDir() . '/' . $name;
		file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $this->uploadFile( $path );
	}

	/** A minimal, valid one-page PDF document. */
	protected function pdfBytes( $label = 'EMR' ) {
		$objects = array(
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
		);
		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $i => $object ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R /Info << /Title (" . $label . ") >> >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}

	// -------------------------------------------------------------------
	// The driver.
	// -------------------------------------------------------------------

	/**
	 * The `$params` array UploadViewController::getPost() hands to
	 * setupParams(), with $overrides merged in.
	 */
	protected function flowParams( $post_id, $upload_file, $new_filename, array $overrides = array() ) {
		return array_merge(
			array(
				'post_id'           => $post_id,
				'replace_type'      => 'replace',
				'timestamp_replace' => ReplaceController::TIME_UPDATEMODIFIED,
				'new_date'          => current_time( 'mysql' ),
				'new_location'      => false,
				'location_dir'      => null,
				'is_custom_date'    => false,
				'remove_background' => false,
				'uploadFile'        => $upload_file,
				'new_filename'      => $new_filename,
			),
			$overrides
		);
	}

	/**
	 * Runs the replace exactly as UploadViewController::load() does after its
	 * validation, asserting setupParams() and run() succeed.
	 *
	 * @return ReplaceController
	 */
	protected function replaceVia( $post_id, array $params ) {
		$controller = new ReplaceController( $post_id );
		$this->assertTrue( $controller->setupParams( $params ), 'setupParams() resolves a target (error ' . var_export( $controller->returnLastError(), true ) . ').' );
		$this->assertTrue( $controller->run(), 'run() returns true.' );
		$this->assertNull( $controller->returnLastError(), 'run() records no error.' );
		return $controller;
	}

	/** MODE_REPLACE with a temporary upload. */
	protected function replaceMode( $post_id, $upload_file, $new_filename = 'upload.jpg', array $overrides = array() ) {
		return $this->replaceVia( $post_id, $this->flowParams( $post_id, $upload_file, $new_filename, $overrides ) );
	}

	/** MODE_SEARCHREPLACE with a temporary upload. */
	protected function searchReplaceMode( $post_id, $upload_file, $new_filename, array $overrides = array() ) {
		$overrides['replace_type'] = 'replace_and_search';
		return $this->replaceVia( $post_id, $this->flowParams( $post_id, $upload_file, $new_filename, $overrides ) );
	}

	// -------------------------------------------------------------------
	// Observation helpers.
	// -------------------------------------------------------------------

	/** Absolute paths of every size file of the attachment's current metadata. */
	protected function sizePaths( $post_id ) {
		$meta  = wp_get_attachment_metadata( $post_id );
		$dir   = dirname( get_attached_file( $post_id ) );
		$paths = array();
		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $data ) {
				$paths[ $size ] = $dir . '/' . $data['file'];
			}
		}
		return $paths;
	}

	/** URL of a size of the attachment, from its metadata. */
	protected function sizeUrl( $post_id, $size ) {
		$meta = wp_get_attachment_metadata( $post_id );
		$this->assertArrayHasKey( $size, $meta['sizes'], "Sentinel: size $size exists." );
		return trailingslashit( dirname( wp_get_attachment_url( $post_id ) ) ) . $meta['sizes'][ $size ]['file'];
	}

	/** Creates a post and writes its content directly (no kses, no filters). */
	protected function makePost( $content, $status = 'publish', $type = 'post' ) {
		global $wpdb;
		$args = array(
			'post_status' => $status,
			'post_type'   => $type,
		);
		if ( 'future' === $status ) {
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS );
			$args['post_date_gmt'] = $args['post_date'];
		}
		$post_id = self::factory()->post->create( $args );
		$this->assertSame( $status, get_post_status( $post_id ), "Sentinel: the post has status $status." );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		return $post_id;
	}

	/** Raw post_content from the database. */
	protected function rawContent( $post_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
	}

	/** Raw post row field from the database. */
	protected function rawPostField( $post_id, $field ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT {$field} FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Raw meta_value of a postmeta row. */
	protected function rawPostMeta( $post_id, $key ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ) );
	}

	/** Raw option_value. */
	protected function rawOption( $name ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/** The _wp_attached_file meta, read from the database. */
	protected function rawAttachedFile( $post_id ) {
		return $this->rawPostMeta( $post_id, '_wp_attached_file' );
	}
}
