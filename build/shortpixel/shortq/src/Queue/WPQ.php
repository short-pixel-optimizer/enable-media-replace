<?php
/**
 * WordPress queue implementation of the ShortQ module.
 *
 * @package EnableMediaReplace\ShortQ
 */
namespace EnableMediaReplace\ShortQ\Queue;
use EnableMediaReplace\ShortQ\Item as Item;
use EnableMediaReplace\ShortQ\Status as Status;
use \EnableMediaReplace\ShortQ\ShortQ as ShortQ;

/**
 * Queue that keeps its status in a WordPress option and its items in a
 * DataProvider.
 *
 * Created by ShortQ::getQueue(), one object per call. The constructor loads
 * the queue's Status from the `shortqwp_{slug}` option (shared by all
 * queues of the plugin, keyed by queue name) and creates it, together with
 * the storage table, when it is missing. Items are buffered in memory by
 * addItems() / withOrder() until enqueue() writes them to the provider in
 * chunks of `enqueue_limit`.
 *
 * Processing modes (`mode` option):
 * - `direct` (default): dequeue() marks items DONE immediately. Suitable
 *   when the caller finishes the work synchronously and only reports
 *   failures with itemFailed().
 * - `wait`: dequeue() marks items INPROCESS and the caller must report
 *   each one with itemDone() or itemFailed(). Items left INPROCESS longer
 *   than `process_timeout` milliseconds are put back to WAITING by
 *   inProcessTimeout() (called at the start of every dequeue()) with
 *   `tries` incremented, and fail fatally once `tries` exceeds
 *   `retry_limit`. This is the mode for work that goes through a remote
 *   API and completes in a later request.
 *
 * Errors: itemFailed() without `$fatal` sets ERROR. When the queue runs out
 * of waiting items, checkQueue() moves ERROR items back to WAITING while
 * their `tries` are below `retry_limit`, otherwise to FATAL, so retries
 * happen after the first pass over the queue. A queue finishes (`finished`
 * = true, `running` = false) when nothing is waiting or in process and
 * the `preparing` flag is off; consumers that add items in batches set
 * `preparing` (addItems() does so) and clear it when they are done.
 *
 * Counters: the Status counters are adjusted in memory on every operation
 * and written with each saveStatus(). They are resynced from the provider
 * (resetInternalCounts()) when a counter would go negative, every
 * `timeout_recount` seconds, when the cached count says the queue is empty
 * and in checkQueue(). hasItems() and itemCount() read the cached counter,
 * not the database.
 *
 * Options (setOptions() / setOption()):
 * - `numitems` (1): items returned per dequeue().
 * - `mode` ('direct'): 'direct' or 'wait', see above.
 * - `enqueue_limit` (1000): items per INSERT when flushing.
 * - `process_timeout` (10000): ms before an INPROCESS item is retried.
 * - `retry_limit` (5): maximum `tries` before an item is fatal.
 * - `timeout_recount` (180): seconds between forced recounts.
 * - `is_debug` (false): stored, not read by the module.
 *
 * Item cache: getItem() caches Items in a static array for the request;
 * every status or value change through this class clears the entry.
 *
 * Hooks fired by this class:
 * - action `shortpixel/modules/wpq/item/timeout` (inProcessTimeout()),
 *   with the Item, right before it is failed fatally for exceeding
 *   `retry_limit`.
 *
 * Bundling: the build tool rewrites the namespace and the hook prefix per
 * consuming plugin; the option name prefix `shortqwp_` and the storage
 * table are not rewritten, see ShortQ.
 *
 * @todo $itemCache is keyed by item_id only, while item IDs are unique per
 *       plugin slug and queue name. Two queues of the same plugin that hold
 *       the same item_id in one request (for example a bulk and a single
 *       queue for the same attachment) return each other's Item from
 *       getItem(). Suggested fix: key the cache by slug, queue name and
 *       item_id.
 * @todo The constructor returns early on an empty slug or queue name,
 *       leaving $options and $currentStatus unset; the first setOption()
 *       or dequeue() then fails. Suggested fix: throw an exception.
 * @todo inProcessTimeout() guards with `! $this->options->mode == 'wait'`,
 *       which negates the string first and is always false, so the method
 *       also runs in `direct` mode (where no items are INPROCESS and the
 *       query is wasted). Suggested fix: `'wait' !== $this->options->mode`.
 */
