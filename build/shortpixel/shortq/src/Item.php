<?php
/**
 * Queue item class of the ShortQ module.
 *
 * @package EnableMediaReplace\ShortQ
 */
namespace EnableMediaReplace\ShortQ;

/**
 * One record of a queue.
 *
 * An Item maps one-to-one onto a row of the data provider's storage; the
 * property names equal the column names so MysqlDataProvider can hydrate an
 * Item by assigning each column. Consumers receive Items from
 * WPQ::dequeue() / WPQ::getItem() and hand them back to WPQ::itemDone(),
 * WPQ::itemFailed() and WPQ::updateItemValue(). The two fields a consumer
 * cares about are `item_id` (the consumer's own identifier, for example an
 * attachment ID) and `value` (an arbitrary payload).
 *
 * All properties are protected and reached through __get() / __set(), which
 * add two conversions:
 * - `value`: arrays and objects are stored JSON-encoded inside a wrapper
 *   `{was_array: bool, value: ...}` and decoded back on read, so the
 *   original type (array or stdClass) survives a round trip through the
 *   `longtext` column. Scalars are stored as-is. getRaw('value') returns the
 *   stored (encoded) form for database writes.
 * - `created` / `updated`: MySQL `Y-m-d H:i:s` strings are converted to Unix
 *   timestamps on write.
 *
 * The class defines no __isset(), so `isset($item->prop)` from outside the
 * class is always false; use `is_null($item->prop)` or getRaw() instead.
 *
 * Fires and listens to no hooks.
 *
 * @todo __get() decides whether `value` is wrapped JSON with isJson(), which
 *       accepts any valid JSON text. A plain string value that happens to be
 *       valid JSON (for example `true`, `null`, `"text"` or `[1,2]`) is then
 *       read as a wrapper object and comes back as null. Suggested fix:
 *       after decoding, require an object with a `was_array` property before
 *       treating the value as wrapped.
 * @todo __set() converts `created`/`updated` with
 *       \DateTime::createFromFormat() and calls format() on the result
 *       without checking for false, so a string in another format is a
 *       fatal error. Suggested fix: fall back to strtotime() or keep the raw
 *       value when parsing fails.
 */
class Item
{

  /**
   * Primary key of the storage row. Null for items that are not saved yet.
   *
   * @var int|null
   */
  protected $id;

  /**
   * Creation time as a Unix timestamp.
   *
   * @var int|null
   */
  protected $created; // as a timestamp

  /**
   * Time of the last status or value change as a Unix timestamp.
   *
   * @var int|null
   */
  protected $updated; // as a timestamp

  /**
   * The consumer's identifier of the work unit (for example an attachment
   * ID). Unique per plugin slug and queue name in storage.
   *
   * @var int|null
   */
  protected $item_id; // the item id of the processor.

  /**
   * Consumer payload. Held JSON-encoded when it was set to an array or
   * object; see the class description.
   *
   * @var mixed
   */
  protected $value; // something of value to the processor

  /**
   * Number of work units this record stands for, summed by itemSum().
   *
   * @var int
   */
  protected $item_count = 1; // Amount of items this record represents.

  /**
   * True when the encoded `value` was an array, false when it was an
   * object, null when it is a scalar. Set by __set() and refreshed by
   * __get().
   *
   * @var bool|null
   */
  protected $json_was_array;

  /**
   * One of the ShortQ::QSTATUS_* constants.
   *
   * @var int
   */
  protected $status = 0;

  /**
   * Sort key for dequeueing; lower runs first. Values below 10 are treated
   * as priority items by WPQ::dequeue().
   *
   * @var int|null
   */
  protected $list_order;

  /**
   * Number of failed or timed-out attempts so far.
   *
   * @var int
   */
  protected $tries = 0;

  /**
   * Creates an empty item; properties are assigned afterwards.
   */
  public function __construct()
  {

  }

  /**
   * Returns a property without the conversions of __get().
   *
   * Used for the stored form of `value` when writing to the database.
   *
   * @param string $name Property name.
   * @return mixed The raw value, or null when unset.
   */
  // Without magic conversion. Used for methods such as database insertion of value
  public function getRaw($name)
  {
     if (isset($this->$name))
      return $this->$name;

    return null;
  }

  /**
   * Reads a property, decoding `value` and `json_was_array` from the JSON
   * wrapper when the stored value is JSON.
   *
   * @param string $name Property name.
   * @return mixed The value, or null when unset or unknown.
   */
  public function __get($name)
  {
    $value= null;

     switch($name)
     {
       case 'value':
       case 'json_was_array':
          if ($this->isJson($this->value))
          {
            $jsonObj = json_decode($this->value);
            $this->json_was_array = $jsonObj->was_array;

            if ($name == 'value')
            {
              if ($this->json_was_array)  // since it's being set after decode, redo this.
              {   $json_array = json_decode($this->value, $this->json_was_array);
                  $value = $json_array['value'];
              }
              else
              {
                $value = $jsonObj->value;
              }
            }
            elseif($name = 'json_was_array') // this is an internal item that normally shouldn't be requested.
              $value = $jsonObj->was_array;
          }
          elseif (isset($this->$name))
              $value = $this->$name;
       break;
       default:
       {
         if (isset($this->$name))
            $value = $this->$name;
       }
     }

    return $value;
  }

  /**
   * Writes a property, encoding array/object values and converting MySQL
   * date strings to timestamps. Unknown names are ignored.
   *
   * @param string $name  Property name.
   * @param mixed  $value New value.
   * @return $this
   */
  public function __set($name, $value)
  {
      switch($name)
      {
         case 'created':
         case 'updated':
            if (! is_numeric($value))
             {
               $dateObj = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
               $value = $dateObj->format('U');
             }
            $this->$name = $value;
         break;
         case 'value':
            if (is_array($value) || is_object($value))
            {
              $this->json_was_array = (is_array($value)) ? true : false;
              $jsonObj = new \stdClass;
              $jsonObj->was_array = $this->json_was_array;
              $jsonObj->value = $value;

              $value = json_encode($jsonObj, JSON_UNESCAPED_UNICODE);
            }

            $this->$name = $value;
         break;
         default:
            if (property_exists($this, $name))
              $this->$name = $value;
         break;
      }

      return $this;
  }

  /**
   * Tells whether a stored value is JSON text. Numbers are excluded because
   * they decode without error but are not wrapped values.
   *
   * @param mixed $value Stored value.
   * @return bool
   */
  private function isJson($value)
  {
    if (is_int($value) || is_numeric($value)) // solo-integer is not a json but will validate.
      return false;

    json_decode($value);
    return (json_last_error() == JSON_ERROR_NONE);
  }

}
