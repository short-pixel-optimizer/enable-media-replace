<?php
/**
 * MySQL storage backend of the ShortQ module.
 *
 * @package EnableMediaReplace\ShortQ
 */
namespace EnableMediaReplace\ShortQ\DataProvider;
use EnableMediaReplace\ShortQ\Item as Item;
use EnableMediaReplace\ShortQ\ShortQ as ShortQ;


/**
 * Stores queue items in the `{$wpdb->prefix}shortpixel_queue` table.
 *
 * Created by ShortQ::QLoader(), one object per queue object. All plugins
 * and all queues share the single table; every query is scoped by the
 * `plugin_slug` and `queue_name` columns (both VARCHAR(30)) and the pair
 * (plugin_slug, queue_name, item_id) is unique, which is what makes
 * INSERT IGNORE in enqueue() skip items that are already queued.
 *
 * Table columns: `id` (primary key), `queue_name`, `plugin_slug`, `status`
 * (ShortQ::QSTATUS_*), `list_order` (dequeue order, ascending), `item_id`
 * (consumer ID), `item_count`, `value` (longtext payload), `tries`,
 * `created` and `updated` (timestamps). Rows are hydrated into Item
 * objects by column name.
 *
 * Self-healing: after a failed query, handleError() runs install(), which
 * uses dbDelta() and therefore both creates a missing table and adds
 * missing columns. Errors are not logged.
 *
 * Timestamps are written with date(), i.e. in PHP's default time zone,
 * and read back by Item::__set() with the same assumption.
 *
 * Fires and listens to no hooks.
 *
 * @todo dequeue() accepts an array of statuses and joins them with commas,
 *       but queryItems() binds the status with `%d`, so only the first
 *       status is used. Suggested fix: build a `status IN (...)` clause.
 * @todo updateRecords() reads the $operators map into a variable but
 *       always emits `=`, so alterQueue()'s third argument has no effect.
 *       Suggested fix: use $operator in the clause.
 * @todo uninstall() returns check(), which is true when the table still
 *       exists, so a successful drop returns false, the same as "table has
 *       rows" and "no table". Suggested fix: return `! $this->check()`
 *       after the drop.
 */
/* WP Mysql DataProvider
*
*/
class MysqlDataProvider implements DataProvider
{
   /**
    * Queue name; the column is VARCHAR(30).
    *
    * @var string
    */
   protected $qName; // Limit is 30 chars!

   /**
    * Plugin slug; the column is VARCHAR(30).
    *
    * @var string
    */
   protected $slug;  // Limit is 30 chars!

   /**
    * Prefixed table name.
    *
    * @var string
    */
   protected $table;

   /**
    * Intended cap on query length in characters. Not enforced anywhere.
    *
    * @var int
    */
   protected $query_size_limit = 10000;  // in strlen characters.

   /**
    * @global \wpdb $wpdb
    *
    * @param string $slug  Plugin slug.
    * @param string $qName Queue name.
    */
   /* Constructor */
   public function __construct($slug, $qName)
   {
      global $wpdb;
      $this->slug = $slug;
      $this->qName = $qName;

      $this->table = $wpdb->prefix . 'shortpixel_queue';
   }

   /**
    * Inserts items with one multi-row INSERT IGNORE.
    *
    * Items without an explicit list_order are numbered from 10 + the
    * current number of waiting items, leaving 0-9 for priority items. The
    * raw (JSON-encoded) value is stored. Rows that violate the unique key
    * are skipped by MySQL and not counted. On a database error the table
    * is repaired through handleError() and false is returned.
    *
    * @global \wpdb $wpdb
    *
    * @param Item[] $items Items to insert.
    * @return int|false Number of rows inserted, false on invalid input or
    *                   database error.
    */
   public function enqueue($items)
   {
      global $wpdb;
      if (! is_array($items))
        return false;
       // start higher to allow priority additions easily.
      $list_order = (10 + $this->itemCount());
      $now = $this->timestamptoSQL();

      $sql = 'INSERT IGNORE INTO ' . $this->table . ' (queue_name, plugin_slug, value, item_count, list_order, item_id, updated, created) VALUES ';
      $values = array();
      foreach ($items as $item)
      {
        $item_id = (int) $item->item_id;
        $item_count = (int) $item->item_count;
        $value = $item->getRaw('value'); // value;

        $order = (! is_null($item->list_order)) ? $item->list_order : $list_order;

        $values[] = $wpdb->prepare('(%s, %s, %s,%d, %d, %d, %s, %s)', $this->qName, $this->slug, $value, $item_count, $order, $item_id, $now, $now);
        if (! isset($item->list_order))
          $list_order++;

      }
      $sql .= implode( ",\n", $values );
      $result = $wpdb->query($sql, $values);

      if (! $this->checkQueryOK())
      {
        return false;
      }

      return $result;
   }

