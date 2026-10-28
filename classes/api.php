<?php
/**
 * This page contains api class.
 *
 * @package EnableMediaReplace
 */
namespace EnableMediaReplace;

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Controller\ReplaceController as ReplaceController;
use EnableMediaReplace\ApiKeyManager as ApiKeyManager;


use Exception;
use stdClass;
/**
 * This class contains api methods
 *
 * Client for the ShortPixel remove-background service.
 *
 * Lifecycle: `new Api` per use (Ajax::remove_background() for the request,
 * RemoveBackGroundViewController::replaceBackground() for the download). Not a singleton,
 * registers no hooks. State: a retry counter and the `refresh` flag, both per instance, so
 * the retry loop in request() lives on one object.
 *
 * Two endpoints: without an API key the free reducer (`http://.../free-reducer.php`,
 * JSON headers, 60 s timeout, max 9 requests); with a key the authenticated reducer
 * (`https://.../reducer.php`, 15 s timeout, max 29 requests) after an Unlimited-plan check
 * and a one-time remote cache purge. The result URL is handed to the browser as a
 * transient key (handleSuccess()) and downloaded later (handleDownload()).
 */
class Api {

	/**
	 * Request Counter
	 *
	 * Number of request() calls on this instance, used for the back-off and the attempt
	 * limit. The first call sees 0.
	 *
	 * @var int $counter
	 */
	private $counter = 0;

	/**
	 * ShortPixel api url (free tier — used when no API key is configured)
	 *
	 * @var string $url
	 */
	private $url = 'http://api.shortpixel.com/v2/free-reducer.php';

	/**
	 * ShortPixel authenticated api url — used when a valid API key is configured.
	 *
	 * @var string
	 */
	private $authenticated_url = 'https://api.shortpixel.com/v2/reducer.php';

	/**
	 * Remote cache purge endpoint, called once before the first keyed request so the
	 * reducer does not return a cached result for the same URL.
	 *
	 * @var string
	 */
	private $cleanup_url = 'https://api.shortpixel.com/v2/cleanup.php';

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
	 * Sent as `refresh` in the request body; true only until the first request is sent, so
	 * retries poll the same job instead of starting a new one. Also gates the cache purge.
	 *
	 * @var bool
	 */
	private $refresh = true; // only first request should be fresh



