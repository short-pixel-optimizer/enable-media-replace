<?php
namespace EnableMediaReplace\Controller;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Controller\ReplaceController as ReplaceController;
use EnableMediaReplace\Api as Api;

/**
 * Bulk Background Removal queue.
 *
 * For now this only handles product images: the main image, the gallery images and the variation images
 * of WooCommerce products. Images inserted in the product description are not processed.
 *
 * The queue lives in an option, so it survives page reloads. It is processed either by the bulk screen
 * (one AJAX request per image) or, in background mode, by WP-Cron so the screen can be closed.
 */
class BulkBackgroundController
{
	protected static $instance;

	const OPTION_QUEUE = 'emr_bulk_bg_queue';
	const OPTION_LOCK = 'emr_bulk_bg_lock';
	const CRON_HOOK = 'emr_bulk_bg_cron';
	const CRON_SCHEDULE = 'emr_bulk_bg_interval';

	// Set on an attachment once its background was removed by the bulk process.
	const META_PROCESSED = '_emr_bulk_bg_removed';

	const MODE_BROWSER = 'browser';
	const MODE_BACKGROUND = 'background';

	const STATUS_RUNNING = 'running';
	const STATUS_PAUSED = 'paused';
	const STATUS_FINISHED = 'finished';
	const STATUS_STOPPED = 'stopped';
	const STATUS_HALTED = 'halted'; // Stopped because of an error that applies to every image (quota, plan).

	const LOG_SIZE = 50;
	const LOCK_TIMEOUT = 600; // A lock older than this is considered left behind by a crashed process.

