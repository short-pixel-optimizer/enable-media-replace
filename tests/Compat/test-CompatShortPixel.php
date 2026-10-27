<?php
/**
 * Cross-plugin compatibility: ShortPixel Image Optimizer (SPIO).
 *
 * Runs with the REAL shortpixel-image-optimiser plugin active
 * (bin/test.sh --compat downloads + activates it). SPIO bundles the same
 * replacer, log and notices modules as EMR under its own namespace, so
 * the two plugins must load side by side without a class clash, and SPIO
 * listens to the two contract hooks EMR fires around a replace:
 *
 *   - `wp_handle_replace`, before the swap: SPIO drops its optimisation
 *     data for the attachment (the old file is about to disappear);
 *   - `enable-media-replace-upload-done`, after the swap: SPIO re-enqueues
 *     the attachment when auto-optimisation is on.
 *
 * EMR's side of the contract (what the hooks carry and when they fire) is
 * covered by tests/Integration/test-SpioContract.php without SPIO loaded.
 * This suite verifies that the real SPIO reacts to a real replace, and
 * that EMR hides its SPIO upsell once SPIO is active.
 *
 * Auto-optimise: SPIO registers `handleReplaceEnqueue` on
 * `enable-media-replace-upload-done` (and `handleImageUploadHook` on
 * `wp_generate_attachment_metadata`) only when its environment reports
 * `is_autoprocess`. The queue tests set that flag and wire exactly the
 * upload-done listener, as SPIO's own test-EMRIntegration.php does, so
 * the queue entry is attributable to EMR's contract hook; the flag and
 * the hooks are restored afterwards.
 *
 * WP Offload Media: with SPIO active, EMR's WPOffload::init() leaves the
 * `shortpixel/image/urltopath` filter to SPIO. That test also needs the
 * amazon-s3-and-cloudfront partner, which every --compat run loads.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once dirname( __DIR__ ) . '/ViewController/ViewControllerTestDoubles.php';

use EnableMediaReplace\Controller\ReplaceController;

class CompatShortPixelTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;

	/** @var bool|null SPIO's is_autoprocess flag before the test. */
	private $savedAutoprocess = null;

	/** @var array|null WPOffload static state before the test: array( instance, offloadPrevented ). */
	private $savedOffload = null;

	public function set_up() {
		if ( ! defined( 'SHORTPIXEL_PLUGIN_FILE' ) ) {
			$this->markTestSkipped( 'ShortPixel Image Optimizer is not loaded — run via bin/test.sh --compat.' );
		}
		parent::set_up();
		$this->flowSetUp();
		$this->ensureSpioHooksWired();
		$this->savedAutoprocess = \wpSPIO()->env()->is_autoprocess;
		$this->savedOffload     = array(
			$this->offloadStatic( 'instance' )->getValue(),
			$this->offloadStatic( 'offloadPrevented' )->getValue(),
		);
	}

	public function tear_down() {
		$this->flowTearDown();
		\wpSPIO()->env()->is_autoprocess = $this->savedAutoprocess;
		$this->offloadStatic( 'instance' )->setValue( null, $this->savedOffload[0] );
		$this->offloadStatic( 'offloadPrevented' )->setValue( null, $this->savedOffload[1] );
		parent::tear_down();
	}

	/** A static property of EMR's WPOffload integration, made accessible. */
	private function offloadStatic( $name ) {
		$prop = new ReflectionProperty( 'EnableMediaReplace\Externals\WPOffload', $name );
		$prop->setAccessible( true );
		return $prop;
	}

	/**
	 * SPIO wires its EMR listeners from AdminController::initHooks() on
	 * `init`. The WP test bootstrap does not always fire `init` for the
	 * plugins loaded on `plugins_loaded`, so fire it once when the
	 * listener is missing, exactly as SPIO's own integration suite does.
	 */
	private function ensureSpioHooksWired(): void {
		$admin = \ShortPixel\Controller\AdminController::getInstance();
		if ( ! has_action( 'wp_handle_replace', array( $admin, 'handleReplaceHook' ) ) ) {
			do_action( 'init' );
		}
		$this->assertNotFalse(
			has_action( 'wp_handle_replace', array( $admin, 'handleReplaceHook' ) ),
			'Precondition: SPIO listens to wp_handle_replace.'
		);
	}

	// -------------------------------------------------------------------
	// Coexistence
	// -------------------------------------------------------------------

	public function test_spio_loads_alongside_emr() {
		$this->assertTrue( defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ), 'SPIO defines its version constant.' );
		$this->assertTrue( function_exists( 'wpSPIO' ), 'SPIO exposes its accessor.' );
		$this->assertTrue( function_exists( 'emr' ), 'EMR exposes its accessor.' );
		$this->assertInstanceOf( 'EnableMediaReplace\EnableMediaReplacePlugin', emr() );
	}

	/**
	 * Both plugins bundle replacer2, log and notices. The build step
	 * rewrites the namespaces, so each plugin loads its own copy and
	 * neither PackageLoader claims the other's classes.
	 */
	public function test_both_plugins_load_their_own_copy_of_the_shared_modules() {
		$pairs = array(
			array( 'EnableMediaReplace\Replacer\Replacer', 'ShortPixel\Replacer\Replacer' ),
			array( 'EnableMediaReplace\ShortPixelLogger\ShortPixelLogger', 'ShortPixel\ShortPixelLogger\ShortPixelLogger' ),
			array( 'EnableMediaReplace\Notices\NoticeController', 'ShortPixel\Notices\NoticeController' ),
		);
		foreach ( $pairs as $pair ) {
			list( $emr_class, $spio_class ) = $pair;
			$this->assertTrue( class_exists( $emr_class ), "$emr_class loads." );
			$this->assertTrue( class_exists( $spio_class ), "$spio_class loads." );

			$emr_file  = ( new ReflectionClass( $emr_class ) )->getFileName();
			$spio_file = ( new ReflectionClass( $spio_class ) )->getFileName();
			$this->assertSame( 0, strpos( $emr_file, dirname( __DIR__, 2 ) . '/build/shortpixel/' ), "$emr_class comes from EMR's build dir." );
			$this->assertStringContainsString( '/shortpixel-image-optimiser/', $spio_file, "$spio_class comes from SPIO's plugin dir." );
		}
	}

	// -------------------------------------------------------------------
	// Upsell
	// -------------------------------------------------------------------

	/** Renders views/upsell.php for an administrator and returns the markup. */
	private function renderUpsell(): string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		ob_start();
		include dirname( __DIR__, 2 ) . '/views/upsell.php';
		return (string) ob_get_clean();
	}

	/**
	 * The SPIO block of the upsell box is skipped when SPIO is active; the
	 * other offers still render, so the box itself is not hidden.
	 */
	public function test_upsell_hides_the_spio_offer_when_spio_is_active() {
		$this->assertTrue( is_plugin_active( 'shortpixel-image-optimiser/wp-shortpixel.php' ), 'Precondition: SPIO is an active plugin.' );
		$this->assertTrue( current_user_can( 'install_plugins' ), 'Precondition: the flow admin may install plugins.' );

		$html = $this->renderUpsell();

		$this->assertStringContainsString( 'upsell-wrapper', $html, 'The upsell box renders.' );
		$this->assertStringNotContainsString( 'shortpixel-offer spio', $html, 'The SPIO offer is skipped.' );
		$this->assertStringNotContainsString( 'data-plugin="spio"', $html, 'No install or activate button for SPIO.' );
		$this->assertStringContainsString( 'data-plugin="spai"', $html, 'The Adaptive Images offer still renders.' );
	}

	// -------------------------------------------------------------------
	// A real replace with SPIO listening
	// -------------------------------------------------------------------

	/**
	 * Marks the attachment as optimised the way SPIO stores it, so the
	 * replace has something to clear.
	 */
	private function markOptimisedBySpio( int $attachment_id ): void {
		$meta_model = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
		$this->assertNotFalse( $meta_model, 'Precondition: SPIO builds an image model for the attachment.' );
		$meta_model->setMeta( 'status', \ShortPixel\Model\Image\ImageModel::FILE_STATUS_SUCCESS );
		$meta_model->setMeta( 'compressionLevel', 1 );
		$meta_model->setMeta( 'compressedSize', 100 );
		$meta_model->setMeta( 'originalSize', 200 );
		$meta_model->saveMeta();
	}

	/** SPIO's stored status, as an int (its table returns strings). */
	private function spioStatus( int $attachment_id ) {
		$model = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
		return $model ? (int) $model->getMeta( 'status' ) : null;
	}

	/**
	 * A MODE_REPLACE through ReplaceController fires each contract hook
	 * exactly once, and SPIO's listener drops the attachment's "optimised"
	 * status: the new file on disk is unoptimised.
	 */
	public function test_replace_fires_the_contract_hooks_once_and_spio_clears_its_optimisation_state() {
		$id = $this->uploadFixture( 'test-image.jpg' );
		$this->markOptimisedBySpio( $id );
		$this->assertSame(
			\ShortPixel\Model\Image\ImageModel::FILE_STATUS_SUCCESS,
			$this->spioStatus( $id ),
			'Precondition: SPIO reports the attachment as optimised.'
		);

		$this->addHook( 'wp_handle_replace', $this->record( 'handle_replace' ), 10, 1 );
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ), 10, 3 );

		$this->replaceMode( $id, $this->tmpJpeg( 80, 60 ) );

		$this->assertSame( array( 'handle_replace', 'upload_done' ), $this->eventNames(), 'Each contract hook fires once, in order.' );
		$this->assertNotSame(
			\ShortPixel\Model\Image\ImageModel::FILE_STATUS_SUCCESS,
			$this->spioStatus( $id ),
			'SPIO no longer reports the replaced attachment as optimised.'
		);
	}

	// -------------------------------------------------------------------
	// Auto-optimise: the re-enqueue after a replace
	// -------------------------------------------------------------------

	/** Whether the attachment sits in SPIO's (non-bulk) media queue. */
	private function inSpioQueue( int $attachment_id ): bool {
		$queue = ( new \ShortPixel\Controller\QueueController() )->getQueue( 'media' );
		$this->assertIsObject( $queue, 'Precondition: SPIO returns its media queue.' );
		return (bool) $queue->isItemInQueue( $attachment_id );
	}

	/**
	 * Wires SPIO's auto-optimise state for the test: the environment flag,
	 * and on `enable-media-replace-upload-done` only the listener SPIO's
	 * initHooks() adds under that flag. SPIO's upload listener on
	 * `wp_generate_attachment_metadata` is removed in both states, so a
	 * queue entry can only come from EMR's contract hook (hooks are
	 * restored by WP_UnitTestCase).
	 */
	private function setSpioAutoprocess( bool $on ): void {
		$admin = \ShortPixel\Controller\AdminController::getInstance();
		\wpSPIO()->env()->is_autoprocess = $on;
		remove_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleImageUploadHook' ), 5 );
		remove_action( 'enable-media-replace-upload-done', array( $admin, 'handleReplaceEnqueue' ), 10 );
		if ( $on ) {
			add_action( 'enable-media-replace-upload-done', array( $admin, 'handleReplaceEnqueue' ), 10, 3 );
		}
	}

	/**
	 * With auto-optimise on, the `enable-media-replace-upload-done` that
	 * EMR fires at the end of a real MODE_REPLACE makes SPIO put the
	 * attachment back into its media queue.
	 */
	public function test_replace_with_auto_optimise_on_re_enqueues_the_attachment_in_spio() {
		$this->setSpioAutoprocess( true );
		$id = $this->uploadJpeg( 'emr-spio-auto-' . $this->token() . '.jpg', 640, 480 );
		$this->assertFalse( $this->inSpioQueue( $id ), 'Precondition: the fresh upload is not queued.' );

		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ), 9, 3 );
		$this->replaceMode( $id, $this->tmpJpeg( 640, 480, array( 200, 40, 40 ) ) );

		$this->assertSame( array( 'upload_done' ), $this->eventNames(), 'EMR fired the contract hook once.' );
		$this->assertSame( $id, $this->events[0][1][2], 'The hook carries the attachment id as an int.' );
		$this->assertTrue( $this->inSpioQueue( $id ), 'SPIO queued the replaced attachment.' );
	}

	/**
	 * With auto-optimise off, SPIO does not listen to the upload-done hook
	 * and the replace leaves its queue alone.
	 */
	public function test_replace_with_auto_optimise_off_does_not_enqueue_the_attachment() {
		$this->setSpioAutoprocess( false );
		$admin = \ShortPixel\Controller\AdminController::getInstance();
		$this->assertFalse( has_action( 'enable-media-replace-upload-done', array( $admin, 'handleReplaceEnqueue' ) ), 'Precondition: SPIO does not listen.' );
		$id = $this->uploadJpeg( 'emr-spio-manual-' . $this->token() . '.jpg', 640, 480 );

		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ), 9, 3 );
		$this->replaceMode( $id, $this->tmpJpeg( 640, 480, array( 200, 40, 40 ) ) );

		$this->assertSame( array( 'upload_done' ), $this->eventNames(), 'EMR still fired the contract hook.' );
		$this->assertFileExists( get_attached_file( $id ), 'The replace itself ran.' );
		$this->assertFalse( $this->inSpioQueue( $id ), 'SPIO did not queue the attachment.' );
	}

	// -------------------------------------------------------------------
	// WP Offload Media: the urltopath filter is left to SPIO
	// -------------------------------------------------------------------

	/**
	 * WPOffload::init() registers its `shortpixel/image/urltopath` callback
	 * only when SPIO is not active. With SPIO active it skips that one and
	 * still registers the five EMR-side hooks.
	 */
	public function test_wpoffload_init_leaves_urltopath_to_spio_and_registers_the_emr_hooks() {
		$this->assertTrue( class_exists( 'Amazon_S3_And_CloudFront' ), 'Precondition: WP Offload Media is loaded (every --compat run loads it).' );
		$this->assertTrue( is_plugin_active( 'shortpixel-image-optimiser/wp-shortpixel.php' ), 'Precondition: SPIO is an active plugin.' );

		$this->offloadStatic( 'instance' )->setValue( null, null );
		$offload = \EnableMediaReplace\Externals\WPOffload::getInstance();
		$this->assertNull( $offload->init( $GLOBALS['as3cf'] ), 'init() passes the compatibility checks.' );

		$this->assertFalse( has_filter( 'shortpixel/image/urltopath', array( $offload, 'checkIfOffloaded' ) ), 'EMR does not register its urltopath callback.' );
		$this->assertNotFalse( has_filter( 'shortpixel/image/urltopath' ), "SPIO's own offload integration answers that filter." );

		$expected = array(
			'emr_after_remove_current'         => 'removeRemote',
			'emr/file/virtual/translate'       => 'getLocalPath',
			'emr/replace/file_is_movable'      => 'isFileMovable',
			'emr/replace/original_image_path'  => 'checkScaledUrl',
			'enable-media-replace-upload-done' => 'updateOriginalPath',
		);
		foreach ( $expected as $hook => $method ) {
			$this->assertSame( 10, has_filter( $hook, array( $offload, $method ) ), "$hook -> $method is registered." );
		}
	}

	// -------------------------------------------------------------------
	// Success view
	// -------------------------------------------------------------------

	/**
	 * Renders views/success.php the way ViewController::loadView() does and
	 * returns the markup. The view ends by requiring admin-footer.php; the
	 * partner plugins' footer callbacks are not under test and are dropped
	 * for the render (WP_UnitTestCase restores the hooks).
	 */
	private function renderSuccess( $post_url ): string {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'upload' );
		$saved_suffix           = isset( $GLOBALS['hook_suffix'] ) ? $GLOBALS['hook_suffix'] : null;
		$GLOBALS['hook_suffix'] = 'upload.php';
		foreach ( array( 'in_admin_footer', 'admin_footer', 'admin_print_footer_scripts', 'admin_footer-upload.php', 'admin_footer_text', 'update_footer', 'admin_print_footer_scripts-upload.php' ) as $hook ) {
			remove_all_filters( $hook );
		}

		$view          = new stdClass();
		$view->postUrl = $post_url;
		$view->post_id = 0;
		$view->emrUrl  = '';
		$controller    = new EMR_TestViewController();

		ob_start();
		try {
			include dirname( __DIR__, 2 ) . '/views/success.php';
		} finally {
			$html                   = (string) ob_get_clean();
			$GLOBALS['hook_suffix'] = $saved_suffix;
		}
		return $html;
	}

	/**
	 * The success screen is the same with SPIO active: the confirmation, the
	 * redirect link, and the ShortPixel recommendation, which is gated on
	 * the `emr/upsell` filter only (unlike views/upsell.php, it does not
	 * check whether SPIO is already active).
	 */
	public function test_success_view_renders_unchanged_with_spio_active() {
		$this->assertTrue( is_plugin_active( 'shortpixel-image-optimiser/wp-shortpixel.php' ), 'Precondition: SPIO is an active plugin.' );
		$url = admin_url( 'post.php?action=edit&post=4242&emr_replaced=1' );

		$html = $this->renderSuccess( $url );

		$this->assertStringContainsString( 'Your image has been replaced!', $html );
		$this->assertStringContainsString( 'id="redirect_counter"', $html );
		$this->assertStringContainsString( '<a id="redirect_url" href="' . esc_url( $url ) . '">', $html );
		$this->assertStringContainsString( 'https://wordpress.org/plugins/shortpixel-image-optimiser/', $html, 'The SPIO recommendation still shows while SPIO is active.' );
	}
}
