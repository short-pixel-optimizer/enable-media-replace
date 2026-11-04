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

	/** ShortPixel sign-up page, with the UTM parameters of the bulk page. */
	const SIGNUP_URL = 'https://shortpixel.com/free-sign-up?utm_source=plugin&utm_medium=emr&utm_campaign=emr_bulk';

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
		$this->view->aiCredits = $this->view->hasKey ? $keyManager->getAiCredits() : false;
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
				'restorable'   => $this->countRestorable($operation),
				'label'        => $this->getOperationLabel($operation),
				'restoreLabel' => $this->getRestoreLabel($operation),
			);
		}

		$this->view->signupUrl      = self::SIGNUP_URL;
		$this->view->backupPath     = $this->getBackupDisplayPath();
		$this->view->factorList     = $this->getFactorList(array_keys($this->view->factors));
		$this->view->maxFactor      = max(array_keys($this->view->factors));
		$this->view->displayOperation = (BulkQueue::CUSTOM_OP_RESTORE === $this->view->customOperation) ? 'restore' : $this->view->currentOperation;

		$this->view->error = false;
		if (false === $this->view->hasKey) {
			$this->view->error        = true;
			$this->view->errorTitle   = __('API key required', 'enable-media-replace');
			// HTML: escaped here, printed with wp_kses_post() by part-dashboard.php.
			$this->view->errorContent = sprintf(
				/* translators: %1$s and %2$s: opening and closing tags of the sign-up link. */
				esc_html__('Bulk Enhance needs a ShortPixel API key on an Unlimited plan. Add your key below to start, or get one %1$shere%2$s.', 'enable-media-replace'),
				'<a href="' . esc_url(self::SIGNUP_URL) . '" target="_blank" rel="noopener">',
				'</a>'
			);
		}

		wp_enqueue_style('emr_style');
		wp_enqueue_style('emr_bulk');
		wp_enqueue_script('emr_bulk_processor');
		wp_enqueue_script('emr_bulk_screen');

		$this->loadView($this->view_name);
	}

	/**
	 * Renders the step bar (views/bulk/part-steps.php) with $part as the current step.
	 *
	 * loadView() includes a template once and passes no arguments, while the
	 * step bar appears on four panels with a different current step.
	 *
	 * @param string $part selection, summary, process or finished.
	 * @return void
	 */
	public function loadSteps($part)
	{
		$view       = $this->view;
		$controller = $this;
		include \emr()->plugin_path('views/bulk/part-steps.php');
	}

	/**
	 * Attributes for an element shown for some operations only.
	 *
	 * The screen script (UpdateOperationLabels()) toggles every
	 * `[data-operation-only]` element when the operation changes; this sets
	 * the initial state for the operation the page loads with.
	 *
	 * @param string $operations Space-separated: background, upscale, restore.
	 * @return string The attribute string, escaped.
	 */
	public function operationOnly($operations)
	{
		$list   = explode(' ', $operations);
		$hidden = in_array($this->view->displayOperation, $list, true) ? '' : ' hidden';
		return 'data-operation-only="' . esc_attr($operations) . '"' . $hidden;
	}

	/**
	 * The backup folder as shown to the user: relative to the WordPress root.
	 *
	 * @return string For example `/wp-content/uploads/EMR_Backups`.
	 */
	protected function getBackupDisplayPath()
	{
		$path = wp_normalize_path(untrailingslashit(BackupController::getBackupDirectory()->getPath()));
		$root = wp_normalize_path(untrailingslashit(ABSPATH));
		if ('' !== $root && 0 === strpos($path, $root)) {
			$path = substr($path, strlen($root));
		}
		return $path;
	}

	/**
	 * The upscale factors as a sentence fragment: "2×, 3× or 4×".
	 *
	 * @param int[] $factors
	 * @return string
	 */
	protected function getFactorList($factors)
	{
		$labels = array();
		foreach ($factors as $factor) {
			/* translators: %d: upscale factor. */
			$labels[] = sprintf(__('%d×', 'enable-media-replace'), (int) $factor);
		}
		$last = array_pop($labels);
		if (0 === count($labels)) {
			return $last;
		}
		/* translators: %1$s: comma-separated factors, %2$s: the last factor. */
		return sprintf(__('%1$s or %2$s', 'enable-media-replace'), implode(', ', $labels), $last);
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
	 * The restore panel's button for an operation: names what comes back,
	 * not the operation ("Restore: Remove background" read as if it would
	 * run it again).
	 *
	 * @param string $operation
	 * @return string
	 */
	public function getRestoreLabel($operation)
	{
		switch ($operation) {
			case BulkQueue::OPERATION_UPSCALE:
				return __('Restore upscaled images', 'enable-media-replace');
			default:
				return __('Restore background removals', 'enable-media-replace');
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
