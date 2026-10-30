<?php
/**
 * Processes the bulk queue items.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\Controller\ReplaceController;
use EnableMediaReplace\Model\Bulk\BulkImage;
use EnableMediaReplace\Model\Bulk\QueueItem;
use EnableMediaReplace\Model\Bulk\QueueItemResult;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Runs a queue item's action: sends it to the API, applies the result to
 * the attachment, or restores the attachment from its backup.
 *
 * Port of ShortPixel Image Optimizer's OptimizerBase plus the action path
 * of its OptimizeController (sendToProcessing(), handleAPIResult(),
 * handleAction()), with one important difference: a finished item is
 * always closed with finishItemProcess(), so ShortQ never retries an item
 * whose result was already applied.
 *
 * Applying a result means: download the result image, back up the
 * current main file (BackupController), then replace the file in place
 * through ReplaceController with the same parameters the single
 * remove-background and upscale flows use (MODE_REPLACE, modified date
 * updated), so thumbnails are regenerated and `wp_handle_replace`,
 * `enable-media-replace-upload-done` and the offload hooks fire as in a
 * manual replace. The attachment then carries the `_emr_bulk` marker.
 *
 * A restore copies the backup over the main file the same way and removes
 * the marker and the backup.
 *
 * Lifecycle: getInstance() singleton; QueueController sets the active
 * queue with setCurrentQueue() before every tick. Hooks:
 * - action `emr/bulk/item/done` (BulkImage, action, QueueItem): after a result was applied or a restore completed.
 * - action `emr/bulk/item/failed` (QueueItem): after a terminal failure.
 */
class ActionController
{
	/** @var static[] */
	protected static $instances = array();

	/** @var BulkApi */
	protected $api;

	/** @var BulkQueue|null */
	protected $currentQueue;

	/** @var QueueController|null */
	protected $queueController;

	/** @var QueueItem[]|null Items blocked while their result is applied, unblocked at shutdown. */
	protected static $blockedItems;

	/** @var bool */
	public $shutdown_registered = false;

	public function __construct()
	{
		$this->api = BulkApi::getInstance();
	}

	/**
	 * @return static
	 */
	public static function getInstance()
	{
		$calledClass = get_called_class();
		if (! isset(static::$instances[ $calledClass ])) {
			static::$instances[ $calledClass ] = new $calledClass();
		}
		return static::$instances[ $calledClass ];
	}

	/**
	 * The API client.
	 *
	 * @return BulkApi
	 */
	public function api()
	{
		return $this->api;
	}

	/**
	 * Sets the queue (and its controller) the next items belong to.
	 *
	 * @param BulkQueue       $queue
	 * @param QueueController $queueController
	 * @return void
	 */
	public function setCurrentQueue($queue, $queueController)
	{
		$this->currentQueue    = $queue;
		$this->queueController = $queueController;
	}

	/**
	 * The active queue, created when none was set.
	 *
	 * @return BulkQueue
	 */
	protected function getCurrentQueue()
	{
		if (is_null($this->currentQueue)) {
			$this->currentQueue = $this->getQueueController()->getQueue();
		}
		return $this->currentQueue;
	}

	/**
	 * @return QueueController
	 */
	protected function getQueueController()
	{
		if (is_null($this->queueController)) {
			$this->queueController = new QueueController();
		}
		return $this->queueController;
	}

	/**
	 * Checks that an image is bound; records a terminal error otherwise.
	 *
	 * @param QueueItem $qItem
	 * @return bool
	 */
	protected function checkImageModel(QueueItem $qItem)
	{
		if (false === $qItem->checkImageModelExists()) {
			$qItem->addResult(array(
				'message'    => __('File error. The attachment could not be loaded with this ID', 'enable-media-replace'),
				'apiStatus'  => BulkApi::STATUS_NOT_API,
				'fileStatus' => QueueItem::FILE_STATUS_ERROR,
				'is_done'    => true,
				'is_error'   => true,
			));
			return false;
		}
		return true;
	}

