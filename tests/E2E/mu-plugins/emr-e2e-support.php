<?php
/**
 * Plugin Name: EMR E2E: Test Support Endpoint
 * Description: Test-support mu-plugin for the browser E2E suite: a small REST API (namespace emr-e2e/v1) the Playwright side uses to reset state, seed the install, upload fixtures, inspect attachments and posts, and steer the mock APIs. Inert unless the EMR_E2E constant is true.
 *
 * Every route requires the X-EMR-E2E-TOKEN header to equal the
 * EMR_E2E_TOKEN constant (both defined in docker-compose.e2e.yml). The
 * routes work without pretty permalinks through
 *   /?rest_route=/emr-e2e/v1/<route>
 *
 * Routes:
 *   POST reset            delete every attachment (files included), post,
 *                         page and suite-created user; the EMR options
 *                         (enable_media_replace, emr_shortpixel_api_key,
 *                         the notices option), the emr_remote_notice and
 *                         remove-background result transients, the feature
 *                         overrides, the mock state, the stub plugins the
 *                         upsell installer installed, the year/month and
 *                         emr-e2e-* upload directories; then re-seed.
 *                         Answers the seed (see emr_e2e_apply_seed()).
 *   POST seed             re-apply the baseline (same answer)
 *   GET  seed             the current seed ids and URLs
 *   POST option           { name, value } update_option, or { name, delete: true }
 *   GET  option?name=     { name, exists, value }
 *   POST fixture          { name[, as, title, author] } upload tests/fixtures/<name>,
 *                         or { width, height[, format: jpg|png, as, title, author] }
 *                         a GD-drawn image, as an attachment with full metadata
 *   POST image            { width, height[, format, name] } the bytes of a
 *                         GD-drawn image (base64), for a browser upload; no
 *                         attachment is created
 *   POST post             { attachment_id[, size, title, status] } or
 *                         { content[, title, status] }: a published post
 *   GET  post/<id>        raw post_content, title, status
 *   GET  attachment/<id>  file, metadata, mime, dates, backup sizes
 *   POST key              { key } store the API key option, { key: null } delete it
 *   POST feature          { background?, upscale?, remote_notice? } force the
 *                         emr/feature/* filter outcome (true/false), null = default
 *   POST user             { role[, login] } a user with password "password"
 *   GET  mock             the mock knobs
 *   POST mock             merge knobs into the mock (see emr-e2e-mock-api.php)
 *   POST mock/reset       wipe mock knobs, counters, request log, stash
 *   GET  mock/requests    the mock's request log (?path= filters on the URL path)
 *
 * Besides the routes, the file keeps the install hermetic: no core, plugin
 * or theme update checks, no pings, no dashboard news widget.
 *
 * @package Enable_Media_Replace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'EMR_E2E' ) || ! EMR_E2E ) {
	return;
}

define( 'EMR_E2E_PLUGIN_DIR', WP_PLUGIN_DIR . '/enable-media-replace' );
define( 'EMR_E2E_SEED_OPTION', 'emr_e2e_seed' );
define( 'EMR_E2E_FEATURE_OPTION', 'emr_e2e_features' );
define( 'EMR_E2E_USER_META', 'emr_e2e_user' );

// -----------------------------------------------------------------------
// Hermetic install
// -----------------------------------------------------------------------

/**
 * Report "no core, plugin or theme updates", always.
 *
 * WordPress otherwise asks api.wordpress.org, and the answer changes with
 * every upstream release: a newer core adds the `.update-nag` notice to
 * every admin page and moves the layout. A fresh, empty transient also
 * means the request is never made.
 */
function emr_e2e_no_updates() {
	return (object) array(
		'last_checked'    => time(),
		'version_checked' => get_bloginfo( 'version' ),
		'updates'         => array(),
		'response'        => array(),
		'no_update'       => array(),
		'translations'    => array(),
		'checked'         => array(),
	);
}
add_filter( 'pre_site_transient_update_core', 'emr_e2e_no_updates' );
add_filter( 'pre_site_transient_update_plugins', 'emr_e2e_no_updates' );
add_filter( 'pre_site_transient_update_themes', 'emr_e2e_no_updates' );

