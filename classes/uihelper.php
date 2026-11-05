<?php
/**
 * Interface helpers for the EMR admin screens.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Notices\NoticeController as Notices;

/* Collection of functions helping the interface being cleaner. */
/**
 * Collection of helpers used by the view controllers and the views.
 *
 * Singleton, reached through \emr()->uiHelper(). Registers no hooks.
 *
 * Covers: the URLs of the EMR screens and redirects, the author restriction
 * check, whether an attachment offers "Remove background", the preview
 * boxes on the replace and remove-background screens (size lookup, scaling,
 * placeholder HTML), the current uploads subdirectory, and the persistent
 * new-feature notice.
 *
 * Holds per-request preview state: the views call setPreviewSizes() and
 * setSourceSizes() first, and getPreviewImage() overwrites the preview
 * width and height with the scaled size of the last image it rendered.
 */
class UIHelper
{
  /**
   * @var string Name of the registered image size used for previews; the
   *             widest size not wider than $preview_max_width. Set by
   *             setPreviewSizes(). Empty until then.
   */
  protected $preview_size = '';
  /**
   * @var int|float Preview width in pixels. Set by setPreviewSizes(), then
   *                overwritten by getPreviewImage() with the scaled width.
   */
  protected $preview_width = 0;
  /**
   * @var int|float Preview height in pixels. Set like $preview_width.
   */
  protected $preview_height = 0;

	/**
	 * @var int Maximum preview width in pixels: WordPress's "large" size, so the
	 *          large preview panes of the single screens get a sharp image.
	 */
	protected $preview_max_width = 1024;
  /**
   * @var int Maximum preview height in pixels.
   */
  protected $preview_max_height = 1024;

  /**
   * @var int Width of the full-size image of the source attachment. Set by
   *          setSourceSizes(); shown in the placeholder's text layer.
   */
  protected $full_width = 0;
  /**
   * @var int Height of the full-size image of the source attachment. Set by
   *          setSourceSizes().
   */
  protected $full_height = 0;

	/**
	 * @var UIHelper|null The singleton instance.
	 */
	private static $instance;

	/**
	 * Persistent notice ID of the "remove background" feature announcement.
	 *
	 * @var string
	 */
	const NOTICE_NEW_FEATURE = 'EMR001';

	/**
	 * A number for display: number_format_i18n() without a trailing ".00",
	 * and a plain space for a locale's non-breaking thousands separator
	 * (SPIO's UiHelper::formatNumber()). A non-numeric value is returned as is.
	 *
	 * @param mixed $number
	 * @param int   $precision Maximum decimals. Default 2.
	 * @return mixed The formatted string, or $number when it is not numeric.
	 */
	public static function formatNumber($number, $precision = 2)
	{
		global $wp_locale;
		if (! is_numeric($number)) {
			return $number;
		}
		$decimalpoint = (isset($wp_locale) && isset($wp_locale->number_format['decimal_point'])) ? $wp_locale->number_format['decimal_point'] : '.';
		$formatted    = number_format_i18n((float) $number, $precision);
		$pos          = strpos($formatted, $decimalpoint);
		if (false !== $pos && '00' === substr($formatted, $pos + 1)) {
			$formatted = substr($formatted, 0, $pos);
		}
		return str_replace('&nbsp;', ' ', $formatted);
	}

	/**
	 * A Unix timestamp as "date @ time" in the site's formats and timezone
	 * (SPIO's UiHelper::formatTS()): the bulk history, the log titles and
	 * the per-item log lines.
	 *
	 * @param int $ts
	 * @return string
	 */
	public static function formatTS($ts)
	{
		$ts = (int) $ts;
		if (function_exists('wp_date')) {
			return wp_date(get_option('date_format'), $ts) . ' @ ' . wp_date(get_option('time_format'), $ts);
		}
		return date_i18n(get_option('date_format'), $ts) . ' @ ' . date_i18n(get_option('time_format'), $ts);
	}

	/**
	 * Returns the singleton, creating it on first call.
	 *
	 * The class is instantiated as `UiHelper`; class names are
	 * case-insensitive, so this resolves to UIHelper.
	 *
	 * @return UIHelper
	 */
	public static function getInstance()
	{
		 if (is_null(self::$instance))
		 {
			  self::$instance = new UiHelper();
		 }

		 return self::$instance;
	}

