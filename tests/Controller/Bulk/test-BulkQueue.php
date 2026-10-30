<?php
/**
 * Tests for EnableMediaReplace\Controller\Bulk\BulkQueue (classes/Controller/Bulk/BulkQueue.php),
 * ported from ShortPixel Image Optimizer's Queue and MediaLibraryQueue tests
 * and extended with the EMR prepare phase, which runs against real
 * attachments here because BulkImage needs no image model stub.
 *
 * The queue writes to the shared `shortpixel_queue` table; every test
 * starts from resetQueue() and the helpers delete EMR's rows afterwards.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';

use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\InstallHelper;
use EnableMediaReplace\Model\Bulk\BulkImage;
use EnableMediaReplace\Model\Bulk\QueueItem;
use EnableMediaReplace\ShortQ\ShortQ;

class BulkQueueTest extends WP_UnitTestCase {

	use EMR_BulkTestHelpers;

	public function set_up() {
		parent::set_up();
		$this->bulkSetUp();
		( new BulkQueue() )->resetQueue();
	}

	public function tear_down() {
		remove_all_filters( 'emr/bulk/source_ids' );
		remove_all_filters( 'emr/bulk/queue_options' );
		$this->bulkTearDown();
		parent::tear_down();
	}

	private function invokePrivate( BulkQueue $q, $method, array $args = array() ) {
		$m = new ReflectionMethod( BulkQueue::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $q, ...$args );
	}

	/** Runs run() while the queue prepares; returns the last result. */
	private function prepareAll( BulkQueue $q, $maxTicks = 10 ) {
		for ( $i = 0; $i < $maxTicks; $i++ ) {
			$result = $q->run();
			if ( BulkQueue::RESULT_PREPARING_DONE === $result->qstatus ) {
				return $result;
			}
		}
		$this->fail( 'Still preparing after ' . $maxTicks . ' ticks: ' . wp_json_encode( $result ) );
	}

	public function test_result_constants_match_spio_and_are_distinct() {
		$constants = array(
			BulkQueue::RESULT_ITEMS, BulkQueue::RESULT_PREPARING, BulkQueue::RESULT_PREPARING_OVERLIMIT,
			BulkQueue::RESULT_PREPARING_DONE, BulkQueue::RESULT_EMPTY, BulkQueue::RESULT_QUEUE_EMPTY,
			BulkQueue::RESULT_RECOUNT, BulkQueue::RESULT_ERROR, BulkQueue::RESULT_UNKNOWN,
		);
		$this->assertSame( array( 1, 2, 5, 3, 4, 10, 11, -1, -10 ), $constants );
		$this->assertSame( count( $constants ), count( array_unique( $constants ) ) );
	}

	public function test_queue_uses_the_emr_slug_and_the_bulk_name_with_spio_like_options() {
		$q = new BulkQueue();
		$this->assertSame( InstallHelper::QUEUE_NAME, $q->getQueueName() );
		$this->assertSame( 'media', $q->getType() );
		$this->assertIsObject( $q->getShortQ() );
		$this->assertSame( 5, $q->getShortQ()->getOption( 'numitems' ) );
		$this->assertSame( 'wait', $q->getShortQ()->getOption( 'mode' ) );
		$this->assertSame( 30, $q->getShortQ()->getOption( 'retry_limit' ) );
		$this->assertSame( 200, $q->getShortQ()->getOption( 'enqueue_limit' ) );
		$this->assertIsArray( get_option( 'shortqwp_' . InstallHelper::QUEUE_SLUG ) );
	}

	public function test_queue_options_filter_is_applied() {
		add_filter( 'emr/bulk/queue_options', function ( $options ) {
			$options['numitems'] = 2;
			return $options;
		} );
		$this->assertSame( 2, ( new BulkQueue() )->getShortQ()->getOption( 'numitems' ) );
	}

	public function test_getStats_shape_and_defaults_on_an_empty_queue() {
		$stats = ( new BulkQueue() )->getStats();

		foreach ( array( 'is_preparing', 'is_running', 'is_finished', 'bulk_running' ) as $flag ) {
			$this->assertIsBool( $stats->$flag, $flag );
		}
		foreach ( array( 'in_queue', 'in_process', 'awaiting', 'errors', 'fatal_errors', 'done', 'total' ) as $count ) {
			$this->assertIsInt( $stats->$count, $count );
			$this->assertSame( 0, $stats->$count, $count );
		}
		$this->assertSame( $stats->in_queue + $stats->in_process, $stats->awaiting );
		$this->assertSame( 100, (int) $stats->percentage_done );
		$this->assertSame( 'background', $stats->operation );
		$this->assertObjectHasProperty( 'images', $stats, 'Counts are recounted while not running.' );
		$this->assertSame( 0, $stats->images->images );
		$this->assertObjectNotHasProperty( 'customOperation', $stats );
	}

	public function test_custom_data_round_trips_through_setBulkOptions() {
		$q = new BulkQueue();
		$this->assertFalse( $q->getOptions() );
		$this->assertFalse( $q->getCustomDataItem( 'nonExistentKey' ) );
		$this->assertFalse( $q->isCustomOperation() );
		$this->assertFalse( $q->setBulkOptions( array() ) );

		$q->setBulkOptions( array( 'customOp' => BulkQueue::CUSTOM_OP_RESTORE, 'operation' => 'upscale', 'threshold' => 800 ) );

		$this->assertSame( BulkQueue::CUSTOM_OP_RESTORE, $q->getCustomDataItem( 'customOperation' ) );
		$this->assertTrue( $q->isCustomOperation() );
		$this->assertSame( array( 'operation' => 'upscale', 'threshold' => 800 ), $q->getOptions() );
		$this->assertSame( 'upscale', $q->getOperation() );
		$stats = $q->getStats();
		$this->assertSame( BulkQueue::CUSTOM_OP_RESTORE, $stats->customOperation );
		$this->assertSame( '10', $stats->isCustomOperation );

		// A fresh object reads the same persisted state.
		$this->assertSame( 'upscale', ( new BulkQueue() )->getOperation() );
	}

	public function test_getOperation_falls_back_to_background_for_unknown_values() {
		$q = new BulkQueue();
		$q->setBulkOptions( array( 'operation' => 'optimize' ) );
		$this->assertSame( 'background', $q->getOperation() );
	}

	public function test_createNewBulk_resets_flags_preparing_and_applies_shortq_option_overrides() {
		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background', 'numitems' => 2, 'retry_limit' => 7, 'items' => array( 5, 6 ) ) );

		$stats = $q->getStats();
		$this->assertTrue( $stats->is_preparing );
		$this->assertTrue( $stats->bulk_running );
		$this->assertFalse( $stats->is_running );
		$this->assertFalse( $stats->is_finished );
		$this->assertSame( 2, $q->getShortQ()->getOption( 'numitems' ) );
		$this->assertSame( 7, $q->getShortQ()->getOption( 'retry_limit' ) );
		$this->assertSame( array( 5, 6 ), $q->getOptions()['items'] );

		$q->startBulk();
		$this->assertFalse( $q->getStats()->is_preparing );
		$this->assertTrue( $q->getStats()->is_running );
	}

	public function test_run_on_a_bulk_queue_that_is_prepared_but_not_started_reports_preparing_done_and_does_not_dequeue() {
		$id = $this->makeImageAttachment( 300, 200 );
		$q  = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$this->prepareAll( $q );

		$result = $q->run();

		$this->assertSame( BulkQueue::RESULT_PREPARING_DONE, $result->qstatus );
		$this->assertNull( $result->items );
		$this->assertCount( 1, $this->queueRows( $id ), 'The row is still waiting.' );
		$this->assertSame( ShortQ::QSTATUS_WAITING, (int) $this->queueRows( $id )[0]->status );
	}

	public function test_prepare_enqueues_image_attachments_and_skips_non_images_marked_and_unsupported_ones() {
		$jpg  = $this->makeImageAttachment( 300, 200 );
		$png  = $this->makeImageAttachment( 300, 200, 'png' );
		$text = $this->makeTextAttachment();
		$gif  = $this->makeImageAttachment( 300, 200 );
		wp_update_post( array( 'ID' => $gif, 'post_mime_type' => 'image/gif' ) );
		$done = $this->makeImageAttachment( 300, 200 );
		BulkImage::getById( $done )->setMarker( 'background', array( 'ts' => time() ), 'x.jpg' );
		$upscaled = $this->makeImageAttachment( 300, 200 );
		BulkImage::getById( $upscaled )->setMarker( 'upscale', array( 'ts' => time() ), 'y.jpg' );

		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background', 'items' => array( $jpg, $png, $text, $gif, $done, $upscaled, 999999 ) ) );

		$first = $q->run();
		$this->assertSame( BulkQueue::RESULT_PREPARING, $first->qstatus );
		$this->assertSame( 3, $first->items, 'jpg, png and the upscaled-only image.' );
		$this->assertSame( 3, $first->images );

		$last = $this->prepareAll( $q );
		$this->assertSame( BulkQueue::RESULT_PREPARING_DONE, $last->qstatus );
		$this->assertFalse( $q->getStats()->is_preparing );

		$ids = array_map( 'intval', wp_list_pluck( $this->queueRows(), 'item_id' ) );
		$this->assertEqualsCanonicalizing( array( $jpg, $png, $upscaled ), $ids );

		$row   = $this->queueRows( $png )[0];
		$value = json_decode( $row->value );
		$value = isset( $value->value ) ? $value->value : $value;
		$this->assertSame( 'remove_background', $value->action );
		$this->assertSame( 1, $value->paramlist->bg_remove );
		$this->assertSame( 3, $q->getStats()->images->images_prepared );
		$this->assertSame( 3, $q->getStats()->total );
	}

	public function test_prepare_passes_the_background_colour_options_to_every_item() {
		$id = $this->makeImageAttachment( 300, 200 );
		$q  = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ), 'do_transparent' => false, 'replace_color' => '#00ff00', 'replace_transparency' => 100 ) );
		$this->prepareAll( $q );

		$value = json_decode( $this->queueRows( $id )[0]->value );
		$value = isset( $value->value ) ? $value->value : $value;
		$this->assertSame( '#00ff00FF', $value->paramlist->bg_remove );
	}

	public function test_prepare_for_upscale_applies_the_threshold_and_the_automatic_factor() {
		$narrow = $this->makeImageAttachment( 500, 200 );   // below 800: queued, 4x
		$mid    = $this->makeImageAttachment( 1100, 200 );  // above threshold: skipped
		$edge   = $this->makeImageAttachment( 800, 200 );   // equal to threshold: skipped (strictly narrower)
		$q      = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'upscale', 'threshold' => 800, 'items' => array( $narrow, $mid, $edge ) ) );
		$this->prepareAll( $q );

		$ids = array_map( 'intval', wp_list_pluck( $this->queueRows(), 'item_id' ) );
		$this->assertSame( array( $narrow ), $ids );
		$value = json_decode( $this->queueRows( $narrow )[0]->value );
		$value = isset( $value->value ) ? $value->value : $value;
		$this->assertSame( 'scale_image', $value->action );
		$this->assertSame( 4, $value->paramlist->upscale );
		$this->assertSame( 500, $value->source_width );
	}

	public function test_prepare_for_upscale_without_threshold_takes_every_image_a_factor_fits() {
		$fits  = $this->makeImageAttachment( 1200, 100 );
		$wide  = $this->makeImageAttachment( 1300, 100 );
		$q     = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'upscale', 'items' => array( $fits, $wide ) ) );
		$this->prepareAll( $q );

		$this->assertSame( array( $fits ), array_map( 'intval', wp_list_pluck( $this->queueRows(), 'item_id' ) ) );
	}

	public function test_prepare_queues_a_shared_file_once_and_skips_items_already_in_the_queue() {
		$id1  = $this->makeImageAttachment( 300, 200 );
		$path = get_attached_file( $id1 );
		// A second attachment pointing at the same file, as WPML / Polylang media duplicates do.
		$id2 = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'dup', 'post_status' => 'inherit' ), $path );
		$this->bulkAttachments[] = $id2;
		wp_update_attachment_metadata( $id2, wp_get_attachment_metadata( $id1 ) );
		$already = $this->makeImageAttachment( 300, 200 );

		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background', 'items' => array( $id1, $id2, $already ) ) );
		// Pre-seed $already as if an earlier page enqueued it.
		$pre = new QueueItem( array( 'imageModel' => BulkImage::getById( $already ) ) );
		$pre->newRemoveBackgroundAction();
		$q->addQueueItem( $pre );

		$this->prepareAll( $q );

		$ids = array_map( 'intval', wp_list_pluck( $this->queueRows(), 'item_id' ) );
		sort( $ids );
		$expected = array( max( $id1, $id2 ), $already ); // newest of the two duplicates wins (DESC order)
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	public function test_prepare_pages_the_explicit_list_by_enqueue_limit_and_last_item_id() {
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $this->makeImageAttachment( 100, 100 );
		}
		add_filter( 'emr/bulk/queue_options', function ( $options ) {
			$options['enqueue_limit'] = 2;
			return $options;
		} );
		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background', 'items' => $ids ) );

		$first = $q->run();
		$this->assertSame( BulkQueue::RESULT_PREPARING, $first->qstatus );
		$this->assertSame( 2, $first->items );
		$second = $q->run();
		$this->assertSame( BulkQueue::RESULT_PREPARING, $second->qstatus );
		$this->assertSame( 1, $second->items );
		$third = $q->run();
		$this->assertSame( BulkQueue::RESULT_PREPARING_DONE, $third->qstatus );
		$this->assertCount( 3, $this->queueRows() );
	}

	public function test_source_ids_filter_replaces_the_selector_but_not_an_explicit_list() {
		$a = $this->makeImageAttachment( 100, 100 );
		$b = $this->makeImageAttachment( 100, 100 );
		$seen = array();
		add_filter( 'emr/bulk/source_ids', function ( $ids, $operation, $queue ) use ( $b, &$seen ) {
			$seen[] = array( $operation, $queue->getSelectorCursor() );
			if ( $queue->getSelectorCursor() > 0 ) {
				return array();
			}
			$queue->setSelectorCursor( $b );
			return array( $b );
		}, 10, 3 );

		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'upscale' ) );
		$this->prepareAll( $q );

		$this->assertSame( array( $b ), array_map( 'intval', wp_list_pluck( $this->queueRows(), 'item_id' ) ), 'The filter replaced the media library selector.' );
		$this->assertSame( 'upscale', $seen[0][0] );
		$this->assertSame( 0, $seen[0][1] );
		$this->assertSame( $b, $seen[1][1], 'The cursor the callback stored is handed back.' );

		$seen = array();
		$q->createNewBulk( array( 'operation' => 'upscale', 'items' => array( $a ) ) );
		$this->prepareAll( $q );
		$this->assertSame( array( $a ), array_map( 'intval', wp_list_pluck( $this->queueRows(), 'item_id' ) ), 'An explicit list is not filtered.' );
		$this->assertSame( array(), $seen );
	}

	public function test_restore_prepare_queues_the_marked_attachments_with_a_backup() {
		$withBackup = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$relative   = BackupController::backup( $withBackup );
		$withBackup->setMarker( 'background', array( 'ts' => time() ), $relative );

		$markerOnly = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$markerOnly->setMarker( 'background', array( 'ts' => time() ), 'missing/backup.jpg' );

		$otherOp = BulkImage::getById( $this->makeImageAttachment( 100, 100 ) );
		$otherOp->setMarker( 'upscale', array( 'ts' => time() ), BackupController::backup( $otherOp ) );

		$q = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background', 'customOp' => BulkQueue::CUSTOM_OP_RESTORE ) );
		$this->prepareAll( $q );

		$this->assertSame( array( $withBackup->get( 'id' ) ), array_map( 'intval', wp_list_pluck( $this->queueRows(), 'item_id' ) ) );
		$value = json_decode( $this->queueRows( $withBackup->get( 'id' ) )[0]->value );
		$value = isset( $value->value ) ? $value->value : $value;
		$this->assertSame( 'restore', $value->action );
	}

	public function test_dequeue_converts_rows_to_queue_items_with_image_tries_and_row_attached() {
		$id = $this->makeImageAttachment( 300, 200 );
		$q  = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$this->prepareAll( $q );
		$q->startBulk();

		$result = $q->run();

		$this->assertSame( BulkQueue::RESULT_ITEMS, $result->qstatus );
		$this->assertCount( 1, $result->items );
		$item = $result->items[0];
		$this->assertInstanceOf( QueueItem::class, $item );
		$this->assertSame( $id, $item->item_id );
		$this->assertInstanceOf( BulkImage::class, $item->imageModel );
		$this->assertSame( 'remove_background', $item->data()->action );
		$this->assertSame( 0, (int) $item->data()->tries );
		$this->assertIsObject( $item->getQueueItem() );
		$this->assertSame( ShortQ::QSTATUS_INPROCESS, (int) $this->queueRows( $id )[0]->status );

		$q->itemDone( $item );
		$this->assertSame( ShortQ::QSTATUS_DONE, (int) $this->queueRows( $id )[0]->status );
		$this->assertSame( BulkQueue::RESULT_QUEUE_EMPTY, $q->run()->qstatus );
	}

	public function test_itemFailed_marks_the_row_and_keeps_the_updated_value() {
		$id = $this->makeImageAttachment( 300, 200 );
		$q  = new BulkQueue();
		$q->createNewBulk( array( 'operation' => 'background', 'items' => array( $id ) ) );
		$this->prepareAll( $q );
		$q->startBulk();
		$item = $q->run()->items[0];
		$item->setData( 'backup', 'kept.jpg' );

		$q->itemFailed( $item, true );

		$row = $this->queueRows( $id )[0];
		$this->assertSame( ShortQ::QSTATUS_FATAL, (int) $row->status );
		$value = json_decode( $row->value );
		$value = isset( $value->value ) ? $value->value : $value;
		$this->assertSame( 'kept.jpg', $value->backup );
		$this->assertSame( 1, $q->getStats()->fatal_errors );
	}

	public function test_isItemInQueue_getItem_updateItem_and_dropItem() {
		$id = $this->makeImageAttachment( 300, 200 );
		$q  = new BulkQueue();
		$this->assertFalse( $q->isItemInQueue( $id ) );
		$this->assertFalse( $q->getItem( $id ) );

		$qItem = new QueueItem( array( 'imageModel' => BulkImage::getById( $id ) ) );
		$qItem->newRemoveBackgroundAction();
		$status = $q->addQueueItem( $qItem );
		$this->assertSame( BulkQueue::RESULT_ITEMS, $status->qstatus );
		$this->assertSame( 1, $status->numitems );

		$this->assertTrue( $q->isItemInQueue( $id ) );
		$stored = $q->getItem( $id );
		$this->assertInstanceOf( QueueItem::class, $stored );
		$stored->data()->addNextAction( 'scale_image' );
		$q->updateItem( $stored );
		$this->assertSame( array( 'scale_image' ), $q->getItem( $id )->data()->next_actions );

		$q->dropItem( $id );
		$this->assertCount( 0, $this->queueRows( $id ) );
	}

	/**
	 * ShortQ's WPQ keeps a static per-request cache of the Item objects
	 * getItem() returned ($itemCache). itemDone(), itemFailed() and
	 * updateItemValue() clear the entry they touch, but resetQueue() and
	 * removeItems() delete rows without clearing the cache, so in the same
	 * request getItem() still returns an Item for a row that no longer
	 * exists, and BulkQueue::isItemInQueue() reports it as queued. A bulk
	 * created right after another one finished in the same request would
	 * skip those attachments as "already queued". The AJAX flow runs
	 * finishBulk() and createBulk() in separate requests, so the UI is not
	 * affected; CLI, cron and tests are.
	 *
	 * How to recognise the fix: WPQ::resetQueue() (and removeRecords
	 * callers) clear $itemCache, isItemInQueue() returns false after the
	 * reset below, and the assertTrue fails.
	 */
	public function test_isItemInQueue_after_resetQueue_still_reports_the_dropped_item_pinned_for_deferred_fix() {
		$id    = $this->makeImageAttachment( 100, 100 );
		$q     = new BulkQueue();
		$qItem = new QueueItem( array( 'imageModel' => BulkImage::getById( $id ) ) );
		$qItem->newRemoveBackgroundAction();
		$q->addQueueItem( $qItem );
		$this->assertTrue( $q->isItemInQueue( $id ), 'Precondition: queued and cached by WPQ::getItem().' );
		$this->assertCount( 1, $this->queueRows( $id ) );

		$q->resetQueue();

		$this->assertCount( 0, $this->queueRows( $id ), 'The row is gone.' );
		$this->assertTrue( ( new BulkQueue() )->isItemInQueue( $id ), 'Stale: WPQ::$itemCache still holds the Item.' );
	}

	public function test_getQStatus_maps_counts() {
		$q = new BulkQueue();
		$this->assertSame( BulkQueue::RESULT_ITEMS, $this->invokePrivate( $q, 'getQStatus', array( 1 ) ) );
		$this->assertSame( BulkQueue::RESULT_QUEUE_EMPTY, $this->invokePrivate( $q, 'getQStatus', array( 0 ) ) );

		$id = $this->makeImageAttachment( 100, 100 );
		$qItem = new QueueItem( array( 'imageModel' => BulkImage::getById( $id ) ) );
		$qItem->newRemoveBackgroundAction();
		$q->addQueueItem( $qItem );
		$this->assertSame( BulkQueue::RESULT_EMPTY, $this->invokePrivate( $q, 'getQStatus', array( 0 ) ), 'Items waiting: empty tick, not finished.' );
	}
}
