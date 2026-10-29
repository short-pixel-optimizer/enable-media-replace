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

class InstallHelperTest extends WP_UnitTestCase {

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