	public static function getInstance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new BulkBackgroundController();
		}
		return self::$instance;
	}

	/** Hooks needed without a logged-in user, since WP-Cron runs without one. */
	public static function hooks()
	{
		add_filter('cron_schedules', array(__CLASS__, 'addSchedule'));
		add_action(self::CRON_HOOK, array(self::getInstance(), 'cronRun'));
	}

	public static function addSchedule($schedules)
	{
		$schedules[self::CRON_SCHEDULE] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display' => __('Every minute (Enable Media Replace bulk background removal)', 'enable-media-replace'),
		);
		return $schedules;
	}

	/** Product post types are registered by WooCommerce. Without it there is nothing to process. */
	public static function hasProducts()
	{
		return post_type_exists('product');
	}

	/**
	 * Get all attachment IDs used as product image, product gallery image or variation image,
	 * which the current user may edit and whose background can be removed.
	 *
	 * @param boolean $skipProcessed  Leave out images already processed by the bulk.
	 * @return array Attachment IDs
	 */
	public static function getProductImageIds($skipProcessed = false)
	{
		global $wpdb;

		if (! self::hasProducts()) {
			return array();
		}

		$rows = $wpdb->get_results(
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type IN ('product', 'product_variation')
			 AND p.post_status NOT IN ('trash', 'auto-draft')
			 AND pm.meta_key IN ('_thumbnail_id', '_product_image_gallery')
			 AND pm.meta_value <> ''"
		);

		$ids = array();
		foreach ($rows as $row) {
			foreach (explode(',', $row->meta_value) as $id) {
				$id = intval($id);
				if ($id > 0) {
					$ids[$id] = $id;
				}
			}
		}

		if (count($ids) === 0) {
			return array();
		}

		$ids = array_values($ids);
		sort($ids);

		$uiHelper = \emr()->uiHelper();
		$result = array();

		// In chunks, so large shops don't load every attachment in memory at once.
		foreach (array_chunk($ids, 500) as $chunk) {
			_prime_post_caches($chunk, false, true);
			foreach ($chunk as $id) {
				$post = get_post($id);
				if (is_null($post) || ! \emr()->checkImagePermission($post) || ! $uiHelper->isBackgroundRemovable($post)) {
					continue;
				}
				if ($skipProcessed && get_post_meta($id, self::META_PROCESSED, true)) {
					continue;
				}
				$result[] = $id;
			}
		}

		return $result;
	}

	public static function getCounts()
	{
		$all = self::getProductImageIds(false);
		$processed = 0;
		foreach ($all as $id) {
			if (get_post_meta($id, self::META_PROCESSED, true)) {
				$processed++;
			}
		}

		return array(
			'total' => count($all),
			'processed' => $processed,
			'pending' => count($all) - $processed,
		);
	}

	/** Check that an attachment is used as image of a product or variation (not in the description). */
	public static function isProductImage($attachment_id)
	{
		global $wpdb;

		if (! self::hasProducts()) {
			return false;
		}

		$found = $wpdb->get_var($wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type IN ('product', 'product_variation')
			 AND p.post_status NOT IN ('trash', 'auto-draft')
			 AND (
				(pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s)
				OR (pm.meta_key = '_product_image_gallery' AND FIND_IN_SET(%s, pm.meta_value))
			 )
			 LIMIT 1",
			strval($attachment_id),
			strval($attachment_id)
		));

		return ! is_null($found);
	}

	/** Sanitize the background settings coming from the bulk screen. */
	public static function sanitizeBackground($background)
	{
		$background = is_array($background) ? $background : array();

		$type = isset($background['type']) && 'solid' === $background['type'] ? 'solid' : 'transparent';
		$color = isset($background['color']) ? sanitize_hex_color($background['color']) : null;
		$transparency = isset($background['transparency']) ? intval($background['transparency']) : 100;

		return array(
			'type' => $type,
			'color' => empty($color) ? '#ffffff' : $color,
			'transparency' => max(0, min(100, $transparency)),
		);
	}

	// ---------- Queue state ----------

	public function getQueue()
	{
		// Bypass the cache: the queue is changed by other requests (cron, other tabs) while one is running.
		wp_cache_delete(self::OPTION_QUEUE, 'options');
		$queue = get_option(self::OPTION_QUEUE, false);
		return is_array($queue) ? $queue : false;
	}

	protected function saveQueue($queue)
	{
		$queue['updated'] = time();
		update_option(self::OPTION_QUEUE, $queue, false);
	}

	/** True while a run is going on or paused, so a new one should not be started over it. */
	public function isActive($queue = null)
	{
		$queue = is_null($queue) ? $this->getQueue() : $queue;
		return is_array($queue) && in_array($queue['status'], array(self::STATUS_RUNNING, self::STATUS_PAUSED));
	}

	public function start($args)
	{
		if ($this->isActive()) {
			return new \WP_Error('active', __('A bulk background removal is already running. Pause or stop it first.', 'enable-media-replace'));
		}

		$skip = ! empty($args['skip_processed']);
		$items = self::getProductImageIds($skip);

		if (count($items) === 0) {
			return new \WP_Error('empty', __('There are no product images left to process.', 'enable-media-replace'));
		}

		$background = self::sanitizeBackground(isset($args['background']) ? $args['background'] : array());
		$mode = (isset($args['mode']) && self::MODE_BACKGROUND === $args['mode']) ? self::MODE_BACKGROUND : self::MODE_BROWSER;

		// Remember the choice for the next time a screen is opened, same as the single screen does.
		$settings = get_option('enable_media_replace', array());
		$settings = is_array($settings) ? $settings : array();
		$settings['bg_type'] = $background['type'];
		$settings['bg_color'] = $background['color'];
		$settings['bg_transparency'] = $background['transparency'];
		update_option('enable_media_replace', $settings, false);

		$queue = array(
			'id' => wp_generate_password(12, false),
			'status' => self::STATUS_RUNNING,
			'mode' => $mode,
			'items' => $items,
			'position' => 0,
			'ok' => 0,
			'fail' => 0,
			'background' => $background,
			'user_id' => get_current_user_id(),
			'started' => time(),
			'log' => array(),
			'current' => $this->getPreviewData($items[0]),
			'last' => null,
			'message' => '',
		);

		$this->saveQueue($queue);
		$this->updateSchedule($queue);

		return $queue;
	}

	public function pause()
	{
		$queue = $this->getQueue();
		if ($queue && self::STATUS_RUNNING === $queue['status']) {
			$queue['status'] = self::STATUS_PAUSED;
			$this->saveQueue($queue);
		}
		$this->updateSchedule($queue);
		return $queue;
	}

	/** Resume a paused run. The mode can be switched when resuming (e.g. from browser to background). */
	public function resume($mode = null)
	{
		$queue = $this->getQueue();
		if (! $queue || self::STATUS_PAUSED !== $queue['status']) {
			return $queue;
		}

		if (! is_null($mode)) {
			$queue['mode'] = (self::MODE_BACKGROUND === $mode) ? self::MODE_BACKGROUND : self::MODE_BROWSER;
		}
		$queue['status'] = self::STATUS_RUNNING;
		$queue['user_id'] = get_current_user_id();
		$this->saveQueue($queue);
		$this->updateSchedule($queue);

		return $queue;
	}

	public function stop()
	{
		$queue = $this->getQueue();
		if ($queue && $this->isActive($queue)) {
			$queue['status'] = self::STATUS_STOPPED;
			$queue['current'] = null;
			$this->saveQueue($queue);
		}
		$this->updateSchedule($queue);
		return $queue;
	}

	/** Cron is only scheduled while a run in background mode is going on. */
	protected function updateSchedule($queue)
	{
		$needed = is_array($queue) && self::STATUS_RUNNING === $queue['status'] && self::MODE_BACKGROUND === $queue['mode'];
		$next = wp_next_scheduled(self::CRON_HOOK);

		if ($needed && false === $next) {
			wp_schedule_event(time(), self::CRON_SCHEDULE, self::CRON_HOOK);
			// Don't wait for the next visitor to start.
			if (function_exists('spawn_cron')) {
				spawn_cron();
			}
		} elseif (! $needed && false !== $next) {
			wp_clear_scheduled_hook(self::CRON_HOOK);
		}
	}

	/** Data for the bulk screen. Leaves out the item list, which can be long. */
	public function getStatus($queue = null)
	{
		$queue = is_null($queue) ? $this->getQueue() : $queue;
		if (! is_array($queue)) {
			return array('status' => 'none');
		}

		$data = $queue;
		unset($data['items'], $data['user_id']);
		$data['total'] = count($queue['items']);
		$data['cron_disabled'] = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
		return $data;
	}

	// ---------- Processing ----------

	/** WP-Cron: process images until the time budget for this run is used up. */
	public function cronRun()
	{
		$queue = $this->getQueue();
		if (! $queue || self::STATUS_RUNNING !== $queue['status'] || self::MODE_BACKGROUND !== $queue['mode']) {
			$this->updateSchedule($queue);
			return;
		}

		// Cron runs without a user. Process as the user who started the run, so permissions apply.
		wp_set_current_user($queue['user_id']);
		if (! current_user_can('upload_files')) {
			$queue['status'] = self::STATUS_HALTED;
			$queue['message'] = __('The user who started the bulk can no longer upload files.', 'enable-media-replace');
			$this->saveQueue($queue);
			$this->updateSchedule($queue);
			return;
		}
		\emr()->loadExternals();

		if (function_exists('set_time_limit')) {
			@set_time_limit(apply_filters('emr/bulk/cron_time_limit', 300));
		}

		$started = time();
		$budget = apply_filters('emr/bulk/cron_time_budget', 45);

		do {
			$result = $this->processNext();
		} while ($result['processed'] && self::STATUS_RUNNING === $result['queue']['status'] && (time() - $started) < $budget);
	}

	/**
	 * Process the next image of the queue.
	 *
	 * @return array 'processed' (bool), 'busy' (another process holds the lock), 'queue' (state after).
	 */
	public function processNext()
	{
		$return = array('processed' => false, 'busy' => false, 'queue' => false);

		if (! $this->acquireLock()) {
			$return['busy'] = true;
			$return['queue'] = $this->getQueue();
			return $return;
		}

		$queue = $this->getQueue();
		$return['queue'] = $queue;

		if (! $queue || self::STATUS_RUNNING !== $queue['status']) {
			$this->releaseLock();
			return $return;
		}

		if ($queue['position'] >= count($queue['items'])) {
			$queue['status'] = self::STATUS_FINISHED;
			$queue['current'] = null;
			$this->saveQueue($queue);
			$this->updateSchedule($queue);
			$this->releaseLock();
			$return['queue'] = $queue;
			return $return;
		}

		$attachment_id = intval($queue['items'][$queue['position']]);

		// If a previous attempt died halfway (fatal error, killed process), the position was never moved on.
		// Don't get stuck on such an image forever.
		$attempts = (isset($queue['attempt_id']) && $queue['attempt_id'] === $attachment_id) ? intval($queue['attempts']) + 1 : 1;
		$queue['attempt_id'] = $attachment_id;
		$queue['attempts'] = $attempts;
		$queue['current'] = $this->getPreviewData($attachment_id);
		$this->saveQueue($queue);

		if ($attempts > 3) {
			$result = array(
				'success' => false,
				'id' => $attachment_id,
				'title' => get_the_title($attachment_id),
				'message' => __('Processing this image failed several times, it was skipped', 'enable-media-replace'),
				'halt' => false,
				'first_time' => false,
			);
		} else {
			$result = $this->processItem($attachment_id, $queue['background']);
		}

		// Re-read, the run might have been paused or stopped meanwhile. Only the counters are ours to change.
		$fresh = $this->getQueue();
		if (! $fresh || $fresh['id'] !== $queue['id']) {
			$this->releaseLock();
			return $return;
		}
		$queue = $fresh;

		$queue['position']++;
		if ($result['success']) {
			$queue['ok']++;
		} else {
			$queue['fail']++;
		}

		$result['seq'] = $queue['position'];
		$queue['log'][] = $result;
		if (count($queue['log']) > self::LOG_SIZE) {
			$queue['log'] = array_slice($queue['log'], -self::LOG_SIZE);
		}
		$queue['last'] = $result;

		$has_next = $queue['position'] < count($queue['items']);
		$queue['current'] = $has_next ? $this->getPreviewData($queue['items'][$queue['position']]) : null;

		if (! $result['success'] && $result['halt']) {
			$queue['status'] = self::STATUS_HALTED;
			$queue['message'] = $result['message'];
		} elseif (! $has_next && self::STATUS_RUNNING === $queue['status']) {
			$queue['status'] = self::STATUS_FINISHED;
		}

		$this->saveQueue($queue);
		$this->updateSchedule($queue);
		$this->releaseLock();

		$return['processed'] = true;
		$return['queue'] = $queue;
		return $return;
	}

	/**
	 * Remove the background of one product image and replace the file.
	 *
	 * @return array Result with 'success', 'message' and, on errors that apply to every image, 'halt'.
	 */
	public function processItem($attachment_id, $background)
	{
		$attachment = get_post($attachment_id);

		$response = array(
			'success' => false,
			'id' => $attachment_id,
			'title' => is_null($attachment) ? '#' . $attachment_id : get_the_title($attachment),
			'message' => '',
			'halt' => false,
			'first_time' => false,
		);

		if (! \emr()->checkImagePermission($attachment)) {
			$response['message'] = __('You don\'t have permission to edit this image', 'enable-media-replace');
			return $response;
		}

		if (! \emr()->uiHelper()->isBackgroundRemovable($attachment)) {
			$response['message'] = __('Background removal is only possible for JPG, PNG and WebP images', 'enable-media-replace');
			return $response;
		}

		if (! self::isProductImage($attachment_id)) {
			$response['message'] = __('This image is not used as a product image', 'enable-media-replace');
			return $response;
		}

		$this->loadAdminIncludes();

		$replaceController = new ReplaceController($attachment_id);
		$sourceFile = $replaceController->getSourceFile();
		if (is_null($sourceFile) || ! $sourceFile->exists()) {
			$response['message'] = __('The image file could not be found on the server', 'enable-media-replace');
			return $response;
		}

		$api = new Api();
		$result = $api->request(array(
			'attachment_id' => $attachment_id,
			'background' => $background,
			'nonce' => wp_create_nonce('emr_bulk_remove_background'),
		));

		if (false === $result->success) {
			$response['message'] = empty($result->message) ? __('The background could not be removed', 'enable-media-replace') : $result->message;
			// Plan or quota problems apply to every next image as well, so the bulk should stop.
			if (! empty($result->plan_expired) || ! empty($result->quota_exceeded)) {
				$response['halt'] = true;
			}
			return $response;
		}

		$download = $api->handleDownload($result->key);
		if (false === $download->success || ! file_exists($download->image)) {
			$response['message'] = __('Replacement Image could not be downloaded or does not exist', 'enable-media-replace');
			return $response;
		}

		$params = array(
			'post_id' => $attachment_id,
			'replace_type' => ReplaceController::MODE_REPLACE,
			'timestamp_replace' => ReplaceController::TIME_UPDATEMODIFIED,
			'new_date' => current_time('mysql'),
			'is_custom_date' => false,
			'remove_background' => true,
			'uploadFile' => $download->image,
			'new_filename' => $sourceFile->getFileName(),
		);

		if (false === $replaceController->setupParams($params)) {
			@unlink($download->image); // run() removes it, but it won't run.
			$response['message'] = __('Replacing the image failed', 'enable-media-replace') . ' (' . $replaceController->returnLastError() . ')';
			return $response;
		}

		try {
			$replaced = $replaceController->run();
		} catch (\RuntimeException $e) {
			Log::addError('Bulk background removal: ' . $e->getMessage());
			$replaced = false;
		}

		// run() returns true even when copying the new file failed, the error is only set.
		if (true !== $replaced || ! is_null($replaceController->returnLastError())) {
			$response['message'] = __('Replacing the image failed', 'enable-media-replace');
			return $response;
		}

		$response['first_time'] = ! get_post_meta($attachment_id, self::META_PROCESSED, true);
		update_post_meta($attachment_id, self::META_PROCESSED, time());

		$thumb = wp_get_attachment_image_src($attachment_id, 'thumbnail');
		$medium = wp_get_attachment_image_src($attachment_id, 'medium');
		$response['success'] = true;
		$response['thumb'] = (false !== $thumb) ? add_query_arg('emr_ts', time(), $thumb[0]) : '';
		$response['after'] = (false !== $medium) ? add_query_arg('emr_ts', time(), $medium[0]) : $response['thumb'];
		$full = wp_get_attachment_url($attachment_id);
		$response['full'] = (false !== $full) ? add_query_arg('emr_ts', time(), $full) : $response['after'];
		$response['edit_url'] = get_edit_post_link($attachment_id, 'raw');

		return $response;
	}

	/** What the live preview shows for an image before it is processed. */
	protected function getPreviewData($attachment_id)
	{
		$medium = wp_get_attachment_image_src($attachment_id, 'medium');
		return array(
			'id' => intval($attachment_id),
			'title' => get_the_title($attachment_id),
			// Same URL is reused after replacing (same filename), so make it unique to not get the cached file.
			'before' => (false !== $medium) ? add_query_arg('emr_before', time(), $medium[0]) : '',
		);
	}

	/** Replacing needs functions from wp-admin which are not loaded in WP-Cron. */
	protected function loadAdminIncludes()
	{
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');
		require_once(ABSPATH . 'wp-admin/includes/media.php');
	}

	// ---------- Lock, so the screen and cron (or two tabs) don't process the same image ----------

	protected function acquireLock()
	{
		global $wpdb;

		$insert = "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')";
		$locked = $wpdb->query($wpdb->prepare($insert, self::OPTION_LOCK, time()));

		if (! $locked) {
			$since = intval($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK)));
			if ($since > 0 && (time() - $since) < self::LOCK_TIMEOUT) {
				return false;
			}
			// Stale lock, probably from a process that was killed.
			$this->releaseLock();
			$locked = $wpdb->query($wpdb->prepare($insert, self::OPTION_LOCK, time()));
		}

		return (bool) $locked;
	}

	protected function releaseLock()
	{
		global $wpdb;
		$wpdb->delete($wpdb->options, array('option_name' => self::OPTION_LOCK));
		wp_cache_delete(self::OPTION_LOCK, 'options');
	}

} // class
