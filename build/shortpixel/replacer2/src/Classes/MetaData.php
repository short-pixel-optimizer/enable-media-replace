<?php 
namespace EnableMediaReplace\Replacer\Classes; 

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class MetaData extends Data
{
    
	
    /*public function addSearchMeta($meta)
    {
         $files = $this->getFilesFromMetadata($meta);
    } */

    public function addData($meta)
	{
		$this->data = $meta; 
        $files = $this->getFilesFromMetadata($meta); 

		$relativeUrls = $this->getRelativeURLS($files);
        
		return $relativeUrls;
    }

    private function getFilesFromMetadata($meta)
    {
          $fileArray = array();
          if (isset($meta['file']))
            $fileArray['file'] = $meta['file'];

          if (isset($meta['sizes']))
          {
            foreach($meta['sizes'] as $name => $data)
            {
              if (isset($data['file']))
              {
                $fileArray[$name] = $data['file'];
              }
            }
          }
        return $fileArray;
    }

    /** FindNearestsize
	* This works on the assumption that when the exact image size name is not available, find the nearest width with the smallest possible difference to impact the site the least.
	*/
	private function findNearestSize($sizeName)
	{

			if (! isset($this->source_metadata['sizes'][$sizeName]) || ! isset($this->target_metadata['width'])) // This can happen with non-image files like PDF.
			{
				 // Check if metadata-less item is a svg file. Just the main file to replace all thumbnails since SVG's don't need thumbnails.
				 if (strpos($this->target_url, '.svg') !== false)
				 {
					$svg_file = wp_basename($this->target_url);
					return $svg_file;  // this is the relpath of the mainfile.
				 }

				return false;
			}
			$old_width = $this->source_metadata['sizes'][$sizeName]['width']; // the width from size not in new image
			$new_width = $this->target_metadata['width']; // default check - the width of the main image

			$diff = abs($old_width - $new_width);
		//  $closest_file = str_replace($this->relPath, '', $this->newMeta['file']);
			$closest_file = wp_basename($this->target_metadata['file']); // mainfile as default

			foreach($this->target_metadata['sizes'] as $sizeName => $data)
			{
					$thisdiff = abs($old_width - $data['width']);

					if ( $thisdiff  < $diff )
					{
							$closest_file = $data['file'];
							if(is_array($closest_file)) { $closest_file = $closest_file[0];} // HelpScout case 709692915
							if(!empty($closest_file)) {
									$diff = $thisdiff;
									$found_metasize = true;
							}
					}
			}

			if(empty($closest_file)) return false;

			return $closest_file;
	}
    
} // class 