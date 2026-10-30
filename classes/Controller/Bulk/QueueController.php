<?php
/**
 * Drives the bulk queue.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\ApiKeyManager;
use EnableMediaReplace\Model\Bulk\BulkImage;
use EnableMediaReplace\Model\Bulk\QueueItem;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Glue between the callers (AJAX, cron, tests) and the BulkQueue.
 *
 * Port of ShortPixel Image Optimizer's QueueController for a single queue.
 * processQueue() is one tick: it checks the API key and the Unlimited plan
 * (skipped for a restore, which needs no API), runs BulkQueue::run(), and
 * for every dequeued item asks the ActionController to send it and handle
 * the result. The JSON it returns has the shape SPIO's bulk screen reads:
 * `media` (this queue's tick) and `total` (the same, there is one queue).
 *
 * The plan check calls api-status.php, so its positive answer is cached in
 * the `emr_bulk_plan_ok` transient; the cache is dropped when the API
 * answers with a quota or key error.
 *
 * Lifecycle: `new` per use. Hooks:
 * - filter `emr/bulk/plan_check_ttl` (3600): seconds a positive plan check is cached.
 */
class QueueController
{
	/** Error codes for the browser, same values as SPIO's AjaxController. */
	const PROCESSOR_ACTIVE = -1;
	const NONCE_FAILED     = -2;
	const NO_ACTION        = -3;
	const APIKEY_FAILED    = -4;
	const NOQUOTA          = -5;
	const SERVER_ERROR     = -6;
	const NO_ACCESS        = -7;
	const PLAN_EXPIRED     = -8;

	/** Transient holding a positive Unlimited plan check. */
	const PLAN_TRANSIENT = 'emr_bulk_plan_ok';

	/** @var int|null */
	protected static $lastId;

	/** @var int|null */
	protected $lastQStatus;

	/** @var array */
	protected $args;

	/**
	 * @param array $args 'is_bulk' (bool, default true; EMR only has the bulk queue).
	 */
	public function __construct($args = array())
	{
		$this->args = wp_parse_args($args, array('is_bulk' => true));
	}

	/**
	 * The queue.
	 *
	 * @param string $type Ignored ('media'); kept for parity with SPIO's callers.
	 * @return BulkQueue
	 */
	public function getQueue($type = 'media')
	{
		return new BulkQueue();
	}

	/**
	 * Adds one attachment to the queue for an action (direct enqueue, outside
	 * the prepare phase; used for next actions).
	 *
	 * @param BulkImage $image
	 * @param array     $args 'action' plus action arguments; 'next_actions' chains more.
	 * @return \EnableMediaReplace\Model\Bulk\QueueItemResult
	 */
	public function addItemToQueue(BulkImage $image, $args = array())
	{
		$args  = wp_parse_args($args, array('action' => 'remove_background', 'next_actions' => array()));
		$qItem = new QueueItem(array('imageModel' => $image));

		if (is_array($args['next_actions']) && count($args['next_actions']) > 0) {
			$qItem->data()->next_actions = $args['next_actions'];
		}

		$queue = $this->getQueue();

		if ($queue->isItemInQueue($image->get('id'))) {
			$existing = $queue->getItem($image->get('id'));
			if (is_object($existing) && false === $existing->data()->hasAction($args['action'])) {
				$existing->data()->addNextAction($args['action']);
				$queue->updateItem($existing);
				$qItem->addResult(array('is_error' => false, 'is_done' => false, 'message' => __('Action added to the queued item', 'enable-media-replace')));
			} else {
				$qItem->addResult(array('is_error' => false, 'is_done' => true, 'message' => __('This item is already awaiting processing', 'enable-media-replace')));
			}
			return $qItem->result();
		}

		$controller = $qItem->getAPIController($args['action']);
		if (is_null($controller)) {
			$qItem->addResult(array('is_error' => true, 'is_done' => true, 'message' => __('No action found', 'enable-media-replace')));
			return $qItem->result();
		}

		$controller->setCurrentQueue($queue, $this);
		if (false === $controller->checkItem($qItem, $args['action'])) {
			return $qItem->result();
		}

		$status            = $controller->enQueueItem($qItem, $args);
		$this->lastQStatus = $status->qstatus;
		$qItem->addResult(array('message' => sprintf(__('Item %s added to the queue. %d items in the queue', 'enable-media-replace'), $image->getFileName(), $status->numitems)));
		return $qItem->result();
	}

	/**
	 * One processing tick.
	 *
	 * @return \stdClass status, media (tick result), total; or status false with error and message.
	 */
	public function processQueue()
	{
		$queue = $this->getQueue();

		if (false === $queue->isCustomOperation()) {
			$keyManager = ApiKeyManager::getInstance();
			if (false === $keyManager->hasApiKey()) {
				$json          = $this->getJsonResponse();
				$json->status  = false;
				$json->error   = self::APIKEY_FAILED;
				$json->message = __('An API key is required for the bulk', 'enable-media-replace');
				return $json;
			}
			if (false === $this->verifyPlan($keyManager)) {
				$json          = $this->getJsonResponse();
				$json->status  = false;
				$json->error   = self::PLAN_EXPIRED;
				$json->message = __('The bulk needs an active Unlimited plan. Please check your API key at shortpixel.com.', 'enable-media-replace');
				return $json;
			}
		}

		$results         = new \stdClass();
		$results->status = 1;
		$results->media  = $this->runTick($queue);
		$results->total  = (object) array('stats' => $results->media->stats);

		if (isset($results->media->error)) {
			$results->status  = false;
			$results->error   = $results->media->error;
			$results->message = $results->media->message;
		}

		return $this->numberFormatStats($results);
	}

