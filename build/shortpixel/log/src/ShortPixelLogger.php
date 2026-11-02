<?php
/**
 * Log module entry point.
 *
 * Holds the ShortPixelLogger class, the file logger that every ShortPixel
 * plugin uses through the `Log` alias.
 *
 * @package EnableMediaReplace\ShortPixelLogger
 */

namespace EnableMediaReplace\ShortPixelLogger;

/**
 * Leveled file logger with an optional admin debug box.
 *
 * Consumers alias the class (`use ...\ShortPixelLogger as Log`) and call the
 * static entry points: addError(), addWarn()/addWarning(), addInfo(),
 * addDebug(), addTrace(), addMemory() and addTemp(). Each call wraps the
 * message and optional data in a DebugItem, keeps it in $items and appends
 * one formatted line to the log file. Nothing is written when the logger is
 * inactive or when the item's level is above the configured level.
 *
 * Lifecycle: singleton through getInstance(); the constructor is public but
 * is only meant to run once. Activation is decided in the constructor, in
 * this order:
 * - A `SHORTPIXEL_DEBUG` request parameter, when the current user can
 *   `manage_options`, enables manual debugging; `true` means
 *   DebugItem::LEVEL_INFO, any other value is cast to a level number. On
 *   multisite the user check is deferred to the `wp_loaded` action
 *   (msCheckUserLevel()), because the user is not known yet when the
 *   plugins instantiate the logger.
 * - Otherwise the `SHORTPIXEL_DEBUG` constant, when defined and greater than
 *   zero, enables logging; `true` means LEVEL_INFO, a number is the level.
 * - Otherwise the logger stays inactive and the add*() calls return early.
 * The level defaults to DebugItem::LEVEL_WARN and can be changed later with
 * logLevel().
 *
 * Log file: the consumer sets it with setLogPath() right after getInstance()
 * when debugIsActive() is true (the plugins use their uploads or backup
 * directory). Without that, the first write defaults to
 * `<uploads basedir>/<first namespace segment>.log`. The file is opened once
 * in append mode and the handle is kept for the request; when the directory
 * or file is not writable the problem is reported through error_log() and
 * writing is skipped silently afterwards.
 *
 * Manual mode: when enabled through the request parameter, initView() (on
 * `init`) prepares a link to the log file and loadView() (on
 * `admin_footer`) includes the view-debug-box.php template, which renders a
 * draggable box with that link in the admin.
 *
 * Constants read: `SHORTPIXEL_DEBUG` (activation and level),
 * `SHORTPIXEL_DEBUG_TARGET` and `SHORTPIXEL_LOG_OVERWRITE` (together they
 * request a reset of the log file at construction, see the @todo). The
 * request parameter and constant names are the same in every plugin; the
 * build does not rewrite them.
 *
 * Fires no hooks. Registers callbacks on the WordPress core actions
 * `wp_loaded` (msCheckUserLevel(), multisite manual mode only), `init`
 * (initView()) and `admin_footer` (loadView()). monitorHooks() can wrap
 * arbitrary filters for logging, but $hooks is empty so it never runs.
 *
 * Bundling: this source is written with the ShortPixel namespace and the
 * ShortPixel text domain. The build tool that bundles the module into a
 * plugin rewrites the namespace and the text domain mechanically, so each
 * consuming plugin (for example Enable Media Replace) sees its own
 * namespace. The default log file name derives from the first segment of
 * that namespace. The module uses no `shortpixel/*` hooks.
 *
 * @todo In addLog() the default path is assigned as
 *       `$this->logPath = $this->setLogPath(...)`; setLogPath() returns
 *       nothing, so $logPath is reset to null right after the file handle is
 *       opened. Writing keeps working through the cached handle, but
 *       getLogPath() returns null and initView() cannot build the link.
 *       Suggested fix: call setLogPath() without assigning its result.
 * @todo The `SHORTPIXEL_LOG_OVERWRITE` reset in the constructor writes to
 *       $logPath while it is still false (no path is set at construction),
 *       so the log is never reset and PHP warns about an empty filename.
 *       Suggested fix: perform the reset in setLogPath() or on the first
 *       getWriteFile() call.
 */
