<?php
/**
 * Tests for EnableMediaReplace\Model\Bulk\QueueItemData
 * (classes/Model/Bulk/QueueItemData.php), ported from ShortPixel Image
 * Optimizer's QueueItemData tests.
 *
 * Fixed-schema data container with a FIFO of next actions, a "keep data"
 * map and a lazy counters object. Every field is protected, so state is
 * read by reflection.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Model\Bulk\QueueItemData;

class BulkQueueItemDataTest extends WP_UnitTestCase {

	private function getPrivate( QueueItemData $d, $prop ) {
		$p = new ReflectionProperty( QueueItemData::class, $prop );
		$p->setAccessible( true );
		return $p->getValue( $d );
	}

	private function setPrivate( QueueItemData $d, $prop, $value ) {
		$p = new ReflectionProperty( QueueItemData::class, $prop );
		$p->setAccessible( true );
		$p->setValue( $d, $value );
	}

	public function test_constructor_leaves_every_field_uninitialised() {
		$d = new QueueItemData();
		foreach ( array( 'urls', 'action', 'next_actions', 'flags', 'counts', 'paramlist', 'backup', 'source_width' ) as $field ) {
			$this->assertNull( $this->getPrivate( $d, $field ), $field );
		}
	}

	public function test_get_returns_the_value_of_a_declared_field() {
		$d = new QueueItemData();
		$this->setPrivate( $d, 'action', 'remove_background' );
		$this->assertSame( 'remove_background', $d->action );
	}

	public function test_get_returns_null_for_unknown_field() {
		$this->assertNull( ( new QueueItemData() )->definitely_unknown );
	}

	public function test_get_coerces_null_and_non_array_flags_to_empty_array_and_returns_arrays_verbatim() {
		$d = new QueueItemData();
		$this->assertSame( array(), $d->flags );
		$this->setPrivate( $d, 'flags', 'not-an-array' );
		$this->assertSame( array(), $d->flags );
		$this->setPrivate( $d, 'flags', array( 'a', 'b' ) );
		$this->assertSame( array( 'a', 'b' ), $d->flags );
	}

	public function test_set_writes_to_a_declared_field_and_drops_unknown_ones() {
		$d         = new QueueItemData();
		$d->action = 'restore';
		$d->tries  = 3;
		$d->not_a_real_field = 'nope';

		$this->assertSame( 'restore', $this->getPrivate( $d, 'action' ) );
		$this->assertSame( 3, $this->getPrivate( $d, 'tries' ) );
		$this->assertFalse( property_exists( $d, 'not_a_real_field' ) );
	}

	public function test_remove_clears_a_declared_field_and_ignores_unknown_ones() {
		$d         = new QueueItemData();
		$d->action = 'restore';
		$d->remove( 'action' );
		$d->remove( 'not-a-real-field' );

		$this->assertNull( $this->getPrivate( $d, 'action' ) );
		$this->assertFalse( property_exists( $d, 'not-a-real-field' ) );
	}

	public function test_toObject_strips_null_fields_and_keeps_the_set_ones() {
		$d         = new QueueItemData();
		$d->action = 'scale_image';
		$d->tries  = 2;
		$d->paramlist = array( 'upscale' => 2 );

		$obj = $d->toObject();

		$this->assertSame( 'scale_image', $obj->action );
		$this->assertSame( 2, $obj->tries );
		$this->assertSame( array( 'upscale' => 2 ), $obj->paramlist );
		$this->assertObjectNotHasProperty( 'urls', $obj );
		$this->assertObjectNotHasProperty( 'backup', $obj );
	}

	public function test_hasAction_matches_current_and_queued_actions_only() {
		$d         = new QueueItemData();
		$d->action = 'remove_background';
		$this->setPrivate( $d, 'next_actions', array( 'scale_image' ) );

		$this->assertTrue( $d->hasAction( 'remove_background' ) );
		$this->assertTrue( $d->hasAction( 'scale_image' ) );
		$this->assertFalse( $d->hasAction( 'restore' ) );
	}

	public function test_next_actions_fifo() {
		$d = new QueueItemData();
		$this->assertFalse( $d->hasNextAction() );
		$this->assertNull( $d->popNextAction() );

		$d->addNextAction( 'restore' );
		$d->addNextAction( 'scale_image' );
		$this->assertSame( array( 'restore', 'scale_image' ), $this->getPrivate( $d, 'next_actions' ) );
		$this->assertTrue( $d->hasNextAction() );

		$this->assertSame( 'restore', $d->popNextAction() );
		$this->assertSame( 'scale_image', $d->popNextAction() );
		$this->assertNull( $d->popNextAction() );
		$this->assertFalse( $d->hasNextAction() );
	}

	public function test_addKeepDataArgs_wraps_scalars_appends_and_deduplicates() {
		$d = new QueueItemData();
		$d->addKeepDataArgs( 'compressionType' );
		$d->addKeepDataArgs( array( 'queue_list_order', 'compressionType', 'backup' ) );

		$this->assertSame( array( 'compressionType', 'queue_list_order', 'backup' ), array_values( $this->getPrivate( $d, 'next_keepdata' ) ) );
	}

	public function test_getKeepDataArgs_resolves_field_names_keeps_pairs_and_drops_nulls_and_unknowns() {
		$d = new QueueItemData();
		$this->assertSame( array(), $d->getKeepDataArgs() );

		$this->setPrivate( $d, 'compressionType', 0 );
		$this->setPrivate( $d, 'backup', '2026/10/a.jpg' );
		$d->addKeepDataArgs( array( 'compressionType', 'backup', 'tries', 'not_a_real_property', 'custom_key' => 'custom_value', 'null_key' => null ) );

		$this->assertSame(
			array( 'compressionType' => 0, 'backup' => '2026/10/a.jpg', 'custom_key' => 'custom_value' ),
			$d->getKeepDataArgs()
		);
	}

	public function test_addCount_lazily_creates_and_merges_the_counts_object() {
		$d = new QueueItemData();
		$this->assertNull( $this->getPrivate( $d, 'counts' ) );

		$d->addCount( array( 'baseCount' => 5, 'webpCount' => 3 ) );
		$d->addCount( array( 'avifCount' => 2, 'baseCount' => 7 ) );

		$counts = $this->getPrivate( $d, 'counts' );
		$this->assertInstanceOf( stdClass::class, $counts );
		$this->assertSame( 7, $counts->baseCount );
		$this->assertSame( 3, $counts->webpCount );
		$this->assertSame( 2, $counts->avifCount );
	}
}