	/**
	 * Creates the client. No setup.
	 */
	public function __construct()
	{

	}
	/**
	 * Create ShortPixel api request
	 *
	 * Sends the attachment's source URL to the reducer and polls until a result is ready.
	 *
	 * Serves both screens: a background removal (the default) or, when
	 * `$posted_data['upscale']` is 2, 3 or 4, an upscale by that factor.
	 *
	 * Sequence:
	 * 1. Reads `attachment_id` directly from `$_POST` (not from $posted_data); null gives
	 *    "No attachment ID given", a failed checkImagePermission() "No permission for user".
	 *    `upscale` is intval() of `$posted_data['upscale']`, 0 when absent; any value other
	 *    than 0, 2, 3 or 4 gives "Invalid upscale factor" before anything is saved or sent.
	 * 2. Saves `bg_type`, `bg_color` (default '#ffffff') and `bg_transparency` from
	 *    `$_POST['background']` into the `enable_media_replace` option before any request is
	 *    sent, and again on every retry. Skipped for an upscale, which has no background
	 *    options to remember.
	 * 3. `bg_remove`: '1' for transparent; for type 'solid' the colour from
	 *    `$posted_data['background']`, with a two-character alpha suffix when the
	 *    transparency is 0-100 ('FF' for 100). `background.type` is read without isset(),
	 *    but only for a background removal: an upscale skips this step, so it accepts
	 *    posted data without a background.
	 * 4. Builds the body: `plugin_version`, `bg_remove`, `urllist` (urlencoded esc_url),
	 *    `lossy` (always 0), `refresh`. For an upscale `bg_remove` is removed from the body
	 *    and `upscale` (the integer factor) is added instead.
	 * 5. With an API key: on the first call, verifyUnlimitedPlan() must pass (else a result
	 *    with `plan_expired` true); adds `key`, `item_id`, a raw `urllist`, uses the
	 *    authenticated endpoint, and on the first call with refresh purges the remote cache.
	 * 6. Retries: from the second call on, sleeps 3 s (keyed) or counter+3 s (free) before
	 *    sending. The counter is incremented before the limit check against 30 (keyed) or
	 *    10 (free), so at most 29 / 9 requests are sent; past that the result carries a
	 *    "reasonable amount of time" message. The free path can sleep about 60 s in total on
	 *    top of 60 s per request timeout, which can hit PHP's max_execution_time.
	 * 7. Response status (string compare): '2' is done: `image` is the LosslessURL (the
	 *    LossyURL branch needs `lossy` '1'/'2' and never runs because it is the integer 0),
	 *    handleSuccess() stores it and returns the `key`, and `url`, `settings` (a
	 *    "key:value" summary of the body) and a `ts` cache-buster on `image` are added.
	 *    '1' is still processing: request() calls itself recursively and returns that
	 *    result. Anything else copies the API message; a message containing
	 *    "Quota exceeded" is replaced with an upgrade message and `quota_exceeded` true.
	 *    The quota and the "reasonable amount of time" messages have an upscale wording
	 *    ("continue upscaling images", "The image could not be upscaled").
	 *
	 * @todo The upscale factor is validated against the allowed set only, not against the
	 *       image: the width limits of UIHelper::getUpscaleFactors(), which the upscale
	 *       screen enforces by disabling radios, are not checked here, so a crafted request
	 *       sends a factor the screen would have refused (for instance 4 for an image wider
	 *       than 1024 px). Suggested fix: refuse a factor whose limit is at or below the
	 *       attachment's metadata width, with the same message as the invalid factor.
	 * @todo `plugin_version` is hard-coded to 'EMR4.2.0' here and in purgeRemoteCache().
	 *       Suggested fix: `'EMR' . EMR_VERSION`.
	 * @todo The alpha suffix is `str_pad($transparency, 2, '0')` of the decimal 0-99 value,
	 *       not a hex alpha: 50 becomes '50' (0x50, about 31 %), 5 becomes '05'. Suggested
	 *       fix: `sprintf('%02X', round($transparency * 255 / 100))`.
	 *
	 * @param  array $posted_data The AJAX POST data; only `upscale`, `background.type`,
	 *                            `.color` and `.transparency` are read from it.
	 * @return stdClass $result `success`, `image`, `message`, and depending on the outcome
	 *                          `key`, `url`, `settings`, `plan_expired`, `quota_exceeded`.
	 */
	public function request( array $posted_data ) {
		$bg_remove         = '1';
		$compression_level = 0; //  intval($posted_data['compression_level']); // off for now.
		$upscale           = isset($posted_data['upscale']) ? intval($posted_data['upscale']) : 0; // 0 is no upscale, else 2, 3 or 4.

		$attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : null;
		$attachment = get_post($attachment_id);


		if (is_null($attachment_id))
		{
			 $result = $this->getResponseObject();
			 $result->success = false;
			 $result->message = __('No attachment ID given', 'enable-media-replace');
			 return $result;
		}

  	if (! emr()->checkImagePermission($attachment)) {
			$result = $this->getResponseObject();
			$result->success = false;
			$result->message = __('No permission for user', 'enable-media-replace');
			return $result;
	  }

		if (! in_array($upscale, array(0, 2, 3, 4), true))
		{
			 $result = $this->getResponseObject();
			 $result->success = false;
			 $result->message = __('Invalid upscale factor', 'enable-media-replace');
			 return $result;
		}

		$replaceController = new ReplaceController($attachment_id);
		$url = $replaceController->getSourceUrl();

		$settings = get_option('enable_media_replace', array()); // save settings and show last loaded.
		$settings['bg_type'] = isset($_POST['background']['type']) ? sanitize_text_field($_POST['background']['type']) : false;
		$settings['bg_color'] = isset($_POST['background']['color']) ? sanitize_text_field($_POST['background']['color']) : '#ffffff'; // default to white.
		$settings['bg_transparency'] = isset($_POST['background']['transparency']) ? sanitize_text_field($_POST['background']['transparency']) : false;

		if (0 === $upscale) { // Upscale has no background settings to remember.
			update_option('enable_media_replace', $settings, false);
		}


		if ( 0 === $upscale && 'solid' === $posted_data['background']['type'] ) {
			$bg_remove = $posted_data['background']['color'];

			$transparency = isset($posted_data['background']['transparency']) ? intval($posted_data['background']['transparency']) : -1;
			if ($transparency >= 0 && $transparency <= 100)
			{
				if ($transparency == 100) {
					$alpha = 'FF';
				} else {
					$alpha = str_pad($transparency, 2, '0', STR_PAD_LEFT);
				}
				$bg_remove .= $alpha;
			}
		}



		$data = array(
			'plugin_version' => 'EMR4.2.0',
			'bg_remove'      => $bg_remove,
			'urllist'        => array( urlencode( esc_url($url) ) ),
			'lossy'          => $compression_level,
			'refresh'				 => $this->refresh,
		);

		if ($upscale > 0) {
			unset($data['bg_remove']);
			$data['upscale'] = $upscale;
		}

		$apiKeyManager = ApiKeyManager::getInstance();
		$apiKey   = $apiKeyManager->getApiKey();
		$endpoint = $this->url;
		if ( ! empty( $apiKey ) ) {
			if ( $this->counter === 0 && ! $apiKeyManager->verifyUnlimitedPlan() ) {
				$result = $this->getResponseObject();
				$result->success      = false;
				$result->plan_expired = true;
				$result->message      = __( 'Your Unlimited plan is no longer active. Please renew at shortpixel.com/pricing or remove your API Key to use the Free plan.', 'enable-media-replace' );
				return $result;
			}
			$data['key']     = $apiKey;
			$data['urllist'] = array( esc_url_raw( $url ) );
			$data['item_id'] = intval( $attachment_id );
			$endpoint        = $this->authenticated_url;

			if ( $this->counter === 0 && $this->refresh ) {
				$this->purgeRemoteCache( $apiKey, esc_url_raw( $url ), intval( $attachment_id ) );
			}
		}

		if ( ! empty( $apiKey ) ) {
			$request = array(
				'method'      => 'POST',
				'timeout'     => 15,
				'redirection' => 3,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(),
				'body'        => json_encode( $data, JSON_UNESCAPED_UNICODE ),
				'cookies'     => array(),
			);
		} else {
			$request = array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => $this->headers,
				'body'    => json_encode( $data ),
			);
		}