// The admin_init update checks and the auto-updater never run either; the
// transients above already answer them, this removes the work.
remove_action( 'admin_init', '_maybe_update_core' );
remove_action( 'admin_init', '_maybe_update_plugins' );
remove_action( 'admin_init', '_maybe_update_themes' );
remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );

// No pings or enclosures: the site is private and offline.
remove_action( 'do_pings', 'do_all_pings', 10 );
add_filter( 'pre_option_ping_sites', '__return_empty_string' );

/** The dashboard news and events widget fetches remote feeds; drop it. */
function emr_e2e_dashboard_setup() {
	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
}
add_action( 'wp_dashboard_setup', 'emr_e2e_dashboard_setup', 99 );

/**
 * Feature overrides: the `feature` route stores the wanted outcome of
 * `emr/feature/background`, `emr/feature/upscale` and
 * `emr/feature/remote_notice`; these filters
 * apply it (late, so they win over any other callback).
 */
function emr_e2e_feature_background( $enabled ) {
	return emr_e2e_feature_value( 'background', $enabled );
}
function emr_e2e_feature_remote_notice( $enabled ) {
	return emr_e2e_feature_value( 'remote_notice', $enabled );
}
function emr_e2e_feature_upscale( $enabled ) {
	return emr_e2e_feature_value( 'upscale', $enabled );
}
function emr_e2e_feature_value( $name, $default ) {
	$features = (array) get_option( EMR_E2E_FEATURE_OPTION, array() );
	return array_key_exists( $name, $features ) ? (bool) $features[ $name ] : $default;
}
add_filter( 'emr/feature/background', 'emr_e2e_feature_background', 999 );
add_filter( 'emr/feature/upscale', 'emr_e2e_feature_upscale', 999 );
add_filter( 'emr/feature/remote_notice', 'emr_e2e_feature_remote_notice', 999 );

// -----------------------------------------------------------------------
// Seed
// -----------------------------------------------------------------------

/**
 * The baseline every test starts from (also run by WP-CLI during
 * provisioning, tests/E2E/provision/seed.php).
 *
 * EMR state: no `enable_media_replace` settings (the replace screen shows
 * its defaults), no API key, no feature overrides. Content: one JPEG
 * attachment `emr-seed.jpg` (800x600, so WordPress makes the thumbnail,
 * medium and medium_large sizes) and one published post whose content links
 * the full image and embeds its medium size, so a replace has references
 * to rewrite. A previous seed's attachment and post are removed first, so
 * the function is idempotent.
 *
 * @return array{attachment_id: int, attachment_url: string, attachment_file: string, post_id: int, post_url: string}
 */
function emr_e2e_apply_seed() {
	delete_option( 'enable_media_replace' );
	delete_option( 'emr_shortpixel_api_key' );
	delete_option( EMR_E2E_FEATURE_OPTION );

	$previous = (array) get_option( EMR_E2E_SEED_OPTION, array() );
	if ( ! empty( $previous['post_id'] ) ) {
		wp_delete_post( (int) $previous['post_id'], true );
	}
	if ( ! empty( $previous['attachment_id'] ) ) {
		wp_delete_attachment( (int) $previous['attachment_id'], true );
	}

	$attachment = emr_e2e_insert_attachment(
		'emr-seed.jpg',
		emr_e2e_draw_image( 800, 600, 'jpg' ),
		'EMR seed image'
	);
	if ( is_wp_error( $attachment ) ) {
		return array( 'error' => $attachment->get_error_message() );
	}

	$post = emr_e2e_insert_post(
		array(
			'attachment_id' => $attachment['id'],
			'title'         => 'EMR seed post',
		)
	);
	if ( is_wp_error( $post ) ) {
		return array( 'error' => $post->get_error_message() );
	}

	$seed = array(
		'attachment_id'   => (int) $attachment['id'],
		'attachment_url'  => $attachment['url'],
		'attachment_file' => $attachment['file'],
		'post_id'         => (int) $post['id'],
		'post_url'        => $post['url'],
	);
	update_option( EMR_E2E_SEED_OPTION, $seed, false );
	return $seed;
}

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

