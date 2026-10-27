<?php
/**
 * Multisite: EMR's stored state is per site, and so is its cleanup.
 *
 * Everything EMR stores goes through the per-site options API: the API key
 * (`emr_shortpixel_api_key`, ApiKeyManager), the last-used settings
 * (`enable_media_replace`), the remote-notice throttle (`emr_remote_notice`
 * transient, RemoteNoticeController) and the stored notices
 * (`EnableMediaReplace-notices`, the bundled NoticeController). Nothing is
 * a network (site) option, so each site of a network starts empty and
 * keeps its own values.
 *
 * Cleanup follows the same rule. InstallHelper::deactivatePlugin() deletes
 * the current site's `EnableMediaReplace-notices`, and
 * InstallHelper::unInstallPlugin() deletes the current site's
 * `enable_media_replace`, `emr_news` and `emr_url_cache`; neither visits
 * the other sites. WordPress runs a network-wide deactivation and the
 * uninstall once, on the main site, so on a network the subsites' rows
 * stay behind (the two pins below). What the uninstall leaves on every
 * site (the key, the transient) is the single-site suite's subject.
 *
 * The upsell box (views/upsell.php) is shown when the user can
 * `install_plugins`, which on a network only super admins can.
 *
 * NoticeController keeps the notices of the site it was built on in
 * static properties; the tests drop them at every switch, as a new request
 * on the other site would start empty.
 *
 * Snapshot/restore: the main site's EMR options (base class) and notices
 * option, the NoticeController statics, current user, super-admin grants,
 * subsite uploads (EMR_MultisiteHelpers).
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/MultisiteHelpers.php';

use EnableMediaReplace\ApiKeyManager;
use EnableMediaReplace\Controller\RemoteNoticeController;
use EnableMediaReplace\InstallHelper;
use EnableMediaReplace\Notices\NoticeController;

class MultisiteSiteStateTest extends EMR_IntegrationTestCase {

	use EMR_MultisiteHelpers;

	const NOTICES_OPTION = 'EnableMediaReplace-notices';
	const MAIN_KEY       = 'mainEFGHIJKLMNOPmain';
	const SUB_KEY        = 'subsEFGHIJKLMNOPsubs';

	/** @var array */
	private $savedNoticeStatics = array();

	/** @var array */
	private $savedNoticesOption = array();

	/** @var int */
	private $savedUser = 0;

	/** @var int[] */
	private $superAdmins = array();

	public function set_up() {
		$this->skipUnlessMultisite();
		parent::set_up();
		foreach ( array( 'instance', 'notices', 'newNotices' ) as $name ) {
			$this->savedNoticeStatics[ $name ] = $this->noticeStatic( $name )->getValue();
		}
		$missing                  = new stdClass();
		$value                    = get_option( self::NOTICES_OPTION, $missing );
		$this->savedNoticesOption = ( $value === $missing ) ? array() : array( $value );
		$this->savedUser          = get_current_user_id();
		$this->freshNoticeRequest();
	}

	public function tear_down() {
		$this->msTearDown();
		foreach ( $this->superAdmins as $user ) {
			revoke_super_admin( $user );
		}
		$this->superAdmins = array();
		if ( $this->savedNoticesOption ) {
			update_option( self::NOTICES_OPTION, $this->savedNoticesOption[0] );
		} else {
			delete_option( self::NOTICES_OPTION );
		}
		foreach ( $this->savedNoticeStatics as $name => $value ) {
			$this->noticeStatic( $name )->setValue( null, $value );
		}
		wp_set_current_user( $this->savedUser );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Plumbing.
	// ---------------------------------------------------------------------

	private function noticeStatic( $name ) {
		$prop = new ReflectionProperty( NoticeController::class, $name );
		$prop->setAccessible( true );
		return $prop;
	}

	/** Drops the in-memory notices, as a new PHP request starts. */
	private function freshNoticeRequest() {
		$this->noticeStatic( 'instance' )->setValue( null, null );
		$this->noticeStatic( 'notices' )->setValue( null, array() );
		$this->noticeStatic( 'newNotices' )->setValue( null, array() );
	}

	/** enterSite() plus an empty notice state. */
	private function enter( $blog ) {
		$this->enterSite( $blog );
		$this->freshNoticeRequest();
	}

	/** leaveSite() plus an empty notice state. */
	private function leave() {
		$this->leaveSite();
		$this->freshNoticeRequest();
	}

	/** Option rows that exist on the current site, read from the database. */
	private function existingOptions( array $names ) {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders) ORDER BY option_name", $names ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Seeds the options the uninstall routine deletes. */
	private function seedUninstallOptions( $marker ) {
		update_option( 'enable_media_replace', array( 'bg_type' => $marker ) );
		update_option( 'emr_news', $marker );
		update_option( 'emr_url_cache', array( 'x' => $marker ) );
		$this->assertSame( array( 'emr_news', 'emr_url_cache', 'enable_media_replace' ), $this->existingOptions( $this->uninstallOptions() ), 'Sentinel: seeded.' );
	}

	private function uninstallOptions() {
		return array( 'enable_media_replace', 'emr_news', 'emr_url_cache' );
	}

	/** Stores a persistent notice on the current site through the notices module. */
	private function storeNotice( $id ) {
		$notice = NoticeController::addWarning( 'Notice ' . $id );
		NoticeController::makePersistent( $notice, $id, HOUR_IN_SECONDS );
		$this->assertIsArray( get_option( self::NOTICES_OPTION ), 'Sentinel: the notice is stored.' );
	}

	/** Renders views/upsell.php for the current user. */
	private function renderUpsell() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		ob_start();
		include dirname( __DIR__, 2 ) . '/views/upsell.php';
		return (string) ob_get_clean();
	}

	// ---------------------------------------------------------------------
	// Per-site options and transients.
	// ---------------------------------------------------------------------

	public function test_the_api_key_is_stored_per_site() {
		$blog = $this->newSite();
		update_option( ApiKeyManager::OPTION_NAME, self::MAIN_KEY );
		$this->assertSame( self::MAIN_KEY, ApiKeyManager::getInstance()->getApiKey(), 'Sentinel: the main site has its key.' );

		$this->enter( $blog );
		$this->assertFalse( get_option( ApiKeyManager::OPTION_NAME ), 'The subsite starts without a key.' );
		$this->assertFalse( ApiKeyManager::getInstance()->hasApiKey() );
		$this->assertSame( '', ApiKeyManager::getInstance()->getApiKey() );
		update_option( ApiKeyManager::OPTION_NAME, self::SUB_KEY );
		$this->resetSingleton( 'EnableMediaReplace\ApiKeyManager' );
		$this->assertSame( self::SUB_KEY, ApiKeyManager::getInstance()->getApiKey() );
		ApiKeyManager::getInstance()->deleteKey();
		$this->assertFalse( get_option( ApiKeyManager::OPTION_NAME ) );
		$this->leave();

		$this->assertSame( self::MAIN_KEY, get_option( ApiKeyManager::OPTION_NAME ), 'The subsite delete leaves the main key.' );
		$this->assertSame( self::MAIN_KEY, ApiKeyManager::getInstance()->getApiKey() );
		$this->assertSame( array(), $this->existingNetworkOptions( ApiKeyManager::OPTION_NAME ), 'No network option is written.' );
	}

	/** Rows of the network options table (sitemeta) named $name. */
	private function existingNetworkOptions( $name ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key = %s", $name ) );
	}

	public function test_the_remote_notice_throttle_and_the_fetched_notices_are_per_site() {
		$blog = $this->newSite();
		$this->api->setKnob(
			'notices',
			array(
				array(
					'id'      => 'emr-ms-remote',
					'message' => 'Network notice',
					'type'    => 'warning',
				),
			)
		);

		RemoteNoticeController::getInstance();

		$this->assertCount( 1, $this->api->requestsTo( 'notices.php' ), 'The main site fetches.' );
		$this->assertSame( 'true', get_transient( 'emr_remote_notice' ) );
		$this->assertNotFalse( NoticeController::getInstance()->getNoticeByID( 'emr-ms-remote' ), 'Stored on the main site.' );

		$this->enter( $blog );
		$this->assertFalse( get_transient( 'emr_remote_notice' ), 'The subsite has no throttle yet.' );
		$this->assertFalse( get_option( self::NOTICES_OPTION ), 'The subsite has no stored notices yet.' );

		RemoteNoticeController::getInstance();

		$this->assertCount( 2, $this->api->requestsTo( 'notices.php' ), 'The subsite fetches on its own.' );
		$this->assertSame( 'true', get_transient( 'emr_remote_notice' ) );
		$this->assertNotFalse( NoticeController::getInstance()->getNoticeByID( 'emr-ms-remote' ), 'Stored on the subsite too.' );
		delete_transient( 'emr_remote_notice' );
		$this->leave();

		$this->assertSame( 'true', get_transient( 'emr_remote_notice' ), 'Deleting the subsite throttle leaves the main one.' );
	}

	// ---------------------------------------------------------------------
	// Deactivation.
	// ---------------------------------------------------------------------

	public function test_deactivation_on_a_subsite_clears_that_sites_notices_and_nothing_else() {
		$blog = $this->newSite();
		$this->storeNotice( 'emr-ms-main' );
		$this->enter( $blog );
		$this->storeNotice( 'emr-ms-sub' );
		update_option( 'enable_media_replace', array( 'bg_type' => 'solid' ) );
		update_option( ApiKeyManager::OPTION_NAME, self::SUB_KEY );

		InstallHelper::deactivatePlugin();

		$this->assertFalse( get_option( self::NOTICES_OPTION ), 'The subsite notices are cleared.' );
		$this->assertSame( array( 'bg_type' => 'solid' ), get_option( 'enable_media_replace' ), 'The settings stay.' );
		$this->assertSame( self::SUB_KEY, get_option( ApiKeyManager::OPTION_NAME ), 'The key stays.' );
		$this->leave();
		$this->assertIsArray( get_option( self::NOTICES_OPTION ), 'The main-site notices stay.' );
		$this->assertNotFalse( NoticeController::getInstance()->getNoticeByID( 'emr-ms-main' ) );
	}

	/**
	 * WordPress fires a network-wide deactivation once, on the main site
	 * (`deactivate_<basename>` with `$network_wide` true).
	 * InstallHelper::deactivatePlugin() ignores that argument and calls
	 * NoticeController::resetNotices(), which deletes the CURRENT site's
	 * `EnableMediaReplace-notices`, so every subsite keeps its stored and
	 * dismissed notices, and the "a dismissed notice shows again after
	 * reactivation" promise of its docblock holds on the main site only.
	 * The routine should loop over the network's sites when
	 * `$network_wide` is true.
	 *
	 * How to recognise the fix: the subsite's notices option is gone after
	 * the action, and the assertions on it fail.
	 */
	public function test_a_network_wide_deactivation_leaves_the_subsites_notices_pinned_for_deferred_fix() {
		$blog = $this->newSite();
		$this->storeNotice( 'emr-ms-main' );
		$this->enter( $blog );
		$this->storeNotice( 'emr-ms-sub' );
		$this->leave();
		$this->assertTrue( is_main_site(), 'Precondition: the main site, as for a network deactivation.' );

		do_action( 'deactivate_' . plugin_basename( EMR_ROOT_FILE ), true );

		$this->assertFalse( get_option( self::NOTICES_OPTION ), 'The main-site notices are cleared.' );
		$this->enter( $blog );
		$this->assertIsArray( get_option( self::NOTICES_OPTION ), 'Pinned: the subsite notices stay.' );
		$this->assertNotFalse( NoticeController::getInstance()->getNoticeByID( 'emr-ms-sub' ) );
	}

	// ---------------------------------------------------------------------
	// Uninstall.
	// ---------------------------------------------------------------------

	public function test_uninstall_deletes_the_three_options_of_the_site_it_runs_on_only() {
		$blog = $this->newSite();
		$this->seedUninstallOptions( 'main' );
		$this->enter( $blog );
		$this->seedUninstallOptions( 'sub' );

		InstallHelper::unInstallPlugin();

		$this->assertSame( array(), $this->existingOptions( $this->uninstallOptions() ), 'The subsite rows are deleted.' );
		$this->leave();
		$this->assertSame( array( 'emr_news', 'emr_url_cache', 'enable_media_replace' ), $this->existingOptions( $this->uninstallOptions() ), 'The main-site rows stay.' );
		$this->assertSame( array( 'bg_type' => 'main' ), get_option( 'enable_media_replace' ) );
	}

	/**
	 * WordPress uninstalls a plugin once, from the network admin, on the
	 * main site, through the callable stored in the main site's
	 * `uninstall_plugins` option. InstallHelper::unInstallPlugin() deletes
	 * `enable_media_replace`, `emr_news` and `emr_url_cache` of the current
	 * site only, so on a network the rows of every subsite outlive the
	 * plugin. The routine should delete the options on every site of the
	 * network (get_sites() and switch_to_blog()) when it runs on a
	 * multisite install.
	 *
	 * How to recognise the fix: the subsite rows are gone after the
	 * uninstall, and the assertion listing them fails.
	 */
	public function test_uninstall_on_a_network_leaves_every_subsites_options_behind_pinned_for_deferred_fix() {
		$blog = $this->newSite();
		$this->enter( $blog );
		$this->seedUninstallOptions( 'sub' );
		$this->leave();
		$this->seedUninstallOptions( 'main' );
		$file          = plugin_basename( EMR_ROOT_FILE );
		$uninstallable = (array) get_option( 'uninstall_plugins' );
		$this->assertArrayHasKey( $file, $uninstallable, 'Precondition: the callable is stored on the main site.' );

		add_action( "uninstall_{$file}", $uninstallable[ $file ] );
		do_action( "uninstall_{$file}" );

		$this->assertSame( array(), $this->existingOptions( $this->uninstallOptions() ), 'The main-site rows are deleted.' );
		$this->enter( $blog );
		$this->assertSame( array( 'emr_news', 'emr_url_cache', 'enable_media_replace' ), $this->existingOptions( $this->uninstallOptions() ), 'Pinned: the subsite rows stay.' );
		$this->assertSame( array( 'bg_type' => 'sub' ), get_option( 'enable_media_replace' ) );
	}

	// ---------------------------------------------------------------------
	// Upsell.
	// ---------------------------------------------------------------------

	public function test_the_upsell_box_renders_for_a_super_admin_and_not_for_a_subsite_administrator() {
		$blog  = $this->newSite();
		$super = self::factory()->user->create( array( 'role' => '' ) );
		grant_super_admin( $super );
		$this->superAdmins[] = $super;
		$this->enter( $blog );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin );
		$this->assertTrue( current_user_can( 'manage_options' ), 'Sentinel: an administrator of the subsite.' );
		$this->assertFalse( current_user_can( 'install_plugins' ) );
		$this->assertSame( '', $this->renderUpsell(), 'No upsell markup for a subsite administrator.' );

		wp_set_current_user( $super );
		$this->assertTrue( current_user_can( 'install_plugins' ) );
		$html = $this->renderUpsell();
		$this->assertStringContainsString( "class='upsell-wrapper'", $html );
		$this->assertStringContainsString( "id='upsell-nonce'", $html );
		$this->assertStringContainsString( "id='upsell-nonce-activate'", $html );
	}
}
