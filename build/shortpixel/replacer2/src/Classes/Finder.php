<?php
/**
 * Finder class of the replacer module.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Classes;


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Finds database rows that contain a base URL.
 *
 * Three queries, one per kind of storage:
 * - posts():    `post_content` of the posts table;
 * - postmeta(): `meta_value` of the post meta table, limited by post status;
 * - tables():   every other table of getTableDefinitions() (comment, term
 *               and user meta, options, and tables added by integrations).
 *
 * posts() and postmeta() return plain rows in a caller-chosen shape.
 * tables() returns plain rows grouped by table key. findRecords() runs all
 * three and wraps every row in a Record, which tells Updater which table and
 * row it came from; that is the entry point Replacer2::replace() uses.
 *
 * A new object is created per use (Replacer2::Finder()).
 *
 * Callback contract: every public query hands its result to the callback,
 * invoked through call_user_func_array() with the string-keyed array
 * `['results' => $result, 'args' => $return_data]`. On PHP 8 string keys are
 * passed as named arguments, so the callback's parameters must be named
 * `$results` and `$args` (or be variadic); on PHP 7 they are passed by
 * position.
 *
 * Hooks fired by this class:
 * - filter `shortpixel/replacer/replacement_tables` (getTableDefinitions())
 *
 * Bundling: the namespace and the `shortpixel/replacer/*` hook prefix are
 * rewritten per plugin by the build tool.
 */
class Finder
{

		/**
		 * Post statuses searched when the caller passes none.
		 *
		 * @var string[]
		 */
		const DEFAULT_POST_STATUS = ['publish', 'future', 'draft', 'pending', 'private'];

		/**
		 * Callable that receives the query results, or null.
		 *
		 * @var callable|null
		 */
		protected $callback;

		/**
		 * Fragment searched for with LIKE `%base_url%`. Not passed through
		 * esc_like(): integrations pass a fragment that is already escaped.
		 *
		 * @var string|false
		 */
		protected $base_url;

		/**
		 * Opaque data passed to the callback as `args`.
		 *
		 * @var mixed
		 */
		protected $return_data;

		/**
		 * Creates a finder.
		 *
		 * @param array $args {
		 *     @type callable|null $callback    Receives the results of every
		 *                                      query. Default null.
		 *     @type string|false  $base_url    Fragment to search for. Default
		 *                                      false.
		 *     @type mixed         $return_data Passed to the callback as
		 *                                      `args`. Default [].
		 * }
		 */
		public function __construct($args = [])
		{

			$defaults = [
				'callback' => null,
				'base_url' => false,
				'return_data' => [],
			];

			$args = wp_parse_args($args, $defaults);
			$this->callback = $args['callback'];
			$this->base_url = $args['base_url'];
			$this->return_data = $args['return_data'];
		}

		/**
		 * Finds every row containing the base URL, as Records.
		 *
		 * Runs the posts, post meta and other-table queries and wraps each row
		 * in a Record that carries its table key and table definition. Rows are
		 * selected with their original column names, so integration filters
		 * that read `meta_id` / `post_id` from the row keep working.
		 *
		 * @param array $args {
		 *     @type string[]        $sources     Which queries to run: `posts`,
		 *                                        `postmeta`, `tables`. Default
		 *                                        all three.
		 *     @type string|string[] $post_status Statuses for the posts and
		 *                                        post meta queries. Default
		 *                                        DEFAULT_POST_STATUS.
		 *     @type string[]        $tables      Limit the `tables` query to
		 *                                        these table keys. Default []
		 *                                        (all).
		 * }
		 * @return Record[] The records; the same array is passed to the
		 *                  callback when one is set.
		 */
		public function findRecords($args = []): array
		{
			$defaults = [
				'sources' => ['posts', 'postmeta', 'tables'],
				'post_status' => self::DEFAULT_POST_STATUS,
				'tables' => [],
			];
			$args = wp_parse_args($args, $defaults);
			$sources = (array) $args['sources'];

			$definitions = $this->getTableDefinitions();
			$records = [];

			if (in_array('posts', $sources, true)) {
				$rows = $this->queryPosts([
					'post_fields' => ['ID', 'post_content'],
					'post_results' => [],
					'post_status' => $args['post_status'],
				]);
				$records = array_merge($records, $this->toRecords('posts', $definitions['posts'], $rows));
			}

			if (in_array('postmeta', $sources, true) && isset($definitions['postmeta'])) {
				$definition = $definitions['postmeta'];
				$rows = $this->queryPostMeta([
					'post_fields' => $this->getSelectColumns($definition),
					'post_results' => [],
					'post_status' => $args['post_status'],
				]);
				$records = array_merge($records, $this->toRecords('postmeta', $definition, $rows));
			}

			if (in_array('tables', $sources, true)) {
				foreach ($this->queryTables($args['tables']) as $table => $rows) {
					$records = array_merge($records, $this->toRecords($table, $definitions[$table], $rows));
				}
			}

			$this->runCallback($records);
			return $records;
		}