	// @todo Add nonce URL to this url as well, in popup / prepare-remove-background
  /**
   * Builds the form action URL of an EMR screen.
   *
   * Points at the hidden EMR page under upload.php with the given `action`
   * and `attachment_id`. The URL carries no nonce; the forms send their own
   * `emr_nonce` field. A `SHORTPIXEL_DEBUG` request argument is carried over
   * (numeric values as int, anything else as 3).
   *
   * @param int         $attach_id Attachment ID.
   * @param string|null $action    Route action; null means 'media_replace_upload'.
   *                               The remove-background screen passes
   *                               'do_background_replace'.
   * @return string Admin URL.
   */
  public function getFormUrl($attach_id, $action = null)
  {
		  $action = (! is_null($action)) ? $action : 'media_replace_upload';

      $url = admin_url('upload.php');
      $url = add_query_arg(array(
          'page' => 'enable-media-replace/enable-media-replace.php',
    //      'noheader' => true,
          'action' => $action,
          'attachment_id' => $attach_id,
      ), $url);

      if (isset($_REQUEST['SHORTPIXEL_DEBUG']))
      {
        $spdebug = $_REQUEST['SHORTPIXEL_DEBUG'];
        if (is_numeric($spdebug))
          $spdebug = intval($spdebug);
        else {
          $spdebug = 3;
        }
        $url = add_query_arg('SHORTPIXEL_DEBUG', $spdebug, $url);
      }
      return $url;

  }

  /**
   * Builds the URL the success screen redirects to.
   *
   * The attachment's edit screen (post.php?action=edit) with `emr_replaced=1`.
   * That argument makes EnableMediaReplacePlugin add a `time` query argument
   * to attachment image URLs (browser cache busting) and show the
   * "Replaced Thumbnails Preview" meta box. `SHORTPIXEL_DEBUG` is carried over
   * as in getFormUrl().
   *
   * @param int $attach_id Attachment ID.
   * @return string Redirect URL, after the `emr_returnurl` filter.
   *
   * @hook filter emr_returnurl Filters the success redirect URL.
   */
  public function getSuccesRedirect($attach_id)
  {
    $url = admin_url('post.php');
    $url = add_query_arg(array('action' => 'edit', 'post' => $attach_id, 'emr_replaced' => '1'), $url);

    if (isset($_REQUEST['SHORTPIXEL_DEBUG']))
    {
      $spdebug = $_REQUEST['SHORTPIXEL_DEBUG'];
      if (is_numeric($spdebug))
        $spdebug = intval($spdebug);
      else {
        $spdebug = 3;
      }
      $url = add_query_arg('SHORTPIXEL_DEBUG', $spdebug, $url);
    }

    $url = apply_filters('emr_returnurl', $url);

    return $url;

  }

	/**
	 * Builds the URL of the remove-background screen for an attachment.
	 *
	 * Used by UploadViewController as the success redirect after a
	 * remove-background replacement. The URL carries no nonce.
	 *
	 * @param int $attach_id Attachment ID.
	 * @return string Admin URL with action 'emr_prepare_remove'.
	 */
	public function getBackgroundRemoveRedirect($attach_id)
	{
		    $url = admin_url("upload.php");
		    $url = add_query_arg(array(
		    'page' => 'enable-media-replace/enable-media-replace.php',
		    'action' => 'emr_prepare_remove',
		    'attachment_id' => $attach_id,
		    ), $url);

				return $url;
	}

  /**
   * Builds the URL back to the replace screen after a failure.
   *
   * Includes a fresh `_wpnonce` for the 'media_replace' action.
   *
   * @param int $attach_id Attachment ID.
   * @return string Admin URL, after the `emr_returnurl_failed` filter.
   *
   * @hook filter emr_returnurl_failed Filters the failure return URL.
   */
  public function getFailedRedirect($attach_id)
  {
    $url = admin_url('upload.php');
    $url = add_query_arg(array(
          'page' => 'enable-media-replace/enable-media-replace.php',
          'action' => 'media_replace',
          'attachment_id' => $attach_id,
          '_wpnonce' => wp_create_nonce('media_replace'),
        ), $url
    );

    $url = apply_filters('emr_returnurl_failed', $url);
    return $url;
  }

  // Restrict some functionality on basis of user profile.
  /**
   * Tells whether the current user is "author restricted".
   *
   * A user is restricted when they lack `edit_others_posts` (by default:
   * Authors and Contributors). Restricted users get no "Remove background"
   * (through EnableMediaReplacePlugin::useFeature('background') and the
   * explicit checks in the row action and attachment fields) and no
   * "replace and search" mode on the replace screen.
   *
   * Independent of EMR_CAPABILITY.
   *
   * @return bool True when the user lacks `edit_others_posts`.
   */
  public function is_author_restricted()
  {
    
    $bool = current_user_can( 'edit_others_posts' ); 
    
    if (true === $bool)
    {
       return false; 
    }
    
    
    return true; 
  }

  /**
   * Picks the image size used for previews.
   *
   * Sets $preview_size, $preview_width and $preview_height to the widest
   * registered image size whose width does not exceed $preview_max_width
   * (see findImageSizeByMax()).
   *
   * @return void
   */
  public function setPreviewSizes()
  {

    list($this->preview_size, $this->preview_width, $this->preview_height) = $this->findImageSizeByMax($this->preview_max_width);
  }