/**
 * Bytes of a deterministic GD image: a light background, a dark rectangle
 * and the dimensions written in, so two sizes never look alike.
 *
 * @param int    $width  Pixels.
 * @param int    $height Pixels.
 * @param string $format 'jpg' or 'png'.
 * @return string
 */
function emr_e2e_draw_image( $width, $height, $format = 'jpg' ) {
	$width  = max( 1, min( 4000, (int) $width ) );
	$height = max( 1, min( 4000, (int) $height ) );
	$img    = imagecreatetruecolor( $width, $height );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 230, 240, 250 ) );
	imagefilledrectangle( $img, (int) ( $width / 4 ), (int) ( $height / 4 ), (int) ( $width * 3 / 4 ), (int) ( $height * 3 / 4 ), imagecolorallocate( $img, 30, 60, 120 ) );
	imagestring( $img, 5, 4, 4, $width . 'x' . $height, imagecolorallocate( $img, 0, 0, 0 ) );

	ob_start();
	if ( 'png' === $format ) {
		imagepng( $img );
	} else {
		imagejpeg( $img, null, 85 );
	}
	return (string) ob_get_clean();
}

/**
 * Write bytes into uploads and register them as an attachment with full
 * metadata (real thumbnails), like a browser upload would. $author is the
 * owning user id (0 = none; author-restriction tests pass a user's id).
 *
 * @return array|WP_Error {id, url, file, mime, width, height, sizes}
 */
function emr_e2e_insert_attachment( $filename, $bytes, $title = '', $author = 0 ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$upload = wp_upload_bits( $filename, null, $bytes );
	if ( ! empty( $upload['error'] ) ) {
		return new WP_Error( 'emr_e2e_upload_failed', (string) $upload['error'], array( 'status' => 500 ) );
	}

	$id = wp_insert_attachment(
		array(
			'post_mime_type' => $upload['type'],
			'post_title'     => '' !== $title ? $title : pathinfo( $filename, PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
			'post_author'    => (int) $author,
		),
		$upload['file'],
		0,
		true
	);
	if ( is_wp_error( $id ) || ! $id ) {
		return new WP_Error( 'emr_e2e_insert_failed', 'wp_insert_attachment failed', array( 'status' => 500 ) );
	}
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

	return emr_e2e_attachment_summary( $id );
}

/** id, url, file, mime, dimensions and per-size URLs of an attachment. */
function emr_e2e_attachment_summary( $id ) {
	$meta  = wp_get_attachment_metadata( $id );
	$meta  = is_array( $meta ) ? $meta : array();
	$sizes = array();
	if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $name => $size ) {
			$src            = wp_get_attachment_image_src( $id, $name );
			$sizes[ $name ] = array(
				'file'   => $size['file'],
				'width'  => (int) $size['width'],
				'height' => (int) $size['height'],
				'url'    => $src ? $src[0] : '',
			);
		}
	}
	return array(
		'id'     => (int) $id,
		'url'    => (string) wp_get_attachment_url( $id ),
		'file'   => (string) get_attached_file( $id ),
		'mime'   => (string) get_post_mime_type( $id ),
		'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height' => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		'sizes'  => $sizes,
	);
}

/**
 * Create a published post: raw `content`, or with `attachment_id` an image
 * block with the markup the block editor serialises, linking the full file
 * and embedding the `size` URL (default medium).
 *
 * @return array|WP_Error {id, url, edit_url, content}
 */
