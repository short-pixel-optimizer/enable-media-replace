<?php
/**
 * The bulk queue.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\Controller\Bulk\Selector\Selector;
use EnableMediaReplace\InstallHelper;
use EnableMediaReplace\Model\Bulk\BulkImage;
use EnableMediaReplace\Model\Bulk\QueueItem;
use EnableMediaReplace\ShortQ\ShortQ;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * EMR's processing queue on top of the bundled ShortQ module.
 *
 * Port of ShortPixel Image Optimizer's Queue and MediaLibraryQueue folded
 * into one class: EMR has a single queue (slug InstallHelper::QUEUE_SLUG,
 * name InstallHelper::QUEUE_NAME) that runs one bulk operation at a time.
 * The lifecycle is SPIO's: createNewBulk() resets the queue and sets
 * `preparing`; run() then scans the source in pages of `enqueue_limit`
 * and enqueues the items until the source runs dry (RESULT_PREPARING_DONE);
 * startBulk() sets `running`, after which run() dequeues `numitems` per
 * tick until the queue is empty (RESULT_QUEUE_EMPTY). A bulk queue never
 * dequeues on its own: both `bulk_running` and `running` must be set.
 *
 * What a bulk works on is stored in the queue's custom_data as
 * `queueOptions`: `operation` ('background' or 'upscale'), the
 * remove-background colour options, `threshold` (upscale), and optionally
 * `items`, an explicit list of attachment IDs. The source of a prepare
 * page is, in this order: the explicit `items` list when given, the
 * `emr/bulk/source_ids` filter when it returns an array, otherwise the
 * operation's Selector (Selector::forOperation(): WooCommerce product
 * images for the background removal, the media library for the upscale),
 * which keeps its position in custom_data (`selectorCursor`).
 *
 * ShortQ options: numitems 5, mode wait, process_timeout 10000 ms,
 * retry_limit 30, enqueue_limit 200. Option names passed to
 * createNewBulk() override them for that run and are re-applied by every
 * later instance from the stored queue options.
 *
 * Lifecycle: `new` per use; QueueController::getQueue() creates one per
 * request. Hooks:
 * - filter `emr/bulk/queue_options` (array): the ShortQ options.
 * - filter `emr/bulk/source_ids` (null, string operation, BulkQueue): return attachment IDs
 *   for the next prepare page to replace the selector; getSelectorCursor() / setSelectorCursor()
 *   and the enqueue limit tell the callback where to resume. Null keeps the selector.
 * - action `emr/bulk/prepare_items` (int[] ids): before a page of ids is turned into items.
 */
class BulkQueue
{
	/** @var \EnableMediaReplace\ShortQ\Queue\WPQ */
	protected $q;

	/** @var array<int,object|false> Per-request cache of isItemInQueue lookups. */
	protected static $isInQueue = array();

	const RESULT_ITEMS               = 1;
	const RESULT_PREPARING           = 2;
	const RESULT_PREPARING_DONE      = 3;
	const RESULT_EMPTY               = 4;
	const RESULT_PREPARING_OVERLIMIT = 5;
	const RESULT_QUEUE_EMPTY         = 10;
	const RESULT_RECOUNT             = 11;
	const RESULT_ERROR               = -1;
	const RESULT_UNKNOWN             = -10;

	/** The two bulk operations and the restore custom operation. */
	const OPERATION_BACKGROUND = 'background';
	const OPERATION_UPSCALE    = 'upscale';
	const CUSTOM_OP_RESTORE    = 'bulk-restore';

	/** @var string */
	protected $queueName = '';

	/** @var array */
	protected $options = array(
		'numitems'        => 5,
		'mode'            => 'wait',
		'process_timeout' => 10000,
		'retry_limit'     => 30,
		'enqueue_limit'   => 200,
	);

	/** @var float Request start, for the time limit guard in prepareItems(). */
	protected $started;

	/**
	 * @param string $queueName ShortQ queue name, InstallHelper::QUEUE_NAME by default.
	 */
	public function __construct($queueName = InstallHelper::QUEUE_NAME)
	{
		$shortQ          = new ShortQ(InstallHelper::QUEUE_SLUG);
		$this->q         = $shortQ->getQueue($queueName);
		$this->queueName = $queueName;
		$this->started   = microtime(true);

		$this->options = apply_filters('emr/bulk/queue_options', $this->options);
		$this->q->setOptions($this->options);

		// A running bulk may carry its own ShortQ limits (a restore lowers them); re-apply them.
		$stored = $this->getOptions();
		if (is_array($stored)) {
			$overrides = array_intersect_key($stored, $this->options);
			if (count($overrides) > 0) {
				$this->q->setOptions($overrides);
			}
		}
	}

