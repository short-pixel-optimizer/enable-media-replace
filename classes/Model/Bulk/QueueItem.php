<?php
/**
 * One slot of the bulk queue.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Model\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\Controller\Bulk\ActionController;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Binds an attachment (BulkImage) to a pending action, the data used to
 * process it (QueueItemData) and the result of that processing
 * (QueueItemResult).
 *
 * Port of ShortPixel Image Optimizer's QueueItem, reduced to the three EMR
 * bulk actions: `remove_background`, `scale_image` and `restore`. Only
 * `data` is persistent: returnEnqueue() serialises it into the ShortQ row;
 * the image and the result are rebuilt on every pull from the queue.
 *
 * Each `new*Action()` calls newAction() first, which wipes the result and
 * resets the data object while keeping queued next actions and the fields
 * listed in next_keepdata. getAPIController() returns the controller that
 * processes the current action.
 *
 * Fires and listens to no hooks.
 */
class QueueItem
{
	/** File status codes, same values as ShortPixel Image Optimizer's ImageModel. */
	const FILE_STATUS_ERROR = -1;
	const FILE_STATUS_UNPROCESSED = 0;
	const FILE_STATUS_PENDING = 1;
	const FILE_STATUS_SUCCESS = 2;

	/** @var BulkImage|null */
	protected $imageModel;

	/** @var int|null Attachment ID, also the queue slot's identity. */
	protected $item_id;

	/** @var object|null Raw row handed back by ShortQ. */
	protected $queueItem;

	/** @var QueueItemResult|null Created lazily by result(). */
	protected $result;

	/** @var QueueItemData The persistent data; the only field written back to the queue. */
	protected $data;

	/** @var int|null Credit count of the item (always 1 for the bulk actions). */
	protected $item_count;

	/**
	 * Accepts a bound BulkImage or a bare item_id.
	 *
	 * @param array{imageModel?: BulkImage, item_id?: int} $args
	 */
	public function __construct($args = array())
	{
		if (isset($args['imageModel']) && is_object($args['imageModel'])) {
			$this->setModel($args['imageModel']);
		} elseif (isset($args['item_id']) && is_numeric($args['item_id'])) {
			$this->item_id = intval($args['item_id']);
		}
		$this->data = new QueueItemData();
	}

	/**
	 * Binds the image and takes its ID.
	 *
	 * @param BulkImage $imageModel
	 * @return void
	 */
	public function setModel(BulkImage $imageModel)
	{
		$this->imageModel = $imageModel;
		$this->item_id    = $imageModel->get('id');
	}

	/**
	 * Fills the data object from a ShortQ row value.
	 *
	 * The row stores the data as a JSON object, so arrays (paramlist, urls,
	 * next_actions, ...) come back as stdClass; they are converted to arrays
	 * again, except `counts`, which is an object by design.
	 *
	 * @param object|array $data
	 * @return void
	 */
	public function setFromQueueData($data)
	{
		foreach ($data as $name => $value) {
			if (is_object($value) && 'counts' !== $name) {
				$value = json_decode(json_encode($value), true);
			}
			$this->setData($name, $value);
		}
	}

	/**
	 * Writes one field of the persistent data.
	 *
	 * @param string $name
	 * @param mixed  $value
	 * @return void
	 */
	public function setData($name, $value)
	{
		$this->data->$name = $value;
	}

	/**
	 * Without argument returns the block flag; with one, sets it.
	 *
	 * @param bool|null $block
	 * @return bool|void
	 */
	public function block($block = null)
	{
		if (is_null($block)) {
			return $this->data->block;
		}
		$this->setData('block', (bool) $block);
	}

	/**
	 * @return QueueItemData
	 */
	public function data()
	{
		return $this->data;
	}

	/**
	 * The result object, created on first use.
	 *
	 * @return QueueItemResult
	 */
	public function result()
	{
		if (is_null($this->result)) {
			$this->result = new QueueItemResult($this->item_id);
		}
		return $this->result;
	}

	/**
	 * Sets a declared property; unknown names are ignored.
	 *
	 * @param string $name
	 * @param mixed  $value
	 * @return void
	 */
	public function set($name, $value)
	{
		if (property_exists($this, $name)) {
			$this->$name = $value;
		}
	}

	/**
	 * The ShortQ row with the current data as its value, or false when the item
	 * did not come from the queue (direct action).
	 *
	 * @return object|false
	 */
	public function getQueueItem()
	{
		if (is_null($this->queueItem)) {
			return false;
		}
		$qItem        = $this->queueItem;
		$qItem->value = $this->data->toObject();
		return $qItem;
	}

	/**
	 * Reads a declared property; null for unknown names.
	 *
	 * @param string $name
	 * @return mixed
	 */
	public function __get($name)
	{
		if (property_exists($this, $name)) {
			return $this->$name;
		}
		return null;
	}

