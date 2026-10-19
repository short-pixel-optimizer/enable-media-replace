<?php
namespace EnableMediaReplace;

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Api as Api;
use EnableMediaReplace\ApiKeyManager as ApiKeyManager;
use EnableMediaReplace\Controller\BulkBackgroundController as BulkBackgroundController;

class Ajax {
	public function __construct() {
		$endpoints = array(
			'remove_background',
			'save_api_key',
			'delete_api_key',
			'bulk_start',
			'bulk_process',
			'bulk_status',
			'bulk_pause',
			'bulk_resume',
			'bulk_stop',
		);
		foreach ( $endpoints as $action ) {
			add_action( "wp_ajax_emr_{$action}", array( $this, $action ) );
		}
	}

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

	/** Start a new bulk run. Builds the list of product images and stores it as queue. */
	public function bulk_start() {
		$this->check_bulk_request();

		$result = BulkBackgroundController::getInstance()->start( array(
			'skip_processed' => isset( $_POST['skip_processed'] ) && 'true' === $_POST['skip_processed'],
			'mode'           => isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : '',
			'background'     => isset( $_POST['background'] ) ? wp_unslash( $_POST['background'] ) : array(),
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( BulkBackgroundController::getInstance()->getStatus( $result ) );
	}

	/** Browser mode: process the next image. Called again by the bulk screen after each response. */
	public function bulk_process() {
		$this->check_bulk_request();

		// Finish the image even if the bulk screen gets closed meanwhile.
		ignore_user_abort( true );

		$controller = BulkBackgroundController::getInstance();
		$result = $controller->processNext();

		$data = $controller->getStatus( $result['queue'] );
		$data['busy'] = $result['busy'];
		wp_send_json_success( $data );
	}

	public function bulk_status() {
		$this->check_bulk_request();
		wp_send_json_success( BulkBackgroundController::getInstance()->getStatus() );
	}

	public function bulk_pause() {
		$this->check_bulk_request();
		$controller = BulkBackgroundController::getInstance();
		wp_send_json_success( $controller->getStatus( $controller->pause() ) );
	}

	public function bulk_resume() {
		$this->check_bulk_request();
		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : null;
		$controller = BulkBackgroundController::getInstance();
		wp_send_json_success( $controller->getStatus( $controller->resume( $mode ) ) );
	}

	public function bulk_stop() {
		$this->check_bulk_request();
		$controller = BulkBackgroundController::getInstance();
		wp_send_json_success( $controller->getStatus( $controller->stop() ) );
	}

	/** Sends an error response and exits when the request is not allowed. */
	private function check_bulk_request() {
		// Same rules as the screen: background feature on, not for author-level users.
		if ( ! current_user_can( 'upload_files' ) || false === emr()->useFeature( 'background' ) || emr()->uiHelper()->is_author_restricted() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'enable-media-replace' ) ) );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
		if ( false === wp_verify_nonce( $nonce, 'emr_bulk_remove_background' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token. Please reload the page and try again.', 'enable-media-replace' ) ) );
		}
	}

	private function check_nonce() {
		$nonce  = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
		$action = isset( $_POST['action'] ) ? sanitize_text_field( $_POST['action'] ) : '';
		return wp_verify_nonce( $nonce, $action );
	}
}


new Ajax();