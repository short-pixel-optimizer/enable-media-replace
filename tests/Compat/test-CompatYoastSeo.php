<?php
/**
 * Cross-plugin compatibility: Yoast SEO.
 *
 * Runs with the REAL wordpress-seo plugin active (bin/test.sh --compat
 * downloads + activates it). Covers the bundled replacer module
 * build/shortpixel/replacer2/src/Modules/YoastSeo.php, a housekeeping
 * module: on `emr/replacer/replace_urls`, before the rewrite queries run,
 * removeIndexes() DELETEs every `{prefix}yoast_indexable` row whose
 * `open_graph_image` or `twitter_image` is LIKE the `base` or the `file`
 * search value (both the URL path of the main file). Yoast rebuilds the
 * indexable on its next request. EMR has no Yoast external of its own.
 *
 * Gated on defined('WPSEO_VERSION') with no override filter, so only the
 * real plugin exercises it.
 *
 *   - A rename (MODE_SEARCHREPLACE) deletes the rows that reference the
 *     old main URL and keeps the unrelated ones.
 *   - A same-name replace (MODE_REPLACE) runs with `thumbnails_only`, so
 *     `base` and `file` are not in the search array and nothing is deleted;
 *     the main URL is unchanged, so the rows stay correct.
 *   - Rows that reference only a size URL, or only the bare file name, are
 *     not matched (see the pin and the documented-behaviour test).
 *
 * Yoast's installer creates the table on activation (the integration
 * bootstrap fires it); ensureYoastIndexableTable() creates the minimal
 * shape the module queries when it is missing, before the test transaction
 * (DDL auto-commits). Rows inserted here roll back. They use object ids no
 * post has, so Yoast's own watchers never rebuild them.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/CompatReplacerModules.php';

class CompatYoastSeoTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_CompatReplacerModules;

	/** @var int Object id given to the seeded rows; no post has it. */
	private $objectId = 970000;

	public function set_up() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not loaded — run via bin/test.sh --compat.' );
		}
		// DDL auto-commits: the table must exist before parent::set_up()
		// opens the test transaction.
		$this->ensureYoastIndexableTable();
		parent::set_up();
		$this->flowSetUp();
		$this->snapshotModuleSingletons();
	}

	public function tear_down() {
		$this->restoreModuleSingletons();
		$this->flowTearDown();
		parent::tear_down();
	}

	/**
	 * Creates the minimal table shape the module queries when Yoast's
	 * migrations did not create the real one.
	 */
	private function ensureYoastIndexableTable() {
		global $wpdb;
		$table = $wpdb->prefix . 'yoast_indexable';
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}
		$wpdb->query(
			"CREATE TABLE {$table} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				object_id bigint unsigned NULL,
				object_type varchar(32) NOT NULL DEFAULT 'post',
				open_graph_image text NULL,
				open_graph_image_meta text NULL,
				twitter_image text NULL,
				PRIMARY KEY (id)
			)"
		);
	}

	/** Inserts an indexable row; returns its id. */
	private function insertIndexableRow( $og_image, $twitter_image = null ) {
		global $wpdb;
		++$this->objectId;
		$wpdb->insert(
			$wpdb->prefix . 'yoast_indexable',
			array(
				'object_id'        => $this->objectId,
				'object_type'      => 'post',
				'open_graph_image' => $og_image,
				'twitter_image'    => null === $twitter_image ? $og_image : $twitter_image,
			)
		);
		$id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $id, 'Sentinel: the indexable row was inserted.' );
		return $id;
	}

	private function indexableRowExists( $id ) {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'yoast_indexable WHERE id = %d', $id ) );
	}

	// -------------------------------------------------------------------
	// Coexistence and detection
	// -------------------------------------------------------------------

	public function test_yoast_loads_alongside_emr() {
		global $wpdb;
		$this->assertTrue( defined( 'WPSEO_VERSION' ), 'Yoast defines its version constant.' );
		$table = $wpdb->prefix . 'yoast_indexable';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'The indexable table exists.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * A fresh Replacer constructs the module, which detects the real plugin
	 * through WPSEO_VERSION and registers removeIndexes().
	 */
	public function test_replacer_construction_registers_the_yoast_module() {
		new \EnableMediaReplace\Replacer\Replacer();

		$module = \EnableMediaReplace\Replacer\Modules\YoastSeo::getInstance();
		$this->assertSame( 10, has_action( 'emr/replacer/replace_urls', array( $module, 'removeIndexes' ) ) );
	}

	// -------------------------------------------------------------------
	// A real replace against Yoast's indexable table
	// -------------------------------------------------------------------

	/**
	 * A rename deletes the rows whose Open Graph or Twitter image is the
	 * old main URL (one row with both, one with only the Twitter image) and
	 * keeps a row that references another image.
	 */
	public function test_rename_deletes_the_indexables_of_the_old_url_and_keeps_the_others() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-yoast-rn' );
		$both      = $this->insertIndexableRow( $url, $url );
		$twitter   = $this->insertIndexableRow( 'https://example.com/emr-yoast-other-og.jpg', $url );
		$unrelated = $this->insertIndexableRow( 'https://example.com/emr-yoast-unrelated.jpg' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-yoast-new-' . $this->token() . '.jpg' );

		$this->assertNotSame( $url, wp_get_attachment_url( $id ), 'Sentinel: the attachment was renamed.' );
		$this->assertFalse( $this->indexableRowExists( $both ), 'The row with the old URL as both images is deleted.' );
		$this->assertFalse( $this->indexableRowExists( $twitter ), 'The row with the old URL as Twitter image is deleted.' );
		$this->assertTrue( $this->indexableRowExists( $unrelated ), 'The unrelated row is kept.' );
	}

	/**
	 * A same-name replace runs the replacer with `thumbnails_only`: the
	 * module receives no `base` or `file` value and deletes nothing. The
	 * main URL does not change, so the row is still correct.
	 */
	public function test_same_name_replace_keeps_the_indexables_of_the_unchanged_main_url() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-yoast-same' );
		$row = $this->insertIndexableRow( $url, $url );
		$this->addHook( 'emr/replacer/replace_urls', $this->record( 'replace_urls' ), 5, 3 );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$this->assertSame( $url, wp_get_attachment_url( $id ), 'Sentinel: the main URL is unchanged.' );
		$this->assertSame( array( 'replace_urls' ), $this->eventNames(), 'Sentinel: the module\'s action fired.' );
		$search = $this->events[0][1][0];
		$this->assertArrayNotHasKey( 'base', $search, 'No `base` value in a same-name replace.' );
		$this->assertArrayNotHasKey( 'file', $search, 'No `file` value in a same-name replace.' );
		$this->assertArrayHasKey( 'medium', $search, 'Sentinel: the changed medium size is searched.' );
		$this->assertTrue( $this->indexableRowExists( $row ), 'The row of the unchanged main URL is kept.' );
	}

	/**
	 * Documents current behaviour: the module matches the URL path of the
	 * main file, so a row that mentions only the bare file name (no path)
	 * is not deleted by a rename.
	 */
	public function test_a_filename_only_reference_is_left_intact() {
		list( $id, $url ) = $this->standardCompatImage( 'emr-yoast-bare' );
		$row = $this->insertIndexableRow( 'Caption mentioning ' . wp_basename( $url ) );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-yoast-bare-new-' . $this->token() . '.jpg' );

		$this->assertNotSame( $url, wp_get_attachment_url( $id ), 'Sentinel: the attachment was renamed.' );
		$this->assertTrue( $this->indexableRowExists( $row ), 'A bare file name does not match the URL path.' );
	}

	/**
	 * YoastSeo::removeIndexes() only searches the `base` and `file` values
	 * (the main file). An indexable whose Open Graph or Twitter image is a
	 * size URL (here the medium size) is kept after a rename, although EMR
	 * deletes the old size file and rewrites every other reference to it:
	 * Yoast keeps serving a social image URL that answers 404 until the
	 * indexable is rebuilt for another reason. The same holds for a
	 * same-name replace that renames a size. The correct behaviour is to
	 * delete rows matching any of the search values (every size), for
	 * example by looping over $search_urls instead of reading two keys.
	 *
	 * How to recognise the fix: the size row is deleted and the pinned
	 * assertTrue below fails.
	 */
	public function test_rename_keeps_an_indexable_that_references_a_size_url_pinned_for_deferred_fix() {
		list( $id, $url, $medium ) = $this->standardCompatImage( 'emr-yoast-size' );
		$main_row = $this->insertIndexableRow( $url, $url );
		$size_row = $this->insertIndexableRow( $medium, $medium );
		$old_size = $this->sizePaths( $id )['medium'];

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-yoast-size-new-' . $this->token() . '.jpg' );

		$this->assertFileDoesNotExist( $old_size, 'Sentinel: the old medium file is gone.' );
		$this->assertFalse( $this->indexableRowExists( $main_row ), 'Sentinel: the main-URL row is deleted.' );
		$this->assertTrue( $this->indexableRowExists( $size_row ), 'Pinned: the row that references the deleted medium file is kept.' );
	}
}