class ShortPixelLogger
{
  /**
   * Shared instance returned by getInstance().
   *
   * @var ShortPixelLogger|null
   */
  static protected $instance = null;

  /**
   * microtime() at construction; the `time_passed` column of every line is
   * measured from it.
   *
   * @var float
   */
  protected $start_time;

  /**
   * PHP `memory_limit` in bytes, resolved on the first addMemoryLog() call.
   *
   * @var int|null
   */
  protected $memoryLimit; // to be used for memory logs only.

  /**
   * Whether anything is logged at all, decided in the constructor.
   *
   * @var bool
   */
  protected $is_active = false;

  /**
   * True when logging was enabled through the `SHORTPIXEL_DEBUG` request
   * parameter rather than the constant. Enables the admin debug box and
   * the per-call administrator check in addLog().
   *
   * @var bool
   */
  protected $is_manual_request = false;

  /**
   * Unused; the debug box is shown whenever $is_manual_request is true.
   *
   * @var bool
   */
  protected $show_debug_view = false;

  /**
   * Every DebugItem logged in this request, in order. Only appended to.
   *
   * @var DebugItem[]
   */
  protected $items = array();

  /**
   * Absolute path of the log file, or false until setLogPath() runs or the
   * first write picks the default.
   *
   * @var string|false
   */
  protected $logPath = false;

  /**
   * Unused; the file is always opened in append mode by getWriteFile().
   *
   * @var int
   */
  protected $logMode = FILE_APPEND;

  /**
   * Highest DebugItem level that is written; items with a higher number
   * (less severe) are dropped.
   *
   * @var int
   */
  protected $logLevel;

  /**
   * Line template for formatLine(). `%%key%%` placeholders are replaced by
   * the scalar entries of DebugItem::getForFormat() plus `time_passed`.
   *
   * @var string
   */
  protected $format = "[ %%time%% ] %%color%% %%level%% %%color_end%% \t %%message%%  \t %%caller%% ( %%time_passed%% )";

  /**
   * Unused; data lines are appended verbatim by formatLine().
   *
   * @var string
   */
  protected $format_data = "\t %%data%% ";

  /**
   * Filters to wrap for logging, as `hook => ['numargs' => int,
   * 'priority' => int]`, consumed by monitorHooks(). Always empty.
   *
   * @var array
   */
  protected $hooks = array();

  /**
   * Open stream of the log file, false when it could not be opened, null
   * before the first getWriteFile() call.
   *
   * @var resource|false|null
   */
  private $logFile; // pointer resource to the logFile.

  /**
   * First segment of this class's namespace (`ShortPixel`, or the plugin
   * namespace after bundling). Names the default log file and the debug
   * box.
   *
   * @var string
   */
  private $namespace;

  /**
   * View data for the debug box template; has `logLink` and, from
   * loadView() on, `namespace`.
   *
   * @var \stdClass|null
   */
  private $view;

  /**
   * Template file name (without `.php`) next to this file, included by
   * loadView().
   *
   * @var string
   */
  protected $template = 'view-debug-box';

