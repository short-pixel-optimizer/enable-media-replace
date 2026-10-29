<?php
/**
 * Tests for Replacer::replace() (build/shortpixel/replacer2/src/Replacer.php):
 * the guards and fail-safes, the building and balancing of the search and
 * replace arrays (thumbnails_only, addURLArray(), dropped sizes, the
 * nearest-size fill-in, identical pairs), the `emr/replacer/replace_urls`
 * action and the `emr/replacer/custom_replace_query` runs with their
 * per-run settings.
 *
 * The arrays are observed through the `emr/replacer/replace_urls` action,
 * which receives them right before any query runs. Attachment metadata is
 * synthetic: replace() only reads its `file`, `width` and `sizes` keys.
 * Rows created for the custom runs live in the test transaction.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Replacer;

/**
 * Replacer::replace().
 */
class ReplacerReplaceTest extends WP_UnitTestCase {

	const SOURCE_URL = 'http://example.org/wp-content/uploads/2020/01/source.jpg';
	const TARGET_URL = 'http://example.org/wp-content/uploads/2026/10/target.jpg';
	const SOURCE_DIR = '/wp-content/uploads/2020/01/';
	const TARGET_DIR = '/wp-content/uploads/2026/10/';

	/**
	 * Calls of the replace_urls action, each [search, replace, base].
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Filters and actions added by the test, as [hook, callback, priority].
	 *
	 * @var array
	 */
	private $added_hooks = array();

	/**
	 * Shared Replacer instance before the test.
	 *
	 * @var Replacer|null
	 */
	private $saved_instance;

	/**
	 * Resets the shared instance and hooks the capture.
	 */
	public function set_up() {
		parent::set_up();
		$prop                 = $this->instanceProperty();
		$this->saved_instance = $prop->getValue();
		$prop->setValue( null, null );
		$this->captured = array();
		$this->addHook(
			'emr/replacer/replace_urls',
			function ( $search, $replace, $base ) {
				$this->captured[] = array( $search, $replace, $base );
			},
			10,
			3
		);
	}

