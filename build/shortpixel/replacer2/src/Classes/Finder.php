<?php
/**
 * Finder class of the newer search/replace API.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Classes;


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}



/**
 * Finds posts and post meta rows that contain a base URL.
 *
 * Each query returns the matching rows and, when a callback is set, also
 * hands them to it. A new object is created per use (Replacer::Finder());
 * the class fires and listens to no hooks.
 *
 * Callback contract: the callback is invoked through call_user_func_array()
 * with the string-keyed array `['results' => $rows, 'args' => $return_data]`.
 * On PHP 8 string keys are passed as named arguments, so the callback's
 * parameters must be named `$results` and `$args` (or be variadic); on
 * PHP 7 they are passed by position.
 */
class Finder
{

		/**
		 * Callable that receives the query results, or null.
		 *
		 * @var callable|null
		 */
		protected $callback;

		/**
		 * Fragment searched for with LIKE `%base_url%`. Not passed through
		 * esc_like().
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
				'callback' => null, // placeholder, should prolly communicate with replacer class 
				'base_url' => false, 
				'return_data' => [], 
			];
			
			$args = wp_parse_args($args, $defaults); 
			$this->callback = $args['callback'];
			$this->base_url = $args['base_url'];
			$this->return_data = $args['return_data'];
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
		 * @global \wpdb $wpdb
		 *
		 * @param array $args {
		 *     @type string[]        $post_fields  Columns to select. Default
		 *                                         `['ID', 'post_content']`.
		 *     @type string[]        $post_results Aliases, index-matched.
		 *                                         Default `['post_id', 'content']`.
		 *     @type int[]           $post_ids     Limit to these post IDs.
		 *                                         Default [] (no limit).
		 *     @type string|string[] $post_status  Statuses to include. Default
		 *                                         publish, future, draft,
		 *                                         pending, private.
		 * }
		 * @return array Result rows as associative arrays (ARRAY_A); the same
		 *               rows are passed to the callback when one is set.
		 */
		public function posts($args = [])
		{
			global $wpdb;

			$defaults = [
				'post_fields' => ['ID', 'post_content'], 
				'post_results' => ['post_id', 'content'], 
				'post_ids' => [], 
				'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
			]; 

			$args = wp_parse_args($args, $defaults);

			$base_url = $this->base_url;
			$prepare = []; 
			/* Search and replace in WP_POSTS */
			// Removed $wpdb->remove_placeholder_escape from here, not compatible with WP 4.8

			$post_statuses = is_array($args['post_status']) ? $args['post_status'] : [$args['post_status']];
			if (count($post_statuses) === 0) {
				$post_statuses = $defaults['post_status'];
			}

			$select = ''; 
			$i = 0; 
			foreach($args['post_fields'] as $index => $field)
			{
				if ($i > 0)
				{
					$select .= ','; 
				}
				$select .=  $field . ' '; 
				if (isset($args['post_results'][$index]))
				{
					$select .= ' as ' . $args['post_results'][$index]; 
				}
				$i++; 
			}

			$status_placeholders = implode(', ', array_fill(0, count($post_statuses), '%s'));
			$posts_sql = 
				"SELECT " . $select . "  FROM $wpdb->posts WHERE post_status IN ($status_placeholders)
					AND post_content LIKE %s"; 
				
			$prepare = array_merge($post_statuses, ['%' . $base_url . '%']);

			if (is_array($args['post_ids']) && count($args['post_ids']) > 0) {
				$post_ids = $args['post_ids']; 
				$placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

				$posts_sql .= " AND ID IN ($placeholders) "; 
				$prepare = array_merge($prepare, $post_ids);
			}

			$posts_sql = $wpdb->prepare($posts_sql, $prepare); 

	
			$rs = $wpdb->get_results($posts_sql, \ARRAY_A);
	

			// @todo before this filter results?  pass results to some worker
			if (false === is_null($this->callback) && true === is_callable($this->callback) )
			{
				call_user_func_array($this->callback, ['results' => $rs, 'args' => $this->return_data]);
			}

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
		 * @global \wpdb $wpdb
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
		 *                                             post. Default publish,
		 *                                             future, draft, pending,
		 *                                             private.
		 *     @type int[]           $exclude_post_ids Exclude these post IDs.
		 *                                             Default [].
		 * }
		 * @return array Result rows as associative arrays (ARRAY_A); the same
		 *               rows are passed to the callback when one is set.
		 */
		public function postmeta($args = [])
		{
			global $wpdb;

			$defaults = [
				'post_fields' => ['post_id', 'meta_value'],
				'post_results' => ['post_id', 'content'],
				'post_ids' => [],
				'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
				'exclude_post_ids' => [], 
			];

			$args = wp_parse_args($args, $defaults);

			$base_url = $this->base_url;
			$prepare = [];

			$post_statuses = is_array($args['post_status']) ? $args['post_status'] : [$args['post_status']];
			if (count($post_statuses) === 0) {
				$post_statuses = $defaults['post_status'];
			}

			$select = '';
			$i = 0;
			foreach ($args['post_fields'] as $index => $field) {
				if ($i > 0) {
					$select .= ',';
				}
				$select .= $field . ' ';
				if (isset($args['post_results'][$index])) {
					$select .= ' as ' . $args['post_results'][$index];
				}
				$i++;
			}

			$status_placeholders = implode(', ', array_fill(0, count($post_statuses), '%s'));
			$postmeta_sql =
				"SELECT " . $select . " FROM $wpdb->postmeta
					INNER JOIN $wpdb->posts ON $wpdb->posts.ID = $wpdb->postmeta.post_id
					WHERE $wpdb->posts.post_status IN ($status_placeholders)
					AND $wpdb->postmeta.meta_value LIKE %s";

			$prepare = array_merge($post_statuses, ['%' . $base_url . '%']);

			if (is_array($args['post_ids']) && count($args['post_ids']) > 0) {
				$post_ids = $args['post_ids'];
				$placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

				$postmeta_sql .= " AND $wpdb->postmeta.post_id IN ($placeholders)";
				$prepare = array_merge($prepare, $post_ids);
			}

			if (is_array($args['exclude_post_ids']) && count($args['exclude_post_ids']) > 0)
			{
				$exclude_post_ids = $args['exclude_post_ids'];
				$placeholders = implode(',', array_fill(0, count($exclude_post_ids), '%d'));

				$postmeta_sql .= " AND $wpdb->postmeta.post_id NOT IN ($placeholders)";
				$prepare = array_merge($prepare, $exclude_post_ids);
			}

			$postmeta_sql = $wpdb->prepare($postmeta_sql, $prepare);
			$rs = $wpdb->get_results($postmeta_sql, \ARRAY_A);

			if (false === is_null($this->callback) && true === is_callable($this->callback)) {
				call_user_func_array($this->callback, ['results' => $rs, 'args' => $this->return_data]);
			}

			return $rs;
		}

		
		
    
}