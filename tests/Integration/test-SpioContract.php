<?php
/**
 * Integration tests: the EMR side of the contract other plugins build on,
 * ShortPixel Image Optimizer first.
 *
 * SPIO's tests/Integration/test-EMRIntegration.php fires the two EMR hooks
 * itself (EMR is not installed there): `wp_handle_replace` with
 * `array('post_id' => $id)` to clear its optimisation data before the swap,
 * and `enable-media-replace-upload-done` with ($target, $source, $post_id)
 * to re-queue the image after it. This file checks that a real replace,
 * driven like UploadViewController::load() drives it, fires them with that
 * shape and at the documented moments, and that the other hooks run()
 * and setupSource() expose (`wp_handle_upload` with 'sideload',
 * `emr/converter/prevent-offload(-off)`, `emr_after_remove_current`,
 * `emr_unique_filename`, `emr/replace/original_image_path`,
 * `emr_unfiltered_get_attached_file`) behave as documented. It also covers
 * the readme's `emr/feature/background` filter on the media list row
 * actions and the edit-screen meta box.
 *
 * @package Enable_Media_Replace
 */

require_once __DIR__ . '/ReplaceFlowHelpers.php';

use EnableMediaReplace\Controller\ReplaceController;
use EnableMediaReplace\FileSystem\Model\File\FileModel;

class SpioContractTest extends EMR_IntegrationTestCase {

	use EMR_ReplaceFlowHelpers;

	/** @var array|null The plugin's feature flags before the test. */
	private $savedFeatures;

	public function set_up() {
		parent::set_up();
		$this->flowSetUp();
		$this->savedFeatures = $this->featuresProperty()->getValue( emr() );
	}

	public function tear_down() {
		$this->featuresProperty()->setValue( emr(), $this->savedFeatures );
		$this->flowTearDown();
		parent::tear_down();
	}

	private function featuresProperty() {
		$prop = new ReflectionProperty( get_class( emr() ), 'features' );
		$prop->setAccessible( true );
		return $prop;
	}

	/** A 400x300 JPEG attachment; returns ( id, path, url ). */
	private function image() {
		$id = $this->uploadJpeg( 'emr-spio-' . $this->token() . '.jpg', 400, 300 );
		return array( $id, get_attached_file( $id ), wp_get_attachment_url( $id ) );
	}

	/** Events recorded under $name. */
	private function eventsNamed( $name ) {
		return array_values(
			array_filter(
				$this->events,
				function ( $event ) use ( $name ) {
					return $event[0] === $name;
				}
			)
		);
	}

	// ---------------------------------------------------------------------
	// wp_handle_replace.
	// ---------------------------------------------------------------------