  /**
   * Decides whether logging is active and at which level.
   *
   * See the class description for the order of the `SHORTPIXEL_DEBUG`
   * request parameter and constant. The request parameter is only honoured
   * for administrators; on multisite that check waits for `wp_loaded`. When
   * active, initView() is scheduled on `init` so the log link is built after
   * the consumer has set the path.
   *
   * @return void
   */
  public function __construct()
  {
    $this->start_time = microtime(true);
    $this->logLevel = DebugItem::LEVEL_WARN;

    $ns = __NAMESPACE__;
    $this->namespace = substr($ns, 0, strpos($ns, '\\')); // try to get first part of namespace

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
    if (is_multisite() && isset($_REQUEST['SHORTPIXEL_DEBUG']))
    {
       add_action('wp_loaded', [$this, 'msCheckUserLevel']); 
       return;
    }
    elseif (isset($_REQUEST['SHORTPIXEL_DEBUG']) && true === $this->checkUserLevel()) // manual takes precedence over constants
    {
        $this->setManualLog(); 
    } else if ((defined('SHORTPIXEL_DEBUG') && SHORTPIXEL_DEBUG > 0)) {
      $this->is_active = true;
      if (SHORTPIXEL_DEBUG === true)
        $this->logLevel = DebugItem::LEVEL_INFO;
      else {
        $this->logLevel = intval(SHORTPIXEL_DEBUG);
      }
    }
    else
    {
      return;
    }

    if (defined('SHORTPIXEL_DEBUG_TARGET') && SHORTPIXEL_DEBUG_TARGET || $this->is_manual_request) {
      if (defined('SHORTPIXEL_LOG_OVERWRITE')) // if overwrite, do this on init once.
        file_put_contents($this->logPath, '-- Log Reset -- ' . PHP_EOL);
    }

    if ($this->is_active) {
      /* Update - always defer to init to build loglink, otherwise logpath is not properly set. */
        add_action('init', array($this, 'initView'));

    }

    if ($this->is_active && count($this->hooks) > 0)
    {
      $this->monitorHooks();
    }
  }

  /**
   * Deferred manual-mode check for multisite, bound to `wp_loaded`.
   *
   * Public because it is a hook callback; not meant to be called directly.
   * Enables manual logging when the request carries `SHORTPIXEL_DEBUG` and
   * the current user is an administrator.
   *
   * @return void
   */
  public function msCheckUserLevel()
  {
      if (isset($_REQUEST['SHORTPIXEL_DEBUG']) && true === $this->checkUserLevel())
      {
         $this->setManualLog();
      }

  }

  /**
   * Activates logging from the `SHORTPIXEL_DEBUG` request parameter.
   *
   * The literal `true` selects DebugItem::LEVEL_INFO; any other value is
   * cast to an integer level.
   *
   * @return void
   */
  protected function setManualLog()
  {
      $this->is_manual_request = true;
      $this->is_active = true;

      // phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
      if ($_REQUEST['SHORTPIXEL_DEBUG'] === 'true') {
        $this->logLevel = DebugItem::LEVEL_INFO;
      } else {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
        $this->logLevel = intval($_REQUEST['SHORTPIXEL_DEBUG']);
      }
  }

  /**
   * Tells whether the current user may use manual debugging.
   *
   * Loads `wp-includes/pluggable.php` when the user functions are not
   * available yet, so this works before `plugins_loaded`.
   *
   * @return bool True when the current user can `manage_options`.
   */
  protected function checkUserLevel()
  {
    if(false === function_exists('wp_get_current_user')) { 
      include(ABSPATH . "wp-includes/pluggable.php"); 
    }

    $user_is_administrator = (current_user_can('manage_options')) ? true : false;
    return $user_is_administrator;
  }

  /**
   * Prepares the debug box, bound to `init`.
   *
   * Public because it is a hook callback; not meant to be called directly.
   * Only acts in manual mode for administrators. Turns the log path into a
   * URL when the file lives inside the uploads directory (otherwise the
   * path itself is used), stores it as a `view-source:` link in $view and
   * schedules loadView() on `admin_footer`.
   *
   * @return void
   */
  public function initView()
  {
    $user_is_administrator = $this->checkUserLevel();

    if ($this->is_active && $this->is_manual_request && $user_is_administrator) {

      $logPath = $logLink = $this->logPath; // default
      $uploads = wp_get_upload_dir();


      if (0 === strpos($logPath, $uploads['basedir'])) { // Simple as it should, filepath and basedir share.
        // Replace file location with url location.
        $logLink = str_replace($uploads['basedir'], $uploads['baseurl'], $logPath);
      }


      $this->view = new \stdClass;
      $this->view->logLink = 'view-source:' . esc_url($logLink);
      add_action('admin_footer', array($this, 'loadView'));
    }
  }