class WPQ implements Queue
{
  /**
   * Option name holding the Status objects of all queues of the plugin.
   * Starts as the prefix and becomes `shortqwp_{slug}` in the constructor.
   *
   * @var string
   */
  private $statusName = 'shortqwp_';

  /**
   * Queue name, at most 30 characters.
   *
   * @var string
   */
  protected $qName; // queue Name

  /**
   * Plugin slug, at most 30 characters.
   *
   * @var string
   */
  protected $pSlug; // plugin slug

  /**
   * Storage backend.
   *
   * @var \EnableMediaReplace\ShortQ\DataProvider\DataProvider
   */
  protected $DataProvider;

  /**
   * Full content of the status option: `['queues' => [name => Status]]`,
   * as loaded at construction. Only read in loadStatus()/createQueue().
   *
   * @var array|null
   */
  protected $status; // the status, the whole status and nothing but.

  /**
   * Status of this queue. False after unInstall(), meaning "remove on save".
   *
   * @var Status|false|null
   */
	protected $currentStatus; // working status, current queue name.

  /**
   * Items buffered by addItems() and not yet flushed by enqueue().
   *
   * @var Item[]
   */
  protected $items = array();

  /**
   * Option values, see the class description.
   *
   * @var \stdClass
   */
  protected $options;

  // statistics and status

  /**
   * Items dequeued by this object during the request. Only used by the
   * unused calcAverageAsk().
   *
   * @var int
   */
  protected $current_ask = 0;

  /**
   * Request-wide cache of Items loaded by getItem(), keyed by item_id.
   *
   * @var array<int, Item>
   */
  protected static $itemCache = []; // cache items to prevent multiple database calls

  /**
   * Loads or creates the queue status and sets the default options.
   *
   * @param string                                        $slug         Plugin slug.
   * @param string                                        $qName        Queue name.
   * @param \EnableMediaReplace\ShortQ\DataProvider\DataProvider $DataProvider Storage backend.
   */
  public function __construct($slug, $qName, $DataProvider)
  {
    if ($slug == '' || $qName == '' )
      return false;

    $statusName = $this->statusName . $slug;
    if (strlen($statusName) >= 64) // max for wp_options
      $statusName = substr($statusName, 0, 64);

    $this->statusName = $statusName;

    $this->qName = $qName;
    $this->pSlug = $slug;
    $this->DataProvider = $DataProvider;

    $this->loadStatus();

    $this->options = new \stdclass;
    $this->options->numitems = 1; //amount to dequeue
    $this->options->mode = 'direct'; // direct | wait
    $this->options->enqueue_limit = 1000; // amount of items to deliver to DataProvider in one go.
    $this->options->process_timeout = 10000; //How long to wait 'IN_PROCESS' for a retry to happen (until retry_limit) in MS
    $this->options->retry_limit = 5;
    $this->options->timeout_recount = 180; // Time to recount and check stuff from datasource in Seconds
    $this->options->is_debug = false;

  }

  /**
   * Sets several options; unknown names are ignored.
   *
   * @param array<string, mixed> $options Option name => value.
   * @return void
   */
  public function setOptions($options)
  {
      foreach($options as $option => $value)
      {
        $this->setOption($option, $value);
      }
  }

  /**
   * Sets one option; unknown names are ignored.
   *
   * @param string $name  Option name.
   * @param mixed  $value New value.
   * @return void
   */
  public function setOption($name, $value)
  {
      if (property_exists($this->options, $name))
        $this->options->$name = $value;
  }

  /**
   * Returns one option.
   *
   * @param string $name Option name.
   * @return mixed The value, or null for an unknown name.
   */
  public function getOption($name)
  {
     if (property_exists($this->options, $name))
        return $this->options->$name;
  }

