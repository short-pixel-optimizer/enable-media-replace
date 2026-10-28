<?php
/**
 * Tests for EnableMediaReplace\Replacer\Classes\Updater
 * (build/shortpixel/replacer2/src/Classes/Updater.php): updatePost() saves
 * through wp_update_post(), keeps the original modified dates and counts
 * its successes in a static counter.
 *
 * The static counter is snapshot in set_up() and restored in tear_down();
 * posts live in the test transaction.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Classes\Updater;

/**
 * Classes\Updater.
 */
class ReplacerUpdaterTest extends WP_UnitTestCase {

	/**
	 * Counter value before the test.
	 *
	 * @var int
	 */
	private $saved_counter;

	/**
	 * Hooks added by the test, as [hook, callback].
	 *
	 * @var array
	 */
	private $added_hooks = array();

	/**
	 * Snapshots the counter.
	 */
	public function set_up() {
		parent::set_up();
		$this->saved_counter = $this->counter();
	}

	/**
	 * Restores the counter and removes hooks.
	 */
	public function tear_down() {
		foreach ( $this->added_hooks as $hook ) {
			remove_action( $hook[0], $hook[1] );
		}
		$this->added_hooks = array();
		$prop              = new ReflectionProperty( Updater::class, 'updatesNumber' );
		$prop->setAccessible( true );
		$prop->setValue( null, $this->saved_counter );
		parent::tear_down();
	}

	/**
	 * Current value of the static counter.
	 *
	 * @return int
	 */
	private function counter() {
		$prop = new ReflectionProperty( Updater::class, 'updatesNumber' );
		$prop->setAccessible( true );
		return $prop->getValue();
	}

	/**
	 * A post with old modified dates.
	 *
	 * @param array $args Extra post arguments.
	 * @return int
	 */
	private function oldPost( $args = array() ) {
		global $wpdb;
		$post_id = self::factory()->post->create( array_merge( array( 'post_content' => 'before' ), $args ) );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => '2001-02-03 04:05:06',
				'post_modified_gmt' => '2001-02-03 03:05:06',
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );
		return $post_id;
	}

	public function test_update_post_replaces_the_content_and_keeps_the_modified_dates() {
		$post_id = $this->oldPost();
		$fired   = array();
		$hook    = function ( $id ) use ( &$fired ) {
			$fired[] = $id;
		};
		add_action( 'post_updated', $hook );
		$this->added_hooks[] = array( 'post_updated', $hook );
		$before              = $this->counter();

		$result = ( new Updater() )->updatePost( $post_id, 'after' );

		$post = get_post( $post_id );
		$this->assertTrue( $result );
		$this->assertSame( 'after', $post->post_content );
		$this->assertSame( '2001-02-03 04:05:06', $post->post_modified );
		$this->assertSame( '2001-02-03 03:05:06', $post->post_modified_gmt );
		$this->assertSame( array( $post_id ), $fired, 'Post hooks run' );
		$this->assertSame( $before + 1, $this->counter() );
	}

	public function test_update_post_keeps_backslashes_of_unslashed_content() {
		$post_id = $this->oldPost();

		( new Updater() )->updatePost( $post_id, 'path C:\\uploads\\a.jpg and {"u":"a\\/b"}' );

		$this->assertSame( 'path C:\\uploads\\a.jpg and {"u":"a\\/b"}', get_post( $post_id )->post_content );
	}

	public function test_update_post_returns_false_for_a_missing_post() {
		$before  = $this->counter();
		$missing = self::factory()->post->create() + 1000;
		$this->assertNull( get_post( $missing ), 'Sentinel: the post does not exist.' );

		$this->assertFalse( ( new Updater() )->updatePost( $missing, 'x' ) );
		$this->assertSame( $before, $this->counter() );
	}

	public function test_update_post_returns_false_when_wp_update_post_fails() {
		$post_id = $this->oldPost();
		$before  = $this->counter();
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$this->added_hooks[] = array( 'wp_insert_post_empty_content', '__return_true' );

		$result = ( new Updater() )->updatePost( $post_id, 'after' );

		$this->assertFalse( $result, 'wp_update_post() returned a WP_Error' );
		$this->assertSame( 'before', get_post( $post_id )->post_content );
		$this->assertSame( '2001-02-03 04:05:06', get_post( $post_id )->post_modified );
		$this->assertSame( $before, $this->counter() );
	}

	public function test_counter_is_shared_by_all_instances() {
		$first  = $this->oldPost();
		$second = $this->oldPost();
		$before = $this->counter();

		( new Updater() )->updatePost( $first, 'one' );
		( new Updater() )->updatePost( $second, 'two' );

		$this->assertSame( $before + 2, $this->counter() );
	}
}
