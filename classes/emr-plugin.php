<?php
/**
 * Main plugin class.
 *
 * @package enable-media-replace
 */

namespace EnableMediaReplace;

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Notices\NoticeController as Notices;
use EnableMediaReplace\FileSystem\Controller\FileSystemController as FileSystem;
use EnableMediaReplace\Controller\RemoteNoticeController as RemoteNoticeController;
use EnableMediaReplace\Ajax;
use EnableMediaReplace\Helper\EnvHelper;

// Does what a plugin does.
/**
 * Plugin singleton: hooks, routing, permissions and the media-library UI.
 *
 * Created by get(), which the root file calls through \emr() at load time.
 * The constructor hooks `init` (runtime()) and `admin_init` (adminInit()).
 *
 * runtime() always registers the `[file_modified]` shortcode; then, only for
 * users who pass the EMR_CAPABILITY check (or `upload_files` when the
 * constant is false), it loads Externals and registers the admin UI in
 * plugin_actions(): the hidden "Replace media" page under Media and its
 * router route(), the media row actions, the attachment edit meta boxes, the
 * attachment-fields button, the "Revised" / "Replaced By" lines, script and
 * style registration, notices on the media screens, and the image cache
 * busting after a replacement.
 *
 * checkImagePermission() is the per-attachment permission gate used by the
 * UI here, the view controllers and the API.
 *
 * State: the capabilities read from EMR_CAPABILITY and the feature flags.
 */
class EnableMediaReplacePlugin
{

    /**
     * @var string|null Plugin directory with trailing slash. Set in
     *                  plugin_actions(); null for users who do not get the UI.
     */
    protected $plugin_path;
    /**
     * @var EnableMediaReplacePlugin|null The singleton instance.
     */
    private static $instance;

    /**
     * @var string|false Capability that allows replacing only the user's own
     *                   attachments: the second element of an array
     *                   EMR_CAPABILITY, else false. Set in runtime().
     */
    private $user_cap = false;
    /**
     * @var string|false Capability that allows replacing every attachment:
     *                   a string EMR_CAPABILITY or the first element of an
     *                   array one, else false. Set in runtime().
     */
    private $general_cap = false;

    /**
     * @var array<string,bool> Feature flags filled by adminInit(): 'replace'
     *                         (always true, unused), 'background' and
     *                         'remote_notice'. Read through useFeature().
     */
    private $features = array();

    /**
     * Hooks the plugin into WordPress. Use get() / \emr() instead.
     *
     * @hook action init        runtime()
     * @hook action admin_init  adminInit()
     */
    public function __construct()
    {
        add_action('init', array($this, 'runtime'));
        // add_action('init', [$this, 'init']);  // init for user authentication, not set on plugins_loaded.
        add_action('admin_init', array($this, 'adminInit')); // adminInit, after functions.php
    }

    /**
     * `init` callback: registers the shortcode, checks access, loads the UI.
     *
     * Registers `[file_modified]` for everyone, then reads EMR_CAPABILITY:
     * - false: returns unless the user has `upload_files`; both stored caps
     *   stay false.
     * - array: stores [0] as $general_cap and [1] as $user_cap; returns
     *   unless the user has either.
     * - string: stores it as $general_cap; returns unless the user has it.
     * Otherwise it instantiates Externals and calls plugin_actions(). Runs on
     * front-end requests too, so logged-in users with access load Externals
     * and the admin hooks there as well.
     *
     * The stored caps are set before the access check, so they are set even
     * for users who are turned away.
     *
     * @uses EMR_CAPABILITY
     * @return void
     *
     * @hook action init (listened to, via the constructor)
     */
    public function runtime()
    {
        $this->nopriv_plugin_actions();

        if (EMR_CAPABILITY !== false) {
            if (is_array(EMR_CAPABILITY)) {
                $this->general_cap = EMR_CAPABILITY[0];
                $this->user_cap = EMR_CAPABILITY[1];

                if (! current_user_can($this->general_cap) && ! current_user_can($this->user_cap)) {
                    return;
                }
            } else {
                $this->general_cap = EMR_CAPABILITY;
                if (! current_user_can($this->general_cap)) {
                    return;
                }
            }
        } elseif (false === current_user_can('upload_files')) {
            return;
        }

        new Externals();

        $this->plugin_actions(); // init
    }

    /**
     * `admin_init` callback: fills the feature flags and loads the AJAX handlers.
     *
     * Also called from useFeature() when the flags are still empty, which
     * happens outside admin requests.
     *
     * @return void
     *
     * @hook action admin_init (listened to, via the constructor)
     * @hook filter emr/feature/background    Enables the remove-background feature. Default true.
     * @hook filter emr/feature/remote_notice Enables the remote notices. Default true.
     *
     * @todo Each call creates a new Ajax instance, which registers every
     *       `wp_ajax_emr_*` endpoint again; classes/ajax.php already
     *       instantiates Ajax at require time, so on admin requests each
     *       handler is hooked twice. When useFeature() runs this method
     *       (flags still empty), the side effect also happens there.
     *       Instantiate Ajax once and keep the feature-flag setup separate
     *       from it.
     */
    public function adminInit()
    {
        $this->features['replace']  = true; // does nothing just for completeness
        $this->features['background'] = apply_filters('emr/feature/background', true);
        $this->features['upscale'] = apply_filters('emr/feature/upscale', true);
        $this->features['remote_notice']  = apply_filters('emr/feature/remote_notice', true);

        // Load Submodules
        new Ajax();
    }

    /**
     * Returns a new FileSystemController of the bundled filesystem module.
     *
     * A new instance on every call.
     *
     * @return FileSystem
     */
    public function filesystem()
    {
        return new FileSystem();
    }

