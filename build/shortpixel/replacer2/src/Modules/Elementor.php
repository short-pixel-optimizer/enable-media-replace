<?php
/**
 * Elementor integration of the replacer module.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Modules;

/**
 * Adds a replace run for Elementor data.
 *
 * Elementor stores its page data (`_elementor_data`) as JSON with escaped
 * slashes, which the main run's base URL does not match. This module adds a
 * run whose base URL is in the slash-escaped form; replaceContent() decodes
 * the JSON, replaces and re-encodes it.
 *
 * Detection: the `ELEMENTOR_VERSION` constant is defined, overridable with
 * the `shortpixel/externals/elementor_is_active` filter.
 *
 * Lifecycle: singleton via getInstance(), created by
 * Replacer::loadFormats().
 *
 * Hooks listened to: filter `shortpixel/replacer/custom_replace_query` ->
 * addElementor().
 *
 * @todo Enable Media Replace also ships its own Elementor integration that
 *       registers an entry under the same `elementor` key on the same filter,
 *       so whichever callback runs later overwrites the other's entry.
 *       Suggested fix: keep one of the two integrations.
 */
class Elementor
{
    /**
     * Shared instance.
     *
     * @var Elementor|null
     */
    private static $instance;

    /**
     * Key of this run in the custom_replace_query result.
     *
     * @var string
     */
    protected $queryKey = 'elementor';

    /**
     * Returns the shared instance, creating it on first use.
     *
     * @return Elementor
     */
    public static function getInstance()
    {
        if (is_null(self::$instance))
          self::$instance = new Elementor();

        return self::$instance;
    }

    /**
     * Registers the custom run when Elementor is active.
     *
     * @hook filter shortpixel/replacer/custom_replace_query addElementor(),
     *       priority 10, 4 args.
     */
    public function __construct()
    {
      if ($this->elementor_is_active())   // elementor is active
      {
        add_filter('emr/replacer/custom_replace_query', array($this, 'addElementor'), 10, 4); // custom query for elementor \ // problem

      }
    }

    /**
     * Adds the Elementor run to the custom replace runs.
     *
     * The base URL is converted with addSlash(); the search and replace
     * arrays are used unchanged. No `args` are set, so the run uses the
     * default replace settings (component `unset`).
     *
     * @hook filter shortpixel/replacer/custom_replace_query
     *
     * @param array  $items        Runs registered so far, keyed by component.
     * @param string $base_url     Base URL of the main run.
     * @param array  $search_urls  Search array of the main run.
     * @param array  $replace_urls Replace array of the main run.
     * @return array $items with the `elementor` entry set.
     */
    public function addElementor($items, $base_url, $search_urls, $replace_urls)
    {
      $base_url = $this->addSlash($base_url);
      $el_search_urls = $search_urls; //array_map(array($this, 'addslash'), $search_urls);
      $el_replace_urls = $replace_urls; //array_map(array($this, 'addslash'), $replace_urls);
      $items[$this->queryKey] = array('base_url' => $base_url, 'search_urls' => $el_search_urls, 'replace_urls' => $el_replace_urls);
      return $items;
    }

    /**
     * Converts a path to the slash-escaped JSON form for a LIKE query.
     *
     * Removes the leading slash, replaces `/` with `\/` and applies
     * `$wpdb->esc_like()`.
     *
     * @global \wpdb $wpdb
     *
     * @param string $value Relative URL path.
     * @return string Escaped LIKE fragment.
     */
    public function addSlash($value)
    {
        global $wpdb;
        $value= ltrim($value, '/'); // for some reason the left / isn't picked up by Mysql.
        $value= str_replace('/', '\/', $value);
        $value =  $wpdb->esc_like(($value)); //(wp_slash) / str_replace('/', '\/', $value);

        return $value;
    }

    /**
     * Tells whether Elementor is active.
     *
     * @hook filter shortpixel/externals/elementor_is_active Manual override
     *       of the detection result.
     *
     * @return bool True when `ELEMENTOR_VERSION` is defined, or as filtered.
     */
    protected function elementor_is_active()
    {
       $bool = false;

       if (defined('ELEMENTOR_VERSION'))
          $bool = true;

        return apply_filters('emr/externals/elementor_is_active', $bool); // manual override
    }

    /**
     * Clears Elementor's generated CSS file cache.
     *
     * Not hooked anywhere in this module; available for consumers. Requires
     * Elementor to be loaded.
     *
     * @return void
     */
    public function removeCache()
    {
       \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
}
