<?php
/**
 * Multisite: who may replace what on a subsite, and the environment flags.
 *
 * On a network a user's role is per site (the `wp_N_capabilities` user
 * meta), and a super admin holds every capability on every site without a
 * role there. EMR's gates read the current site's capabilities only:
 * - EnableMediaReplacePlugin::checkImagePermission() (EMR_CAPABILITY false,
 *   the default) is current_user_can( 'edit_post', $id ) on the current
 *   site's attachment;
 * - runtime() registers the admin UI only for users with `upload_files` on
 *   the current site;
 * - the media row actions and the attachment meta box follow
 *   checkImagePermission(), and remove background is hidden from
 *   author-restricted users (no `edit_others_posts` on the current site).
 *
 * Cast: a super admin with no role on the subsite, an administrator of the
 * subsite, an author of the subsite, and an administrator of ANOTHER
 * subsite, who has no role on this one. Each is created while the site it
 * belongs to is current, so its role lands on that site only.
 *
 * EnvHelper: the flags of a subsite request and of a network-admin screen.
 *
 * Snapshot/restore: current user, super-admin grants, the plugin's feature
 * flags, `$wp_meta_boxes`, `$current_screen`, hooks (WP_UnitTestCase),
 * subsite uploads (EMR_MultisiteHelpers), singletons (base class).
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/MultisiteHelpers.php';

use EnableMediaReplace\Helper\EnvHelper;

class MultisitePermissionsTest extends EMR_IntegrationTestCase {

	use EMR_MultisiteHelpers;

	/** @var int */
	private $savedUser = 0;

	/** @var int[] Users granted super admin by the test. */
	private $superAdmins = array();

	/** @var array|null */
	private $savedFeatures;

	/** @var array */
	private $savedGlobals = array();

	/** @var int Subsite under test. */
	private $blog = 0;

	/** @var int Another subsite. */
	private $otherBlog = 0;

	/** @var array<string,int> Users by role name. */
	private $users = array();

	public function set_up() {
		$this->skipUnlessMultisite();
		parent::set_up();
		$this->savedUser     = get_current_user_id();
		$this->savedFeatures = $this->featuresProperty()->getValue( emr() );
		foreach ( array( 'current_screen', 'wp_meta_boxes' ) as $name ) {
			$this->savedGlobals[ $name ] = array_key_exists( $name, $GLOBALS ) ? array( $GLOBALS[ $name ] ) : null;
		}
		$this->featuresProperty()->setValue( emr(), array() );

		$this->blog      = $this->newSite();
		$this->otherBlog = $this->newSite();

		$this->users['super'] = self::factory()->user->create( array( 'role' => '' ) );
		grant_super_admin( $this->users['super'] );
		$this->superAdmins[] = $this->users['super'];

		switch_to_blog( $this->blog );
		$this->users['admin']  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->users['author'] = self::factory()->user->create( array( 'role' => 'author' ) );
		restore_current_blog();

		switch_to_blog( $this->otherBlog );
		$this->users['stranger'] = self::factory()->user->create( array( 'role' => 'administrator' ) );
		restore_current_blog();
	}

	public function tear_down() {
		$this->msTearDown();
		foreach ( $this->superAdmins as $user ) {
			revoke_super_admin( $user );
		}
		$this->superAdmins = array();
		$this->featuresProperty()->setValue( emr(), $this->savedFeatures );
		foreach ( $this->savedGlobals as $name => $saved ) {
			if ( null === $saved ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = $saved[0];
			}
		}
		wp_set_current_user( $this->savedUser );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Plumbing.
	// ---------------------------------------------------------------------

	private function featuresProperty() {
		$prop = new ReflectionProperty( get_class( emr() ), 'features' );
		$prop->setAccessible( true );
		return $prop;
	}

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
		$post = get_post( $id );
		$this->assertSame( (string) $this->users[ $role ], (string) $post->post_author, 'Sentinel: the owner is set.' );
		return $post;
	}

	// ---------------------------------------------------------------------
	// checkImagePermission().
	// ---------------------------------------------------------------------

	public function test_a_super_admin_without_a_role_on_the_subsite_may_replace_its_attachments() {
		$this->onSubsiteAs( 'super' );
		$post = $this->attachmentOf( 'admin' );
		$this->assertFalse( is_user_member_of_blog( $this->users['super'], $this->blog ), 'Precondition: no role on the subsite.' );
		$this->assertTrue( is_super_admin() );

		$this->assertTrue( emr()->checkImagePermission( $post ) );
		$this->assertFalse( emr()->uiHelper()->is_author_restricted(), 'Not author restricted.' );
	}

	public function test_a_subsite_administrator_may_replace_there_and_not_on_the_main_site() {
		$this->onSubsiteAs( 'admin' );
		$post = $this->attachmentOf( 'author' );
		$this->assertFalse( is_super_admin(), 'Precondition: a plain site administrator.' );

		$this->assertTrue( emr()->checkImagePermission( $post ), 'Any attachment of their own subsite.' );
		$this->leaveSite();

		$main = get_post( $this->uploadFixture( 'test-image.jpg' ) );
		$this->assertFalse( current_user_can( 'upload_files' ), 'Precondition: no role on the main site.' );
		$this->assertFalse( emr()->checkImagePermission( $main ), 'No access to a main-site attachment.' );
	}

	public function test_a_subsite_author_may_replace_their_own_attachment_only() {
		$this->onSubsiteAs( 'admin' );
		$others = $this->attachmentOf( 'admin' );
		$own    = $this->attachmentOf( 'author' );
		wp_set_current_user( $this->users['author'] );

		$this->assertTrue( emr()->checkImagePermission( $own ) );
		$this->assertFalse( emr()->checkImagePermission( $others ) );
		$this->assertTrue( emr()->uiHelper()->is_author_restricted(), 'An author is author restricted.' );
	}

	public function test_a_member_of_another_subsite_is_refused_even_though_they_administer_their_own() {
		$this->enterSite( $this->otherBlog );
		wp_set_current_user( $this->users['stranger'] );
		$home = $this->attachmentOf( 'stranger' );
		$this->assertTrue( emr()->checkImagePermission( $home ), 'Sentinel: an administrator on their own subsite.' );
		$this->leaveSite();

		$this->onSubsiteAs( 'stranger' );
		$post = $this->attachmentOf( 'admin' );
		$this->assertFalse( is_user_member_of_blog( $this->users['stranger'], $this->blog ), 'Precondition: no role on this subsite.' );

		$this->assertFalse( emr()->checkImagePermission( $post ) );
		$this->assertFalse( current_user_can( 'upload_files' ) );
	}

	// ---------------------------------------------------------------------
	// runtime(): the admin UI per site.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider provideRuntimeCast
	 */
	public function test_runtime_registers_the_admin_hooks_only_for_users_with_upload_files_on_the_current_site( $role, $expected ) {
		$this->onSubsiteAs( $role );
		remove_action( 'admin_menu', array( emr(), 'menu' ) );
		remove_filter( 'media_row_actions', array( emr(), 'add_media_action' ), 10 );
		remove_action( 'add_meta_boxes_attachment', array( emr(), 'add_meta_boxes' ), 10 );
		remove_filter( 'attachment_fields_to_edit', array( emr(), 'attachment_editor' ), 10 );
		$this->assertSame( $expected, current_user_can( 'upload_files' ), 'Sentinel: upload_files on the subsite.' );

		emr()->runtime();

		$this->assertSame( $expected, false !== has_action( 'admin_menu', array( emr(), 'menu' ) ), 'admin_menu.' );
		$this->assertSame( $expected, false !== has_filter( 'media_row_actions', array( emr(), 'add_media_action' ) ), 'media_row_actions.' );
		$this->assertSame( $expected, false !== has_action( 'add_meta_boxes_attachment', array( emr(), 'add_meta_boxes' ) ), 'add_meta_boxes_attachment.' );
		$this->assertSame( $expected, false !== has_filter( 'attachment_fields_to_edit', array( emr(), 'attachment_editor' ) ), 'attachment_fields_to_edit.' );
		$this->assertTrue( shortcode_exists( 'file_modified' ), 'The shortcode is registered for everyone.' );
	}

	public function provideRuntimeCast() {
		return array(
			'super admin'           => array( 'super', true ),
			'subsite administrator' => array( 'admin', true ),
			'subsite author'        => array( 'author', true ),
			'member of another site' => array( 'stranger', false ),
		);
	}

	// ---------------------------------------------------------------------
	// Row action and meta box.
	// ---------------------------------------------------------------------

	public function test_the_row_actions_on_a_subsite_follow_the_permission_and_the_author_restriction() {
		$this->onSubsiteAs( 'admin' );
		$admins = $this->attachmentOf( 'admin' );
		$own    = $this->attachmentOf( 'author' );

		$expected = array(
			array( 'super', $admins, array( 'media_replace', 'remove_background', 'upscale_image' ) ),
			array( 'admin', $own, array( 'media_replace', 'remove_background', 'upscale_image' ) ),
			array( 'author', $own, array( 'media_replace' ) ),
			array( 'author', $admins, array() ),
			array( 'stranger', $admins, array() ),
		);
		foreach ( $expected as $case ) {
			list( $role, $post, $keys ) = $case;
			wp_set_current_user( $this->users[ $role ] );

			$actions = emr()->add_media_action( array( 'view' => 'View' ), $post );

			$this->assertSame( array_merge( array( 'view' ), $keys ), array_keys( $actions ), "Row actions for the $role." );
			if ( $keys ) {
				$this->assertStringContainsString( 'attachment_id=' . $post->ID, $actions['media_replace'] );
				$this->assertStringContainsString( get_admin_url( $this->blog ), $actions['media_replace'], 'The link points at the subsite admin.' );
			}
		}
	}

	public function test_the_replace_meta_box_on_a_subsite_is_added_only_for_permitted_users() {
		$this->onSubsiteAs( 'admin' );
		$post  = $this->attachmentOf( 'admin' );
		$cases = array(
			'super'    => true,
			'admin'    => true,
			'author'   => false,
			'stranger' => false,
		);
		foreach ( $cases as $role => $expected ) {
			$GLOBALS['wp_meta_boxes'] = array();
			wp_set_current_user( $this->users[ $role ] );

			emr()->add_meta_boxes( $post );

			$this->assertSame( $expected, isset( $GLOBALS['wp_meta_boxes']['attachment']['side']['low']['emr-replace-box'] ), "Meta box for the $role." );
		}

		wp_set_current_user( $this->users['admin'] );
		ob_start();
		emr()->replace_meta_box( $post );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Upload a new file', $html );
		$this->assertStringContainsString( 'Remove background', $html );
		$this->assertStringContainsString( get_admin_url( $this->blog ), html_entity_decode( $html ), 'The buttons point at the subsite admin.' );
	}

	// ---------------------------------------------------------------------
	// EnvHelper.
	// ---------------------------------------------------------------------

	public function test_env_helper_built_on_a_subsite_request_reports_a_subsite_of_a_network() {
		$this->onSubsiteAs( 'admin' );

		$env = EnvHelper::getInstance();

		$this->assertSame( $env, emr()->env(), 'emr()->env() is the same singleton.' );
		$this->assertTrue( $env->is_multisite );
		$this->assertFalse( $env->is_mainsite );
		$this->assertFalse( $env->is_network_admin );
		$this->assertFalse( $env->is_ajaxcall );
		$this->assertFalse( $env->is_croncall );
	}

	public function test_env_helper_built_on_a_network_admin_screen_reports_the_network_admin() {
		wp_set_current_user( $this->users['super'] );
		set_current_screen( 'dashboard-network' );
		$this->assertTrue( is_network_admin(), 'Precondition: a network admin screen.' );

		$env = EnvHelper::getInstance();

		$this->assertTrue( $env->is_network_admin );
		$this->assertTrue( $env->is_multisite );
		$this->assertTrue( $env->is_mainsite, 'The network admin runs on the main site.' );
	}
}