   /**
    * Unused draft of an input check for enqueue(); computes a list of IDs
    * and returns nothing.
    *
    * @global \wpdb $wpdb
    *
    * @param array $items Item arrays.
    * @return void
    */
   /* Check item consistency and check if items are not already in this queue. Must be unique */
   protected function prepareItems($items)
   {
      global $wpdb;

      $items_ids = array();
      foreach($items as $key => $item)
      {
         if (isset($item['id']))
          $items_ids[] = $item['id'];
        else // no id, no q
          unset($items[$key]);
      }
   }

   /**
    * Selects items and moves them to a new status in one step.
    *
    * The selected rows get `newstatus` and `updated` = now, unless the
    * new status equals the selected one. When `newstatus` is
    * ShortQ::QSTATUS_DELETE the rows are deleted right after. The returned
    * Items already carry the new status.
    *
    * @param array $args {
    *     @type int        $numitems  Rows to take, -1 for all. Default 1.
    *     @type int|array  $status    Status to select. Default WAITING.
    *                                 Arrays are not applied correctly, see
    *                                 the class @todo.
    *     @type int        $newstatus Status to set. Default DONE.
    *     @type string     $orderby   Column to order by. Default list_order.
    *     @type string     $order     'ASC' or 'DESC'. Default 'ASC'.
    *     @type array|int|false $priority list_order condition, either a
    *                                 number or `['operator' => '<',
    *                                 'value' => 10]`. Default false.
    * }
    * @return Item[] Sequential list of the items taken.
    */
   /* Dequeue an item (return it) via specific parameters. Sets a new status after grabbing these records.
   *
   * @param $args Array
            numitems - number of records to pull
            status   - Array - the statusses to pull
            newstatus - To which status said items should be put
            orderby  - how to order the records [not implemented]

      @return Recordset of Items gotten.
   */
   public function dequeue($args = array())
   {
      $defaults = array(
          'numitems' => 1, // pass -1 for all.
          'status' => ShortQ::QSTATUS_WAITING,
          'newstatus' => ShortQ::QSTATUS_DONE,
          'orderby' => 'list_order',
          'order' => 'ASC',
          'priority' => false,  // array('operator' => '<', 'value' => 10);
      );

      $args = wp_parse_args($args, $defaults);

      if (is_array($args['status']))
        $args['status'] = implode(',', $args['status']);

      $items = $this->queryItems(array(
        'numitems' => $args['numitems'],
        'status' => $args['status'],
        'orderby' => $args['orderby'],
        'order' => $args['order'],
        'priority' => $args['priority'],
      ));

      $id_array = array_keys($items);

      // Update status if results yielded.
      if ($args['status'] !== $args['newstatus'] && count($id_array) > 0)
      {
        $now = $this->timestamptoSQL();
        $this->updateRecords(array('status' => $args['newstatus'], 'updated' => $now), array('id' => $id_array));
        foreach($items as $index => $item)
        {
          $item->status = $args['newstatus']; // update status to new situation.
          $item->updated = $now;
          $items[$index] = $item;
        }
      }
      if ($args['newstatus'] == ShortQ::QSTATUS_DELETE)
      {
          $this->removeRecords(array('status' => ShortQ::QSTATUS_DELETE));
      }
      // @todo is Status = QSTATUS_DELETE, remove all records after putting them to this status.

      return array_values($items); // array values resets the id index returns by queryItems
   }

