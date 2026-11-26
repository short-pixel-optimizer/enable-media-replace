<?php
/**
 * Tests for the record flow of the replacer:
 * EnableMediaReplace\Replacer\Classes\Finder::tables() / findRecords(),
 * EnableMediaReplace\Replacer\Classes\Record,
 * EnableMediaReplace\Replacer\Classes\Updater::updateRecord() and
 * EnableMediaReplace\Replacer\Replacer2::replaceRecord() / doReplaceRun().
 *
 * Every row lives in the test transaction and holds a marker that is unique
 * to the test, so rows of other tests can never match. Hooks are restored by
 * the WP test case.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Classes\Finder;
use EnableMediaReplace\Replacer\Classes\Record;
use EnableMediaReplace\Replacer\Classes\Updater;
use EnableMediaReplace\Replacer\Replacer2;

/**
 * Finder records, Updater::updateRecord(), Replacer2 record replacement.
 */
class ReplacerRecordsTest extends WP_UnitTestCase {

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
		$this->marker = 'emr-records-' . strtolower( wp_generate_password( 8, false, false ) );
	}

	/**
	 * Creates a published post with the marker in its content and in a meta
	 * value.
	 *
	 * @param string $status Post status.
	 * @return int Post ID.
	 */
	private function makePostWithMeta( $status = 'publish' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => $status,
				'post_content' => 'see /' . $this->marker . '.jpg',
			)
		);
		add_post_meta( $post_id, 'emr_image', '/' . $this->marker . '.jpg' );
		return $post_id;
	}

	/**
	 * Puts the marker in one row of every non-post table.
	 *
	 * @return array Ids: `comment`, `term`, `user`, `option` (option name).
	 */
	private function makeOtherRows() {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$term_id    = self::factory()->term->create();
		$user_id    = self::factory()->user->create();
		$option     = 'emr_records_' . $this->marker;

		add_comment_meta( $comment_id, 'emr_image', '/' . $this->marker . '.jpg' );
		add_term_meta( $term_id, 'emr_image', '/' . $this->marker . '.jpg' );
		add_user_meta( $user_id, 'emr_image', '/' . $this->marker . '.jpg' );
		add_option( $option, '/' . $this->marker . '.jpg', '', 'no' );

		return array(
			'comment' => $comment_id,
			'term'    => $term_id,
			'user'    => $user_id,
			'option'  => $option,
		);
	}

	/**
	 * Table keys of records, sorted.
	 *
	 * @param Record[] $records Records.
	 * @return string[]
	 */
	private function tablesOf( array $records ) {
		$tables = array_map(
			function ( $record ) {
				return $record->getTable();
			},
			$records
		);
		sort( $tables );
		return $tables;
	}

	/**
	 * Invokes a protected method.
	 *
	 * @param object $object Object.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke( $object, $method, array $args ) {
		$reflection = new ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $object, $args );
	}

	public function test_tables_finds_the_other_tables_grouped_by_table_with_original_column_names() {
		$ids = $this->makeOtherRows();
		$finder = new Finder( array( 'base_url' => $this->marker ) );

		$result = $finder->tables();

		$keys = array_keys( $result );
		sort( $keys );
		$this->assertSame( array( 'commentmeta', 'options', 'termmeta', 'usermeta' ), $keys );
		$this->assertSame( array( 'option_id', 'option_value', 'option_name' ), array_keys( $result['options'][0] ) );
		$this->assertSame( $ids['option'], $result['options'][0]['option_name'] );
		$this->assertSame( array( 'umeta_id', 'meta_value', 'user_id' ), array_keys( $result['usermeta'][0] ) );
		$this->assertSame( $ids['user'], (int) $result['usermeta'][0]['user_id'] );
		$this->assertSame( $ids['comment'], (int) $result['commentmeta'][0]['comment_id'] );
		$this->assertSame( $ids['term'], (int) $result['termmeta'][0]['term_id'] );
	}

	public function test_tables_leaves_posts_and_postmeta_to_their_own_queries() {
		$this->makePostWithMeta();
		$finder = new Finder( array( 'base_url' => $this->marker ) );

		$this->assertSame( array(), $finder->tables() );
		$this->assertCount( 1, $finder->postmeta(), 'precondition: the meta row exists' );
	}

	public function test_tables_can_be_limited_to_some_tables() {
		$this->makeOtherRows();
		$finder = new Finder( array( 'base_url' => $this->marker ) );

		$result = $finder->tables( array( 'tables' => array( 'options', 'usermeta' ) ) );

		$keys = array_keys( $result );
		sort( $keys );
		$this->assertSame( array( 'options', 'usermeta' ), $keys );
	}

	public function test_tables_reads_a_filtered_table_by_table_name_and_skips_an_invalid_definition() {
		global $wpdb;
		$ids = $this->makeOtherRows();
		add_filter(
			'emr/replacer/replacement_tables',
			function () use ( $wpdb ) {
				return array(
					'custom'  => array(
						'table_name' => $wpdb->options,
						'id'         => 'option_id',
						'value'      => 'option_value',
					),
					'invalid' => array(
						'table_name' => $wpdb->options,
						'id'         => 'option_id',
						'value'      => 'option_value; DROP',
					),
				);
			}
		);
		$finder = new Finder( array( 'base_url' => $this->marker ) );

		$result = $finder->tables();
		$records = $finder->findRecords( array( 'sources' => array( 'tables' ) ) );

		$this->assertSame( array( 'custom' ), array_keys( $result ) );
		$this->assertSame( array( 'option_id', 'option_value' ), array_keys( $result['custom'][0] ) );
		$this->assertCount( 1, $records );
		$this->assertSame( 'custom', $records[0]->getTable() );
		$this->assertSame( $wpdb->options, $records[0]->getTableName() );
		$this->assertNull( $records[0]->getMainId() );
		$this->assertSame( get_option( $ids['option'] ), $records[0]->getContent() );
	}

	public function test_find_records_wraps_every_source_with_its_table_and_original_columns() {
		$post_id = $this->makePostWithMeta();
		$this->makePostWithMeta( 'trash' );
		$ids = $this->makeOtherRows();
		$finder = new Finder( array( 'base_url' => $this->marker ) );

		$records = $finder->findRecords();

		$this->assertSame( array( 'commentmeta', 'options', 'postmeta', 'posts', 'termmeta', 'usermeta' ), $this->tablesOf( $records ) );
		foreach ( $records as $record ) {
			$this->assertInstanceOf( Record::class, $record );
			$this->assertFalse( $record->isChanged() );
			if ( 'posts' === $record->getTable() ) {
				$this->assertSame( array( 'ID', 'post_content' ), array_keys( $record->getRow() ) );
				$this->assertSame( $post_id, (int) $record->getId() );
			}
			if ( 'postmeta' === $record->getTable() ) {
				$this->assertSame( array( 'meta_id', 'meta_value', 'post_id' ), array_keys( $record->getRow() ) );
				$this->assertSame( $post_id, (int) $record->getMainId() );
				$this->assertSame( 'post_meta', $record->getCacheGroup() );
			}
			if ( 'options' === $record->getTable() ) {
				$this->assertSame( $ids['option'], $record->getMainId() );
			}
		}
	}

	public function test_find_records_runs_only_the_requested_sources_and_hands_the_records_to_the_callback() {
		$this->makePostWithMeta();
		$this->makeOtherRows();
		$received = array();
		$finder = new Finder(
			array(
				'base_url'    => $this->marker,
				'return_data' => 'ctx',
				'callback'    => function ( $results, $args ) use ( &$received ) {
					$received[] = array( $results, $args );
				},
			)
		);

		$records = $finder->findRecords( array( 'sources' => array( 'postmeta' ) ) );

		$this->assertSame( array( 'postmeta' ), $this->tablesOf( $records ) );
		$this->assertCount( 1, $received, 'one callback for the whole find' );
		$this->assertSame( $records, $received[0][0] );
		$this->assertSame( 'ctx', $received[0][1] );
	}

	public function test_update_record_writes_an_option_and_clears_its_cache() {
		$ids = $this->makeOtherRows();
		$this->assertSame( '/' . $this->marker . '.jpg', get_option( $ids['option'] ), 'precondition: value cached' );
		$records = ( new Finder( array( 'base_url' => $this->marker ) ) )->findRecords( array( 'tables' => array( 'options' ), 'sources' => array( 'tables' ) ) );
		$this->assertCount( 1, $records );

		$records[0]->setContent( '/replaced.jpg' );
		$written = ( new Updater() )->updateRecord( $records[0] );

		$this->assertTrue( $written );
		$this->assertSame( '/replaced.jpg', get_option( $ids['option'] ) );
	}

	public function test_update_record_writes_post_meta_and_clears_the_post_meta_cache() {
		$post_id = $this->makePostWithMeta();
		$this->assertSame( '/' . $this->marker . '.jpg', get_post_meta( $post_id, 'emr_image', true ), 'precondition: value cached' );
		$records = ( new Finder( array( 'base_url' => $this->marker ) ) )->findRecords( array( 'sources' => array( 'postmeta' ) ) );

		$records[0]->setContent( '/replaced.jpg' );
		$written = ( new Updater() )->updateRecord( $records[0] );

		$this->assertTrue( $written );
		$this->assertSame( '/replaced.jpg', get_post_meta( $post_id, 'emr_image', true ) );
	}

	public function test_update_record_writes_post_content_directly_and_keeps_the_modified_date() {
		global $wpdb;
		$post_id = $this->makePostWithMeta();
		$wpdb->update( $wpdb->posts, array( 'post_modified' => '2001-02-03 04:05:06' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		$this->assertSame( 'see /' . $this->marker . '.jpg', get_post( $post_id )->post_content, 'precondition: value cached' );
		$records = ( new Finder( array( 'base_url' => $this->marker ) ) )->findRecords( array( 'sources' => array( 'posts' ) ) );

		$records[0]->setContent( 'see /replaced.jpg' );
		$written = ( new Updater() )->updateRecord( $records[0] );

		$this->assertTrue( $written );
		$this->assertSame( 'see /replaced.jpg', get_post( $post_id )->post_content );
		$this->assertSame( '2001-02-03 04:05:06', get_post( $post_id )->post_modified );
		$this->assertSame( array(), wp_get_post_revisions( $post_id ) );
	}

	public function test_update_record_does_not_write_unchanged_or_skipped_records() {
		$ids = $this->makeOtherRows();
		$records = ( new Finder( array( 'base_url' => $this->marker ) ) )->findRecords( array( 'tables' => array( 'options' ), 'sources' => array( 'tables' ) ) );
		$updater = new Updater();

		$unchanged = $updater->updateRecord( $records[0] );
		$records[0]->setContent( '/replaced.jpg' );
		$records[0]->skip();
		$skipped = $updater->updateRecord( $records[0] );

		$this->assertFalse( $unchanged );
		$this->assertFalse( $skipped );
		$this->assertTrue( $records[0]->isSkipped() );
		$this->assertSame( '/' . $this->marker . '.jpg', get_option( $ids['option'] ) );
	}

	public function test_update_records_counts_the_rows_written() {
		$this->makeOtherRows();
		$records = ( new Finder( array( 'base_url' => $this->marker ) ) )->findRecords( array( 'sources' => array( 'tables' ) ) );
		$this->assertCount( 4, $records, 'precondition: one row per table' );
		$records[0]->setContent( '/a.jpg' );
		$records[1]->setContent( '/b.jpg' );

		$this->assertSame( 2, ( new Updater() )->updateRecords( $records ) );
	}

	public function test_replace_record_replaces_post_content_without_the_meta_filters() {
		$this->makePostWithMeta();
		$calls = 0;
		add_filter(
			'emr/replacer/load_meta_value',
			function ( $content ) use ( &$calls ) {
				$calls++;
				return $content;
			}
		);
		$records = ( new Finder( array( 'base_url' => $this->marker ) ) )->findRecords( array( 'sources' => array( 'posts' ) ) );

		$record = ( new Replacer2( 'emr' ) )->replaceRecord( $records[0], array( '/' . $this->marker . '.jpg' ), array( '/new.jpg' ) );

		$this->assertSame( 'see /new.jpg', $record->getContent() );
		$this->assertTrue( $record->isChanged() );
		$this->assertSame( 0, $calls );
	}

	public function test_replace_record_passes_meta_through_the_load_and_save_filters() {
		$post_id = $this->makePostWithMeta();
		$seen = array();
		add_filter(
			'emr/replacer/load_meta_value',
			function ( $content, $row, $component ) use ( &$seen ) {
				$seen['load'] = array( $content, array_keys( $row ), $component );
				return $content;
			},
			10,
			3
		);
		add_filter(
			'emr/replacer/save_meta_value',
			function ( $content, $row, $component ) use ( &$seen ) {
				$seen['save'] = array( $content, (int) $row['post_id'], $component );
				return $content . '?saved';
			},
			10,
			3
		);
		$records = ( new Finder( array( 'base_url' => $this->marker ) ) )->findRecords( array( 'sources' => array( 'postmeta' ) ) );

		$record = ( new Replacer2( 'emr' ) )->replaceRecord( $records[0], array( '/' . $this->marker . '.jpg' ), array( '/new.jpg' ) );

		$this->assertSame( array( '/' . $this->marker . '.jpg', array( 'meta_id', 'meta_value', 'post_id' ), 'emr' ), $seen['load'] );
		$this->assertSame( array( '/new.jpg', $post_id, 'emr' ), $seen['save'] );
		$this->assertSame( '/new.jpg?saved', $record->getContent() );
	}

	public function test_replace_record_skips_a_record_when_the_load_filter_returns_null() {
		$this->makePostWithMeta();
		add_filter( 'emr/replacer/load_meta_value', '__return_null' );
		$records = ( new Finder( array( 'base_url' => $this->marker ) ) )->findRecords( array( 'sources' => array( 'postmeta' ) ) );

		$record = ( new Replacer2( 'emr' ) )->replaceRecord( $records[0], array( '/' . $this->marker . '.jpg' ), array( '/new.jpg' ) );

		$this->assertTrue( $record->isSkipped() );
		$this->assertFalse( $record->isChanged() );
		$this->assertSame( '/' . $this->marker . '.jpg', $record->getContent() );
	}

	public function test_replace_run_writes_every_table_and_counts_the_records_found() {
		$post_id = $this->makePostWithMeta();
		$ids = $this->makeOtherRows();
		$replacer = new Replacer2( 'emr' );
		$run = array(
			'name'         => 'emr',
			'base_url'     => $this->marker,
			'search_urls'  => array( '/' . $this->marker . '.jpg' ),
			'replace_urls' => array( '/new.jpg' ),
			'args'         => array( 'component' => 'emr' ),
		);

		$found = $this->invoke( $replacer, 'doReplaceRun', array( $run ) );

		$this->assertSame( 6, $found );
		$this->assertSame( 'see /new.jpg', get_post( $post_id )->post_content );
		$this->assertSame( '/new.jpg', get_post_meta( $post_id, 'emr_image', true ) );
		$this->assertSame( '/new.jpg', get_option( $ids['option'] ) );
		$this->assertSame( '/new.jpg', get_user_meta( $ids['user'], 'emr_image', true ) );
		$this->assertSame( '/new.jpg', get_comment_meta( $ids['comment'], 'emr_image', true ) );
		$this->assertSame( '/new.jpg', get_term_meta( $ids['term'], 'emr_image', true ) );
	}

	public function test_replace_run_without_do_save_writes_post_content_but_leaves_meta_to_the_integration() {
		$post_id = $this->makePostWithMeta();
		$replacer = new Replacer2( 'emr' );
		$run = array(
			'name'         => 'integration',
			'base_url'     => $this->marker,
			'search_urls'  => array( '/' . $this->marker . '.jpg' ),
			'replace_urls' => array( '/new.jpg' ),
			'args'         => array( 'component' => 'integration', 'replacer_do_save' => false ),
		);

		$found = $this->invoke( $replacer, 'doReplaceRun', array( $run ) );

		$this->assertSame( 2, $found );
		$this->assertSame( 'see /new.jpg', get_post( $post_id )->post_content );
		$this->assertSame( '/' . $this->marker . '.jpg', get_post_meta( $post_id, 'emr_image', true ) );
	}

	public function test_records_filter_lets_an_integration_add_records_of_its_own() {
		global $wpdb;
		$option = 'emr_records_own_' . $this->marker;
		add_option( $option, 'own /' . $this->marker . '-x.jpg', '', 'no' );
		$option_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM $wpdb->options WHERE option_name = %s", $option ) );
		$received = null;
		add_filter(
			'emr/replacer/records',
			function ( $records, $component, $run ) use ( $wpdb, $option_id, &$received ) {
				$received = array( count( $records ), $component, $run['name'] );
				$records[] = new Record(
					'own',
					array( 'table_name' => $wpdb->options, 'id' => 'option_id', 'value' => 'option_value' ),
					array( 'option_id' => $option_id, 'option_value' => 'own /OTHER.jpg' )
				);
				return $records;
			},
			10,
			3
		);
		$replacer = new Replacer2( 'emr' );
		$run = array(
			'name'         => 'emr',
			'base_url'     => 'no-row-has-this-' . $this->marker,
			'search_urls'  => array( '/OTHER.jpg' ),
			'replace_urls' => array( '/new.jpg' ),
			'args'         => array( 'component' => 'emr' ),
		);

		$found = $this->invoke( $replacer, 'doReplaceRun', array( $run ) );

		$this->assertSame( array( 0, 'emr', 'emr' ), $received );
		$this->assertSame( 1, $found );
		wp_cache_delete( $option, 'options' );
		$this->assertSame( 'own /new.jpg', get_option( $option ) );
	}

	/**
	 * A JSON `\u00XX` escape of one character, written out as text.
	 *
	 * @param string $char Single character, e.g. `<`.
	 * @return string The six-character escape, e.g. backslash-u-003c.
	 */
	private function jsonEscape( $char ) {
		return '\\u00' . bin2hex( $char );
	}

	/**
	 * Post content that is a JSON string holding escaped markup is inert
	 * text (kses leaves the escapes alone). A replace run rewrites the URL in
	 * it but keeps `<` and `>` escaped, so the stored content does not become
	 * a live tag; the JSON value is the same apart from the URL.
	 */
	public function test_replace_run_keeps_escaped_markup_in_json_post_content_escaped() {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$content = '"' . $this->jsonEscape( '<' ) . 'img src=x onerror=alert(document.domain)' . $this->jsonEscape( '>' ) . ' /' . $this->marker . '.jpg"';
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		$this->assertStringNotContainsString( '<', get_post( $post_id )->post_content, 'Sentinel: the stored content holds no literal tag.' );
		$run = array(
			'name'         => 'emr',
			'base_url'     => $this->marker,
			'search_urls'  => array( '/' . $this->marker . '.jpg' ),
			'replace_urls' => array( '/new.jpg' ),
			'args'         => array( 'component' => 'emr' ),
		);

		$this->invoke( new Replacer2( 'emr' ), 'doReplaceRun', array( $run ) );

		$stored = get_post( $post_id )->post_content;
		$this->assertStringContainsString( '/new.jpg', $stored, 'The URL is rewritten' );
		$this->assertStringNotContainsString( '<', $stored, 'No live tag in the stored content' );
		$this->assertStringNotContainsString( '>', $stored );
		$this->assertSame( chr( 60 ) . 'img src=x onerror=alert(document.domain)' . chr( 62 ) . ' /new.jpg', json_decode( $stored ), 'Same JSON value apart from the URL' );
	}

	/**
	 * Every character that could start markup, an entity or an attribute
	 * break-out stays `\u` escaped in re-encoded JSON, at the top level and
	 * below it, while the decoded value keeps them.
	 */
	public function test_re_encoded_json_keeps_markup_characters_escaped() {
		$chars = array( chr( 60 ), chr( 62 ), chr( 38 ), chr( 39 ), chr( 34 ) );
		$html  = implode( ' ', $chars );
		$json  = wp_json_encode( array( 'html' => $html, 'src' => '/old.jpg' ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		foreach ( array( false, true ) as $in_deep ) {
			$result = ( new Replacer2( 'emr' ) )->replaceContent( $json, array( '/old.jpg' ), array( '/new.jpg' ), $in_deep );

			foreach ( array( chr( 60 ), chr( 62 ), chr( 38 ), chr( 39 ) ) as $char ) {
				$this->assertStringNotContainsString( $char, $result, 'No literal ' . $char );
			}
			$this->assertStringContainsString( $this->jsonEscape( chr( 34 ) ), $result, 'Quotes in values are escaped too' );
			$this->assertSame( $html, json_decode( $result )->html );
			$this->assertSame( '/new.jpg', json_decode( $result )->src );
		}
	}

	/**
	 * The HEX flags are added to the run's `json_flags`, so a run that
	 * passes its own flags (or none) cannot switch them off.
	 */
	public function test_json_safe_flags_cannot_be_switched_off_by_the_run_flags() {
		$replacer = new Replacer2( 'emr' );
		$this->invoke( $replacer, 'setReplaceSettings', array( array( 'json_flags' => 0 ) ) );
		$json = '{"html":"' . $this->jsonEscape( chr( 60 ) ) . 'b' . $this->jsonEscape( chr( 62 ) ) . '","src":"/old.jpg"}';

		$result = $replacer->replaceContent( $json, array( '/old.jpg' ), array( '/new.jpg' ) );

		$this->assertStringNotContainsString( chr( 60 ), $result );
		$this->assertStringContainsString( '\\/new.jpg', $result, 'The run flags still apply (escaped slashes)' );
		$this->assertSame( chr( 60 ) . 'b' . chr( 62 ), json_decode( $result )->html );
	}

	/**
	 * JSON nested in a serialized value (builder data in meta) gets the same
	 * escaping, and the serialized lengths stay valid.
	 */
	public function test_json_inside_a_serialized_value_keeps_markup_characters_escaped() {
		$script     = chr( 60 ) . 'script' . chr( 62 ) . 'x' . chr( 60 ) . '/script' . chr( 62 );
		$serialized = serialize( array( 'data' => wp_json_encode( array( 'html' => $script, 'src' => '/old.jpg' ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) ) );

		$result = ( new Replacer2( 'emr' ) )->replaceContent( $serialized, array( '/old.jpg' ), array( '/new.jpg' ) );
		$value  = unserialize( $result );

		$this->assertIsArray( $value, 'Still a valid serialized array' );
		$this->assertStringNotContainsString( chr( 60 ), $value['data'] );
		$this->assertSame( $script, json_decode( $value['data'] )->html );
		$this->assertSame( '/new.jpg', json_decode( $value['data'] )->src );
	}
}
