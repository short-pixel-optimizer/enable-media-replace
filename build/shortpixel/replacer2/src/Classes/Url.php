<?php
/**
 * Url data class of the newer search/replace API.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Classes;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * URLs for one side (search or replace) of a replacement.
 *
 * Created lazily by Setup::URL(), one per side. Two kinds of URL, each with
 * its own method:
 * - the main file, set with setMainUrl(): kept as given (getMainUrl()) and
 *   added to $data as `base`, its relative URL path. The search side's main
 *   file also gives the LIKE fragment (getReplaceBase()).
 * - extra pairs, added with addData(): stored in $data as given, under the
 *   caller's keys. The caller keeps the keys of both sides in step.
 *
 * Fires and listens to no hooks.
 */
class Url extends Data
{

    /**
     * Key of the main file in $data.
     *
     * @var string
     */
    const MAIN_KEY = 'base';

    /**
     * Cached result of getReplaceBase(): the path without extension, or
     * false when a fail-safe rejected it. Null until computed.
     *
     * @var string|false|null
     */
    protected $replaceBase;

    /**
     * Sets the main file of this side.
     *
     * Stores the URL and adds its URL path to $data under `base`. Setting it
     * again replaces the previous main file and resets getReplaceBase() and
     * its fail-safe errors. A value that is not a string with a URL path
     * (false from wp_get_attachment_url(), null, `http://host`) is refused
     * and leaves the side as it was, so Setup::validateData() reports the
     * missing main file.
     *
     * @param string $url Full URL of the main file.
     * @return bool False (logged) when the URL has no path.
     */
    public function setMainUrl($url): bool
    {
        $path = $this->getUrlPath($url);
        if (false === $path) {
            Log::addError('Url::setMainUrl (' . $this->type . ') refused a main URL without path', [$url]);
            return false;
        }

        $this->mainUrl = $url;
        $this->replaceBase = null;
        $this->errors = [];
        $this->data[self::MAIN_KEY] = $path;
        return true;
    }

    /**
     * Returns the main file URL as set, also after removeBase().
     *
     * @return string|null Null when no main file is set.
     */
    public function getMainUrl()
    {
        return $this->mainUrl;
    }

    /**
     * Adds extra URLs to this side.
     *
     * Values are stored as given (as Replacer::addURLArray() did), under the
     * caller's keys: a string key overwrites an earlier entry with that key,
     * numeric keys are appended. The `base` key belongs to the main file and
     * is refused; use setMainUrl() for that. A call is all or nothing: when
     * any value is not a non-empty string nothing is added, because an
     * empty search value matches nothing and an empty replace value would
     * erase every match.
     *
     * @param array $urls URLs or paths keyed by a name shared with the other
     *                    side.
     * @return bool False (logged) when $urls is not an array, holds `base`
     *              or holds a value that is not a non-empty string.
     */
    public function addData($urls)
    {
        if (! is_array($urls)) {
            Log::addError('Url::addData expects an array of extra URLs; set the main file with setMainUrl()');
            return false;
        }

        if (array_key_exists(self::MAIN_KEY, $urls)) {
            Log::addError('Url::addData: key ' . self::MAIN_KEY . ' is reserved for the main file; use setMainUrl()');
            return false;
        }

        foreach ($urls as $key => $value) {
            if (! is_string($value) || '' === trim($value)) {
                Log::addError('Url::addData (' . $this->type . '): value of ' . $key . ' is not a non-empty string', $urls);
                return false;
            }
        }

        $this->data = array_merge($this->data, $urls);
        return true;
    }

    /**
     * Tells whether the main file is set.
     *
     * @return bool
     */
    public function hasURL(): bool
    {
        return ! is_null($this->mainUrl);
    }


    /**
     * Returns the URL path of the main file without its extension.
     *
     * Same derivation as in Replacer::replace(); only meaningful for the
     * search side, where it is the LIKE fragment. Computed on first call, so
     * the fail-safe errors are only recorded on the side that asks for it.
     *
     * @return string|false Path without extension, or false when no main file
     *                      is set or a fail-safe rejects it (error recorded).
     */
    public function getReplaceBase()
    {
        if (! is_null($this->replaceBase))
        {
             return $this->replaceBase;
        }

        if (is_null($this->mainUrl)) {
            Log::addError('Url: getReplaceBase called without a main URL');
            return false;
        }

        $base_url = parse_url($this->mainUrl, PHP_URL_PATH);
		$extension = pathinfo($base_url, PATHINFO_EXTENSION);
		if ('' !== $extension) {
			$base_url = substr($base_url, 0, -strlen($extension) - 1);
		}

		/** Fail-safe if base_url is a whole directory, don't go search/replace */
		if (false === $this->fileIsRestricted($base_url) && is_dir($base_url)) {
			Log::addError('Search Replace tried to replace to directory - ' . $base_url);
			$this->errors[] = __('Fail Safe :: Source Location seems to be a directory.', 'enable-media-replace');
			$this->replaceBase = false;
			return false;
		}

		if (strlen(trim($base_url)) == 0) {
			Log::addError('Current Base URL emtpy - ' . $base_url);
			$this->errors[] = __('Fail Safe :: Source Location returned empty string. Not replacing content', 'enable-media-replace');
			$this->replaceBase = false;
            return false;
		}

        $this->replaceBase = $base_url;
        return $base_url;
    }

    /**
     * Drops the main file from $data, for the thumbnails-only feature.
     *
     * Setup::validateData() with `thumbnails_only` leaves these keys out by
     * itself without changing the side, so Replacer2::replace() does not
     * call this. Removes `base` and `file`. getMainUrl() and getReplaceBase() keep
     * working: the main file is still the reference, it is just not
     * replaced itself.
     *
     * @return void
     */
    public function removeBase()
    {
        if (isset($this->data[self::MAIN_KEY]))
        {
            unset($this->data[self::MAIN_KEY]);
        }
        if (isset($this->data['file']))
        {
             unset($this->data['file']);
        }
    }
}