		/**
		 * Finds posts whose content contains the base URL.
		 *
		 * SQL: `SELECT <fields> FROM posts WHERE post_status IN (...) AND
		 * post_content LIKE %base_url%`, plus `AND ID IN (...)` when
		 * `post_ids` is given. Each field is selected `AS` the alias at the
		 * same index of `post_results` when there is one. Field names and
		 * aliases are concatenated into the SQL unescaped, so they must be
		 * trusted column names. An empty status list falls back to the
		 * default statuses.
		 *
		 * @param array $args {
		 *     @type string[]        $post_fields  Columns to select. Default
		 *                                         `['ID', 'post_content']`.
		 *     @type string[]        $post_results Aliases, index-matched.
		 *                                         Default `['post_id', 'content']`.
		 *     @type int[]           $post_ids     Limit to these post IDs.
		 *                                         Default [] (no limit).
		 *     @type string|string[] $post_status  Statuses to include. Default
		 *                                         DEFAULT_POST_STATUS.
		 * }
		 * @return array Result rows as associative arrays (ARRAY_A); the same
		 *               rows are passed to the callback when one is set.
		 */
		public function posts($args = [])
		{
			$rs = $this->queryPosts($args);
			$this->runCallback($rs);
			return $rs;
		}

		/**
		 * Finds post meta rows whose value contains the base URL.
		 *
		 * SQL: `SELECT <fields> FROM postmeta INNER JOIN posts ON posts.ID =
		 * postmeta.post_id WHERE posts.post_status IN (...) AND
		 * postmeta.meta_value LIKE %base_url%`, plus `AND postmeta.post_id
		 * IN (...)` for `post_ids` and `AND postmeta.post_id NOT IN (...)`
		 * for `exclude_post_ids`. Fields and aliases are built as in posts()
		 * and are not qualified with a table name, so a column that exists in
		 * both tables must be passed qualified.
		 *
		 * @param array $args {
		 *     @type string[]        $post_fields      Columns to select.
		 *                                             Default
		 *                                             `['post_id', 'meta_value']`.
		 *     @type string[]        $post_results     Aliases, index-matched.
		 *                                             Default `['post_id', 'content']`.
		 *     @type int[]           $post_ids         Limit to these post IDs.
		 *                                             Default [].
		 *     @type string|string[] $post_status      Statuses of the parent
		 *                                             post. Default
		 *                                             DEFAULT_POST_STATUS.
		 *     @type int[]           $exclude_post_ids Exclude these post IDs.
		 *                                             Default [].
		 * }
		 * @return array Result rows as associative arrays (ARRAY_A); the same
		 *               rows are passed to the callback when one is set.
		 */
		public function postmeta($args = [])
		{
			$rs = $this->queryPostMeta($args);
			$this->runCallback($rs);
			return $rs;
		}

		/**
		 * Finds rows containing the base URL in every table that is not the
		 * posts or post meta table.
		 *
		 * Queries each table of getTableDefinitions() except `posts` and
		 * `postmeta` (those have posts() and postmeta()) with
		 * `SELECT <id>, <value>[, <main_id>] FROM <table> WHERE <value> LIKE
		 * %base_url%`. Columns keep their original names. A table whose name
		 * cannot be resolved or whose column names are not plain identifiers
		 * is skipped with a logged error.
		 *
		 * @param array $args {
		 *     @type string[] $tables Limit to these table keys. Default []
		 *                            (all).
		 * }
		 * @return array Rows (ARRAY_A) grouped by table key, for example
		 *               `['options' => [...], 'usermeta' => [...]]`. Tables
		 *               without matches are left out. The same array is passed
		 *               to the callback when one is set.
		 */
		public function tables($args = [])
		{
			$args = wp_parse_args($args, ['tables' => []]);
			$result = $this->queryTables($args['tables']);
			$this->runCallback($result);
			return $result;
		}

