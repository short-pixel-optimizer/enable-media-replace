<?php
/**
 * Tests for EnableMediaReplace\Replacer\Classes\Finder
 * (build/shortpixel/replacer2/src/Classes/Finder.php): posts() and
 * postmeta() against real posts, their filters (post_ids,
 * exclude_post_ids, post_status, field aliases) and the callback contract.
 *
 * Every row lives in the test transaction. Every post holds a marker that
 * is unique to the test, so rows of other tests can never match.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Classes\Finder;

/**
 * Classes\Finder.
 */
class ReplacerFinderTest extends WP_UnitTestCase {

	/**
	 * Marker searched for by the test.
	 *
	 * @var string
	 */
	private $marker;

	/**
	 * Picks a fresh marker.
	 */
	public function set_up() {
		parent::set_up();
		$this->marker = 'emr-finder-' . strtolower( wp_generate_password( 8, false, false ) );
	}

	/**
	 * Creates a post holding the marker.
	 *
	 * @param string $status Post status.
	 * @param string $type   Post type.
	 * @return int
	 */
	private function makePost( $status = 'publish', $type = 'post' ) {
		return self::factory()->post->create(
			array(
				'post_status'  => $status,
				'post_type'    => $type,
				'post_content' => 'see ' . $this->marker . '.jpg',
			)
		);
	}

	/**
	 * Post IDs of result rows.
	 *
	 * @param array  $rows Rows.
	 * @param string $key  ID column.
	 * @return int[]
	 */
	private function ids( $rows, $key = 'post_id' ) {
		$ids = array_map( 'intval', wp_list_pluck( $rows, $key ) );
		sort( $ids );
		return $ids;
	}

	public function test_posts_returns_matching_posts_with_aliased_columns_and_default_statuses() {
		$published = $this->makePost( 'publish' );
		$draft     = $this->makePost( 'draft' );
		$this->makePost( 'trash' );
		self::factory()->post->create( array( 'post_content' => 'unrelated' ) );
		$finder = new Finder( array( 'base_url' => $this->marker ) );

		$rows = $finder->posts();

		$this->assertIsArray( $rows );
		$this->assertSame( array( $published, $draft ), $this->ids( $rows ) );
		$this->assertSame( array( 'post_id', 'content' ), array_keys( $rows[0] ) );
		$this->assertStringContainsString( $this->marker, $rows[0]['content'] );
	}

	public function test_posts_can_be_limited_to_post_ids_and_to_a_single_status() {
		$first   = $this->makePost();
		$this->makePost();
		$trashed = $this->makePost( 'trash' );
		$finder  = new Finder( array( 'base_url' => $this->marker ) );

		$this->assertSame( array( $first ), $this->ids( $finder->posts( array( 'post_ids' => array( $first ) ) ) ) );
		$this->assertSame( array( $trashed ), $this->ids( $finder->posts( array( 'post_status' => 'trash' ) ) ), 'A string status is accepted' );
		$this->assertCount( 2, $finder->posts( array( 'post_status' => array() ) ), 'An empty status list falls back to the defaults' );
	}

	public function test_posts_selects_custom_fields_and_unaliased_ones_keep_their_name() {
		$post_id = $this->makePost();
		$finder  = new Finder( array( 'base_url' => $this->marker ) );

		$rows = $finder->posts(
			array(
				'post_fields'  => array( 'ID', 'post_status' ),
				'post_results' => array( 'id' ),
			)
		);

		$this->assertSame( array( array( 'id' => (string) $post_id, 'post_status' => 'publish' ) ), $rows );
	}

	public function test_base_url_is_not_escaped_for_like() {
		$post_id = $this->makePost();
		$finder  = new Finder( array( 'base_url' => str_replace( 'finder', 'fin_er', $this->marker ) ) );

		$this->assertSame( array( $post_id ), $this->ids( $finder->posts() ), 'The underscore acts as a single-character wildcard' );
	}

	public function test_callback_receives_the_results_and_the_return_data() {
		$this->makePost();
		$calls  = array();
		$finder = new Finder(
			array(
				'base_url'    => $this->marker,
				'return_data' => array( 'job' => 7 ),
				'callback'    => function ( $results, $args ) use ( &$calls ) {
					$calls[] = array( $results, $args );
				},
			)
		);

		$posts = $finder->posts();
		$meta  = $finder->postmeta();

		$this->assertCount( 2, $calls, 'Called by posts() and by postmeta()' );
		$this->assertSame( $posts, $calls[0][0] );
		$this->assertSame( array( 'job' => 7 ), $calls[0][1] );
		$this->assertSame( array(), $calls[1][0] );
		$this->assertSame( array(), $meta );
	}

	public function test_non_callable_callback_is_ignored() {
		$this->makePost();
		$finder = new Finder(
			array(
				'base_url' => $this->marker,
				'callback' => 'emr_test_no_such_function',
			)
		);

		$this->assertCount( 1, $finder->posts() );
	}

	public function test_postmeta_returns_rows_of_posts_with_a_default_status() {
		$published = $this->makePost();
		$trashed   = $this->makePost( 'trash' );
		add_post_meta( $published, '_emr_finder', 'img ' . $this->marker );
		add_post_meta( $trashed, '_emr_finder', 'img ' . $this->marker );
		$finder = new Finder( array( 'base_url' => $this->marker ) );

		$rows = $finder->postmeta();

		$this->assertSame( array( array( 'post_id' => (string) $published, 'content' => 'img ' . $this->marker ) ), $rows );
	}

	public function test_postmeta_filters_by_post_ids_exclusions_and_status() {
		$first      = $this->makePost();
		$second     = $this->makePost();
		$attachment = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		foreach ( array( $first, $second, $attachment ) as $id ) {
			add_post_meta( $id, '_emr_finder', 'img ' . $this->marker );
		}
		$this->assertSame( 'inherit', get_post( $attachment )->post_status, 'Sentinel: attachments are inherit.' );
		$finder = new Finder( array( 'base_url' => $this->marker ) );

		$this->assertSame( array( $first, $second ), $this->ids( $finder->postmeta() ), 'Attachment meta is not searched by default' );
		$this->assertSame( array( $second ), $this->ids( $finder->postmeta( array( 'post_ids' => array( $second ) ) ) ) );
		$this->assertSame( array( $second ), $this->ids( $finder->postmeta( array( 'exclude_post_ids' => array( $first ) ) ) ) );
		$this->assertSame( array( $attachment ), $this->ids( $finder->postmeta( array( 'post_status' => 'inherit' ) ) ) );
	}

	public function test_postmeta_selects_qualified_custom_fields() {
		global $wpdb;
		$post_id = $this->makePost();
		$meta_id = add_post_meta( $post_id, '_emr_finder', 'img ' . $this->marker );
		$finder  = new Finder( array( 'base_url' => $this->marker ) );

		$rows = $finder->postmeta(
			array(
				'post_fields'  => array( 'meta_id', "{$wpdb->posts}.post_status" ),
				'post_results' => array( 'id', 'status' ),
			)
		);

		$this->assertSame( array( array( 'id' => (string) $meta_id, 'status' => 'publish' ) ), $rows );
	}
}
