<?php
/**
 * The Media > Bulk screen.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\ViewController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\ApiKeyManager;
use EnableMediaReplace\Controller\Bulk\BackupController;
use EnableMediaReplace\Controller\Bulk\BulkController;
use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\Controller\Bulk\QueueController;
use EnableMediaReplace\Controller\Bulk\Selector\Selector;
use EnableMediaReplace\ViewController;

/**
 * Renders the bulk page: the dashboard with the two operations and the
 * bulk history, and the selection, summary, process, finished and restore
 * panels the screen script switches between.
 *
 * Port of ShortPixel Image Optimizer's BulkViewController for EMR's two
 * operations. The page is registered by EnableMediaReplacePlugin::menu()
 * under Media with the `manage_options` capability and the slug
 * EnableMediaReplacePlugin::BULK_PAGE_SLUG. load() fills $view with the
 * queue stats on load, the state of each operation (canRun(), the source
 * count), the stored settings, the key state, the previous runs and the
 * current error log, enqueues the bulk assets and includes
 * views/bulk/view-bulk.php, which includes the panel parts.
 *
 * Lifecycle: getInstance() singleton. Listens to no hooks.
 */
class BulkViewController extends ViewController
{
	/** @var static|null */
	protected static $instance;

	/** @var string */
	protected $view_name = 'bulk/view-bulk';

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
	 * Builds the view data, enqueues the assets and renders the page.
	 *
	 * @return void
	 */
	public function load()
	{
		if (! current_user_can('manage_options')) {
			$this->viewError(self::ERROR_UPLOAD_PERMISSION);
		}

		$bulk       = BulkController::getInstance();
		$queue      = new QueueController(array('is_bulk' => true));
		$keyManager = ApiKeyManager::getInstance();

		$this->view->stats    = $queue->getStartupData();
		$this->view->settings = BulkController::getSettings();
		$this->view->hasKey   = $keyManager->hasApiKey();
		$this->view->logs     = $this->getLogs();
		$this->view->logHeaders = array(__('Operation', 'enable-media-replace'), __('Images', 'enable-media-replace'), __('Errors', 'enable-media-replace'), __('Date', 'enable-media-replace'));
		$this->view->currentOperation = $queue->getQueue()->getOperation();
		$this->view->customOperation  = $bulk->getCustomOperation();
		$this->view->errorLog = $this->loadCurrentLog($this->view->currentOperation);
		$this->view->factors  = \emr()->uiHelper()->getUpscaleFactors();
		$this->view->maxThreshold = max($this->view->factors);

		$this->view->operations = array();
		foreach (array(BulkQueue::OPERATION_BACKGROUND, BulkQueue::OPERATION_UPSCALE) as $operation) {
			$can      = $bulk->canRun($operation);
			$selector = Selector::forOperation($operation);
			$this->view->operations[ $operation ] = (object) array(
				'available'  => (true === $can),
				'reason'     => is_wp_error($can) ? $can->get_error_message() : '',
				'count'      => is_null($selector) ? 0 : $selector->countSource(),
				'restorable' => $this->countRestorable($operation),
				'label'      => $this->getOperationLabel($operation),
			);
		}

		$this->view->error = false;
		if (false === $this->view->hasKey) {
			$this->view->error        = true;
			$this->view->errorTitle   = __('API key required', 'enable-media-replace');
			$this->view->errorContent = __('The bulk needs a ShortPixel API key on an Unlimited plan. Add it below to start.', 'enable-media-replace');
		}

		wp_enqueue_style('emr_style');
		wp_enqueue_style('emr_bulk');
		wp_enqueue_script('emr_bulk_processor');
		wp_enqueue_script('emr_bulk_screen');

		$this->loadView($this->view_name);
	}

	/**
	 * Human-readable name of an operation.
	 *
	 * @param string $operation
	 * @return string
	 */
	public function getOperationLabel($operation)
	{
		switch ($operation) {
			case BulkQueue::OPERATION_UPSCALE:
				return __('Upscale images', 'enable-media-replace');
			case BulkQueue::CUSTOM_OP_RESTORE:
				return __('Restore originals', 'enable-media-replace');
			default:
				return __('Remove background', 'enable-media-replace');
		}
	}

	/**
	 * Number of attachments carrying a bulk marker for $operation (restore candidates).
	 *
	 * @param string $operation
	 * @return int
	 */
	protected function countRestorable($operation)
	{
		global $wpdb;
		$rows  = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", \EnableMediaReplace\Model\Bulk\BulkImage::MARKER_META));
		$count = 0;
		foreach ((array) $rows as $value) {
			$marker = maybe_unserialize($value);
			if (is_array($marker) && isset($marker[ $operation ]) && ! empty($marker['backup'])) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * The current run's error log as HTML (one line per failed item), or false.
	 *
	 * @param string $operation
	 * @return string|false
	 */
	protected function loadCurrentLog($operation)
	{
		$log = BulkController::getInstance()->getLog(BulkController::currentLogName($operation));
		if (false === $log) {
			return false;
		}

		$output = '';
		foreach (array_filter(explode(';', (string) $log->getContents())) as $line) {
			$cells = array_map('trim', explode('|', $line));
			if (count($cells) < 2) {
				continue;
			}
			$output .= '<div class="fatal">' . esc_html($cells[0]) . ': ';
			if (isset($cells[3])) {
				$output .= esc_html($cells[3]);
			}
			$output .= ' (' . esc_html__('file', 'enable-media-replace') . ' ' . esc_html($cells[1]) . ')</div>';
		}
		return ('' === $output) ? false : $output;
	}

	/**
	 * The previous runs for the history table, newest first.
	 *
	 * @return array<int,array{name: string, images: int, errors: int, date: string, file: string|false}>
	 */
	public function getLogs()
	{
		$bulk = BulkController::getInstance();
		$dir  = BackupController::getBackupDirectory();
		$view = array();

		foreach ($bulk->getLogs() as $logData) {
			$file  = isset($logData['logfile']) ? $logData['logfile'] : false;
			$exists = $file && file_exists($dir->getPath() . $file);
			$name  = $this->getOperationLabel(isset($logData['type']) ? $logData['type'] : BulkQueue::OPERATION_BACKGROUND);
			if (isset($logData['operation']) && BulkQueue::CUSTOM_OP_RESTORE === $logData['operation']) {
				$name = $this->getOperationLabel(BulkQueue::CUSTOM_OP_RESTORE) . ' (' . $name . ')';
			}
			$view[] = array(
				'name'   => $name,
				'images' => isset($logData['total_images']) ? (int) $logData['total_images'] : (int) $logData['processed'],
				'errors' => (int) $logData['fatal_errors'],
				'date'   => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $logData['date']),
				'file'   => $exists ? substr($file, 0, -4) : false,
			);
		}

		return array_reverse($view);
	}
}