function emr_e2e_insert_post( array $params ) {
	$content = isset( $params['content'] ) ? (string) $params['content'] : '';

	if ( '' === $content && ! empty( $params['attachment_id'] ) ) {
		$image_id = (int) $params['attachment_id'];
		if ( 'attachment' !== get_post_type( $image_id ) ) {
			return new WP_Error( 'emr_e2e_not_found', 'No such attachment: ' . $image_id, array( 'status' => 404 ) );
		}
		$size = isset( $params['size'] ) ? sanitize_key( (string) $params['size'] ) : 'medium';
		$src  = wp_get_attachment_image_url( $image_id, $size );
		$full = wp_get_attachment_url( $image_id );
		$src  = $src ? $src : $full;
		$content = sprintf(
			'<!-- wp:image {"id":%1$d,"sizeSlug":"%4$s","linkDestination":"media"} -->' . "\n" .
			'<figure class="wp-block-image size-%4$s"><a href="%3$s"><img src="%2$s" alt="" class="wp-image-%1$d"/></a></figure>' . "\n" .
			'<!-- /wp:image -->',
			$image_id,
			esc_url( $src ),
			esc_url( $full ),
			esc_attr( $size )
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => isset( $params['status'] ) ? sanitize_key( (string) $params['status'] ) : 'publish',
			'post_title'   => isset( $params['title'] ) ? (string) $params['title'] : 'EMR E2E post',
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'emr_e2e_post_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
	}

	return array(
		'id'       => (int) $post_id,
		'url'      => (string) get_permalink( $post_id ),
		'edit_url' => admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ),
		'content'  => get_post( $post_id )->post_content,
	);
}