   /**
    * Formats a Unix timestamp as a MySQL datetime; 0 means now. A
    * non-numeric input is assumed to be a datetime already and returned
    * unchanged.
    *
    * @param int|string $timestamp Unix timestamp or datetime string.
    * @return string `Y-m-d H:i:s`.
    */
   private function timestampToSQL($timestamp = 0)
   {
      if (! is_numeric($timestamp))
        return $timestamp; // possible already date;

      if ($timestamp == 0)
        $timestamp = time();

      $date =  date('Y-m-d H:i:s', $timestamp);

      return $date;
   }

   /**
    * Builds and runs the SELECT behind dequeue(), getItem() and getItems().
    *
    * Always scoped to this plugin slug and queue name. `orderby` is
    * interpolated without escaping and must come from code, not input.
    *
    * @global \wpdb $wpdb
    *
    * @param array $args {
    *     @type int              $status   Status, or QSTATUS_ALL for any.
    *     @type string           $orderby  Column. Default list_order.
    *     @type string           $order    'ASC' or 'DESC'.
    *     @type int              $numitems Limit; <= 0 for no limit.
    *     @type int|false        $item_id  Restrict to one item_id.
    *     @type int|array|false  $updated  Unix timestamp, or
    *                                      `['operator' => '<=', 'value' => ts]`,
    *                                      compared with `updated`.
    *     @type int|array|false  $priority Number or operator/value array,
    *                                      compared with `list_order`.
    * }
    * @return array<int, Item> Items keyed by row id.
    */
   /*
   * @return Array
   */
   private function queryItems($args = array())
   {
     $defaults = array(
       'status' => ShortQ::QSTATUS_ALL,
       'orderby' => 'list_order',
       'order' => 'ASC',
       'numitems' => -1,
       'item_id' => false,
       'updated' => false, // updated since (Unix Timestamp) ( or array with operator)
       'priority' => false, // number (or array with operator)
     );

     $args = wp_parse_args($args, $defaults);

     global $wpdb;
     $prepare = array();

     $sql = 'SELECT * from ' . $this->table . ' where queue_name = %s and plugin_slug = %s ';
     $prepare[] = $this->qName;
     $prepare[] = $this->slug;

     if ($args['status'] <> ShortQ::QSTATUS_ALL)
     {
       $sql .= 'and status = %d ';
       $prepare[] = $args['status'];
     }

     if ($args['item_id'] !== false && intval($args['item_id']) > 0)
     {
       $sql .= 'and item_id = %d ';
       $prepare[] = intval($args['item_id']);
     }

     if ($args['updated'])
     {
       $operator = '=';

       if (is_array($args['updated']))
       {
           $operator = isset($args['updated']['operator']) ? ($args['updated']['operator']) : $operator;
           $value = isset($args['updated']['value']) ? $args['updated']['value'] : false;
       }
       else
       {
           $value = $args['updated'];
       }

       $sql .= 'and updated ' . $operator . ' %s ';
       $prepare[] = $this->timestamptoSQL($value);
     }

     if ($args['priority'])
     {
        $operator = '=';

        if (is_array($args['priority']))
        {
            $operator = isset($args['priority']['operator']) ? ($args['priority']['operator']) : $operator;
            $value = isset($args['priority']['value']) ? $args['priority']['value'] : false;
        }
        else
        {
            $value = $args['priority'];
        }

        $sql .= 'and list_order ' . $operator . ' %d ';
        $prepare[] = $value;
     }

     if ($args['orderby'])
     {
       $order = (strtoupper($args['order']) == 'ASC') ? 'ASC ' : 'DESC ';
       $sql .= 'order by ' . $args['orderby'] . ' ' . $order;
     }

     if ($args['numitems'] > 0)
     {
        $sql .= 'limit %d ';
        $prepare[] = $args['numitems'];
     }

     $sql = $wpdb->prepare($sql, $prepare);

     $result = $wpdb->get_results($sql, ARRAY_A);

     $items = array();

     foreach($result as $index => $row)
     {
       $item = new Item();
       $id = $row['id'];
       foreach($row as $name => $value)
       {
         if (property_exists($item, $name))
         {
            $item->$name = $value;
          }
       }
       $items[$id] = $item;
     }

     return $items;
   }

