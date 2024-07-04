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
				Log::addTemp('Breakdance loaded');
        add_filter('shortpixel/replacer/custom_replace_query', array($this, 'addBreakdance'), 10, 4);
				add_filter('shortpixel/replacer/load_meta_value', array($this, 'loadContent'),10,3);

     }
    }

		public function addBreakdance($items, $base_url, $search_urls, $replace_urls)
		{

			$base_url = $this->addSlash($base_url);
			$el_search_urls = $search_urls; //array_map(array($this, 'addslash'), $search_urls);
			$el_replace_urls = $replace_urls; //array_map(array($this, 'addslash'), $replace_urls);
			$args = array('json_flags' => 0, 'component' => $this->queryKey);
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

		public function loadContent($content, $meta_key, $component)
		{
			 Log::addTemp("Component", $component);
			 Log::addTemp('MetaKey', $meta_key);
			 if ($component ==  $this->queryKey)
			 {
			 	Log::addTemp('Precise Content', $content);
			}

			return $content;
		}



} //  class
