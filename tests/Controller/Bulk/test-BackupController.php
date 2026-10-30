<?php
/**
 * Tests for EnableMediaReplace\Controller\Bulk\BackupController
 * (classes/Controller/Bulk/BackupController.php).
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';

use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\Model\Bulk\BulkImage;

class BulkBackupControllerTest extends WP_UnitTestCase {

	use EMR_BulkTestHelpers;

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
	}

	public function tear_down() {
		remove_all_filters( 'emr/bulk/backup_dir' );
		$this->bulkTearDown();
		parent::tear_down();
	}

	public function test_backup_path_is_EMR_Backups_under_the_uploads_base_and_filterable() {
		$uploads = wp_get_upload_dir();
		$this->assertSame( untrailingslashit( $uploads['basedir'] ) . '/EMR_Backups', BackupController::getBackupPath() );

		add_filter( 'emr/bulk/backup_dir', function () {
			return '/tmp/emr-other-backups/';
		} );
		$this->assertSame( '/tmp/emr-other-backups', BackupController::getBackupPath() );
	}

	public function test_relative_path_mirrors_the_attachments_upload_subfolder() {
		$image    = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$attached = get_post_meta( $image->get( 'id' ), '_wp_attached_file', true );

		$this->assertSame( $attached, BackupController::getRelativePath( $image ), 'yyyy/mm/name.jpg, as the upload itself.' );
	}

	public function test_backup_copies_the_main_file_once_and_keeps_an_existing_backup() {
		$image    = BulkImage::getById( $this->makeImageAttachment( 120, 80 ) );
		$original = file_get_contents( $image->getFile()->getFullPath() );

		$relative = BackupController::backup( $image );

		$this->assertIsString( $relative );
		$backup = BackupController::getBackupFile( $relative );
		$this->assertTrue( $backup->exists() );
		$this->assertSame( $original, file_get_contents( $backup->getFullPath() ) );
		$this->assertStringStartsWith( BackupController::getBackupPath() . '/', $backup->getFullPath() );

		// A second run must not overwrite the true original.
		file_put_contents( $image->getFile()->getFullPath(), 'changed bytes' );
		$this->assertSame( $relative, BackupController::backup( $image ) );
		$this->assertSame( $original, file_get_contents( $backup->getFullPath() ) );
	}

	public function test_backup_returns_false_when_the_source_file_is_missing() {
		$image = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		unlink( $image->getFile()->getFullPath() );
		$this->assertFalse( BackupController::backup( $image ) );
	}

	public function test_hasBackup_reads_the_marker_and_checks_the_file() {
		$image = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$this->assertFalse( BackupController::hasBackup( $image ), 'No marker.' );

		$relative = BackupController::backup( $image );
		$image->setMarker( 'background', array( 'ts' => time() ), $relative );
		$this->assertTrue( BackupController::hasBackup( $image ) );

		$this->assertTrue( BackupController::deleteBackup( $relative ) );
		$this->assertFalse( BackupController::getBackupFile( $relative )->exists() );
		$this->assertFalse( BackupController::hasBackup( $image ), 'Marker without file.' );
		$this->assertTrue( BackupController::deleteBackup( $relative ), 'Deleting a missing backup is fine.' );
	}

	public function test_getBackupFile_strips_parent_directory_segments() {
		$file = BackupController::getBackupFile( '../../wp-config.php' );
		$this->assertStringStartsWith( BackupController::getBackupPath() . '/', $file->getFullPath() );
		$this->assertStringNotContainsString( '..', $file->getFullPath() );
	}

	public function test_getBackupUrl_maps_the_backup_into_the_uploads_url() {
		$image    = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$relative = BackupController::backup( $image );
		$uploads  = wp_get_upload_dir();

		$this->assertSame( $uploads['baseurl'] . '/EMR_Backups/' . $relative, BackupController::getBackupUrl( $relative ) );
		$this->assertFalse( BackupController::getBackupUrl( 'nope/missing.jpg' ) );
	}
}
