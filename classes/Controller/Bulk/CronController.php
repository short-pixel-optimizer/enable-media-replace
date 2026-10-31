<?php
/**
 * WP-Cron driven bulk ticks.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Keeps a running bulk going while nobody has the bulk page open.
 *
 * Port of ShortPixel Image Optimizer's CronController and
 * AdminController::processCronHook() for EMR's single queue. When the
 * `background_process` bulk setting is on and the queue is running with
 * items waiting, the `emr_bulk_cron` event is scheduled on the
 * `emr_bulk_interval` schedule (60 s by default). Each run processes the
 * queue in a loop (processCronHook(): at most `max_runs` ticks, within
 * `timelimit` seconds, `wait` seconds between ticks) and the event is
 * removed as soon as the queue is empty, the setting is off or the bulk
 * was finished. The preparing phase is never driven by cron: it belongs to
 * the bulk page, which has the person's choices.
 *
 * Cron ticks do not take the browser's processor key; ShortQ's in-process
 * status keeps the two from processing the same item.
 *
 * Lifecycle: getInstance() singleton, created on `init` by
 * EnableMediaReplacePlugin::runtime() for every request so the schedule is
 * known to WP-Cron. Hooks:
 * - filter `cron_schedules` (registered): adds `emr_bulk_interval`.
 * - action `emr_bulk_cron` (registered): processCronHook().
 * - filter `emr/bulk/cron_interval` (60): seconds between cron runs.
 * - filter `emr/bulk/cron/options` (array): the loop options of processCronHook().
 */
class CronController
{
	/** The cron event. */
	const EVENT = 'emr_bulk_cron';

	/** The cron schedule the event runs on. */
	const SCHEDULE = 'emr_bulk_interval';

	/** @var static|null */
	private static $instance;

	public function __construct()
	{
		add_filter('cron_schedules', array($this, 'cron_schedules'));
		add_action(self::EVENT, array($this, 'processCronHook'));

		if (false === wp_doing_ajax()) {
			$this->scheduler();
		}
	}

	/**
	 * @return static
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new static();
		}
		return self::$instance;
	}

	/**
	 * Adds the bulk interval to the WP-Cron schedules.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function cron_schedules($schedules)
	{
		$schedules[ self::SCHEDULE ] = array(
			'interval' => max(10, (int) apply_filters('emr/bulk/cron_interval', 60)),
			'display'  => __('Enable Media Replace bulk interval', 'enable-media-replace'),
		);
		return $schedules;
	}

	/**
	 * Whether the background process setting is on.
	 *
	 * @return bool
	 */
	public function isActive()
	{
		$settings = BulkController::getSettings();
		return (bool) $settings['background_process'];
	}

	/**
	 * Whether the event is scheduled.
	 *
	 * @return bool
	 */
	public function isScheduled()
	{
		return false !== wp_next_scheduled(self::EVENT);
	}

	/**
	 * Schedules the event when the setting is on and the bulk is running with
	 * items waiting; removes it otherwise.
	 *
	 * @return bool Whether the event is scheduled afterwards.
	 */
	public function scheduler()
	{
		$scheduled = $this->isScheduled();

		if (false === $this->isActive()) {
			if ($scheduled) {
				$this->unschedule();
			}
			return false;
		}

		$stats    = (new QueueController(array('is_bulk' => true)))->getStartupData(false)->media->stats;
		$wanted   = (true === $stats->is_running && $stats->awaiting > 0);

		if ($wanted && ! $scheduled) {
			wp_schedule_event(time(), self::SCHEDULE, self::EVENT);
			return true;
		}
		if (! $wanted && $scheduled) {
			$this->unschedule();
			return false;
		}
		return $scheduled;
	}

	/**
	 * Re-evaluates the schedule after the queue changed (a bulk started, the
	 * setting changed).
	 *
	 * @return void
	 */
	public function checkNewJobs()
	{
		$this->scheduler();
	}

	/**
	 * Removes the event (deactivation, finished bulk).
	 *
	 * @return void
	 */
	public function unschedule()
	{
		wp_clear_scheduled_hook(self::EVENT);
	}

	/**
	 * Deactivation: no cron event may survive.
	 *
	 * @return void
	 */
	public function onDeactivate()
	{
		$this->unschedule();
	}

	/**
	 * The cron run: processes the queue in a loop.
	 *
	 * Options (filter `emr/bulk/cron/options`): `max_runs` (10), `timelimit`
	 * in seconds (50), `wait` seconds between ticks (1). The loop ends when
	 * the queue reports empty or preparing-done, the tick is refused (key,
	 * plan, quota), the run limit or the time limit is reached. Afterwards
	 * the scheduler decides whether the event stays.
	 *
	 * @return \stdClass|null The last tick's answer, null when nothing ran.
	 */
	public function processCronHook()
	{
		$args = apply_filters('emr/bulk/cron/options', array(
			'max_runs'  => 10,
			'timelimit' => 50,
			'wait'      => 1,
		));

		$this->loadCronCompat();

		if (false === $this->isActive()) {
			$this->unschedule();
			return null;
		}

		$controller = new QueueController(array('is_bulk' => true));
		$started    = microtime(true);
		$last       = null;

		for ($i = 0; $i < max(1, (int) $args['max_runs']); $i++) {
			$last = $controller->processQueue();

			if (false === $last->status) {
				Log::addWarn('Bulk cron: tick refused', $last);
				break;
			}
			$qstatus = isset($last->media) ? $last->media->qstatus : BulkQueue::RESULT_QUEUE_EMPTY;
			if (in_array($qstatus, array(BulkQueue::RESULT_QUEUE_EMPTY, BulkQueue::RESULT_PREPARING_DONE), true)) {
				break;
			}
			if ((microtime(true) - $started) >= (float) $args['timelimit']) {
				Log::addDebug('Bulk cron: over time limit, returning');
				break;
			}
			if ((int) $args['wait'] > 0 && $i < (int) $args['max_runs'] - 1) {
				sleep((int) $args['wait']);
			}
		}

		$this->scheduler();
		return $last;
	}

	/**
	 * Loads the admin functions the tick needs when WP-Cron runs outside wp-admin.
	 *
	 * @return void
	 */
	protected function loadCronCompat()
	{
		if (! function_exists('wp_tempnam') || ! function_exists('download_url')) {
			include_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if (! function_exists('wp_generate_attachment_metadata')) {
			include_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if (! function_exists('wp_delete_attachment_files')) {
			include_once ABSPATH . 'wp-admin/includes/post.php';
		}
	}
}