	/**
	 * Removes the hooks and restores the shared instance.
	 */
	public function tear_down() {
		foreach ( $this->added_hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$this->added_hooks = array();
		$this->instanceProperty()->setValue( null, $this->saved_instance );
		parent::tear_down();
	}

	/**
	 * The private static $instance of Replacer, made accessible.
	 *
	 * @return ReflectionProperty
	 */
	private function instanceProperty() {
		$prop = new ReflectionProperty( Replacer::class, 'instance' );
		$prop->setAccessible( true );
		return $prop;
	}

	/**
	 * Adds a filter or action and records it for tear_down().
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 */
	private function addHook( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter( $hook, $callback, $priority, $args );
		$this->added_hooks[] = array( $hook, $callback, $priority );
	}

	/**
	 * Source metadata: main width 1200 and three sizes.
	 *
	 * @return array
	 */
	private function sourceMeta() {
		return array(
			'file'  => '2020/01/source.jpg',
			'width' => 1200,
			'sizes' => array(
				'thumbnail' => array( 'file' => 'source-150x150.jpg', 'width' => 150 ),
				'medium'    => array( 'file' => 'source-300x200.jpg', 'width' => 300 ),
				'large'     => array( 'file' => 'source-1024x683.jpg', 'width' => 1024 ),
			),
		);
	}

	/**
	 * Target metadata: main width 800, thumbnail and medium, plus a size
	 * the source does not have.
	 *
	 * @return array
	 */
	private function targetMeta() {
		return array(
			'file'  => '2026/10/target.jpg',
			'width' => 800,
			'sizes' => array(
				'thumbnail'    => array( 'file' => 'target-150x150.jpg', 'width' => 150 ),
				'medium'       => array( 'file' => 'target-300x200.jpg', 'width' => 300 ),
				'medium_large' => array( 'file' => 'target-768x512.jpg', 'width' => 768 ),
			),
		);
	}

	/**
	 * A replacer with the given source and target.
	 *
	 * @param string|null $source      Source URL.
	 * @param string|null $target      Target URL.
	 * @param array       $source_meta Source metadata.
	 * @param array       $target_meta Target metadata.
	 * @return Replacer
	 */
	private function makeReplacer( $source = self::SOURCE_URL, $target = self::TARGET_URL, $source_meta = null, $target_meta = null ) {
		$replacer = new Replacer();
		if ( null !== $source ) {
			$replacer->setSource( $source );
		}
		if ( null !== $target ) {
			$replacer->setTarget( $target );
		}
		$replacer->setSourceMeta( null === $source_meta ? $this->sourceMeta() : $source_meta );
		$replacer->setTargetMeta( null === $target_meta ? $this->targetMeta() : $target_meta );
		return $replacer;
	}

	/**
	 * Reads the private replace settings of a replacer.
	 *
	 * @param Replacer $replacer Instance.
	 * @return array|null
	 */
	private function settingsOf( $replacer ) {
		$prop = new ReflectionProperty( Replacer::class, 'replace_settings' );
		$prop->setAccessible( true );
		return $prop->getValue( $replacer );
	}

	/**
	 * Creates a draft post holding the given content.
	 *
	 * @param string $content Post content.
	 * @return int Post ID.
	 */
	private function makePost( $content ) {
		return self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => $content,
			)
		);
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
	 * Raw meta_value from the database.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string
	 */
	private function rawMeta( $post_id, $key ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ) );
	}

	// ---------------------------------------------------------------------
	// Guards and fail-safes.
	// ---------------------------------------------------------------------

	public function test_returns_false_without_source_or_target_and_runs_no_query() {
		global $wpdb;
		$cases = array(
			'neither'     => $this->makeReplacer( null, null ),
			'source only' => $this->makeReplacer( self::SOURCE_URL, null ),
			'target only' => $this->makeReplacer( null, self::TARGET_URL ),
		);

		foreach ( $cases as $label => $replacer ) {
			$before = $wpdb->num_queries;
			$result = $replacer->replace();
			$this->assertFalse( $result, $label );
			$this->assertSame( $before, $wpdb->num_queries, $label . ': no query' );
		}
		$this->assertSame( array(), $this->captured, 'The replace_urls action never fired' );
		$this->assertNull( $this->settingsOf( $cases['neither'] ), 'Returns before the settings are reset' );
	}

	public function test_source_url_path_that_is_a_directory_returns_an_errors_array() {
		global $wpdb;
		$this->assertTrue( is_dir( '/tmp' ), 'Precondition: /tmp exists in the container.' );
		$replacer = $this->makeReplacer( 'http://example.org/tmp', self::TARGET_URL );

		$before = $wpdb->num_queries;
		$result = $replacer->replace();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'seems to be a directory', $result[0] );
		$this->assertSame( $before, $wpdb->num_queries, 'No query ran' );
		$this->assertSame( array(), $this->captured );
	}

	public function test_empty_base_url_returns_an_errors_array() {
		global $wpdb;
		$replacer = $this->makeReplacer( '.jpg', self::TARGET_URL );

		$before = $wpdb->num_queries;
		$result = $replacer->replace();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'returned empty string', $result[0] );
		$this->assertSame( $before, $wpdb->num_queries, 'No query ran' );
		$this->assertSame( array(), $this->captured );
	}

	// ---------------------------------------------------------------------
	// Building and balancing the arrays.
	// ---------------------------------------------------------------------

	public function test_replace_urls_action_receives_balanced_arrays_and_the_base_url() {
		$result = $this->makeReplacer()->replace();

		$this->assertIsInt( $result );
		$this->assertCount( 1, $this->captured, 'The action fired once' );
		list( $search, $replace, $base ) = $this->captured[0];
		$this->assertSame( '/wp-content/uploads/2020/01/source', $base, 'Base URL: the source path without its extension' );
		$this->assertSame(
			array(
				'base'      => self::SOURCE_DIR . 'source.jpg',
				'file'      => self::SOURCE_DIR . 'source.jpg',
				'thumbnail' => self::SOURCE_DIR . 'source-150x150.jpg',
				'medium'    => self::SOURCE_DIR . 'source-300x200.jpg',
				'large'     => self::SOURCE_DIR . 'source-1024x683.jpg',
			),
			$search
		);
		$this->assertSame( array_keys( $search ), array_keys( $replace ), 'Every search entry has its replacement under the same key' );
		$this->assertSame( self::TARGET_DIR . 'target.jpg', $replace['base'] );
		$this->assertSame( self::TARGET_DIR . 'target-150x150.jpg', $replace['thumbnail'] );
	}

	public function test_target_sizes_absent_from_the_source_are_dropped() {
		$this->makeReplacer()->replace();

		list( , $replace ) = $this->captured[0];
		$this->assertArrayNotHasKey( 'medium_large', $replace );
	}

	public function test_thumbnails_only_drops_base_and_file_from_both_sides() {
		$this->makeReplacer()->replace( array( 'thumbnails_only' => true ) );

		list( $search, $replace ) = $this->captured[0];
		$this->assertSame( array( 'thumbnail', 'medium', 'large' ), array_keys( $search ) );
		$this->assertSame( array( 'thumbnail', 'medium', 'large' ), array_keys( $replace ) );
	}

	public function test_nearest_size_prefers_the_same_width() {
		$target                    = $this->targetMeta();
		$target['sizes']['custom'] = array( 'file' => 'target-1024x1.jpg', 'width' => 1024 );

		$this->makeReplacer( self::SOURCE_URL, self::TARGET_URL, null, $target )->replace();

		list( , $replace ) = $this->captured[0];
		$this->assertStringEndsWith( '/target-1024x1.jpg', $replace['large'], 'The target size of identical width fills the missing size' );
	}

	public function test_nearest_size_takes_the_closest_width_and_the_main_file_by_default() {
		$source                         = $this->sourceMeta();
		$source['sizes']['small_thumb'] = array( 'file' => 'source-100x100.jpg', 'width' => 100 );
		$target                         = $this->targetMeta();

		$this->makeReplacer( self::SOURCE_URL, self::TARGET_URL, $source, $target )->replace();

		list( , $replace ) = $this->captured[0];
		$this->assertStringEndsWith( '/target-150x150.jpg', $replace['small_thumb'], '100 is closest to the 150 thumbnail' );
		$this->assertStringEndsWith( '/target.jpg', $replace['large'], '1024 is closer to the 800 main file than to any target size' );
	}

	/**
	 * The filled-in target file is placed in the directory of the SOURCE
	 * size (replace(), step 9), so when the target lives in another
	 * directory the replacement points at a file that does not exist there.
	 * The correct directory is the one of the target's main file.
	 *
	 * How to recognise the fix: `large` is replaced by
	 * /wp-content/uploads/2026/10/target.jpg and the assertSame() below
	 * fails.
	 */
	public function test_nearest_size_fill_in_uses_the_source_directory_pinned_for_deferred_fix() {
		$this->makeReplacer()->replace();

		list( , $replace ) = $this->captured[0];
		$this->assertSame( self::TARGET_DIR . 'target.jpg', $replace['base'], 'Sentinel: the target lives in 2026/10.' );
		$this->assertSame( self::SOURCE_DIR . 'target.jpg', $replace['large'], 'Pinned: the filled-in file is placed in the source directory 2020/01.' );
	}

	public function test_svg_target_without_metadata_replaces_every_size_with_the_svg() {
		$svg = 'http://example.org/wp-content/uploads/2020/01/vector.svg';

		$result = $this->makeReplacer( self::SOURCE_URL, $svg, null, array() )->replace();

		$this->assertIsInt( $result );
		list( $search, $replace ) = $this->captured[0];
		$this->assertSame( array_keys( $search ), array_keys( $replace ) );
		foreach ( array( 'file', 'thumbnail', 'medium', 'large' ) as $size ) {
			$this->assertSame( self::SOURCE_DIR . 'vector.svg', $replace[ $size ], $size );
		}
	}

	/**
	 * replace() returns three types: false for missing input, an array of
	 * error strings when a fail-safe aborts and an int count on success, so
	 * every caller has to type-check the result. A PDF target without
	 * `width` gets no nearest size, the arrays stay unbalanced and the run
	 * aborts with an array. The correct behaviour is one return type (a
	 * result object, or always the errors array with the count exposed
	 * separately).
	 *
	 * How to recognise the fix: the abort no longer returns a plain array
	 * (or the success no longer returns a plain int) and one of the
	 * assertIsArray() / assertIsInt() below fails.
	 */
	public function test_replace_returns_an_array_on_abort_and_an_int_on_success_pinned_for_deferred_fix() {
		global $wpdb;
		$pdf = 'http://example.org/wp-content/uploads/2026/10/document.pdf';

		$before = $wpdb->num_queries;
		$abort  = $this->makeReplacer( self::SOURCE_URL, $pdf, null, array( 'file' => '2026/10/document.pdf' ) )->replace();
		$after  = $wpdb->num_queries;
		$ok     = $this->makeReplacer()->replace();

		$this->assertIsArray( $abort, 'Pinned: an unbalanced abort returns an array of strings' );
		$this->assertCount( 1, $abort );
		$this->assertStringContainsString( 'different amount of values', $abort[0] );
		$this->assertSame( $before, $after, 'The abort ran no query' );
		$this->assertCount( 1, $this->captured, 'Only the successful run fired the action' );
		$this->assertIsInt( $ok, 'Pinned: a successful run returns an int' );
		$this->assertSame( 0, $ok, 'Nothing in the database matches the synthetic URLs' );
	}

	public function test_identical_pairs_are_dropped_and_an_empty_search_returns_zero_without_a_query() {
		global $wpdb;
		$replacer = $this->makeReplacer( self::SOURCE_URL, self::SOURCE_URL, $this->sourceMeta(), $this->sourceMeta() );

		$before = $wpdb->num_queries;
		$result = $replacer->replace();

		$this->assertSame( 0, $result );
		$this->assertSame( $before, $wpdb->num_queries, 'No query ran' );
		$this->assertCount( 1, $this->captured, 'The action still fires, with empty arrays' );
		$this->assertSame( array(), $this->captured[0][0] );
		$this->assertSame( array(), $this->captured[0][1] );
	}

	public function test_add_url_array_pairs_are_merged_and_string_keys_override_generated_pairs() {
		$replacer = $this->makeReplacer();
		$replacer->addURLArray( array( '/extra/a.webp', 'thumbnail' => '/custom/thumb-old.jpg' ), array( '/extra/b.webp', 'thumbnail' => '/custom/thumb-new.jpg' ) );
		$replacer->addURLArray( 'not-an-array', 'not-an-array' );

		$replacer->replace();

		list( $search, $replace ) = $this->captured[0];
		$this->assertSame( '/custom/thumb-old.jpg', $search['thumbnail'] );
		$this->assertSame( '/custom/thumb-new.jpg', $replace['thumbnail'] );
		$this->assertSame( '/extra/a.webp', $search[0] );
		$this->assertSame( '/extra/b.webp', $replace[0] );
		$this->assertCount( 6, $search, 'Non-array arguments are ignored' );
	}

	/**
	 * The pairs added with addURLArray() are never cleared, so the shared
	 * instance from getInstance() reuses the pairs of the first replace() in
	 * every later replace() of the same request. The correct behaviour is to
	 * clear them at the end of replace() (or through a reset method).
	 *
	 * How to recognise the fix: the second run's search array no longer
	 * holds the extra pair and the assertSame() on it fails.
	 */
	public function test_add_url_array_pairs_persist_across_replace_calls_on_the_shared_instance_pinned_for_deferred_fix() {
		$shared = Replacer::getInstance();
		$shared->setSource( self::SOURCE_URL );
		$shared->setTarget( self::TARGET_URL );
		$shared->setSourceMeta( $this->sourceMeta() );
		$shared->setTargetMeta( $this->targetMeta() );
		$shared->addURLArray( array( '/extra/first-run-old.webp' ), array( '/extra/first-run-new.webp' ) );
		$shared->replace();

		$again = Replacer::getInstance();
		$again->setSource( 'http://example.org/wp-content/uploads/2020/01/second.jpg' );
		$again->setTarget( 'http://example.org/wp-content/uploads/2020/01/second-new.jpg' );
		$again->setSourceMeta( array() );
		$again->setTargetMeta( array() );
		$again->replace();

		$this->assertSame( $shared, $again, 'Sentinel: the same shared instance.' );
		$this->assertCount( 2, $this->captured );
		$this->assertSame( '/extra/first-run-old.webp', $this->captured[0][0][0], 'Sentinel: the first run used the extra pair.' );
		$this->assertSame( '/wp-content/uploads/2020/01/second.jpg', $this->captured[1][0]['base'], 'Sentinel: the second run is about another file.' );
		$this->assertSame( '/extra/first-run-old.webp', $this->captured[1][0][0], 'Pinned: the first run\'s extra pair is searched again.' );
	}

	// ---------------------------------------------------------------------
	// custom_replace_query runs.
	// ---------------------------------------------------------------------

	public function test_custom_replace_query_receives_the_main_arrays_and_its_runs_add_to_the_count() {
		$this->assertFalse( has_filter( 'emr/replacer/custom_replace_query' ), 'Precondition: no integration registered a run.' );
		$post_id  = $this->makePost( 'see marker-abc here' );
		$received = array();
		$this->addHook(
			'emr/replacer/custom_replace_query',
			function ( $runs, $base_url, $search, $replace ) use ( &$received ) {
				$received = array( $runs, $base_url, $search, $replace );
				$runs['mine'] = array(
					'base_url'     => 'marker-abc',
					'search_urls'  => array( 'marker-abc' ),
					'replace_urls' => array( 'marker-xyz' ),
				);
				return $runs;
			},
			10,
			4
		);

		$result = $this->makeReplacer()->replace();

		$this->assertSame( 1, $result, 'The main run matched nothing, the custom run one post' );
		$this->assertSame( array(), $received[0], 'The filter starts from an empty list' );
		$this->assertSame( '/wp-content/uploads/2020/01/source', $received[1] );
		$this->assertSame( $this->captured[0][0], $received[2] );
		$this->assertSame( $this->captured[0][1], $received[3] );
		$this->assertSame( 'see marker-xyz here', $this->rawContent( $post_id ) );
	}

	public function test_each_run_passes_its_own_component_to_the_meta_filters() {
		$post_id = $this->makePost( '' );
		add_post_meta( $post_id, '_emr_test_component', 'img ' . self::SOURCE_URL );
		$components = array();
		$this->addHook(
			'emr/replacer/load_meta_value',
			function ( $content, $row, $component ) use ( &$components ) {
				$components[] = $component;
				return null; // Leave the row as it is so every run matches it.
			},
			10,
			3
		);
		$this->addHook(
			'emr/replacer/custom_replace_query',
			function ( $runs, $base_url, $search, $replace ) {
				$runs['with_args']    = array(
					'base_url'     => $base_url,
					'search_urls'  => $search,
					'replace_urls' => $replace,
					'args'         => array( 'component' => 'mine' ),
				);
				$runs['without_args'] = array(
					'base_url'     => $base_url,
					'search_urls'  => $search,
					'replace_urls' => $replace,
				);
				return $runs;
			},
			10,
			4
		);

		$replacer = $this->makeReplacer();
		$result   = $replacer->replace();

		$this->assertSame( array( 'emr', 'mine', 'unset' ), $components );
		$this->assertSame( 3, $result, 'Each run counts the skipped row' );
		$this->assertSame( 'unset', $this->settingsOf( $replacer )['component'], 'The settings of the last run stay on the instance' );
		$this->assertSame( 'img ' . self::SOURCE_URL, $this->rawMeta( $post_id, '_emr_test_component' ) );
	}

	public function test_replacer_do_save_false_skips_the_meta_update_but_not_the_post_update() {
		$post_id = $this->makePost( 'content marker-abc' );
		add_post_meta( $post_id, '_emr_test_nosave', 'meta marker-abc' );
		$saved = array();
		$this->addHook(
			'emr/replacer/save_meta_value',
			function ( $content, $row, $component ) use ( &$saved ) {
				$saved[] = array( $content, $component );
				return $content;
			},
			10,
			3
		);
		$this->addHook(
			'emr/replacer/custom_replace_query',
			function ( $runs ) {
				$runs['nosave'] = array(
					'base_url'     => 'marker-abc',
					'search_urls'  => array( 'marker-abc' ),
					'replace_urls' => array( 'marker-xyz' ),
					'args'         => array( 'component' => 'nosave', 'replacer_do_save' => false ),
				);
				return $runs;
			}
		);

		$result = $this->makeReplacer()->replace();

		$this->assertSame( 2, $result, 'One post and one meta row matched' );
		$this->assertSame( array( array( 'meta marker-xyz', 'nosave' ) ), $saved, 'save_meta_value still gets the replaced value' );
		$this->assertSame( 'meta marker-abc', $this->rawMeta( $post_id, '_emr_test_nosave' ), 'The meta row was not written' );
		$this->assertSame( 'content marker-xyz', $this->rawContent( $post_id ), 'post_content is written regardless of replacer_do_save' );
	}

	public function test_replace_no_serialize_run_hands_the_array_to_save_meta_value() {
		$post_id = $this->makePost( '' );
		add_post_meta( $post_id, '_emr_test_noserialize', array( 'src' => 'marker-abc' ) );
		$saved = array();
		$this->addHook(
			'emr/replacer/save_meta_value',
			function ( $content ) use ( &$saved ) {
				$saved[] = $content;
				return $content;
			}
		);
		$this->addHook(
			'emr/replacer/custom_replace_query',
			function ( $runs ) {
				$runs['raw'] = array(
					'base_url'     => 'marker-abc',
					'search_urls'  => array( 'marker-abc' ),
					'replace_urls' => array( 'marker-xyz' ),
					'args'         => array(
						'component'            => 'raw',
						'replacer_do_save'     => false,
						'replace_no_serialize' => true,
					),
				);
				return $runs;
			}
		);

		$this->makeReplacer()->replace();

		$this->assertSame( array( array( 'src' => 'marker-xyz' ) ), $saved );
		$this->assertSame( serialize( array( 'src' => 'marker-abc' ) ), $this->rawMeta( $post_id, '_emr_test_noserialize' ) );
	}
}
