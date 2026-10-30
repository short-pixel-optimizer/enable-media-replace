<?php
/**
 * Tests for EnableMediaReplace\Model\Bulk\QueueItemResult
 * (classes/Model/Bulk/QueueItemResult.php), ported from ShortPixel Image
 * Optimizer's QueueItemResult tests.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Model\Bulk\QueueItemResult;

class BulkQueueItemResultTest extends WP_UnitTestCase {

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

	private function getPrivate( QueueItemResult $r, $prop ) {
		$p = new ReflectionProperty( QueueItemResult::class, $prop );
		$p->setAccessible( true );
		return $p->getValue( $r );
	}

	public function test_constructor_assigns_item_id_and_leaves_other_fields_at_their_defaults() {
		$r = new QueueItemResult( 42 );

		$this->assertSame( 42, $this->getPrivate( $r, 'item_id' ) );
		$this->assertFalse( $this->getPrivate( $r, 'is_done' ) );
		$this->assertFalse( $this->getPrivate( $r, 'is_error' ) );
		foreach ( array( 'apiStatus', 'message', 'fileStatus', 'filename', 'error', 'original', 'optimized', 'backup', 'action' ) as $field ) {
			$this->assertNull( $this->getPrivate( $r, $field ), $field );
		}
	}

	public function test_get_returns_declared_fields_and_null_for_unknown_ones() {
		$r = new QueueItemResult( 1 );
		$this->assertSame( 1, $r->item_id );
		$this->assertFalse( $r->is_done );
		$this->assertNull( $r->definitely_not_a_field );
	}

	public function test_set_writes_declared_fields_and_drops_unknown_ones() {
		$r            = new QueueItemResult( 1 );
		$r->message   = 'a message';
		$r->is_error  = true;
		$r->apiStatus = 2;
		$r->definitely_not_a_field = 'ignored';

		$this->assertSame( 'a message', $this->getPrivate( $r, 'message' ) );
		$this->assertTrue( $this->getPrivate( $r, 'is_error' ) );
		$this->assertSame( 2, $this->getPrivate( $r, 'apiStatus' ) );
		$this->assertFalse( property_exists( $r, 'definitely_not_a_field' ) );
	}

	public function test_remove_resets_a_field_to_null_and_ignores_unknown_ones() {
		$r          = new QueueItemResult( 1 );
		$r->message = 'a message';
		$r->remove( 'message' );
		$r->remove( 'not-a-field' );

		$this->assertNull( $this->getPrivate( $r, 'message' ) );
		$this->assertFalse( property_exists( $r, 'not-a-field' ) );
	}

	public function test_forReturn_strips_null_fields_and_empty_arrays_but_keeps_falsy_scalars() {
		$r            = new QueueItemResult( 42 );
		$r->message   = '';
		$r->apiStatus = 0;
		$r->success   = false;
		$r->data      = array();

		$obj = $r->forReturn();

		$this->assertIsObject( $obj );
		$this->assertSame( 42, $obj->item_id );
		$this->assertSame( '', $obj->message );
		$this->assertSame( 0, $obj->apiStatus );
		$this->assertFalse( $obj->success );
		$this->assertFalse( $obj->is_done );
		$this->assertObjectNotHasProperty( 'data', $obj );
		$this->assertObjectNotHasProperty( 'filename', $obj );
	}

	public function test_json_encode_produces_the_same_stripped_shape_as_forReturn() {
		$r          = new QueueItemResult( 7 );
		$r->message = 'msg';

		$this->assertEquals( $r->forReturn(), $r->jsonSerialize() );
		$decoded = json_decode( json_encode( $r ) );
		$this->assertSame( 7, $decoded->item_id );
		$this->assertSame( 'msg', $decoded->message );
		$this->assertObjectNotHasProperty( 'optimized', $decoded );
	}
}