		/**
		 * Returns the definitions of the tables to search, keyed by table key.
		 *
		 * `posts` is fixed. The meta tables and `options` pass through the
		 * `shortpixel/replacer/replacement_tables` filter, so integrations can
		 * change them or add their own tables (with `table_name` when the
		 * table is not a `$wpdb` property). See Record for the definition
		 * keys.
		 *
		 * @hook filter shortpixel/replacer/replacement_tables Receives and
		 *       returns the table definitions, keyed by table key.
		 *
		 * @return array Table definitions keyed by table key.
		 */
		public function getTableDefinitions(): array
		{
			$tables = [
				'postmeta' => [
					'id' => 'meta_id',
					'value' => 'meta_value',
					'main_id' => 'post_id',
					'cache_group' => 'post_meta',
				],
				'commentmeta' => [
					'id' => 'meta_id',
					'value' => 'meta_value',
					'main_id' => 'comment_id',
					'cache_group' => 'comment_meta',
				],
				'termmeta' => [
					'id' => 'meta_id',
					'value' => 'meta_value',
					'main_id' => 'term_id',
					'cache_group' => 'term_meta',
				],
				'usermeta' => [
					'id' => 'umeta_id',
					'value' => 'meta_value',
					'main_id' => 'user_id',
					'cache_group' => 'user_meta',
				],
				'options' => [
					'id' => 'option_id',
					'value' => 'option_value',
					'main_id' => 'option_name',
				],
			];

			$tables = apply_filters('emr/replacer/replacement_tables', $tables);

			// Posts are not filterable: their update path is fixed in Updater.
			$tables['posts'] = [
				'id' => 'ID',
				'value' => 'post_content',
				'main_id' => 'ID',
			];

			return $tables;
		}

		/**
		 * Resolves the full table name of a table key.
		 *
		 * @global \wpdb $wpdb
		 *
		 * @param string $table      Table key.
		 * @param array  $definition Table definition.
		 * @return string|null `table_name` from the definition, else
		 *                     `$wpdb->{$table}`, else null.
		 */
		public static function resolveTableName(string $table, array $definition)
		{
			global $wpdb;

			if (isset($definition['table_name'])) {
				return $definition['table_name'];
			}
			if (isset($wpdb->{$table}) && is_string($wpdb->{$table})) {
				return $wpdb->{$table};
			}
			return null;
		}

		/**
		 * Runs the posts query without the callback, see posts().
		 *
		 * @global \wpdb $wpdb
		 *
		 * @param array $args See posts().
		 * @return array Result rows (ARRAY_A).
		 */
		protected function queryPosts($args = [])
		{
			global $wpdb;

			$defaults = [
				'post_fields' => ['ID', 'post_content'],
				'post_results' => ['post_id', 'content'],
				'post_ids' => [],
				'post_status' => self::DEFAULT_POST_STATUS,
			];
			$args = wp_parse_args($args, $defaults);

			$post_statuses = $this->getPostStatuses($args['post_status']);
			$select = $this->buildSelect($args['post_fields'], $args['post_results']);

			$status_placeholders = implode(', ', array_fill(0, count($post_statuses), '%s'));
			$posts_sql =
				"SELECT " . $select . "  FROM $wpdb->posts WHERE post_status IN ($status_placeholders)
					AND post_content LIKE %s";

			$prepare = array_merge($post_statuses, ['%' . $this->base_url . '%']);

			if (is_array($args['post_ids']) && count($args['post_ids']) > 0) {
				$post_ids = $args['post_ids'];
				$placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

				$posts_sql .= " AND ID IN ($placeholders) ";
				$prepare = array_merge($prepare, $post_ids);
			}

			$posts_sql = $wpdb->prepare($posts_sql, $prepare);
			return $wpdb->get_results($posts_sql, \ARRAY_A);
		}

		/**
		 * Runs the post meta query without the callback, see postmeta().
		 *
		 * @global \wpdb $wpdb
		 *
		 * @param array $args See postmeta().
		 * @return array Result rows (ARRAY_A).
		 */
		protected function queryPostMeta($args = [])
		{
			global $wpdb;

			$defaults = [
				'post_fields' => ['post_id', 'meta_value'],
				'post_results' => ['post_id', 'content'],
				'post_ids' => [],
				'post_status' => self::DEFAULT_POST_STATUS,
				'exclude_post_ids' => [],
			];
			$args = wp_parse_args($args, $defaults);

			$post_statuses = $this->getPostStatuses($args['post_status']);
			$select = $this->buildSelect($args['post_fields'], $args['post_results']);

			$status_placeholders = implode(', ', array_fill(0, count($post_statuses), '%s'));
			$postmeta_sql =
				"SELECT " . $select . " FROM $wpdb->postmeta
					INNER JOIN $wpdb->posts ON $wpdb->posts.ID = $wpdb->postmeta.post_id
					WHERE $wpdb->posts.post_status IN ($status_placeholders)
					AND $wpdb->postmeta.meta_value LIKE %s";

			$prepare = array_merge($post_statuses, ['%' . $this->base_url . '%']);

			if (is_array($args['post_ids']) && count($args['post_ids']) > 0) {
				$post_ids = $args['post_ids'];
				$placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

				$postmeta_sql .= " AND $wpdb->postmeta.post_id IN ($placeholders)";
				$prepare = array_merge($prepare, $post_ids);
			}

			if (is_array($args['exclude_post_ids']) && count($args['exclude_post_ids']) > 0) {
				$exclude_post_ids = $args['exclude_post_ids'];
				$placeholders = implode(',', array_fill(0, count($exclude_post_ids), '%d'));

				$postmeta_sql .= " AND $wpdb->postmeta.post_id NOT IN ($placeholders)";
				$prepare = array_merge($prepare, $exclude_post_ids);
			}

			$postmeta_sql = $wpdb->prepare($postmeta_sql, $prepare);
			return $wpdb->get_results($postmeta_sql, \ARRAY_A);
		}

