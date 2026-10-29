<?php
/**
 * Helpers shared by the multisite test classes (trait).
 *
 * Not a test file (no `test-` prefix, so PHPUnit does not collect it); each
 * test file requires it.
 *
 * Subsites are created with the test factory. The WP test library turns
 * their CREATE TABLE statements into temporary tables, so the subsite's
 * rows vanish with the per-test rollback; its uploads directory
 * (`uploads/sites/N/`) does not, and is removed here in tear_down.
 *
 * Attachments uploaded while switched to a subsite are taken off the base
 * class's attachment list: that list is deleted in tear_down while the main
 * site is current, where the same id would name a different post. The
 * subsite's files go with its uploads directory instead.
 *
 * Plugin singletons cache per-site state at construction (EnvHelper the
 * main-site flag, ApiKeyManager the key), so enterSite() and leaveSite()
 * reset them after every switch.
 *
 * @package Enable_Media_Replace
 */

trait EMR_MultisiteHelpers {

	/** @var string[] Uploads base directories of the subsites created by the test. */
	protected $msSiteDirs = array();

	/** Skips the test on a single-site install. Call BEFORE parent::set_up(). */
	protected function skipUnlessMultisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite test install — run via bin/test.sh --ms.' );
		}
	}

	/** Call from tear_down() before the other tear_down helpers. */
	protected function msTearDown() {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		// emr() points the debug log at the uploads folder of the site that
		// is current when it is called; called while switched, the log file
		// lives in the subsite folder removed below. Point it back first.
		emr();
		foreach ( $this->msSiteDirs as $dir ) {
			if ( is_dir( $dir ) ) {
				$this->msRemoveTree( $dir );
			}
		}
		$this->msSiteDirs = array();
		$this->resetPluginSingletons();
	}

	/**
	 * Creates a subsite and records its uploads directory for removal.
	 *
	 * @return int Blog id (always > 1).
	 */
	protected function newSite() {
		$blog_id = (int) self::factory()->blog->create();
		$this->assertGreaterThan( 1, $blog_id, 'Sentinel: the factory created a subsite.' );

		switch_to_blog( $blog_id );
		try {
			$uploads = wp_upload_dir(); // creates the folder, which emr()'s debug log may write into.
			$this->assertStringEndsWith( '/sites/' . $blog_id, untrailingslashit( $uploads['basedir'] ), 'Sentinel: the subsite uploads under sites/N.' );
			$this->msSiteDirs[] = untrailingslashit( $uploads['basedir'] );
		} finally {
			restore_current_blog();
		}
		return $blog_id;
	}

	/** Switches to a site and drops the plugin singletons built for the previous one. */
	protected function enterSite( $blog_id ) {
		switch_to_blog( $blog_id );
		$this->resetPluginSingletons();
	}

	/** Leaves the site entered last and drops the singletons built there. */
	protected function leaveSite() {
		restore_current_blog();
		$this->resetPluginSingletons();
	}

	/**
	 * Runs an upload callback on the current (sub)site and takes the
	 * attachment off the base class's main-site deletion list.
	 *
	 * @param callable $upload Returns an attachment id (uploadFixture(), uploadJpeg(), ...).
	 * @return int Attachment id.
	 */
	protected function subsiteUpload( $upload ) {
		$this->assertFalse( is_main_site(), 'Sentinel: subsiteUpload() runs while switched to a subsite.' );
		$before = count( $this->uploadedAttachments );
		$id     = call_user_func( $upload );
		array_splice( $this->uploadedAttachments, $before );
		return $id;
	}

	/** Uploads base directory of the current site, without trailing slash. */
	protected function baseDir() {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( $uploads['basedir'] );
	}

	/** Recursively removes a directory. */
	protected function msRemoveTree( $dir ) {
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->msRemoveTree( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