  /**
   * Buffers items in memory for a later enqueue().
   *
   * Each item is an array with `id` (the consumer's item ID, required;
   * items without it are skipped), `value` (any payload, default ''),
   * optionally `item_count` and `order` (list order). When items were
   * added and $updateStatus is true, the queue is flagged `preparing` and
   * `finished` is cleared, in memory only; enqueue() saves.
   *
   * @param array $items        List of item arrays.
   * @param bool  $updateStatus False to leave the preparing/finished flags
   *                            alone.
   * @return void
   */
  public function addItems($items, $updateStatus = true)
  {
      foreach($items as $item)
      {

        if (! isset($item['id']))
          continue;

        $value = isset($item['value']) ? $item['value'] : '';
        $itemObj = new Item();
        $itemObj->item_id = $item['id'];
        $itemObj->value = $value;

        if (isset($item['item_count']))
          $itemObj->item_count = intval($item['item_count']);

        if (isset($item['order']))
            $itemObj->list_order = $item['order'];

        $this->items[] = $itemObj;

      }
      if (count($items) > 0 && true === $updateStatus)
      {
        $this->setStatus('preparing', true, false);
        $this->setStatus('finished', false, false); // can't be finished when adding items.
      }
  }

  /**
   * Writes the buffered items (plus $items, if given) to the provider.
   *
   * Items go out in chunks of `enqueue_limit`. The provider inserts with
   * INSERT IGNORE, so an item_id already present in this queue is skipped
   * silently and not counted. After every chunk `last_item_id` is saved
   * to the option so a consumer can resume preparing when the script is
   * terminated. The buffer is emptied afterwards. Call with as many items
   * as fit in memory and the time limit: every chunk costs an option
   * write.
   *
   * @param array $items Optional items as accepted by addItems().
   * @return int Number of items waiting in the queue after the insert.
   */
  public function enqueue($items = array() )
  {
      if (count($items) > 0)
        $this->addItems($items);

      $chunks = array_chunk($this->items, $this->options->enqueue_limit );
      $numitems = $this->getStatus('items');


      foreach($chunks as $chunknum => $objItems)
      {
        $numitems += $this->DataProvider->enqueue($objItems);

        $last_id = end($objItems)->item_id;
        $this->setStatus('last_item_id', $last_id); // save this, as a script termination safeguard.
      }

      $this->items = array(); // empty the item cache after inserting
      $this->setStatus('items', $numitems, false);
      $this->saveStatus();

      return $numitems;
  }

  /**
   * Buffers items with a fixed list order. Chainable:
   * `$queue->withOrder($items, $order)->enqueue()`.
   *
   * Orders below 10 are treated as priority items by dequeue(); regular
   * items get 10 + their position from the provider.
   *
   * @param array $items Items as accepted by addItems().
   * @param int   $order List order to give every item.
   * @return $this
   */
  public function withOrder($items, $order)
  {
      foreach($items as $index => $item)
      {
        $item['order'] = $order;
        $items[$index] = $item;
      }
      $this->addItems($items);
      return $this;
  }

  /**
   * Deletes stored items that have the same item_id as the buffered items,
   * so that the following enqueue() replaces them instead of being ignored.
   * Chainable: `$queue->withRemoveDuplicates()->enqueue()`.
   *
   * Use for small selections only (for example re-queueing one image with
   * a changed value or status): it costs a DELETE per call. Decrements the
   * waiting counter by the rows deleted, and recounts from the provider
   * when the counter reaches zero, because the deleted rows may have been
   * in another status than waiting.
   *
   * @return $this
   */
  public function withRemoveDuplicates()
  {

     $item_ids = array();

     foreach($this->items as $item)
     {
          $item_ids[] = $item->item_id;
     }

     $count = $this->DataProvider->removeRecords(array('items' => $item_ids));

     if ($count > 0)
        $this->setStatusCount('items', -$count );

      // Probabably not the full solution, but this can happen if deleted items were already Dequeued with status Done.
      if ($this->getStatus('items') <= 0)
      {
        $this->resetInternalCounts();
      }

     return $this;
  }

  /**
   * Deletes stored items directly, bypassing the counters. Prefer
   * dequeue() where possible. Only `item_id` is supported.
   *
   * @param array $args `['item_id' => int]`.
   * @return void
   */
	public function removeItems($args)
	{
			 if (isset($args['item_id']))
			 {
						$this->DataProvider->removeRecords(array('item_id' => $args['item_id'] ));
			 }
	}

