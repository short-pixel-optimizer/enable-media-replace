<?php
/**
 * Setup class of the replacer module.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Classes;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * The two sides of one replacement, and the check that they fit together.
 *
 * Usage:
 *
 *     $setup->search()->setUrl($source_url);
 *     $setup->search()->setMetaData($source_metadata);
 *     $setup->replace()->setUrl($target_url);
 *     $setup->replace()->setMetaData($target_metadata);
 *     $setup->validateData(['thumbnails_only' => false]);
 *
 * validateData() pairs the URLs of both sides into the search and replace
 * arrays (getValidatedData()). Replacer2::replace() always runs it itself
 * right before replacing; a caller can run it earlier to show errors before
 * doing any file work.
 *
 * Lifecycle: one instance per Replacer2 (Replacer2::Setup()). Fires the
 * `shortpixel/file/basedir_check` filter (fileIsRestricted()).
 *
 * Bundling: written with the ShortPixel namespace, text domain and hook
 * prefix; the build tool rewrites them per plugin.
 */
class Setup
{

    /**
     * The file being replaced.
     *
     * @var Data
     */
    protected $searchData;

    /**
     * The file that replaces it.
     *
     * @var Data
     */
    protected $replaceData;

    /**
     * Errors of the last validateData() run.
     *
     * @var string[]
     */
    protected $errors = [];

    /**
     * Result of the last successful validateData(): `search`, `replace`
     * (paired by key and position) and `base_url` (the LIKE fragment).
     *
     * @var array
     */
    protected $validatedData = [];

    /**
     * Creates the two sides, both empty.
     */
    public function __construct()
    {
        $this->searchData = new Data('search');
        $this->replaceData = new Data('replace');
    }

    /**
     * @return Data The file being replaced.
     */
    public function search(): Data
    {
        return $this->searchData;
    }

    /**
     * @return Data The file that replaces it.
     */
    public function replace(): Data
    {
        return $this->replaceData;
    }

    /**
     * Pairs the URLs of both sides into getValidatedData().
     *
     * Steps:
     * 1. checkInput(): both sides have a URL and no input errors.
     * 2. getSearchBase(): the LIKE fragment passes the fail-safes.
     * 3. With `thumbnails_only`, the main file (`base`) is left out, and so is
     *    the metadata file (`file`) when it is the main file. A metadata file
     *    that differs from it (a `-scaled` image) stays: a replace that drops
     *    the `-scaled` file then rewrites its references like those of a size.
     * 4. matchReplaceUrls(): a replacement for every search URL.
     * 5. removeUnchanged(): pairs that are already equal are dropped.
     * 6. isBalanced(): both sides have exactly the same keys.
     *
     * Every run starts from scratch; the sides themselves are not changed.
     *
     * @param array $args {
     *     @type bool $thumbnails_only Leave the main file out (step 3). Default
     *                                 false.
     * }
     * @return bool False when a step fails; see getValidationErrors().
     */
    public function validateData($args = []): bool
    {
        $this->errors = [];
        $this->validatedData = [];

        if (false === $this->checkInput()) {
            return false;
        }

        $base_url = $this->getSearchBase();
        if (false === $base_url) {
            return false;
        }

        $search_urls = $this->search()->getUrls();
        $replace_urls = $this->replace()->getUrls();

        if (is_array($args) && ! empty($args['thumbnails_only'])) {
            unset($search_urls['base'], $replace_urls['base']);

            // Only remove file with the same file replace if they are the same.  This prevents not catching replacing -scaled to non-scaled if that happens (big to small file)
            if (! isset($search_urls['file']) || $search_urls['file'] === $this->search()->getPath()) {
                   unset($search_urls['file'], $replace_urls['file']);
            }
        }

        $replace_urls = $this->matchReplaceUrls($search_urls, $replace_urls);
        $this->removeUnchanged($search_urls, $replace_urls);

        if (false === $this->isBalanced($search_urls, $replace_urls)) {
            return false;
        }

        $this->validatedData = [
            'search' => $search_urls,
            'replace' => $replace_urls,
            'base_url' => $base_url,
        ];

        return true;
    }

