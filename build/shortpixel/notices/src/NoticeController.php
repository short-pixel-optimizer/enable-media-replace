<?php
/**
 * Notices module entry point.
 *
 * Holds the NoticeController class, which collects admin notices during a
 * request, stores them in a WordPress option and renders them in the admin
 * screens of the consuming plugin.
 *
 * @package EnableMediaReplace\Notices
 */
namespace EnableMediaReplace\Notices;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Collects, stores and renders admin notices.
 *
 * Consumers add notices through the static addNormal(), addError(),
 * addWarning() and addSuccess() methods, each of which returns the new
 * NoticeModel. A notice is one-shot by default: it is shown on the next
 * admin page that renders notices and is dropped afterwards. makePersistent()
 * turns a notice into a keyed, persistent one that is shown on every admin
 * page until the user dismisses it (through the AJAX handler of this class)
 * or the consumer removes it by key with removeNoticeByID().
 *
 * Lifecycle: getInstance() returns the single instance. The constructor
 * derives the option name from the first segment of the namespace
 * (`ShortPixel-notices` in this source), registers the AJAX dismiss handler
 * and loads the stored notices from that option into the static $notices
 * array. The option holds the serialized NoticeModel objects. update() writes
 * the array back after every change (adding a notice, adding a detail, making
 * a notice persistent, dismissing or removing one) and at the end of
 * admin_notices(); it drops every notice for which NoticeModel::isDone()
 * is true, that is, viewed one-shot notices and dismissed persistent notices
 * whose suppress period has passed. When no notices remain, the option is
 * deleted.
 *
 * The class does not hook `admin_notices` itself. The consuming plugin either
 * adds admin_notices() of this class to that hook, or calls
 * getNoticesForDisplay(), echoes NoticeModel::getForDisplay() for each notice
 * (optionally filtering with NoticeModel::checkScreen()) and calls update()
 * afterwards so the viewed state is stored. Notices added during an AJAX
 * request can be collected with getNewNotices() and returned in the response
 * instead of being rendered.
 *
 * Bundling: this source is written with the ShortPixel namespace and the
 * ShortPixel text domain. The build tool that bundles the module into a
 * plugin rewrites both mechanically, so each consuming plugin sees its own
 * namespace and, because the option name and the AJAX action are derived
 * from the namespace, its own option and AJAX action. Names in these
 * docblocks are given in the source's terms.
 *
 * Fires no hooks.
 *
 * Hooks listened to:
 * - action `wp_ajax_{notice_option}` (ajax_action()), registered in the
 *   constructor; `{notice_option}` is `ShortPixel-notices` in this source.
 * - action `admin_print_footer_scripts` (printNoticeStyle()), registered by
 *   the first admin_notices() call that has notices to show.
 *
 * @todo resetNotices() deletes the option but leaves the static $notices
 *       array untouched, so when the controller was already instantiated in
 *       the same request the next update() call writes the old notices back.
 *       Suggested fix: also reset self::$notices and, when an instance
 *       exists, its $has_stored flag and count.
 * @todo removeNoticeByID() and makePersistent() iterate $notices with
 *       `for ($i = 0; $i < count(self::$notices); $i++)` and unset() entries
 *       while iterating. Every unset() shrinks count() by one, so the last
 *       entries of the array are never examined and a matching notice at the
 *       end can survive. Suggested fix: iterate with foreach over the keys
 *       and unset by key, or filter into a new array.
 */
class NoticeController //extends ShortPixelController
{
  /**
   * All known notices, stored and one-shot alike, shared by every instance.
   * Loaded from the option in loadNotices() and written back by update().
   *
   * @var NoticeModel[]
   */
  protected static $notices = array();
  /**
   * The single instance returned by getInstance().
   *
   * @var NoticeController|null
   */
  protected static $instance = null;
  /**
   * Whether printNoticeStyle() has been hooked to
   * `admin_print_footer_scripts`, so the stylesheet is printed only once.
   *
   * @var bool
   */
  protected static $cssHookLoaded = false;
	/**
	 * Notices added during this request, in order of addition. Used by
	 * consumers to return new notices in an AJAX response. Never cleared.
	 *
	 * @var NoticeModel[]
	 */
	protected static $newNotices = [];

