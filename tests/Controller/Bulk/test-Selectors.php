<?php
/**
 * Tests for the bulk source selectors (classes/Controller/Bulk/Selector/*):
 * Selector::forOperation(), WooCommerceProductImages and UpscaleCandidates,
 * and their wiring into BulkQueue::querySource() and
 * BulkController::canRun() / countSource().
 *
 * WooCommerce is not installed in the unit environment, so the `product`
 * and `product_variation` post types are registered for the duration of a
 * test; the real-WooCommerce run is tests/Compat/test-CompatWooCommerce.php.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';

use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\Controller\Bulk\Selector\Selector;
use EnableMediaReplace\Controller\Bulk\Selector\UpscaleCandidates;
use EnableMediaReplace\Controller\Bulk\Selector\WooCommerceProductImages;
use EnableMediaReplace\Model\Bulk\BulkImage;

class BulkSelectorsTest extends WP_UnitTestCase {

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

	/** @var bool Whether this test registered the product post types. */
	private $registeredProductTypes = false;

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
		( new BulkQueue() )->resetQueue();
	}

	public function tear_down() {
		if ( $this->registeredProductTypes ) {
			unregister_post_type( 'product' );
			unregister_post_type( 'product_variation' );
			$this->registeredProductTypes = false;
		}
		remove_all_filters( 'emr/bulk/selector' );
		remove_all_filters( 'emr/bulk/background/post_types' );
		remove_all_filters( 'emr/bulk/queue_options' );
		$this->bulkTearDown();
		parent::tear_down();
	}

	private function registerProductTypes() {
		register_post_type( 'product', array( 'public' => true, 'supports' => array( 'title', 'thumbnail' ) ) );
		register_post_type( 'product_variation', array( 'public' => false, 'supports' => array( 'thumbnail' ) ) );
		$this->registeredProductTypes = true;
	}

	/** A product post with a featured image and a gallery, like WooCommerce stores them. */
	private function makeProduct( $thumbnail, array $gallery = array(), $status = 'publish', $type = 'product', $parent = 0 ) {
		$id = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => $status, 'post_parent' => $parent, 'post_title' => 'product' ) );
		if ( $thumbnail ) {
			update_post_meta( $id, '_thumbnail_id', $thumbnail );
		}
		if ( count( $gallery ) > 0 ) {
			update_post_meta( $id, '_product_image_gallery', implode( ',', $gallery ) );
		}
		return $id;
	}

	private function prepareAll( BulkQueue $q, $maxTicks = 20 ) {
		for ( $i = 0; $i < $maxTicks; $i++ ) {
			$result = $q->run();
			if ( BulkQueue::RESULT_PREPARING_DONE === $result->qstatus ) {
				return;
			}
		}
		$this->fail( 'Still preparing after ' . $maxTicks . ' ticks.' );
	}

	// -------------------------------------------------------------------
	// Selector::forOperation
	// -------------------------------------------------------------------

	public function test_forOperation_maps_operations_to_selectors_and_unknown_to_null() {
		$this->assertInstanceOf( WooCommerceProductImages::class, Selector::forOperation( 'background' ) );
		$this->assertInstanceOf( UpscaleCandidates::class, Selector::forOperation( 'upscale' ) );
		$this->assertNull( Selector::forOperation( 'optimize' ) );
	}

	public function test_selector_filter_replaces_the_selector_and_ignores_non_selectors() {
		$custom = new UpscaleCandidates();
		add_filter( 'emr/bulk/selector', function ( $selector, $operation ) use ( $custom ) {
			return ( 'background' === $operation ) ? $custom : 'not a selector';
		}, 10, 2 );

		$this->assertSame( $custom, Selector::forOperation( 'background' ) );
		$this->assertInstanceOf( UpscaleCandidates::class, Selector::forOperation( 'upscale' ), 'A non-selector return keeps the default.' );
	}

	// -------------------------------------------------------------------
	// WooCommerceProductImages
	// -------------------------------------------------------------------

	public function test_woocommerce_selector_is_unavailable_without_the_product_post_type() {
		$this->assertFalse( post_type_exists( 'product' ), 'Precondition: no WooCommerce in the unit environment.' );
		$selector = new WooCommerceProductImages();

		$this->assertFalse( $selector->isAvailable() );
		$this->assertStringContainsString( 'WooCommerce', $selector->getUnavailableReason() );
		$this->assertSame( array(), $selector->getIds( new BulkQueue() ) );
		$this->assertSame( 0, $selector->countSource() );

		$can = BulkController::getInstance()->canRun( 'background' );
		$this->assertInstanceOf( WP_Error::class, $can );
		$this->assertSame( 'emr_bulk_unavailable', $can->get_error_code() );
		$this->assertSame( 0, BulkController::getInstance()->countSource( 'background' ) );
		$this->assertSame( 'emr_bulk_unknown_operation', BulkController::getInstance()->canRun( 'optimize' )->get_error_code() );
	}

	public function test_woocommerce_selector_collects_featured_gallery_and_variation_images_newest_first_without_duplicates() {
		$this->registerProductTypes();
		$img = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$img[] = $this->makeImageAttachment( 100, 100 );
		}
		$p1 = $this->makeProduct( $img[0], array( $img[1], $img[2] ) );
		$v1 = $this->makeProduct( $img[3], array(), 'publish', 'product_variation', $p1 );
		$p2 = $this->makeProduct( $img[1], array( $img[4] ), 'draft' );          // shares img[1] with p1
		$trashed = $this->makeProduct( $img[5], array(), 'trash' );            // never scanned
		$post    = self::factory()->post->create();                            // a plain post with a thumbnail is not a product
		update_post_meta( $post, '_thumbnail_id', $img[5] );
		$noImage = $this->makeProduct( 0 );

		$selector = new WooCommerceProductImages();
		$this->assertTrue( $selector->isAvailable() );
		$this->assertSame( '', $selector->getUnavailableReason() );
		$this->assertTrue( BulkController::getInstance()->canRun( 'background' ) );
		$this->assertSame( 4, $selector->countSource(), 'p1, v1, p2 and the product without image; not the trashed one.' );
		$this->assertSame( 4, BulkController::getInstance()->countSource( 'background' ) );

		$q   = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background' ) );
		$ids = $selector->getIds( $q );

		$expected = array( $img[0], $img[1], $img[2], $img[3], $img[4] );
		rsort( $expected );
		$this->assertSame( $expected, $ids );
		$this->assertSame( min( array( $p1, $v1, $p2, $noImage ) ), $q->getSelectorCursor(), 'The cursor is the oldest product of the page.' );
		$this->assertSame( array(), $selector->getIds( $q ), 'The source is exhausted.' );
	}

	public function test_woocommerce_selector_pages_products_by_the_enqueue_limit() {
		$this->registerProductTypes();
		add_filter( 'emr/bulk/queue_options', function ( $options ) {
			$options['enqueue_limit'] = 2;
			return $options;
		} );
		$img = array( $this->makeImageAttachment( 10, 10 ), $this->makeImageAttachment( 10, 10 ), $this->makeImageAttachment( 10, 10 ) );
		$products = array( $this->makeProduct( $img[0] ), $this->makeProduct( $img[1] ), $this->makeProduct( $img[2] ) );

		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background' ) );
		$selector = new WooCommerceProductImages();

		$first = $selector->getIds( $q );
		$this->assertSame( array( $img[2], $img[1] ), $first, 'Two newest products.' );
		$this->assertSame( $products[1], $q->getSelectorCursor() );
		$second = $selector->getIds( $q );
		$this->assertSame( array( $img[0] ), $second );
		$this->assertSame( array(), $selector->getIds( $q ) );
	}

	public function test_woocommerce_selector_post_types_filter_is_applied() {
		$this->registerProductTypes();
		$img = $this->makeImageAttachment( 10, 10 );
		$this->makeProduct( $img, array(), 'publish', 'product_variation' );
		add_filter( 'emr/bulk/background/post_types', function () {
			return array( 'product' );
		} );

		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background' ) );
		$this->assertSame( array(), ( new WooCommerceProductImages() )->getIds( $q ), 'Variations excluded by the filter.' );
	}

	public function test_a_background_bulk_without_an_explicit_list_prepares_the_product_images() {
		$this->registerProductTypes();
		$featured = $this->makeImageAttachment( 300, 200 );
		$gallery  = $this->makeImageAttachment( 300, 200, 'png' );
		$text     = $this->makeTextAttachment();
		$done     = $this->makeImageAttachment( 300, 200 );
		BulkImage::getById( $done )->setMarker( 'background', array( 'ts' => time() ), 'x.jpg' );
		$this->makeProduct( $featured, array( $gallery, $text, $done ) );
		$unrelated = $this->makeImageAttachment( 300, 200 );

		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background' ) );
		$this->prepareAll( $q );

		$ids = array_map( 'intval', wp_list_pluck( $this->queueRows(), 'item_id' ) );
		$this->assertEqualsCanonicalizing( array( $featured, $gallery ), $ids, 'The text file, the processed image and the unrelated library image are not queued.' );
		$this->assertSame( 2, $q->getStats()->total );
		$this->assertFalse( $q->getStats()->is_preparing );
	}

	// -------------------------------------------------------------------
	// UpscaleCandidates
	// -------------------------------------------------------------------

	public function test_upscale_selector_is_always_available_and_lists_jpeg_and_png_attachments_newest_first() {
		$jpg  = $this->makeImageAttachment( 100, 100 );
		$png  = $this->makeImageAttachment( 100, 100, 'png' );
		$gif  = $this->makeImageAttachment( 100, 100 );
		wp_update_post( array( 'ID' => $gif, 'post_mime_type' => 'image/gif' ) );
		$text = $this->makeTextAttachment();
		$post = self::factory()->post->create();

		$selector = new UpscaleCandidates();
		$this->assertTrue( $selector->isAvailable() );
		$this->assertSame( '', $selector->getUnavailableReason() );
		$this->assertTrue( BulkController::getInstance()->canRun( 'upscale' ) );
		$this->assertSame( 2, $selector->countSource() );
		$this->assertSame( 2, BulkController::getInstance()->countSource( 'upscale' ) );

		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'upscale' ) );
		$ids = $selector->getIds( $q );

		$this->assertSame( array( $png, $jpg ), $ids );
		$this->assertSame( $jpg, $q->getSelectorCursor() );
		$this->assertSame( array(), $selector->getIds( $q ) );
	}

	public function test_upscale_selector_pages_by_the_enqueue_limit() {
		add_filter( 'emr/bulk/queue_options', function ( $options ) {
			$options['enqueue_limit'] = 2;
			return $options;
		} );
		$a = $this->makeImageAttachment( 10, 10 );
		$b = $this->makeImageAttachment( 10, 10 );
		$c = $this->makeImageAttachment( 10, 10 );

		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'upscale' ) );
		$selector = new UpscaleCandidates();

		$this->assertSame( array( $c, $b ), $selector->getIds( $q ) );
		$this->assertSame( array( $a ), $selector->getIds( $q ) );
		$this->assertSame( array(), $selector->getIds( $q ) );
	}

	public function test_an_upscale_bulk_without_an_explicit_list_applies_the_threshold_to_the_whole_library() {
		$narrow = $this->makeImageAttachment( 500, 100 );
		$wide   = $this->makeImageAttachment( 900, 100 );
		$huge   = $this->makeImageAttachment( 1300, 100 );

		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'upscale', 'threshold' => 800 ) );
		$this->prepareAll( $q );

		$this->assertSame( array( $narrow ), array_map( 'intval', wp_list_pluck( $this->queueRows(), 'item_id' ) ) );
	}

	public function test_createNewBulk_resets_the_selector_cursor() {
		$a = $this->makeImageAttachment( 10, 10 );
		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'upscale' ) );
		$this->prepareAll( $q );
		$this->assertSame( $a, $q->getSelectorCursor() );

		$q->createNewBulk( array( 'operation' => 'upscale' ) );
		$this->assertSame( 0, $q->getSelectorCursor() );
	}
}