  /**
   * Stores the full-size dimensions of the source attachment.
   *
   * Sets $full_width and $full_height from getImageSizes($attach_id, 'full');
   * leaves them untouched when no image data is found. The placeholder shows
   * them as "W x H" when both are above zero.
   *
   * @param int $attach_id Attachment ID.
   * @return void
   */
  public function setSourceSizes($attach_id)
  {
    $data = $this->getImageSizes($attach_id, 'full');  // wp_get_attachment_image_src($attach_id, 'full');

    if (is_array($data))
    {

      $this->full_width = $data[1];
      $this->full_height = $data[2];
    }

  }

  /**
   * Returns URL, width and height of an attachment at a given size.
   *
   * Reads width and height from the attachment metadata's `sizes` entry and
   * the URL from image_downsize(), because wp_get_attachment_image_src() can
   * return theme-altered dimensions (see the link in the body). When the
   * size is not in the metadata (always the case for 'full'), it falls back
   * to wp_get_attachment_image_src(). For an SVG whose width comes back as
   * 5 or less, the size is read from the SVG itself (fixSVGSize()).
   *
   * @param int    $attach_id Attachment ID.
   * @param string $size      Registered size name, or 'full'.
   * @return array|false array( url, width, height ), or false when WordPress
   *                     has no image data for the attachment.
   *
   * @todo The SVG branch tests the MIME from wp_get_image_mime(), which
   *       detects types through exif_imagetype()/getimagesize(); those do not
   *       recognise SVG, so the branch likely never runs. Use
   *       get_post_mime_type() or wp_check_filetype() instead, and confirm
   *       with a test.
   */
  protected function getImageSizes($attach_id, $size = 'thumbnail')
  {
		// We are not using this function, because depending on the theme, it can mess with the dimensions - https://wordpress.stackexchange.com/questions/167525/why-is-wp-get-attachment-image-src-returning-wrong-dimensions
		$meta = wp_get_attachment_metadata($attach_id);

		$data = false;

		if (isset($meta['sizes']))
		{
				foreach($meta['sizes'] as $sizeName => $metaData)
				{
					  if ($sizeName == $size)
						{
							 $width = isset($metaData['width']) ? $metaData['width'] : 0;
							 $height = isset($metaData['height']) ? $metaData['height'] : 0;
							 $imgData = image_downsize($attach_id, $size); // return whole array w/ possible wrong dimensions.
							 $data = array($imgData[0], $width, $height);
						}
				}
		}

		if ($data === false)
		{
			$data = wp_get_attachment_image_src($attach_id, $size);
			$width = isset($data[1]) ? $data[1] : 0;
		}

    $file = get_attached_file($attach_id, true);
		if (! file_exists($file))
			return $data;

		$mime_type = wp_get_image_mime($file);

    if (strpos($mime_type, 'svg') !== false && $width <= 5)
    {
        $file = get_attached_file($attach_id);
        $data = $this->fixSVGSize($data, $file);
    }


    return $data;
  }

  /**
   * Reads width and height of an SVG from its root element.
   *
   * Uses the numeric part of the `width` / `height` attributes, otherwise the
   * third / fourth value of a four-value `viewBox`, otherwise null. Returns
   * $data unchanged when SimpleXML is unavailable or the file does not parse.
   *
   * @param array|false $data Image data as returned by getImageSizes().
   * @param string      $file Absolute path of the SVG file.
   * @return array|false $data with indexes 1 and 2 replaced.
   */
  protected function fixSVGSize($data, $file)
  {
    if (! function_exists('simplexml_load_file'))
      return $data;

    $xml = simplexml_load_file($file);

    if ($xml)
    { // stolen from SVG Upload plugin
      $attr = $xml->attributes();
      $viewbox = explode(' ', $attr->viewBox);
      $data[1] = isset($attr->width) && preg_match('/\d+/', $attr->width, $value) ? (int) $value[0] : (count($viewbox) == 4 ? (int) $viewbox[2] : null);
      $data[2] = isset($attr->height) && preg_match('/\d+/', $attr->height, $value) ? (int) $value[0] : (count($viewbox) == 4 ? (int) $viewbox[3] : null);
    }

    return $data;
  }