  /**
   * IDs of the persistent notices already returned by
   * getNoticesForDisplay() in this request, so a persistent notice is
   * rendered once even when the display method runs more than once.
   *
   * @var string[]
   */
  protected $notice_displayed = array();

  /**
   * Number of notices in $notices as of the last countNotices() call.
   *
   * @var int
   */
  public $notice_count = 0;

  /**
   * Whether the option existed and held an array when loadNotices() ran.
   * update() deletes the option only when this is true.
   *
   * @var bool
   */
  protected $has_stored = false;

  /**
   * Name of the option the notices are stored in, also used as the AJAX
   * action name: the first segment of the namespace followed by `-notices`.
   *
   * @var string
   */
  protected $notice_option = '';

  /**
   * Derives the option name, registers the AJAX dismiss handler and loads
   * the stored notices.
   *
   * Public for backward compatibility only; use getInstance() so the AJAX
   * action is registered once.
   */
  public function __construct()
  {
      $ns = __NAMESPACE__;
      $ns = substr($ns, 0, strpos($ns, '\\')); // try to get first part of namespace
      $this->notice_option = $ns . '-notices';

      add_action('wp_ajax_' . $this->notice_option, array($this, 'ajax_action'));

      $this->loadNotices();
      //$this->loadConfig();
  }

  /**
   * Returns the shared instance, creating it on first use.
   *
   * @return NoticeController
   */
  public static function getInstance()
  {
     if ( self::$instance === null)
     {
         self::$instance = new NoticeController();
     }

     return self::$instance;
  }

  /**
   * Deletes the notices option so the next load starts without notices.
   *
   * Meant for plugin activation and upgrades, before the controller is
   * instantiated. Works on the option name directly and does not need an
   * instance.
   *
   * @return void
   */
  public static function resetNotices()
  {
    $ns = __NAMESPACE__;
    $ns = substr($ns, 0, strpos($ns, '\\')); // try to get first part of namespace
    $result = delete_option($ns . '-notices');
  }

  /**
   * Placeholder for loading a notices configuration file.
   *
   * Reserved for future use: the method returns before the code that
   * follows the return statement, so nothing is loaded.
   *
   * @return void
   */
  public function loadConfig()
  {
    return;
    if (file_exists('../notice_config.json'))
    {
      $config = file_get_contents('../notice_config.json');
      $json_config = json_decode($config);
    }
  }

  /**
   * Registers the icon HTML per notice type for rendering.
   *
   * Icons are static state of NoticeModel and are not stored, so the
   * consumer sets them on every request before rendering.
   *
   * @param array $icons Icon HTML keyed by type name: `normal`, `error`,
   *                     `warning` or `success`.
   * @return void
   */
  public function loadIcons($icons)
  {
      foreach($icons as $name => $icon)
        NoticeModel::setIcon($name, $icon);
  }


  /**
   * Loads the stored notices from the option into the static $notices array.
   *
   * Keeps only entries that unserialized into NoticeModel objects, so
   * entries that became `__PHP_Incomplete_Class` after a namespace change
   * are discarded. Sets $has_stored and updates the count.
   *
   * @return void
   */
  protected function loadNotices()
  {
    $notices = get_option($this->notice_option, false);
    $cnotice = (is_array($notices)) ? count($notices) : 0;

    if ($notices !== false && is_array($notices))
    {
      $checked = array();
      foreach($notices as $noticeObj)
      {
        if (is_object($noticeObj) && $noticeObj instanceOf NoticeModel)
        {
          $checked[] = $noticeObj;
        }
      }
      self::$notices = $checked;
      $this->has_stored = true;
    }
    else {
      self::$notices = array();
      $this->has_stored = false;
    }
    $this->countNotices();
  }


