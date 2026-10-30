<?php
/**
 * AJAX endpoints of the bulk screen.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * The three admin-ajax actions the bulk screen's processor talks to, and the
 * two-tab lock.
 *
 * Port of the bulk part of ShortPixel Image Optimizer's AjaxController:
 *
 * | Action              | Nonce               | Purpose                                                        |
 * |---------------------|---------------------|----------------------------------------------------------------|
 * | emr_bulk_process    | emr_bulk_process    | one tick: QueueController::processQueue(), needs the processor key |
 * | emr_bulk_request    | emr_bulk_request    | screen actions: createBulk, startBulk, finishBulk, startRestore, getStatus, skipPreparing, recheckActive, loadLogFile |
 * | emr_bulk_settings   | emr_bulk_settings   | the bulk settings (background process, upscale threshold)      |
 *
 * Every action needs `manage_options` (the bulk is never offered to author
 * restricted users) and a `nonce` POST field for its own action. Responses
 * go through send(): wp_send_json() of a stdClass that always carries
 * `status`, echoes the posted `callback` (the browser dispatches a window
 * event of that name) and the current `processorKey`.
 *
 * Processor key: the processor in the browser generates a random
 * `bulk-secret` and posts it with every tick; the first one seen is stored
 * in the `emr_bulk_secret` transient for two minutes and renewed on every
 * tick. A tick with a different key is answered with PROCESSOR_ACTIVE and
 * processes nothing, so two tabs or two users cannot drive the queue at
 * once. Unlike SPIO there is no `isBulk` override: EMR only has the bulk.
 *
 * Settings live in the `enable_media_replace` option under `bulk_*` keys,
 * see BulkController::getSettings().
 *
 * Lifecycle: instantiated by Ajax, which registers the hooks. Hooks:
 * - action wp_ajax_emr_bulk_process, wp_ajax_emr_bulk_request, wp_ajax_emr_bulk_settings (registered).
 */
class BulkAjaxController
{
	/** Transient holding the active processor key. */
	const SECRET_TRANSIENT = 'emr_bulk_secret';

	/** Lifetime of the processor key, renewed on every tick. */
	const SECRET_TTL = 2 * MINUTE_IN_SECONDS;

	/** Capability the bulk requires. */
	const CAPABILITY = 'manage_options';

	/**
	 * Registers the three actions.
	 */
	public function __construct()
	{
		add_action('wp_ajax_emr_bulk_process', array($this, 'ajaxProcessQueue'));
		add_action('wp_ajax_emr_bulk_request', array($this, 'ajaxRequest'));
		add_action('wp_ajax_emr_bulk_settings', array($this, 'settingsRequest'));
	}

	/**
	 * The stored processor key, false when none is active.
	 *
	 * @return string|false
	 */
	public function getProcessorKey()
	{
		$key = get_transient(self::SECRET_TRANSIENT);
		if (false === $key || ! is_string($key) || '' === $key || 'null' === $key) {
			return false;
		}
		return $key;
	}

	/**
	 * One tick of the queue.
	 *
	 * @return void Ends the request.
	 */
	public function ajaxProcessQueue()
	{
		$this->checkNonce('emr_bulk_process');
		$this->checkAccess('processQueue');
		$this->checkProcessorKey();

		$controller = new QueueController(array('is_bulk' => true));
		$this->send($controller->processQueue());
	}

	/**
	 * Dispatches a screen action.
	 *
	 * @return void Ends the request.
	 */
	public function ajaxRequest()
	{
		$this->checkNonce('emr_bulk_request');
		$this->checkAccess('ajax');

		$action = isset($_POST['screen_action']) ? sanitize_text_field(wp_unslash($_POST['screen_action'])) : '';

		$json         = new \stdClass();
		$json->status = false;

		switch ($action) {
			case 'createBulk':
				$json = $this->createBulk($json);
				break;
			case 'startBulk':
				$json = $this->startBulk($json);
				break;
			case 'finishBulk':
				$json = $this->finishBulk($json);
				break;
			case 'startRestore':
				$json = $this->startRestore($json);
				break;
			case 'getStatus':
				$json = $this->getStatus($json);
				break;
			case 'skipPreparing':
				$json = $this->skipPreparing($json);
				break;
			case 'recheckActive':
				$this->checkProcessorKey();
				$json->status  = true;
				$json->message = __('Became processor', 'enable-media-replace');
				break;
			case 'loadLogFile':
				$json = $this->loadLogFile($json);
				break;
			default:
				$json->error   = QueueController::NO_ACTION;
				$json->message = __('Unknown bulk action', 'enable-media-replace');
				break;
		}

		$this->send($json);
	}

	/**
	 * Saves the bulk settings posted as `background_process` and `threshold`.
	 *
	 * @return void Ends the request.
	 */
	public function settingsRequest()
	{
		$this->checkNonce('emr_bulk_settings');
		$this->checkAccess('settings');

		$settings = BulkController::getSettings();
		if (isset($_POST['background_process'])) {
			$settings['background_process'] = filter_var(sanitize_text_field(wp_unslash($_POST['background_process'])), FILTER_VALIDATE_BOOLEAN);
		}
		if (isset($_POST['threshold'])) {
			$settings['upscale_threshold'] = BulkController::sanitizeThreshold(wp_unslash($_POST['threshold']));
		}
		BulkController::saveSettings($settings);

		$json           = new \stdClass();
		$json->status   = true;
		$json->settings = BulkController::getSettings();
		$this->send($json);
	}

