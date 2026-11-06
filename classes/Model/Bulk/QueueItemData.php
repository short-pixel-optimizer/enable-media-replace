<?php
/**
 * Persistent payload of a bulk queue item.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Model\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Per-item input payload attached to a QueueItem for the duration of
 * processing.
 *
 * Port of ShortPixel Image Optimizer's QueueItemData, trimmed to what the
 * EMR bulk uses. This side holds the request state (URLs, parameters, the
 * action to perform, retry counters) while QueueItemResult holds the
 * response state. Magic __get / __set plus a fixed schema stop arbitrary
 * keys from being stored; toObject() strips unset fields so the queue row
 * stays compact.
 *
 * The next-actions trio (addNextAction / hasNextAction / popNextAction) is
 * a FIFO of follow-up actions so one queue slot can chain work.
 *
 * Lifecycle: one instance per QueueItem, recreated by QueueItem::newAction().
 * Fires and listens to no hooks.
 */
class QueueItemData
{
	/** @var array<int|string,string>|null URLs submitted to the API for this item. */
	protected $urls;

	/** @var string|null The action being processed ('remove_background', 'scale_image', 'restore'). */
	protected $action;

	/** @var array<int,string>|null FIFO of actions to run after the current one. */
	protected $next_actions;

	/** @var array<int|string,mixed>|null Data to preserve across the next action(s); see getKeepDataArgs(). */
	protected $next_keepdata;

	/** @var array|null Structured return data echoed back by the API untouched. */
	protected $returndatalist;

	/** @var array|null Parameter list handed to the API (bg_remove / upscale / refresh). */
	protected $paramlist;

	/** @var array<int,mixed>|null Free-form flags; __get() coerces a non-array to []. */
	protected $flags;

	/** @var int|null Compression type sent as `lossy` (always 0, lossless, for the bulk actions). */
	protected $compressionType;

	/** @var int|null Retry counter; ShortQ raises it on every requeue. */
	protected $tries;

	/** @var mixed Block indicator set while the item's result is being applied, so a parallel tick skips it. */
	protected $block;

	/** @var \stdClass|null Counters for the UI (built by addCount()). */
	protected $counts;

	/** @var int|null Queue position hint so requeued items keep their place. */
	protected $queue_list_order;

	/** @var string|null Relative path of the backup written before the swap (restore reads it). */
	protected $backup;

	/** @var int|null Source width in pixels when the action was prepared (upscale bookkeeping). */
	protected $source_width;

	/**
	 * No-op: every field stays unset so toObject() can tell "never assigned"
	 * from "explicitly null".
	 */
	public function __construct()
	{
	}

	/**
	 * Returns a declared field, or null (with a warning log) for an unknown one.
	 * `flags` is coerced to an empty array when it is not an array.
	 *
	 * @param string $name Field name.
	 * @return mixed|null
	 */
	public function __get($name)
	{
		if (property_exists($this, $name)) {
			$value = $this->$name;
			if ('flags' === $name && ! is_array($value)) {
				$value = array();
			}
			return $value;
		}

		Log::addWarn('QueueItemData field requested not found: ' . $name);
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
			Log::addWarn('QueueItemData field not exists - ' . $name);
		}
	}

	/**
	 * Resets a declared field to null so toObject() drops it. No-op for unknown fields.
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
	 * Returns a compact stdClass with every non-null field; what the queue row stores.
	 *
	 * @return object
	 */
	public function toObject()
	{
		$vars = get_object_vars($this);
		$vars = array_filter($vars, function ($value) {
			return ! is_null($value);
		});
		return (object) $vars;
	}

	/**
	 * Whether the item will process $action, now or as a queued next action.
	 *
	 * @param string $action Action name.
	 * @return bool
	 */
	public function hasAction($action)
	{
		$actions = is_array($this->next_actions) ? array_merge(array($this->action), $this->next_actions) : array($this->action);
		return in_array($action, $actions, true);
	}

	/**
	 * Appends a follow-up action to the FIFO. The caller saves the item afterwards.
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	public function addNextAction($action)
	{
		$this->next_actions = is_null($this->next_actions) ? array($action) : array_merge($this->next_actions, array($action));
	}

	/**
	 * Whether any follow-up action is queued.
	 *
	 * @return bool
	 */
	public function hasNextAction()
	{
		return ! is_null($this->next_actions) && count($this->next_actions) > 0;
	}

	/**
	 * Pops the first queued follow-up action (FIFO).
	 *
	 * @return string|null Null when none is queued.
	 */
	public function popNextAction()
	{
		if (! is_null($this->next_actions) && count($this->next_actions) > 0) {
			return array_shift($this->next_actions);
		}
		return null;
	}

	/**
	 * Registers data to preserve when the current action completes and the next starts.
	 *
	 * A numeric key with a field name as value captures that field's current value at
	 * getKeepDataArgs() time; a string key keeps its value verbatim. A scalar is wrapped
	 * in an array; repeated entries are de-duplicated.
	 *
	 * @param mixed $args Field names or name => value pairs.
	 * @return void
	 */
	public function addKeepDataArgs($args)
	{
		if (! is_array($args)) {
			$args = array($args);
		}
		if (is_null($this->next_keepdata)) {
			$this->next_keepdata = $args;
		} else {
			$this->next_keepdata = array_unique(array_merge($this->next_keepdata, $args));
		}
	}

	/**
	 * Materialises the preserved data as name => value, dropping null values and
	 * field names that do not exist on this object.
	 *
	 * @return array<string,mixed>
	 */
	public function getKeepDataArgs()
	{
		$args = array();
		if (! is_array($this->next_keepdata) || 0 === count($this->next_keepdata)) {
			return $args;
		}

		foreach ($this->next_keepdata as $name => $value) {
			if (is_numeric($name)) {
				if (property_exists($this, $value) && ! is_null($this->$value)) {
					$args[ $value ] = $this->$value;
				}
			} elseif (! is_null($value)) {
				$args[ $name ] = $value;
			}
		}
		return $args;
	}

	/**
	 * Merges counters into `$counts`, creating it on first use; existing keys are overwritten.
	 *
	 * @param iterable<string,int|string> $new_count Counter values keyed by name.
	 * @return void
	 */
	public function addCount($new_count)
	{
		if (! is_object($this->counts)) {
			$this->counts = new \stdClass();
		}
		foreach ($new_count as $name => $value) {
			$this->counts->{$name} = $value;
		}
	}
}
