<?php
/**
 * Bulk run lifecycle and logs.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\Controller\Bulk\Selector\Selector;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Creates, starts and finishes a bulk run, and keeps the rotating list of
 * bulk logs.
 *
 * Port of ShortPixel Image Optimizer's BulkController for one queue and
 * two operations. createNewBulk() stores the operation and its options in
 * the queue and puts it in the preparing state; the restore custom
 * operation lowers the ShortQ limits as SPIO does (numitems 5,
 * retry_limit 5, process_timeout 3000). finishBulk() writes the log entry
 * and resets the queue.
 *
 * Logs: the option `emr_bulk_logs` holds at most 10 entries; the per-item
 * log lines of a run are appended to `current_bulk_<operation>.log` in the
 * backup folder while it runs and renamed to `bulk_<operation>_<ts>.log`
 * when it finishes.
 *
 * Lifecycle: getInstance() singleton. Hooks:
 * - filter `emr/bulk/custom_options` (array args): the options of a custom operation before they are stored.
 */
class BulkController
{
	/** Option holding the log entries. */
	const LOG_OPTION = 'emr_bulk_logs';

	/** @var static|null */
	protected static $instance;

	/** @var array|null */
	protected $logs;

	public function __construct()
	{
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
	 * Name of the log file a running bulk appends to.
	 *
	 * @param string $operation
	 * @return string
	 */
	public static function currentLogName($operation)
	{
		return 'current_bulk_' . sanitize_key($operation) . '.log';
	}

	/**
	 * Creates a new bulk: resets the queue, stores the options, flags preparing.
	 *
	 * @param array $args {
	 *     @type string      $operation            'background' (default) or 'upscale'.
	 *     @type string|null $customOp             'bulk-restore' for a restore of $operation; null otherwise.
	 *     @type int|null    $threshold            Upscale: only images narrower than this width.
	 *     @type bool        $do_transparent       Background: transparent result (default true).
	 *     @type string|null $replace_color        Background: colour when not transparent.
	 *     @type int         $replace_transparency Background: alpha 0-100 for the colour.
	 *     @type int[]       $items                Explicit attachment IDs (tests, API callers).
	 * }
	 * @return \stdClass The queue stats after creation.
	 */
	public function createNewBulk($args = array())
	{
		$defaults = array(
			'operation' => BulkQueue::OPERATION_BACKGROUND,
			'customOp'  => null,
			'items'     => array(),
		);
		$args = wp_parse_args($args, $defaults);

		if (! in_array($args['operation'], array(BulkQueue::OPERATION_BACKGROUND, BulkQueue::OPERATION_UPSCALE), true)) {
			$args['operation'] = BulkQueue::OPERATION_BACKGROUND;
		}

		$queueController = new QueueController(array('is_bulk' => true));
		$Q               = $queueController->getQueue();

		$dir         = BackupController::getBackupDirectory();
		$current_log = \emr()->filesystem()->getFile($dir->getPath() . self::currentLogName($args['operation']));
		if ($current_log->exists() && $current_log->is_writable()) {
			$current_log->delete();
		}

		if (! is_null($args['customOp'])) {
			if (BulkQueue::CUSTOM_OP_RESTORE === $args['customOp']) {
				$args['numitems']        = 5;
				$args['retry_limit']     = 5;
				$args['process_timeout'] = 3000;
			}
			$args = apply_filters('emr/bulk/custom_options', $args);
		} else {
			unset($args['customOp']);
		}

		$Q->createNewBulk($args);

		return $Q->getStats();
	}

	/**
	 * Whether an operation can run on this site: its selector is available.
	 *
	 * @param string $operation
	 * @return true|\WP_Error The reason when it cannot.
	 */
	public function canRun($operation)
	{
		$selector = Selector::forOperation($operation);
		if (is_null($selector)) {
			return new \WP_Error('emr_bulk_unknown_operation', __('Unknown bulk operation', 'enable-media-replace'));
		}
		if (false === $selector->isAvailable()) {
			return new \WP_Error('emr_bulk_unavailable', $selector->getUnavailableReason());
		}
		return true;
	}

	/**
	 * How many source rows an operation would scan (products, attachments),
	 * for the selection panel. 0 when the operation cannot run.
	 *
	 * @param string $operation
	 * @return int
	 */
	public function countSource($operation)
	{
		$selector = Selector::forOperation($operation);
		return is_null($selector) ? 0 : $selector->countSource();
	}

	/**
	 * Whether a bulk has unfinished items.
	 *
	 * @return bool
	 */
	public function isBulkRunning()
	{
		$stats = $this->queue()->getStats();
		return (false === $stats->is_finished && $stats->total > 0);
	}

	/**
	 * The custom operation of the queue, false when none.
	 *
	 * @return string|false
	 */
	public function getCustomOperation()
	{
		return $this->queue()->getCustomDataItem('customOperation');
	}

	/**
	 * Starts the run (preparing to running).
	 *
	 * @return \stdClass The queue stats.
	 */
	public function startBulk()
	{
		$q = $this->queue();
		$q->startBulk();
		return $q->getStats();
	}

	/**
	 * Finishes the run: writes the log entry and resets the queue.
	 *
	 * @return void
	 */
	public function finishBulk()
	{
		$q = $this->queue();
		$this->addLog($q);
		$q->resetQueue();
	}

	/**
	 * The stored log entries (newest last), memoised.
	 *
	 * @return array
	 */
	public function getLogs()
	{
		if (is_null($this->logs)) {
			$this->logs = get_option(self::LOG_OPTION, array());
			if (! is_array($this->logs)) {
				$this->logs = array();
			}
		}
		return $this->logs;
	}

	/**
	 * A log file by name, or false when it does not exist or escapes the backup folder.
	 *
	 * @param string $logName
	 * @return \EnableMediaReplace\FileSystem\Model\File\FileModel|false
	 */
	public function getLog($logName)
	{
		$dir        = BackupController::getBackupDirectory();
		$backupPath = realpath($dir->getPath());
		$resolved   = realpath($dir->getPath() . $logName);

		if (false === $backupPath || false === $resolved || 0 !== strpos($resolved, $backupPath)) {
			return false;
		}

		$log = \emr()->filesystem()->getFile($dir->getPath() . $logName);
		return $log->exists() ? $log : false;
	}

	/**
	 * The log entry for a file name.
	 *
	 * @param string $fileName
	 * @return array|false
	 */
	public function getLogData($fileName)
	{
		foreach ($this->getLogs() as $log) {
			if (isset($log['logfile']) && $log['logfile'] === $fileName) {
				return $log;
			}
		}
		return false;
	}

	/**
	 * Records a finished run; skipped when nothing was done and nothing failed.
	 * Keeps 10 entries, deleting the oldest entry's file.
	 *
	 * @param BulkQueue $q
	 * @return void
	 */
	protected function addLog($q)
	{
		$stats     = $q->getStats();
		$operation = $q->getOperation();

		if (0 === $stats->done && 0 === $stats->fatal_errors) {
			return;
		}

		$data = array(
			'processed'     => $stats->done,
			'not_processed' => $stats->in_queue,
			'errors'        => $stats->errors,
			'fatal_errors'  => $stats->fatal_errors,
			'type'          => $operation,
			'date'          => time(),
		);
		if (property_exists($stats, 'images')) {
			$data['total_images'] = $stats->images->images_done;
		}
		if ($q->getCustomDataItem('customOperation')) {
			$data['operation'] = $q->getCustomDataItem('customOperation');
		}

		$logs = $this->getLogs();
		$fs   = \emr()->filesystem();
		$dir  = BackupController::getBackupDirectory();

		if (count($logs) >= 10) {
			$log = array_shift($logs);
			if (isset($log['logfile'])) {
				$fileLog = $fs->getFile($dir->getPath() . $log['logfile']);
				if ($fileLog->exists()) {
					$fileLog->delete();
				}
			}
		}

		$data['logfile'] = 'bulk_' . sanitize_key($operation) . '_' . $data['date'] . '.log';

		$fileLog = $fs->getFile($dir->getPath() . self::currentLogName($operation));
		$moveLog = $fs->getFile($dir->getPath() . $data['logfile']);
		if ($fileLog->exists()) {
			$fileLog->move($moveLog);
		}

		$logs[] = $data;
		$this->saveLogs($logs);
	}

	/**
	 * Saves the entries, deleting the option when empty.
	 *
	 * @param array $logs
	 * @return void
	 */
	protected function saveLogs($logs)
	{
		$this->logs = $logs;
		if (is_array($logs) && count($logs) > 0) {
			update_option(self::LOG_OPTION, $logs, false);
		} else {
			delete_option(self::LOG_OPTION);
		}
	}

	/**
	 * Removes the log option (plugin uninstall).
	 *
	 * @return void
	 */
	public static function uninstallPlugin()
	{
		delete_option(self::LOG_OPTION);
	}

	/**
	 * @return BulkQueue
	 */
	private function queue()
	{
		$queueController = new QueueController(array('is_bulk' => true));
		return $queueController->getQueue();
	}
}
