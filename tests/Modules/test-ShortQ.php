<?php
/**
 * Tests for the bundled ShortQ module (build/shortpixel/shortq) as EMR
 * uses it: the EnableMediaReplace\ShortQ namespace, the rewritten hook
 * prefix, and the row scoping that lets EMR share the
 * `{prefix}shortpixel_queue` table with SPIO and RTA.
 *
 * Constructing a queue creates the table when the plugin's status option
 * is missing (WPQ::createQueue()); CREATE TABLE commits the surrounding
 * test transaction, so every test removes the rows it wrote itself in
 * tear_down() and the status option is deleted explicitly. The test slug
 * is per-run unique so parallel plugins' rows are never touched.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\ShortQ\ShortQ;
use EnableMediaReplace\ShortQ\Queue\WPQ;
use EnableMediaReplace\ShortQ\Item;

class ShortQTest extends WP_UnitTestCase {

	/** @var string */
	private $slug;

	/** @var string */
	private $table;

	public function set_up() {
		global $wpdb;
		parent::set_up();
		$this->slug  = 'emrt' . substr( uniqid(), -6 ); // 10 chars, within VARCHAR(30).
		$this->table = $wpdb->prefix . 'shortpixel_queue';
	}

	public function tear_down() {
		global $wpdb;
		if ( $this->table_exists() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE plugin_slug IN (%s, %s)", $this->slug, 'SPIOTEST' ) );
		}
		delete_option( 'shortqwp_' . $this->slug );
		parent::tear_down();
	}

	private function table_exists() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ) === $this->table;
	}

	private function queue( $name = 'bulk' ) {
		$shortQ = new ShortQ( $this->slug );
		return $shortQ->getQueue( $name );
	}

	private function rows( $slug, $name = 'bulk' ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE plugin_slug = %s AND queue_name = %s", $slug, $name ) );
	}

	public function test_module_is_bundled_under_the_plugin_namespace() {
		$this->assertTrue( class_exists( ShortQ::class ) );
		$this->assertTrue( class_exists( WPQ::class ) );
		$this->assertTrue( class_exists( Item::class ) );
		$this->assertFalse( class_exists( 'ShortPixel\ShortQ\ShortQ', false ), 'The unrewritten SPIO namespace must not leak into EMR.' );
		$this->assertSame( 0, ShortQ::QSTATUS_WAITING );
		$this->assertSame( 2, ShortQ::QSTATUS_INPROCESS );
		$this->assertSame( 3, ShortQ::QSTATUS_DONE );
		$this->assertSame( -2, ShortQ::QSTATUS_ERROR );
		$this->assertSame( -3, ShortQ::QSTATUS_FATAL );
	}

	public function test_build_rewrote_the_timeout_hook_prefix_to_emr() {
		$source = file_get_contents( dirname( dirname( __DIR__ ) ) . '/build/shortpixel/shortq/src/Queue/WPQ.php' );
		$this->assertStringContainsString( "do_action('emr/modules/wpq/item/timeout'", $source );
		$this->assertStringNotContainsString( "do_action('shortpixel/modules/wpq/item/timeout'", $source );
	}

	public function test_get_queue_creates_the_shared_table_and_the_plugin_status_option() {
		$q = $this->queue();

		$this->assertInstanceOf( WPQ::class, $q );
		$this->assertTrue( $this->table_exists() );
		$status = get_option( 'shortqwp_' . $this->slug );
		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'bulk', $status['queues'] );
	}

	public function test_enqueue_then_dequeue_in_wait_mode_marks_items_in_process_until_done() {
		$q = $this->queue();
		$q->setOptions( array( 'mode' => 'wait', 'numitems' => 2 ) );

		$count = $q->enqueue( array(
			array( 'id' => 1001, 'value' => array( 'action' => 'remove_background' ) ),
			array( 'id' => 1002, 'value' => array( 'action' => 'remove_background' ) ),
			array( 'id' => 1003, 'value' => array( 'action' => 'remove_background' ) ),
		) );
		$this->assertSame( 3, $count );
		$this->assertSame( 3, $this->rows( $this->slug ) );

		$items = $q->dequeue();
		$this->assertCount( 2, $items );
		$this->assertInstanceOf( Item::class, $items[0] );
		$this->assertSame( 1001, (int) $items[0]->item_id );
		$this->assertSame( 'remove_background', $items[0]->value['action'], 'Array values round-trip as arrays.' );
		$this->assertSame( 1, $q->getStatus( 'items' ) );
		$this->assertSame( 2, $this->rows_with_status( ShortQ::QSTATUS_INPROCESS ) );

		$q->itemDone( $items[0] );
		$q->itemDone( $items[1] );
		$this->assertSame( 2, $this->rows_with_status( ShortQ::QSTATUS_DONE ) );
		$this->assertSame( 0, $this->rows_with_status( ShortQ::QSTATUS_INPROCESS ) );

		$rest = $q->dequeue();
		$this->assertCount( 1, $rest );
		$this->assertSame( 1003, (int) $rest[0]->item_id );
		$this->assertSame( 0, $q->getStatus( 'items' ) );
	}

	/**
	 * WPQ::setStatusCount() resyncs the counters from the table when
	 * `timeout_recount` seconds passed since the last recount (always true
	 * on a fresh Status, whose `last_count_time` is 0) and then adds $change
	 * on top. dequeue() has already set the rows to INPROCESS by then, so the
	 * fresh count already contains them and the in_process counter comes out
	 * doubled: two dequeued items read as 4. The row status in the table is
	 * right; only the cached counter (what a UI shows) is wrong until the
	 * next recount.
	 *
	 * How to recognise the fix: getStatus('in_process') equals the number
	 * of INPROCESS rows (2) right after the first dequeue(), and the
	 * assertSame( 4, ... ) below fails.
	 */
	public function test_first_dequeue_doubles_the_in_process_counter_pinned_for_deferred_fix() {
		$q = $this->queue();
		$q->setOptions( array( 'mode' => 'wait', 'numitems' => 2 ) );
		$q->enqueue( array( array( 'id' => 21, 'value' => 'a' ), array( 'id' => 22, 'value' => 'b' ), array( 'id' => 23, 'value' => 'c' ) ) );
		$this->assertSame( 0, $q->getStatus( 'last_count_time' ), 'Precondition: no recount happened yet, so the first setStatusCount() recounts.' );

		$items = $q->dequeue();

		$this->assertCount( 2, $items );
		$this->assertSame( 2, $this->rows_with_status( ShortQ::QSTATUS_INPROCESS ), 'The table is right.' );
		$this->assertSame( 4, $q->getStatus( 'in_process' ), 'The cached counter is doubled.' );
	}

	private function rows_with_status( $status ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE plugin_slug = %s AND queue_name = %s AND status = %d", $this->slug, 'bulk', $status ) );
	}

	public function test_duplicate_item_ids_are_inserted_once_per_queue() {
		$q = $this->queue();
		$q->enqueue( array( array( 'id' => 5, 'value' => 'a' ), array( 'id' => 5, 'value' => 'b' ) ) );

		$this->assertSame( 1, $this->rows( $this->slug ), 'The (plugin_slug, queue_name, item_id) unique key keeps one row.' );
	}

	public function test_rows_of_another_plugin_slug_are_neither_read_nor_reset() {
		global $wpdb;
		$q = $this->queue();
		$q->enqueue( array( array( 'id' => 7, 'value' => 'mine' ) ) );
		// A foreign plugin's row with the same item id and queue name.
		$wpdb->insert( $this->table, array(
			'queue_name'  => 'bulk',
			'plugin_slug' => 'SPIOTEST',
			'status'      => ShortQ::QSTATUS_WAITING,
			'list_order'  => 10,
			'item_id'     => 7,
			'item_count'  => 1,
			'value'       => 'theirs',
			'tries'       => 0,
		) );
		$this->assertSame( 1, $this->rows( 'SPIOTEST' ), 'Precondition: the foreign row exists.' );

		$items = $q->dequeue();
		$this->assertCount( 1, $items );
		$this->assertSame( 'mine', $items[0]->value );

		$q->resetQueue();
		$this->assertSame( 0, $this->rows( $this->slug ) );
		$this->assertSame( 1, $this->rows( 'SPIOTEST' ), 'resetQueue() is scoped to the plugin slug.' );
	}

	public function test_uninstall_removes_the_plugin_rows_and_option_but_keeps_a_table_with_foreign_rows() {
		global $wpdb;
		$q = $this->queue();
		$q->enqueue( array( array( 'id' => 8, 'value' => 'mine' ) ) );
		$wpdb->insert( $this->table, array(
			'queue_name'  => 'media',
			'plugin_slug' => 'SPIOTEST',
			'status'      => ShortQ::QSTATUS_WAITING,
			'list_order'  => 10,
			'item_id'     => 8,
			'item_count'  => 1,
			'value'       => 'theirs',
			'tries'       => 0,
		) );

		$q->unInstall();

		$this->assertTrue( $this->table_exists(), 'The table stays while another plugin still has rows.' );
		$this->assertSame( 0, $this->rows( $this->slug ) );
		$this->assertSame( 1, $this->rows( 'SPIOTEST', 'media' ) );
		$this->assertFalse( get_option( 'shortqwp_' . $this->slug ) );
	}
}