	/**
	 * Queue type, 'media' as in SPIO (there is no custom-folder queue in EMR).
	 *
	 * @return string
	 */
	public function getType()
	{
		return 'media';
	}

	/**
	 * @return string
	 */
	public function getQueueName()
	{
		return $this->queueName;
	}

	/**
	 * @return \EnableMediaReplace\ShortQ\Queue\WPQ
	 */
	public function getShortQ()
	{
		return $this->q;
	}

	/**
	 * Resets the queue, flags it preparing and stores the bulk options.
	 *
	 * @param array $args Bulk options; a `customOp` key becomes customOperation. ShortQ option
	 *                    names (numitems, retry_limit, process_timeout) in $args are applied too.
	 * @return array The stored options.
	 */
	public function createNewBulk($args = array())
	{
		$this->resetQueue();

		$this->q->setStatus('preparing', true, false);
		$this->q->setStatus('finished', false, false);
		$this->q->setStatus('bulk_running', true, true);

		$shortQOptions = array_intersect_key($args, $this->options);
		if (count($shortQOptions) > 0) {
			$this->q->setOptions($shortQOptions);
		}

		$this->setBulkOptions($args);
		return $args;
	}

	/**
	 * Moves the queue from preparing to running.
	 *
	 * @return void
	 */
	public function startBulk()
	{
		$this->q->setStatus('preparing', false, false);
		$this->q->setStatus('running', true, true);
	}

	/**
	 * Removes completed items from storage.
	 *
	 * @return void
	 */
	public function cleanQueue()
	{
		$this->q->cleanQueue();
	}

	/**
	 * Drops every item and every status flag.
	 *
	 * @return void
	 */
	public function resetQueue()
	{
		$this->q->resetQueue();
		self::$isInQueue = array();
	}

	/**
	 * Forwards ShortQ options.
	 *
	 * @param array $options
	 * @return mixed
	 */
	public function setOptions($options)
	{
		return $this->q->setOptions($options);
	}

	/**
	 * Enqueues a prepared QueueItem.
	 *
	 * @param QueueItem $qItem
	 * @return \stdClass qstatus (RESULT_*) and numitems.
	 */
	public function addQueueItem(QueueItem $qItem)
	{
		$this->q->addItems(array($qItem->returnEnqueue()), false);
		$numitems = $this->q->withRemoveDuplicates()->enqueue();

		$result           = new \stdClass();
		$result->qstatus  = $this->getQStatus($numitems);
		$result->numitems = $numitems;

		$this->checkQueueCache($qItem->item_id);
		return $result;
	}

	/**
	 * Removes an item from the queue.
	 *
	 * @param int $item_id
	 * @return void
	 */
	public function dropItem($item_id)
	{
		$this->q->removeItems(array('item_id' => $item_id));
		if (isset(self::$isInQueue[ $item_id ])) {
			unset(self::$isInQueue[ $item_id ]);
		}
	}

	/**
	 * One tick: prepares when preparing, dequeues when running, otherwise reports
	 * the state.
	 *
	 * @return \stdClass qstatus (RESULT_*), items (count while preparing, QueueItem[] after a dequeue).
	 */
	public function run()
	{
		$result          = new \stdClass();
		$result->qstatus = self::RESULT_UNKNOWN;
		$result->items   = null;

		if (true === $this->getStatus('preparing')) {
			$prepared = $this->prepare();

			$result->qstatus = self::RESULT_PREPARING;
			$result->items   = $prepared['items'];
			$result->images  = $prepared['images'];

			if (true === $prepared['overlimit']) {
				$result->qstatus = self::RESULT_PREPARING_OVERLIMIT;
			}
			if (0 === $prepared['items'] && 0 === $prepared['results']) {
				$result->qstatus = self::RESULT_PREPARING_DONE;
			}
		} elseif (true == $this->getStatus('bulk_running')) {
			if (true == $this->getStatus('running')) {
				$items = $this->deQueue();
			} elseif (false == $this->getStatus('finished')) {
				$result->qstatus = self::RESULT_PREPARING_DONE;
			} else {
				$result->qstatus = self::RESULT_QUEUE_EMPTY;
			}
		} else {
			$items = $this->deQueue();
		}

		if (isset($items)) {
			$result->qstatus = $this->getQStatus(count($items));
			$result->items   = $items;
		}

		return $result;
	}