    /**
     * Returns the UIHelper singleton.
     *
     * Written as `Uihelper`; class names are case-insensitive.
     *
     * @return UIHelper
     */
    public function uiHelper()
    {
        return Uihelper::getInstance();
    }

    /**
     * Returns the EnvHelper singleton (request environment flags).
     *
     * Written as `envHelper`, resolved case-insensitively through the
     * `use EnableMediaReplace\Helper\EnvHelper` import.
     *
     * @return EnvHelper
     */
    public function env()
    {
        return envHelper::getInstance();
    }

    /**
     * Tells whether an optional feature is enabled for the current request.
     *
     * Fills the flags through adminInit() when they are still empty (before
     * `admin_init`, or on front-end requests).
     * - 'background': the `emr/feature/background` flag, forced to false for
     *   author-restricted users (UIHelper::is_author_restricted()).
     * - 'remote_notice': the `emr/feature/remote_notice` flag.
     * - anything else: false.
     *
     * @param string $name Feature name.
     * @return bool
     */
    public function useFeature($name)
    {
        // If for some obscure reason, it's called earlier or out of admin, still load the features.
        if (count($this->features) === 0) {
            $this->adminInit();
        }

        switch ($name) {
            case 'background':
            case 'upscale':
                $bool = $this->features[$name];
                if (true == $bool)
                {
                	$uiHelper = \emr()->uiHelper();
                	$author_restricted = $uiHelper->is_author_restricted();

                    if (true === $author_restricted)
                    {
                         $bool = false; 
                    }

                }
                break;
            case 'remote_notice':
                $bool = $this->features['remote_notice'];
                break;
            default:
                $bool = false;
                break;
        }
        return $bool;
    }

    /**
     * Returns the singleton, creating it on first call.
     *
     * On every call, when the bundled logger's debug mode is active, it also
     * sets the log path to `emr_log` in the uploads base directory.
     *
     * @return EnableMediaReplacePlugin
     */
    public static function get()
    {
        if (is_null(self::$instance)) {
            self::$instance = new EnableMediaReplacePlugin();
        }

        $log = Log::getInstance();
        if (Log::debugIsActive()) {
            $uploaddir = wp_upload_dir(null, false, false);
            if (isset($uploaddir['basedir'])) {
                $log->setLogPath(trailingslashit($uploaddir['basedir']) . "emr_log");
            }
        }
        return self::$instance;
    }

    // Actions for EMR that always need to hook
    /**
     * Registers the hooks that load for every visitor: the `[file_modified]`
     * shortcode (get_modified_date()).
     *
     * @return void
     */
    protected function nopriv_plugin_actions()
    {
        // shortcode
        add_shortcode('file_modified', array($this, 'get_modified_date'));
    }