    /**
     * @return array|false `search`, `replace` and `base_url` of the last
     *                     validateData() run, or false when it failed or
     *                     never ran.
     */
    public function getValidatedData()
    {
        if (count($this->errors) > 0 || count($this->validatedData) === 0) {
             return false;
        }

        return $this->validatedData;
    }

    /**
     * @return string[] Errors of the last validateData() run.
     */
    public function getValidationErrors()
    {
         return $this->errors;
    }

    /**
     * Checks that both sides have a URL and recorded no input errors.
     *
     * @return bool
     */
    protected function checkInput(): bool
    {
        foreach (['search' => $this->search(), 'replace' => $this->replace()] as $name => $data) {
            if (false === $data->hasUrl()) {
                $this->errors[] = sprintf(__('Replacer: the %s side has no main file URL.', 'enable-media-replace'), $name);
            }
            $this->errors = array_merge($this->errors, $data->getErrors());
        }

        if (count($this->errors) > 0) {
            Log::addError('Replacer Setup: input incomplete or invalid', $this->errors);
            return false;
        }
        return true;
    }

    /**
     * Returns the LIKE fragment: the search file's path without extension.
     *
     * Without extension, the query also finds the thumbnails
     * (`photo-150x150.jpg` for `photo.jpg`). Fails when the fragment is a
     * directory on this server or empty: either would match far too much.
     *
     * @return string|false
     */
    public function getSearchBase()
    {
        $path = $this->search()->getPath();
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base_url = ('' === $extension) ? $path : substr($path, 0, -strlen($extension) - 1);

        if (false === $this->fileIsRestricted($base_url) && is_dir($base_url)) {
            Log::addError('Search Replace tried to replace to directory - ' . $base_url);
            $this->errors[] = __('Fail Safe :: Source Location seems to be a directory.', 'enable-media-replace');
            return false;
        }

        if ('' === trim($base_url)) {
            Log::addError('Current Base URL empty - ' . $base_url);
            $this->errors[] = __('Fail Safe :: Source Location returned empty string. Not replacing content', 'enable-media-replace');
            return false;
        }

        return $base_url;
    }

    /**
     * Finds a replacement for every search URL, in the order of the search.
     *
     * Same key on the replace side: that one. Otherwise the nearest target
     * size (findNearestSize(), which answers the target's main file when
     * there are no widths to compare). Without either, the key stays
     * unmatched and isBalanced() fails. Replace URLs without a search counterpart (a larger
     * target has more sizes) are dropped: nothing can use them yet.
     *
     * @param array $search_urls  Search URLs by key.
     * @param array $replace_urls Replace URLs by key.
     * @return array Replace URLs keyed and ordered like $search_urls.
     *
     * @todo The nearest size is placed in the search URL's directory, which
     *       is wrong when the target file lives in a different directory.
     *       Suggested fix: use the directory of the replace side's path.
     */
    protected function matchReplaceUrls(array $search_urls, array $replace_urls): array
    {
        $matched = [];
        foreach ($search_urls as $name => $search_url) {
            if (isset($replace_urls[$name])) {
                $matched[$name] = $replace_urls[$name];
                continue;
            }

            $nearest = $this->findNearestSize($name);
            if (false === $nearest) {
                Log::addDebug('No replacement found for size ' . $name);
                continue;
            }

            Log::addDebug('Nearest size of source ' . $name . ' for target is ' . $nearest);
            $matched[$name] = substr($search_url, 0, strrpos($search_url, '/') + 1) . $nearest;
        }
        return $matched;
    }