  /**
   * Takes the next batch of waiting items.
   *
   * Steps: run inProcessTimeout(); when the cached waiting counter is zero,
   * checkQueue() recounts, retries errors and may finish the queue, and an
   * empty array is returned if nothing is left. Otherwise the provider
   * moves up to `numitems` items (lowest list_order first) to DONE in
   * `direct` mode or INPROCESS in `wait` mode. When that yields nothing
   * although the counter said otherwise, the counters are recounted and
   * the query is repeated once, which prevents stalling on a stale count.
   * Counters, `last_run` and `running` are updated and saved; when the
   * waiting counter hits zero, checkQueue() runs to possibly finish the
   * queue.
   *
   * @param array $args {
   *     Optional overrides.
   *
   *     @type int  $numitems     Items to take. Default option `numitems`.
   *     @type int  $newstatus    Status to give the items. Default by mode.
   *     @type bool $onlypriority True to take only items with list_order
   *                              below 10. Default false. Such a call does
   *                              not set the `running` flag.
   * }
   * @return Item[] Items taken, possibly empty.
   */
  // Dequeue a record, put it on done in queue.
  public function dequeue($args = array())
  {
    // Check if anything has a timeout, do that first.
    $this->inProcessTimeout();

    if ($this->currentStatus->get('items') <= 0)
    {
      $still_here = $this->checkQueue();
       // @todo if there is queue todo, update items, and go.
      if (! $still_here)
        return array();
    }

    $newstatus = ($this->options->mode == 'wait') ? ShortQ::QSTATUS_INPROCESS : ShortQ::QSTATUS_DONE;

    $defaults = array(
      'numitems' => $this->options->numitems,
      'newstatus' => $newstatus,
      'onlypriority' => false,
    );

    $args = wp_parse_args($args, $defaults);

    if ($args['onlypriority'])
    {
       $args['priority'] = array('operator' => '<', 'value' => 10);
      // unset($args['onlypriority']);
    }

    $items = $this->DataProvider->dequeue($args);

    $itemcount = count($items);

    // @todo Ask dprovder for dequeue
    // Update item count, average ask, last_run for this process
    // When Q is empty, reask for item count for DataProvider and check if it the same, if not, update number, continue.
    if ($itemcount == 0 && $args['onlypriority'] == false)
    { // This pieces prevents stalling. If the cached count is wrong, reset it, and if empty already will go to items_left / end queue system. Oterhwise resume.
        $this->resetInternalCounts();
        $items = $this->DataProvider->dequeue($args);
        $itemcount = count($items);
    }

     $items_left =  $this->getStatus('items') - $itemcount;
     $this->setStatus('items', $items_left , false);

     if ($newstatus == ShortQ::QSTATUS_DONE)
        $this->setStatusCount('done', $itemcount, false);
     elseif($newstatus == ShortQ::QSTATUS_INPROCESS)
        $this->setStatusCount('in_process', $itemcount, false);

     $this->current_ask += $itemcount;

     //$queue['average_ask'] = $this->calcAverageAsk($queue['average_ask']);
     //$this->updateQueue($queue);
     $this->setStatus('last_run', time(), false);
     if (! isset($args['priority']))
		 {
      $this->setStatus('running', true, false);
		 }

     $this->saveStatus();

     if ($items_left == 0)
        $this->checkQueue(); // possible need to end it.

     return $items;
  }



  /**
   * Returns timed-out INPROCESS items to the waiting state.
   *
   * Items updated more than `process_timeout` milliseconds ago get `tries`
   * incremented and go back to WAITING; once `tries` exceeds
   * `retry_limit` the item is failed fatally instead, after firing
   * `shortpixel/modules/wpq/item/timeout`. The in-process and waiting
   * counters are moved accordingly (the latter with a save).
   *
   * Intended for `wait` mode only but runs in every mode, see the class
   * @todo.
   *
   * @return int|void Number of items returned to waiting; nothing when the
   *                  (ineffective) mode guard returns early.
   */
  public function inProcessTimeout()
  {
     // Not waiting for anything.
     if (! $this->options->mode == 'wait')
      return;

    $args = array('status' => ShortQ::QSTATUS_INPROCESS, 'updated' => array('value' => time() - ($this->options->process_timeout/1000), 'operator' => '<='));

    $items = $this->DataProvider->getItems($args);
    $updated = 0;

    foreach($items as $item)
    {
       $item->tries++;
			 if ($item->tries > $this->getOption('retry_limit'))
			 {
				 do_action('emr/modules/wpq/item/timeout', $item);
				 $this->itemFailed($item, true); // fatal fail
			 }
			 else
			 {
			 	$updated += $this->DataProvider->itemUpdate($item, array('status' => ShortQ::QSTATUS_WAITING, 'tries' => $item->tries));
        $this->clearItemcache($item);
			 }
    }

		if ($updated > 0)
		{
			if ($this->options->mode == 'wait')
			{
				$this->setStatusCount('in_process',- $updated, false);
			}

			$this->setStatusCount('items', $updated, true);
		}

    return $updated;

  }

