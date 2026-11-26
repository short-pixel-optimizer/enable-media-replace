<?php
/**
 * EMR-side WPBakery / Visual Composer integration.
 *
 * @package EnableMediaReplace
 */
namespace EnableMediaReplace\Externals;


// Note! This class doubles as integration for both Visual Composer *and* WP Bakery. They both need URLENCODE.
/**
 * Adds a search/replace run for URL-encoded image URLs (WPBakery and Visual Composer).
 *
 * Singleton, loaded by Externals::loadBakery(). Active when
 * `did_action('vc_plugins_loaded')` is non-zero (WPBakery) or `VCV_VERSION`
 * is defined (Visual Composer), overridable through the filter
 * `emr/externals/urlencode_is_active`. Detection runs on `init`, when
 * Externals is created. When active it registers the filter
 * `emr/replacer/custom_replace_query` -> addUrlEncoded() (priority 10, 4 args;
 * the callback is registered as `addURLEncoded`, which resolves because PHP
 * method names are case-insensitive).
 *
 * The bundled replacer module (Modules\WpBakery) registers a callback on the
 * same filter that writes to the same `wpbakery` key, so whichever callback
 * runs last overwrites the other's entry.
 *
 * @todo WPBakery support is registered twice under the same `wpbakery` key (here and in the replacer module's Modules\WpBakery); decide which side owns the integration and remove the query registration from the other.
 */
class WpBakery
{
    /**
     * Singleton instance.
     *
     * @var WpBakery|null
     */
    private static $instance;

    /**
     * Key of this run in the `emr/replacer/custom_replace_query` result array.
     *
     * @var string
     */
    protected $queryKey = 'wpbakery';

    /**
     * Returns the singleton, creating it on first call.
     *
     * @return WpBakery
     */
    public static function getInstance()
    {
        if (is_null(self::$instance))
          self::$instance = new WpBakery();

        return self::$instance;
    }

    /**
     * Registers the custom query filter when WPBakery or Visual Composer is detected.
     *
     * @hook filter emr/replacer/custom_replace_query
     */
    public function __construct()
    {
      if ($this->bakery_is_active())   // elementor is active
      {
        add_filter('emr/replacer/custom_replace_query', array($this, 'addURLEncoded'), 10, 4); // custom query for elementor \ // problem
      }
    }

    /**
     * Adds the URL-encoded run to the custom replace queries.
     *
     * The base URL, every search URL and every replace URL are passed
     * through urlencode().
     *
     * @hook filter emr/replacer/custom_replace_query
     *
     * @param array    $items        Runs collected so far, keyed by integration.
     * @param string   $base_url     Base URL used to select candidate rows.
     * @param string[] $search_urls  URLs to search for.
     * @param string[] $replace_urls Replacement URLs, index-aligned with $search_urls.
     * @return array $items with `wpbakery` => array('base_url', 'search_urls', 'replace_urls').
     */
    public function addUrlEncoded($items, $base_url, $search_urls, $replace_urls)
    {
      $base_url = $this->addEncode($base_url);
      $el_search_urls = array_map(array($this, 'addEncode'), $search_urls);
      $el_replace_urls = array_map(array($this, 'addEncode'), $replace_urls);
      $items[$this->queryKey] = array('base_url' => $base_url, 'search_urls' => $el_search_urls, 'replace_urls' => $el_replace_urls);
      return $items;
    }

    /**
     * URL-encodes a value.
     *
     * @param string $value URL.
     * @return string urlencode($value).
     */
    public function addEncode($value)
    {
        return urlencode($value);
    }

    /**
     * Detects WPBakery or Visual Composer.
     *
     * @hook filter emr/externals/urlencode_is_active Manual override of the detection result.
     *
     * @return bool True when `vc_plugins_loaded` has fired or `VCV_VERSION` is defined, unless overridden by the filter.
     */
    protected function bakery_is_active()
    {
       $bool = false;

       // did_action -> wpbakery , VCV_version -> detect Visual Composer
       if (did_action('vc_plugins_loaded') || defined('VCV_VERSION'))
          $bool = true;

        return apply_filters('emr/externals/urlencode_is_active', $bool); // manual override
    }
}