	// Returns Preview Image HTML Output.
	/**
	 * Returns the HTML of the preview box for an image attachment.
	 *
	 * Looks up the image at $preview_size (call setPreviewSizes() first).
	 * When there is no image data, or the file neither exists nor is virtual
	 * (offloaded), it returns getPreviewFile() for a PDF and an empty
	 * placeholder otherwise (icon 'dashicons-no' for a real attachment, no
	 * icon for $attach_id < 0).
	 *
	 * Sizing: when the image exceeds 1024 x 1024 ($preview_max_width /
	 * $preview_max_height), both dimensions are divided by the larger of
	 * width / 1024 and height / 1024 and floored, which keeps the aspect ratio.
	 * The scaled size is written back to $preview_width / $preview_height
	 * (side effect) and passed to the placeholder; the <img> width and height
	 * attributes are that size raised to at least 150 each.
	 *
	 * @param int    $attach_id Attachment ID; values <= 0 skip the lookup.
	 * @param object $file      FileModel of the attachment's file
	 *                          (exists(), is_virtual(), getFileSize() are used).
	 * @param array  $args      Placeholder overrides, see getPlaceHolder().
	 * @return string HTML.
	 */
	public function getPreviewImage($attach_id,$file, $args = array())
	{
			$data = false;

			if ($attach_id > 0)
			{
				$data = $this->getImageSizes($attach_id, $this->preview_size); //wp_get_attachment_image_src($attach_id, $this->preview_size);
			}

			$mime_type = get_post_mime_type($attach_id);

			if (! is_array($data) || (! $file->exists() && ! $file->is_virtual()) )
			{
				// if attachid higher than zero ( exists ) but not the image, fail, that's an error state.
				$icon = ($attach_id < 0) ? '' : 'dashicons-no';
				$is_document = false;

				$defaults = array(
						'width' => $this->preview_width,
						'height' => $this->preview_height,
						'is_image' => false,
						'is_document' => $is_document,
						'is_upload' => false,
						'icon' => $icon,
						'mime_type' => null,
				);
			 $args = wp_parse_args($args, $defaults);

				// failed, it might be this server doens't support PDF thumbnails. Fallback to File preview.
				if ($mime_type == 'application/pdf')
				{
						return $this->getPreviewFile($attach_id, $file);
				}

				return $this->getPlaceHolder($args);
			}

			$url = $data[0];
			$width = $data[1];
			$height = $data[2];

		 // width
		 $width_ratio = $height_ratio = 0;
		 if ($width > $this->preview_max_width)
		 {
				 $width_ratio = $width / $this->preview_max_width;
		 }
		 if ($height > $this->preview_max_height) // height
		 {
				 $height_ratio = $height / $this->preview_max_height;
		 }

		 $ratio = ($width_ratio > $height_ratio) ? $width_ratio : $height_ratio;

		 if ($ratio > 0)
		 {

			 $width  = floor($width / $ratio);
			 $height = floor($height / $ratio);
		 }

			// SVG's without any helpers return around 0 for width / height. Fix preview.

			 // preview width, if source if found, should be set to source.
			 $this->preview_width = $width;
			 $this->preview_height = $height;

      $image_width = ($width >= 150) ? $width: 150; 
      $image_height = ($height >= 150) ? $height : 150;

			// data-zoom-src: the full-size file, for the hover magnifier (js/emr-magnifier.js).
			$full = wp_get_attachment_image_url($attach_id, 'full');
			$image = sprintf("<img src='%s' width='%d' height='%d' class='image' data-zoom-src='%s' style='max-width:100%%; max-height: 100%%;' />",
        esc_url($url), $image_width, $image_height, esc_url($full ? $full : $url) );

			$defaults = array(
				'width' => $width,
				'height' => $height,
				'image' => $image,
				'mime_type' => $mime_type,
				'is_upload' => false,
				'file_size' => $file->getFileSize(),
			);

		 $args = wp_parse_args($args, $defaults);

			$output = $this->getPlaceHolder($args);
			return $output;
	}

  /**
   * Returns an error placeholder (non-image box with the 'dashicons-no' icon)
   * at the current preview size.
   *
   * @param int $attach_id Unused; views pass -1.
   * @return string HTML.
   */
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

  /**
   * Returns a document placeholder (300 x 300, non-image) for a file.
   *
   * The text layer shows the file name when $attach_id > 0, and the box
   * carries the file's MIME type (falling back to the post MIME type when
   * the server cannot detect it) and its size. The replace screen also calls
   * it with -1 and `is_upload => true` to render the upload drop zone.
   *
   * @param int    $attach_id Attachment ID, or a value <= 0 for no file name.
   * @param object $file      FileModel (getFileName(), getMime(), getFileSize()).
   * @param array  $args      Placeholder overrides, see getPlaceHolder().
   * @return string HTML.
   */
  public function getPreviewFile($attach_id, $file, $args = array())
  {
    if ($attach_id > 0)
    {
      //$filepath = get_attached_file($attach_id);
      $filename = $file->getFileName();
    }
    else {
      $filename = false;
    }

    $mime_type = $file->getMime();
		if (false === $mime_type) // If server is not supporting this, go w/ the post mime type one.
		{
			$mime_type = get_post_mime_type($attach_id);
		}

    $defaults = array(
      'width' => 300,
      'height' => 300,
      'is_image' => false,
      'is_document' => true,
			'is_upload' => false,
      'layer' => $filename,
      'mime_type' => $mime_type,
      'file_size' => $file->getFileSize(),
    );
		$args = wp_parse_args($args, $defaults);


    $output = $this->getPlaceHolder($args);
    return $output;
  }

