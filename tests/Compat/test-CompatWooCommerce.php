<?php
/**
 * Cross-plugin compatibility: WooCommerce.
 *
 * Runs with the REAL WooCommerce plugin active (wordpress.org, fetched by
 * bin/test.sh --compat); without it every test here SKIPS.
 *
 * EMR has no WooCommerce code. WooCommerce stores product images by
 * attachment id: the featured image in `_thumbnail_id` and the gallery in
 * `_product_image_gallery` (comma-separated ids), so a replace must leave
 * those rows alone and the product must render the new file through them.
 * Only the product description (post_content) holds URLs, and the replacer
 * rewrites those like any post's. WooCommerce registers three image sizes
 * (`woocommerce_thumbnail`, `woocommerce_single`,
 * `woocommerce_gallery_thumbnail`) that WordPress generates with the core
 * ones, so a same-name replace regenerates them and drops the old ones.
 *
 * Products are created through WooCommerce's own CRUD (WC_Product_Simple
 * and save()), which writes the meta rows above and WooCommerce's lookup
 * table rows; all of them roll back with the test transaction.
 *
 * Ported from SPIO's tests/Compat/test-CompatWooCommerce.php: the skip
 * condition and the coexistence check. SPIO's regeneration-signal and
 * tools-panel tests cover SPIO's WooCommerce shim and have no EMR
 * counterpart; the product and replace tests are new.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';

use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\Controller\Bulk\QueueController;
use EnableMediaReplace\Controller\Bulk\Selector\WooCommerceProductImages;
use EnableMediaReplace\InstallHelper;
use EnableMediaReplace\Model\Bulk\BulkImage;

class CompatWooCommerceTest extends EMR_IntegrationTestCase {

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

	use EMR_ReplaceFlowHelpers;

	public function set_up() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded — run bin/test.sh --compat.' );
		}
		parent::set_up();
		$this->flowSetUp();
	}

	public function tear_down() {
		$this->flowTearDown();
		parent::tear_down();
	}

	/** WooCommerce's registered image sizes. */
	private function wcSizes() {
		return array( 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail' );
	}

	/**
	 * A published simple product with a featured image, a gallery and a
	 * description showing $content.
	 *
	 * @return int Product id.
	 */
	private function makeProduct( $image_id, array $gallery_ids, $content ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'EMR product ' . $this->token() );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$product->set_description( $content );
		$product->set_image_id( $image_id );
		$product->set_gallery_image_ids( $gallery_ids );
		$product_id = $product->save();
		$this->assertIsInt( $product_id );
		$this->assertGreaterThan( 0, $product_id, 'Precondition: WooCommerce saved the product.' );
		return $product_id;
	}

	/** md5 of a file, or null when it does not exist. */
	private function md5OrNull( $path ) {
		clearstatcache();
		return file_exists( $path ) ? md5_file( $path ) : null;
	}

	// -------------------------------------------------------------------
	// Coexistence
	// -------------------------------------------------------------------

	public function test_woocommerce_loads_alongside_emr() {
		$this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce main class exists.' );
		$this->assertGreaterThan( 0, did_action( 'woocommerce_loaded' ), 'WooCommerce finished loading.' );
		$this->assertTrue( function_exists( 'wc_get_product' ), 'WooCommerce exposes its product accessor.' );
		foreach ( $this->wcSizes() as $size ) {
			$this->assertContains( $size, get_intermediate_image_sizes(), "WooCommerce registers $size." );
		}
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * Shape sentinel: WooCommerce stores the product images as ids, not URLs,
	 * and an 800x600 upload gets every WooCommerce size.
	 */
	public function test_product_stores_its_images_as_attachment_ids() {
		$id         = $this->uploadJpeg( 'emr-wc-shape-' . $this->token() . '.jpg', 800, 600 );
		$gallery_id = $this->uploadJpeg( 'emr-wc-shape-g-' . $this->token() . '.jpg', 400, 300 );
		$product_id = $this->makeProduct( $id, array( $gallery_id, $id ), 'No image.' );

		$this->assertSame( (string) $id, $this->rawPostMeta( $product_id, '_thumbnail_id' ) );
		$this->assertSame( $gallery_id . ',' . $id, $this->rawPostMeta( $product_id, '_product_image_gallery' ) );
		$this->assertSame( 'product', get_post_type( $product_id ) );
		$meta = wp_get_attachment_metadata( $id );
		foreach ( $this->wcSizes() as $size ) {
			$this->assertArrayHasKey( $size, $meta['sizes'], "The upload has a $size file." );
		}
	}

	// -------------------------------------------------------------------
	// A real replace
	// -------------------------------------------------------------------

	/**
	 * A rename rewrites the product description and leaves the id-based
	 * image meta alone; the product renders the new file through
	 * `_thumbnail_id`, and its gallery still lists the same attachments.
	 */
	public function test_rename_rewrites_the_description_and_the_product_image_follows_through_the_id() {
		$id         = $this->uploadJpeg( 'emr-wc-rn-' . $this->token() . '.jpg', 800, 600 );
		$gallery_id = $this->uploadJpeg( 'emr-wc-rn-g-' . $this->token() . '.jpg', 400, 300 );
		$old_url    = wp_get_attachment_url( $id );
		$old_base   = pathinfo( get_attached_file( $id ), PATHINFO_FILENAME );
		$product_id = $this->makeProduct( $id, array( $gallery_id, $id ), '<p>Look:</p><img src="' . $old_url . '" />' );
		$thumb_raw  = $this->rawPostMeta( $product_id, '_thumbnail_id' );
		$gallery    = $this->rawPostMeta( $product_id, '_product_image_gallery' );
		$this->assertStringContainsString( $old_base, wc_get_product( $product_id )->get_image(), 'Precondition: the product image shows the old file.' );

		$name = 'emr-wc-rn-new-' . $this->token() . '.jpg';
		$this->searchReplaceMode( $id, $this->tmpJpeg( 800, 600, array( 200, 40, 40 ) ), $name );

		$new_url  = wp_get_attachment_url( $id );
		$new_base = pathinfo( $name, PATHINFO_FILENAME );
		$this->assertStringEndsWith( '/' . $name, $new_url, 'Sentinel: the attachment was renamed.' );

		$this->assertSame( '<p>Look:</p><img src="' . $new_url . '" />', $this->rawContent( $product_id ), 'The description carries the new URL.' );
		$this->assertSame( $thumb_raw, $this->rawPostMeta( $product_id, '_thumbnail_id' ), '_thumbnail_id is untouched.' );
		$this->assertSame( $gallery, $this->rawPostMeta( $product_id, '_product_image_gallery' ), '_product_image_gallery is untouched.' );

		$product = wc_get_product( $product_id );
		$this->assertSame( (string) $id, (string) $product->get_image_id(), 'The product keeps the attachment as its image.' );
		$this->assertSame( array( $gallery_id, $id ), array_map( 'intval', $product->get_gallery_image_ids() ), 'The gallery keeps its attachments, in order.' );
		$html = $product->get_image();
		$this->assertStringContainsString( $new_base . '-' . wp_get_attachment_metadata( $id )['sizes']['woocommerce_thumbnail']['width'], $html, 'The product image renders the new woocommerce_thumbnail file.' );
		$this->assertStringNotContainsString( $old_base, $html, 'The product image no longer names the old file.' );
	}

	/**
	 * A same-name replace with other dimensions regenerates WooCommerce's
	 * sizes: `woocommerce_single` changes name (600x450 to 600x267) and the
	 * old file is deleted, the square crops keep their name and are
	 * regenerated, and a description showing the old single size follows.
	 */
	public function test_same_name_replace_regenerates_the_woocommerce_sizes_and_drops_the_old_ones() {
		$id         = $this->uploadJpeg( 'emr-wc-same-' . $this->token() . '.jpg', 800, 600 );
		$old_paths  = $this->sizePaths( $id );
		$old_meta   = wp_get_attachment_metadata( $id );
		$old_md5    = array_map( array( $this, 'md5OrNull' ), $old_paths );
		$single_url = $this->sizeUrl( $id, 'woocommerce_single' );
		$product_id = $this->makeProduct( $id, array(), '<img src="' . $single_url . '" />' );
		$this->assertStringEndsWith( '-600x450.jpg', $old_meta['sizes']['woocommerce_single']['file'], 'Sentinel: the old single size is 600x450.' );

		$this->replaceMode( $id, $this->tmpJpeg( 900, 400, array( 200, 40, 40 ) ) );

		$meta      = wp_get_attachment_metadata( $id );
		$new_paths = $this->sizePaths( $id );
		$this->assertSame( 900, (int) $meta['width'], 'Sentinel: the metadata has the new width.' );

		$this->assertStringEndsWith( '-600x267.jpg', $meta['sizes']['woocommerce_single']['file'], 'woocommerce_single is regenerated with the new ratio.' );
		$this->assertFileExists( $new_paths['woocommerce_single'] );
		$this->assertFileDoesNotExist( $old_paths['woocommerce_single'], 'The old woocommerce_single file is deleted.' );

		foreach ( array( 'woocommerce_thumbnail', 'woocommerce_gallery_thumbnail' ) as $size ) {
			$this->assertSame( $old_meta['sizes'][ $size ]['file'], $meta['sizes'][ $size ]['file'], "$size keeps its (cropped) name." );
			$this->assertNotNull( $this->md5OrNull( $new_paths[ $size ] ), "$size exists." );
			$this->assertNotSame( $old_md5[ $size ], $this->md5OrNull( $new_paths[ $size ] ), "$size is regenerated from the new image." );
		}

		$this->assertSame( '<img src="' . $this->sizeUrl( $id, 'woocommerce_single' ) . '" />', $this->rawContent( $product_id ), 'The description follows the renamed single size.' );
		$this->assertSame( (string) $id, $this->rawPostMeta( $product_id, '_thumbnail_id' ), '_thumbnail_id is untouched.' );
	}

	// -------------------------------------------------------------------
	// The bulk remove-background selector on real WooCommerce products.
	// -------------------------------------------------------------------

	/** Drops the EMR queue rows, the status option, the plan transient and the backups. */
	private function cleanBulkState() {
		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_queue';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE plugin_slug = %s", InstallHelper::QUEUE_SLUG ) );
		delete_option( 'shortqwp_' . InstallHelper::QUEUE_SLUG );
		delete_option( BulkController::LOG_OPTION );
		delete_transient( QueueController::PLAN_TRANSIENT );
		$backup = BackupController::getBackupPath();
		if ( is_dir( $backup ) ) {
			$this->removeTree( $backup );
		}
		foreach ( array( 'EnableMediaReplace\\Controller\\Bulk\\BulkApi' => 'instance', 'EnableMediaReplace\\Controller\\Bulk\\BulkController' => 'instance' ) as $class => $property ) {
			$this->resetSingleton( $class, $property );
		}
		foreach ( array( 'EnableMediaReplace\\Controller\\Bulk\\ActionController' => 'instances', 'EnableMediaReplace\\Controller\\Bulk\\BulkQueue' => 'isInQueue' ) as $class => $property ) {
			$prop = new ReflectionProperty( $class, $property );
			$prop->setAccessible( true );
			$prop->setValue( null, array() );
		}
	}

	/** A variation of $parent with its own featured image. */
	private function makeVariation( $parent, $image_id ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent );
		$variation->set_regular_price( '12' );
		$variation->set_image_id( $image_id );
		$id = $variation->save();
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	public function test_bulk_selector_collects_the_images_woocommerce_products_and_variations_use() {
		$this->cleanBulkState();
		try {
			$featured  = $this->uploadJpeg( 'emr-wc-bulk-f-' . $this->token() . '.jpg', 300, 200 );
			$gallery1  = $this->uploadJpeg( 'emr-wc-bulk-g1-' . $this->token() . '.jpg', 300, 200 );
			$gallery2  = $this->uploadJpeg( 'emr-wc-bulk-g2-' . $this->token() . '.jpg', 300, 200 );
			$variation = $this->uploadJpeg( 'emr-wc-bulk-v-' . $this->token() . '.jpg', 300, 200 );
			$library   = $this->uploadJpeg( 'emr-wc-bulk-x-' . $this->token() . '.jpg', 300, 200 );
			$product   = $this->makeProduct( $featured, array( $gallery1, $gallery2 ), 'A product' );
			$this->makeVariation( $product, $variation );
			$draft = new WC_Product_Simple();
			$draft->set_name( 'EMR draft ' . $this->token() );
			$draft->set_status( 'draft' );
			$draft->set_image_id( $gallery1 ); // shared with the published product
			$draft->save();

			$selector = new WooCommerceProductImages();
			$this->assertTrue( $selector->isAvailable(), 'WooCommerce registers the product post type.' );
			$this->assertTrue( BulkController::getInstance()->canRun( 'background' ) );

			$q = new BulkQueue();
			$q->createNewBulk( array( 'operation' => 'background' ) );
			$ids = $selector->getIds( $q );

			$this->assertEqualsCanonicalizing( array( $featured, $gallery1, $gallery2, $variation ), $ids );
			$this->assertNotContains( $library, $ids, 'A library image no product uses is not selected.' );
		} finally {
			$this->cleanBulkState();
		}
	}

	public function test_bulk_remove_background_replaces_the_product_images_and_the_product_keeps_showing_them() {
		$this->cleanBulkState();
		update_option( 'emr_shortpixel_api_key', 'abcdEFGHIJKLMNOPwxyz' );
		try {
			$featured = $this->uploadJpeg( 'emr-wc-bulk-run-f-' . $this->token() . '.jpg', 400, 300 );
			$gallery  = $this->uploadJpeg( 'emr-wc-bulk-run-g-' . $this->token() . '.jpg', 400, 300 );
			$product  = $this->makeProduct( $featured, array( $gallery ), 'Bulk product' );
			$original = file_get_contents( get_attached_file( $featured ) );

			$bulk = BulkController::getInstance();
			$bulk->createNewBulk( array( 'operation' => 'background' ) );
			$c = new QueueController();
			for ( $i = 0; $i < 20; $i++ ) {
				$json = $c->processQueue();
				$this->assertSame( 1, $json->status, wp_json_encode( $json ) );
				if ( BulkQueue::RESULT_PREPARING_DONE === $json->media->qstatus ) {
					break;
				}
			}
			$this->assertSame( '2', $json->media->stats->total );
			$bulk->startBulk();
			for ( $i = 0; $i < 20; $i++ ) {
				$json = $c->processQueue();
				$this->assertSame( 1, $json->status, wp_json_encode( $json ) );
				if ( BulkQueue::RESULT_QUEUE_EMPTY === $json->media->qstatus ) {
					break;
				}
			}
			$this->assertSame( '2', $json->media->stats->done );

			$wc = wc_get_product( $product );
			$this->assertSame( $featured, (int) $wc->get_image_id(), 'The product still points at the same attachment id.' );
			$this->assertSame( array( $gallery ), array_map( 'intval', $wc->get_gallery_image_ids() ) );
			foreach ( array( $featured, $gallery ) as $id ) {
				$this->assertSame( 50, wp_get_attachment_metadata( $id )['width'], 'Replaced in place by the result image.' );
				$this->assertTrue( BackupController::hasBackup( BulkImage::getById( $id ) ) );
				foreach ( $this->wcSizes() as $size ) {
					$this->assertFalse( image_get_intermediate_size( $id, $size ), "WordPress makes no $size for a 50 px source; the old 400 px one must be gone from the metadata." );
				}
			}
			$this->assertSame( $original, file_get_contents( BackupController::getBackupFile( BulkImage::getById( $featured )->getMarker()['backup'] )->getFullPath() ) );
			$html = $wc->get_image();
			$this->assertStringContainsString( basename( get_attached_file( $featured ), '.jpg' ), $html, 'The product renders the replaced featured image.' );
			$bulk->finishBulk();
		} finally {
			$this->cleanBulkState();
		}
	}

}