	/**
	 * Whether the item can be processed for its action. Records the reason on
	 * the result when not.
	 *
	 * @param QueueItem $qItem
	 * @param string    $action remove_background, scale_image or restore.
	 * @return bool
	 */
	public function checkItem(QueueItem $qItem, $action)
	{
		if (false === $this->checkImageModel($qItem)) {
			return false;
		}
		$image  = $qItem->imageModel;
		$reason = false;

		if ('restore' === $action) {
			if (false === BackupController::hasBackup($image)) {
				$reason = __('No bulk backup exists for this image', 'enable-media-replace');
			}
		} else {
			if (false === $image->exists()) {
				$reason = __('The image file does not exist', 'enable-media-replace');
			} elseif (false === $image->isSupportedType()) {
				$reason = __('Only JPEG and PNG images can be processed', 'enable-media-replace');
			} elseif ('scale_image' === $action && 0 === $image->getUpscaleFactor()) {
				$reason = __('The image is too wide to be upscaled', 'enable-media-replace');
			}
		}

		if (false !== $reason) {
			$qItem->addResult(array(
				'message'    => $reason,
				'is_error'   => true,
				'is_done'    => true,
				'fileStatus' => QueueItem::FILE_STATUS_ERROR,
			));
			return false;
		}
		return true;
	}

	/**
	 * Prepares and enqueues one item for an action.
	 *
	 * @param QueueItem $qItem
	 * @param array     $args 'action' plus the action's own arguments.
	 * @return \stdClass qstatus and numitems, as BulkQueue::addQueueItem() returns.
	 */
	public function enQueueItem(QueueItem $qItem, $args = array())
	{
		$queue  = $this->getCurrentQueue();
		$action = isset($args['action']) ? $args['action'] : 'remove_background';

		switch ($action) {
			case 'scale_image':
				$qItem->newScaleImageAction($args);
				break;
			case 'restore':
				$qItem->newRestoreAction();
				break;
			default:
				$qItem->newRemoveBackgroundAction($args);
				break;
		}

		return $queue->addQueueItem($qItem);
	}

	/**
	 * Sends the item to the API, or runs the restore locally.
	 *
	 * @param QueueItem $qItem
	 * @return void
	 */
	public function sendToProcessing(QueueItem $qItem)
	{
		$action = $qItem->data()->action;

		if ('remove_background' === $action || 'scale_image' === $action) {
			$this->api->processActionItem($qItem);
			return;
		}

		if ('restore' === $action) {
			$this->restoreItem($qItem);
			return;
		}

		$qItem->addResult($this->api->returnFailure(BulkApi::STATUS_FAIL, __('Unknown bulk action', 'enable-media-replace')));
	}

	/**
	 * Interprets the result stored by sendToProcessing(): maps errors for the
	 * browser, applies a successful result, finishes or fails the queue item,
	 * and writes the message.
	 *
	 * @param QueueItem $qItem
	 * @return bool|void False when no image is bound.
	 */
	public function handleAPIResult(QueueItem $qItem)
	{
		$q       = $this->getCurrentQueue();
		$item_id = $qItem->item_id;
		$action  = $qItem->data()->action;

		if (false === $this->checkImageModel($qItem)) {
			$q->itemFailed($qItem, true);
			return false;
		}
		$image = $qItem->imageModel;

		$qItem->addResult(array('filename' => $image->getFileName(), 'action' => $action));
		ResponseController::addData($item_id, 'fileName', $image->getFileName());

		if (true === $qItem->result()->is_error) {
			$apistatus = $qItem->result()->apiStatus;
			$error_code = null;

			if (BulkApi::STATUS_ERROR === $apistatus) {
				$qItem->addResult(array('fileStatus' => QueueItem::FILE_STATUS_ERROR));
			} elseif (BulkApi::STATUS_QUOTA_EXCEEDED === $apistatus) {
				$error_code = QueueController::NOQUOTA;
			} elseif (BulkApi::STATUS_NO_KEY === $apistatus) {
				$error_code = QueueController::APIKEY_FAILED;
			} elseif (BulkApi::STATUS_QUEUE_FULL === $apistatus || BulkApi::STATUS_MAINTENANCE === $apistatus) {
				$error_code = QueueController::SERVER_ERROR;
			}

			if (! is_null($error_code)) {
				$qItem->addResult(array('error' => $error_code, 'is_error' => true));
			}

			ResponseController::addData($item_id, array('is_error' => true, 'message' => $qItem->result()->message));

			if ($qItem->result()->is_done) {
				$q->itemFailed($qItem, true);
				do_action('emr/bulk/item/failed', $qItem);
				ResponseController::addData($item_id, 'is_done', true);
			}
		} else {
			if ('restore' === $action) {
				// restoreItem() already applied the backup; is_done is set there.
				if (true === $qItem->result()->is_done) {
					$this->finishItemProcess($qItem);
				}
			} else {
				$this->handleAction($qItem);
			}
		}

		ResponseController::addData($item_id, array(
			'is_error'  => $qItem->result()->is_error,
			'is_done'   => $qItem->result()->is_done,
			'apiStatus' => $qItem->result()->apiStatus,
			'tries'     => $qItem->data()->tries,
		));
		if (! is_null($qItem->result()->fileStatus)) {
			ResponseController::addData($item_id, 'fileStatus', $qItem->result()->fileStatus);
		}

		$responseMessage = ResponseController::formatQItem($qItem);
		if (false !== $responseMessage && strlen($responseMessage) > 0) {
			$qItem->addResult(array('message' => $responseMessage));
		}
	}