  /**
   * Creates a notice, adds it to the array and stores the array.
   *
   * @param string $message Notice text, HTML allowed.
   * @param int    $code    One of the NoticeModel::NOTICE_* constants.
   * @param bool   $unique  When true and a notice with the same message and
   *                        type already exists, no notice is added.
   * @return NoticeModel The new notice, or the existing one with the same
   *                     message when $unique is true.
   *
   * @todo The uniqueness check compares $code of both notices, but
   *       NoticeModel never assigns that property, so it is always null and
   *       only the message decides: a notice with the same text but another
   *       type is treated as a duplicate. Suggested fix: compare
   *       $messageType instead.
   */
  protected function addNotice($message, $code, $unique)
  {
      $notice = new NoticeModel($message, $code);

      if ($unique)
      {
        foreach(self::$notices as $nitem)
        {
          if ($nitem->message == $notice->message && $nitem->code == $notice->code) // same message.
            return $nitem; // return the notice with the same message.
        }
      }
      self::$notices[] = $notice;
			self::$newNotices[] = $notice;
      $this->countNotices();

      $this->update();
      return $notice;
  }

  /**
   * Drops finished notices and writes the remaining ones to the option.
   *
   * A notice is finished when NoticeModel::isDone() returns true. When no
   * notices are left, the option is deleted instead (only if it was stored
   * before) and nothing is written.
   *
   * @return int Number of notices remaining after the update.
   */
  public function update()
  {
    if (! is_array(self::$notices) || count(self::$notices) == 0)
    {
      if ($this->has_stored)
        delete_option($this->notice_option);

      return 0;
    }

    $new_notices = array();
    foreach(self::$notices as $item)
    {
      if (! $item->isDone() )
      {
        $new_notices[] = $item;
      }
    }

    update_option($this->notice_option, $new_notices);
    self::$notices = $new_notices;

    return $this->countNotices();
  }

  /**
   * Recounts the notices and stores the result in $notice_count.
   *
   * @return int Number of notices currently held.
   */
  public function countNotices()
  {
      $this->notice_count = count(self::$notices);
      return $this->notice_count;
  }


  /**
   * Returns all notices, including dismissed and already viewed ones.
   *
   * @return NoticeModel[]
   */
  public function getNotices()
  {
      return self::$notices;
  }

	/**
	 * Returns the notices added during this request.
	 *
	 * @return NoticeModel[]
	 */
	public function getNewNotices()
	{
		 return self::$newNotices;
  }

  /**
   * Returns the notices that should be rendered now.
   *
   * Dismissed notices are skipped. A persistent notice is returned once per
   * request (tracked in $notice_displayed) and gets $notice_action set to
   * the option name so its dismiss script can address the AJAX handler of
   * this controller. One-shot notices are returned every time.
   *
   * Does not check screens; the caller applies NoticeModel::checkScreen()
   * when needed.
   *
   * @return NoticeModel[]
   */
  public function getNoticesForDisplay()
  {
      $newNotices = array();

      foreach(self::$notices as $notice)
      {
          if ($notice->isDismissed()) // dismissed never displays.
            continue;

          if ($notice->isPersistent())
          {
              $id = $notice->getID();
              if (! is_null($id) && ! in_array($id, $this->notice_displayed))
              {
                $notice->notice_action = $this->notice_option;
                $newNotices[] = $notice;
                $this->notice_displayed[] = $id;
              }

          }
          else
            $newNotices[] = $notice;


      }
      return $newNotices;
  }



  /**
   * Finds a persistent notice by its key.
   *
   * @param string $id Key given to makePersistent().
   * @return NoticeModel|false The notice, or false when no notice has this
   *                           key.
   */
  public function getNoticeByID($id)
  {
    foreach(self::$notices as $notice)
    {
      if ($notice->getID() == $id)
        return $notice;
    }

    return false;
  }

