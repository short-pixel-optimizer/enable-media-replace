<?php
/**
 * Tests for Replacer2::replace() (build/shortpixel/replacer2/src/Replacer2.php) against the
 * database: the records Classes\Finder finds in post_content, postmeta,
 * termmeta, usermeta, commentmeta and options, their replacement and the
 * writes and object cache handling of Classes\Updater, end to end with real
 * attachments and real content shapes (srcset, blocks, serialized data,
 * builder JSON).
 *
 * The single steps (finding, replacing one record, writing one record, the
 * meta filters on one record) are covered in test-ReplacerFinder.php and
 * test-ReplacerRecords.php.
 *
 * Source and target are real attachments uploaded from copies of
 * tests/fixtures/test-image.jpg. The fixture is 50x50, so WordPress makes no
 * thumbnails; the size entries the replacer works from are added to the
 * metadata handed to Setup()->search()->setMetaData() and
 * Setup()->replace()->setMetaData() (replace() reads the metadata, not the
 * files). The attachments and their files are deleted in tear_down(); every
 * row lives in the test transaction.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Replacer2;

/**
 * Replacer2::replace() against the database.
 */
class ReplacerDatabaseTest extends WP_UnitTestCase {

	/**
	 * Component of the main replace run.
	 *
	 * @var string
	 */
	const COMPONENT = 'emr';

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
	 * A replacer for the given source and target.
	 *
	 * @param string     $source      Source URL.
	 * @param string     $target      Target URL.
	 * @param array|null $source_meta Source metadata; none when null.
	 * @param array|null $target_meta Target metadata; none when null.
	 * @return Replacer2
	 */
	private function makeReplacer( $source, $target, $source_meta = null, $target_meta = null ) {
		$replacer = new Replacer2( self::COMPONENT );
		$this->assertTrue( $replacer->Setup()->search()->setUrl( $source ), 'Sentinel: the source URL is accepted.' );
		$this->assertTrue( $replacer->Setup()->replace()->setUrl( $target ), 'Sentinel: the target URL is accepted.' );
		if ( null !== $source_meta ) {
			$replacer->Setup()->search()->setMetaData( $source_meta );
		}
		if ( null !== $target_meta ) {
			$replacer->Setup()->replace()->setMetaData( $target_meta );
		}
		return $replacer;
	}

	/**
	 * A source and a target attachment and a replacer set up for them.
	 *
	 * @param string $target_prefix File name prefix of the target.
	 * @return array [Replacer2, source id, target id, source url, target url]
	 */
	private function pair( $target_prefix = 'emr-target-' ) {
		$source_id = $this->upload( 'emr-source-' . strtolower( wp_generate_password( 6, false, false ) ) . '.jpg' );
		$target_id = $this->upload( $target_prefix . strtolower( wp_generate_password( 6, false, false ) ) . '.jpg' );

		$replacer = $this->makeReplacer(
			wp_get_attachment_url( $source_id ),
			wp_get_attachment_url( $target_id ),
			$this->metaWithSizes( $source_id ),
			$this->metaWithSizes( $target_id )
		);

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
		$replacer = $this->makeReplacer( $source, $target );

		$this->assertSame( 1, $replacer->replace() );

		$this->assertSame( '<img src="' . $target . '" />', $this->rawContent( $post_id ) );
	}

	/**
	 * Only the trailing extension is stripped from the base URL, so a file
	 * name that contains its extension in the middle (`pic.jpg-edit.jpg`)
	 * keeps the inner `.jpg` in the LIKE pattern and the post is rewritten.
	 */
	public function test_file_name_with_the_extension_in_the_middle_is_rewritten() {
		$source_id = $this->upload( 'pic' . strtolower( wp_generate_password( 4, false, false ) ) . '.jpg-edit.jpg' );
		$target_id = $this->upload( 'emr-target-' . strtolower( wp_generate_password( 6, false, false ) ) . '.jpg' );
		$source    = wp_get_attachment_url( $source_id );
		$target    = wp_get_attachment_url( $target_id );
		$this->assertStringContainsString( '.jpg-edit.jpg', $source, 'Sentinel: the name has ".jpg" in the middle.' );
		$post_id  = $this->makePost( '<img src="' . $source . '" />' );
		$replacer = $this->makeReplacer( $source, $target, wp_get_attachment_metadata( $source_id ), wp_get_attachment_metadata( $target_id ) );

		$result = $replacer->replace();

		$this->assertSame( 1, $result, 'The post matched the base URL.' );
		$this->assertSame( '<img src="' . $target . '" />', $this->rawContent( $post_id ), 'The post points at the new file.' );
	}

	// ---------------------------------------------------------------------
	// Meta tables and options.
	// ---------------------------------------------------------------------

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

