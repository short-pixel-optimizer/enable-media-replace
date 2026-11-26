<?php
/**
 * Notice data class of the notices module.
 *
 * @package EnableMediaReplace\Notices
 */
namespace EnableMediaReplace\Notices;

/**
 * One admin notice: message, type, details, persistence and dismiss state.
 *
 * Created by NoticeController::addNotice() and serialized as-is into the
 * notices option, so every property that must survive between requests is
 * plain data. A notice starts as one-shot: getForDisplay() marks it viewed
 * and isDone() then reports it finished, so the controller drops it after
 * the first render. setPersistent() gives it a key, after which it is shown
 * on every page until dismiss() is called; a dismissed persistent notice is
 * hidden and becomes finished once its suppress period has passed.
 *
 * getForDisplay() returns the complete markup of the notice, including the
 * inline scripts that fade out a one-shot notice on click and that post the
 * dismissal of a persistent notice to the controller's AJAX handler. Icons
 * per type are static state set through setIcon() on every request, they
 * are not stored.
 *
 * Screen limits set with limitScreens() are stored but not applied here;
 * the consumer calls checkScreen() with the current screen ID before
 * rendering.
 *
 * Fires and listens to no hooks.
 *
 * @todo In getForDisplay(), the branch for a callback that is a single
 *       object calls checkIncomplete($part), but $part is only set in the
 *       array branch, so the check runs on an undefined variable and never
 *       detects an incomplete object. Suggested fix: pass $this->callback.
 * @todo getDismissJS() tests self::$jsDismissLoaded to print the shared
 *       `shortpixel_notice_dismiss` function once, but the property is never
 *       assigned, so the function is printed again for every persistent
 *       notice on the page. Suggested fix: set the flag to true after the
 *       first print.
 */
class NoticeModel //extends ShortPixelModel
{
  /**
   * Notice text, HTML allowed.
   *
   * @var string
   */
  public $message;
  /**
   * Extra lines (for example the files involved) rendered in a collapsible
   * block under the message.
   *
   * @var string[]
   */
  public $details = array();
  /**
   * Never assigned, so always null. The notice type lives in $messageType.
   * Compared by NoticeController::addNotice() for the uniqueness check.
   *
   * @var null
   */
  public $code;

  /**
   * Key of a persistent notice, also used as the HTML element ID. Null for
   * one-shot notices.
   *
   * @var string|null
   */
  private $id = null;
  /**
   * Whether getForDisplay() has run for this notice. A viewed one-shot
   * notice is finished.
   *
   * @var bool
   */
  protected $viewed = false;
  /**
   * Whether the notice is shown on every page until dismissed or removed.
   *
   * @var bool
   */
  protected $is_persistent = false;
  /**
   * Whether the user dismissed this persistent notice.
   *
   * @var bool
   */
  protected $is_dismissed = false;
  /**
   * Timestamp until which a dismissed notice stays hidden; after it the
   * notice is dropped. Null until dismissed.
   *
   * @var int|null
   */
  protected $suppress_until = null;
  /**
   * Seconds a dismissed notice stays hidden before it is dropped, set by
   * setPersistent(). -1 drops it right after dismissal.
   *
   * @var int
   */
  protected $suppress_period = -1;
	/**
	 * Screen IDs the notice is limited to. Empty means every screen.
	 *
	 * @var string[]
	 */
	protected $include_screens = array();
	/**
	 * Screen IDs the notice is never shown on.
	 *
	 * @var string[]
	 */
	protected $exclude_screens = array();
  /**
   * Whether a dismiss button is rendered. Without it a persistent notice
   * can only be removed by the consumer.
   *
   * @var bool
   */
  public $is_removable = true;
  /**
   * One of the NOTICE_* constants, selects the CSS class and icon.
   *
   * @var int
   */
  public $messageType = self::NOTICE_NORMAL;

  /**
   * AJAX action name the dismiss script posts to. Set by
   * NoticeController::getNoticesForDisplay() on persistent notices right
   * before rendering; empty otherwise.
   *
   * @var string|null
   */
  public $notice_action;
  /**
   * Optional callable run by getForDisplay() before rendering; a return
   * value of false suppresses the notice. Stored with the notice, so it must
   * be serializable.
   *
   * @var callable|null
   */
  protected $callback;

  /**
   * Icon HTML per NOTICE_* type, set through setIcon() each request.
   *
   * @var array<int,string>
   */
  public static $icons = array();

  /**
   * Intended guard against printing the shared dismiss function more than
   * once per page. Checked in getDismissJS().
   *
   * @var bool|null
   */
  private static $jsDismissLoaded;

