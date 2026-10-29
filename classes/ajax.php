<?php
/**
 * AJAX endpoints for remove-background and the API key.
 *
 * The file has no ABSPATH guard and instantiates the class when it is required.
 *
 * @package EnableMediaReplace
 */
namespace EnableMediaReplace;

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Api as Api;
use EnableMediaReplace\ApiKeyManager as ApiKeyManager;

/**
 * Registers and handles the EMR admin AJAX actions.
 *
 * | Action                         | Handler             | Checks                                                   |
 * |--------------------------------|---------------------|----------------------------------------------------------|
 * | wp_ajax_emr_remove_background  | remove_background() | nonce verified against `$_POST['action']` (so action `emr_remove_background`); then Api::request() checks checkImagePermission() |
 * | wp_ajax_emr_save_api_key       | save_api_key()      | `manage_options`, nonce `emr_save_api_key`               |
 * | wp_ajax_emr_delete_api_key     | delete_api_key()    | `manage_options`, nonce `emr_save_api_key`               |
 *
 * Only `wp_ajax_` (logged-in) actions are registered. Lifecycle: not a singleton; the
 * constructor registers the hooks. It is instantiated at the bottom of this file when
 * the plugin requires it, and again in EnableMediaReplacePlugin::adminInit().
 *
 * @todo The double instantiation registers every endpoint twice (two callbacks on
 *       different objects). The first handler ends the request with wp_send_json*() or
 *       die(), so the second does not run, but the duplicate is unintended. Suggested fix:
 *       remove one of the two `new Ajax()` calls.
 */
class Ajax {
	/**
	 * Registers `wp_ajax_emr_{action}` for each endpoint, with the method of the same name.
	 *
	 * @hook action wp_ajax_emr_remove_background
	 * @hook action wp_ajax_emr_save_api_key
	 * @hook action wp_ajax_emr_delete_api_key
	 */
	public function __construct() {
		$endpoints = array(
			'remove_background',
			'save_api_key',
			'delete_api_key',
		);
		foreach ( $endpoints as $action ) {
			add_action( "wp_ajax_emr_{$action}", array( $this, $action ) );
		}
	}

	/**
	 * Runs the remove-background request and returns its result as JSON.
	 *
	 * After check_nonce(), passes the whole `$_POST` to Api::request() and sends the result
	 * object with wp_send_json() (no success/data wrapper). A failed nonce ends the request
	 * with the plain text "Wrong nonce". There is no capability check here; Api::request()
	 * checks checkImagePermission() on the attachment.
	 *
	 * @hook action wp_ajax_emr_remove_background
	 * @return never Ends the request.
	 */
	public function remove_background() {
		if ( $this->check_nonce() ) {
			$api = new Api;
			$response = $api->request( $_POST );
            wp_send_json($response);
		}
		else {
				die('Wrong nonce');
		}
	}

	/**
	 * Verifies and saves the API key posted as `api_key`.
	 *
	 * Requires `manage_options` and nonce `nonce` for action `emr_save_api_key`, then calls
	 * ApiKeyManager::saveAndVerify() with the unslashed key.
	 *
	 * @hook action wp_ajax_emr_save_api_key
	 * @return never Ends with wp_send_json_success() or wp_send_json_error(), each with a
	 *               `message`.
	 */
	public function save_api_key() {
		if ( ! current_user_can('manage_options') ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'enable-media-replace' ) ) );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'emr_save_api_key' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token. Please reload the page and try again.', 'enable-media-replace' ) ) );
		}

		$key = isset( $_POST['api_key'] ) ? wp_unslash( $_POST['api_key'] ) : '';
		$result = ApiKeyManager::getInstance()->saveAndVerify( $key );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * Deletes the stored API key.
	 *
	 * Requires `manage_options` and nonce `nonce` for action `emr_save_api_key` (the same
	 * nonce action as saving).
	 *
	 * @hook action wp_ajax_emr_delete_api_key
	 * @return never Ends with wp_send_json_success() or wp_send_json_error(), each with a
	 *               `message`.
	 */
	public function delete_api_key() {
		if ( ! current_user_can('manage_options') ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'enable-media-replace' ) ) );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'emr_save_api_key' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token. Please reload the page and try again.', 'enable-media-replace' ) ) );
		}

		ApiKeyManager::getInstance()->deleteKey();
		wp_send_json_success( array( 'message' => __( 'API Key removed.', 'enable-media-replace' ) ) );
	}

	/**
	 * Verifies `$_POST['nonce']` against the action named in `$_POST['action']`.
	 *
	 * For this endpoint the action is always `emr_remove_background`, which is the nonce
	 * action created in EnableMediaReplacePlugin (localised as `emrObject.nonce`).
	 *
	 * @return int|false 1 or 2 when valid (by nonce age), false otherwise.
	 */
	private function check_nonce() {
		$nonce  = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
		$action = isset( $_POST['action'] ) ? sanitize_text_field( $_POST['action'] ) : '';
		return wp_verify_nonce( $nonce, $action );
	}
}


// Registers the endpoints at require time; adminInit() creates a second instance (see the class @todo).
new Ajax();