	/**
	 * Prepares the next page of the source: asks the selector for IDs after
	 * `last_item_id` and turns them into queue items.
	 *
	 * @return array items, images, results, overlimit (see prepareItems()).
	 */
	protected function prepare()
	{
		$ids = $this->querySource();
		return $this->prepareItems($ids);
	}

	/**
	 * The next page of attachment IDs, newest first.
	 *
	 * For a restore, the attachments carrying a bulk marker for the operation
	 * are the source (paged by `last_item_id`). Otherwise the explicit `items`
	 * list of the bulk options is paged when one was given, the
	 * `emr/bulk/source_ids` filter may answer, and the operation's Selector
	 * answers last.
	 *
	 * @return int[]
	 */
	protected function querySource()
	{
		$last_id   = (int) $this->getStatus('last_item_id');
		$limit     = (int) $this->q->getOption('enqueue_limit');
		$operation = $this->getOperation();
		$options   = $this->getOptions();

		if ($this->isCustomOperation() && self::CUSTOM_OP_RESTORE === $this->getCustomDataItem('customOperation')) {
			return $this->queryMarkedItems($operation, $last_id, $limit);
		}

		$explicit = (is_array($options) && isset($options['items']) && is_array($options['items'])) ? array_map('intval', $options['items']) : array();
		if (count($explicit) > 0) {
			rsort($explicit);
			$ids = array_filter($explicit, function ($id) use ($last_id) {
				return $id > 0 && ($last_id <= 0 || $id < $last_id);
			});
			return array_values(array_unique(array_slice(array_values($ids), 0, $limit)));
		}

		$ids = apply_filters('emr/bulk/source_ids', null, $operation, $this);
		if (! is_array($ids)) {
			$selector = Selector::forOperation($operation);
			$ids      = is_null($selector) ? array() : $selector->getIds($this);
		}

		return array_values(array_unique(array_map('intval', (array) $ids)));
	}

	/**
	 * The selector's position in its source (a post ID; 0 at the start).
	 *
	 * @return int
	 */
	public function getSelectorCursor()
	{
		$cursor = $this->getCustomDataItem('selectorCursor');
		return is_numeric($cursor) ? (int) $cursor : 0;
	}

	/**
	 * Stores the selector's position; createNewBulk() starts from 0 again.
	 *
	 * @param int $cursor
	 * @return void
	 */
	public function setSelectorCursor($cursor)
	{
		$customData                 = $this->getStatus('custom_data');
		$customData->selectorCursor = (int) $cursor;
		$this->q->setStatus('custom_data', $customData, false);
	}

	/**
	 * Attachments the bulk already processed for $operation, newest first.
	 *
	 * @param string $operation
	 * @param int    $last_id
	 * @param int    $limit
	 * @return int[]
	 */
	protected function queryMarkedItems($operation, $last_id, $limit)
	{
		global $wpdb;
		$sql     = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s";
		$prepare = array(BulkImage::MARKER_META);
		if ($last_id > 0) {
			$sql      .= ' AND post_id < %d';
			$prepare[] = $last_id;
		}
		$sql      .= ' ORDER BY post_id DESC LIMIT %d';
		$prepare[] = $limit;

		$rows = $wpdb->get_results($wpdb->prepare($sql, $prepare));
		$ids  = array();
		foreach ((array) $rows as $row) {
			$marker = maybe_unserialize($row->meta_value);
			if (is_array($marker) && (false === $operation || isset($marker[ $operation ]))) {
				$ids[] = (int) $row->post_id;
			}
		}
		return $ids;
	}