	/**
	 * Stats without a tick, for the bulk screen on load.
	 *
	 * @param bool $formatNumbers
	 * @return \stdClass media and total, each with stats.
	 */
	public function getStartupData($formatNumbers = true)
	{
		$queue = $this->getQueue();

		$data               = new \stdClass();
		$data->media        = new \stdClass();
		$data->media->stats = $queue->getStats();
		$data->total        = (object) array('stats' => clone $data->media->stats);

		return $formatNumbers ? $this->numberFormatStats($data) : $data;
	}

	/**
	 * Runs one tick on the queue and processes the dequeued items.
	 *
	 * @param BulkQueue $Q
	 * @return \stdClass JSON-ready result (see queueToJson()).
	 */
	protected function runTick(BulkQueue $Q)
	{
		$result = $Q->run();
		ResponseController::setQ($Q);

		$items     = (isset($result->items) && is_array($result->items)) ? $result->items : array();
		$stopError = null;

		foreach ($items as $mainIndex => $qItem) {
			$action     = $qItem->data()->action;
			$controller = $qItem->getAPIController($action);
			$item_id    = $qItem->item_id;
			$send       = true;

			if (is_null($controller)) {
				Log::addError('No controller found for this action', $action);
				$qItem->addResult(array('fileStatus' => QueueItem::FILE_STATUS_UNPROCESSED, 'is_error' => true, 'is_done' => true, 'message' => __('No action found', 'enable-media-replace')));
				$Q->itemFailed($qItem, true);
				$send = false;
			} else {
				$controller->setCurrentQueue($Q, $this);
			}

			if (false === $qItem->checkImageModelExists()) {
				$qItem->addResult(array(
					'apiStatus'  => BulkApi::STATUS_NOT_API,
					'message'    => __('File error. The attachment could not be loaded with this ID', 'enable-media-replace'),
					'fileStatus' => QueueItem::FILE_STATUS_ERROR,
					'is_done'    => true,
					'is_error'   => true,
				));
				$Q->itemFailed($qItem, true);
				$send = false;
			} elseif (true === $qItem->block()) {
				$qItem->addResult(array('apiStatus' => BulkApi::STATUS_UNCHANGED, 'message' => __('Item is waiting (blocked)', 'enable-media-replace')));
				Log::addWarn('Encountered a blocked item', $item_id);
				$send = false;
			} else {
				$qItem->addResult(array('filename' => $qItem->imageModel->getFileName()));
				ResponseController::addData($item_id, 'fileName', $qItem->imageModel->getFileName());
			}

			$this->setLastID($item_id);

			if (! is_null($controller) && true === $send) {
				$controller->sendToProcessing($qItem);
				$controller->handleAPIResult($qItem);
			}

			if (true === $qItem->result()->is_error) {
				$this->logBulk($Q, $qItem);
				$error = $qItem->result()->error;
				if (in_array($error, array(self::NOQUOTA, self::APIKEY_FAILED), true)) {
					$stopError = $error;
					delete_transient(self::PLAN_TRANSIENT);
				}
			}

			$result->items[ $mainIndex ] = $qItem->result();
		}

		$result->stats = $Q->getStats();
		$json          = $this->queueToJson($result);

		if (! is_null($stopError)) {
			$json->error   = $stopError;
			$json->message = (self::NOQUOTA === $stopError) ? __('Quota exceeded', 'enable-media-replace') : __('Invalid API key', 'enable-media-replace');
		}

		return $json;
	}

	/**
	 * Whether the key is on an active Unlimited plan; a positive answer is cached.
	 *
	 * @param ApiKeyManager $keyManager
	 * @return bool
	 */
	protected function verifyPlan(ApiKeyManager $keyManager)
	{
		if (false !== get_transient(self::PLAN_TRANSIENT)) {
			return true;
		}
		if (true === $keyManager->verifyUnlimitedPlan()) {
			set_transient(self::PLAN_TRANSIENT, 1, (int) apply_filters('emr/bulk/plan_check_ttl', HOUR_IN_SECONDS));
			return true;
		}
		return false;
	}

	/**
	 * Blank response.
	 *
	 * @return \stdClass
	 */
	protected function getJsonResponse()
	{
		$json          = new \stdClass();
		$json->status  = null;
		$json->result  = null;
		$json->results = null;
		$json->message = null;
		return $json;
	}