   /**
    * Mass update of rows without loading them.
    *
    * @param array $data      Column => new value.
    * @param array $fields    Column => value (or list of values) to match.
    * @param array $operators Column => operator. Currently ignored, see the
    *                         class @todo.
    * @return int|false Rows changed, or false on error.
    */
   /** Updates a set of items from queue without pulling or returning those records.
   *
   *  @return int Number of Records Updated
   */
   public function alterQueue($data, $fields, $operators)
   {
    return $this->updateRecords($data, $fields, $operators);
   }

   /**
    * Updates the row of one item, matched by item_id.
    *
    * Returns true only when exactly one row changed, so writing values
    * identical to the stored ones yields false although nothing failed.
    *
    * @param Item  $item   Item to update.
    * @param array $fields Column => new value.
    * @return bool
    */
   /** Updates one queued item, for instance in case of failing, or status update
   *
   * @param $item_id int The Uniq Id of the item to update
   * @param $field Array An array of fields in key => pair format to be updated.
   */
   public function itemUpdate(Item $item, $fields)
   {
      $result = $this->updateRecords($fields, array('item_id' => $item->item_id));
      if ($result == 1 )
        return true;
      else
        return false;
   }

   /**
    * Loads one item by item_id.
    *
    * @param int $item_id Consumer item ID.
    * @return Item|false False when not found.
    */
   public function getItem($item_id)
   {
       $items = $this->queryItems(array('item_id' => $item_id));

       if (count($items) == 0)
          return false;
       else
         return array_shift($items);
   }

   /**
    * Loads items by arbitrary queryItems() arguments.
    *
    * @param array $args See queryItems().
    * @return array<int, Item> Items keyed by row id.
    */
   public function getItems($args)
   {
      return $this->queryItems($args);
   }

   /**
    * Counts the rows of this queue.
    *
    * @global \wpdb $wpdb
    *
    * @param int|string $status A ShortQ::QSTATUS_* constant for one
    *                           status, ShortQ::QSTATUS_ALL for all rows,
    *                           or 'countbystatus' for an array keyed by
    *                           status (values are numeric strings).
    * @return int|array 0 after a database error (which triggers a repair).
    */
   /* Counts Items in Database Queue
   * @param Status Mixed When supplied with ShortQ Status Constant it will count this status, will count all with ShortQ:QSTATUS_ALL.
   * When given 'countbystatus' it will return an array with  ShortQ Status as key and the count as value
     @return Mixed Either count int, or Array.
   */
   public function itemCount($status = ShortQ::QSTATUS_WAITING)
   {
      global $wpdb;
      if (is_numeric($status) && $status != ShortQ::QSTATUS_ALL)
      {
        $sql = 'SELECT count(*) FROM ' . $this->table . ' WHERE queue_name = %s and plugin_slug = %s and status = %d ';
        $count = $wpdb->get_var($wpdb->prepare($sql, $this->qName, $this->slug, $status));
      }
      elseif ($status == ShortQ::QSTATUS_ALL) // full queue, with records from all status.
      {
        $sql = 'SELECT count(*) FROM ' . $this->table . ' WHERE queue_name = %s and plugin_slug = %s ';
        $count = $wpdb->get_var($wpdb->prepare($sql, $this->qName, $this->slug));
      }
      elseif ($status == 'countbystatus')
      {
        $sql = 'SELECT count(id) as count, status FROM ' . $this->table . ' WHERE queue_name = %s and plugin_slug = %s group by status';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $this->qName, $this->slug), ARRAY_A);
        $count = array();

