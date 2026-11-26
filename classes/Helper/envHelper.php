<?php 

/**
 * Request environment flags.
 *
 * @package enable-media-replace
 */

namespace EnableMediaReplace\Helper;

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Snapshot of the WordPress environment of the current request.
 *
 * Singleton, reached through \emr()->env() (EnableMediaReplacePlugin::env()).
 * The flags are computed once, in the constructor, on the first getInstance()
 * call, and are never refreshed during the request. Registers no hooks.
 *
 * ReplaceController::getNewTargetLocation() reads the site flags
 * (is_multisite, is_mainsite); the bulk's prepare loop and cron loop ask
 * IsOverMemoryLimit() / IsOverTimeLimit(), the port of SPIO's
 * EnvironmentModel methods of the same names, whether the request is close
 * to its budget.
 */
class EnvHelper
{
    /**
     * @var EnvHelper|null The singleton instance.
     */
    protected static $instance;


    /**
     * @var bool True on a multisite network (is_multisite()).
     */
    public $is_multisite = false;
    /**
     * @var bool True when the current site is the network's main site
     *           (is_main_site()); also true on a single-site install.
     */
    public $is_mainsite = false;
    /**
     * @var bool True inside the network admin (is_network_admin()).
     */
    public $is_network_admin = false;
    /**
     * @var bool True during an admin-ajax request (wp_doing_ajax()).
     */
    public $is_ajaxcall = false;
    /**
     * @var bool True when the request expects JSON (wp_is_json_request()).
     */
    public $is_jsoncall = false;
    /**
     * @var bool True during a WP-Cron run (wp_doing_cron()).
     */
    public $is_croncall = false;

    /**
     * @var int Unix time the environment was captured (the start of the process for IsOverTimeLimit()).
     */
    public $executionStart = 0;
    /**
     * @var int PHP's max_execution_time in seconds; 0 means no limit.
     */
    public $executionLimit = 0;
    /**
     * @var int PHP's memory_limit in bytes; -1 means unlimited.
     */
    public $memoryLimit = -1;


    /**
     * Computes the flags. Use getInstance() instead of constructing directly.
     */
    public function __construct()
    {
        $this->setWP();
    }


    /**
     * Returns the singleton, creating it (and computing the flags) on first call.
     *
     * @return EnvHelper
     */
    public static function getInstance()
    {
        if (is_null(self::$instance))
            self::$instance = new EnvHelper();
    
        return self::$instance;
    }


    /**
     * Fills the public flags from the WordPress conditional functions.
     *
     * The multisite functions are guarded with function_exists(); the request
     * type functions are not.
     *
     * @return void
     *
     * @todo wp_is_json_request() exists since WordPress 5.0, but readme.txt
     *       declares 4.9.7 as the minimum, and the call is unguarded, so the
     *       first env() call fatals on 4.9.x. Guard it with function_exists()
     *       or raise the readme minimum to 5.0.
     */
    protected function setWP()
    {
        $this->is_multisite = (function_exists("is_multisite") && is_multisite()) ? true : false;
        $this->is_mainsite = (function_exists('is_main_site') && true === is_main_site()) ? true : false;
        $this->is_network_admin = (function_exists('is_network_admin') && true === is_network_admin()) ? true : false; 

        $this->is_ajaxcall = wp_doing_ajax();
        $this->is_jsoncall = wp_is_json_request();
        $this->is_croncall = wp_doing_cron();

        $memory               = (string) ini_get('memory_limit');
        $this->memoryLimit    = ((int) $memory < 0) ? -1 : (int) wp_convert_hr_to_bytes($memory);
        $this->executionStart = time();
        $this->executionLimit = (int) ini_get('max_execution_time');
    }

    /**
     * Whether the process has crossed its execution-time budget, so a loop
     * (the bulk prepare, the cron run) stops and lets the next tick resume.
     *
     * The threshold is a percentage of the limit (90 by default, filter
     * `emr/process/max_execution`). A limit of 0 or less means no limit.
     * SPIO's EnvironmentModel::IsOverTimeLimit(); the `start` argument is
     * EMR's addition for the cron loop, which measures from its own start
     * rather than from the request's.
     *
     * @hook filter emr/process/max_execution Percentage of the limit that counts as over. Default 90.
     *
     * @param array $args {
     *     @type int $limit Time budget in seconds. Default: max_execution_time.
     *     @type int $start Unix time the budget started. Default: the request start.
     * }
     * @return bool
     */
    public function IsOverTimeLimit($args = array())
    {
        $args  = wp_parse_args($args, array('limit' => $this->executionLimit, 'start' => $this->executionStart));
        $limit = (int) $args['limit'];
        if ($limit <= 0) {
            return false; // no limit
        }

        $elapsed = time() - (int) $args['start'];
        if ($elapsed <= 0) {
            return false;
        }

        $threshold = round($limit / 100 * (int) apply_filters('emr/process/max_execution', 90));
        if ($threshold <= $elapsed) {
            Log::addInfo('Process almost over execution time! - ' . $threshold . ' usage: ' . $elapsed);
            return true;
        }
        return false;
    }

    /**
     * Whether the process is over its memory budget: memory_get_usage()
     * against a percentage of memory_limit (90 by default, filter
     * `emr/process/max_memory`). An unlimited memory_limit (-1) is never over.
     * SPIO's EnvironmentModel::IsOverMemoryLimit().
     *
     * @hook filter emr/process/max_memory Percentage of memory_limit that counts as over. Default 90.
     *
     * @param int $runCount Iteration counter, for the log line only.
     * @return bool
     */
    public function IsOverMemoryLimit($runCount)
    {
        if (-1 === (int) $this->memoryLimit) {
            return false;
        }
        $current    = memory_get_usage();
        $percentage = (int) apply_filters('emr/process/max_memory', 90);
        $limit      = round($this->memoryLimit / 100 * $percentage);

        if ($current >= $limit) {
            Log::addInfo('Process over maximum memory! ' . (int) $runCount . ' runs - Current Mem / Limit ' . $current . ' ' . $limit . ' ( ' . $percentage . ' %)');
            return true;
        }
        return false;
    }


    


} // class 