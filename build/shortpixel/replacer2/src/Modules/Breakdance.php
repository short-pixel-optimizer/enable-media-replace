<?php
/**
 * Breakdance integration of the replacer module.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Modules;

use ReflectionMethod;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


/**
 * Replaces URLs in Breakdance page-builder documents.
 *
 * Breakdance stores its document tree in post meta as JSON with escaped
 * slashes and keeps derived data that must be regenerated on save, so this
 * module reads and writes the tree through Breakdance's own functions
 * instead of the replacer's SQL update.
 *
 * Detection: `has_action('breakdance_loaded')` is truthy when the module is
 * constructed, and checkRequiredFunctions() finds the four Breakdance
 * functions it calls. When the functions are missing, only the
 * abortOnContent() fallback is registered.
 *
 * Lifecycle: singleton via getInstance(), created by
 * Replacer::loadFormats().
 *
 * Hooks listened to:
 * - filter `shortpixel/replacer/custom_replace_query` -> addBreakdance()
 * - filter `shortpixel/replacer/load_meta_value` -> loadContent() (or
 *   abortOnContent() when functions are missing)
 * - filter `shortpixel/replacer/save_meta_value` -> saveContent()
 */
class Breakdance
{
    /**
     * Shared instance.
     *
     * @var Breakdance|null
     */
    private static $instance;

    /**
     * Key of this run in the custom_replace_query result and the
     * `component` value of its replace settings.
     *
     * @var string
     */
    protected $queryKey = 'breakdance';

    /**
     * Returns the shared instance, creating it on first use.
     *
     * @return Breakdance
     */
    public static function getInstance()
    {
        if (is_null(self::$instance))
          self::$instance = new static();

        return self::$instance;
    }

    /**
     * Registers the Breakdance hooks when Breakdance is loaded.
     *
     * @hook filter shortpixel/replacer/custom_replace_query addBreakdance(),
     *       priority 10, 4 args.
     * @hook filter shortpixel/replacer/load_meta_value loadContent(), or
     *       abortOnContent() when required functions are missing; priority
     *       10, 3 args.
     * @hook filter shortpixel/replacer/save_meta_value saveContent(),
     *       priority 10, 3 args.
     */
    public function __construct()
    {
      if (\has_action('breakdance_loaded'))   // elementor is active
      {
         if ($this->checkRequiredFunctions())
         {
               add_filter('emr/replacer/custom_replace_query', array($this, 'addBreakdance'), 10, 4);
				       add_filter('emr/replacer/load_meta_value', array($this, 'loadContent'),10,3);
               add_filter('emr/replacer/save_meta_value', array($this, 'saveContent'), 10,3);
          }
          else {
              add_filter('emr/replacer/load_meta_value', array($this, 'abortOnContent'),10,3);
          }
     }
    }

    // This integration uses several Breakdance functions.  Don't something if this dance breaks somehow
    /**
     * Checks that the Breakdance functions used by this module exist.
     *
     * Checks `get_tree`, `encode_before_writing_to_wp`, `get_global_option`
     * and `save_document` in the `Breakdance\Data` namespace, and logs a
     * warning for the first missing one.
     *
     * @return bool True when all four exist.
     */
    public function checkRequiredFunctions()
    {
        $functions = [
          '\Breakdance\Data\get_tree',
          '\Breakdance\Data\encode_before_writing_to_wp',
          '\Breakdance\Data\get_global_option',
          '\Breakdance\Data\save_document'
        ];

        foreach($functions as $function)
        {
           if (false === function_exists($function))
           {
              Log::addWarn('Replacer breakdance module cannot find ' . $function);
              return false;
           }

        }

        return true;
    }

		/**
		 * Adds the Breakdance run to the custom replace runs.
		 *
		 * The run uses the slash-escaped base URL (addSlash()) to find the
		 * JSON-stored tree, the search and replace arrays unchanged (the tree
		 * is decoded before replacing), and the replace settings
		 * `component` = `breakdance`, `replacer_do_save` = false (saving is
		 * done in saveContent()) and `replace_no_serialize` = true (the
		 * replaced tree stays an array/object for saveContent()).
		 *
		 * @hook filter shortpixel/replacer/custom_replace_query
		 *
		 * @param array  $items        Runs registered so far, keyed by component.
		 * @param string $base_url     Base URL of the main run.
		 * @param array  $search_urls  Search array of the main run.
		 * @param array  $replace_urls Replace array of the main run.
		 * @return array $items with the `breakdance` entry set.
		 */
		public function addBreakdance($items, $base_url, $search_urls, $replace_urls)
		{

			$base_url = $this->addSlash($base_url);
			$el_search_urls = $search_urls; //array_map(array($this, 'addslash'), $search_urls);
			$el_replace_urls = $replace_urls; //array_map(array($this, 'addslash'), $replace_urls);
			//$args = [('json_flags' => 0, 'component' => $this->queryKey];
      $args = ['component' => $this->queryKey, 'replacer_do_save' => false, 'replace_no_serialize' => true];
			$items[$this->queryKey] = array('base_url' => $base_url, 'search_urls' => $el_search_urls, 'replace_urls' => $el_replace_urls, 'args' => $args);
			return $items;

		}

