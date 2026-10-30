<?php
/**
 * Smart Slider 3 integration of the replacer module.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Modules;

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


/**
 * Replaces image references in Smart Slider 3 slides.
 *
 * Smart Slider 3 stores slide images in its own table
 * (`{prefix}nextend2_smartslider3_slides`, columns `thumbnail`, `params`,
 * `slide`) as `$upload$/<path inside uploads>` instead of a URL.
 *
 * Detection: the `NEXTEND_SMARTSLIDER_3` constant is defined.
 *
 * Intended lifecycle: singleton via getInstance(), created by
 * Replacer::loadFormats() with the replacer instance; when Smart Slider is
 * active it stores the replacer and the upload directory parts and listens to
 * action `shortpixel/replacer/replace_urls` -> doReplaceQueries().
 *
 * Current behaviour: the module is inert. The constructor returns before
 * registering anything in every case, so none of the methods below run.
 *
 * @todo The constructor has an unconditional `return false;` right after
 *       the constant check, so the hook is never registered. Suggested fix:
 *       remove that line once the integration is meant to be enabled.
 */
class SmartSlider
{

    /**
     * Shared instance.
     *
     * @var SmartSlider|null
     */
    private static $instance;

    /**
     * Replacer used for replaceContent(). Never set while the constructor
     * returns early.
     *
     * @var \EnableMediaReplace\Replacer\Replacer|null
     */
    private $replacer;

    /**
     * Upload base URL with the current year/month subdirectory removed. Not
     * read by any method.
     *
     * @var string|null
     */
    protected $base_url;

    /**
     * Current upload subdirectory (for example `/2024/01`).
     *
     * @var string|null
     */
    protected $subdir;

    /**
     * Upload base directory path; its basename is the uploads directory name
     * looked for by convertToFormat().
     *
     * @var string|null
     */
    protected $basedir;

    /**
     * Returns the shared instance, creating it on first use.
     *
     * @param \EnableMediaReplace\Replacer\Replacer $replacer Replacer instance; only
     *                                                the first one passed is
     *                                                used.
     * @return SmartSlider
     */
    public static function getInstance($replacer)
    {
        if (is_null(self::$instance))
          self::$instance = new SmartSlider($replacer);

        return self::$instance;
    }

    /**
     * Intended to set up the integration when Smart Slider 3 is active.
     *
     * Returns without doing anything when `NEXTEND_SMARTSLIDER_3` is not
     * defined, and returns false right after that check otherwise, so the
     * code that stores the replacer, reads wp_upload_dir() and registers the
     * hook never runs.
     *
     * @hook action shortpixel/replacer/replace_urls doReplaceQueries(),
     *       priority 10, 3 args (unreachable).
     *
     * @param \EnableMediaReplace\Replacer\Replacer $replacer Replacer instance.
     *
     * @todo The unconditional `return false;` makes the rest of the
     *       constructor unreachable. Suggested fix: remove it to enable the
     *       integration.
     */
    public function __construct($replacer)
    {
        // Check if plugin is there. 
        if (false === defined('NEXTEND_SMARTSLIDER_3'))
        {
             return; 
        }

         return false; 

        $this->replacer = $replacer; 

        $uploads_dir = \wp_upload_dir(); 

        $this->base_url = str_replace($uploads_dir['subdir'], '', $uploads_dir['baseurl']); 
        $this->subdir = $uploads_dir['subdir'];
        $this->basedir = $uploads_dir['basedir']; 

        add_action('emr/replacer/replace_urls', [$this, 'doReplaceQueries'], 10, 3);
    }