  /** Informational notice, rendered with the `notice-info` class. */
  const NOTICE_NORMAL = 1;
  /** Error notice, rendered with the `notice-error` class. */
  const NOTICE_ERROR = 2;
  /** Success notice, rendered with the `notice-success` class. */
  const NOTICE_SUCCESS = 3;
  /** Warning notice, rendered with the `notice-warning` class. */
  const NOTICE_WARNING = 4;

  /**
   * Creates a one-shot notice.
   *
   * Use the add methods of NoticeController instead of constructing
   * directly, so the notice is registered and stored.
   *
   * @param string $message     Notice text, HTML allowed.
   * @param int    $messageType One of the NOTICE_* constants.
   */
  public function __construct($message, $messageType = self::NOTICE_NORMAL)
  {
      $this->message = $message;
      $this->messageType = $messageType;
  }

  /**
   * Tells whether the notice can be dropped from storage.
   *
   * A dismissed notice whose suppress period has passed loses its
   * persistent flag here, as a side effect, so it is treated like a viewed
   * one-shot notice.
   *
   * @return bool True when the notice was viewed and is not (or no longer)
   *              persistent.
   */
  public function isDone()
  {
    // check suppressed
    if ($this->is_dismissed && ! is_null($this->suppress_until))
    {
        if (time() >= $this->suppress_until)
        {
            $this->is_persistent = false; // unpersist, so it will be cleaned and dropped.
        }
    }

    if ($this->viewed && ! $this->is_persistent)
      return true;
    else
      return false;
  }

  /**
   * Returns the persistent key.
   *
   * @return string|null The key, or null for a one-shot notice.
   */
  public function getID()
  {
     return $this->id;
  }

  /**
   * Tells whether the notice is persistent.
   *
   * @return bool
   */
  public function isPersistent()
  {
     return $this->is_persistent;
  }

  /**
   * Tells whether the user dismissed the notice.
   *
   * @return bool
   */
  public function isDismissed()
  {
    return $this->is_dismissed;
  }

  /**
   * Marks the notice dismissed and starts its suppress period.
   *
   * Called by the controller's AJAX handler. With a suppress period of -1
   * the suppress time is already in the past, so the next isDone() check
   * lets the controller drop the notice.
   *
   * @return void
   */
  public function dismiss()
  {
     $this->is_dismissed = true;
     $this->suppress_until = time() + $this->suppress_period;
  }

  /**
   * Clears the dismissed flag so the notice is shown again. Leaves the
   * suppress timestamp as it is.
   *
   * @return void
   */
  public function unDismiss()
  {
    $this->is_dismissed = false;
  }

  /**
   * Overrides the time until which a dismissed notice stays hidden.
   *
   * @param int $timestamp Unix timestamp.
   * @return void
   */
  public function setDismissedUntil($timestamp)
  {
    $this->suppress_until = $timestamp;
  }

  /**
   * Adds a detail line under the message.
   *
   * Lets one notice report the same problem for several files without
   * repeating the message. Duplicate lines are ignored.
   *
   * @param string $detail Detail text, HTML allowed.
   * @param bool   $clean  When true, existing details are discarded first.
   * @return void
   */
  public function addDetail($detail, $clean = false)
  {
      if ($clean)
        $this->details = array();

      if (! in_array($detail, $this->details) )
        $this->details[] = $detail;
  }

	/**
	 * Limits the notice to, or excludes it from, admin screens.
	 *
	 * Only records the screen IDs; the consumer enforces them through
	 * checkScreen().
	 *
	 * @param string          $method  `exclude` to hide the notice on these
	 *                                 screens, anything else to show it only
	 *                                 on these screens.
	 * @param string|string[] $screens Screen ID or list of screen IDs, added
	 *                                 to the existing ones.
	 * @return void
	 */
	public function limitScreens($method, $screens)
	{
			if ($method == 'exclude')
			{
				 $var = 'exclude_screens';
			}
			else {
				  $var = 'include_screens';
			}

			if (is_array($screens))
			{
				 $this->$var = array_merge($this->$var, $screens);
			}
			else {
				 $this->{$var}[] = $screens; // strange syntax is PHP 5.6 compat.
			}
	}

	/**
	 * Tells whether the notice may be shown on a screen.
	 *
	 * Excluded screens win over included ones. Without an include list every
	 * screen that is not excluded is allowed.
	 *
	 * @param string $screen_id Screen ID to check, normally the current one.
	 * @return bool
	 */
	public function checkScreen($screen_id)
	{
			if (in_array($screen_id, $this->exclude_screens))
			{
				 return false;
			}
			if (in_array($screen_id, $this->include_screens))
			{
				 return true;
			}

			// if include is set, don't show if not screen included.
			if (count($this->include_screens) == 0)
			{
				return true;
			}
			else {
				return false;
			}
	}