	/**
	 * Applies a remove-background or upscale result.
	 *
	 * Done with success: download, back up, replace in place, mark, finish.
	 * Not done and waiting: keep pending; past the queue's retry limit the item
	 * fails with ERR_TIMEOUT.
	 *
	 * @param QueueItem $qItem
	 * @return void
	 */
	protected function handleAction(QueueItem $qItem)
	{
		$q       = $this->getCurrentQueue();
		$item_id = $qItem->item_id;
		$result  = $qItem->result();

		if (true === $result->is_done) {
			if (BulkApi::STATUS_SUCCESS === $result->apiStatus) {
				$applied = $this->applyResult($qItem);
				if (true === $applied) {
					$qItem->addResult(array('fileStatus' => QueueItem::FILE_STATUS_SUCCESS));
					$this->addPreview($qItem);
					do_action('emr/bulk/item/done', $qItem->imageModel, $qItem->data()->action, $qItem);
				}
			} else {
				Log::addWarn('Bulk item done without success status', $result->forReturn());
				$qItem->addResult(array('is_error' => true, 'apiStatus' => BulkApi::STATUS_FAIL));
			}

			if ($qItem->result()->is_error) {
				$q->itemFailed($qItem, true);
				do_action('emr/bulk/item/failed', $qItem);
			} else {
				$this->finishItemProcess($qItem);
			}
			return;
		}

		if (BulkApi::STATUS_UNCHANGED === $result->apiStatus || BulkApi::STATUS_ENQUEUED === $result->apiStatus) {
			$qItem->addResult(array('fileStatus' => QueueItem::FILE_STATUS_PENDING));
			$retry_limit = (int) $q->getShortQ()->getOption('retry_limit');
			$tries       = (int) $qItem->data()->tries;

			if ($retry_limit === $tries || $retry_limit === ($tries - 1)) {
				$message = __('Retry limit reached. The image might be too large, the limit too low or there are network issues.', 'enable-media-replace');
				ResponseController::addData($item_id, array('message' => $message, 'is_error' => true, 'is_done' => true));
				$qItem->addResult(array(
					'apiStatus' => BulkApi::ERR_TIMEOUT,
					'message'   => $message,
					'is_error'  => true,
					'is_done'   => true,
				));
				$q->itemFailed($qItem, true);
				do_action('emr/bulk/item/failed', $qItem);
			}
		}
	}

