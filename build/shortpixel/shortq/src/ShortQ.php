<?php
/**
 * ShortQ module entry point.
 *
 * Holds the ShortQ factory class and the queue status constants shared by
 * the Queue and DataProvider implementations.
 *
 * @package EnableMediaReplace\ShortQ
 */
namespace EnableMediaReplace\ShortQ;
//use \EnableMediaReplace\ShortQ\Queue;
//use \EnableMediaReplace\ShortQ\DataProvider;

/**
 * Factory for queue objects and holder of the item status constants.
 *
 * ShortQ is a small, database-backed job queue for WordPress plugins. A
 * consumer creates one ShortQ per plugin (`new ShortQ('SPIO')`) and asks it
 * for named queues with getQueue(); every call returns a new Queue\WPQ
 * object backed by a DataProvider\MysqlDataProvider. Several queues of the
 * same plugin (for example `media` and `mediaSingle`) share one database
 * table and one status option but are isolated by their queue name.
 *
 * Typical consumer flow:
 * 1. `$q = (new ShortQ($slug))->getQueue($name);`
 * 2. `$q->setOptions([...])` to choose the processing mode and batch sizes.
 * 3. `$q->enqueue([['id' => 12, 'value' => [...]], ...])` to add work.
 * 4. Repeatedly `$items = $q->dequeue();` from a cron tick or AJAX call and,
 *    in `wait` mode, report back with `itemDone()` / `itemFailed()`.
 * 5. `$q->getStatus()` for counters, `resetQueue()` when the run is over.
 *
 * The queue and data provider implementations are fixed to 'wp' and 'mysql';
 * the switch statements in QLoader() exist so that alternatives can be added
 * later. Nothing is cached: each getQueue() call builds a fresh queue object
 * and the object reloads its status from the options table.
 *
 * Bundling: this source is written with the ShortPixel namespace and the
 * `shortpixel/*` hook prefix. The build tool that bundles the module into a
 * plugin rewrites the namespace and the hook prefix mechanically, so each
 * consuming plugin sees its own namespace (for example
 * `EnableMediaReplace\ShortQ`) and its own hook prefix. The database table
 * name (`{$wpdb->prefix}shortpixel_queue`) and the status option name
 * (`shortqwp_{slug}`) are plain strings and are NOT rewritten: all plugins
 * that bundle ShortQ share the same table, and rows are told apart only by
 * the plugin slug passed to the constructor. Choose a slug that is unique
 * per plugin and at most 30 characters long (the column width).
 *
 * Fires and listens to no hooks.
 *
 * @todo QSTATUS_ALL and QSTATUS_DELETE share the value -1. Querying by
 *       status treats -1 as "all statuses" (see MysqlDataProvider::queryItems()),
 *       so items can never be selected by the delete status. Deleting works
 *       only because MysqlDataProvider::dequeue() removes rows right after
 *       setting them to -1. Suggested fix: give QSTATUS_DELETE its own value.
 */
class ShortQ
{

  /**
   * Virtual status for queries: match items of every status.
   *
   * @var int
   */
  const QSTATUS_ALL = -1; // special status, for query use

  /**
   * Item is waiting to be dequeued.
   *
   * @var int
   */
  const QSTATUS_WAITING = 0;

  /**
   * Reserved for a "pulled but not yet processing" state. Not used.
   *
   * @var int
   */
  const QSTATUS_PULLED = 1; // not in use atm.

  /**
   * Item was dequeued in `wait` mode and the consumer has not reported
   * itemDone() or itemFailed() yet.
   *
   * @var int
   */
  const QSTATUS_INPROCESS = 2;

  /**
   * Item is finished. In `direct` mode items get this status at dequeue.
   *
   * @var int
   */
  const QSTATUS_DONE = 3;

  /**
   * Virtual status: rows set to this status are deleted by the data
   * provider. Same value as QSTATUS_ALL (see the class @todo).
   *
   * @var int
   */
  const QSTATUS_DELETE = -1; // this is a virtual status. If set to this, will be deleted.

  /**
   * Item failed and may be retried until `retry_limit` is reached.
   *
   * @var int
   */
  const QSTATUS_ERROR = -2;

  /**
   * Item failed permanently; it is not retried.
   *
   * @var int
   */
  const QSTATUS_FATAL = -3;

  /**
   * Plugin slug that owns the queues built by this factory. Stored in the
   * `plugin_slug` column of every row.
   *
   * @var string
   */
  protected $pluginSlug; // unique plugin name using Q.

  /**
   * Name of the queue requested by the last getQueue() call.
   *
   * @var string|null
   */
  protected $queueName;

  /**
   * Queue implementation key, always 'wp'.
   *
   * @var string|null
   */
	protected $queue;

  /**
   * Data provider implementation key, always 'mysql'.
   *
   * @var string|null
   */
	protected $dataProvider;

  /**
   * Factories registered per queue name. QLoader() stores `$this` here, not
   * the queue object, and nothing reads the array.
   *
   * @var array<string, ShortQ>
   */
  protected static $queues = array();

  /**
   * @param string $pluginSlug Unique slug of the consuming plugin, at most
   *                           30 characters.
   */
  public function __construct($pluginSlug)
  {

     $this->pluginSlug = $pluginSlug;
      //self::$queues[$qname] = $this;
  }

  /**
   * Returns a queue object for the given name.
   *
   * Builds a new Queue\WPQ with a MysqlDataProvider on every call; the
   * queue loads its persisted status in its constructor, so two objects for
   * the same name see the same state but do not share in-memory buffers.
   *
   * @param string $qName Queue name, at most 30 characters.
   * @param bool   $lock  Unused.
   * @return Queue\WPQ
   */
  public function getQueue($qName, $lock = false)
  {
      // @todo get config from main parent file, or so.
      $this->queue = 'wp';
      $this->dataProvider = 'mysql';
      $this->queueName = $qName;

      // if nothing, then create a new Q.
      $q = $this->QLoader();
      return $q;
  }

  /**
   * Instantiates the data provider and the queue selected by $dataProvider
   * and $queue.
   *
   * @return Queue\WPQ
   */
  protected function QLoader()
  {
     $dataProvider = null;
      switch($this->dataProvider)
      {
        case 'mysql':
				default:
           $dataProvider = new DataProvider\MysqlDataProvider($this->pluginSlug, $this->queueName);
        break;
      }

      switch($this->queue)
      {
        case 'wp':
        default:
              $newQ = new Queue\WPQ($this->pluginSlug, $this->queueName, $dataProvider);
        break;
      }

      self::$queues[$this->queueName] = $this;
      return $newQ;
  }

}