    /**
     * Registers the admin UI hooks for users who passed the access check.
     *
     * Called from runtime(). Also loads the notices controller (which hooks
     * the notice dismiss handler).
     *
     * When the request has `emr_replaced=1` (the success redirect from
     * UIHelper::getSuccesRedirect()), it also adds a `time` query argument to
     * every attachment image URL on that request (browser cache busting) and
     * renders the "Replaced Thumbnails Preview" meta box closed.
     *
     * @return void
     *
     * @hook action admin_menu                         menu()
     * @hook filter submenu_file                       hide_sub_menu() (registered with add_action)
     * @hook action current_screen                     setScreen()
     * @hook action admin_enqueue_scripts              admin_scripts()
     * @hook filter media_row_actions                  add_media_action()
     * @hook action attachment_submitbox_misc_actions  admin_date_replaced_media_on_edit_media_screen(), priority 91
     * @hook action add_meta_boxes_attachment          add_meta_boxes()
     * @hook filter attachment_fields_to_edit          attachment_editor()
     * @hook filter wp_get_attachment_image_src        attempt_uncache_image(), only with emr_replaced=1
     * @hook filter postbox_classes_attachment_emr-showthumbs-box Adds 'closed', only with emr_replaced=1
     *
     * @todo The `emr_replaced` check is written `intval($_GET['emr_replaced'] == 1)`:
     *       the parenthesis closes after the loose comparison, so intval()
     *       wraps a bool and any value that loosely equals 1 passes. Write
     *       `intval($_GET['emr_replaced']) === 1`. Same in add_meta_boxes().
     */
    public function plugin_actions()
    {
        $this->plugin_path = plugin_dir_path(EMR_ROOT_FILE);
        //$this->plugin_url = plugin_dir_url(EMR_ROOT_FILE);

        // loads the dismiss hook.
        $notices = Notices::getInstance();

        // init plugin
        add_action('admin_menu', array($this, 'menu'));
        add_action('submenu_file', array($this, 'hide_sub_menu'));

        add_action('current_screen', array($this, 'setScreen')); // annoying workaround for notices in edit-attachment screen
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));


        // content filters
        add_filter('media_row_actions', array($this, 'add_media_action'), 10, 2);
        add_action('attachment_submitbox_misc_actions', array($this, 'admin_date_replaced_media_on_edit_media_screen'), 91);

        // notices

        // editors
        add_action('add_meta_boxes_attachment', array($this, 'add_meta_boxes'), 10, 2);
        add_filter('attachment_fields_to_edit', array($this, 'attachment_editor'), 10, 2);

        /** Just after an image is replaced, try to browser decache the images */
        if (isset($_GET['emr_replaced']) && intval($_GET['emr_replaced'] == 1)) {
            add_filter('wp_get_attachment_image_src', array($this, 'attempt_uncache_image'), 10, 4);

            // adds a metabox to list thumbnails. This is a cache reset hidden as feature.
            //add_action( 'add_meta_boxes', function () {  );
            add_filter('postbox_classes_attachment_emr-showthumbs-box', function ($classes) {
                $classes[] = 'closed';
                return $classes;
            });
        }
    }

    /**
     * Register this file in WordPress so we can call it with a ?page= GET var.
     * To suppress it in the menu we give it an empty menu title.
     *
     * The page is registered under upload.php with the slug
     * 'enable-media-replace/enable-media-replace.php' and route() as its
     * callback. Its title is "Replace media", or "Remove background" when
     * the request action is 'emr_prepare_remove'. The menu title is not
     * empty; hide_sub_menu() removes the entry instead.
     *
     * @return void
     *
     * @hook action admin_menu (listened to)
     *
     * @todo The page requires `upload_files` regardless of EMR_CAPABILITY, so
     *       a user granted access only through a custom EMR_CAPABILITY sees
     *       the row actions and buttons but is refused by WordPress on the
     *       page. Register the page with the capability runtime() checked.
     */
    public function menu()
    {
        $title =  esc_html__("Replace media", "enable-media-replace");
        $title = (isset($_REQUEST['action']) && ($_REQUEST['action'] === 'emr_prepare_remove')) ? esc_html__("Remove background", "enable-media-replace") : $title;
        $title = (isset($_REQUEST['action']) && ($_REQUEST['action'] === 'emr_prepare_upscale')) ? esc_html__("Upscale image", "enable-media-replace") : $title;
        add_submenu_page('upload.php', $title, $title, 'upload_files', 'enable-media-replace/enable-media-replace.php', array($this, 'route'));
    }

    /**
     * Hides the EMR page from the Media menu and keeps Media highlighted.
     *
     * On the EMR page it returns 'upload.php' as the highlighted submenu
     * file; on every admin page it removes the EMR submenu entry.
     *
     * @global string $plugin_page Current plugin page slug.
     *
     * @param string|null $submenu_file Submenu file to highlight.
     * @return string|null
     *
     * @hook filter submenu_file (listened to)
     */
    public function hide_sub_menu($submenu_file)
    {
        global $plugin_page;
        // Select another submenu item to highlight (optional).
        if ($plugin_page && $plugin_page == 'enable-media-replace/enable-media-replace.php') {
            $submenu_file = 'upload.php';
        }

        // Hide the submenu.

        remove_submenu_page('upload.php', 'enable-media-replace/enable-media-replace.php');

        return $submenu_file;
    }


    /**
     * Loads EMR notices on the media screens.
     *
     * Only on the screen ids 'attachment' (edit media),
     * 'media_page_enable-media-replace/enable-media-replace' (the EMR page)
     * and 'upload' (media library), and only when
     * useFeature('remote_notice') is true: it runs the remote notice check
     * (RemoteNoticeController), sets the notice icons and prints the notices
     * on `admin_notices`. On every other screen EMR prints no notices.
     *
     * @return void
     *
     * @hook action current_screen (listened to)
     * @hook action admin_notices  NoticeController::admin_notices()
     */
    public function setScreen()
    {
        $screen = get_current_screen();

        $notice_pages = array('attachment',  'media_page_enable-media-replace/enable-media-replace', 'upload');
        if (in_array($screen->id, $notice_pages) &&    true === emr()->useFeature('remote_notice')) {
            RemoteNoticeController::getInstance(); // check for remote stuff
            $notices = Notices::getInstance();
            $notices->loadIcons(array(
                'normal' => '<img class="emr-notice-icon" src="' . plugins_url('img/notices/slider.png', EMR_ROOT_FILE) . '">',
                'success' => '<img class="emr-notice-icon" src="' . plugins_url('img/notices/robo-cool.png', EMR_ROOT_FILE) . '">',
                'warning' => '<img class="emr-notice-icon" src="' . plugins_url('img/notices/robo-scared.png', EMR_ROOT_FILE) . '">',
                'error' => '<img class="emr-notice-icon" src="' . plugins_url('img/notices/robo-scared.png', EMR_ROOT_FILE) . '">',
            ));

            add_action('admin_notices', array($notices, 'admin_notices')); // previous page / init time
        }
    }

    /** Load EMR views based on request
     *
     * Page callback of the hidden EMR page. Enqueues the shared styles and
     * scripts (emr_style, emr-redesign, jquery-ui-datepicker, emr_admin),
     * queues the feature notice, then dispatches on `$_GET['action']`:
     * - 'media_replace' with an `attachment_id` > 0:
     *   ReplaceViewController::load() (the replace form), plus emr_upsell.
     *   Without a valid attachment_id nothing is rendered.
     * - 'media_replace_upload': UploadViewController::load() (form POST).
     * - 'emr_prepare_remove', when useFeature('background'):
     *   RemoveBackgroundViewController::load(), plus emr_remove_bg,
     *   emr-remove-background and emr_upsell.
     * - 'do_background_replace', when useFeature('background'):
     *   RemoveBackgroundViewController::loadPost().
     * - anything else (including the two background actions with the
     *   feature off): exit() with a plain "Something went wrong" message.
     *
     * No nonce or permission check happens here; the view controllers do
     * their own.
     *
     * @global string $plugin_page Current plugin page slug.
     *
     * @return void
     *
     * @todo It enqueues the style handle 'jquery-ui-datepicker', which neither
     *       WordPress core nor EMR registers, so that call loads nothing.
     *       Register a datepicker stylesheet under that handle or drop the
     *       call.
     */
    public function route()
    {
        global $plugin_page;
        switch ($plugin_page) {
            case 'enable-media-replace/enable-media-replace.php':
                $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
                wp_enqueue_style('emr_style');
                wp_enqueue_style('emr-redesign');
                wp_enqueue_script('jquery-ui-datepicker');
                wp_enqueue_style('jquery-ui-datepicker');
                wp_enqueue_script('emr_admin');

                $this->uiHelper()->featureNotice();

                if ($action == 'media_replace') {
                    if (array_key_exists("attachment_id", $_GET) && intval($_GET["attachment_id"]) > 0) {
                        wp_enqueue_script('emr_upsell');

                        $controller = \EnableMediaReplace\ViewController\ReplaceViewController::getInstance();
                        $controller->load();
                        //                       require_once($this->plugin_path . "views/popup.php"); // warning variables like $action be overwritten here.
                    }
                } elseif ($action == 'media_replace_upload') {

                    $controller = \EnableMediaReplace\ViewController\UploadViewController::getInstance();
                    $controller->load();
                    //  require_once($this->plugin_path . 'views/upload.php');
                } elseif ('emr_prepare_remove' === $action && $this->useFeature('background')) {
                    //										$attachment_id = intval($_GET['attachment_id']);
                    //										$attachment    = get_post($attachment_id);
                    //We're adding a timestamp to the image URL for cache busting

                    wp_enqueue_script('emr_remove_bg');

                    wp_enqueue_style('emr_style');
                    wp_enqueue_style('emr-remove-background');
                    wp_enqueue_script('emr_upsell');

                    $controller = \EnableMediaReplace\ViewController\RemoveBackgroundViewController::getInstance();
                    $controller->load();

                    //	require_once($this->plugin_path . "views/prepare-remove-background.php");

                } elseif (
                    'do_background_replace' === $action &&
                    $this->useFeature('background')
                ) {
                    $controller = \EnableMediaReplace\ViewController\RemoveBackgroundViewController::getInstance();
                    $controller->loadPost();

                    //	require_once($this->plugin_path . 'views/do-replace-background.php');
                } elseif ('emr_prepare_upscale' === $action && $this->useFeature('upscale')) {
                    wp_enqueue_script('emr_remove_bg'); // Same preview flow, sends the upscale factor.
                    wp_enqueue_style('emr-remove-background');
                    wp_enqueue_script('emr_upsell');

                    $controller = \EnableMediaReplace\ViewController\UpscaleViewController::getInstance();
                    $controller->load();
                } elseif ('do_upscale_replace' === $action && $this->useFeature('upscale')) {
                    $controller = \EnableMediaReplace\ViewController\UpscaleViewController::getInstance();
                    $controller->loadPost();
                } else {

                    exit('Something went wrong loading page, please try again');
                }
                break;
        } // Route
    }

    /**
     * Returns the URL of a file inside the plugin directory.
     *
     * @param string $path Path relative to the plugin root.
     * @return string URL.
     */
    public function getPluginURL($path = '')
    {
        return plugins_url($path, EMR_ROOT_FILE);
    }

    /**
     * Returns the absolute path of a file inside the plugin directory.
     *
     * @param string $path Path relative to the plugin root; '' for the root
     *                     itself (with trailing slash).
     * @return string Absolute path.
     */
    public function plugin_path($path = '')
    {
        $plugin_path = trailingslashit(plugin_dir_path(EMR_ROOT_FILE));
        if (strlen($path) > 0) {
            $plugin_path .= $path;
        }
        return $plugin_path;
    }

    /** register styles and scripts
     *
     * Nothing should ever by -enqueued- here, just registered.
     *
     * Runs on every admin page. route() and show_thumbs_box() enqueue what
     * they need.
     *
     * Styles registered: emr_style (css/admin.css, or css/rtl/admin.css for
     * RTL), emr_edit-attachment, emr-remove-background, and emr-redesign
     * (depends on emr_style; versioned by file mtime).
     *
     * Scripts registered and their localised objects:
     * - emr_admin (js/emr_admin.js) -> `emr_options`: dateFormat (from
     *   convertdate()), maxfilesize (wp_max_upload_size()), allowed_mime
     *   (values of get_allowed_mime_types()), and is_debug when the logger
     *   debug mode is active.
     * - emr_upsell (js/upsell.js) -> `emr_upsell`: ajax URL and the
     *   "Installing ..." label.
     * - emr_remove_bg (js/remove_bg.js, versioned by file mtime) ->
     *   `emrObject`: ajax_url and a nonce for 'emr_remove_background'.
     * - emr_success (js/emr_success.js) -> `emr_success_options`: timeout.
     *
     * @return void
     *
     * @hook action admin_enqueue_scripts (listened to)
     * @hook filter emr/success/timeout Seconds before the success screen redirects. Default 5.
     */
    public function admin_scripts()
    {
        if (is_rtl()) {
            wp_register_style('emr_style', plugins_url('css/rtl/admin.css', EMR_ROOT_FILE));
        } else {
            wp_register_style('emr_style', plugins_url('css/admin.css', EMR_ROOT_FILE));
        }

        wp_register_style('emr_edit-attachment', plugins_url('css/edit_attachment.css', EMR_ROOT_FILE));

        wp_register_style('emr-remove-background', plugins_url('css/remove_background.css', EMR_ROOT_FILE));

        $emr_redesign_path = plugin_dir_path(EMR_ROOT_FILE) . 'css/redesign.css';
        $emr_redesign_ver  = file_exists($emr_redesign_path) ? filemtime($emr_redesign_path) : EMR_VERSION;
        wp_register_style('emr-redesign', plugins_url('css/redesign.css', EMR_ROOT_FILE), array('emr_style'), $emr_redesign_ver);

        $mimes = array_values(get_allowed_mime_types());

        wp_register_script('emr_admin', plugins_url('js/emr_admin.js', EMR_ROOT_FILE), array('jquery'), EMR_VERSION, true);
        $emr_options = array(
            'dateFormat' => $this->convertdate(get_option('date_format')),
            'maxfilesize' => wp_max_upload_size(),
            'allowed_mime' => $mimes,
        );

        wp_register_script('emr_upsell', plugins_url('js/upsell.js', EMR_ROOT_FILE), array('jquery'), EMR_VERSION, true);

        wp_localize_script('emr_upsell', 'emr_upsell', array(
            'ajax' => admin_url('admin-ajax.php'),
            'installing' => __('Installing ...', 'enable-media-replace'),

        ));

        $ts = time();
        $ajax_url = admin_url('admin-ajax.php');


        $emr_remove_bg_path = plugin_dir_path(EMR_ROOT_FILE) . 'js/remove_bg.js';
        $emr_remove_bg_ver  = file_exists($emr_remove_bg_path) ? filemtime($emr_remove_bg_path) : EMR_VERSION;
        wp_register_script('emr_remove_bg', plugins_url('js/remove_bg.js', EMR_ROOT_FILE), array('jquery'), $emr_remove_bg_ver, true);
        wp_localize_script('emr_remove_bg', 'emrObject', array(
            'ajax_url' => $ajax_url,
            'nonce'    => wp_create_nonce('emr_remove_background')
        ));

        $emr_account_path = plugin_dir_path(EMR_ROOT_FILE) . 'js/shortpixel-account.js';
        $emr_account_ver  = file_exists($emr_account_path) ? filemtime($emr_account_path) : EMR_VERSION;
        wp_register_script('emr_shortpixel_account', plugins_url('js/shortpixel-account.js', EMR_ROOT_FILE), array(), $emr_account_ver, true);
        wp_localize_script('emr_shortpixel_account', 'emrAccount', array(
            'ajax_url' => $ajax_url,
            'nonce'    => wp_create_nonce('emr_save_api_key'),
            'strings'  => array(
                'verifying'      => __('Verifying…', 'enable-media-replace'),
                'request_failed' => __('Request failed. Please try again.', 'enable-media-replace'),
            ),
        ));


        if (Log::debugIsActive()) {
            $emr_options['is_debug'] = true;
        }

        wp_localize_script('emr_admin', 'emr_options', $emr_options);

        wp_register_script('emr_success', plugins_url('js/emr_success.js', EMR_ROOT_FILE), array(), EMR_VERSION, true);

        wp_localize_script('emr_success', 'emr_success_options', array(
            'timeout' => apply_filters('emr/success/timeout', 5),
        ));
    }

    /** Utility function for the Jquery UI Datepicker
     *
     * Maps the four WordPress preset date formats to jQuery UI datepicker
     * formats: 'F j, Y' -> 'MM dd, yy', 'Y/m/d' -> 'yy/mm/dd',
     * 'm/d/Y' -> 'mm/dd/yy', and 'd/m/Y' or any other (custom) format ->
     * 'dd/mm/yy'.
     *
     * @param string $sFormat PHP date format (the `date_format` option).
     * @return string jQuery UI datepicker format.
     */
    public function convertdate($sFormat)
    {
        switch ($sFormat) {
            //Predefined WP date formats
            case 'F j, Y':
                return ('MM dd, yy');
                break;
            case 'Y/m/d':
                return ('yy/mm/dd');
                break;
            case 'm/d/Y':
                return ('mm/dd/yy');
                break;
            case 'd/m/Y':
            default:
                return ('dd/mm/yy');
            break;
        }
    }

    /**
     * Tells whether the current user may replace this attachment.
     *
     * The permission gate used by the row actions, meta boxes and attachment
     * fields here, the view controllers and Api::request(). Returns false for
     * a non-object or a post that is not an attachment. Then, depending on
     * the caps runtime() stored from EMR_CAPABILITY:
     * - both false (EMR_CAPABILITY false): current_user_can('edit_post', $id).
     * - $general_cap held: true for every attachment, no per-post check.
     * - $user_cap held (array EMR_CAPABILITY only): true when the attachment's
     *   post_author is the current user.
     * Otherwise false. The caps are only set on `init`, so a call before
     * `init` behaves as if EMR_CAPABILITY were false.
     *
     * @uses EMR_CAPABILITY (through $general_cap / $user_cap)
     *
     * @param \WP_Post|mixed $post Attachment post object.
     * @return bool
     *
     * @todo The ID guard reads `intval($post_id) > ! 0`. `! 0` is true, so the
     *       int is compared with a bool and the condition is never true; the
     *       guard never rejects anything. Write `intval($post_id) <= 0`.
     */
    public function checkImagePermission($post) : bool
    {
        if (! is_object($post)) {
            return false;
        }
        $post_id = $post->ID;
        $post_type = $post->post_type;
        $author_id = $post->post_author;

        if ($post_type !== 'attachment')
            return false;

        if (is_null($post_id) || intval($post_id) > ! 0) {
            return false;
        }

        if ($this->general_cap === false && $this->user_cap === false) {
            if (current_user_can('edit_post', $post_id)  === true) {
                return true;
            }
        } elseif (current_user_can($this->general_cap)) {
            return true;
        } elseif (current_user_can($this->user_cap) && $author_id == get_current_user_id()) {
            return true;
        }

        return false;
    }

    /** Get the URL to the media replace page
     *
     * The URL carries no nonce; callers add one with wp_nonce_url() where
     * they want it (add_media_action() does not).
     *
     * @param int $attach_id The attachment ID to replace
     * @return string Admin URL to the page.
     */
    protected function getMediaReplaceURL($attach_id)
    {
        $url = admin_url("upload.php");
        $url = add_query_arg(array(
            'page' => 'enable-media-replace/enable-media-replace.php',
            'action' => 'media_replace',
            'attachment_id' => $attach_id,
        ), $url);

        return $url;
    }

    /**
     * Get the URL to the remove-background page (action 'emr_prepare_remove').
     *
     * The URL carries no nonce; every caller wraps it in wp_nonce_url().
     *
     * @param int $attach_id Attachment ID.
     * @return string Admin URL to the page.
     */
    protected function getRemoveBgURL($attach_id)
    {
        $url = admin_url("upload.php");
        $url = add_query_arg(array(
            'page' => 'enable-media-replace/enable-media-replace.php',
            'action' => 'emr_prepare_remove',
            'attachment_id' => $attach_id,
        ), $url);

        return $url;
    }

    protected function getUpscaleURL($attach_id)
    {
        $url = admin_url("upload.php");
        $url = add_query_arg(array(
            'page' => 'enable-media-replace/enable-media-replace.php',
            'action' => 'emr_prepare_upscale',
            'attachment_id' => $attach_id,
        ), $url);

        return $url;
    }

    /**
     * Adds the EMR meta boxes to the attachment edit screen.
     *
     * When checkImagePermission() passes: the "Replace Media" side box
     * (replace_meta_box()), and with `emr_replaced=1` in the request also the
     * "Replaced Thumbnails Preview" side box (show_thumbs_box()).
     *
     * @param \WP_Post|null $post Attachment being edited.
     * @return false|void False for a missing post object.
     *
     * @hook action add_meta_boxes_attachment (listened to; registered with
     *       2 accepted args, the hook passes 1)
     */
    public function add_meta_boxes($post)
    {
        // Because some plugins don't like to play by the rules.
        if (is_null($post) || ! is_object($post)) {
            return false;
        }

        if (! $this->checkImagePermission($post)) {
            return;
        }

        add_meta_box('emr-replace-box', __('Replace Media', 'enable-media-replace'), array($this, 'replace_meta_box'), 'attachment', 'side', 'low');

        if (isset($_GET['emr_replaced']) && intval($_GET['emr_replaced'] == 1)) {
            add_meta_box('emr-showthumbs-box', __('Replaced Thumbnails Preview', 'enable-media-replace'), array($this, 'show_thumbs_box'), 'attachment', 'side', 'low');
        }
    }

    /**
     * Prints the "Replace Media" meta box.
     *
     * An "Upload a new file" button to the replace screen (nonce
     * 'media_replace'), and a "Remove background" button (nonce
     * 'emr_prepare_remove') when UIHelper::isBackgroundRemovable() is true.
     *
     * @param \WP_Post $post Attachment being edited.
     * @return void
     */
    public function replace_meta_box($post)
    {

        //Replace media button
        $replace_url = $this->getMediaReplaceURL($post->ID);

        $replace_action = "media_replace";
        $replace_editurl = wp_nonce_url($replace_url, $replace_action);

        $replace_link = "href=\"$replace_editurl\"";

        echo "<p><a class='button-secondary' $replace_link>" . esc_html__("Upload a new file", "enable-media-replace") . "</a></p><p>" . esc_html__("To replace the current file, click the link and upload a replacement file.", "enable-media-replace") . "</p>";
        //Remove background button
        $removeBg_url = $this->getRemoveBgURL($post->ID);

        $removeBg_action = "emr_prepare_remove";
        $removeBg_editurl = wp_nonce_url($removeBg_url, $removeBg_action);

        $removeBg_link = "href=\"$removeBg_editurl\"";

        if ($this->uiHelper()->isBackgroundRemovable($post)) {
            echo "<p><a class='button-secondary' $removeBg_link>" . esc_html__("Remove background", "enable-media-replace") . "</a></p><p>" . esc_html__("To remove the background, click the link and select the options.", "enable-media-replace") . "</p>";
        }

        //Upscale button
        if ($this->uiHelper()->isUpscalable($post)) {
            $upscale_link = "href=\"" . wp_nonce_url($this->getUpscaleURL($post->ID), 'emr_prepare_upscale') . "\"";
            echo "<p><a class='button-secondary' $upscale_link>" . esc_html__("Upscale image", "enable-media-replace") . "</a></p><p>" . esc_html__("To enlarge a small image, click the link and select the options.", "enable-media-replace") . "</p>";
        }
    }

    /**
     * Prints the "Replaced Thumbnails Preview" meta box.
     *
     * Shown after a replacement (`emr_replaced=1`); loading every size here
     * also refreshes the browser cache of the thumbnails, since
     * attempt_uncache_image() adds a `time` argument on that request. Prints
     * one labelled preview per entry in the metadata `sizes`, or "Thumbnails
     * were not generated" when there are none. On WordPress 5.3+ (detected
     * with function_exists('wp_get_original_image_url')), when the original
     * image URL differs from wp_get_attachment_url() it also prints the
     * wp_get_attachment_url() image (the `-scaled` file) labelled "Original".
     *
     * @param \WP_Post $post Attachment being edited.
     * @return false|void False when there are no sizes.
     *
     * @todo The URLs and size names are printed without esc_url() /
     *       esc_attr(); escape them.
     * @todo The "Original" label is shown with the wp_get_attachment_url()
     *       image, which is the `-scaled` file when scaling happened, while
     *       $source_url (the real original) is not shown. Print $source_url
     *       if the box is meant to show the original. The label also uses
     *       __('Original') without the plugin text domain.
     * @todo The function_exists() guard is needed while readme.txt declares
     *       WordPress 4.9.7; drop it when the minimum is raised to 5.3+.
     */
    public function show_thumbs_box($post)
    {
        if (! $this->checkImagePermission($post)) {
            return;
        }

        wp_enqueue_style('emr_edit-attachment');

        $meta = wp_get_attachment_metadata($post->ID);

        if (! isset($meta['sizes'])) {
            echo __('Thumbnails were not generated', 'enable-media-replace');
            return false;
        }

        if (function_exists('wp_get_original_image_url')) { // indicating WP 5.3+
            $source_url = wp_get_original_image_url($post->ID);
            // oldway will give -scaled in case of scaling.
            $source_url_oldway = wp_get_attachment_url($post->ID);

            if ($source_url !== $source_url_oldway) {
                echo "<div class='original previewwrapper'><img src='" . $source_url_oldway . "'><span class='label'>" . __('Original') . "</span></div>";
            }
        }


        foreach ($meta['sizes'] as $size => $data) {
            $display_size = ucfirst(str_replace("_", " ", $size));
            $img = wp_get_attachment_image_src($post->ID, $size);
            echo "<div class='$size previewwrapper'><img src='" . $img[0] . "'><span class='label'>$display_size</span></div>";
        }
    }

    /**
     * Adds the EMR buttons to the attachment fields (media modal and
     * attachment details).
     *
     * Skipped when checkImagePermission() fails, and on the 'attachment'
     * edit screen, which has the meta box instead. Adds the
     * `enable-media-replace` field (replace button, nonce 'media_replace')
     * and, when the background is removable and the user is not author
     * restricted, the `emr-remove-background` field (nonce
     * 'emr_prepare_remove'). The author check repeats the one already
     * inside isBackgroundRemovable().
     *
     * @param array    $form_fields Attachment form fields.
     * @param \WP_Post $post        Attachment.
     * @return array Form fields.
     *
     * @hook filter attachment_fields_to_edit (listened to)
     */
    public function attachment_editor($form_fields, $post)
    {
        $screen = null;

        if (! $this->checkImagePermission($post)) {
            return $form_fields;
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if (! is_null($screen) && $screen->id == 'attachment') { // hide on edit attachment screen.
                return $form_fields;
            }
        }

        $url = $this->getMediaReplaceURL($post->ID);
        $action = "media_replace";
        $editurl = wp_nonce_url($url, $action);

        $link = "href=\"$editurl\"";
        $form_fields["enable-media-replace"] = array(
            "label" => esc_html__("Replace media", "enable-media-replace"),
            "input" => "html",
            "html" => "<a class='button-secondary' $link>" . esc_html__("Upload a new file", "enable-media-replace") . "</a>",
            "helps" => esc_html__("To replace the current file, click the link and upload a replacement file.", "enable-media-replace")
        );

        $uiHelper = $this->uiHelper(); 

        if ($uiHelper->isBackgroundRemovable($post) && false === $uiHelper->is_author_restricted() ) {
            $link = $this->getRemoveBgURL($post->ID);
            $link = "href='" . wp_nonce_url($link, 'emr_prepare_remove') . "'";
            $form_fields["emr-remove-background"] = array(
                "label" => esc_html__("Remove background", "enable-media-replace"),
                "input" => "html",
                "html" => "<a class='button-secondary' $link>" . esc_html__("Remove background", "enable-media-replace") . "</a>",
                "helps" => esc_html__("To remove the background, click the link.", "enable-media-replace")
            );
        }

        if ($uiHelper->isUpscalable($post)) {
            $link = "href='" . wp_nonce_url($this->getUpscaleURL($post->ID), 'emr_prepare_upscale') . "'";
            $form_fields["emr-upscale"] = array(
                "label" => esc_html__("Upscale image", "enable-media-replace"),
                "input" => "html",
                "html" => "<a class='button-secondary' $link>" . esc_html__("Upscale image", "enable-media-replace") . "</a>",
                "helps" => esc_html__("To enlarge a small image, click the link.", "enable-media-replace")
            );
        }
        return $form_fields;
    }

    /**
     * Function called by filter 'media_row_actions'
     * Enables linking to EMR straight from the media library
     *
     * Adds a "Replace media" row action (when checkImagePermission() passes)
     * and a "Remove background" row action (when the background is
     * removable and the user is not author restricted). The replace link has
     * no nonce ($media_replace_action is set but unused); the replace screen
     * does not check one. The remove-background link carries the
     * 'emr_prepare_remove' nonce.
     *
     * @param array    $actions Row actions.
     * @param \WP_Post $post    Attachment of the row.
     * @return array Row actions with EMR's appended.
     *
     * @hook filter media_row_actions (listened to)
     *
     * @todo The "Remove  background" label and aria-label contain a double
     *       space, which makes them separate translation strings from the
     *       "Remove background" used elsewhere. Use a single space.
     */
    public function add_media_action($actions, $post)
    {

        if (! $this->checkImagePermission($post)) {
            return $actions;
        }

        $media_replace_editurl = $this->getMediaReplaceURL($post->ID);
        $media_replace_action = "media_replace";
        $url = $this->getRemoveBgURL($post->ID);
        $background_remove_action = "emr_prepare_remove";
        $background_remove_editurl = wp_nonce_url($url, $background_remove_action);

 
        $media_replace_link = "href=\"$media_replace_editurl\"";
        $background_remove_link = "href=\"$background_remove_editurl\"";

        $newaction['media_replace'] = '<a ' . $media_replace_link . ' aria-label="' . esc_attr__("Replace media", "enable-media-replace") . '" rel="permalink">' . esc_html__("Replace media", "enable-media-replace") . '</a>';

        $uiHelper = $this->uiHelper(); 

        if ($uiHelper->isBackgroundRemovable($post) && false === $uiHelper->is_author_restricted()) {
            $newaction['remove_background'] = '<a ' . $background_remove_link . ' aria-label="' . esc_attr__("Remove  background", "enable-media-replace") . '" rel="permalink">' . esc_html__("Remove  background", "enable-media-replace") . '</a>';
        }

        if ($uiHelper->isUpscalable($post)) {
            $upscale_link = 'href="' . wp_nonce_url($this->getUpscaleURL($post->ID), 'emr_prepare_upscale') . '"';
            $newaction['upscale_image'] = '<a ' . $upscale_link . ' aria-label="' . esc_attr__("Upscale image", "enable-media-replace") . '" rel="permalink">' . esc_html__("Upscale image", "enable-media-replace") . '</a>';
        }
        return array_merge($actions, $newaction);
    }



    /** Outputs the replaced date of the media on the edit_attachment screen
     *
     * Prints a "Revised" line with post_modified whenever it differs from
     * post_date (so after any edit, not only a replacement), and a
     * "Replaced By" line with the display name of the user stored in the
     * `_emr_replace_author` post meta, when set. Falls back to the global
     * $post when the hook passes none. Declared without a visibility keyword
     * (public).
     *
     * @global \WP_Post $post Fallback when no post is passed.
     *
     * @param \WP_Post|mixed $post Obj Post Object
     * @return false|void False when no post object is available.
     *
     * @hook action attachment_submitbox_misc_actions (listened to, priority 91)
     *
     * @todo The global $post fallback covers WordPress before 4.9, where the
     *       hook passes no post; dead on the readme minimum of 4.9.7. Remove
     *       it with the other old-WordPress shims.
     * @todo The date format string __('M j, Y @ H:i') has no text domain, so
     *       it is translated with WordPress core's strings only.
     */
    function admin_date_replaced_media_on_edit_media_screen($post)
    {

        // Fallback for before version 4.9, doens't pass post.
        if (! is_object($post)) {
            global $post;
        }

        if (! is_object($post)) { // try to global, if it doesn't work - return.
            return false;
        }

        $post_id = $post->ID;
        if ($post->post_modified !== $post->post_date) {
            $modified = date_i18n(__('M j, Y @ H:i'), strtotime($post->post_modified));
?>
            <div class="misc-pub-section curtime">
                <span id="timestamp"><?php echo esc_html__('Revised', 'enable-media-replace'); ?>: <b><?php echo esc_html($modified); ?></b></span>
            </div>

        <?php
        }
        $author_id = get_post_meta($post_id, '_emr_replace_author', true);

        if ($author_id) {
            $display_name = get_the_author_meta('display_name', $author_id);
        ?>
            <div class="misc-pub-section replace_author">
                <span><?php echo esc_html__('Replaced By', 'enable-media-replace'); ?>: <b><?php echo esc_html($display_name); ?></b></span>
            </div>
<?php
        }
    }

    /** When an image is just replaced, it can stuck in the browser cache making a look like it was not replaced. Try
     * undo that effect by adding a timestamp to the query string
     *
     * Hooked only on requests with `emr_replaced=1`; adds `time=<now>` to
     * the URL of every attachment image source on that request.
     *
     * @param array|false  $image         array( url, width, height, is_intermediate ) or false.
     * @param int          $attachment_id Attachment ID (unused).
     * @param string|array $size          Requested size (unused).
     * @param bool         $icon          Whether an icon was requested (unused).
     * @return array|false $image with the cache-busting argument on index 0.
     *
     * @hook filter wp_get_attachment_image_src (listened to)
     */
    public function attempt_uncache_image($image, $attachment_id, $size, $icon)
    {
        if ($image === false) {
            return $image;
        }

        // array with image src on 0
        $image[0] = add_query_arg('time', time(), $image[0]);
        return $image;
    }

    /**
     * Shorttag function to show the media file modification date/time.
     *
     * Callback of the `[file_modified id="123" format="..."]` shortcode,
     * registered for every visitor. Returns the filemtime() of the
     * attachment's file formatted with wp_date(); the format defaults to the
     * `date_format` and `time_format` options joined by a space. Returns
     * false (renders nothing) without an `id`, or when the file is missing.
     * There is no permission check; any attachment ID can be queried.
     *
     * @param array|string $args shorttag attributes (`id`, `format`); '' when none are given.
     * @return string|false content / replacement shorttag
     * @todo Note this returns the wrong date, ie. server date not corrected for timezone. Function could be removed altogether, not sure about purpose.
     *       The function formats with wp_date(), which converts the Unix
     *       timestamp to the site timezone, so the remark above may no longer
     *       hold; confirm with a test before acting on it.
     * @todo wp_date() exists since WordPress 5.3 and the call is unguarded,
     *       while readme.txt declares 4.9.7; the shortcode fatals below 5.3.
     *       Raise the readme minimum or fall back to date_i18n().
     */
    public function get_modified_date($args)
    {

        $id = isset($args['id']) ? intval($args['id']) : false;
        $format = isset($args['format']) ? sanitize_text_field($args['format']) : false;

        if (false === $id) {
            return false;
        }

        // Get path to file
        $current_file = get_attached_file($id);

        if (! file_exists($current_file)) {
            return false;
        }

        // Get file modification time
        $filetime = filemtime($current_file);

        if (false === $format) {
            $format = get_option('date_format') . ' ' . get_option('time_format');
        }

        if (false !== $filetime) {
            // do date conversion
            return wp_date($format, $filetime);
        }

        return false;
    }
} // class