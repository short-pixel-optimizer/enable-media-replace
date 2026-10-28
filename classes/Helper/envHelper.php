<?php 

/**
 * Request environment flags.
 *
 * @package enable-media-replace
 */

namespace EnableMediaReplace\Helper;


/**
 * Snapshot of the WordPress environment of the current request.
 *
 * Singleton, reached through \emr()->env() (EnableMediaReplacePlugin::env()).
 * The flags are computed once, in the constructor, on the first getInstance()
 * call, and are never refreshed during the request. Registers no hooks.
 *
 * Currently only ReplaceController::getNewTargetLocation() reads it
 * (is_multisite, is_mainsite).
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
    }


    


} // class 