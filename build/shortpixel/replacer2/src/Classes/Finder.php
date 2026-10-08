<?php 
namespace EnableMediaReplace\Replacer\Classes; 


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}



class Finder 
{

		protected $callback; 
		protected $base_url;
		protected $return_data;

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

		public function postmeta($args = [])
		{
			global $wpdb;

			$defaults = [
				'post_fields' => ['post_id', 'meta_value'],
				'post_results' => ['post_id', 'content'],
				'post_ids' => [],
				'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
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

			$postmeta_sql = $wpdb->prepare($postmeta_sql, $prepare);
			$rs = $wpdb->get_results($postmeta_sql, \ARRAY_A);

			if (false === is_null($this->callback) && true === is_callable($this->callback)) {
				call_user_func_array($this->callback, ['results' => $rs, 'args' => $this->return_data]);
			}

			return $rs;
		}

		
		
    
}