  /**
   * Marks an item DONE and moves the counters (waiting in `direct` mode,
   * in-process in `wait` mode, plus done). Saves the status.
   *
   * @param Item $item Item to finish.
   * @return void
   */
  public function itemDone(Item $item)
  {
      if ($this->options->mode == 'direct')
      {
        $this->setStatusCount('items', -1, false);
      }
      elseif ($this->options->mode == 'wait')
      {
        $this->setStatusCount('in_process',-1, false);
      }

      $this->setStatusCount('done', 1, false);

      $this->saveStatus();

      $this->DataProvider->itemUpdate($item, array('status' => ShortQ::QSTATUS_DONE));
      $this->clearItemCache($item); 
  }

  /**
   * Marks an item as failed.
   *
   * Non-fatal: status ERROR, `errors` counter incremented; checkQueue()
   * retries the item later while its `tries` stay below `retry_limit`.
   * Fatal: status FATAL, `fatal_errors` incremented and the item removed
   * from the waiting (direct) or in-process (wait) counter. In both cases
   * `tries` is incremented and the status saved.
   *
   * @param Item $item  Item that failed.
   * @param bool $fatal True to give up on the item.
   * @return void
   */
  public function itemFailed(Item $item, $fatal = false)
  {
    $status = ShortQ::QSTATUS_ERROR;
    if ($fatal)
    {
        $status = ShortQ::QSTATUS_FATAL;
        $this->setStatusCount('fatal_errors', 1, false );
				if ($this->options->mode == 'direct')
				{
					$this->setStatusCount('items', -1, false);
				}
				elseif ($this->options->mode == 'wait')
				{
					$this->setStatusCount('in_process',-1, false);
				}
    }
    else
      $this->setStatusCount('errors', 1, false );

    $item->tries++;
    $this->DataProvider->itemUpdate($item, array('status' => $status, 'tries' => $item->tries));
    $this->clearItemCache($item); 

		$this->saveStatus();
  }

  /**
   * Persists a changed `value` of an item, leaving its status alone.
   *
   * Used by consumers that keep progress data in the item payload. The
   * property_exists() guard is always true for an Item.
   *
   * @param Item $item Item whose value was changed.
   * @return bool True when exactly one row changed.
   */
  public function updateItemValue(Item $item)
  {
      if (!property_exists($item, 'value'))
      {
          return false;
      }
      $this->clearItemCache($item); 

      return $this->DataProvider->itemUpdate($item, array('value' => $item->getRaw('value') ));
  }

  /**
   * Loads an item by item_id, through the request-wide cache.
   *
   * @param int $item_id Consumer item ID.
   * @return Item|false False when the item is not in this queue.
   */
  public function getItem($item_id)
  {
     if (isset(self::$itemCache[$item_id]))
     {
       return self::$itemCache[$item_id];
     }

     $item =  $this->DataProvider->getItem($item_id);

     if (false !== $item)
     {
        self::$itemCache[$item_id]  = $item; 
     }
     
     return $item;

  }

  /**
   * Removes an item from the request cache; called whenever its status or
   * value changes. A numeric string ID is ignored (only int is accepted).
   *
   * @param Item|int $item Item or item_id.
   * @return void
   */
  protected function clearItemCache($item)
  {
     if (is_object($item))
     {
       $item_id = $item->item_id; 
     }
     elseif(is_int($item))
     {
       $item_id = $item; 
     }
     else
     {
      return;
     }

     if (isset(self::$itemCache[$item_id]))
     {
        unset(self::$itemCache[$item_id]); 
     }
  }

  /**
   * Tells whether the cached waiting counter is positive. No database call.
   *
   * @return bool
   */
  public function hasItems()
  {
    //$status = $this->getQueueStatus();
    $items = $this->itemCount(); // $status->get('items');
    if ($items > 0)
      return true;
    else
      return false;

  }