  /**
   * Finds the widest registered image size not wider than $maxwidth.
   *
   * Sizes with width 0 never match. Widths and heights of the core sizes
   * come from options, so they can be numeric strings.
   *
   * @param int $maxwidth Maximum width in pixels.
   * @return array array( size_name, width, height ); array( '', 0, 0 ) when
   *               nothing matches.
   */
  public function findImageSizeByMax($maxwidth)
  {
      $image_sizes = $this->wp_get_image_sizes();

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

  /**
   * Renders a preview box.
   *
   * The box is a div.image_placeholder sized to at least 150 x 150 pixels.
   * Classes: 'is_image' or 'not_image', plus 'is_document' and
   * 'upload-file-action' when those flags are set. With `is_upload` strictly
   * true it contains the "New" title, the `userfile` file input and the
   * drag-and-drop label; otherwise a "Current" title (or "Preview" with
   * `remove_bg_ui` or `upscale_ui`) and the given image HTML. Then the icon,
   * the text layer (only when setSourceSizes() found full dimensions above
   * zero), the remove-background or upscale overlay when requested, and the
   * formatted file size.
   *
   * @param array $args {
   *     @type int|float   $width        Box width; default 150, minimum 150.
   *     @type int|float   $height       Box height; default 150, minimum 150.
   *     @type string      $image        HTML of the image; default ''.
   *     @type string      $icon         Dashicons class; default 'dashicons-media-document'.
   *     @type string      $layer        Text layer; default "full_width x full_height".
   *     @type bool        $is_image     Default true.
   *     @type bool        $is_document  Default false.
   *     @type bool        $is_upload    Right-side upload interface; default false.
   *     @type string|bool $mime_type    Written to data-filetype; default false (omitted).
   *     @type int|bool    $file_size    Bytes, shown through size_format(); default false (empty).
   *     @type bool        $remove_bg_ui Adds the "Removing background…" overlay; default false.
   *     @type bool        $upscale_ui   Adds the "Upscaling image…" overlay instead, with the same
   *                                     "Preview" title; wins over `remove_bg_ui`; default false.
   * }
   * @return string HTML.
   *
   * @todo `mime_type`, `layer` (a file name in getPreviewFile()) and `icon`
   *       are printed without escaping. Wrap them in esc_attr() / esc_html().
   */
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
				'is_upload' => false, // indicating right-side upload interface for image.
        'mime_type' => false,
        'file_size' => false,
				'remove_bg_ui' => false, // In process icons et al when removing background, for preview pane.
				'upscale_ui' => false, // Same as remove_bg_ui, but for upscaling.
				'hint' => '', // Text shown in an empty pane ("Click Preview to see the result"); js/remove_bg.js removes it.

    );

    $args = wp_parse_args($args, $defaults);

    $w = $args['width'];
    $h = $args['height'];

    if ($w < 150)   // minimum
      $w = 150;
    if ($h < 150)
      $h = 150;

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

		if (true == $args['is_upload'])
		{
			 $placeholder_class .= ' upload-file-action';
		}

    $filetype = '';
    if ($args['mime_type'])
    {
      $filetype = 'data-filetype="' . $args['mime_type'] . '"';
    }

    $filesize = ($args['file_size']) ? $args['file_size'] : '';

		$is_backgroundremove_ui = (isset($args['remove_bg_ui']) && $args['remove_bg_ui'] == true);
		$background_remove_ui = ($is_backgroundremove_ui) ? $this->getBgremoveUI() : '';

		if (isset($args['upscale_ui']) && $args['upscale_ui'] == true)
		{
			$is_backgroundremove_ui = true; // Preview pane, same as background removal.
			$background_remove_ui = $this->getUpscaleUI();
		}

    $output = "<div class='image_placeholder $placeholder_class' $filetype style='width:" . $w . "px; height:". $h ."px'> ";
		if (true === $args['is_upload'])
		{
			$output .= "<span class='upload-title'>" . __('New', 'enable-media-replace') . "</span>";
			$output .= '<input type="file" name="userfile" id="upload-file" />';
				$output .= '<div class="drag-and-drop-title">
                              <span>' . __('Click here to upload, or drop a file in this area', 'enable-media-replace') . '</span>
                   </div>';
		}
		else
		{
			$title = (true === $is_backgroundremove_ui ) ? __('Preview', 'enable-media-replace') : __('Current', 'enable-media-replace');
			$output .= "<span class='upload-title'>" . $title . "</span>";
    	$output .= $args['image'];
		}
    $output .= "<div class='dashicons $icon'>&nbsp;</div>";

    if ($this->full_width > 0 && $this->full_height > 0)
    {
      $output .= "<span class='textlayer'>" . $args['layer'] . "</span>";
    }
		if ('' !== $args['hint'])
		{
			$output .= "<span class='emr-preview-hint'>" . esc_html($args['hint']) . "</span>";
		}
		$output .= $background_remove_ui;
    $output .= "<div class='image_size'>" . $this->convertFileSize($filesize). "</div>";
    $output .= "</div>";

