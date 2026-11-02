<?php
/**
 * WPBakery / Visual Composer integration of the replacer module.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Modules;
// Note! This class doubles as integration for both Visual Composer *and* WP Bakery. They both need URLENCODE.
/**
 * Adds a replace run for urlencoded URLs.
 *
 * WPBakery Page Builder and Visual Composer store some URLs urlencoded in
 * post content. This module adds a run whose base URL, search and replace
 * values are all passed through urlencode().
 *
 * Detection: `did_action('vc_plugins_loaded')` (WPBakery) or the
 * `VCV_VERSION` constant (Visual Composer), overridable with the
 * `shortpixel/externals/urlencode_is_active` filter.
 *
 * Lifecycle: singleton via getInstance(), created by
 * Replacer::loadFormats().
 *
 * Hooks listened to: filter `shortpixel/replacer/custom_replace_query` ->
 * addUrlEncoded(). The callback is registered as `addURLEncoded` while the
 * method is declared as `addUrlEncoded`; this works because PHP method
 * names are case-insensitive.
 *
 * @todo Enable Media Replace also ships its own WPBakery integration that
 *       registers an entry under the same `wpbakery` key on the same filter,
 *       so whichever callback runs later overwrites the other's entry.
 *       Suggested fix: keep one of the two integrations.
 */
class WpBakery
{
    /**
     * Shared instance.
     *
     * @var WpBakery|null
     */
    private static $instance;

    /**
     * Key of this run in the custom_replace_query result.
     *
     * @var string
     */
    protected $queryKey = 'wpbakery';

    /**
     * Returns the shared instance, creating it on first use.
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
     * Registers the custom run when WPBakery or Visual Composer is active.
     *
     * @hook filter shortpixel/replacer/custom_replace_query addUrlEncoded(),
     *       priority 10, 4 args.
     */
    public function __construct()
    {
      if ($this->bakery_is_active())   // elementor is active
      {
        add_filter('emr/replacer/custom_replace_query', array($this, 'addURLEncoded'), 10, 4); // custom query for elementor \ // problem
      }
    }

    /**
     * Adds the urlencoded run to the custom replace runs.
     *
     * Base URL, search and replace values are urlencoded. No `args` are set,
     * so the run uses the default replace settings (component `unset`).
     *
     * @hook filter shortpixel/replacer/custom_replace_query
     *
     * @param array  $items        Runs registered so far, keyed by component.
     * @param string $base_url     Base URL of the main run.
     * @param array  $search_urls  Search array of the main run.
     * @param array  $replace_urls Replace array of the main run.
     * @return array $items with the `wpbakery` entry set.
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
     * Urlencodes a value.
     *
     * @param string $value URL or path.
     * @return string urlencode() of the value.
     */
    public function addEncode($value)
    {
        return urlencode($value);
    }

    /**
     * Tells whether WPBakery or Visual Composer is active.
     *
     * @hook filter shortpixel/externals/urlencode_is_active Manual override
     *       of the detection result.
     *
     * @return bool True when `vc_plugins_loaded` has fired or `VCV_VERSION`
     *              is defined, or as filtered.
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
