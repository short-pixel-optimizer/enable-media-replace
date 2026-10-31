<?php
/**
 * Cross-plugin compatibility: Advanced Custom Fields (free).
 *
 * Runs with the REAL advanced-custom-fields plugin active (bin/test.sh
 * --compat downloads + activates it). EMR has no ACF integration of its
 * own: an ACF image field stores the attachment ID (so it follows a rename
 * through the id), while URL, text, WYSIWYG and group sub-fields store the
 * URL in postmeta and are rewritten by the bundled replacer's generic
 * postmeta pass. ACF's own `_<field>` meta rows hold the field key and must
 * stay as they are.
 *
 *   - A rename (MODE_SEARCHREPLACE): every field that held the old URL
 *     reads the new one, through get_field() and in the raw postmeta; the
 *     image fields resolve to the new URL through the attachment.
 *   - A same-name replace (MODE_REPLACE) with other dimensions: a text
 *     field holding a size URL whose file name changed is rewritten; the
 *     main URL stays.
 *
 * The field group is registered as a local group in set_up() and removed in
 * tear_down(); the values are written with update_field() and roll back
 * with the test transaction.
 *
 * @package Enable_Media_Replace
 */

require_once dirname( __DIR__ ) . '/Integration/ReplaceFlowHelpers.php';
require_once __DIR__ . '/CompatReplacerModules.php';

class CompatAcfTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;
	use EMR_CompatReplacerModules;

	const GROUP = 'group_emr_compat_acf';

	public function set_up() {
		if ( ! function_exists( 'acf_add_local_field_group' ) || ! function_exists( 'update_field' ) ) {
			$this->markTestSkipped( 'Advanced Custom Fields is not loaded — run via bin/test.sh --compat.' );
		}
		parent::set_up();
		$this->flowSetUp();
		$this->snapshotModuleSingletons();
		acf_add_local_field_group(
			array(
				'key'      => self::GROUP,
				'title'    => 'EMR compat',
				'fields'   => array(
					array( 'key' => 'field_emr_image', 'name' => 'emr_image', 'label' => 'Image (url)', 'type' => 'image', 'return_format' => 'url' ),
					array( 'key' => 'field_emr_image_array', 'name' => 'emr_image_array', 'label' => 'Image (array)', 'type' => 'image', 'return_format' => 'array' ),
					array( 'key' => 'field_emr_url', 'name' => 'emr_url', 'label' => 'URL', 'type' => 'url' ),
					array( 'key' => 'field_emr_text', 'name' => 'emr_text', 'label' => 'Text', 'type' => 'text' ),
					array( 'key' => 'field_emr_html', 'name' => 'emr_html', 'label' => 'HTML', 'type' => 'wysiwyg' ),
					array(
						'key'        => 'field_emr_group',
						'name'       => 'emr_group',
						'label'      => 'Group',
						'type'       => 'group',
						'sub_fields' => array(
							array( 'key' => 'field_emr_group_image', 'name' => 'image', 'label' => 'Image', 'type' => 'image', 'return_format' => 'url' ),
							array( 'key' => 'field_emr_group_note', 'name' => 'note', 'label' => 'Note', 'type' => 'text' ),
						),
					),
				),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
			)
		);
	}

	public function tear_down() {
		if ( function_exists( 'acf_remove_local_field_group' ) ) {
			acf_remove_local_field_group( self::GROUP );
		}
		$this->restoreModuleSingletons();
		$this->flowTearDown();
		parent::tear_down();
	}

	/**
	 * A 400x300 JPEG attachment (sizes: thumbnail 150x150, medium 300x225).
	 *
	 * @return array( id, main url, medium url )
	 */
	private function image() {
		$id = $this->uploadJpeg( 'emr-acf-' . $this->token() . '.jpg', 400, 300 );
		return array( $id, wp_get_attachment_url( $id ), $this->sizeUrl( $id, 'medium' ) );
	}

	/** A post with every field of the group filled from the attachment. */
	private function postWithFields( $id, $url, $medium ) {
		$post = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		update_field( 'field_emr_image', $id, $post );
		update_field( 'field_emr_image_array', $id, $post );
		update_field( 'field_emr_url', $url, $post );
		update_field( 'field_emr_text', 'main ' . $url . ' medium ' . $medium, $post );
		update_field( 'field_emr_html', '<p><img src="' . $medium . '" alt="" /> <a href="' . $url . '">file</a></p>', $post );
		update_field( 'field_emr_group', array( 'image' => $id, 'note' => 'see ' . $url ), $post );
		return $post;
	}

	public function test_acf_loads_alongside_emr() {
		$this->assertTrue( function_exists( 'acf_get_field_groups' ) );
		$group = acf_get_field_group( self::GROUP );
		$this->assertIsArray( $group, 'Sentinel: the local group is registered.' );
		$this->assertCount( 6, acf_get_fields( self::GROUP ) );
	}

	public function test_fields_store_the_id_or_the_url_in_postmeta() {
		list( $id, $url, $medium ) = $this->image();
		$post = $this->postWithFields( $id, $url, $medium );

		$this->assertSame( (string) $id, get_post_meta( $post, 'emr_image', true ), 'An image field stores the attachment id.' );
		$this->assertSame( 'field_emr_image', get_post_meta( $post, '_emr_image', true ), 'ACF keeps the field key in a _ row.' );
		$this->assertSame( $url, get_post_meta( $post, 'emr_url', true ) );
		$this->assertSame( (string) $id, get_post_meta( $post, 'emr_group_image', true ), 'A group sub-field is a flat meta row.' );
		$this->assertSame( $url, get_field( 'emr_image', $post ), 'Sentinel: the url format resolves through the attachment.' );
	}

	public function test_rename_updates_every_field_that_held_the_url_and_the_image_fields_follow_the_id() {
		list( $id, $url, $medium ) = $this->image();
		$post = $this->postWithFields( $id, $url, $medium );
		$name = 'emr-acf-renamed-' . $this->token() . '.jpg';

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$new_url    = wp_get_attachment_url( $id );
		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertStringEndsWith( '/' . $name, $new_url, 'Sentinel: the attachment was renamed.' );
		$this->assertNotSame( $url, $new_url );

		// Image fields: the id did not change, so the formatted value follows.
		$this->assertSame( $new_url, get_field( 'emr_image', $post ) );
		$array = get_field( 'emr_image_array', $post );
		$this->assertSame( $new_url, $array['url'] );
		$this->assertSame( $new_medium, $array['sizes']['medium'], 'The array format reads the regenerated sizes.' );
		$this->assertSame( (string) $id, get_post_meta( $post, 'emr_image', true ), 'The stored id is untouched.' );

		// URL-holding fields: rewritten in postmeta by the replacer.
		$this->assertSame( $new_url, get_field( 'emr_url', $post ) );
		$this->assertSame( 'main ' . $new_url . ' medium ' . $new_medium, get_post_meta( $post, 'emr_text', true ) );
		$this->assertSame( '<p><img src="' . $new_medium . '" alt="" /> <a href="' . $new_url . '">file</a></p>', get_post_meta( $post, 'emr_html', true ) );
		$group = get_field( 'emr_group', $post );
		$this->assertSame( $new_url, $group['image'] );
		$this->assertSame( 'see ' . $new_url, $group['note'] );

		// ACF's own bookkeeping rows are left alone.
		foreach ( array( 'emr_image', 'emr_image_array', 'emr_url', 'emr_text', 'emr_html', 'emr_group', 'emr_group_image', 'emr_group_note' ) as $field ) {
			$this->assertStringStartsWith( 'field_emr_', get_post_meta( $post, '_' . $field, true ), "_$field still holds the field key." );
		}
		$this->assertStringNotContainsString( basename( $url, '.jpg' ), wp_json_encode( get_post_meta( $post ) ), 'No meta row holds the old file name any more.' );
	}

	public function test_same_name_replace_rewrites_a_size_url_whose_name_changed_and_keeps_the_main_url() {
		list( $id, $url, $medium ) = $this->image();
		$post = $this->postWithFields( $id, $url, $medium );
		$this->assertStringEndsWith( '-300x225.jpg', $medium, 'Sentinel: medium of a 400x300 image.' );

		$this->replaceMode( $id, $this->tmpJpeg( 400, 400 ) );

		$new_medium = $this->sizeUrl( $id, 'medium' );
		$this->assertStringEndsWith( '-300x300.jpg', $new_medium, 'Sentinel: medium of the 400x400 replacement.' );
		$this->assertSame( $url, wp_get_attachment_url( $id ), 'The main URL does not change in replace mode.' );

		$this->assertSame( 'main ' . $url . ' medium ' . $new_medium, get_post_meta( $post, 'emr_text', true ) );
		$this->assertSame( '<p><img src="' . $new_medium . '" alt="" /> <a href="' . $url . '">file</a></p>', get_post_meta( $post, 'emr_html', true ) );
		$this->assertSame( $url, get_field( 'emr_url', $post ) );
		$this->assertSame( $url, get_field( 'emr_image', $post ) );
		$this->assertSame( $new_medium, get_field( 'emr_image_array', $post )['sizes']['medium'] );
	}
}