  /**
   * Returns the shared logger, creating it on first use.
   *
   * @return ShortPixelLogger
   */
  public static function getInstance()
  {
    if (self::$instance === null) {
      self::$instance = new ShortPixelLogger();
    }
    return self::$instance;
  }

  /**
   * Sets the log file and opens it immediately.
   *
   * Consumers call this right after getInstance() when debugIsActive() is
   * true. Any previously opened handle is replaced. Problems opening the
   * file are reported through error_log() by getWriteFile().
   *
   * @param string $logPath Absolute path of the log file; its directory
   *                        must exist and be writable.
   * @return void
   */
  public function setLogPath($logPath)
  {
    $this->logPath = $logPath;
    $this->getWriteFile(true); // reset the writeFile here.
  }
  /**
   * Records one entry and writes it to the log file.
   *
   * Drops the entry when the logger is inactive or $level is above
   * $logLevel. In manual mode the entry is also dropped when the user
   * functions are not loaded yet or the current user is not an
   * administrator, so a `SHORTPIXEL_DEBUG` parameter from a visitor cannot
   * produce output. Picks the default log path on the first write when
   * none is set (see the class @todo).
   *
   * @param mixed $message Log message; arrays and objects are moved into the
   *                       data by DebugItem.
   * @param int   $level   One of the DebugItem::LEVEL_* constants.
   * @param array $data    Extra variables to dump under the line.
   * @return void|false False when dropped by the manual-mode user check;
   *                    nothing otherwise.
   */
  protected function addLog($message, $level, $data = array())
  {
    //   $log = self::getInstance();

    // don't log anything too low or when not active.
    if ($this->logLevel < $level || ! $this->is_active) {
      return;
    }

    // Force administrator on manuals.
    if ($this->is_manual_request) {
      if (! function_exists('wp_get_current_user')) // not loaded yet
        return false;

      $user_is_administrator = $this->checkUserLevel();
      if (! $user_is_administrator)
        return false;
    }

    // Check where to log to.
    if ($this->logPath === false) {
      $upload_dir = wp_upload_dir(null, false, false);
      $this->logPath = $this->setLogPath($upload_dir['basedir'] . '/' . $this->namespace . ".log");
    }

    $arg = array();
    $args['level'] = $level;
    $args['data'] = $data;

    $newItem = new DebugItem($message, $args);
    $this->items[] = $newItem;

    if ($this->is_active) {
      $this->write($newItem);
    }
  }

  /**
   * Formats one item and appends it to the log file.
   *
   * Adds the seconds elapsed since construction, renders the timestamp and
   * flattens the caller array to `file in function(line)`. Nothing is
   * written when the file could not be opened.
   *
   * @param DebugItem $debugItem Entry to write.
   * @param string    $mode      Unused.
   * @return void
   */
  protected function write($debugItem, $mode = 'file')
  {
    $items = $debugItem->getForFormat();
    $items['time_passed'] =  round(($items['time'] - $this->start_time), 5);
    $items['time'] =  date('Y-m-d H:i:s', (int) $items['time']);

    if (($items['caller']) && is_array($items['caller']) && count($items['caller']) > 0) {
      $caller = $items['caller'];
      $items['caller'] = $caller['file'] . ' in ' . $caller['function'] . '(' . $caller['line'] . ')';
    }

    $line = $this->formatLine($items);

    $file = $this->getWriteFile();

    // try to write to file. Don't write if directory doesn't exists (leads to notices)
    if ($file) {
      fwrite($file, $line);
      //        file_put_contents($this->logPath,$line, FILE_APPEND);
    } else {
      // error_log($line);
    }
  }

