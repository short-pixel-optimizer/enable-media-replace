<?php
/**
 * Tests for EnableMediaReplace\InstallHelper (classes/installHelper.php).
 *
 * unInstallPlugin() and deactivatePlugin() are called directly; the plugin is
 * never really deactivated or uninstalled. Options, transients and post meta
 * live in the test database and are rolled back after each test. The notice
 * controller of the bundled notices module keeps its notices in static
 * properties as well as in the `EnableMediaReplace-notices` option, so those
 * statics are reset by reflection around every test.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\InstallHelper;
use EnableMediaReplace\Notices\NoticeController;
use EnableMediaReplace\ShortQ\ShortQ;

class InstallHelperTest extends WP_UnitTestCase {

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

	/** Option holding the stored admin notices of the bundled notices module. */
	const NOTICE_OPTION = 'EnableMediaReplace-notices';

	public function set_up() {
		parent::set_up();
		$this->reset_notice_controller();
	}

	public function tear_down() {
		$this->reset_notice_controller();
		parent::tear_down();
	}

	/** Clears the static state of the notices module. */
	private function reset_notice_controller() {
		$values = array(
			'instance'   => null,
			'notices'    => array(),
			'newNotices' => array(),
		);
		foreach ( $values as $name => $value ) {
			$prop = new ReflectionProperty( NoticeController::class, $name );
			$prop->setAccessible( true );
			$prop->setValue( null, $value );
		}
	}

	public function test_uninstall_deletes_the_three_plugin_options() {
		update_option( 'enable_media_replace', array( 'replace_type' => 'replace_and_search' ) );
		update_option( 'emr_news', 'seen' );
		update_option( 'emr_url_cache', array( 'a' => 'b' ) );
		$this->assertIsArray( get_option( 'enable_media_replace' ), 'Precondition: options stored.' );
		$this->assertSame( 'seen', get_option( 'emr_news' ) );
		$this->assertIsArray( get_option( 'emr_url_cache' ) );

		InstallHelper::unInstallPlugin();

		$this->assertFalse( get_option( 'enable_media_replace' ) );
		$this->assertFalse( get_option( 'emr_news' ) );
		$this->assertFalse( get_option( 'emr_url_cache' ) );
	}

	public function test_uninstall_without_stored_options_returns_null_and_leaves_none() {
		delete_option( 'enable_media_replace' );
		delete_option( 'emr_news' );
		delete_option( 'emr_url_cache' );

		$this->assertNull( InstallHelper::unInstallPlugin() );
		$this->assertFalse( get_option( 'enable_media_replace' ) );
	}

	/**
	 * InstallHelper::unInstallPlugin() deletes three options but not
	 * `emr_shortpixel_api_key`, the remove-background API key stored in plain
	 * text. Uninstalling the plugin leaves the key in wp_options. The correct
	 * behaviour is to delete the option on uninstall.
	 *
	 * How to recognise the fix: get_option( 'emr_shortpixel_api_key' ) returns
	 * false after unInstallPlugin() and the assertSame on the key fails.
	 */
	public function test_uninstall_leaves_the_api_key_option_pinned_for_deferred_fix() {
		update_option( 'emr_shortpixel_api_key', 'emr-test-key-4711' );
		$this->assertSame( 'emr-test-key-4711', get_option( 'emr_shortpixel_api_key' ), 'Precondition: key stored.' );

		InstallHelper::unInstallPlugin();

		$this->assertSame( 'emr-test-key-4711', get_option( 'emr_shortpixel_api_key' ) );
	}

	/**
	 * InstallHelper::unInstallPlugin() does not delete the `emr_remote_notice`
	 * transient written by the remote notice controller, so it survives the
	 * uninstall until it expires. The correct behaviour is a
	 * delete_transient( 'emr_remote_notice' ) on uninstall.
	 *
	 * How to recognise the fix: get_transient( 'emr_remote_notice' ) returns
	 * false after unInstallPlugin() and the assertSame fails.
	 */
	public function test_uninstall_leaves_the_remote_notice_transient_pinned_for_deferred_fix() {
		set_transient( 'emr_remote_notice', 'emr-test-remote-notice', HOUR_IN_SECONDS );
		$this->assertSame( 'emr-test-remote-notice', get_transient( 'emr_remote_notice' ), 'Precondition: transient stored.' );

		InstallHelper::unInstallPlugin();

		$this->assertSame( 'emr-test-remote-notice', get_transient( 'emr_remote_notice' ) );
	}

	/**
	 * InstallHelper::unInstallPlugin() does not delete the
	 * `EnableMediaReplace-notices` option; only deactivatePlugin() does. When
	 * WordPress uninstalls without a prior deactivation hook run (for example
	 * after the plugin directory was removed), the stored notices stay in
	 * wp_options. The correct behaviour is to delete the option on uninstall
	 * as well.
	 *
	 * How to recognise the fix: the option is false after unInstallPlugin()
	 * and the assertIsArray fails.
	 */
	public function test_uninstall_leaves_the_notices_option_pinned_for_deferred_fix() {
		update_option( self::NOTICE_OPTION, array( 'emr-test-notice' ) );
		$this->assertIsArray( get_option( self::NOTICE_OPTION ), 'Precondition: notices stored.' );

		InstallHelper::unInstallPlugin();

		$this->assertIsArray( get_option( self::NOTICE_OPTION ) );
		$this->assertSame( array( 'emr-test-notice' ), get_option( self::NOTICE_OPTION ) );
	}

	/**
	 * InstallHelper::unInstallPlugin() leaves the `_emr_replace_author` post
	 * meta that the replace flow writes on every replaced attachment. After
	 * uninstall the meta rows stay in wp_postmeta. The correct behaviour is to
	 * delete the meta key for all posts on uninstall (or document the choice
	 * to keep it).
	 *
	 * How to recognise the fix: get_post_meta() returns '' after
	 * unInstallPlugin() and the assertSame on the user id fails.
	 */
	public function test_uninstall_leaves_the_replace_author_meta_pinned_for_deferred_fix() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'emr-author-meta.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $attachment_id, '_emr_replace_author', 4242 );
		$this->assertSame( '4242', get_post_meta( $attachment_id, '_emr_replace_author', true ), 'Precondition: meta stored.' );

		InstallHelper::unInstallPlugin();

		$this->assertSame( '4242', get_post_meta( $attachment_id, '_emr_replace_author', true ) );
	}

	/*
	 * The queue (bundled ShortQ, slug 'emr', queue 'bulk').
	 */

	/** The shared queue table name. */
	private function queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'shortpixel_queue';
	}

	private function queue_table_exists() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->queue_table() ) ) === $this->queue_table();
	}

	private function queue_rows( $slug ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->queue_table()} WHERE plugin_slug = %s", $slug ) );
	}

	public function test_queue_slug_and_name_are_short_enough_for_the_table_columns_and_distinct_from_spio_and_rta() {
		$this->assertSame( 'emr', InstallHelper::QUEUE_SLUG );
		$this->assertSame( 'bulk', InstallHelper::QUEUE_NAME );
		$this->assertLessThanOrEqual( 30, strlen( InstallHelper::QUEUE_SLUG ), 'plugin_slug is VARCHAR(30).' );
		$this->assertLessThanOrEqual( 30, strlen( InstallHelper::QUEUE_NAME ), 'queue_name is VARCHAR(30).' );
		$this->assertNotSame( 'spio', strtolower( InstallHelper::QUEUE_SLUG ), 'Slug comparison follows the (case-insensitive) table collation.' );
		$this->assertNotSame( 'rta', strtolower( InstallHelper::QUEUE_SLUG ) );
	}

	/**
	 * Constructing the queue creates the table (an implicit commit), so the
	 * rows this test writes are removed explicitly in the finally block.
	 */
	public function test_uninstall_removes_emr_queue_rows_and_status_option_and_keeps_other_plugins_rows() {
		global $wpdb;
		$q = ( new ShortQ( InstallHelper::QUEUE_SLUG ) )->getQueue( InstallHelper::QUEUE_NAME );
		try {
			$q->enqueue( array( array( 'id' => 4711, 'value' => 'emr' ), array( 'id' => 4712, 'value' => 'emr' ) ) );
			$wpdb->insert( $this->queue_table(), array(
				'queue_name'  => 'media',
				'plugin_slug' => 'SPIOTEST',
				'status'      => ShortQ::QSTATUS_WAITING,
				'list_order'  => 10,
				'item_id'     => 4711,
				'item_count'  => 1,
				'value'       => 'spio',
				'tries'       => 0,
			) );
			$this->assertSame( 2, $this->queue_rows( 'emr' ), 'Precondition: EMR rows stored.' );
			$this->assertIsArray( get_option( 'shortqwp_emr' ), 'Precondition: status option stored.' );

			InstallHelper::unInstallPlugin();

			$this->assertSame( 0, $this->queue_rows( 'emr' ) );
			$this->assertFalse( get_option( 'shortqwp_emr' ) );
			$this->assertTrue( $this->queue_table_exists(), 'The table is kept while another plugin has rows in it.' );
			$this->assertSame( 1, $this->queue_rows( 'SPIOTEST' ) );
		} finally {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->queue_table()} WHERE plugin_slug IN (%s, %s)", 'emr', 'SPIOTEST' ) );
			delete_option( 'shortqwp_emr' );
		}
	}

	/**
	 * WP_UnitTestCase rewrites CREATE TABLE / DROP TABLE into their
	 * TEMPORARY forms through the `query` filter; the shared queue table is
	 * a real table (SPIO's install creates it outside a test transaction in
	 * the integration suites), so DROP TEMPORARY TABLE would leave it in
	 * place. Both rewrites are suspended for this test. DROP TABLE is an
	 * implicit commit, which also commits the DELETE of every row (test
	 * database only); the table is recreated in the finally block so later
	 * tests find the environment unchanged.
	 */
	public function test_uninstall_drops_the_queue_table_when_no_plugin_has_rows_left() {
		global $wpdb;
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		$q = ( new ShortQ( InstallHelper::QUEUE_SLUG ) )->getQueue( InstallHelper::QUEUE_NAME );
		try {
			$wpdb->query( "DELETE FROM {$this->queue_table()}" ); // Leftover rows of other suites' runs in the test database.
			$q->enqueue( array( array( 'id' => 4713, 'value' => 'emr' ) ) );
			$this->assertSame( 1, $this->queue_rows( 'emr' ), 'Precondition: one EMR row.' );
			$this->assertTrue( $this->queue_table_exists() );

			InstallHelper::unInstallPlugin();

			$this->assertFalse( $this->queue_table_exists() );
			$this->assertFalse( get_option( 'shortqwp_emr' ) );
		} finally {
			( new ShortQ( 'emr' ) )->getQueue( 'bulk' )->install();
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->queue_table()} WHERE plugin_slug = %s", 'emr' ) );
			delete_option( 'shortqwp_emr' );
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_deactivate_deletes_the_stored_notices() {
		NoticeController::addNormal( 'EMR test notice for deactivation' );
		$stored = get_option( self::NOTICE_OPTION );
		$this->assertIsArray( $stored, 'Precondition: the notice is stored.' );
		$this->assertCount( 1, $stored );

		InstallHelper::deactivatePlugin();

		$this->assertFalse( get_option( self::NOTICE_OPTION ) );
	}

	public function test_deactivate_clears_a_dismissed_persistent_notice() {
		$notice = NoticeController::addNormal( 'EMR test persistent notice' );
		NoticeController::makePersistent( $notice, 'EMRTEST', YEAR_IN_SECONDS );
		$notice->dismiss();
		NoticeController::getInstance()->update();
		$stored = get_option( self::NOTICE_OPTION );
		$this->assertIsArray( $stored, 'Precondition: the dismissed notice is stored.' );
		$this->assertSame( 'EMRTEST', $stored[0]->getID() );

		InstallHelper::deactivatePlugin();

		$this->assertFalse( get_option( self::NOTICE_OPTION ) );
	}

	public function test_deactivate_without_stored_notices_returns_null() {
		delete_option( self::NOTICE_OPTION );

		$this->assertNull( InstallHelper::deactivatePlugin() );
		$this->assertFalse( get_option( self::NOTICE_OPTION ) );
	}
}
