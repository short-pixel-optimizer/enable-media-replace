<?php 
namespace EnableMediaReplace\Replacer\Classes; 

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class Url extends Data
{

    public function addData($url)
    {
         $this->data[] = $url;
    }


    public function getBaseURL()
    {
        $source_url = $this->data[0]; 
        $base_url = parse_url($source_url, PHP_URL_PATH);
		$base_url = str_replace('.' . pathinfo($base_url, PATHINFO_EXTENSION), '', $base_url);

        return $base_url;
    }
} 