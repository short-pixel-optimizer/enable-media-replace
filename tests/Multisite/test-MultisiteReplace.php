<?php
/**
 * Multisite: the replace flow on a subsite, and the `sites/` guard.
 *
 * Driven like tests/Integration/test-ReplaceFlow.php (a getPost()-shaped
 * `$params` array, ReplaceController::setupParams() then run(), as an
 * administrator), but while switched to a subsite. A subsite uploads into
 * `uploads/sites/N/` and has its own posts, postmeta and options tables;
 * the bundled Replacer queries through the current `$wpdb` prefix, so a
 * replace on a subsite must rewrite that subsite's references and leave
 * the main site's rows alone, even where they hold the very same URL text.
 *
 * The single-site suite already covers the shapes of the rewrite (srcset,
 * blocks, serialized meta, statuses); here each test seeds the minimum
 * needed to tell the two sites apart: one matching row per meta table.
 *
 * The guard: ReplaceController::getNewTargetLocation() refuses, on the main
 * site of a network, any new location that resolves to `<basedir>sites...`
 * (ERROR_DIRECTORY_SECURITY, with `path` and `basedir` as error data). On a
 * subsite the base directory is already `uploads/sites/N`, so the
 * traversal check confines every location to that subsite's own folder.
 *
 * Cleanup: the subsite uploads directories (EMR_MultisiteHelpers), the
 * main-site attachments and folders (EMR_ReplaceFlowHelpers), singletons
 * (base class).
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/MultisiteHelpers.php';

use EnableMediaReplace\Controller\ReplaceController;

class MultisiteReplaceTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_MultisiteHelpers;

	public function set_up() {
		$this->skipUnlessMultisite();
		parent::set_up();
		$this->flowSetUp();
	}

	public function tear_down() {
		$this->msTearDown();
		$this->flowTearDown();
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Plumbing.
	// ---------------------------------------------------------------------

	/**
	 * A 400x300 JPEG on the current subsite (sizes thumbnail and medium).
	 *
	 * @return array( id, path, main url, thumbnail url, medium url )
	 */
	private function subsiteImage( $name = null ) {
		$name = ( null === $name ) ? 'emr-ms-' . $this->token() . '.jpg' : $name;
		$self = $this;
		$id   = $this->subsiteUpload(
			function () use ( $self, $name ) {
				return $self->uploadJpeg( $name, 400, 300 );
			}
		);
		return array( $id, get_attached_file( $id ), wp_get_attachment_url( $id ), $this->sizeUrl( $id, 'thumbnail' ), $this->sizeUrl( $id, 'medium' ) );
	}

	/** setupParams() for a search-replace with a new location; returns the controller. */
	private function setupNewLocation( $id, $location, $name ) {
		$controller = new ReplaceController( $id );
		$params     = $this->flowParams(
			$id,
			$this->tmpJpeg( 400, 300 ),
			$name,
			array(
				'replace_type' => 'replace_and_search',
				'new_location' => true,
				'location_dir' => $location,
			)
		);
		$this->lastSetup = $controller->setupParams( $params );
		return $controller;
	}

	/** @var bool|null Result of the last setupNewLocation(). */
	private $lastSetup;

	/** Full target path the controller resolved, read from its targetFile. */
	private function targetPath( ReplaceController $controller ) {
		$prop = new ReflectionProperty( ReplaceController::class, 'targetFile' );
		$prop->setAccessible( true );
		$file = $prop->getValue( $controller );
		$this->assertIsObject( $file, 'Sentinel: setupParams() resolved a target file.' );
		return $file->getFullPath();
	}

	// ---------------------------------------------------------------------
	// MODE_REPLACE on a subsite.
	// ---------------------------------------------------------------------

	public function test_replace_mode_on_a_subsite_writes_the_new_bytes_under_its_sites_directory_and_regenerates_the_metadata_there() {
		$blog = $this->newSite();
		$this->enterSite( $blog );
		list( $id, $path, $url ) = $this->subsiteImage();
		$base                    = $this->baseDir();
		$this->assertStringStartsWith( $base . '/', $path, 'Sentinel: the subsite file lives under sites/N/.' );
		$this->assertStringContainsString( '/sites/' . $blog . '/', $url );
		$raw = $this->rawAttachedFile( $id );
		$tmp = $this->tmpJpeg( 600, 400, array( 10, 200, 10 ) );
		$md5 = md5_file( $tmp );

		$this->replaceMode( $id, $tmp );

		$this->assertSame( $path, get_attached_file( $id ), 'Replaced in place.' );
		$this->assertSame( $raw, $this->rawAttachedFile( $id ), '_wp_attached_file stays relative to the subsite base directory.' );
		$this->assertStringStartsNotWith( '/', $raw );
		$this->assertStringNotContainsString( 'sites/', $raw, 'The stored path is relative to sites/N, not to the network uploads.' );
		$this->assertSame( $md5, md5_file( $path ) );
		$this->assertSame( $url, wp_get_attachment_url( $id ) );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 600, $meta['width'] );
		$this->assertSame( 400, $meta['height'] );
		$this->assertSame( $raw, $meta['file'] );
		$this->assertNotEmpty( $meta['sizes'] );
		foreach ( $this->sizePaths( $id ) as $size => $file ) {
			$this->assertStringStartsWith( $base . '/', $file, "The $size file is generated under sites/N/." );
			$this->assertFileExists( $file );
		}
		$this->assertStringContainsString( '/sites/' . $blog . '/', $this->sizeUrl( $id, 'medium' ) );
	}

	public function test_replace_mode_on_a_subsite_rewrites_the_changed_size_there_and_not_in_a_main_site_post_with_the_same_text() {
		$blog = $this->newSite();
		$this->enterSite( $blog );
		list( $id, , , , $medium ) = $this->subsiteImage();
		$content                   = '<img src="' . $medium . '" />';
		$sub_post                  = $this->makePost( $content );
		$this->leaveSite();
		$main_post = $this->makePost( $content );
		$this->enterSite( $blog );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertNotSame( $medium, $new_medium, 'Sentinel: 300x225 became 300x200.' );
		$this->assertSame( '<img src="' . $new_medium . '" />', $this->rawContent( $sub_post ), 'The subsite post is rewritten.' );
		$this->leaveSite();
		$this->assertSame( $content, $this->rawContent( $main_post ), 'The main-site post keeps its text.' );
	}

	public function test_a_subsite_replace_leaves_the_main_site_file_of_the_same_relative_name_untouched() {
		$name    = 'emr-ms-twin-' . $this->token() . '.jpg';
		$main_id = $this->uploadJpeg( $name, 400, 300 );
		$main    = get_attached_file( $main_id );
		$main_md5   = md5_file( $main );
		$main_meta  = wp_get_attachment_metadata( $main_id );
		$main_sizes = $this->sizePaths( $main_id );
		$blog       = $this->newSite();
		$this->enterSite( $blog );
		list( $id, $path ) = $this->subsiteImage( $name );
		$this->assertSame( _wp_relative_upload_path( $path ), $this->rawAttachedFile( $id ) );
		$this->leaveSite();
		$this->assertSame( _wp_relative_upload_path( $main ), $this->rawAttachedFile( $main_id ) );
		$this->enterSite( $blog );
		$this->assertSame( wp_basename( $main ), wp_basename( $path ), 'Sentinel: both sites hold the same file name.' );
		$this->assertNotSame( $main, $path );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );
		$this->leaveSite();

		$this->assertSame( $main_md5, md5_file( $main ), 'The main-site file keeps its bytes.' );
		$this->assertSame( $main_meta, wp_get_attachment_metadata( $main_id ), 'The main-site metadata is unchanged.' );
		foreach ( $main_sizes as $size => $file ) {
			$this->assertFileExists( $file, "The main-site $size file is still there." );
		}
	}

	// ---------------------------------------------------------------------
	// MODE_SEARCHREPLACE on a subsite.
	// ---------------------------------------------------------------------

	public function test_search_replace_on_a_subsite_rewrites_its_post_postmeta_and_option_and_none_on_the_main_site() {
		$blog = $this->newSite();
		$this->enterSite( $blog );
		list( $id, $path, $url, $thumb ) = $this->subsiteImage();
		$content                         = '<img src="' . $url . '" /><img src="' . $thumb . '" />';
		$sub_post                        = $this->makePost( $content );
		$sub_carrier                     = $this->makePost( 'carrier' );
		add_post_meta( $sub_carrier, '_emr_ms_meta', $url );
		update_option( 'emr_ms_widget', 'logo:' . $url );
		$this->leaveSite();

		$main_post    = $this->makePost( $content );
		$main_carrier = $this->makePost( 'carrier' );
		add_post_meta( $main_carrier, '_emr_ms_meta', $url );
		update_option( 'emr_ms_widget', 'logo:' . $url );
		$this->enterSite( $blog );
		$name = 'emr-ms-renamed-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_path = dirname( $path ) . '/' . $name;
		$new_url  = wp_get_attachment_url( $id );
		$this->assertSame( $new_path, get_attached_file( $id ) );
		$this->assertFileExists( $new_path );
		$this->assertFileDoesNotExist( $path );
		$this->assertStringContainsString( '/sites/' . $blog . '/', $new_url );
		$this->assertStringEndsWith( '/' . $name, $new_url );
		$this->assertSame( $new_url, get_post( $id )->guid );
		$this->assertSame( '<img src="' . $new_url . '" /><img src="' . $this->sizeUrl( $id, 'thumbnail' ) . '" />', $this->rawContent( $sub_post ) );
		$this->assertSame( $new_url, $this->rawPostMeta( $sub_carrier, '_emr_ms_meta' ) );
		$this->assertSame( 'logo:' . $new_url, $this->rawOption( 'emr_ms_widget' ) );
		$this->assertSame( 'logo:' . $new_url, get_option( 'emr_ms_widget' ), 'The cached option reads fresh.' );
		$this->leaveSite();

		$this->assertSame( $content, $this->rawContent( $main_post ), 'Main-site post untouched.' );
		$this->assertSame( $url, $this->rawPostMeta( $main_carrier, '_emr_ms_meta' ), 'Main-site postmeta untouched.' );
		$this->assertSame( 'logo:' . $url, $this->rawOption( 'emr_ms_widget' ), 'Main-site option untouched.' );
		$this->assertSame( 'logo:' . $url, get_option( 'emr_ms_widget' ) );
	}

	/**
	 * `usermeta` is one table for the whole network; the Replacer queries it
	 * as `$wpdb->usermeta` whatever site is current, so a subsite replace
	 * rewrites a user meta value that holds the subsite URL. Only the URL
	 * that is replaced matches: the user's other values are left alone.
	 */
	public function test_search_replace_on_a_subsite_rewrites_a_matching_value_in_the_network_wide_usermeta_table() {
		$user = self::factory()->user->create();
		$blog = $this->newSite();
		$this->enterSite( $blog );
		list( $id, , $url ) = $this->subsiteImage();
		add_user_meta( $user, 'emr_ms_avatar', $url );
		add_user_meta( $user, 'emr_ms_other', 'https://example.org/unrelated.jpg' );
		$name = 'emr-ms-user-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url = wp_get_attachment_url( $id );
		$this->leaveSite();
		$this->assertSame( $new_url, get_user_meta( $user, 'emr_ms_avatar', true ), 'Read back from the main site: the network-wide row changed.' );
		$this->assertSame( 'https://example.org/unrelated.jpg', get_user_meta( $user, 'emr_ms_other', true ) );
	}

	public function test_search_replace_on_a_subsite_with_a_new_location_moves_the_file_and_its_sizes_inside_its_own_sites_directory() {
		$main_base = $this->baseDir();
		$folder    = '19' . wp_rand( 70, 89 ) . '/06';
		$this->assertDirectoryDoesNotExist( $main_base . '/' . $folder, 'Sentinel: the folder is new on the main site.' );
		$blog = $this->newSite();
		$this->enterSite( $blog );
		list( $id, $path, $url ) = $this->subsiteImage();
		$base                    = $this->baseDir();
		$post                    = $this->makePost( '<img src="' . $url . '" />' );
		$old_sizes               = $this->sizePaths( $id );
		$name                    = 'emr-ms-moved-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name, array( 'new_location' => true, 'location_dir' => $folder ) );

		$new_path = $base . '/' . $folder . '/' . $name;
		$this->assertSame( $new_path, get_attached_file( $id ) );
		$this->assertSame( $folder . '/' . $name, $this->rawAttachedFile( $id ) );
		$this->assertFileExists( $new_path );
		$this->assertFileDoesNotExist( $path );
		foreach ( $old_sizes as $old ) {
			$this->assertFileDoesNotExist( $old );
		}
		foreach ( $this->sizePaths( $id ) as $size => $file ) {
			$this->assertSame( $base . '/' . $folder, dirname( $file ), "The $size file is generated in the new subsite folder." );
			$this->assertFileExists( $file );
		}
		$new_url = wp_get_attachment_url( $id );
		$this->assertStringContainsString( '/sites/' . $blog . '/' . $folder . '/', $new_url );
		$this->assertSame( '<img src="' . $new_url . '" />', $this->rawContent( $post ) );
		$this->leaveSite();
		$this->assertDirectoryDoesNotExist( $main_base . '/' . $folder, 'Nothing is created in the main-site uploads.' );
	}

	// ---------------------------------------------------------------------
	// The sites/ guard.
	// ---------------------------------------------------------------------

	public function test_on_the_main_site_a_new_location_inside_sites_is_refused_with_the_directory_security_error() {
		$blog = $this->newSite();
		$id        = $this->uploadJpeg( 'emr-ms-guard-' . $this->token() . '.jpg', 400, 300 );
		$path      = get_attached_file( $id );
		$md5       = md5_file( $path );
		$base      = $this->baseDir();
		$real_base = trailingslashit( realpath( $base ) );
		$this->assertTrue( emr()->env()->is_mainsite, 'Precondition: the main site.' );
		$this->assertTrue( emr()->env()->is_multisite );

		$cases = array(
			'sites'                     => $real_base . 'sites/',
			'sites/' . $blog            => $real_base . 'sites/' . $blog . '/',
			'./sites/' . $blog . '/1987' => $real_base . 'sites/' . $blog . '/1987/',
			'/sites/' . $blog . '/'     => $real_base . 'sites/' . $blog . '/',
		);
		foreach ( $cases as $location => $resolved ) {
			$controller = $this->setupNewLocation( $id, $location, 'emr-ms-guarded.jpg' );

			$this->assertFalse( $this->lastSetup, "'$location' is refused." );
			$this->assertSame( ReplaceController::ERROR_DIRECTORY_SECURITY, $controller->returnLastError(), "'$location' gives ERROR_DIRECTORY_SECURITY." );
			$this->assertSame( 24, $controller->returnLastError() );
			$this->assertSame(
				array(
					'path'    => $resolved,
					'basedir' => $real_base,
				),
				$controller->returnLastErrorData(),
				"'$location' reports the resolved path and the base directory."
			);
		}
		$this->assertSame( $path, get_attached_file( $id ), 'The attachment is untouched.' );
		$this->assertSame( $md5, md5_file( $path ) );
		$this->assertDirectoryDoesNotExist( $base . '/sites/' . $blog . '/1987', 'No folder is created in the subsite.' );
	}

	/**
	 * The guard is a prefix match on `<basedir>sites` (documented on
	 * getNewTargetLocation()): a main-site folder whose name only STARTS
	 * with `sites` is refused too, while a `sites` folder deeper in the
	 * tree is accepted.
	 */
	public function test_on_the_main_site_the_guard_matches_the_sites_prefix_at_the_top_of_uploads_only() {
		$id        = $this->uploadJpeg( 'emr-ms-prefix-' . $this->token() . '.jpg', 400, 300 );
		$base      = $this->baseDir();
		$real_base = trailingslashit( realpath( $base ) );

		$refused = $this->setupNewLocation( $id, 'sites-archive/1987', 'emr-ms-prefix.jpg' );
		$this->assertFalse( $this->lastSetup, 'A top-level folder named sites-archive is refused.' );
		$this->assertSame( ReplaceController::ERROR_DIRECTORY_SECURITY, $refused->returnLastError() );

		$deep = 'emr-ms-gallery-' . $this->token() . '/sites/1987';
		$accepted = $this->setupNewLocation( $id, $deep, 'emr-ms-prefix.jpg' );
		$this->assertTrue( $this->lastSetup, 'A sites folder below another folder is accepted.' );
		$this->assertNull( $accepted->returnLastError() );
		$this->assertSame( $real_base . $deep . '/emr-ms-prefix.jpg', $this->targetPath( $accepted ) );
		$this->assertDirectoryDoesNotExist( $base . '/' . dirname( dirname( $deep ) ), 'setupParams() creates no folder.' );
	}

	/**
	 * On a subsite the base directory is `uploads/sites/N`: a `sites/M`
	 * location (another subsite's id) and a location climbing out with `..`
	 * both resolve inside the subsite's own folder (normalizePath() drops
	 * `..`), and the guard, which only runs on the main site, does not
	 * refuse them. A real replace into such a location writes nothing in the
	 * other subsite's folder.
	 */
	public function test_on_a_subsite_a_sites_location_is_accepted_and_stays_inside_its_own_folder() {
		$other = $this->newSite();
		$blog  = $this->newSite();
		$this->assertNotSame( $other, $blog );
		$this->enterSite( $other );
		$other_base = $this->baseDir();
		$this->leaveSite();
		$this->enterSite( $blog );
		$this->assertFalse( emr()->env()->is_mainsite, 'Precondition: EnvHelper built on the subsite.' );
		list( $id, $path ) = $this->subsiteImage();
		$base              = $this->baseDir();
		$real_base         = trailingslashit( realpath( $base ) );

		$controller = $this->setupNewLocation( $id, 'sites/' . $other . '/1987', 'emr-ms-own.jpg' );
		$this->assertTrue( $this->lastSetup );
		$this->assertNull( $controller->returnLastError() );
		$this->assertSame( $real_base . 'sites/' . $other . '/1987/emr-ms-own.jpg', $this->targetPath( $controller ) );

		$name = 'emr-ms-climb-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name, array( 'new_location' => true, 'location_dir' => '../../' . $other . '/1987' ) );

		$this->assertSame( $base . '/' . $other . '/1987/' . $name, get_attached_file( $id ), 'The climb lands inside sites/N.' );
		$this->assertFileExists( $base . '/' . $other . '/1987/' . $name );
		$this->assertFileDoesNotExist( $path );
		$this->assertDirectoryDoesNotExist( $other_base . '/1987', "Nothing is written into the other subsite's folder." );
	}

	/**
	 * The guard reads the main-site flag from EnvHelper, which computes its
	 * flags once per request (EnvHelper::setWP() in the constructor) and
	 * never refreshes them. When the helper was first built while a subsite
	 * was current and the code then switches to the main site with
	 * switch_to_blog() / restore_current_blog(), getNewTargetLocation()
	 * still sees `is_mainsite` false and accepts a main-site location
	 * inside `sites/N/`, so a main-site file can be moved into a subsite's
	 * uploads folder. The guard should ask is_main_site() at call time.
	 *
	 * How to recognise the fix: setupParams() returns false with
	 * ERROR_DIRECTORY_SECURITY, and the assertions on the accepted target
	 * fail.
	 */
	public function test_the_main_site_guard_uses_the_flag_cached_on_a_subsite_pinned_for_deferred_fix() {
		$blog = $this->newSite();
		$id   = $this->uploadJpeg( 'emr-ms-stale-' . $this->token() . '.jpg', 400, 300 );
		$base = $this->baseDir();
		$this->enterSite( $blog );
		$this->assertFalse( emr()->env()->is_mainsite, 'Sentinel: the helper is built on the subsite.' );
		restore_current_blog(); // No singleton reset: the cached flags survive, as in one request.
		$this->assertTrue( is_main_site(), 'Precondition: the main site is current again.' );
		$this->assertFalse( emr()->env()->is_mainsite, 'Precondition: the cached flag still says subsite.' );

		$controller = $this->setupNewLocation( $id, 'sites/' . $blog . '/1987', 'emr-ms-stale.jpg' );

		$this->assertTrue( $this->lastSetup, 'Pinned: the main-site location inside sites/ is accepted.' );
		$this->assertNull( $controller->returnLastError() );
		$this->assertSame( trailingslashit( realpath( $base ) ) . 'sites/' . $blog . '/1987/emr-ms-stale.jpg', $this->targetPath( $controller ) );
	}
}
