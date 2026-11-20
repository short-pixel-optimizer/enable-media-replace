<?php
/**
 * ShortPixel API client of the bulk queue.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\ApiKeyManager;
use EnableMediaReplace\Model\Bulk\QueueItem;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Sends a queue item's action (remove background or upscale) to the
 * reducer endpoint and interprets the answer.
 *
 * Port of ShortPixel Image Optimizer's RequestManager plus the action path
 * of its ApiController (processActionItem(), dumpMediaItem(),
 * handleResponse(), handleActionResponse()). Differences from SPIO:
 *
 * - The request body is the one EMR's single flow sends (Api::request()
 *   with a key): plugin_version, key, urllist, lossy 0, item_id, refresh
 *   and bg_remove or upscale. No timestamp is added to the URL; `refresh`
 *   plus the cleanup call purge the remote cache instead.
 * - handleActionResponse() maps every per-item status that is not waiting
 *   or success to a failure with the API message, where SPIO returns null.
 * - Quota and plan errors are reported as retryable statuses; the queue
 *   controller stops the run on them.
 *
 * Every call is blocking: the first send asks the API to start, later
 * ticks poll until the item is ready (STATUS_UNCHANGED in between).
 *
 * Lifecycle: getInstance() singleton per request. Hooks:
 * - filter `emr/bulk/api/request` (body, item id): the request body before encoding.
 * - filter `emr/bulk/api/timeout` (15): request timeout in seconds.
 */
class BulkApi
{
	const STATUS_ENQUEUED         = 10;
	const STATUS_PARTIAL_SUCCESS  = 3;
	const STATUS_SUCCESS          = 2;
	const STATUS_WAITING          = 1;
	const STATUS_UNCHANGED        = 0;
	const STATUS_ERROR            = -1;
	const STATUS_FAIL             = -2;
	const STATUS_QUOTA_EXCEEDED   = -3;
	const STATUS_SKIP             = -4;
	const STATUS_NOT_FOUND        = -5;
	const STATUS_NO_KEY           = -6;
	const STATUS_QUEUE_FULL       = -404;
	const STATUS_MAINTENANCE      = -500;
	const STATUS_CONNECTION_ERROR = -503;
	const STATUS_NOT_API          = -1000;

	/** Retry limit reached without a result. */
	const ERR_TIMEOUT = -903;
	const ERR_DOWNLOAD = -907;
	const ERR_UNKNOWN = -999;

	/** @var string */
	const API_HOST = 'api.shortpixel.com';

	/** @var static|null */
	protected static $instance;

	/** @var string */
	protected $apiEndPoint;

	/** @var string */
	protected $apiDumpEndPoint;

	public function __construct()
	{
		$this->apiEndPoint     = 'https://' . self::API_HOST . '/v2/reducer.php';
		$this->apiDumpEndPoint = 'https://' . self::API_HOST . '/v2/cleanup.php';
	}