	/**
	 * Creates a new bulk from the selection panel.
	 *
	 * POST fields: `operation` ('background' or 'upscale'), `threshold`,
	 * `bg_type` ('transparent' or 'solid'), `bg_color`, `bg_transparency`,
	 * `background_process`. The choices are stored as the bulk settings and
	 * passed to BulkController::createNewBulk().
	 *
	 * @param \stdClass $json
	 * @return \stdClass
	 */
	protected function createBulk($json)
	{
		$operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : BulkQueue::OPERATION_BACKGROUND;
		if (! in_array($operation, array(BulkQueue::OPERATION_BACKGROUND, BulkQueue::OPERATION_UPSCALE), true)) {
			$operation = BulkQueue::OPERATION_BACKGROUND;
		}

		$bulk = BulkController::getInstance();
		$can  = $bulk->canRun($operation);
		if (true !== $can) {
			$json->status  = false;
			$json->error   = QueueController::NO_ACTION;
			$json->message = is_wp_error($can) ? $can->get_error_message() : __('This bulk cannot run on this site', 'enable-media-replace');
			return $json;
		}

		$settings = BulkController::getSettings();
		if (isset($_POST['threshold'])) {
			$settings['upscale_threshold'] = BulkController::sanitizeThreshold(wp_unslash($_POST['threshold']));
		}
		if (isset($_POST['bg_type'])) {
			$settings['bg_type'] = ('solid' === sanitize_key(wp_unslash($_POST['bg_type']))) ? 'solid' : 'transparent';
		}
		if (isset($_POST['bg_color'])) {
			$color = sanitize_hex_color(wp_unslash($_POST['bg_color']));
			$settings['bg_color'] = $color ? $color : '#ffffff';
		}
		if (isset($_POST['bg_transparency'])) {
			$settings['bg_transparency'] = max(0, min(100, intval($_POST['bg_transparency'])));
		}
		if (isset($_POST['background_process'])) {
			$settings['background_process'] = filter_var(sanitize_text_field(wp_unslash($_POST['background_process'])), FILTER_VALIDATE_BOOLEAN);
		}
		BulkController::saveSettings($settings);

		$args = array('operation' => $operation);
		if (BulkQueue::OPERATION_UPSCALE === $operation) {
			$args['threshold'] = (int) $settings['upscale_threshold'];
		} else {
			$args['do_transparent']       = ('solid' !== $settings['bg_type']);
			$args['replace_color']        = $settings['bg_color'];
			$args['replace_transparency'] = (int) $settings['bg_transparency'];
		}

		$bulk->createNewBulk($args);

		return $this->withStartupData($json);
	}

	/**
	 * Starts the prepared bulk.
	 *
	 * @param \stdClass $json
	 * @return \stdClass
	 */
	protected function startBulk($json)
	{
		BulkController::getInstance()->startBulk();
		return $this->withStartupData($json);
	}

	/**
	 * Finishes the bulk (log entry, queue reset).
	 *
	 * @param \stdClass $json
	 * @return \stdClass
	 */
	protected function finishBulk($json)
	{
		BulkController::getInstance()->finishBulk();
		$json->status = 1;
		return $json;
	}

	/**
	 * Creates a restore bulk for the posted `operation`.
	 *
	 * @param \stdClass $json
	 * @return \stdClass
	 */
	protected function startRestore($json)
	{
		$operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : BulkQueue::OPERATION_BACKGROUND;
		if (! in_array($operation, array(BulkQueue::OPERATION_BACKGROUND, BulkQueue::OPERATION_UPSCALE), true)) {
			$operation = BulkQueue::OPERATION_BACKGROUND;
		}

		QueueController::resetQueues();
		BulkController::getInstance()->createNewBulk(array('operation' => $operation, 'customOp' => BulkQueue::CUSTOM_OP_RESTORE));

		return $this->withStartupData($json);
	}

	/**
	 * The current stats without a tick.
	 *
	 * @param \stdClass $json
	 * @return \stdClass
	 */
	protected function getStatus($json)
	{
		return $this->withStartupData($json);
	}

	/**
	 * Ends the preparing phase early (the "start now" button): what is
	 * queued so far is what the bulk processes.
	 *
	 * @param \stdClass $json
	 * @return \stdClass
	 */
	protected function skipPreparing($json)
	{
		$queue = (new QueueController())->getQueue();
		$queue->getShortQ()->setStatus('preparing', false, true);
		return $this->withStartupData($json);
	}