    return $output;
  }

	/**
	 * Tells whether "Remove background" is offered for an attachment.
	 *
	 * All three must hold: the attachment is an image
	 * (wp_attachment_is_image()), \emr()->useFeature('background') is true
	 * (the `emr/feature/background` filter, and the user is not author
	 * restricted), and the post MIME type contains 'jpg', 'png' or 'jpeg'
	 * (substring match, so image/jpeg and image/png qualify).
	 *
	 * @param \WP_Post|int $post Attachment post or ID.
	 * @return bool
	 */
	public function isBackgroundRemovable($post)
	{
		  if (false === wp_attachment_is_image($post))
				return false;

			if (false === emr()->useFeature('background'))
				return false;

			$extensions = array('jpg', 'png','jpeg');

			$mime = get_post_mime_type($post);
			foreach($extensions as $extension)
			{
				  if (strpos($mime, $extension) !== false )
						return true;
			}

			return false;

	}

	// Max source width per upscale factor, synced with ShortPixel Image Optimizer.
	/**
	 * Returns the upscale factors the API offers and the widest source each one accepts.
	 *
	 * Keys are the factors (2, 3, 4), values the maximum source width in
	 * pixels for that factor; the same table as ShortPixel Image Optimizer.
	 * The upscale screen disables a factor when the image is at least that
	 * wide, and isUpscalable() uses the largest value as the overall limit.
	 *
	 * @return array<int,int> factor => maximum source width.
	 */
	public function getUpscaleFactors()
	{
		return array(
			2 => 1200,
			3 => 1200,
			4 => 1024,
		);
	}

	/**
	 * Tells whether "Upscale image" is offered for an attachment.
	 *
	 * All of these must hold: the attachment is an image
	 * (wp_attachment_is_image()), \emr()->useFeature('upscale') is true (the
	 * `emr/feature/upscale` filter, and the user is not author restricted),
	 * the metadata width, when known, is not above the largest value of
	 * getUpscaleFactors() (1200 px; the height is not considered, and an
	 * attachment without metadata passes), and the post MIME type contains
	 * 'jpg', 'png' or 'jpeg' (substring match).
	 *
	 * @todo Unlike isBackgroundRemovable(), only a \WP_Post is accepted:
	 *       `$post->ID` is read directly, so an attachment ID raises
	 *       "Attempt to read property "ID" on int" and the width check runs
	 *       against the global post instead. Suggested fix: resolve the
	 *       argument with get_post() first.
	 * @todo The width limit is exclusive (`>`), while views/prepare-upscale.php
	 *       disables a factor when the width is at least its limit (`<=`), so
	 *       an image exactly 1200 px wide gets the entry points but a screen
	 *       with every factor disabled. Suggested fix: use the same comparison
	 *       in both places.
	 *
	 * @param \WP_Post $post Attachment post.
	 * @return bool
	 */
	public function isUpscalable($post)
	{
		  if (false === wp_attachment_is_image($post))
				return false;

			if (false === emr()->useFeature('upscale'))
				return false;

			$meta = wp_get_attachment_metadata($post->ID);
			if (is_array($meta) && isset($meta['width']) && intval($meta['width']) > max($this->getUpscaleFactors()))
				return false;

			$extensions = array('jpg', 'png','jpeg');

			$mime = get_post_mime_type($post);
			foreach($extensions as $extension)
			{
				  if (strpos($mime, $extension) !== false )
						return true;
			}

			return false;
	}

	/**
	 * Tells whether the upscale operation applies to an attachment at all,
	 * whatever its size: a JPEG or PNG image, with the `upscale` feature on
	 * (which also excludes author-restricted users). isUpscalable() adds the
	 * width limit; where both differ, the entry points show a greyed-out
	 * Upscale button (the image is too large).
	 *
	 * @param \WP_Post|int $post Attachment post or ID.
	 * @return bool
	 */
	public function supportsUpscale($post)
	{
		$post = get_post($post);
		if (! $post || false === wp_attachment_is_image($post) || false === emr()->useFeature('upscale')) {
			return false;
		}
		$mime = (string) get_post_mime_type($post);
		foreach (array('jpg', 'png', 'jpeg') as $extension) {
			if (false !== strpos($mime, $extension)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The largest upscale factor an attachment's width allows, 0 when none.
	 *
	 * A factor fits when the width is below its limit in getUpscaleFactors(),
	 * the same comparison as the factor choice of views/prepare-upscale.php.
	 * An attachment without a known width gets the largest factor.
	 *
	 * @param \WP_Post|int $post Attachment post or ID.
	 * @return int 0, 2, 3 or 4.
	 */
	public function getMaxUpscaleFactor($post)
	{
		$post  = get_post($post);
		$meta  = $post ? wp_get_attachment_metadata($post->ID) : false;
		$width = (is_array($meta) && isset($meta['width'])) ? (int) $meta['width'] : 0;
		$max   = 0;
		foreach ($this->getUpscaleFactors() as $factor => $limit) {
			if (0 === $width || $width < $limit) {
				$max = max($max, (int) $factor);
			}
		}
		return $max;
	}

	/**
	 * The arguments of getOperationButton() for one operation on an
	 * attachment: the same title, text, icon and nonced link wherever EMR
	 * shows the operation (the single screens, the media modal fields and the
	 * Edit Media "Replace Media" box). The callers decide whether to show it
	 * (isBackgroundRemovable(), supportsUpscale(), the author restriction).
	 *
	 * Upscale links only while getMaxUpscaleFactor() is above 0; otherwise its
	 * `url` is null (a greyed-out tile) and the text says the image is already
	 * large enough.
	 *
	 * @param string        $operation replace, background or upscale.
	 * @param \WP_Post|int  $post      Attachment post or ID.
	 * @return array Arguments for getOperationButton().
	 */
	public function getOperationButtonArgs($operation, $post)
	{
		$post = get_post($post);
		$id   = $post ? (int) $post->ID : 0;
		$base = add_query_arg(array(
			'page'          => 'enable-media-replace/enable-media-replace.php',
			'attachment_id' => $id,
		), admin_url('upload.php'));

		switch ($operation) {
			case 'background':
				return array(
					'id'    => 'background',
					'url'   => wp_nonce_url(add_query_arg('action', 'emr_prepare_remove', $base), 'emr_prepare_remove'),
					'icon'  => 'icon-background-removal.svg',
					'title' => __('Remove background', 'enable-media-replace'),
					'text'  => __('Make this image transparent or a solid color, with AI', 'enable-media-replace'),
				);
			case 'upscale':
				$max = $this->getMaxUpscaleFactor($post);
				return array(
					'id'      => 'upscale',
					'url'     => ($max > 0) ? wp_nonce_url(add_query_arg('action', 'emr_prepare_upscale', $base), 'emr_prepare_upscale') : null,
					'icon'    => 'icon-image-upscale.svg',
					'title'   => __('Upscale image', 'enable-media-replace'),
					/* translators: %d: the largest upscale factor for this image. */
					'text'    => ($max > 0) ? sprintf(__('Enlarge this image up to %d× with AI', 'enable-media-replace'), $max) : __('This image is already large enough', 'enable-media-replace'),
					'new'     => true,
					'tooltip' => __('This image is too large to be upscaled.', 'enable-media-replace'),
				);
			default:
				return array(
					'id'    => 'replace',
					'url'   => wp_nonce_url(add_query_arg('action', 'media_replace', $base), 'media_replace'),
					'icon'  => 'icon-media-replace.svg',
					'title' => __('Replace media', 'enable-media-replace'),
					'text'  => __('Upload another file in place of this one', 'enable-media-replace'),
				);
		}
	}

	/**
	 * HTML of one operation button: the SVG tile of the single screens
	 * (views/operation-buttons.php) and the media modal fields
	 * (EnableMediaReplacePlugin::attachment_editor()). Styles:
	 * scss/_operation-buttons.scss.
	 *
	 * @param array $args {
	 *     @type string      $id       replace, background or upscale (class suffix).
	 *     @type string|null $url      Link target; null renders a greyed-out, inactive tile.
	 *     @type string      $icon     File name under img/icons/.
	 *     @type string      $title    Bold first line.
	 *     @type string      $text     Second line.
	 *     @type bool        $new      Adds SPIO's NEW! badge (img/icons/new.svg).
	 *     @type string      $tooltip  Hover text of an inactive tile.
	 * }
	 * @return string Escaped HTML.
	 */
	public function getOperationButton($args)
	{
		$args = wp_parse_args($args, array('id' => '', 'url' => null, 'icon' => '', 'title' => '', 'text' => '', 'new' => false, 'tooltip' => ''));

		$inner = '<img class="emr-operation-icon" src="' . esc_url(plugins_url('img/icons/' . $args['icon'], EMR_ROOT_FILE)) . '" width="48" height="48" alt="">'
			. '<span class="emr-operation-label">'
			. '<span class="emr-operation-title">' . esc_html($args['title']) . '</span>'
			. '<span class="emr-operation-text">' . esc_html($args['text']) . '</span>'
			. '</span>';
		if ($args['new']) {
			$inner .= '<img class="emr-operation-new" src="' . esc_url(plugins_url('img/icons/new.svg', EMR_ROOT_FILE)) . '" width="58" height="27" alt="' . esc_attr__('New', 'enable-media-replace') . '">';
		}

		$class = 'emr-operation emr-operation-' . sanitize_html_class($args['id']);
		if (is_null($args['url'])) {
			$title = ('' !== $args['tooltip']) ? ' title="' . esc_attr($args['tooltip']) . '"' : '';
			return '<span class="' . esc_attr($class . ' is-disabled') . '" aria-disabled="true"' . $title . '>' . $inner . '</span>';
		}
		return '<a class="' . esc_attr($class) . '" href="' . esc_url($args['url']) . '">' . $inner . '</a>';
	}

	/**
	 * The "Buy Unlimited AI credits" button of ShortPixel Image Optimizer's bulk
	 * summary, reused everywhere EMR suggests Unlimited AI: the same label, the
	 * ShortPixel icon (img/icons/shortpixel.svg) and the `button button-primary
	 * unlimited` classes (styles: scss/_shortpixel-account.scss). Links to
	 * ApiKeyManager::UPGRADE_AI_URL in a new tab.
	 *
	 * @return string Escaped HTML.
	 */
	public function getUpgradeAiButton()
	{
		return '<a class="button button-primary unlimited emr-ai-upgrade" href="' . esc_url(ApiKeyManager::UPGRADE_AI_URL) . '" target="_blank" rel="noopener">'
			. '<img class="icon" src="' . esc_url(plugins_url('img/icons/shortpixel.svg', EMR_ROOT_FILE)) . '" width="30" height="30" alt="">'
			. '<span>' . esc_html__('Buy Unlimited AI credits', 'enable-media-replace') . '</span>'
			. '</a>';
	}

	/**
	 * Returns the "Removing background…" overlay with its CSS spinner.
	 *
	 * @return string HTML.
	 */
	private function getBgremoveUI()
	{
		$output = '<div class="overlay" id="overlay">
										<div class="lds-spinner"><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div></div>';

		$output .=	'<h3>'  .  esc_html__('Removing background…', 'enable-media-replace') . '</h3>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Returns the "Upscaling image…" overlay with its CSS spinner.
	 *
	 * @return string HTML.
	 */
	private function getUpscaleUI()
	{
		$output = '<div class="overlay" id="overlay">
										<div class="lds-spinner"><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div></div>';

		$output .=	'<h3>'  .  esc_html__('Upscaling image…', 'enable-media-replace') . '</h3>';
		$output .= '</div>';

		return $output;
	}

  /**
   * Formats a byte count for display (size_format()).
   *
   * @param int|string $filesize Bytes, or '' for none.
   * @return string|false Formatted size, or false for a non-numeric input.
   */
  private function convertFileSize($filesize)
  {
     return size_format($filesize);
  }

    /**
  * Get size information for all currently-registered image sizes.
  * Directly stolen from - https://codex.wordpress.org/Function_Reference/get_intermediate_image_sizes
  *
  * Adapted from the example on that Codex page. Core sizes (thumbnail,
  * medium, medium_large, large) read their dimensions from the
  * `{size}_size_w` / `{size}_size_h` options; other sizes come from the
  * global registry. Sizes found in neither are skipped.
  *
  * @global array $_wp_additional_image_sizes
  * @uses   get_intermediate_image_sizes()
  * @return array $sizes Data for all currently-registered image sizes.
  */
  private function wp_get_image_sizes() {
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

  /** For Location Dir replacement. Get the Subdir that is in use now.
   *
   * Calls wp_upload_dir(), which creates the current month directory when
   * year/month folders are enabled.
   *
   * @return string|false The current uploads subdirectory without leading
   *                      slash (for example "2024/05", or "" when year/month
   *                      folders are off), or false when no subdir is set.
   */
  public function getRelPathNow()
  {
      $uploadDir = wp_upload_dir();
      if (isset($uploadDir['subdir']))
        return ltrim($uploadDir['subdir'], '/');
      else
        return false;
  }

	/**
	 * Queues the persistent "New Beta Feature" remove-background notice.
	 *
	 * Called by EnableMediaReplacePlugin::route() on every EMR screen load.
	 * Adds a dismissible normal notice and makes it persistent under the ID
	 * NOTICE_NEW_FEATURE ('EMR001') with a suppress period of two years, so
	 * after dismissal it stays hidden for two years; while the ID already
	 * exists the new copy is dropped. The notice store is the
	 * `EnableMediaReplace-notices` option, which deactivation clears.
	 *
	 * @return void
	 *
	 * @todo The notice announces remove-background as a new beta feature on
	 *       every EMR screen until dismissed; the feature is no longer new.
	 *       Replace it with a notice announcing Bulk Enhance (Media > Bulk
	 *       Enhance: bulk background removal for WooCommerce products and bulk
	 *       upscale), the feature that is new. Use a new notice ID, so sites
	 *       that dismissed EMR001 see it, and link it to the bulk page.
	 */
	public function featureNotice()
	{
			$message = sprintf(__('%s New beta feature! %s %s Enable Media Replace can now remove the background of an image and upscale it with AI. Try it in the Media Library: hover over an image and click Remove background or Upscale image, or use the buttons on the image editing screen. %s', 'enable-media-replace' ), '<h3>', '</h3>',
				'<p>', '</p>');

		  $notice = Notices::addNormal($message, true);
			Notices::makePersistent($notice, self::NOTICE_NEW_FEATURE, 2 * YEAR_IN_SECONDS);
	}

} // class