	/**
	 * A stdClass stored as postmeta is rewritten in place: still a serialized
	 * stdClass in the database, its URL replaced, its other properties kept,
	 * and the post's meta cache cleared so get_post_meta() follows.
	 */
	public function test_serialized_stdclass_postmeta_is_rewritten_and_stays_a_stdclass() {
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		$carrier     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$object      = new stdClass();
		$object->url = $source_url;
		$object->tag = 'sentinel';
		add_post_meta( $carrier, '_emr_test_object', $object );
		$this->assertStringStartsWith( 'O:8:"stdClass"', $this->rawPostMeta( $carrier, '_emr_test_object' ), 'Sentinel: stored as a serialized object.' );
		$this->assertSame( $source_url, get_post_meta( $carrier, '_emr_test_object', true )->url, 'Sentinel: cache primed.' );

		$result = $replacer->replace();

		$this->assertSame( 1, $result, 'The row matched' );
		$raw = $this->rawPostMeta( $carrier, '_emr_test_object' );
		$this->assertStringStartsWith( 'O:8:"stdClass"', $raw, 'Still a serialized stdClass' );
		$this->assertStringNotContainsString( $source_url, $raw, 'The old URL is gone from the row' );
		$stored = get_post_meta( $carrier, '_emr_test_object', true );
		$this->assertInstanceOf( stdClass::class, $stored );
		$this->assertSame( $target_url, $stored->url, 'The cached read follows the new URL' );
		$this->assertSame( 'sentinel', $stored->tag );
	}

	/**
	 * An option whose value is a serialized stdClass STRING (update_option()
	 * with a string from serialize(), so stored with a second serialization
	 * layer) keeps its outer layer, and the URL inside the inner object is
	 * replaced with valid lengths at both layers: get_option() returns a
	 * string that still unserializes to the object with the new URL.
	 */
	public function test_option_holding_a_serialized_object_string_keeps_its_layer_and_valid_lengths() {
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
		$this->assertStringStartsWith( 's:', $raw, 'The outer serialization layer is kept.' );
		wp_cache_delete( 'emr_test_object_blob', 'options' );
		$read = get_option( 'emr_test_object_blob' );
		$this->assertIsString( $read, 'get_option() returns a string, as before the replace.' );
		$inner = unserialize( $read );
		$this->assertInstanceOf( stdClass::class, $inner, 'The inner string still unserializes.' );
		$this->assertSame( $target_url, $inner->url, 'With the new URL.' );
	}