	public function test_wp_handle_replace_fires_first_with_the_post_id_while_the_old_files_are_on_disk() {
		list( $id, $path ) = $this->image();
		$old_md5           = md5_file( $path );
		$old_sizes         = $this->sizePaths( $id );
		$seen              = array();
		$this->addHook(
			'wp_handle_replace',
			function ( $args ) use ( &$seen, $id, $path, $old_sizes ) {
				$seen[] = array(
					'args'     => $args,
					'md5'      => md5_file( $path ),
					'sizes'    => array_map( 'file_exists', $old_sizes ),
					'meta'     => wp_get_attachment_metadata( $id )['width'],
					'attached' => get_attached_file( $id ),
				);
			}
		);
		$this->addHook( 'wp_handle_replace', $this->record( 'wp_handle_replace' ) );
		$this->addHook( 'emr_after_remove_current', $this->record( 'after_remove' ) );
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ) );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 600, 400 ), 'emr-spio-new-' . $this->token() . '.jpg' );

		$this->assertCount( 1, $seen, 'Fired once.' );
		$this->assertSame( array( 'post_id' => $id ), $seen[0]['args'] );
		$this->assertIsInt( $seen[0]['args']['post_id'] );
		$this->assertSame( $old_md5, $seen[0]['md5'], 'The old bytes are still on disk.' );
		$this->assertSame( array( 'medium' => true, 'thumbnail' => true ), $seen[0]['sizes'], 'The old sizes are still on disk.' );
		$this->assertSame( 400, $seen[0]['meta'], 'The old metadata is still stored.' );
		$this->assertSame( $path, $seen[0]['attached'] );
		$this->assertSame( array( 'wp_handle_replace', 'after_remove', 'upload_done' ), $this->eventNames() );
	}

	public function test_no_contract_hook_fires_when_setup_params_refuses_the_replace() {
		list( $id ) = $this->image();
		$uploads    = wp_upload_dir();
		$folder     = 'emr-spio-taken-' . $this->token();
		$this->flowDirs[] = $uploads['basedir'] . '/' . $folder;
		wp_mkdir_p( $uploads['basedir'] . '/' . $folder );
		$this->makeJpeg( $uploads['basedir'] . '/' . $folder . '/taken.jpg', 10, 10 );
		$this->addHook( 'wp_handle_replace', $this->record( 'wp_handle_replace' ) );
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ) );

		$controller = new ReplaceController( $id );
		$result     = $controller->setupParams( $this->flowParams( $id, $this->tmpJpeg( 50, 50 ), 'taken.jpg', array( 'replace_type' => 'replace_and_search', 'new_location' => true, 'location_dir' => $folder ) ) );

		$this->assertFalse( $result );
		$this->assertSame( ReplaceController::ERROR_TARGET_EXISTS, $controller->returnLastError() );
		$this->assertSame( array(), $this->events, 'SPIO keeps its data: nothing was replaced.' );
	}

	// ---------------------------------------------------------------------
	// enable-media-replace-upload-done.
	// ---------------------------------------------------------------------

	public function test_upload_done_in_replace_mode_passes_the_same_full_url_twice_and_the_post_id() {
		list( $id, , $url ) = $this->image();
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ), 10, 3 );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$this->assertCount( 1, $this->events );
		list( $target, $source, $post_id ) = $this->events[0][1];
		$this->assertSame( $url, $target );
		$this->assertSame( $url, $source, 'Source equals target in replace mode.' );
		$this->assertStringStartsWith( 'http://', $target, 'A full URL, not a path.' );
		$this->assertSame( $id, $post_id );
		$this->assertIsInt( $post_id );
	}

	public function test_upload_done_for_a_rename_passes_the_new_and_the_old_url() {
		list( $id, , $url ) = $this->image();
		$name               = 'emr-spio-renamed-' . $this->token() . '.jpg';
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ), 10, 3 );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		list( $target, $source, $post_id ) = $this->events[0][1];
		$this->assertSame( wp_get_attachment_url( $id ), $target );
		$this->assertStringEndsWith( '/' . $name, $target );
		$this->assertSame( $url, $source );
		$this->assertNotSame( $source, $target );
		$this->assertSame( $id, $post_id );
	}

	public function test_upload_done_fires_last_with_everything_already_persisted() {
		list( $id, , $url ) = $this->image();
		$post               = $this->makePost( '<img src="' . $url . '" />' );
		$name               = 'emr-spio-done-' . $this->token() . '.jpg';
		$seen               = array();
		$this->addHook( 'emr/cache/flush', $this->record( 'flush' ) );
		$this->addHook(
			'enable-media-replace-upload-done',
			function ( $target, $source, $post_id ) use ( &$seen, $post ) {
				$this->events[] = array( 'upload_done', array() );
				$meta           = wp_get_attachment_metadata( $post_id );
				$seen           = array(
					'width'         => $meta['width'],
					'meta_file'     => $meta['file'],
					'attached_meta' => get_post_meta( $post_id, '_wp_attached_file', true ),
					'attached_file' => get_attached_file( $post_id ),
					'content'       => get_post( $post )->post_content,
					'guid'          => get_post( $post_id )->guid,
					'title'         => get_post( $post_id )->post_title,
					'target'        => $target,
				);
			},
			10,
			3
		);

		$this->searchReplaceMode( $id, $this->tmpJpeg( 640, 480 ), $name );

		$this->assertSame( array( 'flush', 'upload_done' ), $this->eventNames(), 'The cache is flushed before upload-done.' );
		$relative = dirname( _wp_relative_upload_path( get_attached_file( $id ) ) ) . '/' . $name;
		$this->assertSame( 640, $seen['width'], 'The new metadata is persisted.' );
		$this->assertSame( $relative, $seen['meta_file'] );
		$this->assertSame( $relative, $seen['attached_meta'], 'SPIO reading _wp_attached_file sees the new path.' );
		$this->assertFileExists( $seen['attached_file'] );
		$this->assertSame( '<img src="' . $seen['target'] . '" />', $seen['content'], 'References are already rewritten.' );
		$this->assertSame( $seen['target'], $seen['guid'] );
		$this->assertSame( pathinfo( $name, PATHINFO_FILENAME ), $seen['title'] );
	}

	public function test_upload_done_for_a_scaled_image_passes_the_original_urls() {
		$id           = $this->uploadJpeg( 'emr-spio-big-' . $this->token() . '.jpg', 2600, 200 );
		$original_url = wp_get_original_image_url( $id );
		$this->assertNotSame( $original_url, wp_get_attachment_url( $id ), 'Sentinel: the attached file is -scaled.' );
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ), 10, 3 );

		$this->replaceMode( $id, $this->tmpJpeg( 2800, 220 ) );

		list( $target, $source ) = $this->events[0][1];
		$this->assertSame( $original_url, $source, 'The source is the original, not the -scaled URL.' );
		$this->assertSame( $original_url, $target );
		$this->assertStringNotContainsString( '-scaled', $target );
	}

	public function test_upload_done_fires_even_when_doreplace_skips_the_rewrite() {
		list( $id ) = $this->image();
		$this->addHook( 'emr/replace/doreplace', '__return_false' );
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ), 10, 3 );

		$this->replaceMode( $id, $this->tmpJpeg( 400, 300 ) );

		$this->assertSame( array( 'upload_done' ), $this->eventNames() );
	}

	// ---------------------------------------------------------------------
	// The order of the hooks run() fires.
	// ---------------------------------------------------------------------

	public function test_run_fires_its_hooks_in_the_documented_order() {
		list( $id ) = $this->image();
		$hooks      = array(
			'wp_handle_replace'                 => 1,
			'emr_after_remove_current'          => 5,
			'wp_handle_upload'                  => 2,
			'emr/converter/prevent-offload'     => 1,
			'wp_generate_attachment_metadata'   => 3,
			'emr/converter/prevent-offload-off' => 1,
			'wp_update_attachment_metadata'     => 2,
			'emr/replace/doreplace'             => 1,
			'emr/replacer/replace_urls'         => 3,
			'emr/cache/flush'                   => 1,
			'enable-media-replace-upload-done'  => 3,
		);
		foreach ( $hooks as $hook => $args ) {
			$this->addHook( $hook, $this->record( $hook ), 10, $args );
		}

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		// Consecutive repeats collapsed: core saves the partial metadata
		// several times while it creates the sizes.
		$names     = $this->eventNames();
		$collapsed = array();
		foreach ( $names as $name ) {
			if ( end( $collapsed ) !== $name ) {
				$collapsed[] = $name;
			}
		}
		$this->assertSame(
			array(
				'wp_handle_replace',
				'emr_after_remove_current',
				'wp_handle_upload',
				'emr/converter/prevent-offload',
				'wp_update_attachment_metadata',
				'wp_generate_attachment_metadata',
				'emr/converter/prevent-offload-off',
				'wp_update_attachment_metadata',
				'emr/replace/doreplace',
				'emr/replacer/replace_urls',
				'emr/cache/flush',
				'enable-media-replace-upload-done',
			),
			$collapsed,
			'Core\'s progressive metadata saves happen inside the prevent-offload bracket; EMR\'s final save comes after it.'
		);
		$this->assertGreaterThan( 1, count( array_keys( $names, 'wp_update_attachment_metadata', true ) ) );
	}

	public function test_prevent_offload_actions_bracket_the_metadata_generation_with_the_post_id() {
		list( $id, $path ) = $this->image();
		$this->addHook( 'emr/converter/prevent-offload', $this->record( 'prevent' ) );
		$this->addHook( 'wp_generate_attachment_metadata', $this->record( 'generate' ), 10, 3 );
		$this->addHook( 'emr/converter/prevent-offload-off', $this->record( 'prevent_off' ) );

		$this->replaceMode( $id, $this->tmpJpeg( 600, 400 ) );

		$this->assertSame( array( 'prevent', 'generate', 'prevent_off' ), $this->eventNames() );
		$this->assertSame( array( $id ), $this->events[0][1] );
		$this->assertSame( array( $id ), $this->events[2][1] );
		list( $metadata, $attachment_id, $context ) = $this->events[1][1];
		$this->assertSame( $id, $attachment_id );
		$this->assertSame( 'create', $context );
		$this->assertSame( 600, $metadata['width'], 'The metadata is generated from the new file.' );
		$this->assertSame( _wp_relative_upload_path( $path ), $metadata['file'] );
	}

	// ---------------------------------------------------------------------
	// wp_handle_upload.
	// ---------------------------------------------------------------------

	public function test_wp_handle_upload_is_applied_with_sideload_and_the_new_file_url_and_type() {
		list( $id, $path ) = $this->image();
		$name              = 'emr-spio-png-' . $this->token() . '.png';
		$this->addHook( 'wp_handle_upload', $this->record( 'wp_handle_upload' ), 10, 2 );

		$this->searchReplaceMode( $id, $this->tmpUpload( 'codeispoetry.png' ), $name );

		$this->assertCount( 1, $this->events );
		list( $upload, $context ) = $this->events[0][1];
		$this->assertSame( 'sideload', $context );
		$this->assertSame(
			array(
				'file' => dirname( $path ) . '/' . $name,
				'url'  => wp_get_attachment_url( $id ),
				'type' => 'image/png',
			),
			$upload
		);
		$this->assertStringStartsWith( 'http://', $upload['url'] );
	}

	public function test_wp_handle_upload_returning_another_file_moves_the_attachment_there() {
		list( $id, $path, $url ) = $this->image();
		$post                    = $this->makePost( '<img src="' . $url . '" />' );
		$name                    = 'emr-spio-orig-' . $this->token() . '.jpg';
		$converted               = dirname( $path ) . '/emr-spio-converted-' . $this->token() . '.png';
		$this->flowFiles[]       = dirname( $path ) . '/' . $name;
		$this->addHook(
			'wp_handle_upload',
			function ( $upload ) use ( $converted ) {
				copy( $this->fixturePath( 'codeispoetry.png' ), $converted );
				$upload['file'] = $converted;
				return $upload;
			}
		);
		$this->addHook( 'enable-media-replace-upload-done', $this->record( 'upload_done' ), 10, 3 );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), $name );

		$this->assertSame( $converted, get_attached_file( $id ) );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 190, $meta['width'], 'The metadata describes the filtered file.' );
		$this->assertSame( _wp_relative_upload_path( $converted ), $meta['file'] );
		$converted_url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( wp_basename( $converted ), $converted_url );
		$this->assertSame( $converted_url, $this->events[0][1][0], 'upload-done receives the filtered file\'s URL.' );
		$this->assertSame( '<img src="' . $converted_url . '" />', $this->rawContent( $post ), 'References follow the filtered file.' );
		$this->assertSame( $converted_url, get_post( $id )->guid );
		$this->assertFileExists( dirname( $path ) . '/' . $name, 'The file EMR copied is left for the filter to deal with.' );
	}

	// ---------------------------------------------------------------------
	// emr_after_remove_current.
	// ---------------------------------------------------------------------

	public function test_after_remove_current_fires_with_five_arguments_once_the_old_files_are_gone() {
		list( $id, $path ) = $this->image();
		$old_sizes         = $this->sizePaths( $id );
		$name              = 'emr-spio-removed-' . $this->token() . '.jpg';
		$target_path       = dirname( $path ) . '/' . $name;
		$seen              = array();
		$this->addHook(
			'emr_after_remove_current',
			function ( $post_id, $meta, $backup_sizes, $source, $target ) use ( &$seen, $path, $old_sizes, $target_path ) {
				$seen = array(
					'post_id'      => $post_id,
					'meta'         => $meta,
					'backup_sizes' => $backup_sizes,
					'source'       => $source,
					'target'       => $target,
					'main_exists'  => file_exists( $path ),
					'sizes_exist'  => array_filter( array_map( 'file_exists', $old_sizes ) ),
					'target_there' => file_exists( $target_path ),
					'arg_count'    => func_num_args(),
				);
			},
			10,
			5
		);
		$meta_before = wp_get_attachment_metadata( $id );

		$this->searchReplaceMode( $id, $this->tmpJpeg( 600, 400 ), $name );

		$this->assertSame( 5, $seen['arg_count'] );
		$this->assertSame( $id, $seen['post_id'] );
		$this->assertSame( $meta_before, $seen['meta'], 'The old metadata.' );
		$this->assertSame( '', $seen['backup_sizes'], 'No backup sizes for an unedited image.' );
		$this->assertInstanceOf( FileModel::class, $seen['source'] );
		$this->assertSame( $path, $seen['source']->getFullPath() );
		$this->assertInstanceOf( FileModel::class, $seen['target'] );
		$this->assertSame( $target_path, $seen['target']->getFullPath() );
		$this->assertFalse( $seen['main_exists'], 'The old main file is gone.' );
		$this->assertSame( array(), $seen['sizes_exist'], 'The old sizes are gone.' );
		$this->assertFalse( $seen['target_there'], 'The new file is not copied yet.' );
	}

	// ---------------------------------------------------------------------
	// emr_unique_filename.
	// ---------------------------------------------------------------------

	public function test_emr_unique_filename_can_rename_the_target() {
		list( $id, $path, $url ) = $this->image();
		$post                    = $this->makePost( '<img src="' . $url . '" />' );
		$chosen                  = 'emr-spio-chosen-' . $this->token() . '.jpg';
		$this->addHook( 'emr_unique_filename', $this->record( 'unique' ), 10, 3 );
		$this->addHook(
			'emr_unique_filename',
			function () use ( $chosen ) {
				return $chosen;
			},
			20
		);

		$this->searchReplaceMode( $id, $this->tmpJpeg( 400, 300 ), 'Browser Name.jpg' );

		$this->assertSame( array( array( 'unique', array( 'Browser-Name.jpg', trailingslashit( dirname( $path ) ), $id ) ) ), $this->events );
		$this->assertSame( dirname( $path ) . '/' . $chosen, get_attached_file( $id ) );
		$this->assertSame( '<img src="' . wp_get_attachment_url( $id ) . '" />', $this->rawContent( $post ) );
		$this->assertStringEndsWith( '/' . $chosen, wp_get_attachment_url( $id ) );
		$this->assertSame( 'Browser Name', get_post( $id )->post_title, 'The title still comes from the upload name.' );
	}

	// ---------------------------------------------------------------------
	// setupSource() filters.
	// ---------------------------------------------------------------------

	public function test_setup_source_applies_the_unfiltered_and_the_original_image_path_filters() {
		$id       = $this->uploadJpeg( 'emr-spio-src-' . $this->token() . '.jpg', 2600, 200 );
		$original = wp_get_original_image_path( $id );
		$this->addHook( 'emr_unfiltered_get_attached_file', $this->record( 'unfiltered' ) );
		$this->addHook( 'emr/replace/original_image_path', $this->record( 'original_path' ), 10, 2 );

		$controller = new ReplaceController( $id );

		$this->assertSame( array( 'unfiltered', 'unfiltered', 'original_path' ), $this->eventNames(), 'Applied in the constructor.' );
		$this->assertSame( array( true ), $this->events[0][1] );
		$this->assertSame( array( $original, $id ), $this->events[2][1] );
		$this->assertSame( $original, $controller->getSourceFile()->getFullPath(), 'The source is the original, not -scaled.' );
	}

	public function test_original_image_path_filter_chooses_the_file_a_replace_overwrites() {
		list( $id, $path ) = $this->image();
		$elsewhere         = dirname( $path ) . '/emr-spio-local-copy-' . $this->token() . '.jpg';
		copy( $path, $elsewhere );
		$this->flowFiles[] = $elsewhere;
		$untouched         = md5_file( $path );
		$this->addHook(
			'emr/replace/original_image_path',
			function () use ( $elsewhere ) {
				return $elsewhere;
			}
		);
		$tmp = $this->tmpJpeg( 600, 400 );
		$md5 = md5_file( $tmp );

		$this->replaceMode( $id, $tmp );

		$this->assertSame( $md5, md5_file( $elsewhere ), 'The filtered path receives the new bytes.' );
		$this->assertSame( $elsewhere, get_attached_file( $id ) );
		$this->assertSame( 600, wp_get_attachment_metadata( $id )['width'] );
		$this->assertFileDoesNotExist( $path, 'The attached file is still removed by removeCurrent().' );
		$this->assertNotSame( $untouched, $md5, 'Sentinel: the bytes differ.' );
	}

	public function test_unfiltered_false_lets_the_get_attached_file_filter_choose_the_source() {
		list( $id, $path ) = $this->image();
		$translated        = dirname( $path ) . '/emr-spio-translated-' . $this->token() . '.jpg';
		$this->addHook( 'emr_unfiltered_get_attached_file', '__return_false' );
		$this->addHook(
			'get_attached_file',
			function ( $file, $attachment_id ) use ( $id, $translated ) {
				return $attachment_id === $id ? $translated : $file;
			},
			10,
			2
		);

		$controller = new ReplaceController( $id );

		$this->assertSame( $translated, $controller->getSourceFile()->getFullPath() );
	}

	public function test_get_attached_file_filter_is_ignored_by_default() {
		list( $id, $path ) = $this->image();
		$this->addHook(
			'get_attached_file',
			function () {
				return '/somewhere/else.jpg';
			}
		);

		$controller = new ReplaceController( $id );

		$this->assertSame( $path, $controller->getSourceFile()->getFullPath() );
	}

	// ---------------------------------------------------------------------
	// emr/feature/background.
	// ---------------------------------------------------------------------

	public function test_background_feature_filter_off_removes_the_row_action_and_the_meta_box_button() {
		list( $id ) = $this->image();
		$post       = get_post( $id );
		emr()->adminInit();
		$on_actions = emr()->add_media_action( array(), $post );
		ob_start();
		emr()->replace_meta_box( $post );
		$on_box = ob_get_clean();
		$this->assertArrayHasKey( 'remove_background', $on_actions, 'Sentinel: offered by default for a JPEG.' );
		$this->assertStringContainsString( 'Remove background', $on_box, 'Sentinel: offered by default for a JPEG.' );

		$this->addHook( 'emr/feature/background', '__return_false' );
		emr()->adminInit();
		$actions = emr()->add_media_action( array( 'edit' => '<a>Edit</a>' ), $post );
		ob_start();
		emr()->replace_meta_box( $post );
		$box = ob_get_clean();

		$this->assertSame( array( 'edit', 'media_replace' ), array_keys( $actions ) );
		$this->assertStringNotContainsString( 'emr_prepare_remove', implode( '', $actions ) );
		$this->assertStringContainsString( 'Upload a new file', $box );
		$this->assertStringNotContainsString( 'Remove background', $box );
	}
}
