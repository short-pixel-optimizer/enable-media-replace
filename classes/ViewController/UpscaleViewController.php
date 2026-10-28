<?php

namespace EnableMediaReplace\ViewController;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Same flow as background removal ( preview via API, then replace ), only a different form.
class UpscaleViewController extends RemoveBackgroundViewController
{
	static $instance;

	protected $view_name = 'prepare-upscale';
	protected $nonce_action = 'media_upscale_image';

	public static function getInstance()
	{
		if (is_null(self::$instance))
			self::$instance = new UpscaleViewController();

		return self::$instance;
	}

} // class