  /**
   * Returns the cached waiting counter. No database call; the counter is
   * refreshed by dequeue() and the recount rules in the class description.
   *
   * @return int
   */
  public function itemCount()
  {
    //  $queue = $this->getQueueStatus();
      $num = $this->getStatus('items'); //$queue->items;
      if ($num <= 0)
      { // This creates lots of data / sql's and issues.
      //  $this->checkQueue(); // check and update left records before checking on Dprovider.
        //$num = $this->DataProvider->itemCount();
        //$this->setStatus('items', $num);
      }
      return (int) $num;
  }

  /**
   * Sums `item_count` over the stored items, from the provider.
   *
   * With 'countbystatus' the result is an array keyed by status; statuses
   * without rows are filled in with 0 so consumers can index it directly.
   *
   * @param int|string $status Status constant, ShortQ::QSTATUS_ALL, or
   *                           'countbystatus'.
   * @return int|array<int, int>
   */
  public function itemSum($status = ShortQ::QSTATUS_ALL)
  {
      $row = $this->DataProvider->itemSum($status);

      if ($status === 'countbystatus')
      {
        // check if all status are there. If they are unused, they are not in result.
        $status_ar = array(ShortQ::QSTATUS_WAITING, ShortQ::QSTATUS_DONE, ShortQ::QSTATUS_INPROCESS,   ShortQ::QSTATUS_ERROR, ShortQ::QSTATUS_FATAL);

        foreach($status_ar as $stat)
        {
            if (! isset($row[$stat]))
              $row[$stat] = 0;
        }
      }

      return $row;
  }

  /**
   * Ends a queue run: deletes every stored item of this queue and replaces
   * the Status with a fresh one.
   *
   * Statistics are lost, so collect them before calling; that is why this
   * is never done automatically.
   *
   * @return void
      *
   * @todo The static $itemCache is not cleared here (nor by removeItems()),
   *       so getItem() keeps returning Items for rows this call deleted
   *       until the request ends. Suggested fix: reset $itemCache in
   *       resetQueue() and clear the removed ids in removeItems().
   */
  public function resetQueue()
  {
     $this->DataProvider->removeRecords(array('all' => true));
     $this->currentStatus = new Status();
     $this->saveStatus();
  }

  /**
   * Deletes the DONE and FATAL items of this queue and recounts. Keeps the
   * queue usable, unlike resetQueue().
   *
   * @return void
   */
  public function cleanQueue()
  {

     $this->DataProvider->removeRecords(array('status' => ShortQ::QSTATUS_DONE));
     $this->DataProvider->removeRecords(array('status' => ShortQ::QSTATUS_FATAL));
     $this->resetInternalCounts();
  }

  /**
   * Sets a Status value and stamps `last_update`.
   *
   * Consumers use it for the `preparing`, `running`, `bulk_running` and
   * `custom_data` fields. Counters can be set too, but prefer
   * setStatusCount() for relative changes.
   *
   * @param string $name    Status property.
   * @param mixed  $value   New value.
   * @param bool   $savenow False to postpone the option write to a later
   *                        saveStatus().
   * @return bool False when the property does not exist.
   */
  /** @todo Users must be able to control preparing / running status controls for resume / play the queue, but possibly not the counts.  */
  public function setStatus($name, $value, $savenow = true)
  {
    $r = $this->currentStatus->set($name, $value);
    $this->currentStatus->set('last_update', time());
		$bool = true;

    if (! $r)
      $bool = false;

    if ($savenow)
      $this->saveStatus(); // for now.


    return $bool;
  }