  /**
   * Returns the open log file handle, opening it on first use.
   *
   * The handle is cached in $logFile; a failed attempt is cached as false so
   * the checks and error_log() calls happen once per request. The
   * directory must exist and be writable, and an existing file must be
   * writable; the file is created when missing.
   *
   * @param bool $reset True to close the cached handle and reopen $logPath.
   * @return resource|false Stream in append mode, or false when the
   *                        directory or file is not usable.
   *
   * @todo The reset branch tests `is_object($this->logFile)`, which is
   *       never true for a stream resource, so the previous handle is not
   *       closed when setLogPath() is called a second time. Suggested fix:
   *       test with is_resource().
   */
  protected function getWriteFile($reset = false)
  {
    if (! is_null($this->logFile) && $reset === false) {
      return $this->logFile;
    } elseif (is_object($this->logFile)) {
      fclose($this->logFile);
    }

    $logDir = dirname($this->logPath);
    if (! is_dir($logDir) || ! is_writable($logDir)) {
      error_log('ShortpixelLogger: Log Directory is not writable : ' . $logDir);
      $this->logFile = false;
      return false;
    }

    $file = false;
    if (file_exists($this->logPath)) {
      if (! is_writable($this->logPath)) {
        error_log('ShortPixelLogger: File Exists, but not writable: ' . $this->logPath);
        $this->logFile = false;
        return $file;
      }
    }

    $file = fopen($this->logPath, 'a');

    if ($file === false) {
      error_log('ShortpixelLogger: File could not be opened / created: ' . $this->logPath);
      $this->logFile = false;
      return $file;
    }

    $this->logFile = $file;
    return $file;
  }

  /**
   * Renders one log line from $format plus the dumped data.
   *
   * Scalar entries replace their `%%key%%` placeholder; placeholders without
   * a value are left in place. Each non-empty data entry follows on its own
   * line, booleans spelled out as `true`/`false`.
   *
   * @param array $args Output of DebugItem::getForFormat() with
   *                    `time_passed` added.
   * @return string Line(s) ending with PHP_EOL.
   */
  protected function formatLine($args = array())
  {
    $line = $this->format;
    foreach ($args as $key => $value) {
      if (! is_array($value) && ! is_object($value))
        $line = str_replace('%%' . $key . '%%', $value, $line);
    }

    $line .= PHP_EOL;

    if (isset($args['data'])) {
      $data = array_filter($args['data']);
      if (count($data) > 0) {
        // @todo This should probably be a formatter function to handle multiple stuff?
        foreach ($data as $item) {
          if (is_bool($item)) {
            $item = (true === $item) ? 'true' : 'false';
          }
          $line .= $item . PHP_EOL;
        }
      }
    }

    return $line;
  }

  /**
   * Changes the level threshold for the rest of the request.
   *
   * @param int $level One of the DebugItem::LEVEL_* constants.
   * @return void
   */
  protected function setLogLevel($level)
  {
    $this->logLevel = $level;
  }

  /**
   * Reads a property for the static getters.
   *
   * @param string $name Property name.
   * @return mixed Property value, or false when it is unset or null.
   */
  protected function getEnv($name)
  {
    if (isset($this->{$name})) {
      return $this->{$name};
    } else {
      return false;
    }
  }


  /**
   * Wraps every filter in $hooks with a logging callback.
   *
   * Each callback logs the hook name, the filtered value and the extra
   * arguments through logHook() and returns the value unchanged. Called
   * from the constructor only when $hooks is non-empty, which it never is.
   *
   * @return void
   */
  protected function monitorHooks()
  {

    foreach ($this->hooks as $hook => $data) {
      $numargs = isset($data['numargs']) ? $data['numargs'] : 1;
      $prio = isset($data['priority']) ? $data['priority'] : 10;

      add_filter($hook, function ($value) use ($hook) {
        $args = func_get_args();
        return $this->logHook($hook, $value, $args);
      }, $prio, $numargs);
    }
  }

  /**
   * Filter callback used by monitorHooks(); logs at LEVEL_INFO.
   *
   * @param string $hook  Filter name.
   * @param mixed  $value Value being filtered, returned unchanged.
   * @param array  $args  All filter arguments; the first (the value) is
   *                      dropped before logging.
   * @return mixed $value.
   */
  public function logHook($hook, $value, $args)
  {
    array_shift($args);
    self::addInfo('[Hook] - ' . $hook . ' with ' . var_export($value, true), $args);
    return $value;
  }

