<?php
/**
 * Tests for EnableMediaReplace\Model\Bulk\QueueItem (classes/Model/Bulk/QueueItem.php),
 * ported from ShortPixel Image Optimizer's QueueItem tests and extended with
 * the three EMR actions, which need no image model stub here because
 * BulkImage wraps a real attachment.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/BulkTestHelpers.php';

use EnableMediaReplace\Controller\Bulk\ActionController;
use EnableMediaReplace\Model\Bulk\BulkImage;
use EnableMediaReplace\Model\Bulk\QueueItem;
use EnableMediaReplace\Model\Bulk\QueueItemData;
use EnableMediaReplace\Model\Bulk\QueueItemResult;

class BulkQueueItemTest extends WP_UnitTestCase {

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

	private function getPrivate( QueueItem $q, $prop ) {
		$p = new ReflectionProperty( QueueItem::class, $prop );
		$p->setAccessible( true );
		return $p->getValue( $q );
	}

	private function setPrivate( QueueItem $q, $prop, $value ) {
		$p = new ReflectionProperty( QueueItem::class, $prop );
		$p->setAccessible( true );
		$p->setValue( $q, $value );
	}

	private function invokePrivate( QueueItem $q, $method, array $args = array() ) {
		$m = new ReflectionMethod( QueueItem::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $q, ...$args );
	}

	private function image( $width = 400 ) {
		return BulkImage::getById( $this->makeImageAttachment( $width, 300 ) );
	}

	public function test_constructor_shapes() {
		$q = new QueueItem();
		$this->assertInstanceOf( QueueItemData::class, $q->data() );
		$this->assertNull( $this->getPrivate( $q, 'item_id' ) );
		$this->assertNull( $this->getPrivate( $q, 'imageModel' ) );

		$q = new QueueItem( array( 'item_id' => 42 ) );
		$this->assertSame( 42, $this->getPrivate( $q, 'item_id' ) );
		$this->assertFalse( $q->checkImageModelExists() );

		$image = $this->image();
		$q     = new QueueItem( array( 'imageModel' => $image ) );
		$this->assertSame( $image, $this->getPrivate( $q, 'imageModel' ) );
		$this->assertSame( $image->get( 'id' ), $this->getPrivate( $q, 'item_id' ) );
		$this->assertTrue( $q->checkImageModelExists() );

		$q = new QueueItem( array( 'imageModel' => 'not-an-object' ) );
		$this->assertNull( $this->getPrivate( $q, 'imageModel' ) );
	}

	public function test_setData_setFromQueueData_and_block() {
		$q = new QueueItem();
		$q->setData( 'action', 'restore' );
		$q->setFromQueueData( (object) array( 'tries' => 2, 'backup' => '2026/10/a.jpg' ) );

		$this->assertSame( 'restore', $q->data()->action );
		$this->assertSame( 2, $q->data()->tries );
		$this->assertSame( '2026/10/a.jpg', $q->data()->backup );

		$this->assertNull( $q->block() );
		$q->block( true );
		$this->assertTrue( $q->block() );
		$q->block( false );
		$this->assertFalse( $q->data()->block );
	}

	public function test_result_is_created_lazily_with_the_item_id_and_reused() {
		$q = new QueueItem( array( 'item_id' => 42 ) );
		$this->assertNull( $this->getPrivate( $q, 'result' ) );
		$result = $q->result();
		$this->assertInstanceOf( QueueItemResult::class, $result );
		$this->assertSame( 42, $result->item_id );
		$this->assertSame( $result, $q->result() );
	}

	public function test_set_and_magic_get() {
		$q = new QueueItem( array( 'item_id' => 42 ) );
		$q->set( 'item_count', 5 );
		$q->set( 'not_a_real_property', 'ignored' );
		$this->assertSame( 5, $q->item_count );
		$this->assertSame( 42, $q->item_id );
		$this->assertNull( $q->definitely_not_a_field );
		$this->assertFalse( property_exists( $q, 'not_a_real_property' ) );
	}

	public function test_getQueueItem_wraps_the_row_with_the_current_data() {
		$q = new QueueItem( array( 'item_id' => 42 ) );
		$this->assertFalse( $q->getQueueItem() );

		$this->setPrivate( $q, 'queueItem', (object) array( 'id' => 42, 'item_id' => 42 ) );
		$q->data()->action = 'scale_image';
		$row = $q->getQueueItem();
		$this->assertSame( 42, $row->item_id );
		$this->assertSame( 'scale_image', $row->value->action );
	}

	public function test_returnEnqueue_shape() {
		$q = new QueueItem( array( 'item_id' => 42 ) );
		$q->data()->action = 'remove_background';
		$q->set( 'item_count', 3 );
		$payload = $q->returnEnqueue();

		$this->assertSame( 42, $payload['id'] );
		$this->assertSame( 3, $payload['item_count'] );
		$this->assertSame( 'remove_background', $payload['value']->action );
		$this->assertArrayNotHasKey( 'order', $payload );

		$q->data()->queue_list_order = 99;
		$this->assertSame( 99, $q->returnEnqueue()['order'] );
	}

	public function test_addResult_forwards_each_entry() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->addResult( array( 'message' => 'ok', 'apiStatus' => 2, 'is_done' => true ) );
		$this->assertSame( 'ok', $q->result()->message );
		$this->assertSame( 2, $q->result()->apiStatus );
		$this->assertTrue( $q->result()->is_done );
	}

	public function test_newAction_keeps_next_actions_and_keep_data_and_resets_the_result() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->data()->next_actions = array( 'scale_image' );
		$q->data()->backup       = '2026/10/a.jpg';
		$q->data()->addKeepDataArgs( 'backup' );
		$q->result()->message = 'stale';
		$before = $q->result();

		$this->invokePrivate( $q, 'newAction' );

		$this->assertSame( array( 'scale_image' ), $q->data()->next_actions );
		$this->assertSame( '2026/10/a.jpg', $q->data()->backup );
		$this->assertNotSame( $before, $q->result() );
		$this->assertNull( $q->result()->message );
	}

	public function test_newRemoveBackgroundAction_transparent_default() {
		$image = $this->image( 400 );
		$q     = new QueueItem( array( 'imageModel' => $image ) );
		$q->newRemoveBackgroundAction();

		$this->assertSame( 'remove_background', $q->data()->action );
		$this->assertSame( 0, $q->data()->compressionType );
		$this->assertSame( array( $image->getUrl() ), $q->data()->urls );
		$this->assertSame( array( 'bg_remove' => 1, 'refresh' => true ), $q->data()->paramlist );
		$this->assertSame( 0, $q->data()->tries );
		$this->assertSame( 400, $q->data()->source_width );
		$this->assertSame( 1, $q->item_count );
	}

	/**
	 * Same encoding as Api::request() for the single flow: colour plus a
	 * two-digit alpha; 100 becomes FF; values below 10 are zero padded.
	 */
	public function test_newRemoveBackgroundAction_colour_encodes_the_alpha_like_the_single_flow() {
		$q = new QueueItem( array( 'imageModel' => $this->image() ) );

		$q->newRemoveBackgroundAction( array( 'do_transparent' => false, 'replace_color' => '#ffffff', 'replace_transparency' => 100, 'refresh' => false ) );
		$this->assertSame( '#ffffffFF', $q->data()->paramlist['bg_remove'] );
		$this->assertFalse( $q->data()->paramlist['refresh'] );

		$q->newRemoveBackgroundAction( array( 'do_transparent' => false, 'replace_color' => '#000000', 'replace_transparency' => 5 ) );
		$this->assertSame( '#00000005', $q->data()->paramlist['bg_remove'] );

		$q->newRemoveBackgroundAction( array( 'do_transparent' => false, 'replace_color' => '#000000', 'replace_transparency' => 50 ) );
		$this->assertSame( '#00000050', $q->data()->paramlist['bg_remove'] );

		$q->newRemoveBackgroundAction( array( 'do_transparent' => false, 'replace_color' => null ) );
		$this->assertSame( 1, $q->data()->paramlist['bg_remove'], 'No colour means transparent.' );
	}

	public function test_newScaleImageAction_uses_the_given_or_the_automatic_factor() {
		$q = new QueueItem( array( 'imageModel' => $this->image( 1100 ) ) );

		$q->newScaleImageAction();
		$this->assertSame( 'scale_image', $q->data()->action );
		$this->assertSame( 3, $q->data()->paramlist['upscale'], '1100 px allows 3x at most.' );
		$this->assertTrue( $q->data()->paramlist['refresh'] );
		$this->assertSame( 1100, $q->data()->source_width );
		$this->assertSame( 0, $q->data()->compressionType );

		$q->newScaleImageAction( array( 'scale' => 2, 'refresh' => false ) );
		$this->assertSame( 2, $q->data()->paramlist['upscale'] );
		$this->assertFalse( $q->data()->paramlist['refresh'] );
	}

	public function test_newRestoreAction_sets_action_and_count_without_urls() {
		$q = new QueueItem( array( 'imageModel' => $this->image() ) );
		$q->newRestoreAction();
		$this->assertSame( 'restore', $q->data()->action );
		$this->assertNull( $q->data()->urls );
		$this->assertSame( 0, $q->data()->tries );
		$this->assertSame( 1, $q->item_count );
	}

	public function test_getAPIController_routes_the_three_actions_to_ActionController_and_unknown_to_null() {
		$q = new QueueItem();
		foreach ( array( 'remove_background', 'scale_image', 'restore' ) as $action ) {
			$this->assertInstanceOf( ActionController::class, $q->getAPIController( $action ), $action );
		}
		$this->assertNull( $q->getAPIController( 'optimize' ) );
		$this->assertNull( $q->getAPIController( 'not_a_real_action' ) );

		$q->data()->action = 'restore';
		$this->assertInstanceOf( ActionController::class, $q->getAPIController() );
	}
}
