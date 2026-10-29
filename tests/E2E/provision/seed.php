<?php
/**
 * WP-CLI seed step for the E2E install: `wp eval-file` runs this with
 * WordPress (and the mu-plugins) fully loaded, so it calls the same
 * baseline the REST support endpoint applies between tests.
 *
 * WP-CLI runs as uid 33 (see the wpcli service in docker-compose.e2e.yml),
 * the same user as Apache, so the seed attachment written here can be
 * replaced and deleted by the plugin when a test drives the site.
 *
 * @package Enable_Media_Replace
 */

// Check the constant, not function_exists(): PHP declares a file's
// top-level functions even when the file returns early, so the function
// exists while the mu-plugin is inert.
if ( ! defined( 'EMR_E2E' ) || ! EMR_E2E || ! function_exists( 'emr_e2e_apply_seed' ) ) {
	WP_CLI::error( 'emr-e2e-support.php mu-plugin is not loaded: is EMR_E2E defined in wp-config.php?' );
}

$seed = emr_e2e_apply_seed();
WP_CLI::success(
	sprintf(
		'EMR E2E seed applied (attachment %d, post %d, default EMR settings, no API key).',
		(int) $seed['attachment_id'],
		(int) $seed['post_id']
	)
);
