<?php 

namespace EnableMediaReplace\Replacer\Classes; 

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

abstract class Data
{

    protected $data = []; 
    protected $type; // search|replace 
    protected $errors = []; 

    abstract function addData($data); 


    public function __construct($type)
    {
        $this->type = $type; 
    }

    public function hasErrors()
    {
         if (count($this->errors) === 0)
         {
             return false; 
         }
         return true; 
    }


    protected function getRelativeURLS($dataArray)
    {
        $result = [];

		foreach ($dataArray as $index => $item) {
			$result[$index] = array();
			$metadata = $item['metadata'];

			$baseurl = parse_url($item['url'], PHP_URL_PATH);
			$result[$index]['base'] = $baseurl;  // this is the relpath of the mainfile.
			$baseurl = trailingslashit(str_replace(wp_basename($item['url']), '', $baseurl)); // get the relpath of main file.

			foreach ($metadata as $name => $filename) {
				$result[$index][$name] =  $baseurl . wp_basename($filename); // filename can have a path like 19/08 etc.
			}
		}
		//    Log::addDebug('Relative URLS', $result);
		return $result;
    }

} // class