	/**
	 * @return static
	 */
	public static function getInstance()
	{
		if (is_null(static::$instance)) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * The reducer endpoint.
	 *
	 * @return string
	 */
	public function getEndPoint()
	{
		return $this->apiEndPoint;
	}

	/**
	 * Builds and sends the request for a remove-background or upscale item.
	 *
	 * On the first try with `refresh` set, the item's URLs are purged from the
	 * remote cache first. After a terminal error the URLs are purged as well.
	 * Without an API key the item fails with STATUS_NO_KEY.
	 *
	 * @param QueueItem $qItem
	 * @return void
	 */
	public function processActionItem(QueueItem $qItem)
	{
		$apiKey = ApiKeyManager::getInstance()->getApiKey();
		if (empty($apiKey)) {
			$qItem->addResult($this->returnFailure(self::STATUS_NO_KEY, __('No API key', 'enable-media-replace')));
			return;
		}

		$urls = $qItem->data()->urls;
		if (! is_array($urls) || 0 === count($urls)) {
			$qItem->addResult($this->returnFailure(self::STATUS_FAIL, __('No URL given for this item', 'enable-media-replace')));
			return;
		}

		$paramlist = is_array($qItem->data()->paramlist) ? $qItem->data()->paramlist : array();
		$tries     = (int) $qItem->data()->tries;
		$refresh   = (0 === $tries && ! empty($paramlist['refresh']));

		$requestBody = array(
			'plugin_version' => 'EMR' . EMR_VERSION,
			'key'            => $apiKey,
			'urllist'        => array_values(array_map('esc_url_raw', $urls)),
			'lossy'          => is_null($qItem->data()->compressionType) ? 0 : (int) $qItem->data()->compressionType,
			'item_id'        => $qItem->item_id,
			'refresh'        => $refresh,
		);

		if (true === $refresh) {
			$this->dumpMediaItem($qItem);
		}

		if (isset($paramlist['bg_remove'])) {
			$requestBody['bg_remove'] = $paramlist['bg_remove'];
		} elseif (isset($paramlist['upscale'])) {
			$requestBody['upscale'] = (int) $paramlist['upscale'];
		} else {
			$qItem->addResult($this->returnFailure(self::STATUS_FAIL, __('Item has neither a background nor an upscale parameter', 'enable-media-replace')));
			return;
		}

		$request = $this->getRequest($requestBody, array('blocking' => true));
		$this->doRequest($qItem, $request);

		if (true === $qItem->result()->is_error && true === $qItem->result()->is_done) {
			$this->dumpMediaItem($qItem);
		}
	}

	/**
	 * Asks the API to drop the item's URLs from its cache (cleanup endpoint).
	 *
	 * @param QueueItem $qItem
	 * @return false|array|\WP_Error False without URLs, otherwise the wp_remote_post() result.
	 */
	public function dumpMediaItem(QueueItem $qItem)
	{
		$urls = $qItem->data()->urls;
		if (! is_array($urls) || 0 === count($urls)) {
			Log::addWarn('Item without URLs cannot be dumped', $qItem->item_id);
			return false;
		}

		$requestBody = array(
			'plugin_version' => 'EMR' . EMR_VERSION,
			'key'            => ApiKeyManager::getInstance()->getApiKey(),
			'urllist'        => array_values(array_map('esc_url_raw', $urls)),
			'item_id'        => $qItem->item_id,
		);

		$request = $this->getRequest($requestBody, array());
		Log::addDebug('Dumping item from remote cache', $urls);
		return wp_remote_post($this->apiDumpEndPoint, $request);
	}

	/**
	 * The wp_remote_post() argument array for a request body.
	 *
	 * @param array $requestBody
	 * @param array $requestParameters 'blocking' (bool) and 'headers' (array).
	 * @return array
	 */
	protected function getRequest($requestBody = array(), $requestParameters = array())
	{
		$requestBody = apply_filters('emr/bulk/api/request', $requestBody, isset($requestBody['item_id']) ? $requestBody['item_id'] : 0);

		return array(
			'method'      => 'POST',
			'timeout'     => apply_filters('emr/bulk/api/timeout', 15),
			'redirection' => 3,
			'httpversion' => '1.0',
			'blocking'    => isset($requestParameters['blocking']) ? $requestParameters['blocking'] : true,
			'headers'     => isset($requestParameters['headers']) ? $requestParameters['headers'] : array(),
			'body'        => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
			'cookies'     => array(),
		);
	}

	/**
	 * Posts to the reducer and stores the interpreted result on the item.
	 *
	 * A WP_Error is a connection error: cURL 60 (SSL) and cURL 6 (host) are
	 * terminal, everything else retries. A non-200 HTTP code is terminal. A
	 * 200 goes to handleResponse().
	 *
	 * @param QueueItem $qItem
	 * @param array     $requestParameters
	 * @return void
	 */
	protected function doRequest(QueueItem $qItem, $requestParameters)
	{
		$response = wp_remote_post($this->apiEndPoint, $requestParameters);
		Log::addDebug('Bulk API request sent to ' . $this->apiEndPoint, $requestParameters['body']);

		if (empty($requestParameters['blocking'])) {
			$qItem->addResult($this->returnOK(self::STATUS_ENQUEUED, sprintf(__('Item #%d sent for processing', 'enable-media-replace'), $qItem->item_id)));
			return;
		}

		if (is_wp_error($response)) {
			$errorMessage = $response->get_error_message();
			$is_fatal     = false;

			if (false !== strpos($errorMessage, 'cURL error 28')) {
				$errorMessage = __('Timeout fetching data from ShortPixel servers. If persistent, check the server connection / whitelist', 'enable-media-replace');
			}
			if (false !== strpos($errorMessage, 'cURL error 60')) {
				$errorMessage = sprintf(__('Server error, please contact support (%s)', 'enable-media-replace'), $errorMessage);
				$is_fatal     = true;
			}
			if (false !== strpos($errorMessage, 'cURL error 6')) {
				$errorMessage = sprintf(__('Host error, please check configuration or contact support (%s)', 'enable-media-replace'), $errorMessage);
				$is_fatal     = true;
			}

			$qItem->addResult($is_fatal ? $this->returnFailure(self::STATUS_CONNECTION_ERROR, $errorMessage) : $this->returnRetry(self::STATUS_CONNECTION_ERROR, $errorMessage));
			return;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if (200 !== $code) {
			$errorMessage = $code . ' - ' . wp_remote_retrieve_response_message($response);
			$body = wp_remote_retrieve_body($response);
			if (is_string($body) && '' !== $body) {
				$decoded = json_decode($body, true);
				if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error']) && '' !== $decoded['error']) {
					$errorMessage = $decoded['error'];
				}
			}
			$qItem->addResult($this->returnFailure($code, $errorMessage));
			return;
		}

		$qItem->addResult($this->handleResponse($qItem, $response));
	}

	/**
	 * Interprets a 200 answer of the reducer.
	 *
	 * The API signals errors in the body: a top-level `Status` object, or the
	 * `Status` of the first (only) image entry. Known codes:
	 * -102/-105/-106/-108/-111/-113/-201/-202/-203/-207 permanent file or URL
	 * errors (STATUS_ERROR), -301/-403 quota (STATUS_QUOTA_EXCEEDED, retry),
	 * -302 and -306 permanent (STATUS_FAIL), -401/-402 key (STATUS_NO_KEY),
	 * -404 queue full and -500 maintenance (retry). Otherwise the image entry
	 * goes to handleActionResponse().
	 *
	 * @param QueueItem $qItem
	 * @param array     $response wp_remote_post() response.
	 * @return array Result array from one of the return* helpers.
	 */
	protected function handleResponse(QueueItem $qItem, $response)
	{
		$APIresponse = $this->parseResponse($response);

		$status = false;
		if (isset($APIresponse['Status'])) {
			$status = $APIresponse['Status'];
		} elseif (is_array($APIresponse) && isset($APIresponse[0]) && is_object($APIresponse[0]) && property_exists($APIresponse[0], 'Status')) {
			$status = $APIresponse[0]->Status;
		}

		if (is_object($status) && isset($status->Code)) {
			$known = $this->mapStatusCode((int) $status->Code, isset($status->Message) ? (string) $status->Message : '');
			if (false !== $known) {
				return $known;
			}
		}

		if (is_array($APIresponse) && isset($APIresponse[0]) && is_object($APIresponse[0])) {
			$action = $qItem->data()->action;
			if ('remove_background' === $action || 'scale_image' === $action) {
				return $this->handleActionResponse($qItem, $APIresponse);
			}
			return $this->returnFailure(self::STATUS_FAIL, __('The API client was not given a known action', 'enable-media-replace'));
		}

		if (! isset($APIresponse['Status'])) {
			Log::addError('API returned an unknown status/response', $response);
			return $this->returnFailure(self::STATUS_FAIL, __('Unrecognized API response. Please contact support.', 'enable-media-replace'));
		}

		$message = (is_object($APIresponse['Status']) && isset($APIresponse['Status']->Message)) ? $APIresponse['Status']->Message : '';
		if ('' === $message) {
			$message = __('Unrecognized API message. Please contact support.', 'enable-media-replace');
		}
		return $this->returnRetry(self::STATUS_FAIL, $message);
	}

	/**
	 * Maps a known API error code to a result; false for codes that are not errors
	 * (waiting, success) or unknown.
	 *
	 * @param int    $code
	 * @param string $message
	 * @return array|false
	 */
	protected function mapStatusCode($code, $message)
	{
		switch ($code) {
			case -102:
			case -105:
			case -106:
			case -108:
			case -111:
			case -113:
			case -201:
			case -202:
			case -203:
			case -207:
				return $this->returnFailure(self::STATUS_ERROR, $message);
			case -403:
			case -301:
				return $this->returnRetry(self::STATUS_QUOTA_EXCEEDED, __('Quota exceeded.', 'enable-media-replace'));
			case -302:
				return $this->returnFailure(self::STATUS_FAIL, __('File no longer available on remote system', 'enable-media-replace'));
			case -306:
				return $this->returnFailure(self::STATUS_FAIL, __('Files need to be from a single domain per request.', 'enable-media-replace'));
			case -401:
			case -402:
				return $this->returnFailure(self::STATUS_NO_KEY, $message);
			case -404:
				return $this->returnRetry(self::STATUS_QUEUE_FULL, $message);
			case -500:
				return $this->returnRetry(self::STATUS_MAINTENANCE, $message);
		}
		return false;
	}

	/**
	 * Interprets the image entry of a remove-background or upscale answer.
	 *
	 * Waiting (0/1) keeps the item pending; success (2) returns `optimized`
	 * (LosslessURL) and `original` (OriginalURL); any other code is a failure
	 * carrying the API message.
	 *
	 * @param QueueItem $qItem
	 * @param array     $response Decoded response, index 0 is the image object.
	 * @return array
	 */
	protected function handleActionResponse(QueueItem $qItem, $response)
	{
		$item        = $response[0];
		$status_code = (isset($item->Status) && isset($item->Status->Code)) ? intval($item->Status->Code) : self::ERR_UNKNOWN;

		if (in_array($status_code, array(self::STATUS_UNCHANGED, self::STATUS_WAITING), true)) {
			return $this->returnOK(self::STATUS_UNCHANGED, __('Item is waiting', 'enable-media-replace'));
		}
		if (self::STATUS_SUCCESS === $status_code) {
			if (! isset($item->LosslessURL) || '' === (string) $item->LosslessURL) {
				return $this->returnFailure(self::STATUS_FAIL, __('The API reported success without a result image', 'enable-media-replace'));
			}
			return $this->returnSuccess(array(
				'optimized' => $item->LosslessURL,
				'original'  => isset($item->OriginalURL) ? $item->OriginalURL : null,
			));
		}

		$message = (isset($item->Status) && isset($item->Status->Message) && '' !== (string) $item->Status->Message)
			? (string) $item->Status->Message
			: sprintf(__('The API returned status %d', 'enable-media-replace'), $status_code);
		return $this->returnFailure(self::STATUS_ERROR, $message);
	}

	/**
	 * Decodes the JSON body; on failure pulls the first JSON object out of the raw text.
	 *
	 * @param array $response
	 * @return array
	 */
	protected function parseResponse($response)
	{
		$raw  = wp_remote_retrieve_body($response);
		$data = json_decode($raw);
		if (is_null($data)) {
			preg_match_all('#\{(?:[^{}]|(?R))*\}#s', (string) $raw, $matches);
			foreach ($matches[0] as $match) {
				$decoded = json_decode($match);
				if (! is_null($decoded)) {
					return (array) $decoded;
				}
			}
			return array('status' => self::STATUS_ERROR, 'error' => json_last_error_msg());
		}
		return (array) $data;
	}

	/**
	 * Terminal failure (is_error, is_done).
	 *
	 * @param int|string $status
	 * @param string     $message
	 * @return array
	 */
	public function returnFailure($status, $message)
	{
		return array('apiStatus' => $status, 'message' => $message, 'is_error' => true, 'is_done' => true);
	}

	/**
	 * Transient error (is_error, not done).
	 *
	 * @param int|string $status
	 * @param string     $message
	 * @return array
	 */
	public function returnRetry($status, $message)
	{
		return array('apiStatus' => $status, 'message' => $message, 'is_error' => true, 'is_done' => false);
	}

	/**
	 * Intermediate state (no error, not done).
	 *
	 * @param int          $status
	 * @param string|false $message
	 * @return array
	 */
	public function returnOK($status = self::STATUS_UNCHANGED, $message = false)
	{
		return array('apiStatus' => $status, 'message' => $message, 'is_error' => false, 'is_done' => false);
	}

	/**
	 * Success; done when $status is STATUS_SUCCESS.
	 *
	 * @param array        $data
	 * @param int          $status
	 * @param string|false $message
	 * @return array
	 */
	public function returnSuccess($data, $status = self::STATUS_SUCCESS, $message = false)
	{
		$result = array('apiStatus' => $status, 'message' => $message, 'is_error' => false);
		if (self::STATUS_SUCCESS === $status) {
			$result['is_done'] = true;
		}
		if (false === $message) {
			unset($result['message']);
		}
		return array_merge($result, $data);
	}
}
