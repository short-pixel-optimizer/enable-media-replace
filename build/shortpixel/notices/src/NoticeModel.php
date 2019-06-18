<?php
namespace EnableMediaReplace\Notices;

class NoticeModel //extends ShortPixelModel
{
  protected $message;
  public $code;

  protected $viewed = false;
  public $is_persistent = false;  // This is a fatal issue, display until something was fixed.
  public $is_removable = true; // if removable, display a notice dialog with red X or so.
  public $messageType = self::NOTICE_NORMAL;

  const NOTICE_NORMAL = 1;
  const NOTICE_ERROR = 2;
  const NOTICE_SUCCESS = 3;
  const NOTICE_WARNING = 4;


  public function __construct($message, $messageType = self::NOTICE_NORMAL)
  {
      $this->message = $message;
      $this->messageType = $messageType;

  }

  public function isDone()
  {
    if ($this->viewed && ! $this->is_persistent)
      return true;
    else
      return false;

  }

  public function getForDisplay()
  {
    $this->viewed = true;
    $class = 'shortpixel notice ';

    $icon = 'slider';

    switch($this->messageType)
    {
      case self::NOTICE_ERROR:
        $class .= 'notice-error ';
        $icon = 'scared';
      break;
      case self::NOTICE_SUCCESS:
        $class .= 'notice-success ';
      break;
      case self::NOTICE_WARNING:
        $class .= 'notice-warning ';
      break;
      case self::NOTICE_NORMAL:
      default:
        $class .= 'notice-info ';
      break;
    }

    $image =  '<img src="' . plugins_url('/shortpixel-image-optimiser/res/img/robo-' . $icon . '.png') . '"
             srcset="' . plugins_url( 'shortpixel-image-optimiser/res/img/robo-' . $icon . '.png' ) . ' 1x, ' . plugins_url( 'shortpixel-image-optimiser/res/img/robo-' . $icon . '@2x.png') . ' 2x" class="short-pixel-notice-icon">';


    if ($this->is_removable)
    {
      $class .= 'is-dismissible ';
    }

    if ($this->is_persistent)
    {
      $class .= '';
    }

    return "<div class='$class'>" . $image . "<p>" . $this->message . "</p></div>";

  }



  // @todo Transient save, since that is used in some parts.
  // save
  // load


}