		$settingsData = '';
		//unset($settingsData['url']);

		foreach($data as $key => $val)
		{
			 if ($key == 'urllist' || $key == 'refresh')
			 {
			 	continue;
			 }
			 $settingsData .= " $key:$val ";
		}


		if ($this->counter > 0) {
			sleep( ! empty( $apiKey ) ? 3 : ( $this->counter + 3 ) );
		}

		$this->counter++;

		$result = $this->getResponseObject();

		$maxAttempts = ! empty( $apiKey ) ? 30 : 10;

		if ( $this->counter < $maxAttempts ) {
			try {

				Log::addDebug('Sending request', $request);
				$response = wp_remote_post( $endpoint, $request );

				$this->refresh = false;

				if ( is_wp_error( $response ) ) {
					$result->message = $response->get_error_message();
				} else {

					$json = json_decode( $response['body'] );

					Log::addDebug('Response Json', $json);
					if ( is_array( $json ) && '2' === $json[0]->Status->Code ) {
						$result->success = true;

						if ( '1' === $compression_level || '2' === $compression_level ) {
							$result->image = $json[0]->LossyURL;
						} else {
							$result->image = $json[0]->LosslessURL;
						}

						$key = $this->handleSuccess($result);
						$result->key = $key;
						$result->url = $url;
						$result->image = add_query_arg('ts', time(), $result->image);

						$result->settings = $settingsData;

//						$this->handleSuccess($result);
					} elseif ( is_array( $json ) && '1' === $json[0]->Status->Code ) {
						return $this->request( $posted_data );
					} else {
						if (is_array($json))
						{
							$result->message = $json[0]->Status->Message;
						}
						elseif (is_object($json) && property_exists($json, 'Status'))
						{
							 $result->message = $json->Status->Message;
						}

						// Friendly override + flag when the free-reducer quota is exhausted.
						if ( ! empty( $result->message ) && stripos( $result->message, 'Quota exceeded' ) !== false ) {
							$result->quota_exceeded = true;
							$result->message       = ($upscale > 0)
								? __( 'Quota exceeded. Please purchase the Unlimited or Unlimited AI plan and add your API key to continue upscaling images.', 'enable-media-replace' )
								: __( 'Quota exceeded. Please purchase the Unlimited or Unlimited AI plan and add your API key to continue removing backgrounds.', 'enable-media-replace' );
						}
					}
				}
			} catch ( Exception $e ) {
				$result->message = $e->getMessage();
			}
		} else {
			$result->message = ($upscale > 0)
				? __( 'The image could not be upscaled in a reasonable amount of time. The file might be too big, or the API could be busy. Please try again later!', 'enable-media-replace' )
				: __( 'The background could not be removed in a reasonable amount of time. The file might be too big, or the API could be busy. Please try again later!', 'enable-media-replace' );
		}