  /**
   * Removes the persistent notice with the given key and stores the array.
   *
   * Used by consumers to withdraw a notice whose condition no longer
   * applies, and by ajax_action() for the `remove` dismiss type. Logs each
   * removal. Does nothing besides storing when the key is unknown.
   *
   * @param string $id Key given to makePersistent().
   * @return void
   */
  public static function removeNoticeByID($id)
  {
    $noticeController = self::getInstance();

    for($i = 0; $i < count(self::$notices); $i++)
    {
      $item = self::$notices[$i];
      if (is_object($item) && $item->getID() == $id)
      {
        Log::addDebug('Removing notice with ID ' . $id);
        unset(self::$notices[$i]);
      }
      //if ($notice_item )
    }
    $noticeController->update();
  }

  /**
   * AJAX handler for dismissing a persistent notice.
   *
   * Reads `nonce` (action `dismiss`), `plugin_action` (must be `dismiss`),
   * `id` (the notice key) and `dismisstype` from the POST request. With
   * dismiss type `remove` the notice is deleted; otherwise it is marked
   * dismissed, which hides it and, once its suppress period has passed,
   * lets update() drop it. Stores the array and ends the request with a JSON
   * response: `result` (bool), `reason` (string, set on failure) and
   * `dismissed` or `removed` (true) on success. Logs an error for a wrong
   * nonce or an unknown key.
   *
   * @return void
   */
  public function ajax_action()
  {
    $response = array('result' => false, 'reason' => '');

    if (isset($_POST['nonce']) && wp_verify_nonce( sanitize_key($_POST['nonce']), 'dismiss') )
    {
       if (isset($_POST['plugin_action']) && 'dismiss' == $_POST['plugin_action'] )
       {
          $id = (isset($_POST['id'])) ? sanitize_text_field( wp_unslash($_POST['id'])) : null;
          $type = (isset($_POST['dismisstype'])) ? sanitize_text_field($_POST['dismisstype']) : 'dismiss';

					if (! is_null($id))
					{
          	$notice = $this->getNoticeByID($id);
					}
					else
					{
						$notice = false;
					}

          if(false !== $notice)
          {
            if ($type == 'remove')
            {
                  self::removeNoticeByID($id);
                  $response['removed'] = true;
            }
            else {
              $notice->dismiss();
              $this->update();
              $response['dismissed'] = true;
            }
            $response['result'] = true;

          }
          else
          {
            Log::addError('Notice not found when dismissing -> ' . $id, self::$notices);
						$response['result']  = false;
            $response['reason'] = ' Notice ' . $id . ' not found. ';
          }

       }

    }
    else
    {
      Log::addError('Wrong Nonce when dismissed notice. ');
      $response['reason'] = 'wrong nonce';
    }
    wp_send_json($response);
  }

  /**
   * Adds an informational notice.
   *
   * @param string $message Notice text, HTML allowed.
   * @param bool   $unique  When true, do not add the notice if one with the
   *                        same message and type already exists.
   * @return NoticeModel The added or the matching existing notice.
   */
  public static function addNormal($message, $unique = false)
  {
    $noticeController = self::getInstance();
    $notice = $noticeController->addNotice($message, NoticeModel::NOTICE_NORMAL, $unique);
    return $notice;

  }

  /**
   * Adds an error notice.
   *
   * @param string $message Notice text, HTML allowed.
   * @param bool   $unique  When true, do not add the notice if one with the
   *                        same message and type already exists.
   * @return NoticeModel The added or the matching existing notice.
   */
  public static function addError($message, $unique = false)
  {
    $noticeController = self::getInstance();
    $notice = $noticeController->addNotice($message, NoticeModel::NOTICE_ERROR, $unique);
    return $notice;

  }

  /**
   * Adds a warning notice.
   *
   * @param string $message Notice text, HTML allowed.
   * @param bool   $unique  When true, do not add the notice if one with the
   *                        same message and type already exists.
   * @return NoticeModel The added or the matching existing notice.
   */
  public static function addWarning($message, $unique = false)
  {
    $noticeController = self::getInstance();
    $notice = $noticeController->addNotice($message, NoticeModel::NOTICE_WARNING, $unique);
    return $notice;
  }