	/**
	 * Turns attachment IDs into queue items for the current operation and
	 * enqueues them.
	 *
	 * Skips attachments that are not images, whose file type the API does not
	 * accept, that the operation already processed (marker), whose file is
	 * already queued under another attachment (WPML / Polylang duplicates
	 * share one file), or that are already in the queue. Stops early when
	 * memory or time runs low (`overlimit`), saving `last_item_id` so the
	 * next tick resumes.
	 *
	 * @param int[] $items
	 * @return array{items: int, images: int, results: int, overlimit: bool}
	 */
	protected function prepareItems($items)
	{
		do_action('emr/bulk/prepare_items', $items);

		$return = array('items' => 0, 'images' => 0, 'results' => 0, 'overlimit' => false);

		if (0 === count($items)) {
			$this->q->setStatus('preparing', false);
			return $return;
		}

		$operation  = $this->getOperation();
		$customOp   = $this->getCustomDataItem('customOperation');
		$options    = $this->getOptions();
		$customData = $this->getStatus('custom_data');
		$queue      = array();
		$files      = array();
		$imageCount = 0;
		$item_id    = 0;

		foreach ($items as $i => $item_id) {
			$image = BulkImage::getById($item_id);
			if (false === $image) {
				ResponseController::addData($item_id, array('is_error' => true, 'issue_type' => ResponseController::ISSUE_QUEUE_FAILED, 'message' => __('Not an image attachment', 'enable-media-replace')));
				continue;
			}

			$qItem = new QueueItem(array('imageModel' => $image));

			if (self::CUSTOM_OP_RESTORE === $customOp) {
				if (false === BackupController::hasBackup($image)) {
					continue;
				}
				$qItem->newRestoreAction();
			} else {
				if (false === $image->isSupportedType() || false === $image->exists()) {
					continue;
				}
				if (true === $image->hasOperation($operation)) {
					continue;
				}
				$path = $image->getFile()->getFullPath();
				if (isset($files[ $path ])) {
					continue; // another attachment in this page shares the file.
				}
				if ($this->isItemInQueue($item_id)) {
					continue;
				}

				if (self::OPERATION_UPSCALE === $operation) {
					$threshold = (is_array($options) && isset($options['threshold'])) ? (int) $options['threshold'] : 0;
					$width     = $image->getWidth();
					if ($threshold > 0 && ($width <= 0 || $width >= $threshold)) {
						continue;
					}
					if (0 === $image->getUpscaleFactor()) {
						continue;
					}
					$qItem->newScaleImageAction(array('scale' => null));
				} else {
					$qItem->newRemoveBackgroundAction(array(
						'do_transparent'       => ! (is_array($options) && isset($options['do_transparent'])) || (bool) $options['do_transparent'],
						'replace_color'        => (is_array($options) && isset($options['replace_color'])) ? $options['replace_color'] : null,
						'replace_transparency' => (is_array($options) && isset($options['replace_transparency'])) ? $options['replace_transparency'] : 0,
					));
				}
				$files[ $path ] = true;
			}

			$queue[] = $qItem->returnEnqueue();
			$imageCount++;
			$this->checkQueueCache($item_id);

			if (true === $this->isOverLimit($i)) {
				Log::addWarn('Bulk prepare: over limit, breaking at index ' . $i);
				$this->q->setStatus('last_item_id', $item_id);
				$return['overlimit'] = true;
				break;
			}
		}

		$this->q->addItems($queue);
		$this->q->enqueue();

		$customData->imageCount = (int) $customData->imageCount + $imageCount;
		$this->q->setStatus('custom_data', $customData, false);
		$this->q->setStatus('last_item_id', $item_id);

		$return['items']   = count($queue);
		$return['images']  = $imageCount;
		$return['results'] = count($items);

		return $return;
	}

	/**
	 * Whether the request is close to its memory or time limit.
	 *
	 * @param int $index Position in the current page (unused, kept for parity with SPIO).
	 * @return bool
	 */
	protected function isOverLimit($index)
	{
		$limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
		if ($limit > 0 && memory_get_usage(true) > 0.9 * $limit) {
			return true;
		}

		$maxTime = (int) ini_get('max_execution_time');
		if ($maxTime > 0 && (microtime(true) - $this->started) > ($maxTime - 5)) {
			return true;
		}
		return false;
	}

	/**
	 * Maps an item count to RESULT_ITEMS, RESULT_EMPTY or RESULT_QUEUE_EMPTY.
	 *
	 * @param int $numitems
	 * @return int
	 */
	protected function getQStatus($numitems)
	{
		if (0 == $numitems) {
			if (0 == $this->getStatus('items') && 0 == $this->getStatus('errors') && 0 == $this->getStatus('in_process')) {
				return self::RESULT_QUEUE_EMPTY;
			}
			return self::RESULT_EMPTY;
		}
		return self::RESULT_ITEMS;
	}