		return $result;
	}

	/**
	 * Asks the API to drop any cached result for the image, so the first keyed request
	 * processes it fresh.
	 *
	 * Blocking POST (15 s timeout) to the cleanup endpoint with `plugin_version` (hard-coded
	 * 'EMR4.2.0', see the @todo on request()), `key`, `urllist` and `item_id`. The response
	 * or error is only logged.
	 *
	 * @param string $apiKey   The API key.
	 * @param string $imageUrl Raw source image URL.
	 * @param int    $itemId   Attachment ID.
	 * @return void
	 */
	private function purgeRemoteCache( $apiKey, $imageUrl, $itemId = 0 )
	{
		$body = array(
			'plugin_version' => 'EMR4.2.0',
			'key'            => $apiKey,
			'urllist'        => array( $imageUrl ),
			'item_id'        => intval( $itemId ),
		);

		$request = array(
			'method'      => 'POST',
			'timeout'     => 15,
			'redirection' => 3,
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => array(),
			'body'        => json_encode( $body, JSON_UNESCAPED_UNICODE ),
			'cookies'     => array(),
		);

		Log::addDebug( 'Purging remote cache', $request );
		$cleanup_response = wp_remote_post( $this->cleanup_url, $request );
		if ( is_wp_error( $cleanup_response ) ) {
			Log::addDebug( 'Cleanup error', $cleanup_response->get_error_message() );
		} else {
			Log::addDebug( 'Cleanup response', array(
				'code' => wp_remote_retrieve_response_code( $cleanup_response ),
				'body' => wp_remote_retrieve_body( $cleanup_response ),
			) );
		}
	}

	/**
	 * Stores the result image URL in a transient and returns its key.
	 *
	 * The key is `wp_hash($nonce . $result->image, 'logged_in')`, where $nonce is
	 * `$_POST['nonce']` (the AJAX nonce) or, when absent, wp_create_nonce() for action -1.
	 * The URL is stored as transient `emr_<key>` for 30 minutes (before the `ts`
	 * cache-buster is added). The browser posts the key back to `do_background_replace`.
	 *
	 * @param \stdClass $result Result object with `image` set to the remote URL.
	 * @return string The transient key (without the `emr_` prefix).
	 */
	public function handleSuccess($result)
	{
		 // $fs = emr()->filesystem();
		//	$result = $fs->downloadFile($result->image, wp_tempnam($result->image));
		$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : wp_create_nonce();
		$key = wp_hash($nonce . $result->image, 'logged_in');

		set_transient('emr_' . $key, $result->image, 30 * MINUTE_IN_SECONDS);
		return $key;
	}

	/**
	 * Downloads the result image stored under a transient key to a temporary file.
	 *
	 * Reads transient `emr_<key>`; when it has expired, returns a "not available anymore"
	 * message. Otherwise downloads the URL with FileSystemController::downloadFile() into a
	 * wp_tempnam() file. The transient is not deleted after use.
	 *
	 * @param string $key Transient key from handleSuccess().
	 * @return \stdClass `success`; `image` is the temp file path on success; `message` on
	 *                   failure.
	 */
	public function handleDownload($key)
	{
		$url = get_transient('emr_' . $key);
		$result = $this->getResponseObject();

		if ($url === false)
		{
			 	$result->message = __('This file seems not available anymore. Please try again', 'enable-media-replace');
				return $result;
		}

		$fs = emr()->filesystem();
		$target = wp_tempnam($url);

		$bool = $fs->downloadFile($url, $target);

		if ($bool === false)
		 {
			  $result->message = __('Download failed', 'enable-media-replace');
		 }
		else {
			$result->success = true;
			$result->image = $target;
		}
		return $result;
	}

	/**
	 * Returns an empty result object.
	 *
	 * @return \stdClass With `success` false, `image` null and `message` null.
	 */
	protected function  getResponseObject()
	{
		$result          = new stdClass;
		$result->success = false;
		$result->image = null;
		$result->message = null;

		return $result;
	}

}