  /**
   * Adds a (possibly negative) amount to a counter.
   *
   * When the result would be negative, or `timeout_recount` seconds have
   * passed since the last recount, the counters are first resynced from
   * the provider and the change is applied to the fresh value. This keeps
   * the cached counters from drifting when several requests work on the
   * same queue.
   *
   * @param string $name    Counter name; non-counters are refused.
   * @param int    $change  Amount to add.
   * @param bool   $savenow False to postpone the option write.
   * @return bool False for a non-counter or unknown property.
   *
   * @todo When the recount runs, the provider's count already includes the
   *       rows the caller just changed (dequeue() updates the rows before
   *       calling this), and $change is then added on top, so the counter
   *       is double counted until the next recount. On a fresh Status
   *       `last_count_time` is 0, so this happens on the very first
   *       dequeue(): two items dequeued give in_process = 4. Suggested fix:
   *       after a recount return the fresh value without adding $change.
   */
  public function setStatusCount($name, $change, $savenow = true)
  {
      if (! $this->currentStatus->isCounter($name))
      {
        return false;
      }

      $count = $this->getStatus($name);
      // Check for regular timeout on refreshing the counts.
      $last_count = time() - $this->getStatus('last_count_time');
      $timeout = ($last_count > $this->options->timeout_recount) ? true : false;

      if ($count + $change < 0 || true === $timeout)
      {
       // Weird problem that would trigger sometimes with background active.
       $this->resetInternalCounts();
       $count = $this->getStatus($name);
      }

      return $this->setStatus($name, $count + $change, $savenow);
  }

  /**
   * Starts a new Status for this queue and saves it. When the option did
   * not exist at all, the provider's storage is (re)installed too.
   *
   * @return void
   */
  private function createQueue()
  {
			if (is_null($this->status))
			{
				$this->status = array();
				$this->status['queues']  = array();
				$this->DataProvider->install(true);
			}

			$this->currentStatus = new Status();
      $this->saveStatus();

  }


  /**
   * Loads this queue's Status from the option, creating it when the option
   * or the queue entry is missing or malformed.
   *
   * @return void
   */
  private function loadStatus()
  {
    $this->status = get_option($this->statusName, null);

    if (false === $this->status || is_null($this->status) || (! is_object($this->status) && ! is_array($this->status) ))
		{
      $this->createQueue();
		}
    elseif (! isset($this->status['queues'][$this->qName]))
		{
      $this->createQueue();
		}
		else {
			// ONLY status this reference.
			$this->currentStatus = $this->status['queues'][$this->qName];
		}
  }

  /**
   * Returns the Status object or one of its values.
   *
   * @param string|false $item Property name, or false for the whole object.
   * @return Status|mixed|false False when no status is loaded.
   */
  public function getStatus($item = false)
  {
			if (is_null($this->currentStatus))
				return false;
      elseif (! $item)
        return $this->currentStatus;
      elseif (is_object($this->currentStatus))
        return $this->currentStatus->get($item);
			else {
				return false;
			}
  }

  /**
   * Writes this queue's Status into the shared option.
   *
   * Re-reads the option first so that the entries of other queues written
   * by concurrent requests are kept. When $currentStatus is false (after
   * unInstall()) the entry is removed, and the option is deleted once no
   * queue is left. Bypasses the object cache afterwards so the next read
   * in this request sees the stored value.
   *
   * @return void
   */
  protected function saveStatus()
  {
     $status = get_option($this->statusName);  // two different Q's can run simulanously.

		 $currentStatus =  $this->currentStatus;
		 if( $currentStatus === false && isset($status['queues'][$this->qName]) ) // Don't save status which has been removed.
		 {
			  unset($status['queues'][$this->qName]);
		 }
		 else {
			 if (false === $status || false === is_array($status))
			 {
				  $status = array();
					$status['queues'] = array();
			 }
			 $status['queues'][$this->qName]  = $currentStatus;
		 }

     if (count($status['queues']) == 0)
     {

       $res = delete_option($this->statusName);
     }
     else {
        $res = update_option($this->statusName, $status);
     }


     wp_cache_delete($this->statusName, 'options');
  }

