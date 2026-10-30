<?php
/**
 * Tests for EnableMediaReplace\Controller\Bulk\ActionController
 * (classes/Controller/Bulk/ActionController.php), ported from ShortPixel
 * Image Optimizer's OptimizerBase tests and extended with the EMR result
 * handling: a successful API result is downloaded, backed up and applied
 * through ReplaceController, the item is finished in the queue, and a
 * restore brings the backup back.
 *
 * HTTP is stubbed through pre_http_request; the "download" serves the
 * bytes of tests/fixtures/codeispoetry.png so the replacement is visible
 * in the file and the metadata.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';

use EnableMediaReplace\Controller\Bulk\ActionController;
use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\Controller\Bulk\BulkApi;
use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\Controller\Bulk\QueueController;
use EnableMediaReplace\Model\Bulk\BulkImage;
use EnableMediaReplace\Model\Bulk\QueueItem;
use EnableMediaReplace\ShortQ\ShortQ;

class BulkActionControllerTest extends WP_UnitTestCase {

	use EMR_BulkTestHelpers;

	/** @var array */
	private $requests = array();

	/** @var array */
	private $events = array();

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
		( new BulkQueue() )->resetQueue();
		$this->requests = array();
		$this->events   = array();
		add_filter( 'pre_http_request', array( $this, 'stub' ), 10, 3 );
		add_filter( 'pre_http_request', array( $this, 'failFast' ), 9999, 3 );
		add_action( 'emr/bulk/item/done', array( $this, 'onDone' ), 10, 3 );
		add_action( 'emr/bulk/item/failed', array( $this, 'onFailed' ) );
		add_action( 'enable-media-replace-upload-done', array( $this, 'onReplaced' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'stub' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'failFast' ), 9999 );
		remove_action( 'emr/bulk/item/done', array( $this, 'onDone' ), 10 );
		remove_action( 'emr/bulk/item/failed', array( $this, 'onFailed' ) );
		remove_action( 'enable-media-replace-upload-done', array( $this, 'onReplaced' ), 10 );
		$this->bulkTearDown();
		parent::tear_down();
	}

	public function onDone( $image, $action, $qItem ) {
		$this->events[] = 'done:' . $action . ':' . $image->get( 'id' );
	}

	public function onFailed( $qItem ) {
		$this->events[] = 'failed:' . $qItem->item_id;
	}

	public function onReplaced( $target_url, $source_url, $post_id = 0 ) {
		$this->events[] = 'replaced:' . $post_id;
	}

	public function stub( $preempt, $args, $url ) {
		$this->requests[] = $url;
		if ( false !== strpos( $url, '/f/' ) ) {
			$bytes = file_get_contents( dirname( dirname( __DIR__ ) ) . '/fixtures/codeispoetry.png' );
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $bytes );
				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '', 'filename' => $args['filename'], 'headers' => array( 'content-type' => 'image/png' ), 'cookies' => array() );
			}
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => $bytes, 'headers' => array( 'content-type' => 'image/png' ), 'cookies' => array() );
		}
		if ( false !== strpos( $url, '/missing/' ) ) {
			return new WP_Error( 'http_request_failed', 'not found' );
		}
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '{"Status":{"Code":"2"}}', 'headers' => array(), 'cookies' => array() );
	}

	public function failFast( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$this->fail( 'Unstubbed HTTP request to ' . $url );
	}

	private function invoke( ActionController $c, $method, array $args = array() ) {
		$m = new ReflectionMethod( ActionController::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	/** A dequeued queue item for $id (queue reset, bulk created, prepared, started, dequeued). */
	private function dequeued( $id, $operation = 'background' ) {
		BulkController::getInstance()->createNewBulk( array( 'operation' => $operation, 'items' => array( $id ) ) );
		$q = new BulkQueue();
		while ( BulkQueue::RESULT_PREPARING_DONE !== $q->run()->qstatus ) {
		}
		$q->startBulk();
		$result = $q->run();
		$this->assertSame( BulkQueue::RESULT_ITEMS, $result->qstatus, 'Precondition: the item is dequeued.' );
		$controller = ActionController::getInstance();
		$controller->setCurrentQueue( $q, new QueueController() );
		return array( $result->items[0], $q, $controller );
	}

	public function test_getInstance_is_a_singleton_per_class() {
		$a = ActionController::getInstance();
		$this->assertInstanceOf( ActionController::class, $a );
		$this->assertSame( $a, ActionController::getInstance() );
		$this->assertFalse( $a->shutdown_registered );
	}

	public function test_setCurrentQueue_stores_queue_and_controller() {
		$c  = new ActionController();
		$q  = new BulkQueue();
		$qc = new QueueController();
		$c->setCurrentQueue( $q, $qc );
		$pq = new ReflectionProperty( ActionController::class, 'currentQueue' );
		$pq->setAccessible( true );
		$pc = new ReflectionProperty( ActionController::class, 'queueController' );
		$pc->setAccessible( true );
		$this->assertSame( $q, $pq->getValue( $c ) );
		$this->assertSame( $qc, $pc->getValue( $c ) );
	}

	public function test_checkImageModel_records_an_error_without_an_image() {
		$c = new ActionController();
		$qItem = new QueueItem( array( 'item_id' => 99 ) );
		$this->assertFalse( $this->invoke( $c, 'checkImageModel', array( $qItem ) ) );
		$this->assertTrue( $qItem->result()->is_error );
		$this->assertTrue( $qItem->result()->is_done );
		$this->assertSame( BulkApi::STATUS_NOT_API, $qItem->result()->apiStatus );

		$good = new QueueItem( array( 'imageModel' => BulkImage::getById( $this->makeImageAttachment( 10, 10 ) ) ) );
		$this->assertTrue( $this->invoke( $c, 'checkImageModel', array( $good ) ) );
	}

	public function test_checkItem_rules_per_action() {
		$c = new ActionController();

		$jpg = new QueueItem( array( 'imageModel' => BulkImage::getById( $this->makeImageAttachment( 300, 200 ) ) ) );
		$this->assertTrue( $c->checkItem( $jpg, 'remove_background' ) );
		$this->assertTrue( $c->checkItem( $jpg, 'scale_image' ) );
		$this->assertFalse( $c->checkItem( $jpg, 'restore' ), 'No backup yet.' );
		$this->assertStringContainsString( 'backup', $jpg->result()->message );

		$wide = new QueueItem( array( 'imageModel' => BulkImage::getById( $this->makeImageAttachment( 1300, 200 ) ) ) );
		$this->assertTrue( $c->checkItem( $wide, 'remove_background' ) );
		$this->assertFalse( $c->checkItem( $wide, 'scale_image' ) );
		$this->assertStringContainsString( 'too wide', $wide->result()->message );

		$gifId = $this->makeImageAttachment( 300, 200 );
		wp_update_post( array( 'ID' => $gifId, 'post_mime_type' => 'image/gif' ) );
		$gif = new QueueItem( array( 'imageModel' => BulkImage::getById( $gifId ) ) );
		$this->assertFalse( $c->checkItem( $gif, 'remove_background' ) );
		$this->assertSame( QueueItem::FILE_STATUS_ERROR, $gif->result()->fileStatus );

		$missingId = $this->makeImageAttachment( 300, 200 );
		unlink( get_attached_file( $missingId ) );
		$missing = new QueueItem( array( 'imageModel' => BulkImage::getById( $missingId ) ) );
		$this->assertFalse( $c->checkItem( $missing, 'remove_background' ) );

		$this->assertFalse( $c->checkItem( new QueueItem( array( 'item_id' => 5 ) ), 'remove_background' ) );
	}

	public function test_checkBlockedItems_is_public_and_a_noop_without_blocked_items() {
		$m = new ReflectionMethod( ActionController::class, 'checkBlockedItems' );
		$this->assertTrue( $m->isPublic(), 'register_shutdown_function() cannot call protected methods.' );
		$p = new ReflectionProperty( ActionController::class, 'blockedItems' );
		$p->setAccessible( true );
		$p->setValue( null, null );
		( new ActionController() )->checkBlockedItems();
		$p->setValue( null, array() );
		( new ActionController() )->checkBlockedItems();
		$this->assertSame( array(), $p->getValue( null ) );
	}

	public function test_a_successful_result_is_downloaded_backed_up_applied_marked_and_finished() {
		$id = $this->makeImageAttachment( 400, 300 );
		$originalBytes = file_get_contents( get_attached_file( $id ) );
		list( $qItem, $q, $controller ) = $this->dequeued( $id );
		$qItem->addResult( $controller->api()->returnSuccess( array( 'optimized' => 'https://api.shortpixel.com/f/result.png', 'original' => $qItem->imageModel->getUrl() ) ) );

		$controller->handleAPIResult( $qItem );

		$result = $qItem->result();
		$this->assertFalse( $result->is_error, $result->message );
		$this->assertTrue( $result->is_done );
		$this->assertSame( QueueItem::FILE_STATUS_SUCCESS, $result->fileStatus );
		$this->assertSame( 'remove_background', $result->action );
		$this->assertSame( 'Background removed', $result->message );

		// The file was replaced in place: same path, new bytes, new dimensions.
		$path = get_attached_file( $id );
		$this->assertSame( basename( $path ), $result->filename );
		$this->assertNotSame( $originalBytes, file_get_contents( $path ) );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 190, $meta['width'], 'codeispoetry.png is 190 px wide.' );

		// Backup holds the original and the marker points at it.
		$marker = BulkImage::getById( $id )->getMarker();
		$this->assertArrayHasKey( 'background', $marker );
		$this->assertSame( 1, $marker['background']['bg_remove'] );
		$this->assertSame( $result->backup, $marker['backup'] );
		$this->assertSame( $originalBytes, file_get_contents( BackupController::getBackupFile( $marker['backup'] )->getFullPath() ) );
		$this->assertSame( BackupController::getBackupUrl( $marker['backup'] ), $result->original );
		$this->assertSame( wp_get_attachment_url( $id ), $result->optimized );

		// Finished in the queue, hooks fired, block lifted.
		$this->assertSame( ShortQ::QSTATUS_DONE, (int) $this->queueRows( $id )[0]->status );
		$this->assertSame( 1, $q->getStats()->done );
		$this->assertContains( 'done:remove_background:' . $id, $this->events );
		$this->assertContains( 'replaced:' . $id, $this->events, 'ReplaceController fired enable-media-replace-upload-done.' );
		$this->assertFalse( $qItem->block() );
		$this->assertCount( 1, array_filter( $this->requests, function ( $u ) {
			return false !== strpos( $u, '/f/' );
		} ) );
	}

	public function test_an_upscale_result_writes_the_factor_and_source_width_into_the_marker() {
		$id = $this->makeImageAttachment( 500, 300 );
		list( $qItem, $q, $controller ) = $this->dequeued( $id, 'upscale' );
		$qItem->addResult( $controller->api()->returnSuccess( array( 'optimized' => 'https://api.shortpixel.com/f/up.png', 'original' => '' ) ) );

		$controller->handleAPIResult( $qItem );

		$this->assertFalse( $qItem->result()->is_error, $qItem->result()->message );
		$this->assertSame( 'Image upscaled', $qItem->result()->message );
		$marker = BulkImage::getById( $id )->getMarker();
		$this->assertSame( 4, $marker['upscale']['factor'] );
		$this->assertSame( 500, $marker['upscale']['from_width'] );
		$this->assertContains( 'done:scale_image:' . $id, $this->events );
	}

	public function test_a_failed_download_fails_the_item_without_touching_the_file() {
		$id = $this->makeImageAttachment( 400, 300 );
		$bytes = file_get_contents( get_attached_file( $id ) );
		list( $qItem, $q, $controller ) = $this->dequeued( $id );
		$qItem->addResult( $controller->api()->returnSuccess( array( 'optimized' => 'https://api.shortpixel.com/missing/result.png' ) ) );

		$controller->handleAPIResult( $qItem );

		$this->assertTrue( $qItem->result()->is_error );
		$this->assertTrue( $qItem->result()->is_done );
		$this->assertSame( BulkApi::ERR_DOWNLOAD, $qItem->result()->apiStatus );
		$this->assertSame( $bytes, file_get_contents( get_attached_file( $id ) ) );
		$this->assertSame( array(), BulkImage::getById( $id )->getMarker() );
		$this->assertSame( ShortQ::QSTATUS_FATAL, (int) $this->queueRows( $id )[0]->status );
		$this->assertContains( 'failed:' . $id, $this->events );
		$this->assertFalse( $qItem->block() );
	}

	public function test_a_terminal_api_error_fails_the_item_and_maps_quota_and_key_errors_for_the_browser() {
		$id = $this->makeImageAttachment( 400, 300 );
		list( $qItem, $q, $controller ) = $this->dequeued( $id );
		$qItem->addResult( $controller->api()->returnFailure( BulkApi::STATUS_ERROR, 'Invalid image format' ) );

		$controller->handleAPIResult( $qItem );
		$this->assertSame( QueueItem::FILE_STATUS_ERROR, $qItem->result()->fileStatus );
		$this->assertStringContainsString( 'Invalid image format', $qItem->result()->message );
		$this->assertStringContainsString( (string) $id, $qItem->result()->message, 'Attachment ID appended to API errors.' );
		$this->assertSame( ShortQ::QSTATUS_FATAL, (int) $this->queueRows( $id )[0]->status );
		$this->assertNull( $qItem->result()->error );

		$quota = new QueueItem( array( 'imageModel' => BulkImage::getById( $id ) ) );
		$quota->newRemoveBackgroundAction();
		$quota->addResult( $controller->api()->returnRetry( BulkApi::STATUS_QUOTA_EXCEEDED, 'Quota exceeded.' ) );
		$controller->handleAPIResult( $quota );
		$this->assertSame( QueueController::NOQUOTA, $quota->result()->error );
		$this->assertFalse( $quota->result()->is_done );

		$key = new QueueItem( array( 'imageModel' => BulkImage::getById( $id ) ) );
		$key->newRemoveBackgroundAction();
		$key->addResult( $controller->api()->returnFailure( BulkApi::STATUS_NO_KEY, 'Wrong key' ) );
		$controller->handleAPIResult( $key );
		$this->assertSame( QueueController::APIKEY_FAILED, $key->result()->error );

		$server = new QueueItem( array( 'imageModel' => BulkImage::getById( $id ) ) );
		$server->newRemoveBackgroundAction();
		$server->addResult( $controller->api()->returnRetry( BulkApi::STATUS_MAINTENANCE, 'Maintenance' ) );
		$controller->handleAPIResult( $server );
		$this->assertSame( QueueController::SERVER_ERROR, $server->result()->error );
	}

	public function test_a_waiting_result_keeps_the_item_pending_until_the_retry_limit() {
		$id = $this->makeImageAttachment( 400, 300 );
		list( $qItem, $q, $controller ) = $this->dequeued( $id );
		$qItem->addResult( $controller->api()->returnOK( BulkApi::STATUS_UNCHANGED, 'Item is waiting' ) );

		$controller->handleAPIResult( $qItem );
		$this->assertFalse( $qItem->result()->is_error );
		$this->assertFalse( $qItem->result()->is_done );
		$this->assertSame( QueueItem::FILE_STATUS_PENDING, $qItem->result()->fileStatus );
		$this->assertSame( 'Processing - waiting for results', $qItem->result()->message );
		$this->assertSame( ShortQ::QSTATUS_INPROCESS, (int) $this->queueRows( $id )[0]->status );

		$qItem->setData( 'tries', (int) $q->getShortQ()->getOption( 'retry_limit' ) );
		$qItem->addResult( $controller->api()->returnOK( BulkApi::STATUS_UNCHANGED, 'Item is waiting' ) );
		$controller->handleAPIResult( $qItem );
		$this->assertTrue( $qItem->result()->is_error );
		$this->assertTrue( $qItem->result()->is_done );
		$this->assertSame( BulkApi::ERR_TIMEOUT, $qItem->result()->apiStatus );
		$this->assertSame( ShortQ::QSTATUS_FATAL, (int) $this->queueRows( $id )[0]->status );
	}

	public function test_a_blocked_item_is_skipped_by_the_tick() {
		$id = $this->makeImageAttachment( 400, 300 );
		list( $qItem, $q, $controller ) = $this->dequeued( $id );
		$this->invoke( $controller, 'blockItem', array( $qItem ) );
		$this->assertTrue( $q->getItem( $id )->block(), 'The block is persisted.' );
		$this->assertTrue( $controller->shutdown_registered );

		$this->invoke( $controller, 'unBlockItem', array( $qItem ) );
		$this->assertFalse( $q->getItem( $id )->block() );
	}

	public function test_restore_copies_the_backup_back_and_removes_marker_and_backup() {
		$id = $this->makeImageAttachment( 400, 300 );
		$originalBytes = file_get_contents( get_attached_file( $id ) );
		list( $qItem, $q, $controller ) = $this->dequeued( $id );
		$qItem->addResult( $controller->api()->returnSuccess( array( 'optimized' => 'https://api.shortpixel.com/f/result.png' ) ) );
		$controller->handleAPIResult( $qItem );
		$this->assertSame( 190, wp_get_attachment_metadata( $id )['width'], 'Precondition: replaced.' );
		$backup = BulkImage::getById( $id )->getMarker()['backup'];
		$this->events = array();

		BulkController::getInstance()->createNewBulk( array( 'operation' => 'background', 'customOp' => BulkQueue::CUSTOM_OP_RESTORE ) );
		$q = new BulkQueue();
		while ( BulkQueue::RESULT_PREPARING_DONE !== $q->run()->qstatus ) {
		}
		$q->startBulk();
		$restore = $q->run()->items[0];
		$this->assertSame( 'restore', $restore->data()->action );
		$controller->setCurrentQueue( $q, new QueueController() );

		$controller->sendToProcessing( $restore );
		$controller->handleAPIResult( $restore );

		$this->assertFalse( $restore->result()->is_error, $restore->result()->message );
		$this->assertTrue( $restore->result()->is_done );
		$this->assertSame( BulkApi::STATUS_NOT_API, $restore->result()->apiStatus );
		$this->assertStringContainsString( 'Restore completed', $restore->result()->message );
		$this->assertSame( $originalBytes, file_get_contents( get_attached_file( $id ) ) );
		$this->assertSame( 400, wp_get_attachment_metadata( $id )['width'] );
		$this->assertSame( array(), BulkImage::getById( $id )->getMarker() );
		$this->assertFalse( BackupController::getBackupFile( $backup )->exists() );
		$this->assertSame( ShortQ::QSTATUS_DONE, (int) $this->queueRows( $id )[0]->status );
		$this->assertContains( 'done:restore:' . $id, $this->events );
		$this->assertContains( 'replaced:' . $id, $this->events );
	}

	public function test_restore_without_a_backup_fails_the_item() {
		$id    = $this->makeImageAttachment( 100, 100 );
		$qItem = new QueueItem( array( 'imageModel' => BulkImage::getById( $id ) ) );
		$qItem->newRestoreAction();
		$controller = ActionController::getInstance();
		$controller->setCurrentQueue( new BulkQueue(), new QueueController() );

		$controller->sendToProcessing( $qItem );

		$this->assertTrue( $qItem->result()->is_error );
		$this->assertTrue( $qItem->result()->is_done );
		$this->assertStringContainsString( 'backup', $qItem->result()->message );
	}

	public function test_enQueueItem_prepares_the_action_and_adds_the_item() {
		$id    = $this->makeImageAttachment( 600, 100 );
		$qItem = new QueueItem( array( 'imageModel' => BulkImage::getById( $id ) ) );
		$controller = ActionController::getInstance();
		$controller->setCurrentQueue( new BulkQueue(), new QueueController() );

		$status = $controller->enQueueItem( $qItem, array( 'action' => 'scale_image', 'scale' => 2 ) );

		$this->assertSame( BulkQueue::RESULT_ITEMS, $status->qstatus );
		$this->assertSame( 'scale_image', $qItem->data()->action );
		$this->assertSame( 2, $qItem->data()->paramlist['upscale'] );
		$this->assertCount( 1, $this->queueRows( $id ) );
	}

	public function test_sendToProcessing_with_an_unknown_action_fails() {
		$qItem = new QueueItem( array( 'imageModel' => BulkImage::getById( $this->makeImageAttachment( 10, 10 ) ) ) );
		$qItem->data()->action = 'optimize';
		ActionController::getInstance()->sendToProcessing( $qItem );
		$this->assertTrue( $qItem->result()->is_error );
		$this->assertSame( BulkApi::STATUS_FAIL, $qItem->result()->apiStatus );
	}
}