	/**
	 * Progress and counts for the bulk screen.
	 *
	 * @return \stdClass
	 */
	public function getStats()
	{
		$stats               = new \stdClass();
		$stats->is_preparing = (bool) $this->getStatus('preparing');
		$stats->is_running   = (bool) $this->getStatus('running');
		$stats->is_finished  = (bool) $this->getStatus('finished');
		$stats->in_queue     = (int) $this->getStatus('items');
		$stats->in_process   = (int) $this->getStatus('in_process');
		$stats->awaiting     = $stats->in_queue + $stats->in_process;
		$stats->errors       = (int) $this->getStatus('errors');
		$stats->fatal_errors = (int) $this->getStatus('fatal_errors');
		$stats->done         = (int) $this->getStatus('done');
		$stats->bulk_running = (bool) $this->getStatus('bulk_running');
		$stats->operation    = $this->getOperation();

		if ($this->isCustomOperation()) {
			$stats->customOperation   = $this->getCustomDataItem('customOperation');
			$stats->isCustomOperation = '10';
		}

		$stats->total = $stats->in_queue + $stats->fatal_errors + $stats->errors + $stats->done + $stats->in_process;
		$stats->percentage_done = ($stats->total > 0) ? round((100 / $stats->total) * ($stats->done + $stats->fatal_errors), 0, PHP_ROUND_HALF_DOWN) : 100;

		if (! $stats->is_running) {
			$stats->images = $this->countQueue();
		}

		return $stats;
	}

	/**
	 * Recounts the items by status.
	 *
	 * @return \stdClass images, images_done, images_inprocess, images_prepared.
	 */
	protected function countQueue()
	{
		$recount    = $this->q->itemSum('countbystatus');
		$customData = $this->getStatus('custom_data');

		$count = (object) array(
			'images'           => isset($recount[ ShortQ::QSTATUS_WAITING ]) ? (int) $recount[ ShortQ::QSTATUS_WAITING ] : 0,
			'images_done'      => isset($recount[ ShortQ::QSTATUS_DONE ]) ? (int) $recount[ ShortQ::QSTATUS_DONE ] : 0,
			'images_inprocess' => isset($recount[ ShortQ::QSTATUS_INPROCESS ]) ? (int) $recount[ ShortQ::QSTATUS_INPROCESS ] : 0,
			'images_prepared'  => is_object($customData) ? (int) $customData->imageCount : 0,
		);
		return $count;
	}

	/**
	 * The options the bulk was started with.
	 *
	 * @return array|false False before any bulk was created.
	 */
	public function getOptions()
	{
		return $this->getCustomDataItem('queueOptions');
	}

	/**
	 * The bulk operation, 'background' when none was stored.
	 *
	 * @return string
	 */
	public function getOperation()
	{
		$options = $this->getOptions();
		if (is_array($options) && isset($options['operation']) && in_array($options['operation'], array(self::OPERATION_BACKGROUND, self::OPERATION_UPSCALE), true)) {
			return $options['operation'];
		}
		return self::OPERATION_BACKGROUND;
	}

	/**
	 * A ShortQ status value; custom_data is returned as an object with defaults.
	 *
	 * @param string|false $name
	 * @return mixed
	 */
	protected function getStatus($name = false)
	{
		if ('custom_data' === $name || 'options' === $name) {
			$customData = $this->q->getStatus('custom_data');
			if (! is_object($customData)) {
				$customData = $this->createCustomData();
			}
			return $customData;
		}
		return $this->q->getStatus($name);
	}

	/**
	 * Stores the bulk options in custom_data; a `customOp` key sets customOperation.
	 *
	 * @param array $options
	 * @return false|void False when $options is empty.
	 */
	public function setBulkOptions($options = array())
	{
		if (0 === count($options)) {
			return false;
		}

		$customData = $this->getStatus('custom_data');

		if (isset($options['customOp'])) {
			$customData->customOperation = $options['customOp'];
			unset($options['customOp']);
		}

		$customData->queueOptions = (is_array($options) && count($options) > 0) ? $options : array();
		$this->q->setStatus('custom_data', $customData);
	}

	/**
	 * Whether a custom operation (restore) rather than an API operation runs.
	 *
	 * @return bool
	 */
	public function isCustomOperation()
	{
		$op = $this->getCustomDataItem('customOperation');
		return (false !== $op && ! is_null($op) && '' !== $op);
	}

	/**
	 * One property of custom_data, false when absent.
	 *
	 * @param string $name
	 * @return mixed
	 */
	public function getCustomDataItem($name)
	{
		$customData = $this->getStatus('custom_data');
		if (is_object($customData) && property_exists($customData, $name)) {
			return $customData->$name;
		}
		return false;
	}

