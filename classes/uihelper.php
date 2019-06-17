<?php
namespace EnableMediaReplace;

/* Collection of functions helping the interface being cleaner.  */
class UIHelper
{
  protected $preview_size = '';
  protected $preview_width = 0;
  protected $preview_height = 0;

  protected $full_width = 0;
  protected $full_height = 0;

  public function __construct()
  {

  }

  public function setPreviewSizes()
  {
    list($this->preview_size, $this->preview_width, $this->preview_height) = $this->findImageSizeByMax(400);
  }

  public function setSourceSizes($attach_id)
  {
    $data = wp_get_attachment_image_src($attach_id, 'full');
    if (is_array($data))
    {
      $this->full_width = $data[1];
      $this->full_height = $data[2];
    }
  }

  // Returns Preview Image HTML Output.
  public function getPreviewImage($attach_id)
  {
      $data = false;

      if ($attach_id > 0)
      {
        $data = wp_get_attachment_image_src($attach_id, $this->preview_size);
        $file = get_attached_file($attach_id);
      }

      if (! is_array($data) || ! file_exists($file) )
      {
        // if attachid higher than zero ( exists ) but not the fail, that's an error state.
        $icon = ($attach_id < 0) ? '' : 'dashicons-no';
        $args = array(
            'width' => $this->preview_width,
            'height' => $this->preview_height,
            'is_image' => false,
            'icon' => $icon,
        );
        return $this->getPlaceHolder($args);
      }


      $url = $data[0];
      $width = $data[1];
      $height = $data[2];
      // preview width, if source if found, should be set to source.
      $this->preview_width = $width;
      $this->preview_height = $height;

      $type = get_post_mime_type($attach_id);
      $image = "<img src='$url' data-filetype='$type' width='$width' height='$height' class='image' />";

      $args = array(
        'width' => $width,
        'height' => $height,
        'image' => $image,
      );
      $output = $this->getPlaceHolder($args);
      return $output;
  }

  public function getPreviewError($attach_id)
  {
    $args = array(
      'width' => $this->preview_width,
      'height' => $this->preview_height,
      'icon' => 'dashicons-no',
      'is_image' => false,
    );
    $output = $this->getPlaceHolder($args);
    return $output;
  }

  public function getPreviewFile($attach_id)
  {
    $args = array(
      'width' => 150,
      'height' => 150,
      'is_image' => false,
      'is_document' => true,
    );
    $output = $this->getPlaceHolder($args);
    return $output;
  }

  public function findImageSizeByMax($maxwidth)
  {
      $image_sizes = $this->get_image_sizes();

      $match_width = 0;
      $match_height = 0;
      $match = '';

      foreach($image_sizes as $sizeName => $sizeItem)
      {

          $width = $sizeItem['width'];
          if ($width > $match_width && $width <= $maxwidth)
          {
            $match = $sizeName;
            $match_width = $width;
            $match_height = $sizeItem['height'];
          }
      }
      return array($match, $match_width, $match_height);
  }

  public function getPlaceHolder($args)
  {
    $defaults = array(
        'width' => 150,
        'height' => 150,
        'image' => '',
        'icon' => 'dashicons-media-document',
        'layer' =>  $this->full_width . ' x ' . $this->full_height,
        'is_image' => true,
        'is_document' => false,
    );

    $args = wp_parse_args($args, $defaults);
    $w = $args['width'];
    $h = $args['height'];
    $icon = $args['icon'];

    if ($args['is_image'])
    {
      $placeholder_class = 'is_image';
    }
    else {
      $placeholder_class = 'not_image';
    }

    if ($args['is_document'])
    {
      $placeholder_class .= ' is_document';
    }

    $output = "<div class='image_placeholder $placeholder_class' style='width:" . $w . "px; min-height:". $h ."px'> ";
    $output .= $args['image'];
    $output .= "<div class='dashicons $icon'>&nbsp;</div>";
    $output .= "<span class='textlayer'>" . $args['layer'] . "</span>";
    $output .= "</div>";

    return $output;
  }

    /**
  * Get size information for all currently-registered image sizes.
  * Directly stolen from - https://codex.wordpress.org/Function_Reference/get_intermediate_image_sizes
  * @global $_wp_additional_image_sizes
  * @uses   get_intermediate_image_sizes()
  * @return array $sizes Data for all currently-registered image sizes.
  */
  private function get_image_sizes() {
   global $_wp_additional_image_sizes;

   $sizes = array();

   foreach ( get_intermediate_image_sizes() as $_size ) {
     if ( in_array( $_size, array('thumbnail', 'medium', 'medium_large', 'large') ) ) {
       $sizes[ $_size ]['width']  = get_option( "{$_size}_size_w" );
       $sizes[ $_size ]['height'] = get_option( "{$_size}_size_h" );
     } elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
       $sizes[ $_size ] = array(
         'width'  => $_wp_additional_image_sizes[ $_size ]['width'],
         'height' => $_wp_additional_image_sizes[ $_size ]['height'],
       );
     }
   }

   return $sizes;
  }

} // class
