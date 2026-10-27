<?php
/**
 * Loader for the EMR-side third-party integrations.
 *
 * Instantiated once by EnableMediaReplacePlugin on `init`, after the
 * capability check has passed. Loads the Elementor, WPBakery / Visual
 * Composer, SiteOrigin and WP Offload Media integrations and offers a hook
 * point to disable replace options when a conflicting plugin is present.
 *
 * @package EnableMediaReplace
 */
namespace EnableMediaReplace;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Notices\NoticeController as Notices;

use EnableMediaReplace\Externals\Elementor as Elementor;
use EnableMediaReplace\Externals\WpBakery as WpBakery;
use EnableMediaReplace\Externals\SiteOrigin as SiteOrigin;
use EnableMediaReplace\Externals\WPOffload as WPOffload;


/**
 * Loads the integrations and filters the replace-type options of the replace screen.
 *
 * Not a singleton: EnableMediaReplacePlugin creates one instance per request.
 * The constructor registers three hooks used by views/screen.php:
 * - filter `emr_display_replace_type_options` -> get_replace_type()
 * - filter `emr_enable_replace_and_search` -> get_replacesearch_type()
 * - action `emr_after_replace_type_options` -> get_messages()
 * It then runs check() and loads each integration singleton.
 *
 * With the current (empty) check(), $replaceType and $replaceSearchType stay
 * null and $messages stays empty, so all three hooks pass values through
 * unchanged and print nothing.
 */
class Externals
{
  /**
   * Forced value for the "replace" option, or null to leave the filtered value unchanged.
   *
   * @var bool|null
   */
  protected $replaceType = null;

  /**
   * Forced value for the "replace and search" option, or null to leave the filtered value unchanged.
   *
   * @var bool|null
   */
  protected $replaceSearchType = null;

  /**
   * Conflict notices printed below the replace-type options.
   *
   * @var string[]
   */
  protected $messages = array();

  /**
   * Registers the replace-type filters and loads the integrations.
   *
   * Calls loadWpOffload(), while the method is declared as loadWPOffload().
   * This works because PHP method names are case-insensitive.
   *
   * @hook filter emr_display_replace_type_options
   * @hook filter emr_enable_replace_and_search
   * @hook action emr_after_replace_type_options
   */
  public function __construct()
  {
    // These hooks prevent loading of options when plugin conflicts arise.
      add_filter('emr_display_replace_type_options', array($this, 'get_replace_type'));
      add_filter('emr_enable_replace_and_search', array($this, 'get_replacesearch_type'));
      add_action('emr_after_replace_type_options', array($this, 'get_messages'));

      $this->check();

      // integrations
      $this->loadElementor();
      $this->loadBakery(); // in case of urlencoded issues, this class should be used probably.
			$this->loadSiteOrigins();
	   $this->loadWpOffload();
  }

  /**
   * Checks for plugin conflicts that should disable replace options.
   *
   * The body is empty: it only holds a commented-out Beaver Builder check
   * (`class_exists('FLBuilder')`) that would disable replace-and-search and
   * add a notice. The commented block is garbled (a stray `return true;` sits
   * inside the `$this->messages[] = ...` assignment), so it cannot be
   * uncommented as is.
   *
   * @todo check() has an empty body containing a garbled, commented-out Beaver Builder block; either restore it as valid code (set $replaceSearchType to false and append the notice to $messages) or remove the method and its call in the constructor.
   *
   * @return void
   */
  protected function check() //  check if any of the options should be disabled due to conflicts
{
    /*if (class_exists('FLBuilder'))
    {
      $this->replaceSearchType = false;
      $this->messages[]				return true;
 = __('Replace and Search feature is not compatible with Beaver Builder.', 'enable-media-replace');
    } */
}

  /**
   * Filters whether the "replace" option is shown.
   *
   * @hook filter emr_display_replace_type_options
   *
   * @param bool $bool Value passed by the view.
   * @return bool $bool when $replaceType is null, otherwise $replaceType.
   */
  public function get_replace_type($bool)
  {
    if ($this->replaceType === null)
      return $bool;

    return $this->replaceType;
  }

  /**
   * Filters whether the "replace and search" option is enabled.
   *
   * @hook filter emr_enable_replace_and_search
   *
   * @param bool $bool Value passed by the view.
   * @return bool $bool when $replaceSearchType is null, otherwise $replaceSearchType.
   */
  public function get_replacesearch_type($bool)
  {
    if ($this->replaceSearchType === null)
      return $bool;

    return $this->replaceSearchType;
  }

  /**
   * Prints each conflict notice in $messages as a `nofeature-notice` span.
   *
   * Messages are echoed without escaping.
   *
   * @hook action emr_after_replace_type_options
   *
   * @return void
   */
  public function get_messages()
  {
      foreach($this->messages as $message)
      {
        echo '<span class="nofeature-notice"><p>'. $message . '</p></span>';
      }
  }

  /**
   * Instantiates the Elementor integration singleton.
   *
   * @return void
   */
  public function loadElementor()
  {
         Elementor::getInstance();
  }

  /**
   * Instantiates the WPBakery / Visual Composer (urlencoded URLs) integration singleton.
   *
   * @return void
   */
  public function loadBakery()
  {
      WpBakery::getInstance();
  }

	/**
	 * Instantiates the SiteOrigin Page Builder integration singleton.
	 *
	 * @return void
	 */
	public function loadSiteOrigins()
	{
		 SiteOrigin::getInstance();
	}

	/**
	 * Instantiates the WP Offload Media integration singleton.
	 *
	 * Called from the constructor as loadWpOffload(); PHP method names are
	 * case-insensitive, so the different casing resolves to this method.
	 *
	 * @return void
	 */
	public function loadWPOffload()
	{
		 WPOffload::getInstance();
	}

} // class