  /**
   * Makes the notice persistent under a key.
   *
   * The notice is then shown on every page until dismissed or removed. The
   * key becomes the element ID in the markup and the ID the dismiss request
   * sends back. Use NoticeController::makePersistent(), which also keeps
   * keys unique and stores the change.
   *
   * @param string        $key      Unique key of this notice.
   * @param int           $suppress Seconds the notice stays hidden after
   *                                dismissal; -1 drops it on dismissal.
   * @param callable|null $callback Called with the notice before rendering;
   *                                ignored when not callable.
   * @return void
   */
  public function setPersistent($key, $suppress = -1, $callback = null)
  {
      $this->id = $key;
      $this->is_persistent = true;
      $this->suppress_period = $suppress;
      if ( ! is_null($callback) && is_callable($callback))
      {
        $this->callback = $callback;
      }
  }

  /**
   * Sets the icon HTML for a notice type, for every notice.
   *
   * @param string $notice_type `error`, `success`, `warning` or `normal`;
   *                            unknown names map to the normal type.
   * @param string $icon        Icon HTML, output unescaped.
   * @return void
   */
  public static function setIcon($notice_type, $icon)
  {
    switch($notice_type)
    {
      case 'error':
        $type = self::NOTICE_ERROR;
      break;
      case 'success':
        $type = self::NOTICE_SUCCESS;
      break;
      case 'warning':
        $type = self::NOTICE_WARNING;
      break;
      case 'normal':
      default:
        $type = self::NOTICE_NORMAL;
      break;
    }
    self::$icons[$type] = $icon;
  }

	/**
	 * Reads a property by name, for debug views.
	 *
	 * @param string $var Property name.
	 * @return mixed The property value, or null when no such property exists.
	 */
	public function _debug_getvar($var)
	{
		 if (property_exists($this, $var))
		 {
			  return $this->$var;
		 }
	}

  /**
   * Tells whether a stored callback part is an object whose class was not
   * available when the notice was unserialized from the option.
   *
   * @param mixed $var Callback or part of a callback.
   * @return bool
   */
  private function checkIncomplete($var)
  {
     return ($var instanceof \__PHP_Incomplete_Class);
  }