  /**
   * Adds a success notice.
   *
   * @param string $message Notice text, HTML allowed.
   * @param bool   $unique  When true, do not add the notice if one with the
   *                        same message and type already exists.
   * @return NoticeModel The added or the matching existing notice.
   */
  public static function addSuccess($message, $unique = false)
  {
    $noticeController = self::getInstance();
    $notice = $noticeController->addNotice($message, NoticeModel::NOTICE_SUCCESS, $unique);
    return $notice;
  }

  /**
   * Adds a detail line to a notice and stores the array.
   *
   * Details are rendered in a collapsible block under the message, which
   * keeps one notice readable when the same problem occurs for many files.
   *
   * @param NoticeModel $notice Notice to extend.
   * @param string      $detail Detail text, HTML allowed. Duplicates are
   *                            ignored.
   * @return void
   */
  public static function addDetail($notice, $detail)
  {
    $noticeController = self::getInstance();
    $notice->addDetail($detail);

    $noticeController->update();
  }

  /**
   * Makes a notice persistent under a key and stores the array.
   *
   * When no notice with this key exists, the given notice is marked
   * persistent with the key, suppress period and callback. When one exists,
   * the given notice (already added with a null ID by one of the add
   * methods) is removed from the array again so the key stays unique; if its
   * message differs from the stored one, the stored notice takes the new
   * message. In that case the passed $notice object itself is left unchanged
   * and is no longer part of the array.
   *
   * @param NoticeModel   $notice   Notice returned by one of the add methods.
   * @param string        $key      Unique key of the persistent notice.
   * @param int           $suppress Seconds the notice stays hidden after the
   *                                user dismisses it; -1 drops it on dismiss.
   * @param callable|null $callback Called by NoticeModel::getForDisplay()
   *                                with the notice before rendering; a
   *                                return value of false suppresses the
   *                                output. Must survive serialization into
   *                                the option, so closures are not usable.
   * @return void
   */
  public static function makePersistent($notice, $key, $suppress = -1, $callback = null)
  {
     $noticeController = self::getInstance();
     $existing = $noticeController->getNoticeByID($key);

     // if this key already exists, don't allow the new notice to be entered into the array. Remove it since it's already created.
     if ($existing)
     {
       for($i = 0; $i < count(self::$notices); $i++)
       {
         $item = self::$notices[$i];

         if ($item->message == $notice->message && $item->getID() == null)
         {
           if ($item->message != $existing->message) // allow the persistent message to be updated, if something else is served on this ID
           {
              $existing->message = $item->message;
           }
           unset(self::$notices[$i]);
         }
         //if ($notice_item )
       }
     }
     else
     {
       $notice->setPersistent($key, $suppress, $callback); // set this notice persistent.
     }

     $noticeController->update();
  }

  /**
   * Renders the notices that are due and stores the resulting state.
   *
   * Intended as the callback for the `admin_notices` action. Echoes the
   * markup of every notice from getNoticesForDisplay(), hooks the
   * stylesheet output on the first call that has notices, and calls
   * update() so viewed one-shot notices are dropped from the option.
   *
   * @return void
   */
  public function admin_notices()
  {
      if ($this->countNotices() > 0)
      {
          if (! self::$cssHookLoaded)
          {
            add_action('admin_print_footer_scripts', array($this, 'printNoticeStyle'));
            self::$cssHookLoaded = true;
          }
          foreach($this->getNoticesForDisplay() as $notice)
          {
            echo $notice->getForDisplay();
          }
      }
      $this->update(); // puts views, and updates
  }


  /**
   * Prints the module stylesheet inline.
   *
   * Hooked to `admin_print_footer_scripts` by admin_notices(). Reads
   * css/notices.css next to this file; logs a debug message when the file
   * is missing.
   *
   * @return void
   */
  public function printNoticeStyle()
  {
     if (file_exists(__DIR__ . '/css/notices.css'))
     {
       echo '<style>' . esc_html(file_get_contents(__DIR__ . '/css/notices.css')) . '</style>';
     }
     else {
       Log::addDebug('Notices : css/notices.css could not be loaded');
     }
  }




}
