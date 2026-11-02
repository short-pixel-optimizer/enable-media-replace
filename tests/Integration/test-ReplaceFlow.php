<?php
/**
 * Integration tests: the replace flow from an "uploaded" file to the
 * persisted attachment, for both replace modes.
 *
 * Driven the way UploadViewController::load() drives it after its
 * validation: a getPost()-shaped `$params` array, `new
 * ReplaceController($id)`, setupParams(), run(), as an administrator, with a
 * copy of a fixture (or a GD-built image) in a temporary path as the upload.
 * Everything is observed through WordPress APIs and the uploads directory:
 * get_attached_file(), the raw `_wp_attached_file`, wp_get_attachment_metadata(),
 * get_post(), get_option(), the meta APIs and the files on disk.
 *
 * Content shapes seeded for the reference rewrites follow the patterns of
 * SPIO's tests/Integration/test-ConversionReplacement.php (classic img,
 * srcset, Gutenberg blocks, draft / private / future / pending posts, a
 * page, string / serialized / object / JSON postmeta, options, term, user
 * and comment meta) with EMR's ReplaceController as the driver. Most tests
 * seed one matching postmeta row so the outcome on that row can be
 * observed; one test seeds two matching rows in the same table to show
 * that each owner's meta cache is cleared.
 *
 * The uploads folder is the test install's current month folder; every
 * attachment, file and directory a test creates is deleted in tear_down.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ReplaceFlowHelpers.php';

use EnableMediaReplace\Controller\ReplaceController;

class ReplaceFlowTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;

	public function set_up() {
		parent::set_up();
		$this->flowSetUp();
	}

	public function tear_down() {
		$this->flowTearDown();
		parent::tear_down();
	}

	/**
	 * A 400x300 JPEG attachment (sizes: thumbnail 150x150, medium 300x225).
	 *
	 * @return array( id, path, main url, thumbnail url, medium url )
	 */
	private function standardImage( $prefix = 'emr-flow' ) {
		$id   = $this->uploadJpeg( $prefix . '-' . $this->token() . '.jpg', 400, 300 );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( array( 'medium', 'thumbnail' ), $this->sortedKeys( $meta['sizes'] ), 'Sentinel: a 400x300 image gets thumbnail and medium.' );
		return array( $id, get_attached_file( $id ), wp_get_attachment_url( $id ), $this->sizeUrl( $id, 'thumbnail' ), $this->sizeUrl( $id, 'medium' ) );
	}

	private function sortedKeys( array $array ) {
		$keys = array_keys( $array );
		sort( $keys );
		return $keys;
	}

	/** A month-style folder under uploads that does not exist yet; removed in tear_down. */
	private function freshUploadsFolder() {
		$uploads = wp_upload_dir();
		$year    = '19' . wp_rand( 70, 89 );
		$this->assertDirectoryDoesNotExist( $uploads['basedir'] . '/' . $year . '/06', 'Sentinel: the folder is new.' );
		if ( ! is_dir( $uploads['basedir'] . '/' . $year ) ) {
			$this->flowDirs[] = $uploads['basedir'] . '/' . $year;
		} else {
			$this->flowDirs[] = $uploads['basedir'] . '/' . $year . '/06';
		}
		return $year . '/06';
	}

	// ---------------------------------------------------------------------
	// MODE_REPLACE, same type.
	// ---------------------------------------------------------------------

	public function test_replace_mode_writes_the_new_bytes_at_the_same_path_and_keeps_the_identity() {
		list( $id, $path, $url ) = $this->standardImage();
		$before = get_post( $id );
		$raw    = $this->rawAttachedFile( $id );
		$tmp    = $this->tmpJpeg( 600, 400 );
		$md5    = md5_file( $tmp );
		$this->assertNotSame( md5_file( $path ), $md5, 'Sentinel: the upload has other bytes.' );

		$this->replaceMode( $id, $tmp, 'whatever-the-browser-sent.jpg' );

		$after = get_post( $id );
		$this->assertSame( $path, get_attached_file( $id ) );
		$this->assertSame( $raw, $this->rawAttachedFile( $id ), '_wp_attached_file is unchanged.' );
		$this->assertSame( $md5, md5_file( $path ), 'The new bytes sit at the old path.' );
		$this->assertSame( $url, wp_get_attachment_url( $id ) );
		$this->assertSame( 'image/jpeg', $after->post_mime_type );
		$this->assertSame( $before->guid, $after->guid, 'The guid is unchanged.' );
		$this->assertSame( $before->post_title, $after->post_title, 'The title is unchanged.' );
		$this->assertSame( $before->post_name, $after->post_name, 'The slug is unchanged.' );
		$this->assertFileDoesNotExist( $tmp, 'The temporary upload is consumed.' );
	}

	public function test_replace_mode_regenerates_the_metadata_and_the_size_files() {
		list( $id, $path ) = $this->standardImage();
		$old_sizes = $this->sizePaths( $id );
		$old_thumb = md5_file( $old_sizes['thumbnail'] );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400, array( 10, 200, 10 ) ) );

		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 600, $meta['width'] );
		$this->assertSame( 400, $meta['height'] );
		$this->assertSame( _wp_relative_upload_path( $path ), $meta['file'] );
		$this->assertSame( array( 'medium', 'thumbnail' ), $this->sortedKeys( $meta['sizes'] ) );
		$this->assertSame( 300, $meta['sizes']['medium']['width'] );
		$this->assertSame( 200, $meta['sizes']['medium']['height'] );
		$new_sizes = $this->sizePaths( $id );
		$this->assertFileDoesNotExist( $old_sizes['medium'], 'The old 300x225 medium is deleted.' );
		$this->assertFileExists( $new_sizes['medium'] );
		$this->assertSame( $old_sizes['thumbnail'], $new_sizes['thumbnail'], 'Sentinel: the 150x150 thumbnail keeps its name.' );
		$this->assertFileExists( $new_sizes['thumbnail'] );
		$this->assertNotSame( $old_thumb, md5_file( $new_sizes['thumbnail'] ), 'The thumbnail is regenerated from the new image.' );
	}

	public function test_replace_mode_rewrites_only_the_thumbnail_whose_name_changed() {
		list( $id, , $url, $thumb, $medium ) = $this->standardImage();
		$content = '<p><img src="' . $url . '" /></p><p><img src="' . $thumb . '" /></p><p><img src="' . $medium . '" /></p>';
		$post    = $this->makePost( $content );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertStringEndsWith( '-300x200.jpg', $new_medium, 'Sentinel: the medium size got a new name.' );
		$expected = '<p><img src="' . $url . '" /></p><p><img src="' . $thumb . '" /></p><p><img src="' . $new_medium . '" /></p>';
		$this->assertSame( $expected, $this->rawContent( $post ), 'Main URL and same-named thumbnail untouched, medium rewritten.' );
	}

	/**
	 * Replacer::replace() builds the search and replace arrays keyed by size
	 * name, but str_replace() pairs them by POSITION. The search array
	 * follows the source metadata order (`medium`, `thumbnail`); a size
	 * the target lacks is filled in by findNearestSize() and APPENDED to
	 * the replace array, so the replace order becomes `thumbnail`, `medium`
	 * and every pair after the gap is shifted. Here a 400x300 image
	 * replaced by a 200x100 one (no medium size) sends the medium reference
	 * to the new thumbnail and the thumbnail reference to the main file;
	 * the same happens in search-replace mode whenever the new image lacks
	 * a size. The correct behaviour is to align the replace array to the
	 * keys of the search array before the str_replace() (for example
	 * build it by iterating the search keys). Lives in Replacer::replace(),
	 * steps 9-11.
	 *
	 * How to recognise the fix: the post holds the new 150x100 thumbnail
	 * first and the main file second, the replace keys follow the search
	 * keys, and the pinned assertions below fail.
	 */
	public function test_a_size_filled_in_by_nearest_size_shifts_the_rewrite_pairs_pinned_for_deferred_fix() {
		list( $id, , $url, $thumb, $medium ) = $this->standardImage();
		$post = $this->makePost( '<img src="' . $thumb . '" /><img src="' . $medium . '" />' );
		$this->addHook( 'emr/replacer/replace_urls', $this->record( 'replace_urls' ), 10, 3 );

		$this->replaceMode( $id, $this->tmpJpeg( 200, 100 ) );

		$meta = wp_get_attachment_metadata( $id );
		$this->assertArrayNotHasKey( 'medium', $meta['sizes'], 'Sentinel: a 200x100 image has no medium size.' );
		$new_thumb = $this->sizeUrl( $id, 'thumbnail' );
		$this->assertStringEndsWith( '-150x100.jpg', $new_thumb );
		list( $search, $replace ) = $this->events[0][1];
		$this->assertSame( wp_make_link_relative( $url ), $replace['medium'], 'Sentinel: by key, medium maps to the closest width, the 200px main file.' );
		$this->assertSame( wp_make_link_relative( $new_thumb ), $replace['thumbnail'], 'Sentinel: by key, thumbnail maps to the new thumbnail.' );
		$this->assertSame( array( 'medium', 'thumbnail' ), array_keys( $search ), 'Pinned: the search order follows the source sizes.' );
		$this->assertSame( array( 'thumbnail', 'medium' ), array_keys( $replace ), 'Pinned: the filled-in size is appended.' );
		$this->assertSame(
			'<img src="' . $url . '" /><img src="' . $new_thumb . '" />',
			$this->rawContent( $post ),
			'Pinned: the thumbnail reference goes to the main file and the medium reference to the new thumbnail.'
		);
	}

	public function test_replace_mode_with_the_same_dimensions_rewrites_nothing() {
		list( $id, , $url, $thumb, $medium ) = $this->standardImage();
		$content = '<img src="' . $url . '" srcset="' . $medium . ' 300w, ' . $thumb . ' 150w" />';
		$post    = $this->makePost( $content );
		$this->addHook( 'emr/replacer/replace_urls', $this->record( 'replace_urls' ), 10, 3 );

		$this->replaceMode( $id, $this->tmpJpeg( 400, 300, array( 0, 0, 0 ) ) );

		$this->assertSame( $content, $this->rawContent( $post ) );
		$this->assertSame( array( 'replace_urls' ), $this->eventNames() );
		$this->assertSame( array(), $this->events[0][1][0], 'Every pair was identical and dropped: nothing to search.' );
	}

	public function test_replace_mode_leaves_the_main_url_in_meta_and_options_and_rewrites_the_size() {
		list( $id, , $url, , $medium ) = $this->standardImage();
		$carrier = $this->makePost( 'carrier' );
		add_post_meta( $carrier, '_emr_flow_meta', array( 'main' => $url, 'medium' => $medium ) );
		update_option( 'emr_flow_widget', array( 'main' => $url, 'medium' => $medium ) );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertSame( array( 'main' => $url, 'medium' => $new_medium ), get_post_meta( $carrier, '_emr_flow_meta', true ) );
		$this->assertSame( array( 'main' => $url, 'medium' => $new_medium ), get_option( 'emr_flow_widget' ) );
	}

	// ---------------------------------------------------------------------
	// MODE_REPLACE, different type.
	// ---------------------------------------------------------------------

	/**
	 * Current behaviour, and what "replace" promises: the name stays. The
	 * PNG bytes are written under the old `.jpg` name, the post MIME type
	 * follows the bytes, the sizes are generated with `.jpg` names holding
	 * PNG data.
	 */
	public function test_replace_mode_with_a_png_keeps_the_jpg_name_and_stores_the_png_mime() {
		list( $id, $path, $url ) = $this->standardImage();
		$guid = get_post( $id )->guid;

		$this->replaceMode( $id, $this->tmpUpload( 'codeispoetry.png' ), 'codeispoetry.png' );

		$this->assertSame( $path, get_attached_file( $id ), 'The attached file keeps its .jpg name.' );
		$this->assertSame( $url, wp_get_attachment_url( $id ) );
		$this->assertSame( 'image/png', get_post_mime_type( $id ), 'The post MIME type follows the bytes.' );
		$this->assertSame( 'image/png', wp_get_image_mime( $path ), 'The bytes on disk are PNG.' );
		$this->assertSame( $guid, get_post( $id )->guid );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 190, $meta['width'] );
		$this->assertSame( 15, $meta['height'] );
		$this->assertStringEndsWith( '.jpg', $meta['file'] );
		$this->assertSame( array( 'thumbnail' ), array_keys( $meta['sizes'] ) );
		$this->assertStringEndsWith( '-150x15.jpg', $meta['sizes']['thumbnail']['file'], 'The size keeps the .jpg extension.' );
		$this->assertSame( 'image/png', $meta['sizes']['thumbnail']['mime-type'] );
		$size_paths = $this->sizePaths( $id );
		$this->assertSame( 'image/png', wp_get_image_mime( $size_paths['thumbnail'] ) );
	}

	public function test_replace_mode_with_a_png_rewrites_the_thumbnail_reference_to_the_new_file() {
		$id    = $this->uploadJpeg( 'emr-small-' . $this->token() . '.jpg', 200, 160 );
		$this->assertSame( array( 'thumbnail' ), array_keys( wp_get_attachment_metadata( $id )['sizes'] ), 'Sentinel: only a thumbnail.' );
		$url   = wp_get_attachment_url( $id );
		$thumb = $this->sizeUrl( $id, 'thumbnail' );
		$post  = $this->makePost( '<img src="' . $url . '" /><img src="' . $thumb . '" />' );

		$this->replaceMode( $id, $this->tmpUpload( 'codeispoetry.png' ), 'codeispoetry.png' );

		$new_thumb = $this->sizeUrl( $id, 'thumbnail' );
		$this->assertStringEndsWith( '-150x15.jpg', $new_thumb );
		$this->assertSame( '<img src="' . $url . '" /><img src="' . $new_thumb . '" />', $this->rawContent( $post ) );
	}

	// ---------------------------------------------------------------------
	// MODE_SEARCHREPLACE, new name.
	// ---------------------------------------------------------------------

	public function test_search_replace_moves_the_file_to_the_new_name_and_removes_the_old_files() {
		list( $id, $path ) = $this->standardImage();
		$old_sizes = $this->sizePaths( $id );
		$raw       = $this->rawAttachedFile( $id );
		$name      = 'emr-renamed-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_path = dirname( $path ) . '/' . $name;
		$this->assertSame( $new_path, get_attached_file( $id ) );
		$this->assertSame( dirname( $raw ) . '/' . $name, $this->rawAttachedFile( $id ), '_wp_attached_file holds the new relative path.' );
		$this->assertFileExists( $new_path );
		$this->assertFileDoesNotExist( $path, 'The old main file is gone.' );
		foreach ( $old_sizes as $size => $old ) {
			$this->assertFileDoesNotExist( $old, "The old $size file is gone." );
		}
		foreach ( $this->sizePaths( $id ) as $size => $new ) {
			$this->assertFileExists( $new, "The new $size file exists." );
			$this->assertStringStartsWith( dirname( $path ) . '/' . pathinfo( $name, PATHINFO_FILENAME ) . '-', $new );
		}
		$this->assertSame( dirname( $raw ) . '/' . $name, wp_get_attachment_metadata( $id )['file'] );
	}

	public function test_search_replace_updates_guid_title_and_slug_from_the_upload_name() {
		list( $id, $path ) = $this->standardImage();
		$token = $this->token();

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'Holiday Photo ' . $token . '.jpg' );

		$post = get_post( $id );
		$this->assertSame( dirname( $path ) . '/Holiday-Photo-' . $token . '.jpg', get_attached_file( $id ), 'The file name is sanitized.' );
		$this->assertSame( 'Holiday Photo ' . $token, $post->post_title, 'The title keeps the spaces of the upload name.' );
		$this->assertSame( 'holiday-photo-' . $token, $post->post_name );
		$this->assertSame( wp_get_attachment_url( $id ), $post->guid, 'The guid is the new URL.' );
		$this->assertSame( wp_get_attachment_url( $id ), $this->rawPostField( $id, 'guid' ), 'The guid is written to the database.' );
	}

	public function test_search_replace_takes_title_and_excerpt_from_the_iptc_metadata() {
		list( $id ) = $this->standardImage();
		$upload     = $this->makeJpegWithIptc( $this->tempDir() . '/iptc.jpg', 400, 300, 'Sunset Over The Delta', 'Taken from the ferry.' );
		$tmp        = $this->tmpUpload( $upload );

		$this->searchReplaceMode( $id, $tmp, 'emr-iptc-' . $this->token() . '.jpg' );

		$post = get_post( $id );
		$this->assertSame( 'Sunset Over The Delta', $post->post_title );
		$this->assertSame( 'sunset-over-the-delta', $post->post_name );
		$this->assertSame( 'Taken from the ferry.', $post->post_excerpt );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 'Sunset Over The Delta', $meta['image_meta']['title'] );
	}

	public function test_search_replace_title_passes_the_enable_media_replace_title_filter() {
		list( $id ) = $this->standardImage();
		$name       = 'emr-titled-' . $this->token();
		$this->addHook( 'enable_media_replace_title', $this->record( 'title' ) );
		$this->addHook(
			'enable_media_replace_title',
			function ( $title ) {
				return 'Filtered: ' . $title;
			},
			20
		);

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name . '.jpg' );

		$this->assertSame( array( array( 'title', array( $name ) ) ), $this->events );
		$this->assertSame( 'Filtered: ' . $name, get_post( $id )->post_title );
		$this->assertSame( sanitize_title( 'Filtered: ' . $name ), get_post( $id )->post_name );
	}

	public function test_search_replace_keeps_the_excerpt_when_the_new_file_has_no_caption() {
		list( $id ) = $this->standardImage();
		wp_update_post( array( 'ID' => $id, 'post_excerpt' => 'Kept caption' ) );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-nocaption-' . $this->token() . '.jpg' );

		$this->assertSame( 'Kept caption', get_post( $id )->post_excerpt );
	}

	public function test_search_replace_rewrites_classic_srcset_and_block_markup() {
		list( $id, , $url, $thumb, $medium ) = $this->standardImage();
		$classic   = '<p><img class="wp-image-' . $id . '" src="' . $url . '" srcset="' . $url . ' 400w, ' . $medium . ' 300w, ' . $thumb . ' 150w" sizes="(max-width: 400px) 100vw, 400px" /></p>';
		$gutenberg = '<!-- wp:image {"id":' . $id . ',"sizeSlug":"medium"} --><figure class="wp-block-image size-medium"><img src="' . $medium . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->'
			. '<!-- wp:cover {"url":"' . $url . '","id":' . $id . '} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-' . $id . '" src="' . $url . '"/></div><!-- /wp:cover -->';
		$post_classic = $this->makePost( $classic );
		$post_blocks  = $this->makePost( $gutenberg );
		$name         = 'emr-markup-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url    = wp_get_attachment_url( $id );
		$new_thumb  = $this->sizeUrl( $id, 'thumbnail' );
		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertStringEndsWith( '/' . $name, $new_url );
		$this->assertSame(
			'<p><img class="wp-image-' . $id . '" src="' . $new_url . '" srcset="' . $new_url . ' 400w, ' . $new_medium . ' 300w, ' . $new_thumb . ' 150w" sizes="(max-width: 400px) 100vw, 400px" /></p>',
			$this->rawContent( $post_classic )
		);
		$blocks = parse_blocks( $this->rawContent( $post_blocks ) );
		$this->assertSame( 'core/image', $blocks[0]['blockName'] );
		$this->assertSame( $id, $blocks[0]['attrs']['id'] );
		$this->assertStringContainsString( 'src="' . $new_medium . '"', $blocks[0]['innerHTML'] );
		$this->assertSame( 'core/cover', $blocks[1]['blockName'] );
		$this->assertSame( $new_url, $blocks[1]['attrs']['url'], 'The URL inside the block comment JSON is rewritten and still parses.' );
		$this->assertStringContainsString( 'src="' . $new_url . '"', $blocks[1]['innerHTML'] );
	}

	public function test_search_replace_rewrites_every_content_status_and_post_type() {
		list( $id, , $url ) = $this->standardImage();
		$content = '<img src="' . $url . '" />';
		$posts   = array(
			'publish' => $this->makePost( $content ),
			'draft'   => $this->makePost( $content, 'draft' ),
			'pending' => $this->makePost( $content, 'pending' ),
			'private' => $this->makePost( $content, 'private' ),
			'future'  => $this->makePost( $content, 'future' ),
			'page'    => $this->makePost( $content, 'publish', 'page' ),
		);

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-status-' . $this->token() . '.jpg' );

		$expected = '<img src="' . wp_get_attachment_url( $id ) . '" />';
		foreach ( $posts as $label => $post_id ) {
			$this->assertSame( $expected, $this->rawContent( $post_id ), "The $label carrier is rewritten." );
		}
	}

	public function test_search_replace_leaves_trashed_posts_and_revisions_alone() {
		list( $id, , $url ) = $this->standardImage();
		$content = '<img src="' . $url . '" />';
		$trashed = $this->makePost( $content );
		wp_trash_post( $trashed );
		$this->assertSame( 'trash', get_post_status( $trashed ), 'Sentinel: the post is trashed.' );
		$auto_draft = $this->makePost( $content, 'auto-draft' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-trash-' . $this->token() . '.jpg' );

		$this->assertSame( $content, $this->rawContent( $trashed ), 'A trashed post keeps the old URL.' );
		$this->assertSame( $content, $this->rawContent( $auto_draft ), 'An auto-draft keeps the old URL.' );
	}

	public function test_search_replace_rewrites_comment_meta_but_not_comment_content() {
		list( $id, , $url ) = $this->standardImage();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->makePost( 'commented' ),
				'comment_content' => 'Look: ' . $url,
			)
		);
		add_comment_meta( $comment, 'emr_flow_image', $url );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-comment-' . $this->token() . '.jpg' );

		$this->assertSame( wp_get_attachment_url( $id ), get_comment_meta( $comment, 'emr_flow_image', true ) );
		$this->assertSame( 'Look: ' . $url, get_comment( $comment )->comment_content, 'comment_content is not a replacer target.' );
	}

	public function test_search_replace_rewrites_a_string_postmeta() {
		list( $id, , $url ) = $this->standardImage();
		$carrier            = $this->makePost( 'carrier' );
		add_post_meta( $carrier, '_emr_flow_string', 'background: url(' . $url . ')' );
		$this->assertSame( 'background: url(' . $url . ')', get_post_meta( $carrier, '_emr_flow_string', true ), 'Sentinel: cache primed.' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-pm-string-' . $this->token() . '.jpg' );

		$this->assertSame( 'background: url(' . wp_get_attachment_url( $id ) . ')', get_post_meta( $carrier, '_emr_flow_string', true ) );
	}

	/**
	 * Two posts carrying the URL in the same meta table: both rows are
	 * rewritten and each post's meta cache is cleared, so both read the new
	 * URL through the cache after the replace (the Replacer unit suite
	 * covers the row loop of handleMetaData() itself).
	 */
	public function test_search_replace_rewrites_two_matching_postmeta_rows_and_clears_both_caches() {
		list( $id, , $url ) = $this->standardImage();
		$first              = $this->makePost( 'first carrier' );
		$second             = $this->makePost( 'second carrier' );
		$this->assertNotSame( $first, $second );
		add_post_meta( $first, '_emr_flow_twice', 'image:' . $url );
		add_post_meta( $second, '_emr_flow_twice', 'image:' . $url );
		$this->assertSame( 'image:' . $url, get_post_meta( $first, '_emr_flow_twice', true ), 'Sentinel: first cache primed.' );
		$this->assertSame( 'image:' . $url, get_post_meta( $second, '_emr_flow_twice', true ), 'Sentinel: second cache primed.' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-pm-twice-' . $this->token() . '.jpg' );

		$new_url = wp_get_attachment_url( $id );
		$this->assertNotSame( $url, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertSame( 'image:' . $new_url, $this->rawPostMeta( $first, '_emr_flow_twice' ) );
		$this->assertSame( 'image:' . $new_url, $this->rawPostMeta( $second, '_emr_flow_twice' ) );
		$this->assertSame( 'image:' . $new_url, get_post_meta( $first, '_emr_flow_twice', true ), 'The first post reads the new URL through the cache.' );
		$this->assertSame( 'image:' . $new_url, get_post_meta( $second, '_emr_flow_twice', true ), 'The second post reads the new URL through the cache.' );
	}

	public function test_search_replace_rewrites_a_nested_serialized_array_postmeta() {
		list( $id, , $url, $thumb ) = $this->standardImage();
		$carrier = $this->makePost( 'carrier' );
		add_post_meta(
			$carrier,
			'_emr_flow_array',
			array(
				'main'   => $url,
				'nested' => array(
					'src'   => $thumb,
					'deep'  => array( 'list' => array( $url, 'unrelated' ) ),
					'count' => 3,
				),
			)
		);

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-pm-array-' . $this->token() . '.jpg' );

		$new_url = wp_get_attachment_url( $id );
		$this->assertTrue( is_serialized( $this->rawPostMeta( $carrier, '_emr_flow_array' ) ), 'Still stored serialized.' );
		$this->assertSame(
			array(
				'main'   => $new_url,
				'nested' => array(
					'src'   => $this->sizeUrl( $id, 'thumbnail' ),
					'deep'  => array( 'list' => array( $new_url, 'unrelated' ) ),
					'count' => 3,
				),
			),
			get_post_meta( $carrier, '_emr_flow_array', true )
		);
	}

	/**
	 * The object-blob pattern: a serialized object (even stdClass) is never
	 * instantiated nor walked; the row is written back byte-identical.
	 */
	public function test_search_replace_leaves_a_serialized_object_postmeta_byte_identical() {
		list( $id, , $url ) = $this->standardImage();
		$carrier            = $this->makePost( 'carrier' );
		$object             = new stdClass();
		$object->url        = $url;
		$object->tag        = 'sentinel';
		add_post_meta( $carrier, '_emr_flow_object', $object );
		$raw_before = $this->rawPostMeta( $carrier, '_emr_flow_object' );
		$this->assertStringStartsWith( 'O:8:"stdClass"', $raw_before, 'Sentinel: stored as a serialized object.' );
		$this->addHook( 'emr/replacer/load_meta_value', $this->record( 'load_meta' ), 10, 3 );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-pm-object-' . $this->token() . '.jpg' );

		$this->assertSame( array( 'load_meta' ), $this->eventNames(), 'Sentinel: the replacer reached the row.' );
		$this->assertSame( $raw_before, $this->rawPostMeta( $carrier, '_emr_flow_object' ), 'Byte-identical.' );
	}

	public function test_search_replace_renames_a_url_used_as_an_array_key() {
		list( $id, , $url ) = $this->standardImage();
		$carrier            = $this->makePost( 'carrier' );
		add_post_meta( $carrier, '_emr_flow_keyed', array( $url => array( 'alt' => 'keyed' ), 'other' => 'x' ) );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-pm-key-' . $this->token() . '.jpg' );

		$this->assertSame(
			array( wp_get_attachment_url( $id ) => array( 'alt' => 'keyed' ), 'other' => 'x' ),
			get_post_meta( $carrier, '_emr_flow_keyed', true )
		);
	}

	public function test_search_replace_rewrites_a_json_postmeta_and_keeps_it_json() {
		list( $id, , $url ) = $this->standardImage();
		$carrier            = $this->makePost( 'carrier' );
		$json               = wp_json_encode( array( array( 'id' => 'abc123', 'settings' => array( 'image' => array( 'url' => $url, 'id' => $id ) ) ) ), JSON_UNESCAPED_SLASHES );
		add_post_meta( $carrier, '_emr_flow_builder', wp_slash( $json ) );
		$this->assertSame( $json, $this->rawPostMeta( $carrier, '_emr_flow_builder' ), 'Sentinel: stored as plain JSON.' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-pm-json-' . $this->token() . '.jpg' );

		$decoded = json_decode( $this->rawPostMeta( $carrier, '_emr_flow_builder' ), true );
		$this->assertIsArray( $decoded, 'Still valid JSON.' );
		$this->assertSame( wp_get_attachment_url( $id ), $decoded[0]['settings']['image']['url'] );
		$this->assertSame( $id, $decoded[0]['settings']['image']['id'] );
	}

	public function test_search_replace_rewrites_options_term_meta_and_user_meta() {
		list( $id, , $url, $thumb ) = $this->standardImage();
		update_option( 'emr_flow_autoloaded', array( 'logo' => $url ), true );
		update_option( 'emr_flow_lazy', 'logo:' . $thumb, false );
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$user = self::factory()->user->create();
		add_term_meta( $term, 'emr_flow_image', $url );
		add_user_meta( $user, 'emr_flow_avatar', $thumb );
		$this->assertSame( array( 'logo' => $url ), get_option( 'emr_flow_autoloaded' ), 'Sentinel: cache primed.' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-tables-' . $this->token() . '.jpg' );

		$new_url   = wp_get_attachment_url( $id );
		$new_thumb = $this->sizeUrl( $id, 'thumbnail' );
		$this->assertSame( array( 'logo' => $new_url ), get_option( 'emr_flow_autoloaded' ), 'The autoloaded option reads fresh.' );
		$this->assertSame( 'logo:' . $new_thumb, $this->rawOption( 'emr_flow_lazy' ), 'The non-autoloaded option is rewritten in the database.' );
		$this->assertSame( $new_url, get_term_meta( $term, 'emr_flow_image', true ) );
		$this->assertSame( $new_thumb, get_user_meta( $user, 'emr_flow_avatar', true ) );
	}

	// ---------------------------------------------------------------------
	// MODE_SEARCHREPLACE edge cases.
	// ---------------------------------------------------------------------

	public function test_search_replace_with_the_same_name_overwrites_in_place_and_leaves_references() {
		list( $id, $path, $url, $thumb ) = $this->standardImage();
		$content = '<img src="' . $url . '" /><img src="' . $thumb . '" />';
		$post    = $this->makePost( $content );
		$tmp     = $this->tmpJpeg( 400, 300, array( 5, 5, 5 ) );
		$md5     = md5_file( $tmp );

		$this->searchReplaceMode( $id, $tmp, wp_basename( $path ) );

		$this->assertSame( $path, get_attached_file( $id ) );
		$this->assertSame( $md5, md5_file( $path ) );
		$this->assertSame( $url, get_post( $id )->guid, 'The guid is (re)written with the unchanged URL.' );
		$this->assertSame( $content, $this->rawContent( $post ) );
		$this->assertSame( array(), glob( dirname( $path ) . '/' . pathinfo( $path, PATHINFO_FILENAME ) . '-1.jpg' ), 'No suffixed copy was made.' );
	}

	public function test_search_replace_onto_an_existing_name_gets_a_unique_suffix() {
		list( $id, $path, $url ) = $this->standardImage();
		$clash_name              = 'emr-clash-' . $this->token() . '.jpg';
		$other                   = $this->uploadJpeg( $clash_name, 120, 90 );
		$other_path              = get_attached_file( $other );
		$this->assertSame( dirname( $path ) . '/' . $clash_name, $other_path, 'Sentinel: the clashing file sits in the same folder.' );
		$other_md5 = md5_file( $other_path );
		$post      = $this->makePost( '<img src="' . $url . '" />' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $clash_name );

		$suffixed = dirname( $path ) . '/' . pathinfo( $clash_name, PATHINFO_FILENAME ) . '-1.jpg';
		$this->assertSame( $suffixed, get_attached_file( $id ) );
		$this->assertFileExists( $suffixed );
		$this->assertSame( $other_md5, md5_file( $other_path ), 'The other attachment is untouched.' );
		$this->assertSame( $other_path, get_attached_file( $other ) );
		$this->assertSame( '<img src="' . wp_get_attachment_url( $id ) . '" />', $this->rawContent( $post ) );
		$this->assertStringEndsWith( '-1.jpg', wp_get_attachment_url( $id ) );
		$this->assertSame( wp_get_attachment_url( $id ), get_post( $id )->guid );
	}

	public function test_search_replace_into_another_month_folder_moves_the_file_and_its_sizes() {
		list( $id, $path, $url, $thumb, $medium ) = $this->standardImage();
		$folder  = $this->freshUploadsFolder();
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] . '/' . $folder );
		$old_sizes = $this->sizePaths( $id );
		$post      = $this->makePost( '<img src="' . $url . '" srcset="' . $medium . ' 300w, ' . $thumb . ' 150w" />' );
		$name      = 'emr-moved-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name, array( 'new_location' => true, 'location_dir' => $folder ) );

		$new_path = $uploads['basedir'] . '/' . $folder . '/' . $name;
		$this->assertSame( $new_path, get_attached_file( $id ) );
		$this->assertSame( $folder . '/' . $name, $this->rawAttachedFile( $id ), '_wp_attached_file holds the new folder.' );
		$this->assertFileExists( $new_path );
		$this->assertFileDoesNotExist( $path );
		foreach ( $old_sizes as $old ) {
			$this->assertFileDoesNotExist( $old );
		}
		foreach ( $this->sizePaths( $id ) as $size => $new ) {
			$this->assertSame( $uploads['basedir'] . '/' . $folder, dirname( $new ), "The $size file is generated in the new folder." );
			$this->assertFileExists( $new );
		}
		$new_url = $uploads['baseurl'] . '/' . $folder . '/' . $name;
		$this->assertSame( $new_url, wp_get_attachment_url( $id ) );
		$this->assertSame(
			'<img src="' . $new_url . '" srcset="' . $this->sizeUrl( $id, 'medium' ) . ' 300w, ' . $this->sizeUrl( $id, 'thumbnail' ) . ' 150w" />',
			$this->rawContent( $post )
		);
		$this->assertStringContainsString( '/' . $folder . '/', $this->sizeUrl( $id, 'thumbnail' ) );
	}

	public function test_new_location_folder_is_created_when_missing() {
		list( $id ) = $this->standardImage();
		$folder     = $this->freshUploadsFolder();
		$uploads    = wp_upload_dir();
		$this->assertDirectoryDoesNotExist( $uploads['basedir'] . '/' . $folder );
		$name = 'emr-newdir-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name, array( 'new_location' => true, 'location_dir' => $folder ) );

		$this->assertDirectoryExists( $uploads['basedir'] . '/' . $folder );
		$this->assertSame( $uploads['basedir'] . '/' . $folder . '/' . $name, get_attached_file( $id ) );
	}

	/**
	 * `..` segments are dropped by normalizePath(), not resolved, so a
	 * location that climbs out of uploads lands in a folder of that name
	 * inside uploads (documented on getNewTargetLocation()).
	 */
	public function test_new_location_with_parent_segments_stays_inside_uploads() {
		list( $id ) = $this->standardImage();
		$uploads    = wp_upload_dir();
		$escape     = 'emr-escape-' . $this->token();
		$this->flowDirs[] = $uploads['basedir'] . '/' . $escape;
		$outside          = dirname( dirname( $uploads['basedir'] ) ) . '/' . $escape;
		$name             = 'emr-escaped-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name, array( 'new_location' => true, 'location_dir' => '../../' . $escape ) );

		$this->assertSame( $uploads['basedir'] . '/' . $escape . '/' . $name, get_attached_file( $id ) );
		$this->assertSame( $escape . '/' . $name, $this->rawAttachedFile( $id ) );
		$this->assertDirectoryDoesNotExist( $outside, 'Nothing is written outside uploads.' );
	}

	public function test_new_location_with_an_existing_target_is_refused_and_changes_nothing() {
		list( $id, $path, $url ) = $this->standardImage();
		$folder  = $this->freshUploadsFolder();
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] . '/' . $folder );
		$name     = 'emr-occupied-' . $this->token() . '.jpg';
		$occupant = $uploads['basedir'] . '/' . $folder . '/' . $name;
		$this->makeJpeg( $occupant, 30, 30 );
		$occupant_md5 = md5_file( $occupant );
		$source_md5   = md5_file( $path );
		$sizes        = $this->sizePaths( $id );
		$meta         = wp_get_attachment_metadata( $id );
		$raw          = $this->rawAttachedFile( $id );
		$post_before  = get_post( $id );
		$content      = '<img src="' . $url . '" />';
		$carrier      = $this->makePost( $content );
		$tmp          = $this->tmpJpeg( 400, 300 );

		$controller = new ReplaceController( $id );
		$result     = $controller->setupParams( $this->flowParams( $id, $tmp, $name, array( 'replace_type' => 'replace_and_search', 'new_location' => true, 'location_dir' => $folder ) ) );

		$this->assertFalse( $result );
		$this->assertSame( ReplaceController::ERROR_TARGET_EXISTS, $controller->returnLastError() );
		$this->assertSame( array(), $controller->returnLastErrorData() );
		$this->assertSame( $source_md5, md5_file( $path ) );
		foreach ( $sizes as $size_path ) {
			$this->assertFileExists( $size_path );
		}
		$this->assertSame( $occupant_md5, md5_file( $occupant ) );
		$this->assertFileExists( $tmp, 'The upload is not consumed.' );
		$this->assertSame( $raw, $this->rawAttachedFile( $id ) );
		$this->assertSame( $meta, wp_get_attachment_metadata( $id ) );
		$this->assertSame( $post_before->post_title, get_post( $id )->post_title );
		$this->assertSame( $post_before->guid, get_post( $id )->guid );
		$this->assertSame( $content, $this->rawContent( $carrier ) );
	}

	// ---------------------------------------------------------------------
	// -scaled originals.
	// ---------------------------------------------------------------------

	/**
	 * A 2600x200 JPEG: WordPress keeps the original and attaches a
	 * 2560x197 `-scaled` copy.
	 *
	 * @return array( id, original path, scaled path )
	 */
	private function scaledImage( $prefix = 'emr-big' ) {
		$id       = $this->uploadJpeg( $prefix . '-' . $this->token() . '.jpg', 2600, 200 );
		$scaled   = get_attached_file( $id );
		$original = wp_get_original_image_path( $id );
		$this->assertStringEndsWith( '-scaled.jpg', $scaled, 'Sentinel: WordPress attached a -scaled file.' );
		$this->assertNotSame( $scaled, $original );
		$this->assertFileExists( $original );
		return array( $id, $original, $scaled );
	}

	public function test_replace_mode_on_a_scaled_image_replaces_the_original_and_regenerates_the_scaled_file() {
		list( $id, $original, $scaled ) = $this->scaledImage();
		$tmp = $this->tmpJpeg( 2800, 220, array( 90, 10, 10 ) );
		$md5 = md5_file( $tmp );

		$this->replaceMode( $id, $tmp, 'big.jpg' );

		$this->assertSame( $md5, md5_file( $original ), 'The original receives the new bytes.' );
		$this->assertSame( $scaled, get_attached_file( $id ), 'The -scaled file is attached again.' );
		$this->assertSame( $original, wp_get_original_image_path( $id ) );
		$size = getimagesize( $scaled );
		$this->assertSame( array( 2560, 201 ), array( $size[0], $size[1] ), 'The scaled file is regenerated from the new image.' );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( wp_basename( $original ), $meta['original_image'] );
		$this->assertSame( 2560, $meta['width'] );
		$this->assertFileDoesNotExist( dirname( $original ) . '/' . pathinfo( $original, PATHINFO_FILENAME ) . '-scaled-scaled.jpg' );
	}

	public function test_replace_mode_on_a_scaled_image_keeps_the_scaled_url_and_rewrites_resized_sizes() {
		list( $id, , $scaled ) = $this->scaledImage();
		$scaled_url = wp_get_attachment_url( $id );
		$medium     = $this->sizeUrl( $id, 'medium' );
		$post       = $this->makePost( '<img src="' . $scaled_url . '" /><img src="' . $medium . '" />' );

		$this->replaceMode( $id, $this->tmpJpeg( 2800, 220 ), 'big.jpg' );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertStringEndsWith( '-300x24.jpg', $new_medium );
		$this->assertSame( '<img src="' . $scaled_url . '" /><img src="' . $new_medium . '" />', $this->rawContent( $post ) );
		$this->assertFileExists( $scaled );
	}

	/**
	 * Replacing a `-scaled` image with one below the big-image threshold in
	 * MODE_REPLACE deletes the `-scaled` file (removeCurrent()) and no new
	 * one is generated, but the references to the `-scaled` URL, which is
	 * the URL WordPress hands out for the image, are not rewritten: run()
	 * passes `thumbnails_only`, and Replacer::replace() drops both `base`
	 * (the original) and `file` (the metadata file, here the `-scaled`
	 * file) in that mode. Every post that embedded the image now points at
	 * a missing file. The correct behaviour is to treat a vanished
	 * `-scaled` file like a vanished size and rewrite it to the new main
	 * file (keep the `file` pair in thumbnails-only mode when it differs
	 * from `base`). Lives in ReplaceController::run() and
	 * Replacer::replace().
	 *
	 * How to recognise the fix: the post content holds the new main URL and
	 * the assertSame() on the old content fails.
	 */
	public function test_replace_mode_with_a_smaller_image_leaves_references_to_the_deleted_scaled_file_pinned_for_deferred_fix() {
		list( $id, $original, $scaled ) = $this->scaledImage();
		$scaled_url = wp_get_attachment_url( $id );
		$content    = '<img src="' . $scaled_url . '" />';
		$post       = $this->makePost( $content );

		$this->replaceMode( $id, $this->tmpJpeg( 400, 300 ), 'small.jpg' );

		$this->assertSame( $original, get_attached_file( $id ), 'Sentinel: no -scaled file is attached any more.' );
		$this->assertFileDoesNotExist( $scaled, 'Sentinel: the -scaled file is deleted.' );
		$this->assertArrayNotHasKey( 'original_image', wp_get_attachment_metadata( $id ) );
		$this->assertSame( $content, $this->rawContent( $post ), 'Pinned: the post still points at the deleted -scaled file.' );
	}

	public function test_search_replace_on_a_scaled_image_renames_both_files_and_rewrites_both_urls() {
		list( $id, $original, $scaled ) = $this->scaledImage();
		$original_url = wp_get_original_image_url( $id );
		$scaled_url   = wp_get_attachment_url( $id );
		$large        = $this->sizeUrl( $id, 'large' );
		$post         = $this->makePost( '<a href="' . $original_url . '"><img src="' . $scaled_url . '" srcset="' . $large . ' 1024w" /></a>' );
		$base         = 'emr-big-renamed-' . $this->token();

		$this->searchReplaceMode( $id, $this->tmpJpeg( 2800, 220 ), $base . '.jpg' );

		$dir = dirname( $original );
		$this->assertFileDoesNotExist( $original );
		$this->assertFileDoesNotExist( $scaled );
		$this->assertSame( $dir . '/' . $base . '-scaled.jpg', get_attached_file( $id ) );
		$this->assertSame( $dir . '/' . $base . '.jpg', wp_get_original_image_path( $id ) );
		$this->assertFileExists( $dir . '/' . $base . '.jpg' );
		$this->assertFileExists( $dir . '/' . $base . '-scaled.jpg' );
		$new_original_url = wp_get_original_image_url( $id );
		$this->assertSame(
			'<a href="' . $new_original_url . '"><img src="' . wp_get_attachment_url( $id ) . '" srcset="' . $this->sizeUrl( $id, 'large' ) . ' 1024w" /></a>',
			$this->rawContent( $post )
		);
		$this->assertSame( $new_original_url, get_post( $id )->guid, 'The guid is the URL of the new original.' );
	}

	// ---------------------------------------------------------------------
	// Non-image attachments.
	// ---------------------------------------------------------------------

	public function test_text_file_replace_mode_overwrites_in_place_without_sizes() {
		$id   = $this->uploadText( 'emr-notes-' . $this->token() . '.txt', "old body\n" );
		$path = get_attached_file( $id );
		$url  = wp_get_attachment_url( $id );
		$post = $this->makePost( '<a href="' . $url . '">notes</a>' );
		$tmp  = wp_tempnam( 'emr-upload' );
		file_put_contents( $tmp, "the new body\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->flowFiles[] = $tmp;

		$this->replaceMode( $id, $tmp, 'notes.txt' );

		$this->assertSame( $path, get_attached_file( $id ) );
		$this->assertSame( "the new body\n", file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( 'text/plain', get_post_mime_type( $id ) );
		if ( version_compare( get_bloginfo( 'version' ), '6.0', '>=' ) ) {
			$this->assertSame( array( 'filesize' => 13 ), wp_get_attachment_metadata( $id ) );
		} else {
			$this->assertFalse( wp_get_attachment_metadata( $id ), 'Core records no metadata for non-images before 6.0.' );
		}
		$this->assertSame( '<a href="' . $url . '">notes</a>', $this->rawContent( $post ) );
	}

	public function test_text_file_search_replace_renames_and_rewrites_the_link() {
		$id   = $this->uploadText( 'emr-doc-' . $this->token() . '.txt' );
		$path = get_attached_file( $id );
		$url  = wp_get_attachment_url( $id );
		$post = $this->makePost( '<a href="' . $url . '">doc</a>' );
		$tmp  = wp_tempnam( 'emr-upload' );
		file_put_contents( $tmp, "renamed body\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->flowFiles[] = $tmp;
		$name              = 'emr-doc-new-' . $this->token() . '.txt';

		$this->searchReplaceMode( $id, $tmp, $name );

		$this->assertSame( dirname( $path ) . '/' . $name, get_attached_file( $id ) );
		$this->assertFileDoesNotExist( $path );
		// Non-image metadata is '' on WordPress 5.9 and an array from 6.0 on.
		$this->assertArrayNotHasKey( 'sizes', (array) wp_get_attachment_metadata( $id ) );
		$this->assertSame( '<a href="' . wp_get_attachment_url( $id ) . '">doc</a>', $this->rawContent( $post ) );
		$this->assertSame( pathinfo( $name, PATHINFO_FILENAME ), get_post( $id )->post_title );
	}

	public function test_pdf_search_replace_renames_and_rewrites_the_link() {
		$dir = $this->tempDir();
		file_put_contents( $dir . '/emr-manual.pdf', $this->pdfBytes( 'old' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$id   = $this->uploadFile( $dir . '/emr-manual.pdf' );
		$path = get_attached_file( $id );
		$url  = wp_get_attachment_url( $id );
		$this->assertSame( 'application/pdf', get_post_mime_type( $id ), 'Sentinel: uploaded as PDF.' );
		$post = $this->makePost( '<!-- wp:file {"id":' . $id . ',"href":"' . $url . '"} --><div class="wp-block-file"><a href="' . $url . '">Manual</a></div><!-- /wp:file -->' );
		$tmp  = wp_tempnam( 'emr-upload' );
		file_put_contents( $tmp, $this->pdfBytes( 'new edition' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->flowFiles[] = $tmp;
		$name              = 'emr-manual-v2-' . $this->token() . '.pdf';

		$this->searchReplaceMode( $id, $tmp, $name );

		$this->assertSame( dirname( $path ) . '/' . $name, get_attached_file( $id ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( 'application/pdf', get_post_mime_type( $id ) );
		$meta = (array) wp_get_attachment_metadata( $id ); // '' on WordPress 5.9, array from 6.0.
		$this->assertArrayNotHasKey( 'sizes', $meta, 'No PDF previews with the GD editor.' );
		if ( version_compare( get_bloginfo( 'version' ), '6.0', '>=' ) ) {
			// Core records the file size in attachment metadata from 6.0 on.
			$this->assertSame( filesize( get_attached_file( $id ) ), $meta['filesize'] );
		}
		$blocks = parse_blocks( $this->rawContent( $post ) );
		$this->assertSame( wp_get_attachment_url( $id ), $blocks[0]['attrs']['href'] );
		$this->assertStringContainsString( 'href="' . wp_get_attachment_url( $id ) . '"', $blocks[0]['innerHTML'] );
	}

	/**
	 * Replacing an image with a non-image file in search-replace mode moves
	 * the attachment to the new file and deletes the image and its sizes,
	 * but rewrites no reference at all: the source side has `file` and
	 * size entries, the target metadata has neither `file` nor `width`,
	 * findNearestSize() finds no partner (the SVG rule does not apply), the
	 * search and replace arrays stay unbalanced and Replacer::replace()
	 * aborts before any query, including for the main URL (`base`), which
	 * has a valid pair. run() ignores the abort. Every post that embedded
	 * the image now points at a deleted file. The correct behaviour is to
	 * map the unpaired entries to the new main file (as the SVG rule does)
	 * or at least to run the balanced `base` pair. Lives in
	 * Replacer::replace() / findNearestSize().
	 *
	 * How to recognise the fix: the post content holds the new .txt URL and
	 * the pinned assertSame() fails.
	 */
	public function test_search_replace_from_an_image_to_a_non_image_rewrites_no_reference_pinned_for_deferred_fix() {
		list( $id, $path, $url ) = $this->standardImage();
		$content                 = '<a href="' . $url . '">see</a>';
		$post                    = $this->makePost( $content );
		$tmp                     = wp_tempnam( 'emr-upload' );
		file_put_contents( $tmp, "now a text file\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->flowFiles[] = $tmp;
		$name              = 'emr-was-image-' . $this->token() . '.txt';

		$this->searchReplaceMode( $id, $tmp, $name );

		$this->assertSame( dirname( $path ) . '/' . $name, get_attached_file( $id ), 'Sentinel: the attachment moved to the text file.' );
		$this->assertSame( 'text/plain', get_post_mime_type( $id ) );
		$this->assertFileDoesNotExist( $path, 'Sentinel: the image is deleted.' );
		$this->assertSame( $content, $this->rawContent( $post ), 'Pinned: the link still points at the deleted image.' );
	}

	// ---------------------------------------------------------------------
	// SVG target.
	// ---------------------------------------------------------------------

	public function test_search_replace_to_an_svg_points_every_size_reference_at_the_svg() {
		$this->addHook(
			'upload_mimes',
			function ( $mimes ) {
				$mimes['svg'] = 'image/svg+xml';
				return $mimes;
			}
		);
		list( $id, $path, $url, $thumb, $medium ) = $this->standardImage();
		$old_sizes = $this->sizePaths( $id );
		$post      = $this->makePost( '<img src="' . $url . '" srcset="' . $medium . ' 300w, ' . $thumb . ' 150w" />' );
		$name      = 'emr-vector-' . $this->token() . '.svg';

		$this->searchReplaceMode( $id, $this->tmpUpload( 'test-vector.svg' ), $name );

		$svg_url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '/' . $name, $svg_url );
		// ReplaceController::run() updates post_mime_type only when the target
		// FileModel reports a MIME type. wp_get_image_mime() and
		// wp_check_filetype_and_ext() detect SVG on some PHP builds (8.x here)
		// and not on others (7.4 here); where they do not, the attachment keeps
		// the replaced image's MIME type. Assert whichever this runtime does.
		$detected = emr()->filesystem()->getFile( get_attached_file( $id ) )->getMime();
		if ( 'image/svg+xml' === $detected ) {
			$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		} else {
			$this->assertSame( 'image/jpeg', get_post_mime_type( $id ), 'Without SVG detection the old MIME type stays on the attachment.' );
		}
		$this->assertArrayNotHasKey( 'sizes', (array) wp_get_attachment_metadata( $id ), 'An SVG gets no sizes.' );
		foreach ( $old_sizes as $old ) {
			$this->assertFileDoesNotExist( $old );
		}
		$this->assertSame( '<img src="' . $svg_url . '" srcset="' . $svg_url . ' 300w, ' . $svg_url . ' 150w" />', $this->rawContent( $post ) );
	}

	// ---------------------------------------------------------------------
	// Hooks and side effects.
	// ---------------------------------------------------------------------

	public function test_doreplace_false_replaces_the_file_but_rewrites_no_reference() {
		list( $id, $path, $url ) = $this->standardImage();
		$content                 = '<img src="' . $url . '" />';
		$post                    = $this->makePost( $content );
		$this->addHook( 'emr/replace/doreplace', '__return_false' );
		$name = 'emr-noreplace-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$this->assertSame( dirname( $path ) . '/' . $name, get_attached_file( $id ), 'The file is replaced.' );
		$this->assertSame( wp_get_attachment_url( $id ), get_post( $id )->guid, 'The attachment row is updated.' );
		$this->assertSame( $content, $this->rawContent( $post ), 'No reference is rewritten.' );
	}

	public function test_replace_by_another_user_records_the_replacer_and_the_edit_screen_names_them() {
		list( $id ) = $this->standardImage();
		$author     = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_update_post( array( 'ID' => $id, 'post_author' => $author ) );

		$this->replaceMode( $id, $this->tmpJpeg( 400, 300 ) );

		$this->assertSame( (string) $this->adminId, get_post_meta( $id, '_emr_replace_author', true ) );
		ob_start();
		emr()->admin_date_replaced_media_on_edit_media_screen( get_post( $id ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Replaced By', $html );
		$this->assertStringContainsString( '<b>EMR Flow Admin</b>', $html );
	}

	public function test_replace_by_the_author_removes_the_replacer_note_and_the_edit_screen_drops_it() {
		list( $id ) = $this->standardImage();
		$author     = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_update_post( array( 'ID' => $id, 'post_author' => $author ) );
		$this->replaceMode( $id, $this->tmpJpeg( 400, 300 ) );
		$this->assertSame( (string) $this->adminId, get_post_meta( $id, '_emr_replace_author', true ), 'Sentinel: the admin was recorded.' );

		wp_set_current_user( $author );
		$this->replaceMode( $id, $this->tmpJpeg( 300, 200 ) );

		$this->assertSame( array(), get_post_meta( $id, '_emr_replace_author' ) );
		ob_start();
		emr()->admin_date_replaced_media_on_edit_media_screen( get_post( $id ) );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'Replaced By', $html );
	}

	public function test_cache_flush_fires_for_the_post_and_cached_reads_are_fresh() {
		list( $id, , $url ) = $this->standardImage();
		$post               = $this->makePost( '<img src="' . $url . '" />' );
		$this->addHook( 'emr/cache/flush', $this->record( 'flush' ) );
		// Prime the object cache for both posts.
		$old_title = get_post( $id )->post_title;
		$this->assertStringContainsString( $url, get_post( $post )->post_content, 'Sentinel: cache primed with the old content.' );
		$name = 'emr-cache-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$this->assertSame( array( array( 'flush', array( $id ) ) ), $this->events );
		$fresh = get_post( $id );
		$this->assertNotSame( $old_title, $fresh->post_title );
		$this->assertSame( pathinfo( $name, PATHINFO_FILENAME ), $fresh->post_title );
		$this->assertSame( wp_get_attachment_url( $id ), $fresh->guid );
		$this->assertSame( $this->rawPostField( $id, 'post_modified' ), $fresh->post_modified );
		$this->assertSame( '<img src="' . wp_get_attachment_url( $id ) . '" />', get_post( $post )->post_content, 'The rewritten post reads fresh without a manual cache clean.' );
	}
}
