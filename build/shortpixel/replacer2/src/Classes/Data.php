<?php
/**
 * Base data class of the newer search/replace API.
 *
 * @package EnableMediaReplace\Replacer
 */

namespace EnableMediaReplace\Replacer\Classes;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Base class for the data of one side (search or replace) of a replacement.
 *
 * Subclasses (MetaData, Url) implement addData() to store their input.
 * Instances are created by Setup with the side name. Fires and listens to no
 * hooks.
 */
abstract class Data
{

    /**
     * Data stored by addData(); shape depends on the subclass.
     *
     * @var array
     */
    protected $data = [];

    /**
     * Side this data belongs to.
     *
     * @var string `search` or `replace`.
     */
    protected $type; // search|replace

    /**
     * Error messages. Nothing in the module adds to it yet.
     *
     * @var array
     */
    protected $errors = [];

    /**
     * Stores input data for this side.
     *
     * @param mixed $data Subclass-specific input.
     * @return mixed Subclass-specific; MetaData returns relative URLs, Url
     *               returns nothing.
     */
    abstract function addData($data);


    /**
     * Creates a data object for one side.
     *
     * @param string $type `search` or `replace`.
     */
    public function __construct($type)
    {
        $this->type = $type;
    }

    /**
     * Tells whether any error was recorded.
     *
     * @return bool True when $errors is not empty.
     */
    public function hasErrors()
    {
         if (count($this->errors) === 0)
         {
             return false; 
         }
         return true; 
    }


    /**
     * Builds relative URL arrays, same logic as Replacer::getRelativeURLS().
     *
     * For each item the result holds `base`, the URL path of `url`, and one
     * entry per `metadata` file: the directory of that path plus the file's
     * basename.
     *
     * @param array $dataArray Items keyed by an index, each an array with
     *                         `url` (main file URL) and `metadata` (map of
     *                         name => file name).
     * @return array Per index: `base` plus one relative path per metadata
     *               name.
     *
     * @todo MetaData::addData() passes a flat map of name => file name
     *       instead of items with `url` and `metadata` keys, so this cannot
     *       work for its only caller. See MetaData::addData().
     */
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