	/**
	 * Parses a finished bulk log for the log modal.
	 *
	 * POST field `loadFile`: the file name (`bulk_<operation>_<ts>`, without
	 * extension). Returns `log` with `title`, `results` (a header row
	 * followed by one row per line: date, filename, id, link, error).
	 *
	 * @param \stdClass $json
	 * @return \stdClass
	 */
	protected function loadLogFile($json)
	{
		$name = isset($_POST['loadFile']) ? sanitize_file_name(wp_unslash($_POST['loadFile'])) : '';
		$json->log = new \stdClass();

		if ('' === $name) {
			$json->log->is_error = true;
			$json->log->message  = __('Could not load the log file', 'enable-media-replace');
			return $json;
		}

		$bulk    = BulkController::getInstance();
		$log     = $bulk->getLog($name . '.log');
		$logData = $bulk->getLogData($name . '.log');

		if (false === $log) {
			$json->log->is_error = true;
			$json->log->message  = __('The log file does not exist', 'enable-media-replace');
			return $json;
		}

		$results   = array();
		$results[] = array(__('Time', 'enable-media-replace'), __('File', 'enable-media-replace'), __('ID', 'enable-media-replace'), __('Error', 'enable-media-replace'));

		foreach (array_filter(explode(';', trim((string) $log->getContents()))) as $line) {
			$cells = array_map('trim', explode('|', $line));
			if (count($cells) < 2) {
				continue;
			}
			$id    = isset($cells[2]) ? (int) $cells[2] : 0;
			$entry = array(
				'date'     => $cells[0],
				'filename' => $cells[1],
				'id'       => $id,
				'error'    => isset($cells[3]) ? $cells[3] : '',
			);
			if ($id > 0) {
				$entry['link'] = esc_url(admin_url('post.php?post=' . $id . '&action=edit'));
			}
			$results[] = $entry;
		}

		$json->log->title    = (is_array($logData) && isset($logData['date'])) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $logData['date']) : $name;
		$json->log->results  = $results;
		$json->log->is_error = false;
		$json->status        = true;
		return $json;
	}

	/**
	 * Adds `media` and `total` stats and sets status true.
	 *
	 * @param \stdClass $json
	 * @return \stdClass
	 */
	protected function withStartupData($json)
	{
		$data         = (new QueueController(array('is_bulk' => true)))->getStartupData();
		$json->media  = $data->media;
		$json->total  = $data->total;
		$json->status = true;
		return $json;
	}

	/**
	 * Verifies `$_POST['nonce']` for $action; ends the request with NONCE_FAILED otherwise.
	 *
	 * @param string $action
	 * @return void
	 */
	protected function checkNonce($action)
	{
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (! wp_verify_nonce($nonce, $action)) {
			$json          = new \stdClass();
			$json->message = __('Nonce is missing or wrong. Try to refresh the page', 'enable-media-replace');
			$json->status  = false;
			$json->error   = QueueController::NONCE_FAILED;
			$this->send($json);
		}
	}

	/**
	 * Requires the bulk capability; ends the request with NO_ACCESS otherwise.
	 *
	 * @param string $action For the message only.
	 * @return void
	 */
	protected function checkAccess($action)
	{
		if (! current_user_can(self::CAPABILITY)) {
			$json          = new \stdClass();
			$json->message = __('This user is not allowed to perform this action', 'enable-media-replace');
			$json->action  = $action;
			$json->status  = false;
			$json->error   = QueueController::NO_ACCESS;
			$this->send($json);
		}
	}

	/**
	 * Claims or confirms the processor key posted as `bulk-secret`; ends the
	 * request with PROCESSOR_ACTIVE when another key holds the queue.
	 *
	 * @return void
	 */
	protected function checkProcessorKey()
	{
		$processKey = $this->getProcessorKey();
		$bulkSecret = isset($_POST['bulk-secret']) ? sanitize_text_field(wp_unslash($_POST['bulk-secret'])) : false;
		if (false !== $bulkSecret && ('' === $bulkSecret || 'null' === $bulkSecret)) {
			$bulkSecret = false;
		}

		$is_processor = false;
		if (false === $processKey && false !== $bulkSecret) {
			$is_processor = true;
		} elseif (false !== $bulkSecret && $processKey === $bulkSecret) {
			$is_processor = true;
		}

		if ($is_processor) {
			set_transient(self::SECRET_TRANSIENT, $bulkSecret, self::SECRET_TTL);
			return;
		}

		$json          = new \stdClass();
		$json->message = __('The bulk is being processed in another window', 'enable-media-replace');
		$json->status  = false;
		$json->error   = QueueController::PROCESSOR_ACTIVE;
		$this->send($json);
	}

	/**
	 * Sends the JSON answer with the callback and the processor key, and ends the request.
	 *
	 * @param \stdClass|array $json
	 * @return void
	 */
	protected function send($json)
	{
		if (is_array($json) || (is_object($json) && ! ($json instanceof \stdClass))) {
			$json = (object) $json;
		}
		$callback = isset($_POST['callback']) ? sanitize_text_field(wp_unslash($_POST['callback'])) : false;
		if ($callback) {
			$json->callback = $callback;
		}
		$key = $this->getProcessorKey();
		if (false !== $key) {
			$json->processorKey = $key;
		}
		wp_send_json($json);
	}
}