	/**
	 * Downloads the result image, backs up the current file and replaces it.
	 *
	 * @param QueueItem $qItem
	 * @return bool True when the attachment now holds the result.
	 */
	protected function applyResult(QueueItem $qItem)
	{
		$image   = $qItem->imageModel;
		$item_id = $qItem->item_id;
		$url     = $qItem->result()->optimized;

		$this->blockItem($qItem);

		$tmpFile = $this->download($url);
		if (false === $tmpFile) {
			$this->failItem($qItem, BulkApi::ERR_DOWNLOAD, __('Download of the result image failed', 'enable-media-replace'));
			$this->unBlockItem($qItem);
			return false;
		}

		$backup = BackupController::backup($image);
		if (false === $backup) {
			@unlink($tmpFile);
			ResponseController::addData($item_id, 'issue_type', ResponseController::ISSUE_BACKUP_CREATE);
			$this->failItem($qItem, BulkApi::STATUS_FAIL, __('The backup of the original could not be written', 'enable-media-replace'));
			$this->unBlockItem($qItem);
			return false;
		}
		$qItem->setData('backup', $backup);

		$replaced = $this->replaceFile($image, $tmpFile);
		if (false === $replaced) {
			$this->failItem($qItem, BulkApi::STATUS_FAIL, __('Replacing the file failed', 'enable-media-replace'));
			$this->unBlockItem($qItem);
			return false;
		}

		$action    = $qItem->data()->action;
		$paramlist = is_array($qItem->data()->paramlist) ? $qItem->data()->paramlist : array();
		if ('scale_image' === $action) {
			$image->setMarker('upscale', array(
				'ts'         => time(),
				'factor'     => isset($paramlist['upscale']) ? (int) $paramlist['upscale'] : 0,
				'from_width' => (int) $qItem->data()->source_width,
			), $backup);
		} else {
			$image->setMarker('background', array(
				'ts'        => time(),
				'bg_remove' => isset($paramlist['bg_remove']) ? $paramlist['bg_remove'] : 1,
			), $backup);
		}
		$qItem->addResult(array('backup' => $backup));

		$this->unBlockItem($qItem);
		return true;
	}

	/**
	 * Restores an item from its bulk backup: copies the backup over the main
	 * file through ReplaceController and removes marker and backup.
	 *
	 * @param QueueItem $qItem
	 * @return void
	 */
	protected function restoreItem(QueueItem $qItem)
	{
		if (false === $this->checkImageModel($qItem)) {
			return;
		}
		$image  = $qItem->imageModel;
		$marker = $image->getMarker();

		if (empty($marker['backup']) || false === BackupController::hasBackup($image)) {
			$qItem->addResult($this->api->returnFailure(BulkApi::STATUS_NOT_API, __('No bulk backup exists for this image', 'enable-media-replace')));
			return;
		}

		$backupFile = BackupController::getBackupFile($marker['backup']);
		$tmpPath    = wp_tempnam($backupFile->getFileName());
		$tmpFile    = \emr()->filesystem()->getFile($tmpPath);
		if (false === $backupFile->copy($tmpFile)) {
			$qItem->addResult($this->api->returnFailure(BulkApi::STATUS_NOT_API, __('The backup could not be read', 'enable-media-replace')));
			return;
		}

		$this->blockItem($qItem);
		$replaced = $this->replaceFile($image, $tmpPath);
		$this->unBlockItem($qItem);

		if (false === $replaced) {
			$qItem->addResult($this->api->returnFailure(BulkApi::STATUS_NOT_API, __('Restoring the file failed', 'enable-media-replace')));
			return;
		}

		$image->deleteMarker();
		BackupController::deleteBackup($marker['backup']);

		$qItem->addResult(array(
			'apiStatus'  => BulkApi::STATUS_NOT_API,
			'fileStatus' => QueueItem::FILE_STATUS_SUCCESS,
			'is_done'    => true,
			'is_error'   => false,
			'message'    => __('Original restored', 'enable-media-replace'),
		));
		do_action('emr/bulk/item/done', $image, 'restore', $qItem);
	}

	/**
	 * Replaces the attachment's main file with $tmpPath, the way the single
	 * remove-background and upscale flows do.
	 *
	 * @param BulkImage $image
	 * @param string    $tmpPath Temporary file, consumed by ReplaceController.
	 * @return bool
	 */
	protected function replaceFile(BulkImage $image, $tmpPath)
	{
		$replaceController = new ReplaceController($image->get('id'));
		$sourceFile        = $replaceController->getSourceFile();

		$params = array(
			'post_id'           => $image->get('id'),
			'replace_type'      => ReplaceController::MODE_REPLACE,
			'timestamp_replace' => ReplaceController::TIME_UPDATEMODIFIED,
			'new_date'          => current_time('mysql'),
			'is_custom_date'    => false,
			'remove_background' => true,
			'uploadFile'        => $tmpPath,
			'new_filename'      => $sourceFile->getFileName(),
		);

		if (false === $replaceController->setupParams($params)) {
			Log::addError('Bulk replace: setupParams failed', $replaceController->returnLastError());
			@unlink($tmpPath);
			return false;
		}

		$replaceController->run();

		if (! is_null($replaceController->returnLastError())) {
			Log::addError('Bulk replace: run reported an error', $replaceController->returnLastError());
			return false;
		}
		return true;
	}

