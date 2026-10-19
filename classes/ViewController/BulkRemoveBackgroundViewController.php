<?php

namespace EnableMediaReplace\ViewController;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use EnableMediaReplace\Controller\BulkBackgroundController as BulkBackgroundController;

/**
 * Bulk Background Removal screen (Media > Bulk Background Removal).
 * The queue and the processing itself live in BulkBackgroundController.
 */
class BulkRemoveBackgroundViewController extends \EnableMediaReplace\ViewController
{
	static $instance;

	public function __construct()
	{
		parent::__construct();
	}

	public static function getInstance()
	{
		if (is_null(self::$instance))
			self::$instance = new BulkRemoveBackgroundViewController();

		return self::$instance;
	}

	public function load()
	{
		if (! current_user_can('upload_files')) {
			$this->viewError(self::ERROR_UPLOAD_PERMISSION);
		}

		$defaults = array(
			'bg_type' => 'transparent',
			'bg_color' => '#ffffff',
			'bg_transparency' => 100,
		);
		$settings = get_option('enable_media_replace', $defaults);
		$settings = array_merge($defaults, is_array($settings) ? $settings : array()); // might miss some

		$controller = BulkBackgroundController::getInstance();

		$this->view->settings = $settings;
		$this->view->has_products = BulkBackgroundController::hasProducts();
		$this->view->counts = BulkBackgroundController::getCounts();
		$this->view->status = $controller->getStatus();
		$this->view->cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

		// Current run (if any), so the screen picks up where it was after a reload.
		wp_add_inline_script('emr_bulk_bg', 'var emrBulkState = ' . wp_json_encode($this->view->status) . ';', 'before');

		$this->loadView('bulk-remove-background');
	}

} // class