	/**
	 * Converts a BulkQueue::run() result into the response object.
	 *
	 * @param \stdClass       $result
	 * @param \stdClass|false $json
	 * @return \stdClass
	 */
	protected function queueToJson($result, $json = false)
	{
		if (! $json) {
			$json = $this->getJsonResponse();
		}

		switch ($result->qstatus) {
			case BulkQueue::RESULT_PREPARING:
				$json->message = sprintf(__('Prepared %s items', 'enable-media-replace'), $result->items);
				break;
			case BulkQueue::RESULT_PREPARING_OVERLIMIT:
				$json->message = sprintf(__('Prepared %s items, but went over the limit', 'enable-media-replace'), $result->items);
				break;
			case BulkQueue::RESULT_PREPARING_DONE:
				$json->message = sprintf(__('Preparing is done, the queue has %s items', 'enable-media-replace'), isset($result->stats) ? $result->stats->total : 0);
				break;
			case BulkQueue::RESULT_EMPTY:
				$json->message = __('Queue returned no active items', 'enable-media-replace');
				break;
			case BulkQueue::RESULT_QUEUE_EMPTY:
				$json->message = __('Queue empty and done', 'enable-media-replace');
				break;
			case BulkQueue::RESULT_ITEMS:
				$json->message = sprintf(__('Fetched %d items', 'enable-media-replace'), count($result->items));
				$json->results = $result->items;
				break;
			case BulkQueue::RESULT_RECOUNT:
				$json->has_error = true;
				$json->message   = __('Bulk preparation seems to be interrupted. Restart the queue or continue without an accurate count', 'enable-media-replace');
				break;
			default:
				$json->message = sprintf(__('Unknown status %s', 'enable-media-replace'), $result->qstatus);
				break;
		}
		$json->qstatus = $result->qstatus;

		if (property_exists($result, 'stats')) {
			$json->stats = $result->stats;
		}
		return $json;
	}

	/**
	 * @param int $item_id
	 * @return void
	 */
	protected function setLastID($item_id)
	{
		self::$lastId = $item_id;
	}

	/**
	 * @return int|null
	 */
	public function getLastQueueStatus()
	{
		return $this->lastQStatus;
	}

	/**
	 * The attachment ID that went through the pipeline last (diagnostics).
	 *
	 * @return int|null
	 */
	public static function getLastId()
	{
		return self::$lastId;
	}

	/**
	 * Resets the queue (plugin activation).
	 *
	 * @return void
	 */
	public static function resetQueues()
	{
		$q = new BulkQueue();
		$q->resetQueue();
	}

	/**
	 * Removes the queue (plugin uninstall).
	 *
	 * @return void
	 */
	public static function uninstallPlugin()
	{
		$q = new BulkQueue();
		$q->uninstall();
	}

	/**
	 * Locale-aware number formatting of every stat value (percentages with two decimals).
	 *
	 * @param \stdClass $results
	 * @return \stdClass
	 */
	private function numberFormatStats($results)
	{
		foreach ($results as $qn => $item) {
			if (is_object($item) && property_exists($item, 'stats') && is_object($item->stats)) {
				foreach ($item->stats as $key => $value) {
					if (is_object($value)) {
						foreach ($value as $key2 => $val2) {
							$value->$key2 = $this->formatNumber($val2, 0);
						}
					} elseif (false !== strpos($key, 'percentage')) {
						$value = $this->formatNumber($value, 2);
					} elseif (is_numeric($value) && ! is_bool($value)) {
						$value = $this->formatNumber($value, 0);
					}
					$results->$qn->stats->$key = $value;
				}
			}
		}
		return $results;
	}

	/**
	 * number_format_i18n() without trailing ".00".
	 *
	 * @param mixed $number
	 * @param int   $precision
	 * @return string
	 */
	private function formatNumber($number, $precision = 2)
	{
		global $wp_locale;
		if (! is_numeric($number)) {
			return $number;
		}
		$decimalpoint = (isset($wp_locale) && isset($wp_locale->number_format['decimal_point'])) ? $wp_locale->number_format['decimal_point'] : '.';
		$formatted    = number_format_i18n((float) $number, $precision);
		$pos          = strpos($formatted, $decimalpoint);
		if (false !== $pos && '00' === substr($formatted, $pos + 1)) {
			$formatted = substr($formatted, 0, $pos);
		}
		return $formatted;
	}

	/**
	 * Appends a failed item to the current bulk log file.
	 *
	 * @param BulkQueue $Q
	 * @param QueueItem $qItem
	 * @return void
	 */
	private function logBulk(BulkQueue $Q, QueueItem $qItem)
	{
		$dir     = BackupController::getBackupDirectory();
		$fileLog = \emr()->filesystem()->getFile($dir->getPath() . BulkController::currentLogName($Q->getOperation()));
		$name    = is_object($qItem->imageModel) ? $qItem->imageModel->getFileName() : '';
		$message = ResponseController::formatQItem($qItem);
		$fileLog->append(date_i18n('Y-m-d H:i:s') . '|' . $name . '| ' . $qItem->item_id . '|' . $message . ';' . PHP_EOL);
	}
}
