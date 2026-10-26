<?php
namespace EnableMediaReplace\Replacer\Modules;

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


class SmartSlider
{

    private static $instance; 

    private $replacer; 

    protected $base_url;
    protected $subdir;
    protected $basedir;

    public static function getInstance($replacer)
    {
        if (is_null(self::$instance))
          self::$instance = new SmartSlider($replacer);

        return self::$instance;
    }

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

    
    protected function checkFixEncoding($params)
    {
        $decoded_params = json_decode($params, true);

        if (! is_array($decoded_params)) {
            return $params;
        }

        $params = $this->encodeFontValues($decoded_params);
        return json_encode($params);
    }

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
