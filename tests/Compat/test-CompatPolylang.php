<?php
/**
 * Cross-plugin compatibility: Polylang.
 *
 * Runs with the REAL Polylang plugin active (wordpress.org, fetched by
 * bin/test.sh --compat); without it every test here SKIPS.
 *
 * EMR has no Polylang code. These tests document what a real EMR replace
 * does to a Polylang media translation and pin what goes wrong.
 *
 * The Polylang media-translation model: with media support on, a
 * translated image is a SEPARATE attachment post whose `_wp_attached_file`
 * and `_wp_attachment_metadata` are copies of the original's, so both posts
 * point at the SAME file and the same size files on disk. Polylang links the
 * two through its `language` and `post_translations` taxonomies, and its
 * `wp_delete_file` guard (PLL_CRUD_Posts::wp_delete_file()) refuses to
 * delete a file that any attachment's metadata still names.
 *
 * Seeding: Polylang's own API, PLL_Translated_Post::create_media_translation(),
 * exists in the installed version but cannot run here: the test install has
 * no language configured, so Polylang never boots its context, PLL() is not
 * defined, and the method needs both. The translation is created with the
 * same steps that method performs: wp_insert_attachment() with a copy of the
 * original post row (same guid, same author), the unfiltered metadata and
 * attached file copied over, the `language` term set on both posts and one
 * `post_translations` group term whose description is the serialized
 * language => post id map, Polylang's storage format.
 *
 * The delete guard: Polylang registers it only when media support is on,
 * which is the setting every site with media translations runs. The test
 * install has media support off, so set_up() registers Polylang's own
 * method on an instance built without its constructor (the method reads
 * only $wpdb and the uploads directory).
 *
 * What a replace does (the pins below):
 *   - A rename of the original deletes the shared file (removeCurrent()
 *     hard-unlinks the attached file past the guard) and leaves the
 *     translation's attached file and metadata on the old, missing file.
 *   - A same-name replace with other dimensions overwrites the shared file
 *     and regenerates the sizes for the original only; the translation's
 *     metadata keeps the old dimensions and the old size names, some of
 *     which are now the new image and some the old one.
 *   - A rename of the translation deletes the original's file the same way.
 *   - With the guard on, every replace leaves the old size files on disk:
 *     the guard counts the attachment being replaced, whose metadata is
 *     still in place when removeCurrent() runs.
 *
 * Ported from SPIO's tests/Compat/test-CompatPolylang.php: the guid-
 * duplicate seeding (rewritten to follow create_media_translation()) and
 * the coexistence check. SPIO's optimisation, dedupe and rename-engine
 * tests are replaced by EMR replaces.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';

class CompatPolylangTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;

	/** @var PLL_CRUD_Posts Polylang's CRUD class, holding the real delete guard. */
	private $pllCrud;

	public function set_up() {
		if ( ! defined( 'POLYLANG_VERSION' ) ) {
			$this->markTestSkipped( 'Polylang is not loaded — run bin/test.sh --compat (downloads polylang from wp.org).' );
		}
		parent::set_up();
		$this->flowSetUp();

		// Polylang's own wp_delete_file guard, as registered with media support on.
		$this->pllCrud = ( new ReflectionClass( 'PLL_CRUD_Posts' ) )->newInstanceWithoutConstructor();
		$this->addHook( 'wp_delete_file', array( $this->pllCrud, 'wp_delete_file' ) );
	}

	public function tear_down() {
		$this->flowTearDown();
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Seeding and observation helpers
	// -------------------------------------------------------------------

	/**
	 * Creates the Polylang translation of an attachment the way
	 * PLL_Translated_Post::create_media_translation() does (see the class
	 * docblock): a second attachment post sharing the file and metadata.
	 * Every file of the original is recorded for tear_down, because a
	 * replace can leave them on disk without an owner.
	 *
	 * @return int The translation's attachment id.
	 */
	private function createPolylangTranslation( $source_id, $source_lang = 'en', $lang = 'fr' ) {
		$post = get_post( $source_id, ARRAY_A );
		unset( $post['ID'] );
		$dup_id = wp_insert_attachment( wp_slash( $post ) );
		$this->assertIsInt( $dup_id );
		$this->assertGreaterThan( $source_id, $dup_id, 'Precondition: the translation is a new attachment post.' );
		$this->uploadedAttachments[] = $dup_id;

		wp_update_attachment_metadata( $dup_id, wp_slash( wp_get_attachment_metadata( $source_id, true ) ) );
		update_attached_file( $dup_id, wp_slash( get_attached_file( $source_id, true ) ) );

		wp_set_object_terms( $source_id, $source_lang, 'language' );
		wp_set_object_terms( $dup_id, $lang, 'language' );
		$group = wp_insert_term( uniqid( 'pll_' ), 'post_translations', array( 'description' => maybe_serialize( array( $source_lang => $source_id, $lang => $dup_id ) ) ) );
		$this->assertIsArray( $group, 'Precondition: the translation group term is created.' );
		wp_set_object_terms( $source_id, (int) $group['term_id'], 'post_translations' );
		wp_set_object_terms( $dup_id, (int) $group['term_id'], 'post_translations' );

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

	public function test_polylang_loads_alongside_emr() {
		$this->assertTrue( defined( 'POLYLANG_VERSION' ), 'Polylang defines its version constant.' );
		$this->assertTrue( method_exists( 'PLL_Translated_Post', 'create_media_translation' ), 'Polylang ships the media translation API the seeding follows.' );
		$this->assertTrue( taxonomy_exists( 'language' ), 'Polylang registers its language taxonomy.' );
		$this->assertTrue( taxonomy_exists( 'post_translations' ), 'Polylang registers its translation-group taxonomy.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * Shape sentinel for the tests below: the translation is a separate post
	 * sharing the guid, the attached file and the metadata of the original,
	 * both posts sit in one translation group, and Polylang's guard refuses
	 * to delete the shared file or its sizes.
	 */
	public function test_translation_shares_the_file_and_the_metadata_of_the_original() {
		$id     = $this->standardImage( 'emr-pll-shape' );
		$dup_id = $this->createPolylangTranslation( $id );

		$this->assertNotSame( $id, $dup_id );
		$this->assertSame( $this->rawAttachedFile( $id ), $this->rawAttachedFile( $dup_id ), 'Both posts store the same _wp_attached_file.' );
		$this->assertSame( $this->rawPostMeta( $id, '_wp_attachment_metadata' ), $this->rawPostMeta( $dup_id, '_wp_attachment_metadata' ), 'Both posts store the same metadata.' );
		$this->assertSame( $this->rawPostField( $id, 'guid' ), $this->rawPostField( $dup_id, 'guid' ), 'Both posts carry the same guid.' );
		$this->assertSame( (string) get_post_field( 'post_author', $id ), (string) get_post_field( 'post_author', $dup_id ), 'The translation keeps the author.' );

		$this->assertSame( array( 'en' ), wp_get_object_terms( $id, 'language', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( array( 'fr' ), wp_get_object_terms( $dup_id, 'language', array( 'fields' => 'slugs' ) ) );
		$group = wp_get_object_terms( $dup_id, 'post_translations' );
		$this->assertCount( 1, $group );
		$this->assertSame( array( 'en' => $id, 'fr' => $dup_id ), maybe_unserialize( $group[0]->description ), 'The group maps each language to its post.' );

		$file = get_attached_file( $id );
		$this->assertSame( '', $this->pllCrud->wp_delete_file( $file ), "Polylang's guard keeps the shared file." );
		$this->assertSame( '', $this->pllCrud->wp_delete_file( $this->sizePaths( $id )['medium'] ), "Polylang's guard keeps the shared sizes." );
	}

	// -------------------------------------------------------------------
	// A replace of the original
	// -------------------------------------------------------------------

	/**
	 * A rename of the original moves the file: removeCurrent() asks WordPress
	 * to delete the old files (Polylang's guard refuses), then unlinks the
	 * attached file directly. The translation still stores the old
	 * `_wp_attached_file` and metadata, so its main image is a missing file,
	 * while its size files survive (the guard kept them) and still show the
	 * old image. The correct behaviour is to keep the translation working:
	 * either leave the shared file in place while another attachment names
	 * it, or update the attached file and metadata of every attachment that
	 * shares it.
	 *
	 * How to recognise the fix: the translation's attached file exists after
	 * the rename and the pinned assertFileDoesNotExist below fails.
	 */
	public function test_rename_of_the_original_leaves_the_translation_on_the_deleted_file_pinned_for_deferred_fix() {
		$id          = $this->standardImage( 'emr-pll-rn' );
		$dup_id      = $this->createPolylangTranslation( $id );
		$old_file    = get_attached_file( $id );
		$old_rel     = $this->rawAttachedFile( $id );
		$dup_meta    = $this->freshMeta( $dup_id );
		$dup_sizes   = $this->sizePaths( $dup_id );
		$sizes_md5   = array_map( array( $this, 'md5OrNull' ), $dup_sizes );

		$name = 'emr-pll-rn-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300, array( 200, 40, 40 ) ), $name );

		$this->assertStringEndsWith( '/' . $name, get_attached_file( $id ), 'Sentinel: the original was renamed.' );
		$this->assertFileExists( get_attached_file( $id ), 'Sentinel: the original has its new file.' );
		$this->assertFileDoesNotExist( $old_file, 'The shared file is deleted past the guard.' );

		$this->assertSame( $old_rel, $this->rawAttachedFile( $dup_id ), 'Pinned: the translation keeps the old _wp_attached_file.' );
		$this->assertFileDoesNotExist( get_attached_file( $dup_id ), 'Pinned: the translation points at a missing file.' );
		$this->assertSame( $dup_meta, $this->freshMeta( $dup_id ), 'Pinned: the translation keeps the old metadata.' );
		$this->assertStringEndsWith( basename( $old_file ), wp_get_attachment_url( $dup_id ), 'Pinned: the translation URL names the old file.' );
		foreach ( $dup_sizes as $size => $path ) {
			$this->assertSame( $sizes_md5[ $size ], $this->md5OrNull( $path ), "Pinned: the translation's $size file survives with the old image." );
		}
	}

	/**
	 * A same-name replace with other dimensions overwrites the file both
	 * posts share and regenerates the sizes under the original only. The
	 * translation's metadata keeps the old dimensions and size names:
	 *   - its main file is the new image (600x400) while the metadata says
	 *     400x300;
	 *   - its `thumbnail` keeps its name (150x150 either way) and was
	 *     regenerated, so it shows the new image;
	 *   - its `medium` names the old 300x225 file, which the guard kept on
	 *     disk, so it shows the old image.
	 * The correct behaviour is to regenerate (or copy) the metadata of every
	 * attachment sharing the file.
	 *
	 * How to recognise the fix: the translation's metadata reports 600x400
	 * and the pinned assertSame on the width fails.
	 */
	public function test_same_name_replace_leaves_the_translation_metadata_stale_pinned_for_deferred_fix() {
		$id         = $this->standardImage( 'emr-pll-same' );
		$dup_id     = $this->createPolylangTranslation( $id );
		$shared     = get_attached_file( $id );
		$dup_sizes  = $this->sizePaths( $dup_id );
		$thumb_md5  = $this->md5OrNull( $dup_sizes['thumbnail'] );
		$medium_md5 = $this->md5OrNull( $dup_sizes['medium'] );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400, array( 200, 40, 40 ) ) );

		$meta = $this->freshMeta( $id );
		$this->assertSame( $shared, get_attached_file( $id ), 'Sentinel: the original keeps its file name.' );
		$this->assertSame( 600, (int) $meta['width'], 'Sentinel: the original metadata has the new width.' );
		$this->assertStringEndsWith( '-300x200.jpg', $meta['sizes']['medium']['file'], 'Sentinel: the original medium size got a new name.' );
		$size = getimagesize( $shared );
		$this->assertSame( array( 600, 400 ), array( $size[0], $size[1] ), 'The shared file is the new image.' );

		$dup_meta = $this->freshMeta( $dup_id );
		$this->assertSame( $shared, get_attached_file( $dup_id ), 'The translation still points at the shared (existing) file.' );
		$this->assertSame( 400, (int) $dup_meta['width'], 'Pinned: the translation metadata keeps the old width.' );
		$this->assertSame( 300, (int) $dup_meta['height'], 'Pinned: the translation metadata keeps the old height.' );
		$this->assertStringEndsWith( '-300x225.jpg', $dup_meta['sizes']['medium']['file'], 'Pinned: the translation medium keeps the old name.' );
		$this->assertSame( $medium_md5, $this->md5OrNull( $dup_sizes['medium'] ), 'Pinned: the translation medium is the old image, kept by the guard.' );
		$this->assertNotNull( $this->md5OrNull( $dup_sizes['thumbnail'] ), 'The translation thumbnail exists.' );
		$this->assertNotSame( $thumb_md5, $this->md5OrNull( $dup_sizes['thumbnail'] ), 'The translation thumbnail was regenerated with the new image.' );
	}

	/**
	 * With Polylang's guard on, a replace leaves the old size files on disk
	 * even for an image without a translation: removeCurrent() calls
	 * wp_delete_attachment_files() while the attachment's own metadata is
	 * still stored, and the guard counts that attachment as a user of each
	 * file. Only the main file goes, through the direct unlink. The correct
	 * behaviour is to delete the old sizes no other attachment names.
	 *
	 * How to recognise the fix: the old size files are gone after the rename
	 * and the pinned assertFileExists below fails.
	 */
	public function test_rename_leaves_the_old_size_files_on_disk_with_the_guard_on_pinned_for_deferred_fix() {
		$id        = $this->standardImage( 'emr-pll-orphan' );
		$old_file  = get_attached_file( $id );
		$old_sizes = $this->sizePaths( $id );
		foreach ( $old_sizes as $path ) {
			$this->flowFiles[] = $path;
		}
		$this->assertSame( '', $this->pllCrud->wp_delete_file( $old_sizes['medium'] ), 'Precondition: the guard counts the attachment itself.' );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'emr-pll-orphan-new-' . $this->token() . '.jpg' );

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
	 * still names, the mirror of the rename-of-the-original pin. A post that
	 * shows the shared URL is rewritten to the translation's new file (the
	 * replacer works by URL, not by language), so the post itself keeps a
	 * valid image; the original attachment does not.
	 *
	 * How to recognise the fix: the original's attached file exists after
	 * the rename and the pinned assertFileDoesNotExist below fails.
	 */
	public function test_rename_of_the_translation_deletes_the_original_file_pinned_for_deferred_fix() {
		$id      = $this->standardImage( 'emr-pll-dup-rn' );
		$dup_id  = $this->createPolylangTranslation( $id );
		$old_rel = $this->rawAttachedFile( $id );
		$url     = wp_get_attachment_url( $id );
		$post_id = $this->makePost( '<img src="' . $url . '" />' );

		$name = 'emr-pll-dup-rn-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $dup_id, $this->tmpJpeg( 400, 300, array( 200, 40, 40 ) ), $name );

		$this->assertStringEndsWith( '/' . $name, get_attached_file( $dup_id ), 'Sentinel: the translation was renamed.' );
		$this->assertFileExists( get_attached_file( $dup_id ), 'Sentinel: the translation has its new file.' );
		$this->assertSame( '<img src="' . wp_get_attachment_url( $dup_id ) . '" />', $this->rawContent( $post_id ), 'The post showing the shared URL now shows the translation file.' );

		$this->assertSame( $old_rel, $this->rawAttachedFile( $id ), 'Pinned: the original keeps its _wp_attached_file.' );
		$this->assertFileDoesNotExist( get_attached_file( $id ), 'Pinned: the original points at a missing file.' );
	}

	/**
	 * A same-name replace of the translation with the same dimensions
	 * changes the image for every language: the shared file and the shared
	 * size files are the new image, and the original's metadata stays valid
	 * because no size name changed.
	 */
	public function test_same_name_replace_of_the_translation_updates_the_image_for_every_language() {
		$id        = $this->standardImage( 'emr-pll-dup-same' );
		$dup_id    = $this->createPolylangTranslation( $id );
		$shared    = get_attached_file( $id );
		$meta      = $this->freshMeta( $id );
		$md5_main  = $this->md5OrNull( $shared );
		$md5_sizes = array_map( array( $this, 'md5OrNull' ), $this->sizePaths( $id ) );

		$this->replaceMode( $dup_id, $this->tmpJpeg( 400, 300, array( 200, 40, 40 ) ) );

		$this->assertSame( $shared, get_attached_file( $dup_id ), 'Sentinel: the translation keeps the shared file name.' );
		$this->assertSame( $shared, get_attached_file( $id ), 'The original still names the shared file.' );
		$this->assertNotSame( $md5_main, $this->md5OrNull( $shared ), 'The shared file is the new image.' );
		$this->assertSame( $meta, $this->freshMeta( $id ), 'The original metadata is unchanged.' );
		foreach ( $this->sizePaths( $id ) as $size => $path ) {
			$this->assertNotNull( $this->md5OrNull( $path ), "The original's $size file exists." );
			$this->assertNotSame( $md5_sizes[ $size ], $this->md5OrNull( $path ), "The original's $size file is the new image." );
		}
	}

	// -------------------------------------------------------------------
	// The permission gate
	// -------------------------------------------------------------------

	/**
	 * checkImagePermission() treats the translation as an ordinary
	 * attachment: the translation keeps the original's author, so the author
	 * may replace both, another author neither, an administrator both. EMR
	 * offers the replace on every language's copy.
	 */
	public function test_permission_gate_answers_the_same_for_the_translation_and_the_original() {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$id    = $this->standardImage( 'emr-pll-perm' );
		wp_update_post( array( 'ID' => $id, 'post_author' => $owner ) );
		$dup_id = $this->createPolylangTranslation( $id );
		$this->assertSame( (string) $owner, (string) get_post_field( 'post_author', $dup_id ), 'Precondition: the translation keeps the owner.' );

		$cases = array(
			'administrator' => array( $this->adminId, true ),
			'owner'         => array( $owner, true ),
			'other author'  => array( $other, false ),
		);
		foreach ( $cases as $label => $case ) {
			wp_set_current_user( $case[0] );
			$this->assertSame( $case[1], emr()->checkImagePermission( get_post( $id ) ), "$label on the original." );
			$this->assertSame( $case[1], emr()->checkImagePermission( get_post( $dup_id ) ), "$label on the translation." );
		}
	}
}
