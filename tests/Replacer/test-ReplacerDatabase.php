<?php
/**
 * Tests for the database passes of Replacer::replace()
 * (build/shortpixel/replacer2/src/Replacer.php): doReplaceQuery() over
 * post_content and handleMetaData() over postmeta, termmeta, usermeta,
 * commentmeta and options, with their filters and their object cache
 * handling.
 *
 * Source and target are real attachments uploaded from copies of
 * tests/fixtures/test-image.jpg. The fixture is 50x50, so WordPress makes no
 * thumbnails; the size entries the replacer works from are added to the
 * metadata handed to setSourceMeta() / setTargetMeta() (replace() reads the
 * metadata, not the files). The attachments and their files are deleted in
 * tear_down(); every row lives in the test transaction.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Replacer;

/**
 * Replacer::replace() against the database.
 */
class ReplacerDatabaseTest extends WP_UnitTestCase {

	/**
	 * Attachment IDs created by the test.
	 *
	 * @var int[]
	 */
	private $attachments = array();

	/**
	 * Filters added by the test, as [hook, callback, priority].
	 *
	 * @var array
	 */
	private $added_filters = array();

	/**
	 * Session sql_mode to restore, when a test changed it.
	 *
	 * @var string|null
	 */
	private $saved_sql_mode;