	/**
	 * The array ShortQ's addItems() expects.
	 *
	 * @return array{id: int, value: object, item_count: mixed, order?: mixed}
	 */
	public function returnEnqueue()
	{
		$enqueue = array(
			'id'         => $this->item_id,
			'value'      => $this->data->toObject(),
			'item_count' => $this->item_count,
		);
		if (! is_null($this->data->queue_list_order)) {
			$enqueue['order'] = $this->data->queue_list_order;
		}
		return $enqueue;
	}

	/**
	 * Merges result fields through QueueItemResult's mutator.
	 *
	 * @param array<string,mixed> $data
	 * @return void
	 */
	public function addResult($data = array())
	{
		foreach ($data as $name => $value) {
			$this->result()->$name = $value;
		}
	}

	/**
	 * Resets result and data before a new action, keeping queued next actions
	 * and the preserved data.
	 *
	 * @return void
	 */
	protected function newAction()
	{
		$this->result = new QueueItemResult($this->item_id);

		$nextActions = $this->data()->hasNextAction() ? $this->data()->next_actions : null;
		$keepDataArgs = $this->data()->getKeepDataArgs();
		$next_keepdata = $this->data()->next_keepdata;

		$this->data = new QueueItemData();

		if (! is_null($nextActions)) {
			$this->data()->next_actions = $nextActions;
		}
		if (count($keepDataArgs) > 0) {
			$this->data()->next_keepdata = $next_keepdata;
			foreach ($keepDataArgs as $name => $value) {
				$this->data()->$name = $value;
			}
		}
	}

	/**
	 * Schedules a background removal.
	 *
	 * `bg_remove` is 1 for a transparent background, otherwise the colour
	 * followed by the two-digit alpha (100 becomes FF), the same encoding
	 * Api::request() uses for the single flow. `refresh` asks the API to
	 * drop its cache for the URL on the first send (the single flow does the
	 * same), so a second run on a changed file is not served the old result.
	 *
	 * @param array{do_transparent?: bool, replace_color?: string|null, replace_transparency?: int|string, refresh?: bool} $args
	 * @return void
	 */
	public function newRemoveBackgroundAction($args = array())
	{
		$this->newAction();

		$defaults = array(
			'do_transparent'       => true,
			'replace_color'        => null,
			'replace_transparency' => 0,
			'refresh'              => true,
		);
		$args = wp_parse_args($args, $defaults);

		$paramlist = array();
		if (true === $args['do_transparent'] || is_null($args['replace_color'])) {
			$paramlist['bg_remove'] = 1;
		} else {
			$paramlist['bg_remove'] = $args['replace_color'];
			$transparency = intval($args['replace_transparency']);
			if ($transparency >= 0 && $transparency <= 100) {
				$alpha = (100 === $transparency) ? 'FF' : str_pad((string) $transparency, 2, '0', STR_PAD_LEFT);
				$paramlist['bg_remove'] .= $alpha;
			}
		}
		$paramlist['refresh'] = (bool) $args['refresh'];

		$this->data->action          = 'remove_background';
		$this->data->compressionType = 0; // lossless: the result carries alpha.
		$this->data->urls            = array($this->imageModel->getUrl());
		$this->data->paramlist       = $paramlist;
		$this->data->tries           = 0;
		$this->data->source_width    = $this->imageModel->getWidth();
		$this->item_count            = 1;
	}

	/**
	 * Schedules an upscale.
	 *
	 * @param array{scale?: int|null, refresh?: bool} $args `scale` is 2, 3 or 4; null picks the
	 *                                                    largest factor the image width allows.
	 * @return void
	 */
	public function newScaleImageAction($args = array())
	{
		$this->newAction();

		$defaults = array(
			'scale'   => null,
			'refresh' => true,
		);
		$args  = wp_parse_args($args, $defaults);
		$scale = is_null($args['scale']) ? $this->imageModel->getUpscaleFactor() : intval($args['scale']);

		$paramlist = array(
			'upscale' => $scale,
			'refresh' => (bool) $args['refresh'],
		);

		$this->data->action          = 'scale_image';
		$this->data->compressionType = 0;
		$this->data->urls            = array($this->imageModel->getUrl());
		$this->data->paramlist       = $paramlist;
		$this->data->tries           = 0;
		$this->data->source_width    = $this->imageModel->getWidth();
		$this->item_count            = 1;
	}

	/**
	 * Schedules a restore from the bulk backup. No API call is involved.
	 *
	 * @return void
	 */
	public function newRestoreAction()
	{
		$this->newAction();

		$this->data->action = 'restore';
		$this->data->tries  = 0;
		$this->item_count   = 1;
	}

	/**
	 * The controller that processes an action. EMR has one for the three
	 * bulk actions; unknown actions return null.
	 *
	 * @param string|null $action Defaults to the current action.
	 * @return ActionController|null
	 */
	public function getAPIController($action = null)
	{
		if (is_null($action)) {
			$action = $this->data()->action;
		}
		switch ($action) {
			case 'remove_background':
			case 'scale_image':
			case 'restore':
				return ActionController::getInstance();
		}
		return null;
	}

	/**
	 * Whether an image is bound.
	 *
	 * @return bool
	 */
	public function checkImageModelExists()
	{
		return is_object($this->imageModel);
	}
}
