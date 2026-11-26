<?php
/**
 * EMR-side Elementor integration.
 *
 * @package EnableMediaReplace
 */
namespace EnableMediaReplace\Externals;

/**
 * Adds an Elementor-specific search/replace run and clears Elementor's file cache after a replace.
 *
 * Singleton, loaded by Externals::loadElementor(). Active when the
 * `ELEMENTOR_VERSION` constant is defined, overridable through the filter
 * `emr/externals/elementor_is_active`. When active it registers:
 * - filter `emr/replacer/custom_replace_query` -> addElementor() (priority 10, 4 args)
 * - action `enable-media-replace-upload-done` -> removeCache()
 *
 * Elementor stores its data as JSON, where `/` is written as `\/`; the extra
 * query run searches for the base URL in that escaped form.
 *
 * The bundled replacer module (Modules\Elementor) registers a callback on the
 * same filter that writes to the same `elementor` key of the returned array,
 * so whichever callback runs last overwrites the other's entry. The module's
 * copy is registered when the Replacer is constructed, usually after this one.
 *
 * @todo Elementor support is registered twice under the same `elementor` key (here and in the replacer module's Modules\Elementor); decide which side owns the integration and remove the query registration from the other.
 */
class Elementor
{
    /**
     * Singleton instance.
     *
     * @var Elementor|null
     */
    private static $instance;

    /**
     * Key of this run in the `emr/replacer/custom_replace_query` result array.
     *
     * @var string
     */
    protected $queryKey = 'elementor';

    /**
     * Returns the singleton, creating it on first call.
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
     * Registers the hooks when Elementor is detected.
     *
     * @hook filter emr/replacer/custom_replace_query
     * @hook action enable-media-replace-upload-done
     */
    public function __construct()
    {
      if ($this->elementor_is_active())   // elementor is active
      {
        add_filter('emr/replacer/custom_replace_query', array($this, 'addElementor'), 10, 4); // custom query for elementor \ // problem
        add_action('enable-media-replace-upload-done', array($this, 'removeCache') );
      }
    }

    /**
     * Adds the Elementor run to the custom replace queries.
     *
     * Only the base URL (used in the SQL LIKE that selects rows) is escaped
     * with addSlash(); the search and replace URLs are passed unchanged.
     *
     * @hook filter emr/replacer/custom_replace_query
     *
     * @param array    $items        Runs collected so far, keyed by integration.
     * @param string   $base_url     Base URL used to select candidate rows.
     * @param string[] $search_urls  URLs to search for.
     * @param string[] $replace_urls Replacement URLs, index-aligned with $search_urls.
     * @return array $items with `elementor` => array('base_url', 'search_urls', 'replace_urls').
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
     * Converts a URL into the form used in a MySQL LIKE against JSON-encoded data.
     *
     * Strips the leading `/`, replaces every `/` with `\/` (JSON escaped
     * slash) and then escapes the result with `$wpdb->esc_like()`.
     *
     * @global \wpdb $wpdb
     *
     * @param string $value URL or path.
     * @return string Escaped value for a LIKE pattern.
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
     * Detects Elementor.
     *
     * @hook filter emr/externals/elementor_is_active Manual override of the detection result.
     *
     * @return bool True when `ELEMENTOR_VERSION` is defined, unless overridden by the filter.
     */
    protected function elementor_is_active()
    {
       $bool = false;

       if (defined('ELEMENTOR_VERSION'))
          $bool = true;

        return apply_filters('emr/externals/elementor_is_active', $bool); // manual override
    }

    /**
     * Clears Elementor's generated files cache (CSS) after a replace.
     *
     * Assumes `\Elementor\Plugin::$instance` exists; when detection was forced
     * on through the override filter without Elementor loaded, this call fails.
     *
     * @hook action enable-media-replace-upload-done
     *
     * @return void
     */
    public function removeCache()
    {
       \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
}
