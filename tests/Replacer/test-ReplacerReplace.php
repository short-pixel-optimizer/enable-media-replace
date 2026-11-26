<?php
/**
 * Tests for Replacer2::replace() (build/shortpixel/replacer2/src/Replacer2.php) as seen from
 * the outside: the guards, the arrays Classes\Setup builds for it (the base
 * URL, dropped sizes, the nearest-size fill-in, identical pairs, the key
 * order of the replace array, extra URLs kept on the instance), the
 * `emr/replacer/replace_urls` action and the
 * `emr/replacer/custom_replace_query` runs with their per-run
 * settings.
 *
 * Setup on its own (validation errors, thumbnails_only, extra URL pairing,
 * the directory fail-safe) is covered in test-ReplacerSetup.php; single runs
 * through doReplaceRun() in test-ReplacerRecords.php.
 *
 * The arrays are observed through the `emr/replacer/replace_urls` action,
 * which receives them right before any query runs. Attachment metadata is
 * synthetic: Setup only reads its `file`, `width` and `sizes` keys.
 * Rows created for the custom runs live in the test transaction.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Replacer2;

/**
 * Replacer2::replace().
 */
class ReplacerReplaceTest extends WP_UnitTestCase {

	const SOURCE_URL = 'http://example.org/wp-content/uploads/2020/01/source.jpg';
	const TARGET_URL = 'http://example.org/wp-content/uploads/2026/10/target.jpg';
	const SOURCE_DIR = '/wp-content/uploads/2020/01/';
	const TARGET_DIR = '/wp-content/uploads/2026/10/';
	const COMPONENT  = 'emr';

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
	 * Hooks the capture.
	 */
	public function set_up() {
		parent::set_up();
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
	 * Removes the hooks.
	 */
	public function tear_down() {
		foreach ( $this->added_hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$this->added_hooks = array();
		parent::tear_down();
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
	 * @return Replacer2
	 */
	private function makeReplacer( $source = self::SOURCE_URL, $target = self::TARGET_URL, $source_meta = null, $target_meta = null ) {
		$replacer = new Replacer2( self::COMPONENT );
		$setup    = $replacer->Setup();
		if ( null !== $source ) {
			$this->assertTrue( $setup->search()->setUrl( $source ), 'Sentinel: the source URL is accepted.' );
		}
		if ( null !== $target ) {
			$this->assertTrue( $setup->replace()->setUrl( $target ), 'Sentinel: the target URL is accepted.' );
		}
		$setup->search()->setMetaData( null === $source_meta ? $this->sourceMeta() : $source_meta );
		$setup->replace()->setMetaData( null === $target_meta ? $this->targetMeta() : $target_meta );
		return $replacer;
	}

	/**
	 * Reads the private replace settings of a replacer.
	 *
	 * @param Replacer2 $replacer Instance.
	 * @return array|null
	 */
	private function settingsOf( $replacer ) {
		$prop = new ReflectionProperty( Replacer2::class, 'replace_settings' );
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
		$this->assertSame( self::COMPONENT, $this->settingsOf( $cases['neither'] )['component'], 'Returns before any run changes the settings' );
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

	public function test_base_url_removes_extension_only_from_the_end_of_the_path() {
		$replacer = $this->makeReplacer( 'http://example.org/a.jpg-dir/b.jpg', self::TARGET_URL );

		$result = $replacer->replace();

		$this->assertIsInt( $result );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( '/a.jpg-dir/b', $this->captured[0][2] );
	}

	public function test_target_sizes_absent_from_the_source_are_dropped() {
		$this->makeReplacer()->replace();

		list( , $replace ) = $this->captured[0];
		$this->assertArrayNotHasKey( 'medium_large', $replace );
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
	 * size (Classes\Setup::matchReplaceUrls()), so when the target lives in
	 * another directory the replacement points at a file that does not exist
	 * there. The correct directory is the one of the target's main file.
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

	/**
	 * str_replace() pairs the search and replace arrays by position, not by
	 * key. A size filled in by the nearest-size step is appended at the end
	 * of the target array, so when the missing size sits in the middle of the
	 * source sizes (here `medium`, between `thumbnail` and `large`) the
	 * target array is rebuilt in the key order of the source array. Without
	 * that, the source `large` file would be rewritten to the file filled in
	 * for `medium`.
	 */
	public function test_filled_in_size_is_paired_by_position_with_its_own_search_entry() {
		$target = $this->targetMeta();
		unset( $target['sizes']['medium'] );
		$target['sizes']['large'] = array( 'file' => 'target-1024x683.jpg', 'width' => 1024 );
		$source_large             = 'http://example.org' . self::SOURCE_DIR . 'source-1024x683.jpg';
		$source_medium            = 'http://example.org' . self::SOURCE_DIR . 'source-300x200.jpg';
		$post_id                  = $this->makePost( '<img src="' . $source_large . '"><img src="' . $source_medium . '">' );

		$result = $this->makeReplacer( self::SOURCE_URL, self::TARGET_URL, null, $target )->replace();

		$this->assertSame( 1, $result, 'Sentinel: the post matched.' );
		list( $search, $replace ) = $this->captured[0];
		$this->assertSame( array( 'base', 'file', 'thumbnail', 'medium', 'large' ), array_keys( $search ), 'Sentinel: medium sits between thumbnail and large in the source.' );
		$this->assertSame( array_keys( $search ), array_keys( $replace ), 'The replace array follows the key order of the search array' );
		$this->assertSame( self::TARGET_DIR . 'target-1024x683.jpg', $replace['large'] );
		$content = $this->rawContent( $post_id );
		$this->assertStringContainsString( 'http://example.org' . self::TARGET_DIR . 'target-1024x683.jpg', $content, 'The source large file is rewritten to the target large file' );
		$this->assertStringNotContainsString( $source_large, $content );
		$this->assertStringNotContainsString( $source_medium, $content, 'The source medium file is rewritten too' );
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
	 * A target without widths (a PDF or any other non-image file) has no
	 * size to pair by width, so every source entry it lacks is rewritten to
	 * the target's main file, as for an SVG. No reference is left pointing
	 * at a deleted size.
	 */
	public function test_pdf_target_without_width_replaces_every_size_with_its_main_file() {
		$pdf = 'http://example.org/wp-content/uploads/2026/10/document.pdf';

		$result = $this->makeReplacer( self::SOURCE_URL, $pdf, null, array( 'file' => '2026/10/document.pdf' ) )->replace();

		$this->assertIsInt( $result );
		list( $search, $replace ) = $this->captured[0];
		$this->assertSame( array( 'base', 'file', 'thumbnail', 'medium', 'large' ), array_keys( $search ) );
		$this->assertSame( array_keys( $search ), array_keys( $replace ) );
		$this->assertSame( '/wp-content/uploads/2026/10/document.pdf', $replace['base'] );
		$this->assertSame( '/wp-content/uploads/2026/10/document.pdf', $replace['file'] );
		foreach ( array( 'thumbnail', 'medium', 'large' ) as $size ) {
			$this->assertSame( self::SOURCE_DIR . 'document.pdf', $replace[ $size ], $size );
		}
	}

	/**
	 * Replacer2::replace() returns three types: false for missing input, an
	 * array of error strings when validation fails and an int count on
	 * success, so
	 * every caller has to type-check the result. A target whose metadata
	 * has a `width` but neither a main `file` nor a size with a file gets
	 * no nearest size, the arrays stay unbalanced and the run aborts with
	 * an array. The correct behaviour is one return type (a result object,
	 * or always the errors array with the count exposed separately).
	 *
	 * How to recognise the fix: the abort no longer returns a plain array
	 * (or the success no longer returns a plain int) and one of the
	 * assertIsArray() / assertIsInt() below fails.
	 */
	public function test_replace_returns_an_array_on_abort_and_an_int_on_success_pinned_for_deferred_fix() {
		global $wpdb;

		$before = $wpdb->num_queries;
		$abort  = $this->makeReplacer( self::SOURCE_URL, self::TARGET_URL, null, array( 'width' => 800 ) )->replace();
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

	/**
	 * Extra URLs added with Data::addUrls() are never cleared, so a second
	 * replace() on the same instance searches the extra URLs of the first
	 * one again, even after both sides are set to another file (see the
	 * @todo on Replacer2). The consumers create a new instance per
	 * replacement, so it does not show there. The correct behaviour is to
	 * clear them at the end of replace() (or through a reset method).
	 *
	 * How to recognise the fix: the second run's search array no longer
	 * holds the extra URL and the pinned assertSame() below fails.
	 */
	public function test_extra_urls_persist_across_replace_calls_on_one_instance_pinned_for_deferred_fix() {
		$replacer = $this->makeReplacer();
		$this->assertTrue( $replacer->Setup()->search()->addUrls( array( 'webp' => '/extra/first-run-old.webp' ) ), 'Sentinel: accepted.' );
		$this->assertTrue( $replacer->Setup()->replace()->addUrls( array( 'webp' => '/extra/first-run-new.webp' ) ), 'Sentinel: accepted.' );
		$first = $replacer->replace();

		$replacer->Setup()->search()->setUrl( 'http://example.org/wp-content/uploads/2020/01/second.jpg' );
		$replacer->Setup()->replace()->setUrl( 'http://example.org/wp-content/uploads/2020/01/second-new.jpg' );
		$replacer->Setup()->search()->setMetaData( array() );
		$replacer->Setup()->replace()->setMetaData( array() );
		$second = $replacer->replace();

		$this->assertIsInt( $first, 'Sentinel: the first run succeeded.' );
		$this->assertIsInt( $second, 'Sentinel: the second run succeeded.' );
		$this->assertCount( 2, $this->captured );
		$this->assertSame( '/extra/first-run-old.webp', $this->captured[0][0]['webp'], 'Sentinel: the first run used the extra URL.' );
		$this->assertSame( '/wp-content/uploads/2020/01/second.jpg', $this->captured[1][0]['base'], 'Sentinel: the second run is about another file.' );
		$this->assertArrayHasKey( 'webp', $this->captured[1][0], 'Pinned: the first run\'s extra URL is still on the search side.' );
		$this->assertSame( '/extra/first-run-old.webp', $this->captured[1][0]['webp'], 'Pinned: the first run\'s extra URL is searched again.' );
		$this->assertSame( '/extra/first-run-new.webp', $this->captured[1][1]['webp'], 'Pinned: and replaced again.' );
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

		$this->assertSame( array( self::COMPONENT, 'mine', 'unset' ), $components );
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

	/**
	 * A run with `replace_no_serialize` reads the raw meta value, a
	 * serialized array. replaceContent() serializes back every value it
	 * unserialized itself, whatever the setting says, so the
	 * `save_meta_value` callback receives a serialized string instead of
	 * the array the setting promises (Replacer2::replaceContent(), the
	 * `$was_unserialized` branch runs before the `replace_no_serialize`
	 * check; its docblock says a walked serialized value is left as it is
	 * when the setting is true). The setting only has an effect on values
	 * that arrive already unserialized, through `load_meta_value`.
	 *
	 * How to recognise the fix: the callback receives
	 * `array( 'src' => 'marker-xyz' )` and the assertSame() on the
	 * serialized string below fails.
	 */
	public function test_replace_no_serialize_run_hands_a_serialized_string_to_save_meta_value_pinned_for_deferred_fix() {
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

		$result = $this->makeReplacer()->replace();

		$this->assertSame( 1, $result, 'Sentinel: the run reached the meta row.' );
		$this->assertCount( 1, $saved, 'Sentinel: save_meta_value ran once.' );
		$this->assertIsString( $saved[0], 'Pinned: the callback gets a string, not the array' );
		$this->assertSame( serialize( array( 'src' => 'marker-xyz' ) ), $saved[0], 'Pinned: the replaced value, serialized back' );
		$this->assertSame( serialize( array( 'src' => 'marker-abc' ) ), $this->rawMeta( $post_id, '_emr_test_noserialize' ), 'The meta row was not written' );
	}

	/**
	 * The same run with a `load_meta_value` callback that hands over the
	 * unserialized value, as the Breakdance module does with its tree:
	 * replaceContent() did not unserialize it, so `replace_no_serialize`
	 * applies and `save_meta_value` receives the replaced array.
	 */
	public function test_replace_no_serialize_run_keeps_an_array_loaded_by_load_meta_value() {
		$post_id = $this->makePost( '' );
		add_post_meta( $post_id, '_emr_test_noserialize', array( 'src' => 'marker-abc' ) );
		$saved = array();
		$this->addHook(
			'emr/replacer/load_meta_value',
			function ( $content ) {
				return maybe_unserialize( $content );
			}
		);
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

		$result = $this->makeReplacer()->replace();

		$this->assertSame( 1, $result, 'Sentinel: the run reached the meta row.' );
		$this->assertSame( array( array( 'src' => 'marker-xyz' ) ), $saved );
		$this->assertSame( serialize( array( 'src' => 'marker-abc' ) ), $this->rawMeta( $post_id, '_emr_test_noserialize' ), 'The meta row was not written' );
	}
}
