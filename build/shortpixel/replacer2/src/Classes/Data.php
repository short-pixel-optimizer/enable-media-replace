<?php
/**
 * Data class of the newer search/replace API.
 *
 * @package EnableMediaReplace\Replacer
 */

namespace EnableMediaReplace\Replacer\Classes;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Everything about the file on one side (search or replace) of a replacement.
 *
 * A side is one file: its main URL, its attachment metadata (the thumbnail
 * sizes) and optional extra URLs (webp, ...) that pair with the same keys on
 * the other side. getUrls() turns that into the relative URL paths to search
 * for or replace with.
 *
 * The URL is parsed once, in setUrl(); the path and directory are kept.
 *
 * Created by Setup::search() and Setup::replace(). Fires and listens to no
 * hooks.
 */
class Data
{

    /**
     * Side this data belongs to, for messages.
     *
     * @var string `search` or `replace`.
     */
    protected $side;

    /**
     * Full URL of the main file, as given to setUrl().
     *
     * @var string|null
     */
    protected $url;

    /**
     * URL path of the main file, e.g. `/wp-content/uploads/2026/10/photo.jpg`.
     *
     * @var string|null
     */
    protected $path;

    /**
     * Directory of $path with trailing slash, e.g. `/wp-content/uploads/2026/10/`.
     * Thumbnails live next to the main file.
     *
     * @var string|null
     */
    protected $directory;

    /**
     * Attachment metadata as delivered by WordPress; empty when none.
     *
     * @var array
     */
    protected $metadata = [];

    /**
     * Extra URLs, keyed by a name shared with the other side.
     *
     * @var array
     */
    protected $extraUrls = [];

    /**
     * Errors recorded on invalid input. Setup refuses to validate while a
     * side has errors.
     *
     * @var string[]
     */
    protected $errors = [];

    /**
     * @param string $side `search` or `replace`.
     */
    public function __construct($side)
    {
        $this->side = $side;
    }

    /**
     * Sets the main file of this side.
     *
     * A value that is not a URL with a path (false from
     * wp_get_attachment_url(), null, `http://host`) is refused and leaves the
     * side as it was.
     *
     * @param string $url Full URL of the main file.
     * @return bool False (logged) when refused.
     */
    public function setUrl($url): bool
    {
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

        if (! is_string($path) || '' === $path) {
            Log::addError('Replacer Data::setUrl (' . $this->side . ') refused a URL without path', [$url]);
            return false;
        }

        $this->url = $url;
        $this->path = $path;
        $this->directory = substr($path, 0, strrpos($path, '/') + 1);
        return true;
    }

    /**
     * Sets the attachment metadata of this side.
     *
     * No metadata is valid (false from wp_get_attachment_metadata(), an empty
     * array for a PDF). Anything else that is not an array is refused and
     * recorded as an error.
     *
     * @param array|false|null $metadata Attachment metadata.
     * @return bool False (logged, error recorded) when refused.
     */
    public function setMetaData($metadata): bool
    {
        if (empty($metadata)) {
            $metadata = [];
        }

        if (! is_array($metadata)) {
            Log::addError('Replacer Data::setMetaData (' . $this->side . ') expects an array, got ' . gettype($metadata));
            $this->errors[] = sprintf(__('Replacer: the %s metadata is not valid attachment metadata.', 'enable-media-replace'), $this->side);
            return false;
        }

        $this->metadata = $metadata;
        return true;
    }

    /**
     * Adds extra URLs, paired by key with the other side.
     *
     * A string key overwrites an earlier entry with that key. `base` is
     * reserved for the main file. All or nothing: when any value is not a
     * non-empty string, nothing is added (an empty replace value would erase
     * every match).
     *
     * @param array $urls Relative URLs or paths keyed by name.
     * @return bool False (logged) when refused.
     */
    public function addUrls($urls): bool
    {
        if (! is_array($urls) || array_key_exists('base', $urls)) {
            Log::addError('Replacer Data::addUrls (' . $this->side . ') expects an array without the reserved key base', [$urls]);
            return false;
        }

        foreach ($urls as $name => $url) {
            if (! is_string($url) || '' === trim($url)) {
                Log::addError('Replacer Data::addUrls (' . $this->side . '): ' . $name . ' is not a non-empty string', $urls);
                return false;
            }
        }

        $this->extraUrls = array_merge($this->extraUrls, $urls);
        return true;
    }

    /**
     * Returns every relative URL path of this side.
     *
     * In this order: the main file (`base`, `file`), the thumbnail sizes,
     * the extra URLs (a string key there overrides an earlier entry).
     *
     * @return array Map of name => relative URL path; empty without URL.
     */
    public function getUrls(): array
    {
        if (false === $this->hasUrl()) {
            return [];
        }

        return array_merge($this->getMainFileUrls(), $this->getSizeUrls(), $this->extraUrls);
    }

    /**
     * Returns the main file under `base`, and under `file` the file the
     * metadata names (the `-scaled` version, if WordPress made one).
     *
     * @return array
     */
    protected function getMainFileUrls(): array
    {
        $urls = ['base' => $this->path];

        if (isset($this->metadata['file']) && is_string($this->metadata['file']) && '' !== $this->metadata['file']) {
            $urls['file'] = $this->getFileUrl($this->metadata['file']);
        }

        return $urls;
    }

    /**
     * Returns the thumbnail sizes of the metadata, keyed by size name.
     *
     * Sizes without a string file name are left out.
     *
     * @return array
     */
    protected function getSizeUrls(): array
    {
        $sizes = (isset($this->metadata['sizes']) && is_array($this->metadata['sizes'])) ? $this->metadata['sizes'] : [];

        $urls = [];
        foreach ($sizes as $name => $size) {
            if (isset($size['file']) && is_string($size['file']) && '' !== $size['file']) {
                $urls[$name] = $this->getFileUrl($size['file']);
            }
        }

        return $urls;
    }

    /**
     * Places a file name from the metadata in the main file's directory.
     *
     * The metadata `file` carries a path like `2026/10/`; only its basename
     * is used.
     *
     * @param string $filename File name from the metadata.
     * @return string Relative URL path.
     */
    protected function getFileUrl($filename): string
    {
        return $this->directory . wp_basename($filename);
    }

    /**
     * @return bool Whether the main file is set.
     */
    public function hasUrl(): bool
    {
        return ! is_null($this->url);
    }

    /**
     * @return string|null Full URL of the main file.
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @return string|null URL path of the main file.
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return array Attachment metadata; empty when none.
     */
    public function getMetaData(): array
    {
        return $this->metadata;
    }

    /**
     * @return string[] Errors recorded on invalid input.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

} // class