		/**
		 * Runs the other-table queries without the callback, see tables().
		 *
		 * @global \wpdb $wpdb
		 *
		 * @param string[] $only Limit to these table keys; [] for all.
		 * @return array Rows grouped by table key; tables without matches are
		 *               left out.
		 */
		protected function queryTables($only = [])
		{
			global $wpdb;

			$result = [];
			$only = (array) $only;

			foreach ($this->getTableDefinitions() as $table => $definition) {
				if ('posts' === $table || 'postmeta' === $table) {
					continue; // Own queries: posts() and postmeta().
				}
				if (count($only) > 0 && ! in_array($table, $only, true)) {
					continue;
				}

				$table_name = self::resolveTableName($table, $definition);
				$columns = $this->getSelectColumns($definition);

				if (null === $table_name || false === $columns) {
					Log::addError('Replacer: skipping table with invalid definition ' . $table, $definition);
					continue;
				}

				$value_field = $definition['value'];
				$sql = 'SELECT ' . implode(', ', $columns) . " FROM `$table_name` WHERE `$value_field` LIKE %s";
				$rows = $wpdb->get_results($wpdb->prepare($sql, '%' . $this->base_url . '%'), \ARRAY_A);

				if (! empty($rows)) {
					$result[$table] = $rows;
				}
			}

			return $result;
		}

		/**
		 * Returns the columns to select for a table definition.
		 *
		 * @param array $definition Table definition.
		 * @return string[]|false `id`, `value` and, when set, `main_id`
		 *                        (once each); false when `id` or `value` is
		 *                        missing or a column is not a plain
		 *                        identifier.
		 */
		protected function getSelectColumns(array $definition)
		{
			if (! isset($definition['id']) || ! isset($definition['value'])) {
				return false;
			}

			$columns = [$definition['id'], $definition['value']];
			if (isset($definition['main_id'])) {
				$columns[] = $definition['main_id'];
			}

			foreach ($columns as $column) {
				if (! is_string($column) || 1 !== preg_match('/^[A-Za-z0-9_]+$/', $column)) {
					return false;
				}
			}

			return array_values(array_unique($columns));
		}

		/**
		 * Builds the SELECT list for posts() and postmeta().
		 *
		 * @param string[] $fields  Columns.
		 * @param string[] $aliases Aliases, index-matched; missing ones mean
		 *                          no alias.
		 * @return string SELECT list.
		 */
		protected function buildSelect($fields, $aliases)
		{
			$parts = [];
			foreach ($fields as $index => $field) {
				$part = $field . ' ';
				if (isset($aliases[$index])) {
					$part .= ' as ' . $aliases[$index];
				}
				$parts[] = $part;
			}
			return implode(',', $parts);
		}

		/**
		 * Normalises a post status argument.
		 *
		 * @param string|string[] $post_status One status or a list.
		 * @return string[] The statuses; DEFAULT_POST_STATUS when empty.
		 */
		protected function getPostStatuses($post_status)
		{
			$post_statuses = is_array($post_status) ? $post_status : [$post_status];
			if (count($post_statuses) === 0) {
				$post_statuses = self::DEFAULT_POST_STATUS;
			}
			return $post_statuses;
		}

		/**
		 * Wraps rows of one table in Records.
		 *
		 * @param string $table      Table key.
		 * @param array  $definition Table definition.
		 * @param array  $rows       Rows keyed by original column names.
		 * @return Record[]
		 */
		protected function toRecords(string $table, array $definition, $rows): array
		{
			$records = [];
			if (! is_array($rows)) {
				return $records;
			}
			foreach ($rows as $row) {
				$records[] = new Record($table, $definition, $row);
			}
			return $records;
		}

		/**
		 * Hands a result to the callback, when one is set.
		 *
		 * @param mixed $results Query result.
		 * @return void
		 */
		protected function runCallback($results)
		{
			if (false === is_null($this->callback) && true === is_callable($this->callback)) {
				call_user_func_array($this->callback, ['results' => $results, 'args' => $this->return_data]);
			}
		}

}