	/**
	 * Pulls the next batch and converts the rows to QueueItems.
	 *
	 * @return QueueItem[]
	 */
	protected function deQueue()
	{
		$items = $this->q->deQueue();
		return array_map(array($this, 'queueToMediaItem'), $items);
	}

	/**
	 * Converts a ShortQ row into a QueueItem (image bound when it still exists).
	 *
	 * @param object $qItem
	 * @return QueueItem
	 */
	protected function queueToMediaItem($qItem)
	{
		$image = BulkImage::getById($qItem->item_id);
		$item  = (false !== $image) ? new QueueItem(array('imageModel' => $image)) : new QueueItem(array('item_id' => $qItem->item_id));

		$value = $qItem->value;
		if (is_array($value) || is_object($value)) {
			$item->setFromQueueData($value);
		}
		$item->setData('tries', $qItem->tries);
		$item->setData('queue_list_order', $qItem->list_order);
		$item->data()->addKeepDataArgs('queue_list_order');
		$item->set('queueItem', $qItem);
		return $item;
	}

	/**
	 * A queue row by attachment ID as a QueueItem.
	 *
	 * @param int $item_id
	 * @return QueueItem|false
	 */
	public function getItem($item_id)
	{
		$itemObj = $this->q->getItem($item_id);
		if (! is_object($itemObj)) {
			return false;
		}
		return $this->queueToMediaItem($itemObj);
	}

	/**
	 * Whether the attachment is waiting or in process in the queue (cached per request).
	 *
	 * @param int $item_id
	 * @return bool
	 */
	public function isItemInQueue($item_id)
	{
		if (isset(self::$isInQueue[ $item_id ])) {
			$itemObj = self::$isInQueue[ $item_id ];
		} else {
			$itemObj = $this->q->getItem($item_id);
			if (is_object($itemObj)) {
				self::$isInQueue[ $item_id ] = $itemObj;
			}
		}

		$notQ = array(ShortQ::QSTATUS_DONE, ShortQ::QSTATUS_FATAL);
		return is_object($itemObj) && ! in_array((int) floor($itemObj->status), $notQ, true);
	}

	/**
	 * Drops a stale "not in queue" cache entry after an enqueue.
	 *
	 * @param int $item_id
	 * @return void
	 */
	protected function checkQueueCache($item_id)
	{
		if (isset(self::$isInQueue[ $item_id ]) && false === self::$isInQueue[ $item_id ]) {
			unset(self::$isInQueue[ $item_id ]);
		}
	}

	/**
	 * Marks an item failed (fatal: counted as such) and saves its data.
	 *
	 * @param QueueItem $qItem
	 * @param bool      $fatal
	 * @return void
	 */
	public function itemFailed(QueueItem $qItem, $fatal = false)
	{
		if ($fatal) {
			Log::addError('Bulk item failed', $qItem->result()->forReturn());
		}
		$item = $qItem->getQueueItem();
		if (false === $item) {
			return;
		}
		$this->q->itemFailed($item, $fatal);
		$this->q->updateItemValue($item);
	}

	/**
	 * Saves the item's data back to its row.
	 *
	 * @param QueueItem $item
	 * @return void
	 */
	public function updateItem(QueueItem $item)
	{
		$qItem = $item->getQueueItem();
		if (false !== $qItem) {
			$this->q->updateItemValue($qItem);
		}
	}

	/**
	 * Marks an item done.
	 *
	 * @param QueueItem $item
	 * @return void
	 */
	public function itemDone(QueueItem $item)
	{
		$qItem = $item->getQueueItem();
		if (false !== $qItem) {
			$this->q->itemDone($qItem);
		}
		if (isset(self::$isInQueue[ $item->item_id ])) {
			unset(self::$isInQueue[ $item->item_id ]);
		}
	}

	/**
	 * Removes this queue on uninstall (rows, status option, table when empty).
	 *
	 * @return void
	 */
	public function uninstall()
	{
		$this->q->unInstall();
	}

	/**
	 * Fresh custom_data.
	 *
	 * @return \stdClass
	 */
	private function createCustomData()
	{
		$data                  = new \stdClass();
		$data->imageCount      = 0;
		$data->customOperation = false;
		$data->selectorCursor  = 0;
		return $data;
	}
}
