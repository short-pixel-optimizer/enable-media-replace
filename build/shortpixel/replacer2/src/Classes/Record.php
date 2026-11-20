<?php
/**
 * Record class of the newer search/replace API.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Classes;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * One database row found by the Finder, carried through the replace and
 * update steps.
 *
 * A record knows where it came from: the table key (`posts`, `postmeta`,
 * `commentmeta`, `termmeta`, `usermeta`, `options`, or a key added through
 * the `shortpixel/replacer/replacement_tables` filter), the table definition
 * (which column is the id, which holds the value, which holds the owning
 * object, which cache group to clear) and the raw row as selected. Updater
 * writes a record back using only that information, so a record built by an
 * integration for its own table is written the same way.
 *
 * Lifecycle: created by Finder::findRecords() (or by an integration through
 * the `shortpixel/replacer/records` filter), its content is replaced by
 * Replacer2::replaceRecord() and it is written by Updater::updateRecord().
 * Fires and listens to no hooks.
 *
 * Table definition keys:
 * - `id` (required): primary key column.
 * - `value` (required): column holding the content.
 * - `main_id` (optional): column of the owning object (post id, option name).
 * - `cache_group` (optional): object cache group keyed by the `main_id` value.
 * - `table_name` (optional): full table name, for tables that are not a
 *   `$wpdb` property. Without it the table is `$wpdb->{$table}`.
 */
class Record
{

	/**
	 * Table key, see the class description.
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Table definition, see the class description.
	 *
	 * @var array
	 */
	protected $definition;

	/**
	 * Row as selected, keyed by the original column names. Passed unchanged
	 * to the `load_meta_value` and `save_meta_value` filters.
	 *
	 * @var array
	 */
	protected $row;

	/**
	 * Content as loaded from the database.
	 *
	 * @var mixed
	 */
	protected $original_content;

	/**
	 * Content as it is now; differs from $original_content once replaced.
	 *
	 * @var mixed
	 */
	protected $content;

	/**
	 * True when a filter asked to leave this record alone.
	 *
	 * @var bool
	 */
	protected $skipped = false;

	/**
	 * Creates a record.
	 *
	 * @param string $table      Table key.
	 * @param array  $definition Table definition with at least `id` and
	 *                           `value`.
	 * @param array  $row        Row keyed by the original column names; must
	 *                           hold the `id` and `value` columns.
	 */
	public function __construct(string $table, array $definition, array $row)
	{
		$this->table = $table;
		$this->definition = $definition;
		$this->row = $row;

		$value_field = $definition['value'];
		$this->original_content = isset($row[$value_field]) ? $row[$value_field] : null;
		$this->content = $this->original_content;
	}

	/**
	 * Returns the table key.
	 *
	 * @return string
	 */
	public function getTable(): string
	{
		return $this->table;
	}

	/**
	 * Returns the table definition.
	 *
	 * @return array
	 */
	public function getDefinition(): array
	{
		return $this->definition;
	}

	/**
	 * Returns the raw row, keyed by the original column names.
	 *
	 * @return array
	 */
	public function getRow(): array
	{
		return $this->row;
	}

	/**
	 * Returns the primary key column name.
	 *
	 * @return string
	 */
	public function getIdField(): string
	{
		return $this->definition['id'];
	}

	/**
	 * Returns the content column name.
	 *
	 * @return string
	 */
	public function getValueField(): string
	{
		return $this->definition['value'];
	}

	/**
	 * Returns the primary key value of the row.
	 *
	 * @return int|string|null
	 */
	public function getId()
	{
		$field = $this->getIdField();
		return isset($this->row[$field]) ? $this->row[$field] : null;
	}

	/**
	 * Returns the owning object of the row (post id, option name, ...).
	 *
	 * @return int|string|null Null when the table has no `main_id`.
	 */
	public function getMainId()
	{
		if (! isset($this->definition['main_id'])) {
			return null;
		}
		$field = $this->definition['main_id'];
		return isset($this->row[$field]) ? $this->row[$field] : null;
	}

	/**
	 * Returns the object cache group of the owning object.
	 *
	 * @return string|null Null when the table defines none.
	 */
	public function getCacheGroup()
	{
		return isset($this->definition['cache_group']) ? $this->definition['cache_group'] : null;
	}

	/**
	 * Returns the full table name.
	 *
	 * @global \wpdb $wpdb
	 *
	 * @return string|null Null when the table key is not a `$wpdb` property
	 *                     and the definition has no `table_name`.
	 */
	public function getTableName()
	{
		return Finder::resolveTableName($this->table, $this->definition);
	}

	/**
	 * Returns the content as loaded from the database.
	 *
	 * @return mixed
	 */
	public function getOriginalContent()
	{
		return $this->original_content;
	}

	/**
	 * Returns the current content.
	 *
	 * @return mixed
	 */
	public function getContent()
	{
		return $this->content;
	}

	/**
	 * Sets the replaced content.
	 *
	 * @param mixed $content New content.
	 * @return void
	 */
	public function setContent($content)
	{
		$this->content = $content;
	}

	/**
	 * Tells whether the content differs from what was loaded.
	 *
	 * @return bool
	 */
	public function isChanged(): bool
	{
		return false === $this->skipped && $this->content !== $this->original_content;
	}

	/**
	 * Marks the record to be left untouched.
	 *
	 * @return void
	 */
	public function skip()
	{
		$this->skipped = true;
	}

	/**
	 * Tells whether the record was marked to be left untouched.
	 *
	 * @return bool
	 */
	public function isSkipped(): bool
	{
		return $this->skipped;
	}
}
