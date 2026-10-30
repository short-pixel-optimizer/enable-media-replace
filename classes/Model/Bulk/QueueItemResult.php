<?php
/**
 * Result payload of a bulk queue item.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Model\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use JsonSerializable;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Per-item result produced by one processing round.
 *
 * Port of ShortPixel Image Optimizer's QueueItemResult, trimmed to the
 * fields the EMR bulk produces. A fixed schema behind magic __get / __set
 * keeps unknown keys out (they are logged and dropped); forReturn() and the
 * JsonSerializable contract strip fields that were never set, so the
 * browser gets a compact object.
 *
 * Written by BulkApi (API status), ActionController (what happened to the
 * file) and QueueController (filename, messages). Fires and listens to no
 * hooks.
 */
class QueueItemResult implements JsonSerializable
{
	/** @var int Attachment ID this result relates to. */
	protected $item_id;

	/** @var bool True once the item finished processing, with success or a terminal failure. */
	protected $is_done = false;

	/** @var bool True when the round ended in an error. */
	protected $is_error = false;

	/** @var int|null Status code, see BulkApi::STATUS_*. */
	protected $apiStatus;

	/** @var string|null Human-readable message; ResponseController decides the final wording. */
	protected $message;

	/** @var int|null File status, see QueueItem::FILE_STATUS_*. */
	protected $fileStatus;

	/** @var string|null File name of the attachment, for the bulk screen. */
	protected $filename;

	/** @var int|string|null Error identifier for the browser, see QueueController::ERROR_*. */
	protected $error;

	/** @var bool|null Success flag, newer alternative to !$is_error. */
	protected $success;

	/** @var string|null URL of the original image, for the before/after view. */
	protected $original;

	/** @var string|null URL of the result image, for the before/after view. */
	protected $optimized;

	/** @var string|null Knowledge-base link for the error message. */
	protected $kblink;

	/** @var string|null The action that produced this result. */
	protected $action;

	/** @var array|null Free-form structured payload (return data list). */
	protected $data;

	/** @var string|null Relative path of the backup written for this item. */
	protected $backup;

	/**
	 * Only assigns the identity field, so forReturn() can tell "not set" from null.
	 *
	 * @param int $item_id Attachment ID.
	 */
	public function __construct($item_id)
	{
		$this->item_id = $item_id;
	}

	/**
	 * Returns a declared field, or null (with a warning log) for an unknown one.
	 *
	 * @param string $name Field name.
	 * @return mixed|null
	 */
	public function __get($name)
	{
		if (property_exists($this, $name)) {
			return $this->$name;
		}
		Log::addWarn('QueueItemResult field requested not found: ' . $name);
		return null;
	}

	/**
	 * Assigns a declared field; logs a warning and does nothing for an unknown one.
	 *
	 * @param string $name  Field name.
	 * @param mixed  $value Value to assign.
	 * @return void
	 */
	public function __set($name, $value)
	{
		if (property_exists($this, $name)) {
			$this->$name = $value;
		} else {
			Log::addWarn('QueueItemResult field not exists - ' . $name);
		}
	}

	/**
	 * Resets a declared field to null so forReturn() drops it. No-op for unknown fields.
	 *
	 * @param string $name Field name.
	 * @return void
	 */
	public function remove($name)
	{
		if (property_exists($this, $name)) {
			$this->$name = null;
		}
	}

	/**
	 * JsonSerializable hook; same shape as forReturn().
	 *
	 * @return object
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		return $this->forReturn();
	}

	/**
	 * The compact response object: every field that is not null and not an empty array.
	 *
	 * @return object
	 */
	public function forReturn()
	{
		$vars = get_object_vars($this);
		$vars = array_filter($vars, function ($value) {
			if (is_null($value)) {
				return false;
			}
			if (is_array($value) && 0 === count($value)) {
				return false;
			}
			return true;
		});
		return (object) $vars;
	}
}
