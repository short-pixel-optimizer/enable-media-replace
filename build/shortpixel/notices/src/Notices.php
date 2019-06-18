<?php
namespace EnableMediaReplace\Notices;
use EnableMediaReplace\ShortPixelLogger as Log;

class NoticeController //extends ShortPixelController
{
  protected static $notices;
  protected static $instance = null;
  public $notice_count = 0;

  protected $has_stored = false;

  public function __construct()
  {
      $this->loadModel('notice');
      $this->loadNotices();
  }


  protected function loadNotices()
  {
    $notices = get_option('shortpixel-notices', false);
    $cnotice = (is_array($notices)) ? count($notices) : 0;
    Log::addDebug('Notice Control - #num notices' . $cnotice);
    if ($notices !== false)
    {
      self::$notices = $notices;
      $this->has_stored = true;
    }
    else {
      self::$notices = array();
      $this->has_stored = false;
    }
    $this->countNotices();
  }

  public function addNotice($message, $code)
  {
      $notice = new NoticeModel($message, $code);
      self::$notices[] = $notice;
      $this->countNotices();
      Log::addDebug('Adding notice - ', $notice);
      $this->update();
      return $notice;
  }

  /** Update the notices to store, check what to remove, returns count.  */
  public function update()
  {
    if (! is_array(self::$notices) || count(self::$notices) == 0)
    {
      if ($this->has_stored)
        delete_option('shortpixel-notices');

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

    update_option('shortpixel-notices', $new_notices);
    self::$notices = $new_notices;

    return $this->countNotices();
  }

  public function countNotices()
  {
      $this->notice_count = count(self::$notices);
      return $this->notice_count;
  }


  public function getNotices()
  {
        return self::$notices;
  }

  public static function getInstance()
  {
     if ( self::$instance === null)
     {
         self::$instance = new NoticeController();
     }

     return self::$instance;
  }

  /** Adds a notice, quick and fast method
  * @param String $message The Message you want to notify
  * @param int $code A value of messageType as defined in model
  * @returm Object Instance of noticeModel
  */

  public static function addNormal($message)
  {
    $noticeController = self::getInstance();
    $notice = $noticeController->addNotice($message, NoticeModel::NOTICE_NORMAL);
    return $notice;

  }

  public static function addError($message)
  {
    $noticeController = self::getInstance();
    $notice = $noticeController->addNotice($message, NoticeModel::NOTICE_ERROR);
    return $notice;

  }

  public static function addWarning($message)
  {
    $noticeController = self::getInstance();
    $notice = $noticeController->addNotice($message, NoticeModel::NOTICE_WARNING);
    return $notice;

  }

  public static function addSuccess($message)
  {
    $noticeController = self::getInstance();
    $notice = $noticeController->addNotice($message, NoticeModel::NOTICE_SUCCESS);
    return $notice;

  }


}
