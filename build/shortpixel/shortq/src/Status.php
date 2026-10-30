<?php
/**
 * Status value object of the ShortQ module.
 *
 * @package EnableMediaReplace\ShortQ
 */
namespace EnableMediaReplace\ShortQ;

/**
 * Counters and flags describing the state of one queue.
 *
 * One Status object exists per queue name. Queue\WPQ keeps it in memory and
 * stores it serialized, together with the Status objects of the plugin's
 * other queues, in the `shortqwp_{slug}` option. Because the object itself
 * is serialized, the class (under the namespace the build tool gave it)
 * must stay loadable for the stored option to unserialize.
 *
 * Status is provider-agnostic: it does no loading or saving itself. All
 * integer properties are counters that WPQ adjusts with setStatusCount()
 * and periodically resyncs from the database; treat them as approximate
 * until the queue has recounted. The boolean flags drive the lifecycle:
 * `preparing` (items are still being added, do not finish the queue when
 * it runs empty), `running` (dequeue() has been called), `finished`
 * (nothing waiting or in process, set by WPQ when a run ends) and
 * `bulk_running` (set by the consumer only, ShortQ never reads it).
 *
 * Consumers read values through WPQ::getStatus() and write them through
 * WPQ::setStatus(); the typical application use is `custom_data`, a free
 * slot for anything the consumer wants persisted with the queue.
 *
 * Fires and listens to no hooks.
 */
class Status
{

  // Protecting against direct writing so at later point we can still add quality checks on setters.

  /**
   * Number of items waiting to be dequeued.
   *
   * @var int
   */
  protected $items = 0; // number of items waiting.

  /**
   * Number of items dequeued in `wait` mode and not yet reported done or
   * failed.
   *
   * @var int
   */
  protected $in_process = 0; // amount of items currently in process ..

  /**
   * True while the consumer is still adding items. A preparing queue is
   * not finished when it runs out of items.
   *
   * @var bool
   */
  protected $preparing = false; // flag to signal queue is being created and items are being uploaded.  Don't run.

  /**
   * True once dequeue() has run and until the queue finishes or is reset.
   *
   * @var bool
   */
  protected $running = false; // flag to signal the queue is currently running

  /**
   * True when the queue ran empty and was not preparing; cleared when
   * items are added again.
   *
   * @var bool
   */
  protected $finished = false;  // flag to signal nothing can move this queue anymore.

  /**
   * Consumer-owned flag marking a bulk run. ShortQ only stores it.
   *
   * @var bool
   */
  protected $bulk_running = false; // external flag to note if a large amount is being more [optional]

  /**
   * Number of items processed successfully.
   *
   * @var int
   */
  protected $done = 0; // number of items processed

  /**
   * Number of items currently in the error (retryable) status.
   *
   * @var int
   */
  protected $errors = 0;

  /**
   * Number of items that failed permanently.
   *
   * @var int
   */
  protected $fatal_errors = 0;

  /**
   * Unix timestamp of the last dequeue() or finish.
   *
   * @var int
   */
  protected $last_run = 0; // internal

  /**
   * Unix timestamp of the last set() through WPQ::setStatus().
   *
   * @var int
   */
  protected $last_update = 0; // internal

  /**
   * Number of times the queue reached the finished state.
   *
   * @var int
   */
  protected $times_ran = 0; // internal

  /**
   * Reserved for an average batch size. Never written.
   *
   * @var int
   */
  protected $average_ask = 0; // internal

  /**
   * Unix timestamp of the last recount from the data provider.
   *
   * @var int
   */
  protected $last_count_time = 0;

  /**
   * Item ID of the last item handed to the data provider by enqueue(),
   * kept so a consumer can resume preparing after a script termination.
   *
   * @var int
   */
  protected $last_item_id = 0;

  /**
   * Free slot for the consuming application. ShortQ never reads it.
   *
   * @var mixed
   */
  protected $custom_data = null; // data for the application, shortq does nothing with it.

  /**
   * Tells whether a property is a counter, i.e. currently holds an integer.
   *
   * Used by WPQ::setStatusCount() to refuse arithmetic on flags. Based on
   * the current value, so `custom_data` counts as a counter once an integer
   * has been stored in it.
   *
   * @param string $name Property name.
   * @return bool
   */
  public function isCounter($name)
  {
     if (  gettype($this->$name) == 'integer')
      return true;
    else
      return false;
  }

  /**
   * Returns a status value.
   *
   * @param string $name Property name.
   * @return mixed The value, or null when the property is unknown or null.
   */
  public function get($name)
  {
    if (isset($this->$name))
      return $this->$name;
    else
      return null;
  }

  /**
   * Sets a status value. Counters are cast to int.
   *
   * @param string $name  Property name.
   * @param mixed  $value New value.
   * @return bool True when the property exists, false otherwise.
   */
  public function set($name, $value)
  {
     if(property_exists($this,$name))
     {
      if ($this->isCounter($name))
        $value = intval($value);

      $this->$name = $value;
      return true;
    }
    return false;
  }


}