  /**
   * Builds the HTML of the notice and marks it viewed.
   *
   * Runs the callback first when one is set: the notice is not rendered when
   * the callback refers to an incomplete class, is not callable or returns
   * false. The markup is a WordPress `notice-*` element with the icon, the
   * message, the optional details block and, for removable notices, a
   * dismiss button. A one-shot notice gets an inline script that only hides
   * it; a persistent notice gets the script from getDismissJS() that also
   * posts the dismissal to the controller. The element ID is the persistent
   * key, or a uniqid() value for one-shot notices.
   *
   * @return string|false|null The markup; false when the callback contains
   *                           an incomplete object, null when the callback
   *                           is not callable or returns false.
   */
  public function getForDisplay()
  {
    $this->viewed = true;
    $class = 'shortpixel shortpixel-notice ';

    $icon = '';

    if ($this->callback)
    {
      if (is_array($this->callback))
      {
        foreach($this->callback as $part)
        {
          if ($this->checkIncomplete($part) === true)
          {
              return false;
          }
        }
      } elseif (is_object($this->callback))
      {
            if ($this->checkIncomplete($part) === true)
              return false;
      }

			if (! is_callable($this->callback))
			{
				 return;
			}
			else {
				$return = call_user_func($this->callback, $this);
        if ($return === false) // don't display is callback returns false explicitly.
         return;

			}
    }

    switch($this->messageType)
    {
      case self::NOTICE_ERROR:
        $class .= 'notice-error ';
        $icon = isset(self::$icons[self::NOTICE_ERROR]) ? self::$icons[self::NOTICE_ERROR] : '';
        //$icon = 'scared';
      break;
      case self::NOTICE_SUCCESS:
        $class .= 'notice-success ';
        $icon = isset(self::$icons[self::NOTICE_SUCCESS]) ? self::$icons[self::NOTICE_SUCCESS] : '';
      break;
      case self::NOTICE_WARNING:
        $class .= 'notice-warning ';
        $icon = isset(self::$icons[self::NOTICE_WARNING]) ? self::$icons[self::NOTICE_WARNING] : '';
      break;
      case self::NOTICE_NORMAL:
         $class .= 'notice-info ';
         $icon = isset(self::$icons[self::NOTICE_NORMAL]) ? self::$icons[self::NOTICE_NORMAL] : '';
      break;
      default:
        $class .= 'notice-info ';
        $icon = '';
      break;
    }


    if ($this->is_removable)
    {
      $class .= 'is-dismissible ';
    }

    if ($this->is_persistent)
    {
      $class .= 'is-persistent ';
    }

    $id = ! is_null($this->id) ?  $this->id : uniqid();

    $output = sprintf('<div id="%s" class="%s"><span class="icon">%s</span><span class="content">%s', $id, $class, $icon,$this->message);

    if ($this->hasDetails())
    {

      $details = sprintf('<div class="details-wrapper">
            <input type="checkbox" name="detailhider" id="check-%s">
            <label for="check-%s"  class="show-details"><span>%s</span>
            </label>
             <div class="detail-content-wrapper"><p class="detail-content">%s</p></div>
          <label for="check-%s" class="hide-details"><span>%s</span></label>
            </div>', $id, $id, __('See Details', 'enable-media-replace'), $this->parseDetails(), $id, __('Hide Details', 'enable-media-replace') );

      $output .= $details;

    }
    $output .= "</span>";

    if ($this->is_removable)
    {
        $output .= sprintf('<button type="button" id="button-%s" class="notice-dismiss" data-dismiss="%s" ><span class="screen-reader-text">%s</span></button>', $id,  $this->suppress_period, __('Dismiss this notice', 'enable-media-replace') );

       if (! $this->is_persistent)
       {
                $output .= "<script type='text/javascript'>\n
                                document.getElementById('button-$id').onclick = function()
                                {
                                  var el = document.getElementById('$id');
                           				jQuery(el).fadeTo(100,0,function() {
                               		jQuery(el).slideUp(100, 0, function () {
                                  jQuery(el).remove();
                               })
                           });
                         } </script>";
       }
    }

    $output .= "</div>";

    if ($this->is_persistent && $this->is_removable)
    {
        $output .= "<script type='text/javascript'>\n" . $this->getDismissJS() . "\n</script>";
    }
    return $output;
  }

  /**
   * Tells whether the notice has detail lines.
   *
   * @return bool
   */
  protected function hasDetails()
  {
     if (is_array($this->details) && count($this->details) > 0)
      return true;
    else
      return false;
  }

  /**
   * Joins the detail lines for output.
   *
   * @return string Details separated by `<BR>`.
   */
  protected function parseDetails()
  {
      return implode('<BR>', $this->details);
  }

  /**
   * Builds the script that posts a dismissal of this notice to the
   * controller.
   *
   * Defines the shared `shortpixel_notice_dismiss` handler, which posts
   * `plugin_action`, `action` ($notice_action), a `dismiss` nonce, the
   * dismiss type from the button's `data-dismisstype` attribute, the
   * suppress period from `data-dismiss` and the element ID to admin-ajax.php,
   * then hides the notice. Binds the handler to the dismiss buttons of this
   * notice on DOM ready.
   *
   * @return string JavaScript without script tags.
   */
  private function getDismissJS()
  {

      $js = '';
      if (is_null(self::$jsDismissLoaded))
      {
          $nonce = wp_create_nonce('dismiss');
          $url = wp_json_encode(admin_url('admin-ajax.php'));
          $js = "function shortpixel_notice_dismiss(event) {
                    event.preventDefault();
                    var target = event.target;
                    if (target.tagName !== 'button') // tricky this
                    {
                        target = target.closest('button');
                    }
                    var parent = target.closest('.shortpixel-notice');

                    if (target.hasAttribute('data-dismisstype'))
                    {
                       var type = target.getAttribute('data-dismisstype');
                    }
                    else {
                       var type = 'dismiss';
                    }

                    var data = {
                      'plugin_action': 'dismiss',
                      'action' : '$this->notice_action',
                      'nonce' : '$nonce',
                      'dismisstype' : type,
                    }
                    data.time = target.getAttribute('data-dismiss');
                    data.id = parent.getAttribute('id');
                    jQuery.post($url,data);

                    jQuery(parent).fadeTo(100,0,function() {
                        jQuery(parent).slideUp(100, 0, function () {
                            jQuery(parent).remove();
                        })
                    });
          }";
      }

      // This all needs to be formalized in a better script, regardless of class, also in proper scope.
      $js .=  ' jQuery("#' . $this->id . '").find(".notice-dismiss, .notice-dismiss-action").on("click", shortpixel_notice_dismiss); ';

      return "\n jQuery(document).ready(function(){ \n" . $js . "\n});";
  }

}