        foreach($rows as $row)
        {
           $count[$row['status']] = $row['count'];
        }

      }

      if (!empty($wpdb->last_error))
      {
        $this->handleError($wpdb->last_error);
        return 0;
      }

      return $count;
   }

   /**
    * Sums `item_count` over the rows of this queue. Same selection rules
    * as itemCount().
    *
    * @global \wpdb $wpdb
    *
    * @param int|string $status Status constant, QSTATUS_ALL or
    *                           'countbystatus'.
    * @return int|array<int, int> 0 (or an empty array for 'countbystatus')
    *                             after a database error.
    */
   /* Counts Sum of Items in the Database Queue
   * @param Status Mixed When supplied with ShortQ Status Constant it will count this status, will count all with ShortQ:QSTATUS_ALL.
   * When given 'countbystatus' it will return an array with  ShortQ Status as key and the count as value
     @return Mixed Either count int, or Array.
   */
   public function itemSum($status = ShortQ::QSTATUS_WAITING)
   {
      global $wpdb;
      if (is_numeric($status) && $status != ShortQ::QSTATUS_ALL)
      {
        $sql = 'SELECT SUM(item_count) FROM ' . $this->table . ' WHERE queue_name = %s and plugin_slug = %s and status = %d ';
        $count = (int) $wpdb->get_var($wpdb->prepare($sql, $this->qName, $this->slug, $status));
      }
      elseif ($status == ShortQ::QSTATUS_ALL) // full queue, with records from all status.
      {
        $sql = 'SELECT SUM(item_count) FROM ' . $this->table . ' WHERE queue_name = %s and plugin_slug = %s ';
        $count = (int) $wpdb->get_var($wpdb->prepare($sql, $this->qName, $this->slug));
      }
      elseif ($status == 'countbystatus')
      {
        $sql = 'SELECT SUM(item_count) as count, status FROM ' . $this->table . ' WHERE queue_name = %s and plugin_slug = %s group by status';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $this->qName, $this->slug), ARRAY_A);
        $count = array();

        foreach($rows as $row)
        {
           $count[$row['status']] = (int) $row['count'];
        }

      }

      if (!empty($wpdb->last_error))
      {
        $this->handleError($wpdb->last_error);
        if ($status == 'countbystatus')
          return array();
        else
          return 0;
      }

      return $count;
   }


   /**
    * Runs an UPDATE scoped to this queue.
    *
    * `updated` is always written (the given value or now). `created` is
    * pinned to itself unless given, because some MySQL servers define the
    * first timestamp column as ON UPDATE CURRENT_TIMESTAMP. Array values
    * in $where become IN (...) clauses, scalars an equality.
    *
    * @global \wpdb $wpdb
    *
    * @param array $data      Column => new value.
    * @param array $where     Column => value or list of values.
    * @param array $operators Column => operator. Read but not applied, see
    *                         the class @todo.
    * @return int|false Rows changed, or false on error.
    */
   /** Update records
   *
   * @param $Data Array. Data array to change, to WP standards
   * @param $where Array. Data Array on conditions, to WP standards
   * @param $operators Array. Maps of Field => Operator to use anything else than standard = in the where query.
   * @return int Amount of records updates, or null|false
   */
   private function updateRecords($data, $where, $operators = array() )
   {
      global $wpdb;
      $update_sql = 'UPDATE ' . $this->table . ' set updated = %s';
      if (isset($data['updated']))
      {
          $placeholders = array($this->timestamptoSQL($data['updated']));
          unset($data['updated']);
      }
      else
          $placeholders = array($this->timestamptoSQL());

			// Certain older SQL servers like to auto-update created date, creating a mess.
			if (! isset($data['created']))
			{
				 $update_sql .= ', created = created';
			}

      foreach($data as $field => $value)
      {
        $update_sql .= ' ,' . $field . ' = %s ';
        $placeholders[] =  $value;
      }

      $update_sql .= ' WHERE queue_name = %s and plugin_slug = %s ';
      $placeholders[] = $this->qName;
      $placeholders[] = $this->slug;

      foreach ($where as $field => $value)
      {
        if (is_array($value))
        {
          $vals = implode( ', ', array_fill( 0, count( $value ), '%s' ));
          $update_sql .= ' AND ' . $field . ' in (' . $vals . ' ) ';
          $placeholders = array_merge($placeholders, $value);
        }
        else {
          $operator = isset($operators[$field]) ? $operators[$field] : '=';
          $update_sql .= ' AND ' . $field . ' = %s';
          $placeholders[] = $value;
        }
      }
      $update_sql = $wpdb->prepare($update_sql, $placeholders);

      $result = $wpdb->query($update_sql);
      return $result;
   }

   /**
    * Deletes rows of this queue by one selector.
    *
    * Exactly one of `status`, `item_id`, `items` or `all` is applied, in
    * that order of precedence. `all` must be passed explicitly as true to
    * wipe the queue; without any selector nothing is deleted. With
    * `check_safe` the table's existence is verified first, for the
    * uninstall path where the table may already be gone.
    *
    * @global \wpdb $wpdb
    *
    * @param array $args {
    *     @type int|null  $status     Delete rows with this status.
    *     @type int|null  $item_id    Delete the row with this item_id.
    *     @type int[]|null $items     Delete rows with these item_ids.
    *     @type bool      $all        True to delete every row of the queue.
    *     @type bool      $check_safe True to return false when the table
    *                                 does not exist.
    * }
    * @return int|false Rows deleted, or false without selector, missing
    *                   table (check_safe) or query error.
    */
   /** @todo Accept array or ItemIDS to remove
   * @param $args Array . Array of options:
   *  'Status' : remove items with selected ShortQ QSTATUS
   *  'All' : Set to true to remove all from this queue ( sep. argument is safety feature )
   * 'Item_id' : Delete by this item id
   * 'Items' : Array of Item ID's.
   */
   public function removeRecords($args)
   {
     $defaults = array(
        'status' => null,
        'all' => false,
        'item_id' => null,
        'items' => null,
        'check_safe' => false,
     );

     global $wpdb;
     $args = wp_parse_args($args, $defaults);

     if (true === $args['check_safe'])
     {
         if (false === $this->check())
         {
            return false;
         }
     }

     $data = array($this->qName, $this->slug);
     $delete_sql = 'DELETE FROM ' . $this->table . ' where queue_name = %s and plugin_slug = %s';

     if (! is_null($args['status']))
     {
        $data[] = intval($args['status']);
        $delete_sql .= ' and status = %s';
     }
     elseif (! is_null($args['item_id']))
     {
       $data[] = $args['item_id'];
       $delete_sql .= ' and item_id = %s';
     }
     elseif(! is_null($args['items']) && count($args['items']) > 0)
     {
       $items = $args['items'];
       $vals = implode( ', ', array_fill( 0, count( $items ), '%d' ));
       $delete_sql .= ' AND item_id in (' . $vals .  ' ) ';
       $data = array_merge($data, $items);
     }
     elseif ($args['all'] === true)
     {
        // do nothing, query already here for full delete.
     }
     else {
       return false; // prevent accidents if all is not set explicitly.
     }

     $delete_sql = $wpdb->prepare($delete_sql, $data);
     $result = $wpdb->query($delete_sql);
     return $result;
   }

   /**
    * Tells whether the table exists (SHOW TABLES LIKE).
    *
    * @global \wpdb $wpdb
    *
    * @return bool
    */
   /** Checks if database table properly exists
   * https://wordpress.stackexchange.com/questions/220275/wordpress-unit-testing-cannot-create-tables
   * @return Boolean Yes or no
   */
   private function check()
   {
     global $wpdb;
     $sql = $wpdb->prepare("
              SHOW TABLES LIKE %s
              ", $this->table);

      $result = intval($wpdb->query($sql));

      if ($result == 0)
        return false;
      else {
        return true;
      }
      // if something something, install.
   }

   /**
    * Creates or upgrades the table with dbDelta() and adds the unique key
    * `uq_{prefix}` on (plugin_slug, queue_name, item_id) when missing.
    *
    * dbDelta() is idempotent and adds columns that are missing from an
    * older table, which is why handleError() calls this on any failed
    * query.
    *
    * @global \wpdb $wpdb
    *
    * @param bool $nocheck False to skip the work when the table exists.
    * @return bool True when the table exists afterwards.
    */
   public function install($nocheck = false)
   {
     if ($nocheck === false && true === $this->check())
        return true;

     require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

     global $wpdb;
     $prefix = $wpdb->prefix;

     $charset = $wpdb->get_charset_collate();
      $sql = "CREATE TABLE `" . $this->table . "` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                queue_name VARCHAR(30) NOT NULL,
                plugin_slug VARCHAR(30) NOT NULL,
                status int(11) NOT NULL DEFAULT 0,
                list_order int(11) NOT NULL,
                item_id bigint unsigned NOT NULL,
                item_count INT DEFAULT 1,
                value longtext NOT NULL,
                tries int(11) NOT NULL DEFAULT 0,
                created timestamp ,
                updated timestamp,
                PRIMARY KEY  (id),
                KEY queue_name (queue_name),
                KEY plugin_slug (plugin_slug),
                KEY status (status),
                KEY item_id (item_id),
                KEY list_order (list_order)
                ) $charset; ";

			$result = dbDelta($sql);

      $sql = "SHOW INDEX FROM " . $this->table . " WHERE Key_name = 'uq_" . $prefix . "'";
      $result = $wpdb->get_results($sql);
      if (is_null($result) || count($result) == 0)
      {
         $sql = 'ALTER TABLE '. $this->table . ' ADD CONSTRAINT UNIQUE uq_' . $prefix . '(plugin_slug,queue_name,item_id)';
         $wpdb->query($sql);
      }

      return $this->check();
   }

   /**
    * Drops the table, but only when it holds no rows at all, across every
    * plugin and queue that shares it. Called from WPQ::unInstall() after
    * the queue's own rows were deleted.
    *
    * @global \wpdb $wpdb
    *
    * @return bool See the class @todo: false in every case except a failed
    *              drop.
    */
   public function uninstall()
   {
     global $wpdb;
     require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

     // Check if table exists, if not, return.
     if (! $this->check())
        return false;

     $sql = 'SELECT count(*) as cnt FROM ' . $this->table;
     $records = $wpdb->get_var($sql);


     // Don't remove table on any doubt.
     if (is_null($records) || intval($records) <> 0)
        return false;

     $sql = ' DROP TABLE IF EXISTS ' . $this->table;

     $wpdb->query($sql);

     return $this->check();
   }

   /**
    * Tells whether the last query succeeded; on failure runs the repair in
    * handleError().
    *
    * @global \wpdb $wpdb
    *
    * @param bool $override_check Passed to handleError().
    * @return bool
    */
   private function checkQueryOK($override_check = false)
   {
      global $wpdb;

      if (!empty($wpdb->last_error))
      {
        $this->handleError($wpdb->last_error, $override_check);
        return false;
      }

      return true;
   }

   /**
    * Repairs the table after a failed query.
    *
    * Installs the table when it is missing (unless $override_check),
    * forces a dbDelta() run when the error mentions something "unknown"
    * (typically a missing column), then runs install() once more. The
    * failed query itself is not retried and the error is not logged.
    *
    * @global \wpdb $wpdb
    *
    * @param string $error          wpdb error text.
    * @param bool   $override_check True to skip the table-existence check.
    * @return void
    *
    * @todo Errors are swallowed; nothing reaches the plugin log. Suggested
    *       fix: pass the error to the logger of the consuming plugin.
    */
   private function handleError($error, $override_check = false)
   {
     global $wpdb;

     // check if table is there.
     if (false === $override_check)
     {
       if (false === $this->check())
        $this->install();
     }

     // If the error contains something 'unknown' a field might be missing, do a hard DbDelta.
     if (strpos(strtolower($error), 'unknown') !== false)
     {
      $this->install(true);
     }

     $this->install();

     // @todo Add error log here
   }


}
