<?php
/**
 * AJAX handlers that install and activate the plugins offered in views/upsell.php.
 *
 * Registers two global functions on admin-ajax at require time:
 * - `wp_ajax_emr_plugin_install` -> emr_plugin_install()
 * - `wp_ajax_emr_plugin_activate` -> emr_activate()
 * Both are called by js/upsell.js with POST fields `plugin` (slug) and
 * `nonce`. Accepted slugs: `envira` (Envira Gallery Lite), `spio`
 * (ShortPixel Image Optimizer), `spai` (ShortPixel Adaptive Images) and
 * `fp` (FastPixel).
 *
 * @package EnableMediaReplace
 */

add_action( 'wp_ajax_emr_plugin_install', 'emr_plugin_install' );

/**
 * Downloads and installs one of the upsell plugins from wordpress.org.
 *
 * Checks the nonce (action `emr-plugin-install`, field `nonce`; dies on
 * failure) and the `install_plugins` capability (JSON `result => false`,
 * `message => 'Install permission issue'` on failure). Maps the slug to its
 * download URL:
 * - envira: downloads.wordpress.org/plugin/envira-gallery-lite.zip
 * - spio: downloads.wordpress.org/plugin/shortpixel-image-optimiser.zip
 * - spai: downloads.wordpress.org/plugin/shortpixel-adaptive-images.zip
 * - fp: downloads.wordpress.org/plugin/fastpixel-website-accelerator.zip
 *
 * When filesystem credentials are needed, it prints JSON `{ "form": <html> }`
 * holding the WordPress credentials form (first request, or again when
 * WP_Filesystem() rejects the credentials) and dies. Otherwise it installs
 * with Plugin_Upgrader and an Automatic_Upgrader_Skin, calls
 * wp_cache_flush() so the new plugin is visible, and sends
 * wp_send_json_success( array( 'plugin' => <basename> ) ). Any other outcome
 * sends `result => false` and dies.
 *
 * @hook action wp_ajax_emr_plugin_install
 *
 * @todo $download_url is never assigned for an unknown or missing slug, so the `is_null($download_url)` check reads an undefined variable; initialise $download_url to null before the switch.
 * @todo The final failure response is array('result' => false, 'download url issue'), where the text lands under key 0; use a `message` key like the permission failure.
 *
 * @global string $hook_suffix Declared for set_current_screen().
 *
 * @return void Always ends the request.
 */
function emr_plugin_install() {

	// Run a security check first.
	check_admin_referer( 'emr-plugin-install', 'nonce' );

	$plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : null;

	if ( ! current_user_can('install_plugins'))
	{
		// Send back a response.
		
		wp_send_json(array('result'=> false, 'message' => 'Install permission issue'));
		die;
	}

	switch($plugin)
	{
		 case "envira":
			$download_url = 'https://downloads.wordpress.org/plugin/envira-gallery-lite.zip';
		 break;
		 case 'spio':
			$download_url = 'https://downloads.wordpress.org/plugin/shortpixel-image-optimiser.zip';
		 break;
		 case 'spai':
			$download_url = 'https://downloads.wordpress.org/plugin/shortpixel-adaptive-images.zip';
		 break;
		 case 'fp':
			$download_url = 'https://downloads.wordpress.org/plugin/fastpixel-website-accelerator.zip';
		 break;
	}

	// Install the addon.
	if ( ! is_null($download_url ) ) {

		global $hook_suffix;

		// Set the current screen to avoid undefined notices.
		set_current_screen();

		// Prepare variables.
		$method = '';
		$url    = add_query_arg(
			array(
			),
			admin_url( 'admin.php' )
		);
		$url    = esc_url( $url );

		// Start output bufferring to catch the filesystem form if credentials are needed.
		ob_start();
		$creds = request_filesystem_credentials( $url, $method, false, false, null );
		if ( false === $creds ) {
			$form = ob_get_clean();
			echo wp_json_encode( array( 'form' => $form ) );
			die;
		}

		// If we are not authenticated, make it happen now.
		if ( ! WP_Filesystem( $creds ) ) {
			ob_start();
			request_filesystem_credentials( $url, $method, true, false, null );
			$form = ob_get_clean();
			echo wp_json_encode( array( 'form' => $form ) );
			die;
		}

		// We do not need any extra credentials if we have gotten this far, so let's install the plugin.
		require_once (ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');

		// Create the plugin upgrader with our custom skin.
		$skin      = new Automatic_Upgrader_Skin();
		$installer = new Plugin_Upgrader( $skin );
		$installer->install( $download_url );

		// Flush the cache and return the newly installed plugin basename.
		wp_cache_flush();

		if ( $installer->plugin_info() ) {
			$plugin_basename = $installer->plugin_info();

		ob_end_clean();


			wp_send_json_success( array( 'plugin' => $plugin_basename ) );

			die();
		}
	}

	// Send back a response.
	wp_send_json(array('result'=> false, 'download url issue'));
	die;

}

add_action( 'wp_ajax_emr_plugin_activate', 'emr_activate' );

/**
 * Activates an Envira addon.
 *
 * Activates one of the upsell plugins. Checks the nonce (action
 * `emr-plugin-activate`, field `nonce`; dies on failure) and the
 * `activate_plugins` capability (JSON `result => false` on failure). Maps
 * the slug to its plugin basename:
 * - envira: envira-gallery-lite/envira-gallery-lite.php
 * - spio: shortpixel-image-optimiser/wp-shortpixel.php
 * - spai: shortpixel-adaptive-images/short-pixel-ai.php
 * - fp: fastpixel-website-accelerator/fastpixel.php
 * On activation error it prints `{ "error": <message> }`, otherwise `true`.
 * A missing slug prints `true` without activating anything. An unknown slug
 * is passed unchanged to activate_plugin(), so any installed plugin basename
 * can be activated by a user who already holds `activate_plugins`.
 *
 * @hook action wp_ajax_emr_plugin_activate
 *
 * @todo An unknown slug is passed as-is to activate_plugin(); set $plugin to null in a default case so only the four listed plugins can be activated here.
 *
 * @since 1.0.0
 *
 * @return void Always ends the request.
 */
function emr_activate() {

	// Run a security check first.
	check_admin_referer( 'emr-plugin-activate', 'nonce' );

$plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : null;

if ( ! current_user_can('activate_plugins'))
{
	// Send back a response.
	wp_send_json(array('result'=> false));
	die;
}


switch($plugin)
{
	 case "envira":
			$plugin = 'envira-gallery-lite/envira-gallery-lite.php';
	 break;
	 case 'spio':
			$plugin = 'shortpixel-image-optimiser/wp-shortpixel.php';
	 break;
	 case 'spai':
			$plugin = 'shortpixel-adaptive-images/short-pixel-ai.php';
	 break;
	 case 'fp':
			$plugin = 'fastpixel-website-accelerator/fastpixel.php';
	 break;
}

	// Activate the addon.
	if ( ! is_null($plugin)  ) {
	    $activate = activate_plugin( $plugin );
	    if ( is_wp_error( $activate ) ) {
		   echo json_encode( array( 'error' => $activate->get_error_message() ) );
		   die;
	    }
	}

	echo json_encode( true );
	die;

}