    /**
     * Replaces image references in the Smart Slider slides table.
     *
     * Intended behaviour (not reachable while the constructor returns
     * early): converts the base URL and every search and replace value to
     * the `$upload$/...` form with convertToFormat(), selects the slides
     * whose `thumbnail`, `params` or `slide` column is LIKE the converted
     * base URL, runs replaceContent() of the replacer on each of the three
     * columns, passes changed `params` and `slide` values through
     * checkFixEncoding(), and updates the changed columns of the row.
     *
     * Uses the `%i` identifier placeholder of `$wpdb->prepare()`, which
     * needs WordPress 6.2 or later. Values that convertToFormat() cannot
     * convert become null.
     *
     * @global \wpdb $wpdb
     *
     * @hook action shortpixel/replacer/replace_urls
     *
     * @param array  $search_urls  Search array of the main run.
     * @param array  $replace_urls Replace array of the main run.
     * @param string $base_url     Base URL of the main run.
     * @return void
     */
    public function doReplaceQueries($search_urls, $replace_urls, $base_url)
    {
        global $wpdb; 
        $table = $wpdb->prefix . 'nextend2_smartslider3_slides'; 

       $base_url = $this->convertToFormat($base_url);
        $search_urls = array_map([$this, 'convertToFormat'], $search_urls); 
        $replace_urls = array_map([$this, 'convertToFormat'], $replace_urls);


        $select_sql = 'SELECT * FROM %i where %i like %s OR %i like %s OR %i LIKE %s'; 

            $prepared_select = $wpdb->prepare($select_sql, [
                $table, 
                'thumbnail', 
                '%' . $base_url . '%', 
                'params', 
                '%' . $base_url . '%',
                                'slide', 
                '%' . $base_url . '%'
            ]);              
                        
            $results = $wpdb->get_results($prepared_select, ARRAY_A);

            foreach($results as $index => $data)
            {

                $row_id = $data['id']; 
                $thumbnail = $this->replacer->replaceContent($data['thumbnail'], $search_urls, $replace_urls); 
                $params = $this->replacer->replaceContent($data['params'], $search_urls, $replace_urls); 
                $slide = $this->replacer->replaceContent($data['slide'], $search_urls, $replace_urls); 

                $update = []; 
                if ($thumbnail != $data['thumbnail'])
                {
                    $update['thumbnail'] = $thumbnail; 
                }
                if ($params !== $data['params'])
                {
                    $update['params'] = $this->checkFixEncoding($params); 
                }
                if ($slide !== $data['slide'])
                {
                    $update['slide'] = $this->checkFixEncoding($slide); 
                }
                
                if (count($update) > 0)
                {
                    $res = $wpdb->update($table, $update, ['id' => $row_id], '%s', '%d' );
                    Log::addInfo('Smartslider Updated records: ' . $res, $update);   
                }
            }
    
    }

    
    /**
     * Re-encodes font values after a replacement.
     *
     * Smart Slider stores `font` values base64-encoded inside its JSON.
     * Decodes $params as an associative array; when that fails returns
     * $params unchanged. Otherwise base64-encodes every string value under
     * a `font` key at any depth (encodeFontValues()) and returns the result
     * of json_encode() with default flags.
     *
     * @param string $params JSON string after replacement.
     * @return string|false JSON string, $params unchanged, or false when
     *                      json_encode() fails.
     */
    protected function checkFixEncoding($params)
    {
        $decoded_params = json_decode($params, true);

        if (! is_array($decoded_params)) {
            return $params;
        }

        $params = $this->encodeFontValues($decoded_params);
        return json_encode($params);
    }

    /**
     * Base64-encodes string values under `font` keys, recursively.
     *
     * @param mixed $value Decoded JSON value.
     * @return mixed The value with every string `font` entry base64-encoded;
     *               non-arrays are returned unchanged.
     */
    protected function encodeFontValues($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if ('font' === $key) {
                $result[$key] = is_string($item) ? base64_encode($item) : $item;
            } else {
                $result[$key] = $this->encodeFontValues($item);
            }
        }

        return $result;
    }

    /**
     * Converts a relative URL path to Smart Slider's `$upload$/` form.
     *
     * Finds the first occurrence of `<uploads directory name>/` (basename of
     * $basedir, usually `uploads`) in the path and returns `$upload$/`
     * followed by everything after it, for example
     * `/wp-content/uploads/2024/01/a.jpg` becomes `$upload$/2024/01/a.jpg`.
     *
     * @param string $relpath Relative URL path.
     * @return string|null Converted path, or null when the path does not
     *                     contain the uploads directory name.
     */
    protected function convertToFormat($relpath) {
        $basedir = $this->basedir; 
    // Get just the directory name of the uploads folder from basedir
    $uploadsDirName = basename($basedir);  // 'uploads'
    
    // Find where uploads/ appears in relpath and get everything after it
    $uploadsPos = strpos($relpath, $uploadsDirName . '/');
    
    if ($uploadsPos !== false) {
        // Get the portion after 'uploads/'
        $filePortion = substr($relpath, $uploadsPos + strlen($uploadsDirName) + 1);
        
        $result = '$upload$/' . $filePortion;
        return $result;
    }
    
    return null; // Path doesn't contain uploads directory
}

    
}
