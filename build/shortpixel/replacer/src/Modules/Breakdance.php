<?php
namespace EnableMediaReplace\Replacer\Modules;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


class Breakdance
{
    private static $instance;

    protected $queryKey = 'breakdance';

    public static function getInstance()
    {
        if (is_null(self::$instance))
          self::$instance = new static();

        return self::$instance;
    }

    public function __construct()
    {
      if (\has_action('breakdance_loaded'))   // elementor is active
      {
         if ($this->checkRequiredFunctions())
         {
				       Log::addTemp('Breakdance loaded');

               add_filter('shortpixel/replacer/custom_replace_query', array($this, 'addBreakdance'), 10, 4);
				       add_filter('shortpixel/replacer/load_meta_value', array($this, 'loadContent'),10,3);
               add_filter('shortpixel/replacer/save_meta_value', array($this, 'saveContent'), 10,3);
          }
          else {
              add_filter('shortpixel/replacer/load_meta_value', array($this, 'abortOnContent'),10,3);
          }
     }
     Log::addTemp('After load Breakdance');
    }

    // This integration uses several Breakdance functions.  Don't something if this dance breaks somehow
    public function checkRequiredFunctions()
    {
        $functions = [
          '\Breakdance\Data\get_tree',
          '\Breakdance\Data\encode_before_writing_to_wp',

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

		public function addBreakdance($items, $base_url, $search_urls, $replace_urls)
		{

			$base_url = $this->addSlash($base_url);
			$el_search_urls = $search_urls; //array_map(array($this, 'addslash'), $search_urls);
			$el_replace_urls = $replace_urls; //array_map(array($this, 'addslash'), $replace_urls);
			//$args = [('json_flags' => 0, 'component' => $this->queryKey];
      $args = ['component' => $this->queryKey];
			$items[$this->queryKey] = array('base_url' => $base_url, 'search_urls' => $el_search_urls, 'replace_urls' => $el_replace_urls, 'args' => $args);
			return $items;

		}

		// @todo This function is duplicated w/ elementor, so possibly at some point needs a Module main class for utils.
		public function addSlash($value)
    {
        global $wpdb;
        $value= ltrim($value, '/'); // for some reason the left / isn't picked up by Mysql.
        $value= str_replace('/', '\/', $value);
        $value =  $wpdb->esc_like(($value)); //(wp_slash) / str_replace('/', '\/', $value);

        return $value;
    }

		public function loadContent($content, $meta_row, $component)
		{
        if ($component !== $this->queryKey)
        {
           return $content;
        }

        Log::addTemp('using tree loader');

        $meta_id = $meta_row['meta_id'];
        $post_id = $meta_row['post_id'];

        $result = \Breakdance\Data\get_tree($post_id);
        if (false === $result)
        {
           Log::addTemp('tree false, returning null');
           return null;
        }


        return $result;
		}

    public function saveContent($content, $meta_row, $component)
    {
      Log::addTemp('Save content comp' . $component);
      if ($component !== $this->queryKey)
      {
         return $content;
      }

      $global = \Breakdance\Data\get_global_option('global_settings_json_string');
Log::addTemp("Doing breakdance save ", $content);
      \Breakdance\Data\save_document($content, $global, null, $meta_row['meta_id']);
/*      Log::addTemp('Saving with some tree content', $content);
        return \Breakdance\Data\encode_before_writing_to_wp([
          'tree_json_string' => $content,
        ], true); */

       return $content;
    }

    // If something is wrong with breakdance, don't replace content for it since it breaks the whole page content.
    public function abortOnContent($content, $meta_row, $component)
    {
      if ($component !== $this->queryKey)
      {
         return $content;
      }

      return null;
    }



} //  class
