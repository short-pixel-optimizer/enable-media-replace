<?php
/**
 * The upscale screen and its POST handler.
 *
 * @package EnableMediaReplace\ViewController
 */

namespace EnableMediaReplace\ViewController;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Same flow as background removal ( preview via API, then replace ), only a different form.
/**
 * Upscale screen (`action=emr_prepare_upscale`) and the replacement with the upscaled
 * image (`action=do_upscale_replace`).
 *
 * Inherits load() and loadPost() from RemoveBackGroundViewController and changes only the
 * template, the nonce action and the operation: the preview runs over the same AJAX
 * endpoint (Ajax::remove_background() -> Api::request() with an `upscale` factor), and the
 * apply step runs the request again with the checked factor and applies the result the
 * same way. load() still reads the background settings into
 * `$view->settings`; views/prepare-upscale.php does not use them.
 *
 * Lifecycle: its own singleton (the `$instance` property is redeclared so it does not share
 * the parent's), created by getInstance() from EnableMediaReplacePlugin::route() when the
 * `upscale` feature is on. Registers no hooks.
 */
class UpscaleViewController extends RemoveBackgroundViewController
{
	/**
	 * The singleton instance, separate from RemoveBackGroundViewController::$instance.
	 *
	 * @var UpscaleViewController|null
	 */
	static $instance;

	/**
	 * Template under views/ that load() renders.
	 *
	 * @var string
	 */
	protected $view_name = 'prepare-upscale';

	/**
	 * Nonce action loadPost() verifies `emr_nonce` against.
	 *
	 * @var string
	 */
	protected $nonce_action = 'media_upscale_image';

	/**
	 * The apply step re-sends the checked `upscale` factor.
	 *
	 * @var string
	 */
	protected $operation = 'upscale';

	/**
	 * Returns the singleton.
	 *
	 * @return UpscaleViewController
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance))
			self::$instance = new UpscaleViewController();

		return self::$instance;
	}

} // class
