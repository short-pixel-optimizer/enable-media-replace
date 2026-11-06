<?php
/**
 * DataProvider interface of the ShortQ module.
 *
 * @package EnableMediaReplace\ShortQ
 */
namespace EnableMediaReplace\ShortQ\DataProvider;
use EnableMediaReplace\ShortQ\Item as Item;


/**
 * Contract of a storage backend for queue items.
 *
 * A DataProvider persists Items for one plugin slug and queue name, hands
 * them back on request and changes their status. It creates its own
 * storage (install()) and removes it when asked (uninstall()). The only
 * implementation is MysqlDataProvider, which WPQ also calls beyond this
 * interface: removeRecords() is required by resetQueue(),
 * withRemoveDuplicates(), cleanQueue() and uninstall().
 *
 * @todo The defaults of itemCount() and itemSum() refer to `ShortQ::...`
 *       without importing the class, so the name resolves to the
 *       non-existent \EnableMediaReplace\ShortQ\DataProvider\ShortQ. Defaults in
 *       an interface are never evaluated, so nothing breaks, but the
 *       declared defaults are unusable. Suggested fix: import
 *       \EnableMediaReplace\ShortQ\ShortQ.
 */
/* DataProvider handles where the data is stored, and retrieval upon queue request
*
* DataProvider is responsible for creating it's own environment, and cleanup when uninstall is being called.
*
*/
interface DataProvider
{

  /**
   * @param string $pluginSlug Slug of the owning plugin.
   * @param string $queueName  Queue name.
   */
  function __construct($pluginSlug, $queueName);

  //function add($items);

  /**
   * Stores items.
   *
   * @param Item[] $items Items to insert.
   * @return int|false Number of rows inserted, or false on error.
   */
  function enqueue($items);

  /**
   * Selects items and moves them to a new status in one step.
   *
   * @param array $args Selection and target status, see
   *                    MysqlDataProvider::dequeue().
   * @return Item[]
   */
  function dequeue($args); // @return Items removed from queue and set to status. Returns Item Object

  /**
   * Mass update of stored items.
   *
   * @param array $changes    Column => new value.
   * @param array $conditions Column => value (or list of values) to match.
   * @param array $operators  Column => comparison operator.
   * @return int|false Number of rows changed.
   */
  function alterQueue($changes, $conditions, $operators); // @return Item Count / Boolean . Mass alteration of queue. ( changes, what to change, conditions, basically where statement)

  /**
   * Updates one item, identified by its item_id.
   *
   * @param Item  $item       Item to update.
   * @param array $new_status Column => new value (status, tries, value...).
   * @return bool
   */
  function itemUpdate(Item $item, $new_status);

  /**
   * Loads one item by item_id.
   *
   * @param int $item_id Consumer item ID.
   * @return Item|false
   */
  function getItem($item_id);

  /**
   * Loads items by status, update time and/or priority.
   *
   * @param array $args Query arguments, see MysqlDataProvider::queryItems().
   * @return Item[]
   */
  function getItems($args); // get items on basis of status / updated date /etc


  /**
   * Counts stored items.
   *
   * @param int|string $status Status constant, ShortQ::QSTATUS_ALL, or
   *                           'countbystatus' for a per-status array.
   * @return int|array
   */
  // Returns number of items left in Queue.
  function itemCount($status = ShortQ::QSTATUS_WAITING);

  /**
   * Sums the `item_count` of stored items.
   *
   * @param int|string $status As for itemCount().
   * @return int|array
   */
  // Sum of a arbitrary number of items set by user.
  function itemSum($status = ShortQ::QSTATUS_ALL);

  /**
   * Creates the storage.
   *
   * @param bool $nocheck True to (re)run the creation even when the
   *                      storage already exists.
   * @return bool True when the storage exists afterwards.
   */
  function install($nocheck = false);

  /**
   * Removes the storage when it holds no more items.
   *
   * @return bool
   */
  function uninstall();
}