	/**
	 * Deletes the attachments and their files, removes filters, restores
	 * the sql_mode.
	 */
	public function tear_down() {
		global $wpdb;
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		if ( null !== $this->saved_sql_mode ) {
			$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $this->saved_sql_mode ) );
			$this->saved_sql_mode = null;
		}
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachments = array();
		parent::tear_down();
	}

	/**
	 * Adds a filter and records it for tear_down().
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 */
	private function addFilter( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->added_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Uploads a copy of the fixture under the given file name.
	 *
	 * @param string $name File name.
	 * @return int Attachment ID.
	 */
	private function upload( $name ) {
		$dir = trailingslashit( get_temp_dir() ) . 'emr-replacer-' . wp_generate_password( 8, false, false );
		wp_mkdir_p( $dir );
		$copy = $dir . '/' . $name;
		copy( dirname( __DIR__ ) . '/fixtures/test-image.jpg', $copy );
		$id = self::factory()->attachment->create_upload_object( $copy );
		unlink( $copy );
		rmdir( $dir );
		$this->assertIsInt( $id, 'Sentinel: the fixture was uploaded.' );
		$this->attachments[] = $id;
		return $id;
	}

	/**
	 * Attachment metadata with two synthetic sizes next to the main file.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	private function metaWithSizes( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$base = pathinfo( $meta['file'], PATHINFO_FILENAME );

		$meta['sizes'] = array(
			'thumbnail' => array( 'file' => $base . '-150x150.jpg', 'width' => 150 ),
			'medium'    => array( 'file' => $base . '-300x300.jpg', 'width' => 300 ),
		);
		return $meta;
	}

	/**
	 * A source and a target attachment and a replacer set up for them.
	 *
	 * @param string $target_prefix File name prefix of the target.
	 * @return array [Replacer, source id, target id, source url, target url]
	 */
	private function pair( $target_prefix = 'emr-target-' ) {
		$source_id = $this->upload( 'emr-source-' . strtolower( wp_generate_password( 6, false, false ) ) . '.jpg' );
		$target_id = $this->upload( $target_prefix . strtolower( wp_generate_password( 6, false, false ) ) . '.jpg' );

		$replacer = new Replacer();
		$replacer->setSource( wp_get_attachment_url( $source_id ) );
		$replacer->setTarget( wp_get_attachment_url( $target_id ) );
		$replacer->setSourceMeta( $this->metaWithSizes( $source_id ) );
		$replacer->setTargetMeta( $this->metaWithSizes( $target_id ) );

		return array( $replacer, $source_id, $target_id, wp_get_attachment_url( $source_id ), wp_get_attachment_url( $target_id ) );
	}

	/**
	 * URL of a synthetic size of an attachment.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $size Size name.
	 * @return string
	 */
	private function sizeUrl( $id, $size ) {
		$meta = $this->metaWithSizes( $id );
		return trailingslashit( dirname( wp_get_attachment_url( $id ) ) ) . $meta['sizes'][ $size ]['file'];
	}

	/**
	 * Creates a post and writes its content directly (no kses).
	 *
	 * @param string $content Post content.
	 * @param string $status  Post status.
	 * @return int Post ID.
	 */
	private function makePost( $content, $status = 'publish' ) {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_status' => $status ) );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		return $post_id;
	}

	/**
	 * Raw post_content from the database.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function rawContent( $post_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
	}

	/**
	 * Raw meta_value of a postmeta row.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string
	 */
	private function rawPostMeta( $post_id, $key ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ) );
	}

	/**
	 * Raw option_value.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private function rawOption( $name ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	// ---------------------------------------------------------------------
	// post_content.
	// ---------------------------------------------------------------------

	public function test_classic_img_and_every_srcset_entry_are_rewritten_without_touching_post_modified() {
		list( $replacer, $source_id, $target_id, $source_url, $target_url ) = $this->pair();
		$content = '<img src="' . $source_url . '" srcset="' . $source_url . ' 50w, '
			. $this->sizeUrl( $source_id, 'thumbnail' ) . ' 150w, ' . $this->sizeUrl( $source_id, 'medium' ) . ' 300w" alt="" />';
		$post_id = $this->makePost( $content );
		$modified = get_post( $post_id )->post_modified;
		$revisions = count( wp_get_post_revisions( $post_id ) );

		$result = $replacer->replace();

		$after = $this->rawContent( $post_id );
		$this->assertSame( 1, $result, 'One post matched' );
		$this->assertSame(
			'<img src="' . $target_url . '" srcset="' . $target_url . ' 50w, '
			. $this->sizeUrl( $target_id, 'thumbnail' ) . ' 150w, ' . $this->sizeUrl( $target_id, 'medium' ) . ' 300w" alt="" />',
			$after
		);
		$this->assertSame( $after, get_post( $post_id )->post_content, 'The post cache was cleaned' );
		$this->assertSame( $modified, get_post( $post_id )->post_modified, 'post_modified is untouched' );
		$this->assertCount( $revisions, wp_get_post_revisions( $post_id ), 'No revision is created' );
	}

	public function test_gutenberg_block_json_comment_and_body_are_rewritten_and_still_parse() {
		list( $replacer, $source_id, , $source_url, $target_url ) = $this->pair();
		$markup  = '<!-- wp:cover {"url":"' . $source_url . '","id":' . $source_id . '} -->' . "\n"
			. '<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-' . $source_id . '" src="' . $source_url . '" /></div>' . "\n"
			. '<!-- /wp:cover -->';
		$post_id = $this->makePost( $markup, 'draft' );

		$replacer->replace();

		$blocks = array_values(
			array_filter(
				parse_blocks( $this->rawContent( $post_id ) ),
				function ( $block ) {
					return 'core/cover' === $block['blockName'];
				}
			)
		);
		$this->assertCount( 1, $blocks, 'Still exactly one parseable cover block' );
		$this->assertSame( $target_url, $blocks[0]['attrs']['url'], 'The URL in the block comment JSON is rewritten' );
		$this->assertSame( $source_id, $blocks[0]['attrs']['id'], 'The id attribute survives' );
		$this->assertStringContainsString( 'src="' . $target_url . '"', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( 'wp-image-' . $source_id, $blocks[0]['innerHTML'] );
	}

	public function test_only_posts_with_a_content_status_are_rewritten() {
		list( $replacer, , , $source_url ) = $this->pair();
		$included = array();
		foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
			$included[ $status ] = $this->makePost( 'x ' . $source_url, $status );
		}
		$future = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 'future', get_post_status( $future ), 'Sentinel: a scheduled post.' );
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_content' => 'x ' . $source_url ), array( 'ID' => $future ) );
		$included['future'] = $future;
		$trashed            = $this->makePost( 'x ' . $source_url, 'trash' );

		$result = $replacer->replace();

		$this->assertSame( 5, $result );
		foreach ( $included as $status => $post_id ) {
			$this->assertStringNotContainsString( $source_url, $this->rawContent( $post_id ), $status );
		}
		$this->assertSame( 'x ' . $source_url, $this->rawContent( $trashed ), 'A trashed post is left alone' );
	}

	/**
	 * A file whose base name equals its directory name: only the file name
	 * segment of the URL is rewritten, never the directory.
	 */
	public function test_directory_named_like_the_file_base_is_not_rewritten() {
		$source   = 'http://example.org/wp-content/uploads/emr-photo/emr-photo.jpg';
		$target   = 'http://example.org/wp-content/uploads/emr-photo/emr-basenew.jpg';
		$post_id  = $this->makePost( '<img src="' . $source . '" />' );
		$replacer = new Replacer();
		$replacer->setSource( $source );
		$replacer->setTarget( $target );

		$this->assertSame( 1, $replacer->replace() );

		$this->assertSame( '<img src="' . $target . '" />', $this->rawContent( $post_id ) );
	}

	/**
	 * The extension is removed from the base URL with str_replace(), so a
	 * file name that contains its extension in the middle
	 * (`pic.jpg-edit.jpg`) loses both: the LIKE pattern becomes
	 * `.../picXXXX-edit`, matches no row, and nothing is rewritten although
	 * the post embeds the file. The correct behaviour is to strip only the
	 * trailing extension.
	 *
	 * How to recognise the fix: replace() returns 1 and the post points at
	 * the target; the assertSame( 0, ... ) below fails.
	 */
	public function test_file_name_with_the_extension_in_the_middle_is_not_rewritten_pinned_for_deferred_fix() {
		$source_id = $this->upload( 'pic' . strtolower( wp_generate_password( 4, false, false ) ) . '.jpg-edit.jpg' );
		$target_id = $this->upload( 'emr-target-' . strtolower( wp_generate_password( 6, false, false ) ) . '.jpg' );
		$source    = wp_get_attachment_url( $source_id );
		$this->assertStringContainsString( '.jpg-edit.jpg', $source, 'Sentinel: the name has ".jpg" in the middle.' );
		$post_id  = $this->makePost( '<img src="' . $source . '" />' );
		$replacer = new Replacer();
		$replacer->setSource( $source );
		$replacer->setTarget( wp_get_attachment_url( $target_id ) );
		$replacer->setSourceMeta( wp_get_attachment_metadata( $source_id ) );
		$replacer->setTargetMeta( wp_get_attachment_metadata( $target_id ) );

		$result = $replacer->replace();

		$this->assertSame( 0, $result, 'Pinned: no row matched the mangled base URL.' );
		$this->assertSame( '<img src="' . $source . '" />', $this->rawContent( $post_id ), 'Pinned: the post still points at the old file.' );
	}

	// ---------------------------------------------------------------------
	// Meta tables and options.
	// ---------------------------------------------------------------------

	public function test_postmeta_string_is_rewritten_and_the_post_meta_cache_is_cleared() {
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		$page_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		add_post_meta( $page_id, '_emr_test_builder', 'image:' . $source_url );
		$this->assertSame( 'image:' . $source_url, get_post_meta( $page_id, '_emr_test_builder', true ), 'Sentinel: the cache is primed.' );

		$this->assertSame( 1, $replacer->replace() );

		$this->assertSame( 'image:' . $target_url, $this->rawPostMeta( $page_id, '_emr_test_builder' ) );
		$this->assertSame( 'image:' . $target_url, get_post_meta( $page_id, '_emr_test_builder', true ), 'The cached read follows' );
	}

	/**
	 * The rows handed to the meta filters carry the owner id column, which
	 * the Breakdance integration needs to load the document tree.
	 */
	public function test_meta_rows_handed_to_the_filters_carry_the_post_id() {
		list( $replacer, , , $source_url ) = $this->pair();
		$page_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		add_post_meta( $page_id, '_emr_test_builder', 'image:' . $source_url );
		$rows = array();
		$this->addFilter(
			'emr/replacer/load_meta_value',
			function ( $content, $row ) use ( &$rows ) {
				$rows['load'] = $row;
				return $content;
			},
			10,
			2
		);
		$this->addFilter(
			'emr/replacer/save_meta_value',
			function ( $content, $row ) use ( &$rows ) {
				$rows['save'] = $row;
				return $content;
			},
			10,
			2
		);

		$replacer->replace();

		$this->assertSame( array( 'meta_id', 'meta_value', 'post_id' ), array_keys( $rows['load'] ) );
		$this->assertSame( $page_id, (int) $rows['load']['post_id'] );
		$this->assertSame( $rows['load'], $rows['save'], 'Both filters get the same row' );
	}

	public function test_serialized_array_postmeta_is_rewritten_and_stays_valid() {
		list( $replacer, $source_id, $target_id, $source_url, $target_url ) = $this->pair();
		$carrier = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta(
			$carrier,
			'_emr_test_serialized',
			array(
				'widget'   => 'image',
				'settings' => array(
					'image'    => array( 'url' => $source_url, 'id' => $source_id ),
					'children' => array( array( 'src' => $this->sizeUrl( $source_id, 'medium' ) ) ),
				),
			)
		);

		$replacer->replace();

		$stored = get_post_meta( $carrier, '_emr_test_serialized', true );
		$this->assertIsArray( $stored, 'Round-trips as an array' );
		$this->assertSame( $target_url, $stored['settings']['image']['url'] );
		$this->assertSame( $this->sizeUrl( $target_id, 'medium' ), $stored['settings']['children'][0]['src'] );
		$this->assertSame( $source_id, $stored['settings']['image']['id'] );
	}

	public function test_serialized_object_postmeta_blob_is_left_untouched() {
		list( $replacer, , , $source_url ) = $this->pair();
		$carrier     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$object      = new stdClass();
		$object->url = $source_url;
		$object->tag = 'sentinel';
		add_post_meta( $carrier, '_emr_test_object', $object );
		$raw_before = $this->rawPostMeta( $carrier, '_emr_test_object' );
		$this->assertStringStartsWith( 'O:8:"stdClass"', $raw_before, 'Sentinel: stored as a serialized object.' );

		$result = $replacer->replace();

		$this->assertSame( 1, $result, 'The row matched' );
		$this->assertSame( $raw_before, $this->rawPostMeta( $carrier, '_emr_test_object' ), 'Byte-identical: no rewrite inside an object payload' );
	}

	/**
	 * An option whose value is a serialized-object STRING (update_option()
	 * with a string from serialize(), so stored with a second serialization
	 * layer) is unwrapped by replaceContent() to the inner string, the URL is
	 * replaced inside it without fixing its `s:<length>` prefix, and the
	 * inner string is written back without the outer layer. get_option() can
	 * no longer read the value. The correct behaviour is to leave the inner
	 * object payload alone, or to keep the outer layer and a valid length.
	 *
	 * How to recognise the fix: get_option() returns the stored string again
	 * (unchanged or with a correctly serialized URL) and the assertFalse()
	 * below fails.
	 */
	public function test_option_holding_a_serialized_object_string_is_corrupted_pinned_for_deferred_fix() {
		list( $replacer, , , $source_url, $target_url ) = $this->pair( 'emr-longer-target-' );
		$this->assertNotSame( strlen( $source_url ), strlen( $target_url ), 'Sentinel: the URLs differ in length.' );
		$object      = new stdClass();
		$object->url = $source_url;
		$blob        = serialize( $object );
		update_option( 'emr_test_object_blob', $blob, false );
		$this->assertSame( $blob, get_option( 'emr_test_object_blob' ), 'Sentinel: reads back before the replace.' );
		$this->assertStringStartsWith( 's:', $this->rawOption( 'emr_test_object_blob' ), 'Sentinel: stored with the outer layer.' );

		$replacer->replace();

		$raw = $this->rawOption( 'emr_test_object_blob' );
		$this->assertStringContainsString( $target_url, $raw, 'Sentinel: the replacer reached the row.' );
		$this->assertStringStartsWith( 'O:8:"stdClass"', $raw, 'Pinned: the outer serialization layer is gone.' );
		wp_cache_delete( 'emr_test_object_blob', 'options' );
		$this->assertFalse( get_option( 'emr_test_object_blob' ), 'Pinned: the value no longer unserializes.' );
	}

	public function test_autoloaded_option_is_rewritten_and_its_cached_read_follows() {
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		update_option( 'emr_test_widget', 'image:' . $source_url, true );
		$this->assertSame( 'image:' . $source_url, get_option( 'emr_test_widget' ), 'Sentinel: cache primed.' );

		$replacer->replace();

		$this->assertSame( 'image:' . $target_url, $this->rawOption( 'emr_test_widget' ) );
		$this->assertSame( 'image:' . $target_url, get_option( 'emr_test_widget' ) );
	}

	/**
	 * For options handleMetaData() only clears the `alloptions` and
	 * `notoptions` cache keys, while a non-autoloaded option is cached under
	 * its own name, so a cached read keeps returning the old URL although
	 * the database holds the new one. The correct behaviour is to also
	 * delete the option's own key in the `options` group.
	 *
	 * How to recognise the fix: get_option() returns the new URL and the
	 * pinned assertSame() below fails.
	 */
	public function test_non_autoloaded_option_keeps_the_old_url_in_the_cache_pinned_for_deferred_fix() {
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		update_option( 'emr_test_lazy_widget', 'image:' . $source_url, false );
		$this->assertSame( 'image:' . $source_url, get_option( 'emr_test_lazy_widget' ), 'Sentinel: cache primed.' );

		$replacer->replace();

		$this->assertSame( 'image:' . $target_url, $this->rawOption( 'emr_test_lazy_widget' ), 'Sentinel: the database was rewritten.' );
		$this->assertSame( 'image:' . $source_url, get_option( 'emr_test_lazy_widget' ), 'Pinned: the cached read still returns the old URL.' );
	}

	public function test_term_user_and_comment_meta_are_rewritten_and_their_caches_cleared() {
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		$term_id    = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$user_id    = self::factory()->user->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => self::factory()->post->create() ) );
		add_term_meta( $term_id, 'emr_test_image', 'image:' . $source_url );
		add_user_meta( $user_id, 'emr_test_image', 'image:' . $source_url );
		add_comment_meta( $comment_id, 'emr_test_image', 'image:' . $source_url );
		$this->assertSame( 'image:' . $source_url, get_term_meta( $term_id, 'emr_test_image', true ), 'Sentinel: term meta cached.' );
		$this->assertSame( 'image:' . $source_url, get_user_meta( $user_id, 'emr_test_image', true ), 'Sentinel: user meta cached.' );
		$this->assertSame( 'image:' . $source_url, get_comment_meta( $comment_id, 'emr_test_image', true ), 'Sentinel: comment meta cached.' );

		$result = $replacer->replace();

		$this->assertSame( 3, $result, 'One row per table matched' );
		$this->assertSame( 'image:' . $target_url, get_term_meta( $term_id, 'emr_test_image', true ) );
		$this->assertSame( 'image:' . $target_url, get_user_meta( $user_id, 'emr_test_image', true ) );
		$this->assertSame( 'image:' . $target_url, get_comment_meta( $comment_id, 'emr_test_image', true ) );
	}

	/**
	 * Every matching row of a meta table clears the meta cache of its own
	 * owner. handleMetaData() keeps the selected column name (`post_id`)
	 * apart from the value it reads from each row, so the second and later
	 * rows of a table hand their own owner id to wp_cache_delete(): no
	 * undefined-index error is raised and every primed cache reads the new
	 * URL after the replace.
	 *
	 * Notices and warnings are captured with a local error handler so that
	 * a regression fails on the captured error (the harness's own handler
	 * in tests/bootstrap.php keeps PHPUnit from converting them into
	 * exceptions).
	 */
	public function test_every_matching_postmeta_row_clears_the_meta_cache_of_its_owner() {
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		$first  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$second = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertNotSame( $first, $second );
		$this->assertGreaterThan( 1, min( $first, $second ), 'Sentinel: ids are not 0/1.' );
		add_post_meta( $first, '_emr_test_builder', 'image:' . $source_url );
		add_post_meta( $second, '_emr_test_builder', 'image:' . $source_url );
		$this->assertSame( 'image:' . $source_url, get_post_meta( $first, '_emr_test_builder', true ), 'Sentinel: first cache primed.' );
		$this->assertSame( 'image:' . $source_url, get_post_meta( $second, '_emr_test_builder', true ), 'Sentinel: second cache primed.' );

		$errors = array();
		set_error_handler(
			function ( $errno, $errstr ) use ( &$errors ) {
				if ( E_WARNING === $errno || E_NOTICE === $errno ) {
					$errors[] = array( $errno, $errstr );
					return true;
				}
				return false;
			}
		);
		try {
			$result = $replacer->replace();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 2, $result, 'Both rows matched.' );
		$this->assertSame( array(), $errors, 'No notice or warning is raised on the second row.' );
		$this->assertSame( 'image:' . $target_url, $this->rawPostMeta( $first, '_emr_test_builder' ), 'The first row is rewritten in the database.' );
		$this->assertSame( 'image:' . $target_url, $this->rawPostMeta( $second, '_emr_test_builder' ), 'The second row is rewritten in the database.' );
		$this->assertSame( 'image:' . $target_url, get_post_meta( $first, '_emr_test_builder', true ), 'The first post reads the new URL through the cache.' );
		$this->assertSame( 'image:' . $target_url, get_post_meta( $second, '_emr_test_builder', true ), 'The second post reads the new URL through the cache.' );
	}

	/**
	 * The postmeta query of handleMetaData() quotes its statuses with double
	 * quotes. Under the ANSI_QUOTES SQL mode MySQL reads them as column
	 * names, the query fails, and postmeta is silently skipped while
	 * post_content (single quotes) is still rewritten. The correct behaviour
	 * is to quote the statuses with single quotes.
	 *
	 * How to recognise the fix: the postmeta row is rewritten under
	 * ANSI_QUOTES and the pinned assertSame() below fails.
	 */
	public function test_postmeta_is_skipped_under_ansi_quotes_pinned_for_deferred_fix() {
		global $wpdb;
		list( $replacer, , , $source_url ) = $this->pair();
		$post_id = $this->makePost( 'x ' . $source_url, 'draft' );
		add_post_meta( $post_id, '_emr_test_builder', 'image:' . $source_url );

		$this->saved_sql_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$wpdb->query( "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',ANSI_QUOTES')" );
		$this->assertStringContainsString( 'ANSI_QUOTES', (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' ), 'Sentinel: ANSI_QUOTES is on.' );
		$suppressed = $wpdb->suppress_errors( true );
		$result     = $replacer->replace();
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 1, $result, 'Pinned: only the post counted, the postmeta query failed.' );
		$this->assertStringNotContainsString( $source_url, $this->rawContent( $post_id ), 'Sentinel: post_content was rewritten.' );
		$this->assertSame( 'image:' . $source_url, $this->rawPostMeta( $post_id, '_emr_test_builder' ), 'Pinned: postmeta was skipped.' );
	}

	// ---------------------------------------------------------------------
	// Meta filters.
	// ---------------------------------------------------------------------

	public function test_replacement_tables_filter_can_remove_and_add_tables() {
		global $wpdb;
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		update_option( 'emr_test_skipped', 'image:' . $source_url, false );
		$wpdb->insert(
			$wpdb->links,
			array(
				'link_url'   => 'http://example.org/',
				'link_name'  => 'emr test',
				'link_image' => $source_url,
			)
		);
		$link_id  = (int) $wpdb->insert_id;
		$received = null;
		$this->addFilter(
			'emr/replacer/replacement_tables',
			function ( $tables ) use ( &$received ) {
				$received = $tables;
				unset( $tables['options'] );
				$tables['links'] = array(
					'id'    => 'link_id',
					'value' => 'link_image',
				);
				return $tables;
			}
		);

		$result = $replacer->replace();

		$this->assertSame( array( 'postmeta', 'commentmeta', 'termmeta', 'usermeta', 'options' ), array_keys( $received ) );
		$this->assertSame( 'post_id', $received['postmeta']['main_id'] );
		$this->assertSame( 1, $result, 'Only the links row matched' );
		$this->assertSame( $target_url, (string) $wpdb->get_var( $wpdb->prepare( "SELECT link_image FROM {$wpdb->links} WHERE link_id = %d", $link_id ) ) );
		$this->assertSame( 'image:' . $source_url, $this->rawOption( 'emr_test_skipped' ), 'The options table was removed' );
	}

	public function test_load_meta_value_returning_null_skips_the_row_but_counts_it() {
		list( $replacer, , , $source_url ) = $this->pair();
		$page_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		add_post_meta( $page_id, '_emr_test_builder', 'image:' . $source_url );
		$saved = 0;
		$this->addFilter( 'emr/replacer/load_meta_value', '__return_null' );
		$this->addFilter(
			'emr/replacer/save_meta_value',
			function ( $content ) use ( &$saved ) {
				$saved++;
				return $content;
			}
		);

		$this->assertSame( 1, $replacer->replace() );

		$this->assertSame( 'image:' . $source_url, $this->rawPostMeta( $page_id, '_emr_test_builder' ) );
		$this->assertSame( 0, $saved, 'save_meta_value is not reached' );
	}

	public function test_save_meta_value_result_is_what_gets_written() {
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		$page_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		add_post_meta( $page_id, '_emr_test_builder', 'image:' . $source_url );
		$this->addFilter(
			'emr/replacer/save_meta_value',
			function ( $content, $row, $component ) {
				return $component . '|' . $content;
			},
			10,
			3
		);

		$replacer->replace();

		$this->assertSame( 'emr|image:' . $target_url, $this->rawPostMeta( $page_id, '_emr_test_builder' ) );
	}

	/**
	 * Bricks-shaped builder data in postmeta and in an option: a nested
	 * slider keeps its options as a JSON string next to the image element.
	 * After the replace the image URL follows in both rows and the untouched
	 * slider options are still the original JSON string, which Bricks
	 * passes to stripslashes().
	 */
	public function test_json_strings_in_builder_data_stay_json_strings() {
		list( $replacer, $source_id, , $source_url, $target_url ) = $this->pair();
		$elements = array(
			array(
				'id'       => 'slider1',
				'settings' => array( 'options' => '{"type":"loop","perPage":3}' ),
			),
			array(
				'id'       => 'image1',
				'settings' => array( 'image' => array( 'id' => $source_id, 'url' => $source_url ) ),
			),
		);
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_post_meta( $page_id, '_emr_test_bricks', $elements );
		update_option( 'emr_test_components', array( array( 'elements' => $elements ) ), false );

		$replacer->replace();

		wp_cache_delete( $page_id, 'post_meta' );
		wp_cache_delete( 'emr_test_components', 'options' );
		$page       = get_post_meta( $page_id, '_emr_test_bricks', true );
		$components = get_option( 'emr_test_components' );
		$this->assertSame( $target_url, $page[1]['settings']['image']['url'], 'Sentinel: the page image URL follows.' );
		$this->assertSame( $target_url, $components[0]['elements'][1]['settings']['image']['url'], 'Sentinel: the component image URL follows.' );
		$this->assertSame( '{"type":"loop","perPage":3}', $page[0]['settings']['options'], 'The page slider options are the JSON string.' );
		$this->assertSame( '{"type":"loop","perPage":3}', $components[0]['elements'][0]['settings']['options'], 'The component slider options are the JSON string.' );
	}
}
