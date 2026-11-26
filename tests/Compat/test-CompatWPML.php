<?php
/**
 * Cross-plugin compatibility: WPML (sitepress-multilingual-cms).
 *
 * Runs with the REAL WPML plugin active. WPML is commercial, so
 * bin/test.sh --compat extracts it from a zip dropped into
 * tests/partner-plugins/ (gitignored); without that zip every test here
 * SKIPS.
 *
 * EMR has no WPML code. These tests document what a real EMR replace does
 * to a WPML attachment translation that shares the original's file, and
 * pin what goes wrong.
 *
 * The WPML model: a translated attachment is a separate post with a copy of
 * the original's `_wp_attached_file` and `_wp_attachment_metadata`, linked
 * to it through one `trid` in `{prefix}icl_translations` (the original's
 * row has no source_language_code). On a configured site WPML core adds two
 * callbacks that matter for a replace:
 *   - WPML_Attachment_Action::delete_file_filter() on `wp_delete_file`:
 *     refuses to delete a file (or a size of it) that any attachment's
 *     `_wp_attached_file` still names;
 *   - WPML\Media\Duplication\Hooks::syncAttachedFile() on `update_postmeta`:
 *     when an ORIGINAL's `_wp_attached_file` changes, copies the new value to
 *     every translation still holding the old one. Translations do not sync
 *     back, and the metadata is never synced.
 *
 * Seeding: the icl_translations rows are inserted per test (SPIO's helper;
 * WPML only fills them once its setup wizard ran, which never happened in
 * this install). The table comes from WPML's activation; when it is
 * missing, ensureIclTranslationsTable() creates it before the test
 * transaction starts (DDL auto-commits). WPML registers the two callbacks
 * above only on a configured site, so set_up() registers WPML's own
 * methods directly; both work against the seeded rows.
 *
 * What a replace does (the pins below):
 *   - A rename of the original deletes the shared file; WPML's sync moves
 *     the translation's `_wp_attached_file` to the new file, but its
 *     metadata keeps the old file name, dimensions and size names, and the
 *     old size files (kept by WPML's guard) still show the old image.
 *   - A same-name replace with other dimensions leaves the translation's
 *     metadata stale the same way.
 *   - A rename of the translation deletes the file the original still
 *     names: WPML syncs only from the original.
 *   - With the guard on, every replace leaves the old size files on disk.
 *   - EMR's contract hooks fire once per replace, not once per language.
 *
 * Own-file translations (WPML Media Translation) are covered in
 * test-CompatWPMLMedia.php.
 *
 * Ported from SPIO's tests/Compat/test-CompatWPML.php:
 * ensureIclTranslationsTable(), insertTranslationRow(),
 * createDuplicateAttachment() and the coexistence check. SPIO's AI, queue,
 * dedupe and rename-engine tests are replaced by EMR replaces.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';

class CompatWPMLTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;

	/** @var WPML_Attachment_Action WPML's attachment action, holding the real delete guard. */
	private $wpmlAction;

	/** @var int Next trid handed out by nextTrid(). */
	private static $trid = 731400;

	public function set_up() {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$this->markTestSkipped( 'WPML is not loaded — drop its zip (sitepress-multilingual-cms*.zip) into tests/partner-plugins/ and run bin/test.sh --compat.' );
		}

		// DDL auto-commits, so the table must exist BEFORE the test
		// transaction starts in parent::set_up().
		$this->ensureIclTranslationsTable();

		parent::set_up();
		$this->flowSetUp();

		global $wpdb;
		$this->wpmlAction = new WPML_Attachment_Action( $GLOBALS['sitepress'], $wpdb );
		$this->addHook( 'wp_delete_file', array( $this->wpmlAction, 'delete_file_filter' ) );
		$this->addHook( 'update_postmeta', array( 'WPML\Media\Duplication\Hooks', 'syncAttachedFile' ), 10, 4 );
	}

	public function tear_down() {
		$this->flowTearDown();
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Seeding and observation helpers
	// -------------------------------------------------------------------

	/**
	 * WPML normally creates icl_translations during its own setup; when the
	 * activation alone did not, create it with the columns the seeding uses.
	 */
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

	/** A trid no other test of this process uses. */
	private function nextTrid() {
		return ++self::$trid;
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
	 * A second attachment record pointing at the SAME file on disk with a
	 * copy of the metadata, linked to the original as its 'de' translation.
	 * Every file of the original is recorded for tear_down, because a
	 * replace can leave them on disk without an owner.
	 *
	 * @return int The translation's attachment id.
	 */
	private function createDuplicateAttachment( $source_id ) {
		$dup_id = wp_insert_attachment(
			array(
				'post_mime_type' => get_post_mime_type( $source_id ),
				'post_title'     => get_the_title( $source_id ) . ' (translation)',
				'post_status'    => 'inherit',
			),
			get_attached_file( $source_id )
		);
		$this->assertIsInt( $dup_id );
		$this->assertGreaterThan( $source_id, $dup_id, 'Duplicate attachment must be created.' );
		$this->uploadedAttachments[] = $dup_id;
		update_post_meta( $dup_id, '_wp_attachment_metadata', wp_get_attachment_metadata( $source_id ) );

		$trid = $this->nextTrid();
		$this->insertTranslationRow( $source_id, $trid, 'en' );
		$this->insertTranslationRow( $dup_id, $trid, 'de', 'en' );

		$this->flowFiles[] = get_attached_file( $source_id );
		foreach ( $this->sizePaths( $source_id ) as $path ) {
			$this->flowFiles[] = $path;
		}
		return $dup_id;
	}

	/** The attachment metadata, read past the object cache. */
	private function freshMeta( $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );
		return wp_get_attachment_metadata( $post_id );
	}

	/** The raw _wp_attached_file, read past the object cache. */
	private function freshAttachedFile( $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );
		return get_attached_file( $post_id );
	}

	/** md5 of a file, or null when it does not exist. */
	private function md5OrNull( $path ) {
		clearstatcache();
		return file_exists( $path ) ? md5_file( $path ) : null;
	}

	/** A 400x300 JPEG attachment with thumbnail (150x150) and medium (300x225) sizes. */
	private function standardImage( $prefix ) {
		$id   = $this->uploadJpeg( $prefix . '-' . $this->token() . '.jpg', 400, 300 );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 225, (int) $meta['sizes']['medium']['height'], 'Sentinel: the medium size is 300x225.' );
		$this->assertArrayHasKey( 'thumbnail', $meta['sizes'], 'Sentinel: a thumbnail size exists.' );
		return $id;
	}

	// -------------------------------------------------------------------
	// Coexistence and the seeded shape
	// -------------------------------------------------------------------

	public function test_wpml_loads_alongside_emr() {
		global $wpdb;
		$this->assertTrue( defined( 'ICL_SITEPRESS_VERSION' ), 'WPML defines its version constant.' );
		$this->assertInstanceOf( 'SitePress', $GLOBALS['sitepress'], 'WPML exposes its SitePress instance.' );
		$this->assertSame( $wpdb->prefix . 'icl_translations', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'icl_translations' ) ), 'The translations table exists.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * Shape sentinel for the tests below: WPML resolves the seeded rows (the
	 * original lists the duplicate as its translation, not the other way
	 * round), the two posts share file and metadata, and WPML's guard keeps
	 * the shared file and its sizes.
	 */
	public function test_seeded_translation_shares_the_file_and_wpml_resolves_it() {
		$id     = $this->standardImage( 'emr-wpml-shape' );
		$dup_id = $this->createDuplicateAttachment( $id );

		$this->assertSame( $this->rawAttachedFile( $id ), $this->rawAttachedFile( $dup_id ), 'Both posts store the same _wp_attached_file.' );
		$this->assertSame( $this->rawPostMeta( $id, '_wp_attachment_metadata' ), $this->rawPostMeta( $dup_id, '_wp_attachment_metadata' ), 'Both posts store the same metadata.' );
		$this->assertSame( array( $dup_id ), array_map( 'intval', \WPML\Element\API\IfOriginalPost::getTranslationIds( $id )->toArray() ), 'WPML lists the duplicate as the translation of the original.' );
		$this->assertSame( array(), \WPML\Element\API\IfOriginalPost::getTranslationIds( $dup_id )->toArray(), 'The translation is not an original.' );
		$this->assertNull( $this->wpmlAction->delete_file_filter( get_attached_file( $id ) ), "WPML's guard keeps the shared file." );
		$this->assertNull( $this->wpmlAction->delete_file_filter( $this->sizePaths( $id )['medium'] ), "WPML's guard keeps the shared sizes." );
	}

	// -------------------------------------------------------------------
	// A replace of the original
	// -------------------------------------------------------------------

	/**
	 * A rename of the original deletes the shared file (removeCurrent()
	 * unlinks the attached file past WPML's guard), and WPML's sync moves the
	 * translation's `_wp_attached_file` to the new file. The translation's
	 * metadata is not synced: it keeps the old `file`, the old dimensions and
	 * the old size names, whose files the guard kept on disk, so the
	 * translation's sizes still show the old image. The correct behaviour is
	 * to regenerate (or copy) the metadata of every attachment that follows
	 * the rename.
	 *
	 * How to recognise the fix: the translation's metadata names the new
	 * file and the pinned assertSame on the metadata below fails.
	 */
	public function test_rename_of_the_original_leaves_the_translation_metadata_on_the_old_file_pinned_for_deferred_fix() {
		$id        = $this->standardImage( 'emr-wpml-rn' );
		$dup_id    = $this->createDuplicateAttachment( $id );
		$old_file  = get_attached_file( $id );
		$dup_meta  = $this->freshMeta( $dup_id );
		$dup_sizes = $this->sizePaths( $dup_id );
		$sizes_md5 = array_map( array( $this, 'md5OrNull' ), $dup_sizes );

		$name = 'emr-wpml-rn-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300, array( 200, 40, 40 ) ), $name );

		$new_file = $this->freshAttachedFile( $id );
		$this->assertStringEndsWith( '/' . $name, $new_file, 'Sentinel: the original was renamed.' );
		$this->assertFileDoesNotExist( $old_file, 'The shared file is deleted past the guard.' );

		$this->assertSame( $new_file, $this->freshAttachedFile( $dup_id ), "WPML's sync moves the translation to the new file." );
		$this->assertFileExists( $this->freshAttachedFile( $dup_id ), 'The translation main file exists.' );

		$stale = $this->freshMeta( $dup_id );
		$this->assertSame( $dup_meta, $stale, 'Pinned: the translation metadata is unchanged.' );
		$this->assertSame( basename( $old_file ), basename( $stale['file'] ), 'Pinned: the translation metadata names the deleted file.' );
		foreach ( $dup_sizes as $size => $path ) {
			$this->assertSame( $sizes_md5[ $size ], $this->md5OrNull( $path ), "Pinned: the translation's $size file survives with the old image." );
		}
	}

	/**
	 * A same-name replace with other dimensions changes no attached file, so
	 * WPML syncs nothing. The shared file is the new image; the translation's
	 * metadata keeps 400x300 and the old medium name, whose file the guard
	 * kept, so the translation's medium shows the old image. Same cause as
	 * the rename pin above.
	 *
	 * How to recognise the fix: the translation's metadata reports 600x400
	 * and the pinned assertSame on the width fails.
	 */
	public function test_same_name_replace_leaves_the_translation_metadata_stale_pinned_for_deferred_fix() {
		$id         = $this->standardImage( 'emr-wpml-same' );
		$dup_id     = $this->createDuplicateAttachment( $id );
		$shared     = get_attached_file( $id );
		$dup_sizes  = $this->sizePaths( $dup_id );
		$medium_md5 = $this->md5OrNull( $dup_sizes['medium'] );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400, array( 200, 40, 40 ) ) );

		$meta = $this->freshMeta( $id );
		$this->assertSame( 600, (int) $meta['width'], 'Sentinel: the original metadata has the new width.' );
		$this->assertStringEndsWith( '-300x200.jpg', $meta['sizes']['medium']['file'], 'Sentinel: the original medium size got a new name.' );
		$size = getimagesize( $shared );
		$this->assertSame( array( 600, 400 ), array( $size[0], $size[1] ), 'The shared file is the new image.' );

		$dup_meta = $this->freshMeta( $dup_id );
		$this->assertSame( $shared, $this->freshAttachedFile( $dup_id ), 'The translation still points at the shared (existing) file.' );
		$this->assertSame( 400, (int) $dup_meta['width'], 'Pinned: the translation metadata keeps the old width.' );
		$this->assertSame( 300, (int) $dup_meta['height'], 'Pinned: the translation metadata keeps the old height.' );
		$this->assertStringEndsWith( '-300x225.jpg', $dup_meta['sizes']['medium']['file'], 'Pinned: the translation medium keeps the old name.' );
		$this->assertSame( $medium_md5, $this->md5OrNull( $dup_sizes['medium'] ), 'Pinned: the translation medium is the old image, kept by the guard.' );
	}

	/**
	 * With WPML's guard on, a replace leaves the old size files on disk even
	 * for an image without a translation: removeCurrent() calls
	 * wp_delete_attachment_files() while the attachment's own
	 * `_wp_attached_file` is still stored, and the guard (which strips the
	 * size suffix before its lookup) finds that attachment for every size.
	 * The correct behaviour is to delete the old sizes no other attachment
	 * names.
	 *
	 * How to recognise the fix: the old size files are gone after the rename
	 * and the pinned assertFileExists below fails.
	 */
	public function test_rename_leaves_the_old_size_files_on_disk_with_the_guard_on_pinned_for_deferred_fix() {
		$id        = $this->standardImage( 'emr-wpml-orphan' );
		$old_file  = get_attached_file( $id );
		$old_sizes = $this->sizePaths( $id );
		foreach ( $old_sizes as $path ) {
			$this->flowFiles[] = $path;
		}
		$this->assertNull( $this->wpmlAction->delete_file_filter( $old_sizes['medium'] ), 'Precondition: the guard finds the attachment itself.' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-wpml-orphan-new-' . $this->token() . '.jpg' );

		$this->assertFileDoesNotExist( $old_file, 'Sentinel: the old main file is gone.' );
		$this->assertNotContains( $old_sizes['medium'], $this->sizePaths( $id ), 'Sentinel: the attachment no longer names the old sizes.' );
		foreach ( $old_sizes as $size => $path ) {
			$this->assertFileExists( $path, "Pinned: the old $size file stays on disk without an owner." );
		}
	}

	// -------------------------------------------------------------------
	// A replace of the translation
	// -------------------------------------------------------------------

	/**
	 * A rename started from the translation deletes the file the original
	 * still names: WPML syncs `_wp_attached_file` only from an original, so
	 * the original keeps the old value and points at a missing file. A post
	 * that shows the shared URL is rewritten to the translation's new file
	 * (the replacer works by URL, not by language).
	 *
	 * How to recognise the fix: the original's attached file exists after
	 * the rename and the pinned assertFileDoesNotExist below fails.
	 */
	public function test_rename_of_the_translation_deletes_the_original_file_pinned_for_deferred_fix() {
		$id       = $this->standardImage( 'emr-wpml-dup-rn' );
		$dup_id   = $this->createDuplicateAttachment( $id );
		$old_file = get_attached_file( $id );
		$post_id  = $this->makePost( '<img src="' . wp_get_attachment_url( $id ) . '" />' );

		$name = 'emr-wpml-dup-rn-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $dup_id, $this->tmpJpeg( 400, 300, array( 200, 40, 40 ) ), $name );

		$this->assertStringEndsWith( '/' . $name, $this->freshAttachedFile( $dup_id ), 'Sentinel: the translation was renamed.' );
		$this->assertFileExists( $this->freshAttachedFile( $dup_id ), 'Sentinel: the translation has its new file.' );
		$this->assertSame( '<img src="' . wp_get_attachment_url( $dup_id ) . '" />', $this->rawContent( $post_id ), 'The post showing the shared URL now shows the translation file.' );

		$this->assertSame( $old_file, $this->freshAttachedFile( $id ), 'Pinned: the original keeps the old attached file (no sync from a translation).' );
		$this->assertFileDoesNotExist( $old_file, 'Pinned: the original points at a missing file.' );
	}

	/**
	 * A same-name replace of the translation with the same dimensions
	 * changes the image for every language: the shared file and sizes are
	 * the new image and the original's metadata stays valid.
	 */
	public function test_same_name_replace_of_the_translation_updates_the_image_for_every_language() {
		$id        = $this->standardImage( 'emr-wpml-dup-same' );
		$dup_id    = $this->createDuplicateAttachment( $id );
		$shared    = get_attached_file( $id );
		$meta      = $this->freshMeta( $id );
		$md5_main  = $this->md5OrNull( $shared );
		$md5_sizes = array_map( array( $this, 'md5OrNull' ), $this->sizePaths( $id ) );

		$this->replaceMode( $dup_id, $this->tmpJpeg( 400, 300, array( 200, 40, 40 ) ) );

		$this->assertSame( $shared, $this->freshAttachedFile( $id ), 'The original still names the shared file.' );
		$this->assertNotSame( $md5_main, $this->md5OrNull( $shared ), 'The shared file is the new image.' );
		$this->assertSame( $meta, $this->freshMeta( $id ), 'The original metadata is unchanged.' );
		foreach ( $this->sizePaths( $id ) as $size => $path ) {
			$this->assertNotNull( $this->md5OrNull( $path ), "The original's $size file exists." );
			$this->assertNotSame( $md5_sizes[ $size ], $this->md5OrNull( $path ), "The original's $size file is the new image." );
		}
	}

	// -------------------------------------------------------------------
	// Contract hooks
	// -------------------------------------------------------------------

	/**
	 * With a translation present, EMR fires `wp_handle_replace` and
	 * `enable-media-replace-upload-done` once per replace, for the replaced
	 * attachment only: EMR does not loop over languages.
	 */
	public function test_contract_hooks_fire_once_per_replace_not_once_per_language() {
		$id     = $this->standardImage( 'emr-wpml-hooks' );
		$dup_id = $this->createDuplicateAttachment( $id );

		$this->addHook( 'wp_handle_replace', $this->record( 'handle_replace' ), 10, 1 );
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ), 10, 3 );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-wpml-hooks-new-' . $this->token() . '.jpg' );

		$this->assertSame( array( 'handle_replace', 'upload_done' ), $this->eventNames(), 'Each contract hook fires once, in order.' );
		$this->assertSame( array( 'post_id' => $id ), $this->events[0][1][0], 'wp_handle_replace carries the original only.' );
		$this->assertSame( $id, $this->events[1][1][2], 'upload-done carries the original only.' );
		$this->assertNotSame( $dup_id, $this->events[1][1][2] );
	}
}
