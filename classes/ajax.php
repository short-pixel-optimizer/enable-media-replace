<?php

namespace EnableMediaReplace;
use EnableMediaReplace\Api;

class Ajax {
	public function __construct() {
		$endpoints = array(
			'remove_backround',
		);
		foreach ( $endpoints as $action ) {
			add_action( "wp_ajax_emr_{$action}", array( $this, $action ) );
		}
	}

	public function remove_backround() {
		if ( $this->check_nonce() ) {
			$api = new Api;
			$response = $api->request( $_POST );
            wp_send_json($response);
		}
	}

	private function check_nonce() {
		$nonce  = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
		$action = isset( $_POST['action'] ) ? sanitize_text_field( $_POST['action'] ) : '';
		return wp_verify_nonce( $nonce, $action );
	}
}


new Ajax();
