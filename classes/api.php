<?php
/**
 * This page contains api class.
 */

namespace EnableMediaReplace;
use Exception;
use stdClass;
/**
 * This class contains api methods
 */
class Api {


	/**
	 * ShortPixel api url
	 *
	 * @var string $url
	 */
	private $url = 'http://api.shortpixel.com/v2/reducer.php';

	/**
	 * ShortPixel api request headers
	 *
	 * @var array $headers
	 */
	private $headers = array(
		'Content-Type: application/json',
		'Accept: application/json',
	);


	/**
	 * Create ShortPixel api request
	 *
	 * @param  array $data
	 * @return stdClass $result
	 */
	public function request( array $posted_data ) {
		$bg_remove = '1';

		if ( 'solid' === $posted_data['background']['type'] ) {
			$bg_remove = str_replace( '#', '', $posted_data['background']['color'] );
			if ( '100' === $posted_data['background']['transparency'] ) {
				$bg_remove .= '99';
			} elseif ( '10' > $posted_data['background']['transparency'] ) {
				$bg_remove .= "0{$posted_data['background']['transparency']}";
			} else {
				$bg_remove .= $posted_data['background']['transparency'];
			}
		}

		$data = array(
			'plugin_version' => 'v0.1',
			'key'            => '4quMx3AjWuFa4H6v0C0t',
			'bg_remove'      => $bg_remove,
			'urllist'        => array( urlencode( $posted_data['image'] ) ),
			'lossy'          => '1',
		);

		$request = array(
			'method'  => 'POST',
			'timeout' => 30,
			'headers' => $this->headers,
			'body'    => json_encode( $data ),
		);

		try {
			$result          = new stdClass;
			$result->success = false;
			$response        = wp_remote_post( $this->url, $request );

			if ( is_wp_error( $response ) ) {
				$result->message = $response->get_error_message();
			} else {
				$json = json_decode( $response['body'], false, 512, JSON_THROW_ON_ERROR );
				if ( is_array( $json ) && '2' === $json[0]->Status->Code ) {
					$result->success = true;
					$result->image   = $json[0]->LosslessURL;
				} else {
					$this->request( $posted_data );
				}
			}
		} catch ( Exception $e ) {
			$result->message = $e->getMessage();
		}

		return $result;
	}
}
