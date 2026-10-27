<?php
/**
 * Url data class of the newer search/replace API.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Classes;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * URLs for one side (search or replace) of a replacement.
 *
 * Created lazily by Setup::URL(), one per side. Collects URLs in $data; the
 * first one is the main file. Fires and listens to no hooks.
 */
class Url extends Data
{

    /**
     * Appends a URL.
     *
     * @param string $url Full URL.
     * @return void
     */
    public function addData($url)
    {
         $this->data[] = $url;
    }


    /**
     * Returns the URL path of the first URL without its extension.
     *
     * Same derivation as in Replacer::replace(). The first URL must have
     * been added.
     *
     * @return string Path without extension, used as LIKE fragment.
     *
     * @todo The extension is removed with str_replace(), which also removes
     *       an identical text earlier in the path. Suggested fix: strip only
     *       the trailing extension.
     */
    public function getBaseURL()
    {
        $source_url = $this->data[0]; 
        $base_url = parse_url($source_url, PHP_URL_PATH);
		$base_url = str_replace('.' . pathinfo($base_url, PATHINFO_EXTENSION), '', $base_url);

        return $base_url;
    }
} 