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

class CompatWooCommerceTest extends EMR_IntegrationTestCase {

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
}
