<?php
/**
 * Per-item response registry for the bulk.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\Model\Bulk\QueueItem;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Request-scoped registry of per-item response data and the formatter of
 * the messages the bulk screen and the log show.
 *
 * Port of ShortPixel Image Optimizer's ResponseController in BULK output
 * mode only. All methods are static; the data lives in the static $items
 * array keyed by item ID for the duration of the request. setQ() primes the
 * queue context (name, retry limit).
 *
 * Fires and listens to no hooks.
 */
class ResponseController
{
	/** Issue types. */
	const ISSUE_BACKUP_CREATE = 10;
	const ISSUE_QUEUE_FAILED  = 13;
	const ISSUE_API           = 50;
	const ISSUE_QUOTA         = 100;

	/** @var array<int,\stdClass> item_id => response data. */
	protected static $items = array();

	/** @var string|null */
	protected static $queueName;

	/** @var int|null */
	protected static $queueMaxTries;

	/** Fields a response item may hold. */
	protected static $fields = array('item_id', 'fileName', 'is_error', 'is_done', 'apiStatus', 'fileStatus', 'tries', 'issue_type', 'message', 'action');

	/**
	 * Primes the controller with the active queue.
	 *
	 * @param BulkQueue $q
	 * @return void
	 */
	public static function setQ($q)
	{
		self::$queueName     = $q->getQueueName();
		self::$queueMaxTries = $q->getShortQ()->getOption('retry_limit');
	}

	/**
	 * Returns (creating when needed) the response data of an item.
	 *
	 * @param int $item_id
	 * @return \stdClass
	 */
	public static function getResponseItem($item_id)
	{
		if (! isset(self::$items[ $item_id ])) {
			$item = new \stdClass();
			foreach (self::$fields as $field) {
				$item->$field = null;
			}
			$item->item_id = $item_id;
			self::$items[ $item_id ] = $item;
		}
		return self::$items[ $item_id ];
	}

	/**
	 * Attaches data to an item: a name/value pair or an array of them. Unknown
	 * names are ignored.
	 *
	 * @param int          $item_id
	 * @param string|array $name
	 * @param mixed        $value
	 * @return void
	 */
	public static function addData($item_id, $name, $value = null)
	{
		if (! is_numeric($item_id)) {
			Log::addWarn('ResponseController: first parameter should be the item id', $name);
		}
		$data = (is_array($name) || is_object($name)) ? (array) $name : array($name => $value);
		$item = self::getResponseItem($item_id);
		foreach ($data as $prop => $val) {
			if (in_array($prop, self::$fields, true)) {
				$item->$prop = $val;
			}
		}
	}

	/**
	 * Drops every stored item (tests, or between ticks).
	 *
	 * @return void
	 */
	public static function reset()
	{
		self::$items = array();
	}

	/**
	 * Merges a QueueItem's result into its response data and returns the message.
	 *
	 * @param QueueItem $queueItem
	 * @return string
	 */
	public static function formatQItem(QueueItem $queueItem)
	{
		$result = $queueItem->result();
		$item   = self::getResponseItem($queueItem->item_id);

		foreach ($result->forReturn() as $name => $value) {
			if (in_array($name, self::$fields, true)) {
				$item->$name = $value;
			}
		}
		if (! is_null($queueItem->data()->action)) {
			$item->action = $queueItem->data()->action;
		}
		if (is_object($queueItem->imageModel)) {
			$item->fileName = $queueItem->imageModel->getFileName();
		}

		return self::formatItem($queueItem->item_id);
	}

	/**
	 * The message for an item, error or regular.
	 *
	 * @param int $item_id
	 * @return string
	 */
	public static function formatItem($item_id)
	{
		$item = self::getResponseItem($item_id);
		$text = (string) $item->message;

		if ($item->is_error) {
			return self::formatErrorItem($item, $text);
		}
		return self::formatRegularItem($item, $text);
	}

	/**
	 * Appends the attachment ID to error messages from the API or the file system.
	 *
	 * @param \stdClass $item
	 * @param string    $text
	 * @return string
	 */
	private static function formatErrorItem($item, $text)
	{
		if (self::ISSUE_BACKUP_CREATE === $item->issue_type && ! empty($item->fileName)) {
			$text .= sprintf(__(' - file %s', 'enable-media-replace'), $item->fileName);
		}
		if (QueueItem::FILE_STATUS_ERROR === $item->fileStatus || BulkApi::STATUS_FAIL === $item->apiStatus) {
			$text .= sprintf(__(' (Attachment ID %d)', 'enable-media-replace'), $item->item_id);
		}
		return $text;
	}

	/**
	 * Status-appropriate text for an item without error.
	 *
	 * @param \stdClass $item
	 * @param string    $text
	 * @return string
	 */
	private static function formatRegularItem($item, $text)
	{
		if (! $item->is_done && BulkApi::STATUS_UNCHANGED === $item->apiStatus) {
			$text = __('Processing - waiting for results', 'enable-media-replace');
		}
		if (! $item->is_done && BulkApi::STATUS_ENQUEUED === $item->apiStatus) {
			$text = __('Processing - item has been sent to ShortPixel', 'enable-media-replace');
		}

		switch ($item->apiStatus) {
			case BulkApi::STATUS_SUCCESS:
				$text = ('scale_image' === $item->action) ? __('Image upscaled', 'enable-media-replace') : __('Background removed', 'enable-media-replace');
				break;
			case BulkApi::STATUS_NOT_API:
				$text = sprintf(__('%s completed for %s', 'enable-media-replace'), ucfirst((string) $item->action), (string) $item->fileName);
				break;
		}
		return $text;
	}
}