	/**
	 * Downloads a result URL into a temporary file (extension kept).
	 *
	 * @param string $url
	 * @return string|false Path of the temporary file.
	 */
	protected function download($url)
	{
		if (! is_string($url) || '' === $url) {
			return false;
		}
		if (! function_exists('wp_tempnam')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$target = wp_tempnam($url);
		$ok     = \emr()->filesystem()->downloadFile($url, $target);
		if (false === $ok || ! file_exists($target) || 0 === (int) filesize($target)) {
			@unlink($target);
			return false;
		}
		return $target;
	}

	/**
	 * Records a terminal failure on the item.
	 *
	 * @param QueueItem $qItem
	 * @param int       $status
	 * @param string    $message
	 * @return void
	 */
	protected function failItem(QueueItem $qItem, $status, $message)
	{
		ResponseController::addData($qItem->item_id, array('message' => $message, 'is_error' => true));
		$qItem->addResult(array(
			'apiStatus'  => $status,
			'message'    => $message,
			'is_error'   => true,
			'is_done'    => true,
			'fileStatus' => QueueItem::FILE_STATUS_ERROR,
		));
	}

	/**
	 * Marks the item done in the queue and enqueues its next action, if any.
	 *
	 * @param QueueItem $qItem
	 * @param array     $args
	 * @return QueueItemResult
	 */
	protected function finishItemProcess(QueueItem $qItem, $args = array())
	{
		$queue = $this->getCurrentQueue();

		if (is_object($qItem->getQueueItem())) {
			$queue->itemDone($qItem);
		}

		if (true === $qItem->data()->hasNextAction()) {
			$action = $qItem->data()->popNextAction();
			$image  = BulkImage::getById($qItem->item_id);
			if (false !== $image) {
				$args['action'] = $action;
				if (true === $qItem->data()->hasNextAction()) {
					$args['next_actions'] = $qItem->data()->next_actions;
				}
				$args = array_merge($args, $qItem->data()->getKeepDataArgs());
				Log::addInfo("New action $action for {$qItem->item_id}", $args);
				$this->getQueueController()->addItemToQueue($image, $args);
			}
		}

		return $qItem->result();
	}

	/**
	 * Adds before/after URLs to the result: the backup and the current file.
	 *
	 * @param QueueItem $qItem
	 * @return QueueItem
	 */
	protected function addPreview(QueueItem $qItem)
	{
		$image    = $qItem->imageModel;
		$backup   = $qItem->data()->backup;
		$original = $backup ? BackupController::getBackupUrl($backup) : false;

		$current   = \emr()->filesystem()->getFile(get_attached_file($image->get('id')));
		$optimized = $current->exists() ? \emr()->filesystem()->pathToUrl($current) : false;

		$qItem->addResult(array('original' => $original, 'optimized' => $optimized));
		return $qItem;
	}

	/**
	 * Blocks the item (and persists the flag) while its result is applied, so a
	 * parallel tick skips it; registers the shutdown safety net once.
	 *
	 * @param QueueItem $qItem
	 * @return void
	 */
	protected function blockItem(QueueItem $qItem)
	{
		$qItem->block(true);
		$this->getCurrentQueue()->updateItem($qItem);

		self::$blockedItems[ $qItem->item_id ] = $qItem;

		if (false === $this->shutdown_registered) {
			register_shutdown_function(array($this, 'checkBlockedItems'));
			$this->shutdown_registered = true;
		}
	}

	/**
	 * Lifts the block and persists it.
	 *
	 * @param QueueItem $qItem
	 * @return void
	 */
	protected function unBlockItem(QueueItem $qItem)
	{
		$qItem->block(false);
		$this->getCurrentQueue()->updateItem($qItem);

		if (isset(self::$blockedItems[ $qItem->item_id ])) {
			unset(self::$blockedItems[ $qItem->item_id ]);
		}
	}

	/**
	 * Shutdown callback: unblocks items a fatal error left blocked. Public
	 * because register_shutdown_function() cannot call protected methods.
	 *
	 * @return void
	 */
	public function checkBlockedItems()
	{
		if (is_null(self::$blockedItems) || 0 === count(self::$blockedItems)) {
			return;
		}
		foreach (self::$blockedItems as $blockedItem) {
			Log::addWarn('Shutdown unblocking item', $blockedItem->item_id);
			$this->unBlockItem($blockedItem);
		}
	}
}
