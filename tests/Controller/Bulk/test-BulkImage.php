<?php
/**
 * Tests for EnableMediaReplace\Model\Bulk\BulkImage (classes/Model/Bulk/BulkImage.php).
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';

use EnableMediaReplace\Model\Bulk\BulkImage;

class BulkImageTest extends WP_UnitTestCase {

	/**
	 * Creates the shared `shortpixel_queue` table once, outside the test
	 * transaction. ShortQ installs it with CREATE TABLE and ALTER TABLE on
	 * first use; inside a test WP_UnitTestCase rewrites the CREATE into a
	 * TEMPORARY table (invisible to SHOW TABLES, dropped per connection) and
	 * the ALTER commits the transaction, which leaks every option written
	 * before it into later tests. Installed up front, the in-test install is
	 * a no-op (dbDelta sees the table, the unique key exists).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		( new \EnableMediaReplace\ShortQ\ShortQ( \EnableMediaReplace\InstallHelper::QUEUE_SLUG ) )->getQueue( \EnableMediaReplace\InstallHelper::QUEUE_NAME )->install();
	}

	use EMR_BulkTestHelpers;

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
	}

	public function tear_down() {
		$this->bulkTearDown();
		parent::tear_down();
	}

	public function test_getById_returns_false_for_a_missing_post_a_non_attachment_and_a_non_image() {
		$post = self::factory()->post->create();
		$this->assertFalse( BulkImage::getById( 999999 ) );
		$this->assertFalse( BulkImage::getById( $post ) );
		$this->assertFalse( BulkImage::getById( $this->makeTextAttachment() ) );
	}

	public function test_getById_wraps_an_image_attachment_and_answers_the_queue_layers_questions() {
		$id    = $this->makeImageAttachment( 640, 480 );
		$image = BulkImage::getById( $id );

		$this->assertInstanceOf( BulkImage::class, $image );
		$this->assertSame( $id, $image->get( 'id' ) );
		$this->assertSame( 'media', $image->get( 'type' ) );
		$this->assertNull( $image->get( 'something_else' ) );
		$this->assertSame( basename( get_attached_file( $id ) ), $image->getFileName() );
		$this->assertSame( wp_get_attachment_url( $id ), $image->getUrl() );
		$this->assertSame( 640, $image->getWidth() );
		$this->assertSame( 'image/jpeg', $image->getMime() );
		$this->assertTrue( $image->exists() );
		$this->assertTrue( $image->isSupportedType() );
		$this->assertSame( get_attached_file( $id ), $image->getFile()->getFullPath() );
	}

	public function test_isSupportedType_accepts_jpeg_and_png_only() {
		$this->assertTrue( BulkImage::getById( $this->makeImageAttachment( 10, 10, 'png' ) )->isSupportedType() );

		$id = $this->makeImageAttachment( 10, 10 );
		wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/gif' ) );
		$this->assertFalse( BulkImage::getById( $id )->isSupportedType() );
		wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/x-png' ) );
		$this->assertFalse( BulkImage::getById( $id )->isSupportedType(), 'Exact mime types only, the list the upscale selector queries.' );
		$this->assertSame( BulkImage::MIME_TYPES, \EnableMediaReplace\Controller\Bulk\Selector\UpscaleCandidates::MIME_TYPES );
	}

	/**
	 * The factor table is UIHelper::getUpscaleFactors(): 4x up to 1024 px,
	 * 3x and 2x up to 1200 px. The largest factor the width allows wins;
	 * above 1200 px none fits.
	 */
	public function test_getUpscaleFactor_picks_the_largest_factor_the_width_allows() {
		$this->assertSame( 4, BulkImage::getById( $this->makeImageAttachment( 1024, 10 ) )->getUpscaleFactor() );
		$this->assertSame( 3, BulkImage::getById( $this->makeImageAttachment( 1025, 10 ) )->getUpscaleFactor() );
		$this->assertSame( 3, BulkImage::getById( $this->makeImageAttachment( 1200, 10 ) )->getUpscaleFactor() );
		$this->assertSame( 0, BulkImage::getById( $this->makeImageAttachment( 1201, 10 ) )->getUpscaleFactor() );
	}

	public function test_getUpscaleFactor_is_zero_without_a_known_width() {
		$id = $this->makeImageAttachment( 100, 100 );
		delete_post_meta( $id, '_wp_attachment_metadata' );
		$image = BulkImage::getById( $id );
		$this->assertSame( 0, $image->getWidth() );
		$this->assertSame( 0, $image->getUpscaleFactor() );
	}

	/** One meta key per operation (SPIO's one-flag-per-fact style), merged by getMarker(). */
	public function test_marker_round_trip_stores_one_meta_key_per_operation() {
		$image = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$id    = $image->get( 'id' );
		$this->assertSame( '_emr_bulk_background', BulkImage::getMarkerKey( 'background' ) );
		$this->assertSame( '_emr_bulk_upscale', BulkImage::getMarkerKey( 'upscale' ) );
		$this->assertSame( array(), $image->getMarker() );
		$this->assertFalse( $image->hasOperation( 'background' ) );

		$image->setMarker( 'background', array( 'ts' => 1700000000, 'bg_remove' => 1 ), '2026/10/a.jpg' );
		$marker = $image->getMarker();
		$this->assertSame( array( 'ts' => 1700000000, 'bg_remove' => 1 ), $marker['background'] );
		$this->assertSame( '2026/10/a.jpg', $marker['backup'] );
		$this->assertSame( array( 'ts' => 1700000000, 'bg_remove' => 1, 'backup' => '2026/10/a.jpg' ), get_post_meta( $id, '_emr_bulk_background', true ), 'The operation\'s own key holds its data and the backup.' );
		$this->assertSame( '', get_post_meta( $id, '_emr_bulk_upscale', true ) );
		$this->assertTrue( $image->hasOperation( 'background' ) );
		$this->assertFalse( $image->hasOperation( 'upscale' ) );

		$image->setMarker( 'upscale', array( 'factor' => 2 ), '2026/10/a.jpg' );
		$this->assertTrue( $image->hasOperation( 'background' ), 'A second operation keeps the first.' );
		$this->assertTrue( $image->hasOperation( 'upscale' ) );
		$this->assertSame( array( 'factor' => 2 ), $image->getMarker()['upscale'] );
		$this->assertSame( array( 'factor' => 2, 'backup' => '2026/10/a.jpg' ), $image->getOperationMarker( 'upscale' ) );

		$image->deleteMarker( 'upscale' );
		$this->assertFalse( $image->hasOperation( 'upscale' ) );
		$this->assertTrue( $image->hasOperation( 'background' ), 'Deleting one operation keeps the other.' );

		$image->deleteMarker();
		$this->assertSame( array(), $image->getMarker() );
		$this->assertSame( '', get_post_meta( $id, '_emr_bulk_background', true ) );
	}

	public function test_countMarked_and_queryMarked_see_one_operation_only_and_page_by_matches() {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->makeImageAttachment( 100, 100 );
		}
		sort( $ids );
		// Newest three: background. Oldest two: upscale. The middle one carries both.
		BulkImage::getById( $ids[4] )->setMarker( 'background', array( 'ts' => 1 ), 'a.jpg' );
		BulkImage::getById( $ids[3] )->setMarker( 'background', array( 'ts' => 1 ), 'b.jpg' );
		BulkImage::getById( $ids[2] )->setMarker( 'background', array( 'ts' => 1 ), 'c.jpg' );
		BulkImage::getById( $ids[2] )->setMarker( 'upscale', array( 'ts' => 1 ), 'c.jpg' );
		BulkImage::getById( $ids[1] )->setMarker( 'upscale', array( 'ts' => 1 ), 'd.jpg' );
		BulkImage::getById( $ids[0] )->setMarker( 'upscale', array( 'ts' => 1 ), 'e.jpg' );

		$this->assertSame( 3, BulkImage::countMarked( 'background' ) );
		$this->assertSame( 3, BulkImage::countMarked( 'upscale' ) );
		$this->assertSame( 0, BulkImage::countMarked( 'nothing' ) );

		$this->assertSame( array( $ids[2], $ids[1] ), BulkImage::queryMarked( 'upscale', 0, 2 ), 'Newest first, only upscale rows: the background-only rows above do not fill the page.' );
		$this->assertSame( array( $ids[0] ), BulkImage::queryMarked( 'upscale', $ids[1], 2 ), 'The next page starts below the last id.' );
		$this->assertSame( array(), BulkImage::queryMarked( 'upscale', $ids[0], 2 ), 'Empty only when nothing is left.' );
		$this->assertSame( array( $ids[4], $ids[3], $ids[2], $ids[1], $ids[0] ), BulkImage::queryMarked( false, 0, 10 ), 'Any operation, each attachment once.' );
	}
}
