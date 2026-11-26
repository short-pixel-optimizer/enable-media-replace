<?php
/**
 * Shared helpers for the bulk unit tests (tests/Controller/Bulk).
 *
 * Image attachments are built with GD so width-dependent behaviour
 * (upscale factor, threshold) can be asserted. Every attachment, file and
 * option a test creates is removed in bulkTearDown(); the shared ShortQ
 * table rows of the EMR slug are deleted too, because CREATE TABLE commits
 * the test transaction.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\InstallHelper;

trait EMR_BulkTestHelpers {

	/** @var int[] */
	protected $bulkAttachments = array();

	/** @var string[] */
	protected $bulkFiles = array();

	/** @var array<string,array{exists: bool, value: mixed}> */
	protected $bulkOptionSnapshot = array();

	protected function bulkSetUp() {
		$this->bulkOptionSnapshot = array();
		foreach ( array( 'emr_shortpixel_api_key', 'emr_bulk_logs', 'enable_media_replace' ) as $name ) {
			$missing = new stdClass();
			$value   = get_option( $name, $missing );
			$this->bulkOptionSnapshot[ $name ] = ( $value === $missing ) ? array( 'exists' => false, 'value' => null ) : array( 'exists' => true, 'value' => $value );
		}
		delete_transient( 'emr_bulk_plan_ok' );
		$this->resetBulkSingletons();
	}

	protected function bulkTearDown() {
		global $wpdb;
		foreach ( $this->bulkAttachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->bulkAttachments = array();
		foreach ( $this->bulkFiles as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
		$this->bulkFiles = array();

		foreach ( $this->bulkOptionSnapshot as $name => $snapshot ) {
			if ( $snapshot['exists'] ) {
				update_option( $name, $snapshot['value'] );
			} else {
				delete_option( $name );
			}
		}
		delete_transient( 'emr_bulk_plan_ok' );
		wp_clear_scheduled_hook( 'emr_bulk_cron' );

		$table = $wpdb->prefix . 'shortpixel_queue';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE plugin_slug = %s", InstallHelper::QUEUE_SLUG ) );
		}
		delete_option( 'shortqwp_' . InstallHelper::QUEUE_SLUG );

		$backup = BackupController::getBackupPath();
		if ( is_dir( $backup ) ) {
			$this->removeTreeBulk( $backup );
		}
		$this->resetBulkSingletons();
	}

	protected function resetBulkSingletons() {
		foreach ( array(
			'EnableMediaReplace\Controller\Bulk\BulkApi'          => 'instance',
			'EnableMediaReplace\Controller\Bulk\ActionController' => 'instances',
			'EnableMediaReplace\Controller\Bulk\BulkController'   => 'instance',
			'EnableMediaReplace\Controller\Bulk\CronController'   => 'instance',
			'EnableMediaReplace\Controller\Bulk\BulkQueue'        => 'isInQueue',
			'EnableMediaReplace\ApiKeyManager'                    => 'instance',
		) as $class => $property ) {
			$prop = new ReflectionProperty( $class, $property );
			$prop->setAccessible( true );
			$prop->setValue( null, ( 'instances' === $property || 'isInQueue' === $property ) ? array() : null );
		}
		\EnableMediaReplace\Controller\Bulk\ResponseController::reset();
	}

	protected function removeTreeBulk( $dir ) {
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $name ) {
			$path = $dir . '/' . $name;
			is_dir( $path ) ? $this->removeTreeBulk( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	/**
	 * A real JPEG (or PNG) attachment of the given size in the uploads folder.
	 *
	 * @return int Attachment ID.
	 */
	protected function makeImageAttachment( $width = 400, $height = 300, $type = 'jpg', $name = null ) {
		$uploads = wp_upload_dir();
		$name    = $name ? $name : 'emr-bulk-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.' . $type;
		$path    = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], $name );

		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 30, 120, 200 ) );
		if ( 'png' === $type ) {
			imagepng( $image, $path );
		} else {
			imagejpeg( $image, $path, 90 );
		}
		imagedestroy( $image );
		$this->bulkFiles[] = $path;

		$id = wp_insert_attachment( array(
			'post_mime_type' => ( 'png' === $type ) ? 'image/png' : 'image/jpeg',
			'post_title'     => pathinfo( $path, PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
		), $path );
		$this->assertGreaterThan( 0, $id );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
		$this->bulkAttachments[] = $id;
		return $id;
	}

	/** A non-image attachment (text file). */
	protected function makeTextAttachment() {
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'emr-bulk-' . uniqid() . '.txt' );
		file_put_contents( $path, "not an image\n" );
		$this->bulkFiles[] = $path;
		$id = wp_insert_attachment( array( 'post_mime_type' => 'text/plain', 'post_title' => 'text', 'post_status' => 'inherit' ), $path );
		$this->bulkAttachments[] = $id;
		return $id;
	}

	/** Rows of the EMR queue, optionally for one item. */
	protected function queueRows( $item_id = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_queue';
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE plugin_slug = %s AND queue_name = %s", InstallHelper::QUEUE_SLUG, InstallHelper::QUEUE_NAME );
		if ( null !== $item_id ) {
			$sql .= $wpdb->prepare( ' AND item_id = %d', $item_id );
		}
		return $wpdb->get_results( $sql . ' ORDER BY item_id DESC' );
	}
}