		// @todo This function is duplicated w/ elementor, so possibly at some point needs a Module main class for utils.
		/**
		 * Converts a path to the slash-escaped JSON form for a LIKE query.
		 *
		 * Removes the leading slash, replaces `/` with `\/` and applies
		 * `$wpdb->esc_like()`. Identical to Elementor::addSlash().
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
		 * Loads the Breakdance tree of a post instead of the raw meta value.
		 *
		 * Acts only on the `breakdance` run and on rows that carry `post_id`
		 * (post meta rows); returns $content untouched otherwise. Returns the
		 * result of `Breakdance\Data\get_tree($post_id)`, or null when that is
		 * false, which makes the replacer skip the row.
		 *
		 * @hook filter shortpixel/replacer/load_meta_value
		 *
		 * @param mixed  $content   Meta value as loaded.
		 * @param array  $meta_row  Selected row (`meta_id`, `meta_value`,
		 *                          `post_id`).
		 * @param string $component Component of the current run.
		 * @return mixed The tree, $content, or null to skip the row.
		 */
		public function loadContent($content, $meta_row, $component)
		{
        if ($component !== $this->queryKey)
        {
           return $content;
        }

        if (false === isset($meta_row['post_id']))
        {
           return $content; 
        }

        $meta_id = $meta_row['meta_id'];
        $post_id = $meta_row['post_id'];

        $result = \Breakdance\Data\get_tree($post_id);
        if (false === $result)
        {
           Log::addWarn("Breakdance integration: Tree returns as false");
           return null;
        }

        return $result;
		}

    /**
     * Saves the replaced tree through Breakdance.
     *
     * Acts only on the `breakdance` run and on rows with `post_id`. Encodes
     * the tree with JSON_UNESCAPED_SLASHES, reads the global settings option
     * and calls `Breakdance\Data\save_document()`. The signature differs
     * between Breakdance versions, so the parameter count is read by
     * reflection: with 4 parameters it is called as (tree, global settings,
     * null, post id); with 10 as (tree, eight times false, post id). Any
     * other count saves nothing. When reflection throws, the error is logged
     * and the 4-parameter form is used.
     *
     * @hook filter shortpixel/replacer/save_meta_value
     *
     * @param mixed  $content   Replaced tree.
     * @param array  $meta_row  Selected row (`meta_id`, `meta_value`,
     *                          `post_id`).
     * @param string $component Component of the current run.
     * @return mixed $content unchanged; the replacer does not save it
     *               because the run sets `replacer_do_save` to false.
     */
    public function saveContent($content, $meta_row, $component)
    {
      if ($component !== $this->queryKey)
      {
         return $content;
      }

      if (false === isset($meta_row['post_id']))
      {
         return $content; 
      }

      $global = \Breakdance\Data\get_global_option('global_settings_json_string');

      $tree = json_encode($content, JSON_UNESCAPED_SLASHES);

    
     $paramCount = 4; // Default. 
     try 
     {
       $method = new \ReflectionFunction('Breakdance\Data\save_document');
       $paramCount = $method->getNumberOfParameters();
     }
     catch (\Exception $e)
     {
        Log::addError('Reflection check in breakdance failing', $e);
     }

     // Old vs New.  Tried via Ajax Method which seems more dynamic, put this path ends the PHP script via send_json. 
     if (4 === $paramCount)
     {
          \Breakdance\Data\save_document($tree, $global, null, $meta_row['post_id']);
     }
     elseif (10 === $paramCount)
     {
          \Breakdance\Data\save_document($tree, false, false, false, false, false, false, false, false, $meta_row['post_id']);
     }
    

       return $content;
    }

    // If something is wrong with breakdance, don't replace content for it since it breaks the whole page content.
    /**
     * Skips every row of the `breakdance` run.
     *
     * Registered instead of loadContent() when a required function is
     * missing. In that case addBreakdance() is not registered either, so a
     * `breakdance` run only exists when another callback adds one.
     *
     * @hook filter shortpixel/replacer/load_meta_value
     *
     * @param mixed  $content   Meta value as loaded.
     * @param array  $meta_row  Selected row.
     * @param string $component Component of the current run.
     * @return mixed|null Null for the `breakdance` run, else $content.
     */
    public function abortOnContent($content, $meta_row, $component)
    {
      if ($component !== $this->queryKey)
      {
         return $content;
      }

      return null;
    }



} //  class