  /**
   * Includes the debug box template, bound to `admin_footer`.
   *
   * The template sees `$view` (with `logLink` and `namespace`) and
   * `$controller` (this logger). A missing template is logged as an error
   * instead of rendered.
   *
   * @return void
   */
  public function loadView()
  {
    // load either param or class template.
    $template = $this->template;

    $view = $this->view;
    $view->namespace = $this->namespace;
    $controller = $this;

    $template_path = __DIR__ . '/' . $this->template  . '.php';
    if (file_exists($template_path)) {

      include($template_path);
    } else {
      self::addError(
        "View $template for ShortPixelLogger could not be found in " . $template_path,
        array('class' => get_class($this))
      );
    }
  }

  /**
   * Logs a message suffixed with current memory usage, limit and
   * percentage, at LEVEL_DEBUG.
   *
   * @param string $message Log message.
   * @param array  $args    Extra variables to dump under the line.
   * @return void
   *
   * @todo A `memory_limit` of `-1` (unlimited) is converted to -1, which
   *       yields a negative percentage. Suggested fix: treat a negative
   *       limit as unlimited and skip the percentage.
   */
  public function addMemoryLog($message, $args = array())
  {
    if (is_null($this->memoryLimit)) {
      $this->memoryLimit = $this->unitToInt(ini_get('memory_limit'));
    }

    $usage = memory_get_usage();
    $percentage = round(($usage / $this->memoryLimit) * 100, 2);
    $memmsg = sprintf(
      "( %s / %s - %s %%)",
      $this->formatBytes($usage),
      $this->formatBytes($this->memoryLimit),
      $percentage
    );
    $level = DebugItem::LEVEL_DEBUG;
    $this->addLog($message . ' ' . $memmsg, $level, $args);
  }

  /**
   * Converts a php.ini size such as `256M` to bytes.
   *
   * Recognises the `K`, `M` and `G` suffixes; a bare number is returned as
   * is.
   *
   * @param string $s Size with optional unit suffix.
   * @return int Bytes.
   */
  private function unitToInt($s)
  {
    return (int)preg_replace_callback('/(\-?\d+)(.?)/', function ($m) {
      return $m[1] * pow(1024, strpos('BKMG', $m[2]));
    }, strtoupper($s));
  }

  /**
   * Formats a byte count with a `K`/`M`/`G`/`T` suffix.
   *
   * @param int $size      Bytes.
   * @param int $precision Decimals to keep.
   * @return string|int Formatted size, or 0 for zero or a non-finite
   *                    result.
   */
  private function formatBytes($size, $precision = 2)
  {
    $base = log($size, 1024);
    $suffixes = array('', 'K', 'M', 'G', 'T');

    if (0 === $size) {
      return 0;
    }

    $calculation = pow(1024, $base - floor($base));
    if (is_nan($calculation)) {
      return 0;
    }

    return round($calculation, $precision) . ' ' . $suffixes[floor($base)];
  }