/** rm -rf, refused outside $root. */
function emr_e2e_rmdir_recursive( $dir, $root ) {
	$real     = realpath( $dir );
	$realRoot = realpath( $root );
	if ( false === $real || false === $realRoot || $real === $realRoot || 0 !== strpos( $real, trailingslashit( $realRoot ) ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

// -----------------------------------------------------------------------
// REST routes
// -----------------------------------------------------------------------

/** Shared permission check: the token header must match the constant. */
function emr_e2e_rest_permission( $request ) {
	$token = defined( 'EMR_E2E_TOKEN' ) ? (string) EMR_E2E_TOKEN : '';
	return '' !== $token && hash_equals( $token, (string) $request->get_header( 'X-EMR-E2E-TOKEN' ) );
}

function emr_e2e_register_routes() {
	$ns  = 'emr-e2e/v1';
	$def = array( 'permission_callback' => 'emr_e2e_rest_permission' );

	register_rest_route( $ns, '/reset', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_reset' ) );
	register_rest_route( $ns, '/seed', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_seed' ) );
	register_rest_route( $ns, '/seed', $def + array( 'methods' => 'GET', 'callback' => 'emr_e2e_route_get_seed' ) );
	register_rest_route( $ns, '/option', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_option' ) );
	register_rest_route( $ns, '/option', $def + array( 'methods' => 'GET', 'callback' => 'emr_e2e_route_get_option' ) );
	register_rest_route( $ns, '/fixture', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_fixture' ) );
	register_rest_route( $ns, '/image', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_image' ) );
	register_rest_route( $ns, '/post', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_post' ) );
	register_rest_route( $ns, '/post/(?P<id>\d+)', $def + array( 'methods' => 'GET', 'callback' => 'emr_e2e_route_get_post' ) );
	register_rest_route( $ns, '/attachment/(?P<id>\d+)', $def + array( 'methods' => 'GET', 'callback' => 'emr_e2e_route_attachment' ) );
	register_rest_route( $ns, '/key', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_key' ) );
	register_rest_route( $ns, '/feature', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_feature' ) );
	register_rest_route( $ns, '/user', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_user' ) );
	register_rest_route( $ns, '/mock', $def + array( 'methods' => 'GET', 'callback' => 'emr_e2e_route_get_mock' ) );
	register_rest_route( $ns, '/mock', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_mock' ) );
	register_rest_route( $ns, '/mock/reset', $def + array( 'methods' => 'POST', 'callback' => 'emr_e2e_route_mock_reset' ) );
	register_rest_route( $ns, '/mock/requests', $def + array( 'methods' => 'GET', 'callback' => 'emr_e2e_route_mock_requests' ) );
}
add_action( 'rest_api_init', 'emr_e2e_register_routes' );

// -----------------------------------------------------------------------
// Route handlers
// -----------------------------------------------------------------------

function emr_e2e_route_reset( WP_REST_Request $request ) {
	global $wpdb;

	// Content: every attachment (files included), post and page.
	$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) {
		wp_delete_attachment( $id, true );
	}
	$ids = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	delete_option( EMR_E2E_SEED_OPTION );

	// Users the `user` route created.
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( get_users( array( 'meta_key' => EMR_E2E_USER_META, 'fields' => 'ID' ) ) as $user_id ) {
		wp_delete_user( (int) $user_id );
	}

	// EMR state: settings, API key, persistent notices (the notices module's
	// option, dismissals included), the remote-notice throttle and the
	// remove-background result transients (emr_<hash>).
	delete_option( 'enable_media_replace' );
	delete_option( 'emr_shortpixel_api_key' );
	delete_option( 'EnableMediaReplace-notices' );
	delete_option( EMR_E2E_FEATURE_OPTION );
	delete_transient( 'emr_remote_notice' );
	$like = $wpdb->esc_like( '_transient_emr_' ) . '%';
	$likeTimeout = $wpdb->esc_like( '_transient_timeout_emr_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $likeTimeout ) );
	wp_cache_flush();

	// Per-user screen preferences a test may change (list/grid mode, hidden columns).
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		delete_user_option( $user_id, 'media_library_mode' );
		delete_user_option( $user_id, 'manageuploadcolumnshidden' );
		delete_user_option( $user_id, 'manageuploadcolumnshidden', true );
	}

	if ( class_exists( 'EMR_E2E_MockApi' ) ) {
		EMR_E2E_MockApi::resetAll();

		// Stub plugins installed through the upsell installer. Only folders
		// whose main file carries the mock's marker are touched.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( EMR_E2E_MockApi::STUB_PLUGINS as $slug => $main ) {
			$file = WP_PLUGIN_DIR . '/' . $slug . '/' . $main[0];
			if ( is_file( $file ) && false !== strpos( (string) file_get_contents( $file ), EMR_E2E_MockApi::STUB_MARKER ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				deactivate_plugins( $slug . '/' . $main[0], true );
				emr_e2e_rmdir_recursive( WP_PLUGIN_DIR . '/' . $slug, WP_PLUGIN_DIR );
			}
		}
		wp_clean_plugins_cache( false );
	}

	// Upload directories: the year/month folders (files a replace left
	// behind under a new name) and any emr-e2e-* folder a "new location"
	// test created. Same file names in every test, no -1 suffixes.
	$uploads = wp_get_upload_dir();
	$base    = $uploads['basedir'];
	foreach ( (array) glob( trailingslashit( $base ) . '*', GLOB_ONLYDIR ) as $dir ) {
		$name = basename( $dir );
		if ( preg_match( '/^\d{4}$/', $name ) || 0 === strpos( $name, 'emr-e2e-' ) ) {
			emr_e2e_rmdir_recursive( $dir, $base );
		}
	}

	return rest_ensure_response( emr_e2e_apply_seed() );
}

function emr_e2e_route_seed( WP_REST_Request $request ) {
	return rest_ensure_response( emr_e2e_apply_seed() );
}

function emr_e2e_route_get_seed( WP_REST_Request $request ) {
	return rest_ensure_response( (array) get_option( EMR_E2E_SEED_OPTION, array() ) );
}

function emr_e2e_route_option( WP_REST_Request $request ) {
	$params = (array) $request->get_json_params();
	if ( empty( $params['name'] ) ) {
		return new WP_Error( 'emr_e2e_bad_request', 'name is required', array( 'status' => 400 ) );
	}
	$name = (string) $params['name'];
	if ( ! empty( $params['delete'] ) ) {
		delete_option( $name );
		return rest_ensure_response( array( 'ok' => true, 'deleted' => $name ) );
	}
	update_option( $name, array_key_exists( 'value', $params ) ? $params['value'] : '' );
	return rest_ensure_response( array( 'ok' => true ) );
}

function emr_e2e_route_get_option( WP_REST_Request $request ) {
	$name    = (string) $request->get_param( 'name' );
	$missing = new stdClass();
	$value   = get_option( $name, $missing );
	return rest_ensure_response(
		array(
			'name'   => $name,
			'exists' => $value !== $missing,
			'value'  => $value === $missing ? null : $value,
		)
	);
}

/** The format param, normalised to 'jpg' or 'png'. */
function emr_e2e_format_param( array $params ) {
	$format = isset( $params['format'] ) ? strtolower( (string) $params['format'] ) : 'jpg';
	return 'png' === $format ? 'png' : 'jpg';
}

/**
 * Upload tests/fixtures/<name> (optionally under another name `as`), or a
 * GD-drawn image of { width, height }, as an attachment.
 */
function emr_e2e_route_fixture( WP_REST_Request $request ) {
	$params = (array) $request->get_json_params();
	$title  = isset( $params['title'] ) ? (string) $params['title'] : '';
	$author = isset( $params['author'] ) ? (int) $params['author'] : 0;
	$as     = isset( $params['as'] ) ? sanitize_file_name( basename( (string) $params['as'] ) ) : '';

	if ( ! empty( $params['name'] ) ) {
		$name   = basename( (string) $params['name'] );
		$source = EMR_E2E_PLUGIN_DIR . '/tests/fixtures/' . $name;
		if ( ! is_file( $source ) ) {
			return new WP_Error( 'emr_e2e_no_fixture', 'Unknown fixture: ' . $name, array( 'status' => 404 ) );
		}
		if ( '' !== $as && strtolower( pathinfo( $as, PATHINFO_EXTENSION ) ) !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'emr_e2e_bad_as', "'as' must keep the fixture's extension", array( 'status' => 400 ) );
		}
		$result = emr_e2e_insert_attachment( '' !== $as ? $as : $name, (string) file_get_contents( $source ), $title, $author ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	} elseif ( ! empty( $params['width'] ) && ! empty( $params['height'] ) ) {
		$format   = emr_e2e_format_param( $params );
		$filename = '' !== $as ? $as : sprintf( 'emr-e2e-%dx%d.%s', (int) $params['width'], (int) $params['height'], $format );
		$result   = emr_e2e_insert_attachment( $filename, emr_e2e_draw_image( $params['width'], $params['height'], $format ), $title, $author );
	} else {
		return new WP_Error( 'emr_e2e_bad_request', 'Give a fixture name, or width and height', array( 'status' => 400 ) );
	}

	return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
}

/** Bytes of a GD-drawn image, base64, for a browser-side upload. */
function emr_e2e_route_image( WP_REST_Request $request ) {
	$params = (array) $request->get_json_params();
	if ( empty( $params['width'] ) || empty( $params['height'] ) ) {
		return new WP_Error( 'emr_e2e_bad_request', 'width and height are required', array( 'status' => 400 ) );
	}
	$format = emr_e2e_format_param( $params );
	$name   = isset( $params['name'] ) ? sanitize_file_name( basename( (string) $params['name'] ) ) : sprintf( 'emr-e2e-%dx%d.%s', (int) $params['width'], (int) $params['height'], $format );
	return rest_ensure_response(
		array(
			'name'     => $name,
			'mimeType' => 'png' === $format ? 'image/png' : 'image/jpeg',
			'base64'   => base64_encode( emr_e2e_draw_image( $params['width'], $params['height'], $format ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		)
	);
}

function emr_e2e_route_post( WP_REST_Request $request ) {
	$result = emr_e2e_insert_post( (array) $request->get_json_params() );
	return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
}

/** Raw post_content, straight from the database. */
function emr_e2e_route_get_post( WP_REST_Request $request ) {
	$id = (int) $request['id'];
	clean_post_cache( $id );
	$post = get_post( $id );
	if ( ! $post ) {
		return new WP_Error( 'emr_e2e_not_found', 'No such post', array( 'status' => 404 ) );
	}
	return rest_ensure_response(
		array(
			'id'      => (int) $post->ID,
			'content' => $post->post_content,
			'status'  => $post->post_status,
			'title'   => $post->post_title,
		)
	);
}

/** Server-side truth about one attachment, straight from the database. */
function emr_e2e_route_attachment( WP_REST_Request $request ) {
	$id = (int) $request['id'];
	clean_post_cache( $id );
	wp_cache_delete( $id, 'post_meta' );
	$post = get_post( $id );
	if ( ! $post || 'attachment' !== $post->post_type ) {
		return new WP_Error( 'emr_e2e_not_found', 'No such attachment', array( 'status' => 404 ) );
	}
	$file   = (string) get_attached_file( $id );
	$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
	return rest_ensure_response(
		array(
			'id'                => $id,
			'title'             => $post->post_title,
			'mime'              => (string) $post->post_mime_type,
			'attached_file'     => (string) get_post_meta( $id, '_wp_attached_file', true ),
			'file'              => $file,
			'file_exists'       => '' !== $file && file_exists( $file ),
			'url'               => (string) wp_get_attachment_url( $id ),
			'guid'              => $post->guid,
			'post_date'         => $post->post_date,
			'post_date_gmt'     => $post->post_date_gmt,
			'post_modified'     => $post->post_modified,
			'post_modified_gmt' => $post->post_modified_gmt,
			'metadata'          => wp_get_attachment_metadata( $id ),
			'backup_sizes'      => is_array( $backup ) ? $backup : null,
			'replace_author'    => get_post_meta( $id, '_emr_replace_author', true ),
		)
	);
}

/** { key: '<20 chars>' } stores the API key option as is; { key: null } deletes it. */
function emr_e2e_route_key( WP_REST_Request $request ) {
	$params = (array) $request->get_json_params();
	$key    = array_key_exists( 'key', $params ) ? $params['key'] : null;
	if ( null === $key || '' === $key ) {
		delete_option( 'emr_shortpixel_api_key' );
		return rest_ensure_response( array( 'ok' => true, 'key' => null ) );
	}
	update_option( 'emr_shortpixel_api_key', (string) $key );
	return rest_ensure_response( array( 'ok' => true, 'key' => (string) $key ) );
}

/** { background?: bool|null, upscale?: bool|null, remote_notice?: bool|null }: null removes the override. */
function emr_e2e_route_feature( WP_REST_Request $request ) {
	$features = (array) get_option( EMR_E2E_FEATURE_OPTION, array() );
	foreach ( (array) $request->get_json_params() as $name => $value ) {
		if ( ! in_array( $name, array( 'background', 'upscale', 'remote_notice' ), true ) ) {
			return new WP_Error( 'emr_e2e_bad_request', 'Unknown feature: ' . $name, array( 'status' => 400 ) );
		}
		if ( null === $value ) {
			unset( $features[ $name ] );
		} else {
			$features[ $name ] = (bool) $value;
		}
	}
	update_option( EMR_E2E_FEATURE_OPTION, $features, false );
	return rest_ensure_response( array( 'ok' => true, 'features' => $features ) );
}

/** A user with the given role and password "password"; removed by reset. */
function emr_e2e_route_user( WP_REST_Request $request ) {
	$params = (array) $request->get_json_params();
	$role   = isset( $params['role'] ) ? sanitize_key( (string) $params['role'] ) : 'author';
	if ( null === get_role( $role ) ) {
		return new WP_Error( 'emr_e2e_bad_request', 'Unknown role: ' . $role, array( 'status' => 400 ) );
	}
	$login = isset( $params['login'] ) ? sanitize_user( (string) $params['login'], true ) : 'emr-e2e-' . $role;
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => 'password',
			'user_email' => $login . '@example.com',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		return new WP_Error( 'emr_e2e_user_failed', $id->get_error_message(), array( 'status' => 500 ) );
	}
	update_user_meta( $id, EMR_E2E_USER_META, 1 );
	return rest_ensure_response( array( 'id' => (int) $id, 'login' => $login, 'password' => 'password', 'role' => $role ) );
}

function emr_e2e_route_get_mock( WP_REST_Request $request ) {
	return rest_ensure_response( EMR_E2E_MockApi::knobs() );
}

function emr_e2e_route_mock( WP_REST_Request $request ) {
	$result = EMR_E2E_MockApi::setKnobs( (array) $request->get_json_params() );
	return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true, 'knobs' => $result ) );
}

function emr_e2e_route_mock_reset( WP_REST_Request $request ) {
	EMR_E2E_MockApi::resetAll();
	return rest_ensure_response( array( 'ok' => true ) );
}

function emr_e2e_route_mock_requests( WP_REST_Request $request ) {
	$requests = EMR_E2E_MockApi::requests();
	$filter   = (string) $request->get_param( 'path' );
	if ( '' !== $filter ) {
		$requests = array_values(
			array_filter(
				$requests,
				function ( $entry ) use ( $filter ) {
					return false !== strpos( (string) $entry['path'], $filter );
				}
			)
		);
	}
	return rest_ensure_response( $requests );
}
