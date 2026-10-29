<?php
/**
 * Cross-plugin compatibility: WPML Media Translation.
 *
 * Runs with the REAL WPML Media Translation add-on active on top of WPML
 * core (both commercial: zips dropped into tests/partner-plugins/,
 * gitignored); without them every test here SKIPS.
 *
 * Media Translation lets a translator give a translated attachment its OWN
 * file. Such a translation is linked to the original through one `trid` in
 * `{prefix}icl_translations` but shares nothing on disk, so a replace of one
 * side must leave the other side byte-identical: its attached file, its
 * metadata, its main and size files, and the posts that show it. These are
 * positive tests: EMR replaces by attachment and by URL, and neither touches
 * the other language's file.
 *
 * The translation rows are seeded per test as in test-CompatWPML.php (the
 * setup wizard never ran in this install, so Media Translation's own UI
 * flow is not exercisable). WPML core's delete guard and attached-file sync
 * are registered from WPML's own classes as in that file, so the tests run
 * with the callbacks a configured site has.
 *
 * Ported from SPIO's tests/Compat/test-CompatWPMLMedia.php: the skip
 * condition, ensureIclTranslationsTable(), insertTranslationRow(),
 * createOwnFileTranslation() and the coexistence check. SPIO's
 * optimisation, dedupe and AI fan-out tests are replaced by EMR replaces.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';

class CompatWPMLMediaTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;

	/** @var int Next trid handed out by nextTrid(). */
	private static $trid = 731900;

	public function set_up() {
		if ( ! class_exists( 'SitePress' ) || ! defined( 'WPML_MEDIA_VERSION' ) ) {
			$this->markTestSkipped( 'WPML Media Translation is not loaded — drop the sitepress + wpml-media-translation zips into tests/partner-plugins/ and run bin/test.sh --compat.' );
		}

		// DDL auto-commits, so the table must exist BEFORE the test
		// transaction starts in parent::set_up().
		$this->ensureIclTranslationsTable();

		parent::set_up();
		$this->flowSetUp();

		global $wpdb;
		$action = new WPML_Attachment_Action( $GLOBALS['sitepress'], $wpdb );
		$this->addHook( 'wp_delete_file', array( $action, 'delete_file_filter' ) );
		$this->addHook( 'update_postmeta', array( 'WPML\Media\Duplication\Hooks', 'syncAttachedFile' ), 10, 4 );
	}

	public function tear_down() {
		$this->flowTearDown();
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Seeding and observation helpers
	// -------------------------------------------------------------------

	/** Same fallback as CompatWPMLTest: WPML only creates the table once its setup ran. */
	private function ensureIclTranslationsTable() {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}
		$wpdb->query(
			"CREATE TABLE {$table} (
				translation_id bigint unsigned NOT NULL AUTO_INCREMENT,
				element_type varchar(60) NOT NULL DEFAULT 'post_post',
				element_id bigint unsigned NULL,
				trid bigint unsigned NOT NULL,
				language_code varchar(7) NOT NULL,
				source_language_code varchar(7) NULL,
				PRIMARY KEY (translation_id)
			)"
		);
	}

	private function insertTranslationRow( $element_id, $trid, $lang, $source = null ) {
		global $wpdb;
		$this->assertSame(
			1,
			$wpdb->insert(
				$wpdb->prefix . 'icl_translations',
				array(
					'element_type'         => 'post_attachment',
					'element_id'           => $element_id,
					'trid'                 => $trid,
					'language_code'        => $lang,
					'source_language_code' => $source,
				)
			),
			'Precondition: the icl_translations row is inserted.'
		);
	}

	/**
	 * The Media Translation end state: a translation attachment with its OWN
	 * uploaded file (another image, another name), linked to the original
	 * through one trid.
	 *
	 * @return int The translated attachment's id.
	 */
	private function createOwnFileTranslation( $original_id, $lang = 'de' ) {
		$translated_id = $this->uploadJpeg( 'emr-wpmlm-de-' . $this->token() . '.jpg', 400, 300 );
		$this->recordFilesOf( $translated_id );
		$trid          = ++self::$trid;
		$this->insertTranslationRow( $original_id, $trid, 'en' );
		$this->insertTranslationRow( $translated_id, $trid, $lang, 'en' );

		$this->assertNotSame( get_attached_file( $original_id ), get_attached_file( $translated_id ), 'Precondition: the translation has its own file.' );
		$this->assertSame( array( $translated_id ), array_map( 'intval', \WPML\Element\API\IfOriginalPost::getTranslationIds( $original_id )->toArray() ), 'Precondition: WPML resolves the translation.' );
		return $translated_id;
	}

	/**
	 * Everything that makes up an attachment on disk and in the database:
	 * the raw attached file and metadata, and the md5 of the main file and of
	 * every size file (null when missing).
	 */
	private function snapshot( $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );
		clearstatcache();
		$files = array( 'main' => get_attached_file( $post_id ) ) + $this->sizePaths( $post_id );
		$md5   = array();
		foreach ( $files as $key => $path ) {
			$md5[ $key ] = file_exists( $path ) ? md5_file( $path ) : null;
		}
		return array(
			'attached_file' => $this->rawAttachedFile( $post_id ),
			'metadata'      => $this->rawPostMeta( $post_id, '_wp_attachment_metadata' ),
			'files'         => $md5,
		);
	}

	/** A 400x300 JPEG attachment with thumbnail (150x150) and medium (300x225) sizes. */
	private function standardImage( $prefix ) {
		$id   = $this->uploadJpeg( $prefix . '-' . $this->token() . '.jpg', 400, 300 );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 225, (int) $meta['sizes']['medium']['height'], 'Sentinel: the medium size is 300x225.' );
		$this->recordFilesOf( $id );
		return $id;
	}

	/**
	 * Records every file of the attachment for tear_down: with WPML's guard
	 * on, a replace leaves the old size files on disk without an owner (see
	 * test-CompatWPML.php).
	 */
	private function recordFilesOf( $post_id ) {
		$this->flowFiles[] = get_attached_file( $post_id );
		foreach ( $this->sizePaths( $post_id ) as $path ) {
			$this->flowFiles[] = $path;
		}
	}

	/** A post showing the attachment's full and medium URLs. */
	private function postShowing( $post_id ) {
		return $this->makePost( '<img src="' . wp_get_attachment_url( $post_id ) . '" /><img src="' . $this->sizeUrl( $post_id, 'medium' ) . '" />' );
	}

	// -------------------------------------------------------------------
	// Coexistence
	// -------------------------------------------------------------------

	public function test_wpml_media_translation_loads_alongside_emr() {
		$this->assertTrue( defined( 'ICL_SITEPRESS_VERSION' ), 'WPML core is active.' );
		$this->assertTrue( defined( 'WPML_MEDIA_VERSION' ), 'Media Translation defines its version constant.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	// -------------------------------------------------------------------
	// A replace of the original
	// -------------------------------------------------------------------

	/**
	 * A rename of the original leaves the own-file translation and a post
	 * showing it byte-identical, while the original and its post follow.
	 */
	public function test_rename_of_the_original_leaves_the_own_file_translation_untouched() {
		$id            = $this->standardImage( 'emr-wpmlm-rn' );
		$translated_id = $this->createOwnFileTranslation( $id );
		$post_orig     = $this->postShowing( $id );
		$post_tr       = $this->postShowing( $translated_id );
		$before        = $this->snapshot( $translated_id );
		$content_tr    = $this->rawContent( $post_tr );

		$name = 'emr-wpmlm-rn-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300, array( 200, 40, 40 ) ), $name );

		$this->assertStringEndsWith( '/' . $name, get_attached_file( $id ), 'Sentinel: the original was renamed.' );
		$this->assertStringContainsString( pathinfo( $name, PATHINFO_FILENAME ), $this->rawContent( $post_orig ), "Sentinel: the original's post follows the rename." );

		$this->assertSame( $before, $this->snapshot( $translated_id ), 'The translation is byte-identical.' );
		$this->assertNotContains( null, $before['files'], 'Every file of the translation exists.' );
		$this->assertSame( $content_tr, $this->rawContent( $post_tr ), "The translation's post is byte-identical." );
	}

	/**
	 * A same-name replace of the original with other dimensions (its medium
	 * size changes name) leaves the own-file translation byte-identical.
	 */
	public function test_same_name_replace_of_the_original_leaves_the_own_file_translation_untouched() {
		$id            = $this->standardImage( 'emr-wpmlm-same' );
		$translated_id = $this->createOwnFileTranslation( $id );
		$post_tr       = $this->postShowing( $translated_id );
		$before        = $this->snapshot( $translated_id );
		$content_tr    = $this->rawContent( $post_tr );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400, array( 200, 40, 40 ) ) );

		$this->assertStringEndsWith( '-300x200.jpg', $this->sizeUrl( $id, 'medium' ), 'Sentinel: the original medium size got a new name.' );
		$this->assertSame( $before, $this->snapshot( $translated_id ), 'The translation is byte-identical.' );
		$this->assertSame( $content_tr, $this->rawContent( $post_tr ), "The translation's post is byte-identical." );
	}

	// -------------------------------------------------------------------
	// A replace of the translation
	// -------------------------------------------------------------------

	/**
	 * A rename of the own-file translation leaves the original and a post
	 * showing it byte-identical, while the translation and its post follow.
	 */
	public function test_rename_of_the_translation_leaves_the_original_untouched() {
		$id            = $this->standardImage( 'emr-wpmlm-tr-rn' );
		$translated_id = $this->createOwnFileTranslation( $id );
		$post_orig     = $this->postShowing( $id );
		$post_tr       = $this->postShowing( $translated_id );
		$before        = $this->snapshot( $id );
		$content_orig  = $this->rawContent( $post_orig );

		$name = 'emr-wpmlm-tr-rn-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $translated_id, $this->tmpJpeg( 400, 300, array( 200, 40, 40 ) ), $name );

		$this->assertStringEndsWith( '/' . $name, get_attached_file( $translated_id ), 'Sentinel: the translation was renamed.' );
		$this->assertStringContainsString( pathinfo( $name, PATHINFO_FILENAME ), $this->rawContent( $post_tr ), "Sentinel: the translation's post follows the rename." );

		$this->assertSame( $before, $this->snapshot( $id ), 'The original is byte-identical.' );
		$this->assertNotContains( null, $before['files'], 'Every file of the original exists.' );
		$this->assertSame( $content_orig, $this->rawContent( $post_orig ), "The original's post is byte-identical." );
	}

	/**
	 * A same-name replace of the own-file translation leaves the original
	 * byte-identical.
	 */
	public function test_same_name_replace_of_the_translation_leaves_the_original_untouched() {
		$id            = $this->standardImage( 'emr-wpmlm-tr-same' );
		$translated_id = $this->createOwnFileTranslation( $id );
		$before        = $this->snapshot( $id );
		$md5_tr        = md5_file( get_attached_file( $translated_id ) );

		$this->replaceMode( $translated_id, $this->tmpJpeg( 600, 400, array( 200, 40, 40 ) ) );

		$this->assertNotSame( $md5_tr, md5_file( get_attached_file( $translated_id ) ), 'Sentinel: the translation file was replaced.' );
		$this->assertSame( $before, $this->snapshot( $id ), 'The original is byte-identical.' );
	}
}
