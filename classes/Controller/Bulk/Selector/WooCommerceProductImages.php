<?php
/**
 * Bulk source: the images WooCommerce products use.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk\Selector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\Controller\Bulk\BulkQueue;

/**
 * Selects the featured image (`_thumbnail_id`) and the gallery images
 * (`_product_image_gallery`, a comma-separated list) of every WooCommerce
 * product, plus the featured image of every product variation.
 *
 * Products of any status are scanned except trashed and auto-draft ones.
 * The scan pages over the product posts, newest first, `enqueue_limit`
 * products per page; the attachment IDs of a page are returned de-duplicated.
 * An attachment shared by several products in different pages is caught by
 * the queue (already queued) or by its bulk marker (already processed).
 *
 * The selector is available when the `product` post type is registered,
 * which WooCommerce does on `init`; without WooCommerce every page is empty.
 *
 * Hooks:
 * - filter `emr/bulk/background/post_types` (array): the post types scanned (default product, product_variation).
 */
class WooCommerceProductImages extends Selector
{
	/** Post statuses that are never scanned. */
	const EXCLUDED_STATUSES = array('trash', 'auto-draft');

	/**
	 * @return bool
	 */
	public function isAvailable()
	{
		return post_type_exists('product');
	}

	/**
	 * @return string
	 */
	public function getUnavailableReason()
	{
		return $this->isAvailable() ? '' : __('WooCommerce is not active, so there are no product images to process.', 'enable-media-replace');
	}

	/**
	 * The post types scanned.
	 *
	 * @return string[]
	 */
	protected function getPostTypes()
	{
		$types = apply_filters('emr/bulk/background/post_types', array('product', 'product_variation'));
		return array_values(array_filter(array_map('sanitize_key', (array) $types)));
	}

	/**
	 * @param BulkQueue $queue
	 * @return int[]
	 */
	public function getIds(BulkQueue $queue)
	{
		global $wpdb;

		if (false === $this->isAvailable()) {
			return array();
		}

		$limit  = $this->limit($queue);
		$cursor = (int) $queue->getSelectorCursor();
		$types  = $this->getPostTypes();
		if (0 === count($types)) {
			return array();
		}

		$typeList   = implode(',', array_fill(0, count($types), '%s'));
		$statusList = implode(',', array_fill(0, count(self::EXCLUDED_STATUSES), '%s'));
		$prepare    = array_merge($types, self::EXCLUDED_STATUSES);
		$sql        = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($typeList) AND post_status NOT IN ($statusList)";
		if ($cursor > 0) {
			$sql      .= ' AND ID < %d';
			$prepare[] = $cursor;
		}
		$sql      .= ' ORDER BY ID DESC LIMIT %d';
		$prepare[] = $limit;

		$postIds = array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $prepare)));
		if (0 === count($postIds)) {
			return array();
		}

		$queue->setSelectorCursor(min($postIds));

		$idList = implode(',', $postIds);
		$rows   = $wpdb->get_results("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($idList) AND meta_key IN ('_thumbnail_id', '_product_image_gallery')");

		$ids = array();
		foreach ((array) $rows as $row) {
			foreach (explode(',', (string) $row->meta_value) as $value) {
				$id = (int) trim($value);
				if ($id > 0) {
					$ids[ $id ] = $id;
				}
			}
		}

		rsort($ids);
		return array_values($ids);
	}

	/**
	 * Number of products and variations that would be scanned.
	 *
	 * @return int
	 */
	public function countSource()
	{
		global $wpdb;
		if (false === $this->isAvailable()) {
			return 0;
		}
		$types = $this->getPostTypes();
		if (0 === count($types)) {
			return 0;
		}
		$typeList   = implode(',', array_fill(0, count($types), '%s'));
		$statusList = implode(',', array_fill(0, count(self::EXCLUDED_STATUSES), '%s'));
		$sql        = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($typeList) AND post_status NOT IN ($statusList)";
		return (int) $wpdb->get_var($wpdb->prepare($sql, array_merge($types, self::EXCLUDED_STATUSES)));
	}
}
