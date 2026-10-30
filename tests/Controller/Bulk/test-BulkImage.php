<?php
/**
 * Tests for EnableMediaReplace\Model\Bulk\BulkImage (classes/Model/Bulk/BulkImage.php).
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';

use EnableMediaReplace\Model\Bulk\BulkImage;

class BulkImageTest extends WP_UnitTestCase {

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

	public function test_marker_round_trip() {
		$image = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$this->assertSame( array(), $image->getMarker() );
		$this->assertFalse( $image->hasOperation( 'background' ) );

		$image->setMarker( 'background', array( 'ts' => 1700000000, 'bg_remove' => 1 ), '2026/10/a.jpg' );
		$marker = $image->getMarker();
		$this->assertSame( array( 'ts' => 1700000000, 'bg_remove' => 1 ), $marker['background'] );
		$this->assertSame( '2026/10/a.jpg', $marker['backup'] );
		$this->assertTrue( $image->hasOperation( 'background' ) );
		$this->assertFalse( $image->hasOperation( 'upscale' ) );

		$image->setMarker( 'upscale', array( 'factor' => 2 ), '2026/10/a.jpg' );
		$this->assertTrue( $image->hasOperation( 'background' ), 'A second operation keeps the first.' );
		$this->assertTrue( $image->hasOperation( 'upscale' ) );

		$image->deleteMarker();
		$this->assertSame( array(), $image->getMarker() );
		$this->assertSame( '', get_post_meta( $image->get( 'id' ), BulkImage::MARKER_META, true ) );
	}
}
