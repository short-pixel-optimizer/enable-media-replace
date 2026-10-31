<?php
/**
 * Multisite: the EMR admin-ajax endpoints on a subsite.
 *
 * The requests go through WP_Ajax_UnitTestCase's dispatch (EMR_AjaxTestCase)
 * while a subsite is current, as admin-ajax.php of that subsite would run
 * them. MockEmrApi answers api.shortpixel.com; every other host is refused.
 *
 * - `emr_remove_background` has no capability check of its own: the nonce,
 *   then Api::request() asks checkImagePermission() about the subsite's
 *   attachment, and the request uses the subsite's API key option, the
 *   subsite's uploads URL and the subsite's options and transients.
 * - `emr_save_api_key` / `emr_delete_api_key` require `manage_options` on
 *   the current site and write the current site's option only.
 * - `emr_plugin_install` requires `install_plugins` and
 *   `emr_plugin_activate` requires `activate_plugins`. On a network both
 *   are mapped to super admins (activation only while the network does not
 *   enable the Plugins menu for site administrators), so a subsite
 *   administrator is refused. The super-admin install path itself is the
 *   single-site suite's (tests/Integration/test-AjaxEndpoints.php).
 *
 * Cast: a super admin with no role on the subsite, the subsite's
 * administrator and author, and an administrator of another subsite.
 *
 * Snapshot/restore: $_POST/$_GET/$_REQUEST, current user, super-admin
 * grants, the EMR options of the main site (base class); the subsite's
 * rows live in temporary tables rolled back with the test, its uploads
 * folder is removed (EMR_MultisiteHelpers).
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/MultisiteHelpers.php';

use EnableMediaReplace\ApiKeyManager;

class MultisiteAjaxTest extends EMR_AjaxTestCase {

	use EMR_MultisiteHelpers;

	const MAIN_KEY = 'mainEFGHIJKLMNOPmain';
	const SUB_KEY  = 'subsEFGHIJKLMNOPsubs';

	/** @var array */
	private $savedRequest = array();

	/** @var int */
	private $savedUser = 0;

	/** @var int[] */
	private $superAdmins = array();

	/** @var int */
	private $blog = 0;

	/** @var array<string,int> */
	private $users = array();

	public function set_up() {
		$this->skipUnlessMultisite();
		parent::set_up();
		$this->savedRequest = array( $_POST, $_GET, $_REQUEST );
		$this->savedUser    = get_current_user_id();
		$_POST              = array();
		$_GET               = array();
		$_REQUEST           = array();

		$this->blog = $this->newSite();
		$other      = $this->newSite();

		$this->users['super'] = self::factory()->user->create( array( 'role' => '' ) );
		grant_super_admin( $this->users['super'] );
		$this->superAdmins[] = $this->users['super'];

		switch_to_blog( $this->blog );
		$this->users['admin']  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->users['author'] = self::factory()->user->create( array( 'role' => 'author' ) );
		restore_current_blog();

		switch_to_blog( $other );
		$this->users['stranger'] = self::factory()->user->create( array( 'role' => 'administrator' ) );
		restore_current_blog();
	}

	public function tear_down() {
		$this->msTearDown();
		foreach ( $this->superAdmins as $user ) {
			revoke_super_admin( $user );
		}
		$this->superAdmins = array();
		list( $_POST, $_GET, $_REQUEST ) = $this->savedRequest;
		wp_set_current_user( $this->savedUser );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Plumbing.
	// ---------------------------------------------------------------------

	/** Switches to the subsite under test and logs $role in there. */
	private function onSubsiteAs( $role ) {
		$this->enterSite( $this->blog );
		wp_set_current_user( $this->users[ $role ] );
	}

	/** A JPEG attachment on the current subsite owned by $role. */
	private function attachmentOf( $role ) {
		$id = $this->subsiteUpload(
			function () {
				return $this->uploadFixture( 'test-image.jpg' );
			}
		);
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $this->users[ $role ],
			)
		);
		return $id;
	}

	/** Posts $post to $action; returns the decoded JSON object, or null. */
	private function post( $action, array $post ) {
		$_POST = $post;
		$_GET  = array();
		return $this->doAjax( $action );
	}

	private function removeBackground( $id ) {
		return $this->post(
			'emr_remove_background',
			array(
				'nonce'         => wp_create_nonce( 'emr_remove_background' ),
				'attachment_id' => (string) $id,
				'background'    => array( 'type' => 'transparent' ),
			)
		);
	}

	/** The same endpoint with an upscale factor instead of a background (the upscale preview). */
	private function upscale( $id, $factor = 2 ) {
		return $this->post(
			'emr_remove_background',
			array(
				'nonce'         => wp_create_nonce( 'emr_remove_background' ),
				'attachment_id' => (string) $id,
				'upscale'       => (string) $factor,
			)
		);
	}

	/** The option as stored on the main site, read without leaving the subsite's state behind. */
	private function mainOption( $name ) {
		switch_to_blog( get_main_site_id() );
		try {
			return get_option( $name );
		} finally {
			restore_current_blog();
		}
	}

	// ---------------------------------------------------------------------
	// emr_remove_background.
	// ---------------------------------------------------------------------

	public function test_remove_background_by_the_subsite_administrator_sends_the_subsite_url_and_stores_its_state_on_the_subsite() {
		$this->onSubsiteAs( 'admin' );
		$id = $this->attachmentOf( 'author' );

		$response = $this->removeBackground( $id );

		$this->assertIsObject( $response );
		$this->assertTrue( $response->success );
		$sent = $this->api->requestsTo( 'free-reducer.php' );
		$this->assertCount( 1, $sent, 'One free reducer call.' );
		$this->assertCount( 1, $this->api->getRequests(), 'Nothing else was called.' );
		$url = urldecode( $sent[0]['request']['urllist'][0] );
		$this->assertStringContainsString( '/sites/' . $this->blog . '/', $url, 'The subsite uploads URL is sent.' );
		$this->assertSame( wp_get_original_image_url( $id ), $response->url );
		$this->assertIsString( get_transient( 'emr_' . $response->key ), 'The result is kept in a subsite transient.' );
		$this->assertSame( 'transparent', get_option( 'enable_media_replace' )['bg_type'], 'The settings are saved on the subsite.' );

		$this->assertFalse( $this->mainOption( 'enable_media_replace' ), 'Nothing is saved on the main site.' );
		$this->assertFalse( $this->mainOption( '_transient_emr_' . $response->key ), 'The transient is not on the main site.' );
	}

	/**
	 * The upscale preview goes through the same endpoint with `upscale` =
	 * factor; on a subsite it must send the subsite's URL, keep its result
	 * transient there and, unlike a removal, store no background settings.
	 */
	public function test_upscale_by_the_subsite_administrator_sends_the_factor_and_the_subsite_url_and_keeps_its_state_there() {
		update_option( ApiKeyManager::OPTION_NAME, self::MAIN_KEY );
		$this->onSubsiteAs( 'admin' );
		$id = $this->attachmentOf( 'author' );
		$this->assertSame( 50, wp_get_attachment_metadata( $id )['width'], 'Sentinel: the fixture is 50 px wide, every factor fits.' );

		$response = $this->upscale( $id, 3 );

		$this->assertIsObject( $response );
		$this->assertTrue( $response->success, wp_json_encode( $response ) );
		$sent = $this->api->requestsTo( 'free-reducer.php' );
		$this->assertCount( 1, $sent, 'Without a subsite key the free endpoint is used, the main site key is ignored.' );
		$this->assertCount( 0, $this->api->requestsTo( 'api-status.php' ) );
		$this->assertSame( 3, (int) $sent[0]['request']['upscale'] );
		$this->assertArrayNotHasKey( 'bg_remove', $sent[0]['request'] );
		$url = urldecode( $sent[0]['request']['urllist'][0] );
		$this->assertStringContainsString( '/sites/' . $this->blog . '/', $url, 'The subsite uploads URL is sent.' );
		$this->assertIsString( get_transient( 'emr_' . $response->key ), 'The result is kept in a subsite transient.' );
		$this->assertFalse( get_option( 'enable_media_replace' ), 'An upscale stores no background settings on the subsite.' );

		$this->assertFalse( $this->mainOption( 'enable_media_replace' ), 'Nothing is saved on the main site.' );
		$this->assertFalse( $this->mainOption( '_transient_emr_' . $response->key ), 'The transient is not on the main site.' );

		update_option( ApiKeyManager::OPTION_NAME, self::SUB_KEY );
		$this->resetSingleton( 'EnableMediaReplace\ApiKeyManager' );
		$this->api->reset();

		$this->assertTrue( $this->upscale( $id, 2 )->success );
		$status = $this->api->requestsTo( 'api-status.php' );
		$keyed  = $this->api->requestsTo( '/reducer.php' );
		$this->assertCount( 1, $status, 'The subsite key is verified.' );
		$this->assertSame( self::SUB_KEY, $status[0]['request']['key'] );
		$this->assertCount( 1, $keyed );
		$this->assertSame( self::SUB_KEY, $keyed[0]['request']['key'] );
		$this->assertSame( 2, (int) $keyed[0]['request']['upscale'] );
		$this->assertSame( $id, (int) $keyed[0]['request']['item_id'] );
	}

	public function test_remove_background_on_a_subsite_ignores_the_main_site_key_and_uses_the_subsite_key() {
		update_option( ApiKeyManager::OPTION_NAME, self::MAIN_KEY );
		$this->onSubsiteAs( 'admin' );
		$id = $this->attachmentOf( 'admin' );
		$this->assertFalse( get_option( ApiKeyManager::OPTION_NAME ), 'Precondition: no key on the subsite.' );

		$this->assertTrue( $this->removeBackground( $id )->success );
		$this->assertCount( 1, $this->api->requestsTo( 'free-reducer.php' ), 'Without a subsite key the free endpoint is used.' );
		$this->assertCount( 0, $this->api->requestsTo( 'api-status.php' ) );

		update_option( ApiKeyManager::OPTION_NAME, self::SUB_KEY );
		$this->resetSingleton( 'EnableMediaReplace\ApiKeyManager' );
		$this->api->reset();

		$this->assertTrue( $this->removeBackground( $id )->success );
		$status = $this->api->requestsTo( 'api-status.php' );
		$keyed  = $this->api->requestsTo( '/reducer.php' );
		$this->assertCount( 1, $status, 'The subsite key is verified.' );
		$this->assertSame( self::SUB_KEY, $status[0]['request']['key'] );
		$this->assertCount( 1, $keyed );
		$this->assertSame( '/v2/reducer.php', $keyed[0]['path'], 'The keyed endpoint, not the free one.' );
		$this->assertSame( self::SUB_KEY, $keyed[0]['request']['key'] );
	}

	public function test_remove_background_by_a_super_admin_without_a_role_on_the_subsite_is_served() {
		$this->onSubsiteAs( 'super' );
		$id = $this->attachmentOf( 'admin' );

		$response = $this->removeBackground( $id );

		$this->assertIsObject( $response );
		$this->assertTrue( $response->success );
		$this->assertCount( 1, $this->api->requestsTo( 'free-reducer.php' ) );
	}

	/**
	 * @dataProvider provideRefusedRemovers
	 */
	public function test_remove_background_is_refused_without_rights_on_the_subsite_attachment( $role ) {
		$this->onSubsiteAs( 'admin' );
		$id = $this->attachmentOf( 'admin' );
		wp_set_current_user( $this->users[ $role ] );

		$response = $this->removeBackground( $id );

		$this->assertEquals(
			(object) array(
				'success' => false,
				'image'   => null,
				'message' => 'No permission for user',
			),
			$response
		);
		$this->assertSame( array(), $this->api->getRequests(), 'No API call.' );
		$this->assertFalse( get_option( 'enable_media_replace' ), 'Nothing saved.' );
	}

	public function provideRefusedRemovers() {
		return array(
			'author on another user\'s attachment' => array( 'author' ),
			'member of another subsite'            => array( 'stranger' ),
		);
	}

	// ---------------------------------------------------------------------
	// emr_save_api_key / emr_delete_api_key.
	// ---------------------------------------------------------------------

	public function test_save_api_key_by_the_subsite_administrator_stores_the_key_on_the_subsite_only() {
		update_option( ApiKeyManager::OPTION_NAME, self::MAIN_KEY );
		$this->onSubsiteAs( 'admin' );

		$response = $this->post(
			'emr_save_api_key',
			array(
				'nonce'   => wp_create_nonce( 'emr_save_api_key' ),
				'api_key' => self::SUB_KEY,
			)
		);

		$this->assertIsObject( $response );
		$this->assertTrue( $response->success );
		$this->assertSame( self::SUB_KEY, get_option( ApiKeyManager::OPTION_NAME ) );
		$status = $this->api->requestsTo( 'api-status.php' );
		$this->assertCount( 1, $status );
		$this->assertSame( get_site_url(), $status[0]['request']['DomainCheck'], 'The subsite URL is sent as the domain.' );
		$this->assertSame( self::MAIN_KEY, $this->mainOption( ApiKeyManager::OPTION_NAME ), 'The main-site key is untouched.' );
	}

	public function test_delete_api_key_by_the_subsite_administrator_deletes_the_subsite_key_only() {
		update_option( ApiKeyManager::OPTION_NAME, self::MAIN_KEY );
		$this->onSubsiteAs( 'admin' );
		update_option( ApiKeyManager::OPTION_NAME, self::SUB_KEY );

		$response = $this->post( 'emr_delete_api_key', array( 'nonce' => wp_create_nonce( 'emr_save_api_key' ) ) );

		$this->assertIsObject( $response );
		$this->assertTrue( $response->success );
		$this->assertFalse( get_option( ApiKeyManager::OPTION_NAME ), 'The subsite key is gone.' );
		$this->assertSame( self::MAIN_KEY, $this->mainOption( ApiKeyManager::OPTION_NAME ), 'The main-site key stays.' );
	}

	/**
	 * @dataProvider provideKeyStrangers
	 */
	public function test_the_key_endpoints_refuse_users_without_manage_options_on_the_subsite( $role ) {
		$this->onSubsiteAs( 'admin' );
		update_option( ApiKeyManager::OPTION_NAME, self::SUB_KEY );
		wp_set_current_user( $this->users[ $role ] );
		$this->assertFalse( current_user_can( 'manage_options' ), 'Precondition.' );

		$save   = $this->post(
			'emr_save_api_key',
			array(
				'nonce'   => wp_create_nonce( 'emr_save_api_key' ),
				'api_key' => self::MAIN_KEY,
			)
		);
		$delete = $this->post( 'emr_delete_api_key', array( 'nonce' => wp_create_nonce( 'emr_save_api_key' ) ) );

		foreach ( array( $save, $delete ) as $response ) {
			$this->assertIsObject( $response );
			$this->assertFalse( $response->success );
			$this->assertSame( 'You do not have permission to perform this action.', $response->data->message );
		}
		$this->assertSame( self::SUB_KEY, get_option( ApiKeyManager::OPTION_NAME ), 'The subsite key is unchanged.' );
		$this->assertSame( array(), $this->api->getRequests() );
	}

	public function provideKeyStrangers() {
		return array(
			'subsite author'            => array( 'author' ),
			'member of another subsite' => array( 'stranger' ),
		);
	}

	// ---------------------------------------------------------------------
	// emr_plugin_install / emr_plugin_activate.
	// ---------------------------------------------------------------------

	public function test_plugin_install_refuses_a_subsite_administrator_because_install_plugins_is_network_only() {
		$this->onSubsiteAs( 'admin' );
		$this->assertTrue( current_user_can( 'manage_options' ), 'Sentinel: an administrator of the subsite.' );
		$this->assertFalse( current_user_can( 'install_plugins' ), 'Precondition: install_plugins is for super admins on a network.' );
		$plugins_before = array_keys( get_plugins() );

		$response = $this->post(
			'emr_plugin_install',
			array(
				'nonce'  => wp_create_nonce( 'emr-plugin-install' ),
				'plugin' => 'spio',
			)
		);

		$this->assertEquals(
			(object) array(
				'result'  => false,
				'message' => 'Install permission issue',
			),
			$response
		);
		$this->assertSame( array(), $this->api->getRequests(), 'Nothing was downloaded.' );
		wp_clean_plugins_cache( false );
		$this->assertSame( $plugins_before, array_keys( get_plugins() ), 'No plugin was added.' );

		wp_set_current_user( $this->users['super'] );
		$this->assertTrue( current_user_can( 'install_plugins' ), 'Sentinel: a super admin holds the capability on the same subsite.' );
	}

	public function test_plugin_activate_refuses_a_subsite_administrator_while_the_network_keeps_the_plugins_menu_closed() {
		$this->onSubsiteAs( 'admin' );
		$menu = get_site_option( 'menu_items', array() );
		$this->assertTrue( empty( $menu['plugins'] ), 'Precondition: the network does not open the Plugins menu to site administrators.' );
		$this->assertFalse( current_user_can( 'activate_plugins' ) );
		$active = get_option( 'active_plugins' );

		$response = $this->post(
			'emr_plugin_activate',
			array(
				'nonce'  => wp_create_nonce( 'emr-plugin-activate' ),
				'plugin' => 'spio',
			)
		);

		$this->assertEquals( (object) array( 'result' => false ), $response );
		$this->assertSame( $active, get_option( 'active_plugins' ), 'Nothing was activated on the subsite.' );
	}
}
