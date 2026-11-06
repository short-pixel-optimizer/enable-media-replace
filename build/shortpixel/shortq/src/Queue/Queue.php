<?php
/**
 * Queue interface of the ShortQ module.
 *
 * @package EnableMediaReplace\ShortQ
 */
namespace EnableMediaReplace\ShortQ\Queue;
use EnableMediaReplace\ShortQ\Item as Item;



/* Needed features
 - Database / Table agnostic Queue system (as in not dependent on specific ID's).
 - Check if Queue is in Use / Lock system
 - Multiple ways to say / restore should be possible
 - Multiplugin ready
*/

/**
 * Contract of a queue: the object consumers enqueue into and dequeue from.
 *
 * A Queue owns the in-memory item buffer, the status counters and the
 * processing rules (modes, retries, timeouts) and delegates storage to a
 * DataProvider. The only implementation is WPQ. The status constants the
 * methods refer to live in \EnableMediaReplace\ShortQ\ShortQ.
 *
 * The interface describes the minimum; WPQ adds setOption(), getOption(),
 * withRemoveDuplicates(), removeItems(), updateItemValue(), setStatus(),
 * setStatusCount(), cleanQueue() and install(), which consumers also use.
 *
 * @todo itemSum() defaults to `SHORTQ::QSTATUS_ALL` without importing the
 *       ShortQ class, so the name resolves to the non-existent
 *       \EnableMediaReplace\ShortQ\Queue\SHORTQ. It never fails because a default
 *       in an interface declaration is not evaluated, but the default is
 *       unusable. Suggested fix: import ShortQ or write the
 *       fully-qualified name.
 */
/* Queue is the main iterator for the queue */
interface Queue
{

  // needs to sync with DataProvider!
/*  const QSTATUS_WAITING = 0; //Waiting for process
  const QSTATUS_PULLED = 1;
  const QSTATUS_INPROCESS = 2; // Doing process now
  const QSTATUS_DONE = 3; // Is Done.

  const QSTATUS_DELETE = -1; // this is a virtual status. If set to this, will be deleted.
  const QSTATUS_ERROR = -2;
  const QSTATUS_FATAL = -3; */
  //private $name; // queue name

  /**
   * @param string                                   $pluginSlug   Slug of the owning plugin.
   * @param string                                   $queue_name   Queue name.
   * @param \EnableMediaReplace\ShortQ\DataProvider\DataProvider $dataProvider Storage backend.
   */
  public function __construct($pluginSlug, $queue_name, $dataProvider);

  /**
   * Sets several options at once (batch sizes, mode, timeouts).
   *
   * @param array<string, mixed> $options Option name => value.
   * @return void
   */
  public function setOptions($options); // set options via array, such as how much to pull / type of queue

  /**
   * Buffers items in memory until enqueue() flushes them to storage.
   *
   * @param array $items List of arrays with keys `id`, `value` and
   *                     optionally `order` and `item_count`.
   * @return void
   */
  /* @param Mixed $items Items, or array of items. */
  public function addItems($items);

  /**
   * Flushes the buffered items to storage.
   *
   * @return int Number of items waiting in the queue afterwards.
   */
  public function enqueue();

  /**
   * Buffers items with an explicit list order. Chainable:
   * `$q->withOrder($items, 5)->enqueue()`.
   *
   * @param array $items Items as accepted by addItems().
   * @param int   $order List order to give every item.
   * @return Queue
   */
  public function withOrder($items, $order); // chained method for adding items with an order. Returns Queue Object

  /**
   * Takes the next items out of the waiting state.
   *
   * @param array $args Overrides, see WPQ::dequeue().
   * @return Item[] Possibly empty list of items.
   */
  public function dequeue($args = array());

  /** Functions for async processing. Process should return item_id, put them on done or fail. */

  /**
   * Marks an item as processed.
   *
   * @param Item $item Item returned by dequeue() or getItem().
   * @return void
   */
  public function itemDone(Item $item);

  /**
   * Marks an item as failed, retryable or fatal.
   *
   * @param Item $item  Item returned by dequeue() or getItem().
   * @param bool $fatal True to stop retrying the item.
   * @return void
   */
  public function itemFailed(Item $item, $fatal = false);

  /**
   * Loads one item by the consumer's item ID.
   *
   * @param int $item_id Consumer item ID.
   * @return Item|false
   */
  public function getItem($item_id);

  /**
   * Returns the status object with counters and flags.
   *
   * @return \EnableMediaReplace\ShortQ\Status|false
   */
  // return status object, for app to check what is going on.
  public function getStatus();



  /**
   * Tells whether items are waiting, from the cached counter.
   *
   * @return bool
   */
  public function hasItems();

  /**
   * Returns the cached number of waiting items.
   *
   * @return int
   */
  public function itemCount();

  /**
   * Sums the `item_count` of the stored items.
   *
   * @param int|string $status Status constant, or 'countbystatus'.
   * @return int|array
   */
  public function itemSum($status = SHORTQ::QSTATUS_ALL);

  /**
   * Deletes every item of this queue and resets its status.
   *
   * @return void
   */
  // reset, start fresh
  public function resetQueue();

  //public function setDataProvider($DataProvider); // DataProvider Object

  /**
   * Removes this queue's items and status; drops the storage when empty.
   *
   * @return void
   */
  public function uninstall();

} // class