    /**
     * Drops the pairs whose search and replace URL are equal (a file
     * replaced by one with the same name and sizes).
     *
     * @param array $search_urls  Search URLs, changed in place.
     * @param array $replace_urls Replace URLs, changed in place.
     * @return void
     */
    protected function removeUnchanged(array &$search_urls, array &$replace_urls)
    {
        foreach ($search_urls as $name => $search_url) {
            if (isset($replace_urls[$name]) && $replace_urls[$name] === $search_url) {
                unset($search_urls[$name], $replace_urls[$name]);
            }
        }
    }

    /**
     * Checks that both sides have the same keys in the same order.
     *
     * str_replace() pairs search and replace by position: a missing
     * replacement would shift every pair after it.
     *
     * @param array $search_urls  Search URLs.
     * @param array $replace_urls Replace URLs.
     * @return bool
     */
    protected function isBalanced(array $search_urls, array $replace_urls): bool
    {
        if (array_keys($search_urls) === array_keys($replace_urls)) {
            return true;
        }

        Log::addError('Unbalanced Replace Arrays, aborting', [$search_urls, $replace_urls]);
        $this->errors[] = __('There was an issue with updating your image URLS: Search and replace have different amount of values. Aborting updating thumbnails', 'enable-media-replace');
        return false;
    }

	/**
	 * Finds the target file closest in width to a source size the target lacks.
	 *
	 * Starts from the target's main file and takes any target size with a
	 * smaller width difference. Without widths to compare (the source entry
	 * has none, or the target is an SVG or a non-image file such as a PDF or
	 * a text file), the target's main file replaces the entry, so no
	 * reference is left on a file the replace deleted.
	 *
	 * @param string $sizeName Size name present on the search side.
	 * @return string|false File name of the closest target file; false when
	 *                      the target has a width but neither a main file
	 *                      nor a size with a file.
	 */
	protected function findNearestSize($sizeName)
	{
		$source_metadata = $this->search()->getMetaData();
		$target_metadata = $this->replace()->getMetaData();

		if (! isset($source_metadata['sizes'][$sizeName]['width'], $target_metadata['width'])) {
             // When replacing and there is no candidate for the source to replace to, replace it to the main result, otherwise there will be a broken reference, so better to default to this. 
			$target_path = (string) $this->replace()->getPath();
			return wp_basename($target_path);
		}

		$old_width = (int) $source_metadata['sizes'][$sizeName]['width'];
		$diff = abs($old_width - (int) $target_metadata['width']);
		$closest_file = isset($target_metadata['file']) ? wp_basename($target_metadata['file']) : '';

		$target_sizes = (isset($target_metadata['sizes']) && is_array($target_metadata['sizes'])) ? $target_metadata['sizes'] : [];
		foreach ($target_sizes as $size) {
			$file = isset($size['file']) && is_array($size['file']) ? reset($size['file']) : (isset($size['file']) ? $size['file'] : null); // some plugins store a size file as an array
			if (! isset($size['width']) || ! is_string($file) || '' === $file) {
				continue;
			}
			$this_diff = abs($old_width - (int) $size['width']);
			if ($this_diff < $diff) {
				$closest_file = $file;
				$diff = $this_diff;
			}
		}


		return ('' === $closest_file) ? false : $closest_file;
	}

	/**
	 * Tells whether a path is outside open_basedir, so is_dir() on it would
	 * warn instead of answer.
	 *
	 * @hook filter shortpixel/file/basedir_check Receives the result; allows
	 *       overriding it for server setups (symlinks) that are flagged
	 *       falsely.
	 *
	 * @param string $path Path to check.
	 * @return bool
	 */
	protected function fileIsRestricted(string $path)
	{
		$basedir = ini_get('open_basedir');

		if (false === $basedir || strlen($basedir) == 0) {
			return false;
		}

		$restricted = true;
		foreach (preg_split('/:|;/i', $basedir) as $basepath) {
			if (strpos($path, $basepath) !== false) {
				$restricted = false;
				break;
			}
		}

		return apply_filters('emr/file/basedir_check', $restricted);
	}

} // class
