<?php
/**
 * MetaData data class of the newer search/replace API.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Classes;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Attachment metadata for one side (search or replace) of a replacement.
 *
 * Created lazily by Setup::MetaData(), one per side. Keeps the raw metadata
 * (getMetaData(), used by Setup to find the nearest size) and the map of
 * name => file name in $data. The files only become relative URLs together
 * with the main URL of the same side, see getFileUrls(); Setup does that
 * in validateData(). Fires and listens to no hooks.
 */
class MetaData extends Data
{
    /**
     * Raw attachment metadata as delivered by WordPress; an empty array when
     * the file has none.
     *
     * @var array
     */
    protected $metadata = [];

    /**
     * Stores attachment metadata for this side.
     *
     * Replaces any metadata set before. "No metadata" is a valid input:
     * wp_get_attachment_metadata() returns false and
     * wp_generate_attachment_metadata() an empty array for files without
     * sizes (PDF, SVG, ...), and both are stored as an empty array. Any other
     * non-array input (a string, an object, a WP_Error) is refused and
     * recorded as an error, so Setup::validateData() fails.
     *
     * @param mixed $meta Attachment metadata (`file`, `width`, `sizes`), or
     *                    false / null / an empty array for none.
     * @return bool False (logged, error recorded) when $meta is not an array
     *              and not empty.
     */
    public function addData($meta)
	{
        if (false === $meta || null === $meta || '' === $meta) {
            $meta = [];
        }

        if (! is_array($meta)) {
            $type = is_object($meta) ? get_class($meta) : gettype($meta);
            Log::addError('MetaData::addData (' . $this->type . ') expects an attachment metadata array, got ' . $type);
            $this->errors[] = sprintf(__('Replacer: the %s metadata is not valid attachment metadata.', 'enable-media-replace'), $this->type);
            return false;
        }

        $this->metadata = $meta;
        $this->data = $this->getFilesFromMetadata($meta);
        return true;
    }

    /**
     * Returns the relative URL paths of the metadata files.
     *
     * @param string|null $mainUrl Full URL of the main file of this side
     *                             (Url::getMainUrl()). The files are placed
     *                             in its directory.
     * @return array Map of name => relative URL path, empty when there is no
     *               metadata or $mainUrl has no path.
     */
    public function getFileUrls($mainUrl): array
    {
        return $this->getRelativeURLS($mainUrl, $this->data);
    }

    /**
     * Extracts the file names from attachment metadata.
     *
     * Same logic as Replacer::getFilesFromMetadata(), limited to string file
     * names.
     *
     * @param array $meta Attachment metadata.
     * @return array Map of `file` => metadata file and size name => size file,
     *               for every entry that has a non-empty string `file`.
     */
    private function getFilesFromMetadata(array $meta)
    {
          $fileArray = array();
          if (isset($meta['file']) && is_string($meta['file']) && '' !== $meta['file'])
            $fileArray['file'] = $meta['file'];

          if (isset($meta['sizes']) && is_array($meta['sizes']))
          {
            foreach($meta['sizes'] as $name => $data)
            {
              if (is_array($data) && isset($data['file']) && is_string($data['file']) && '' !== $data['file'])
              {
                $fileArray[$name] = $data['file'];
              }
            }
          }
        return $fileArray;
    }

    /**
     * Returns the raw metadata as stored by addData().
     *
     * @return array Empty array when none was set.
     */
    public function getMetaData(): array
    {
       return $this->metadata;
    }

} // class