	/**
	 * A user meta value that is a serialized object STRING of a class other
	 * than stdClass (stored by WordPress with a second serialization layer,
	 * e.g. typed into a profile field by a low-privileged user) keeps that
	 * outer layer when the file it names is replaced. get_user_meta() still
	 * returns the string; the row never becomes a raw serialized object that
	 * WordPress core would instantiate on the next read. The inner object is
	 * not walked, so it is left byte for byte.
	 */
	public function test_user_meta_holding_a_serialized_object_string_is_never_turned_into_an_object() {
		list( $replacer, , , $source_url ) = $this->pair( 'emr-longer-target-' );
		$user_id = self::factory()->user->create();
		$class   = 'EMR_Test_Injection_Probe_';
		$this->assertFalse( class_exists( $class, false ), 'Sentinel: the probe class does not exist.' );
		$payload = 'O:' . strlen( $class ) . ':"' . $class . '":1:{s:3:"url";s:' . strlen( $source_url ) . ':"' . $source_url . '";}';
		add_user_meta( $user_id, 'emr_test_probe', $payload );
		$this->assertSame( $payload, get_user_meta( $user_id, 'emr_test_probe', true ), 'Sentinel: reads back as a string.' );

		$result = $replacer->replace();

		$this->assertSame( 1, $result, 'Sentinel: the replacer reached the row.' );
		wp_cache_delete( $user_id, 'user_meta' );
		$read = get_user_meta( $user_id, 'emr_test_probe', true );
		$this->assertIsString( $read, 'get_user_meta() returns a string, not an object.' );
		$this->assertSame( $payload, $read, 'The inner payload is left byte for byte.' );
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
	 * A non-autoloaded option is cached under its own name in the `options`
	 * group, outside `alloptions`. The Finder selects `option_name` with the
	 * row and the Updater deletes that key too, so a cached read follows the
	 * rewrite.
	 */
	public function test_non_autoloaded_option_is_rewritten_and_its_cached_read_follows() {
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		update_option( 'emr_test_lazy_widget', 'image:' . $source_url, false );
		$this->assertSame( 'image:' . $source_url, get_option( 'emr_test_lazy_widget' ), 'Sentinel: cache primed.' );
		$this->assertSame( 'image:' . $source_url, wp_cache_get( 'emr_test_lazy_widget', 'options' ), 'Sentinel: cached under its own name.' );

		$replacer->replace();

		$this->assertSame( 'image:' . $target_url, $this->rawOption( 'emr_test_lazy_widget' ), 'The database was rewritten.' );
		$this->assertFalse( wp_cache_get( 'emr_test_lazy_widget', 'options' ), 'The option\'s own cache key was deleted.' );
		$this->assertSame( 'image:' . $target_url, get_option( 'emr_test_lazy_widget' ), 'The cached read returns the new URL.' );
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
	 * owner: each Record reads the owner id (`post_id`) from its own row, so
	 * the second and later rows of a table hand their own owner id to
	 * wp_cache_delete(), no undefined-index error is raised and every primed
	 * cache reads the new URL after the replace.
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
	 * The posts and postmeta queries of Classes\Finder pass their statuses
	 * as prepared placeholders (single quotes), so they still run under the
	 * ANSI_QUOTES SQL mode (where double quotes are identifiers) and postmeta
	 * is rewritten with post_content.
	 */
	public function test_postmeta_is_rewritten_under_ansi_quotes() {
		global $wpdb;
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		$post_id = $this->makePost( 'x ' . $source_url, 'draft' );
		add_post_meta( $post_id, '_emr_test_builder', 'image:' . $source_url );

		$this->saved_sql_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$wpdb->query( "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',ANSI_QUOTES')" );
		$this->assertStringContainsString( 'ANSI_QUOTES', (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' ), 'Sentinel: ANSI_QUOTES is on.' );
		$suppressed = $wpdb->suppress_errors( true );
		$result     = $replacer->replace();
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 2, $result, 'The post and the postmeta row both matched.' );
		$this->assertStringNotContainsString( $source_url, $this->rawContent( $post_id ), 'post_content was rewritten.' );
		$this->assertSame( 'image:' . $target_url, $this->rawPostMeta( $post_id, '_emr_test_builder' ), 'postmeta was rewritten.' );
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

	/**
	 * A table added through `emr/replacer/replacement_tables` with a
	 * `cache_group` but no `main_id` has no owning object to clear: the row
	 * is rewritten, no notice or warning is raised and wp_cache_delete() is
	 * not called with a null key (WordPress would report that as an
	 * incorrect usage of WP_Object_Cache::delete, which fails the test).
	 *
	 * Notices and warnings are captured with a local error handler (see
	 * test_every_matching_postmeta_row_clears_the_meta_cache_of_its_owner()).
	 */
	public function test_cache_group_without_main_id_writes_the_row_without_a_cache_delete() {
		global $wpdb;
		list( $replacer, , , $source_url, $target_url ) = $this->pair();
		$wpdb->insert(
			$wpdb->links,
			array(
				'link_url'   => 'http://example.org/',
				'link_name'  => 'emr test',
				'link_image' => $source_url,
			)
		);
		$link_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $link_id, 'Sentinel: the link row exists.' );
		$this->addFilter(
			'emr/replacer/replacement_tables',
			function () {
				return array(
					'links' => array(
						'id'          => 'link_id',
						'value'       => 'link_image',
						'cache_group' => 'emr_test_links',
					),
				);
			}
		);

		$errors = array();
		set_error_handler(
			function ( $errno, $errstr ) use ( &$errors ) {
				if ( E_WARNING === $errno || E_NOTICE === $errno ) {
					$errors[] = $errstr;
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

		$this->assertSame( 1, $result, 'Sentinel: only the links row matched.' );
		$this->assertSame( $target_url, (string) $wpdb->get_var( $wpdb->prepare( "SELECT link_image FROM {$wpdb->links} WHERE link_id = %d", $link_id ) ), 'The row is rewritten.' );
		$this->assertSame( array(), $errors, 'No notice or warning is raised.' );
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

		$this->assertSame( self::COMPONENT . '|image:' . $target_url, $this->rawPostMeta( $page_id, '_emr_test_builder' ) );
	}

	/**
	 * Bricks-shaped builder data in postmeta and in an option: a nested
	 * slider keeps its options as a JSON string next to the image element.
	 * replaceContent() decodes every nested JSON string and encodes it back
	 * at the same level, so after the replace the untouched slider options
	 * are still JSON strings in both rows (older bundled copies handed back
	 * stdClass objects here, and Bricks fataled on every page rendering the
	 * element).
	 */
	public function test_json_strings_in_builder_data_stay_strings() {
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
		$this->assertSame( '{"type":"loop","perPage":3}', $page[0]['settings']['options'], 'The page slider options are still the JSON string.' );
		$this->assertSame( '{"type":"loop","perPage":3}', $components[0]['elements'][0]['settings']['options'], 'The component slider options are still the JSON string.' );
	}
}
