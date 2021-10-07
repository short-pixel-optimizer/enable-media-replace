<?php

add_action( 'wp_ajax_envira_emr_install', 'emr_envira_install' );

function emr_envira_install() {

	// Run a security check first.
	check_admin_referer( 'envira-emr-install', 'nonce' );

	// Install the addon.
	if ( isset( $_POST['plugin'] ) ) {

		$download_url = esc_url_raw( wp_unslash( $_POST['plugin'] ) );
		global $hook_suffix;

		// Set the current screen to avoid undefined notices.
		set_current_screen();

		// Prepare variables.
		$method = '';
		$url    = add_query_arg(
			array(
				'page' => 'envira-gallery-settings',
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
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once plugin_dir_path( EMR_ROOT_FILE ) . 'classes/external/upgrader_skin.php';

		// Create the plugin upgrader with our custom skin.
		$skin      = new EMR_Envira_Gallery_Skin();
		$installer = new Plugin_Upgrader( $skin );
		$installer->install( $download_url );

		// Flush the cache and return the newly installed plugin basename.
		wp_cache_flush();

		if ( $installer->plugin_info() ) {
			$plugin_basename = $installer->plugin_info();

			wp_send_json_success( array( 'plugin' => $plugin_basename ) );

			die();
		}
	}

	// Send back a response.
	echo wp_json_encode( true );
	die;

}

add_action( 'wp_ajax_envira_emr_activate', 'envira_emr_activate' );

/**
 * Activates an Envira addon.
 *
 * @since 1.0.0
 */
function envira_emr_activate() {

	// Run a security check first.
	check_admin_referer( 'envira-emr-activate', 'nonce' );

	// Activate the addon.
	if ( isset( $_POST['plugin'] ) ) {
	    $activate = activate_plugin( $_POST['plugin'] );
	    if ( is_wp_error( $activate ) ) {
		   echo json_encode( array( 'error' => $activate->get_error_message() ) );
		   die;
	    }
	}

	echo json_encode( true );
	die;

}