  /**
   * Logs at DebugItem::LEVEL_ERROR.
   *
   * @param mixed $message Log message; arrays and objects are dumped as data.
   * @param array $args    Extra variables to dump under the line.
   * @return void
   */
  public static function addError($message, $args = array())
  {
    $level = DebugItem::LEVEL_ERROR;
    $log = self::getInstance();
    $log->addLog($message, $level, $args);
  }
  /**
   * Logs at DebugItem::LEVEL_WARN.
   *
   * @param mixed $message Log message; arrays and objects are dumped as data.
   * @param array $args    Extra variables to dump under the line.
   * @return void
   */
  public static function addWarn($message, $args = array())
  {
    $level = DebugItem::LEVEL_WARN;
    $log = self::getInstance();
    $log->addLog($message, $level, $args);
  }
  /**
   * Alias of addWarn().
   *
   * The caller column of the resulting line names this method rather than
   * the real caller (see DebugItem::setCaller()).
   *
   * @param mixed $message Log message.
   * @param array $args    Extra variables to dump under the line.
   * @return void
   */
  public static function addWarning($message, $args = array())
  {
    self::addWarn($message, $args);
  }
  /**
   * Logs at DebugItem::LEVEL_INFO.
   *
   * @param mixed $message Log message; arrays and objects are dumped as data.
   * @param array $args    Extra variables to dump under the line.
   * @return void
   */
  public static function addInfo($message, $args = array())
  {
    $level = DebugItem::LEVEL_INFO;
    $log = self::getInstance();
    $log->addLog($message, $level, $args);
  }
  /**
   * Logs at DebugItem::LEVEL_DEBUG.
   *
   * @param mixed $message Log message; arrays and objects are dumped as data.
   * @param array $args    Extra variables to dump under the line.
   * @return void
   */
  public static function addDebug($message, $args = array())
  {
    $level = DebugItem::LEVEL_DEBUG;
    $log = self::getInstance();
    $log->addLog($message, $level, $args);
  }

  /**
   * Logs a message followed by a backtrace, at LEVEL_DEBUG.
   *
   * The trace frames are passed as data, so each frame is dumped on its own
   * line by DebugItem.
   *
   * @param string $message      Log message.
   * @param int    $amount       Maximum number of frames.
   * @param int    $debug_option Options for debug_backtrace(); defaults to
   *                             DEBUG_BACKTRACE_IGNORE_ARGS.
   * @return void
   */
  public static function addTrace($message, $amount = 10, $debug_option = 2)
  {
    $trace = debug_backtrace($debug_option, $amount);
    $log = self::getInstance();
    $log->addLog($message, DebugItem::LEVEL_DEBUG, $trace);
  }

  /**
   * Static entry point for addMemoryLog().
   *
   * @param string $message Log message.
   * @param array  $args    Extra variables to dump under the line.
   * @return void
   */
  public static function addMemory($message, $args = array())
  {
    $log = self::getInstance();
    $log->addMemoryLog($message, $args);
  }

  /**
   * Alias of addDebug() for temporary statements.
   *
   * Calls to it mark debugging code that is meant to be removed before a
   * release, so they are easy to grep for.
   *
   * @param mixed $message Log message.
   * @param array $args    Extra variables to dump under the line.
   * @return void
   */
  public static function addTemp($message, $args = array())
  {
    self::addDebug($message, $args);
  }

  /**
   * Changes the level threshold at runtime and logs the change at
   * LEVEL_INFO (under the previous threshold).
   *
   * @param int $level One of the DebugItem::LEVEL_* constants.
   * @return void
   */
  public static function logLevel($level)
  {
    $log = self::getInstance();
    static::addInfo('Changing Log level' . $level);
    $log->setLogLevel($level);
  }

  /**
   * Returns the current level threshold.
   *
   * @return int One of the DebugItem::LEVEL_* constants.
   */
  public static function getLogLevel()
  {
    $log = self::getInstance();
    return $log->getEnv('logLevel');
  }

  /**
   * Tells whether logging was enabled through the request parameter.
   *
   * Consumers use it to carry `SHORTPIXEL_DEBUG` over to the URLs they
   * generate and to show debugging aids in their settings pages.
   *
   * @return bool
   */
  public static function isManualDebug()
  {
    $log = self::getInstance();
    return $log->getEnv('is_manual_request');
  }

  /**
   * Returns the log file path.
   *
   * @return string|false Absolute path, or false when no path is set yet
   *                      (see the class @todo for the defaulted case).
   */
  public static function getLogPath()
  {
    $log = self::getInstance();
    return $log->getEnv('logPath');
  }

  /**
   * Tells whether the logger writes anything in this request.
   *
   * Consumers check it before calling setLogPath() and before building
   * debug-only output.
   *
   * @return bool
   */
  public static function debugIsActive()
  {
    $log = self::getInstance();
    return $log->getEnv('is_active');
  }
} // class debugController