  /**
   * Reconciles the queue with the provider when the cached count says it
   * is empty.
   *
   * Recounts all counters, then retries ERROR items: those with `tries`
   * below `retry_limit` go back to WAITING, the others to FATAL, followed
   * by another recount. Finishes the queue when nothing is waiting or in
   * process and the queue is not `preparing`.
   *
   * @return bool|null True when items remain (waiting or in process),
   *                   false when the queue was finished, null when it is
   *                   empty but still preparing.
   */
  private function checkQueue()
  {
    $this->resetInternalCounts(); // retrieve accurate count from dataSource.

    $tasks_done = $this->getStatus('done');
    $tasks_open = $this->getStatus('items');
    $tasks_inprocess = $this->getStatus('in_process');
    $tasks_error = $this->getStatus('errors');

    $preparing = $this->getStatus('preparing');

    $mode = $this->options->mode;
    $update_at_end = false;

    if ($tasks_error > 0)
    {
      $update_at_end = true;
      $error_args = array(
          'numitems' => $tasks_error,
          'status' => ShortQ::QSTATUS_ERROR,
      //    'status' => ShortQ::QSTATUS_ERROR,
      );

      $error_items = $this->DataProvider->dequeue($error_args);
      $retry_array = array();
      $failed_array = array();
      foreach($error_items as $errItem)
      {
        $errid = $errItem->item_id;
        

        if ($errItem->tries < $this->options->retry_limit)
        {
          //$retry_array = $erritem->id;
          $this->DataProvider->itemUpdate($errItem, array('status' => ShortQ::QSTATUS_WAITING));
        }
        else {
           $this->DataProvider->itemUpdate($errItem, array('status' => ShortQ::QSTATUS_FATAL));

        }
        $this->clearItemCache($errItem); 
      }
    } // tasks_errors

    if($update_at_end)
    {
      $this->resetInternalCounts(); // retrieve accurate count from dataSource.
      $tasks_open = $this->currentStatus->get('items');
      $tasks_inprocess = $this->currentStatus->get('in_process');
    }

    if ($tasks_open > 0 || $tasks_inprocess > 0)
      return true;
    elseif (false === $preparing) // preparing queues might have zero items, but no reason to finish them since items may come
    {
      $this->finishQueue();
      return false;
    }

    return null;
  }

  /**
   * Replaces the cached counters with a per-status count from the provider
   * and saves. Stamps `last_count_time`.
   *
   * @return void
   */
  private function resetInternalCounts()
  {
    $dataQ = $this->DataProvider->itemCount('countbystatus');
    $num_items = $num_done = $num_in_process = $num_errors = $num_fatal = 0;

		if (is_array($dataQ))
		{
	    foreach($dataQ as $qstatus => $count)
	    {
	         switch($qstatus)
	         {
	           case ShortQ::QSTATUS_WAITING:
	             $num_items = $count;
	           break;
	           case ShortQ::QSTATUS_DONE:
	             $num_done = $count;
	           break;
	           case ShortQ::QSTATUS_INPROCESS:
	             $num_in_process = $count;
	           break;
	           case ShortQ::QSTATUS_ERROR:
	             $num_errors = $count;
	           break;
	           case ShortQ::QSTATUS_FATAL:
	              $num_fatal = $count;
	           break;
	         }
	     }
		 }

     $this->setStatus('items', $num_items, false);
     $this->setStatus('done', $num_done, false);
     $this->setStatus('in_process', $num_in_process, false);
     $this->setStatus('errors', $num_errors, false);
     $this->setStatus('fatal_errors', $num_fatal, false);
     $this->setStatus('last_count_time', time());

     $this->saveStatus();
     // direct, to prevent loop.

  }

  /**
   * Unused draft of an average batch size calculation.
   *
   * @param int|float $avg Previous average.
   * @return float
   */
  private function calcAverageAsk($avg)
  {
    // @todo this is nonsense. Need a counter for X times run.
    return ($avg / $this->current_ask);
  }

  /**
   * Marks the queue finished: `running` off, `finished` on, `last_run`
   * now, `times_ran` incremented. Saves.
   *
   * @return void
   */
  private function finishQueue()
  {
     $this->setStatus('running', false, false);
     $this->setStatus('finished', true, false);
     $this->setStatus('last_run', time(), false);
     $this->setStatusCount('times_ran', 1, false );
     $this->saveStatus();

  }

  /**
   * (Re)creates the provider's storage table. Safe to call on every
   * activation; the provider uses dbDelta.
   *
   * @return void
   */
  public function install()
  {
			$this->DataProvider->install(true);
  }

  /**
   * Removes this queue on plugin uninstall: deletes its stored items,
   * drops its entry from the status option (and the option when it was
   * the last queue) and asks the provider to drop the table, which only
   * happens when no rows of any plugin or queue remain.
   *
   * @return void
   */
  public function unInstall()
  {
      // Remove the Queued Items
      $this->DataProvider->removeRecords(array('all' => true, 'check_safe' => true));

      // Unset the WP Option queue
      $this->currentStatus = false;
      $this->saveStatus();

      // Signal to DataProvider to check if the whole thing can be removed. Won't happen when there are still records.
      $this->DataProvider->uninstall();
  }

}
