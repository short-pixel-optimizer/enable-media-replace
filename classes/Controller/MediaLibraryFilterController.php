<?php
/**
 * The Bulk Enhance filter of the Media Library.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller;

if (! defined('ABSPATH')) {
	exit;
}

use EnableMediaReplace\Controller\Bulk\BulkQueue;
use EnableMediaReplace\Model\Bulk\BulkImage;

/**
 * Lets the Media Library show only the images a bulk operation processed:
 * a dropdown next to the date filter of the list view, the same dropdown in
 * the toolbar of the grid view and of the media modal (js/emr-media-filter.js),
 * and the query behind both.
 *
 * Port of ShortPixel Image Optimizer's status filter:
 * `ListMediaViewController::mediaAddFilterDropdown()` (the list dropdown),
 * `AdminController::filter_listener()` / `filter_add_where()` (the query,
 * caught on `pre_get_posts` so the grid's `query-attachments` AJAX request
 * is filtered too) and `res/js/shortpixel-media.js` (the grid dropdown).
 *
 * The filter reads the marker post meta of the operation
 * (BulkImage::getMarkerKey(), `_emr_bulk_background` / `_emr_bulk_upscale`),
 * the marker a bulk operation leaves and a restore removes, so a restored
 * image drops out of the list. One meta key per operation, so the query is
 * SPIO's `ID IN (SELECT post_id ... WHERE meta_key = %s)` form (its
 * `_shortpixel_prevent_optimize` filter).
 *
 * One difference from SPIO: a request does not need the list form's
 * `filter_action` field for the value to count, so a plain link such as
 * the finished panel's "View in Media Library" (getUrl()) filters as well.
 *
 * Lifecycle: getInstance() singleton; EnableMediaReplacePlugin::plugin_actions()
 * calls register() for users who may upload files.
 *
 * @hook action restrict_manage_posts addFilterDropdown()
 * @hook filter pre_get_posts         filterListener(), adds posts_where filterAddWhere() when a value is selected
 * @hook action wp_enqueue_media      enqueueGridFilter()
 */
class MediaLibraryFilterController
{
	/** @var static|null */
	protected static $instance;

	/** Query argument of the filter: 'all', 'background' or 'upscale'. */
	const QUERY_VAR = 'emr_bulk';

	/** The value that filters nothing. */
	const VALUE_ALL = 'all';

	/**
	 * @return static
	 */
	public static function getInstance()
	{
		if (is_null(static::$instance)) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * Hooks the dropdowns and the query.
	 *
	 * @return void
	 */
	public function register()
	{
		add_action('restrict_manage_posts', array($this, 'addFilterDropdown'));
		add_filter('pre_get_posts', array($this, 'filterListener'));
		add_action('wp_enqueue_media', array($this, 'enqueueGridFilter'));
	}

	/**
	 * The options of the dropdown, value to label.
	 *
	 * @return array<string, string>
	 */
	public function getFilters()
	{
		return array(
			self::VALUE_ALL               => __('Any Bulk Enhance state', 'enable-media-replace'),
			BulkQueue::OPERATION_BACKGROUND => __('Background removed in bulk', 'enable-media-replace'),
			BulkQueue::OPERATION_UPSCALE    => __('Upscaled in bulk', 'enable-media-replace'),
		);
	}

	/**
	 * The Media Library (list mode) filtered on an operation; the finished
	 * panel's "View in Media Library" link.
	 *
	 * @param string $operation 'background' or 'upscale'.
	 * @return string
	 */
	public function getUrl($operation)
	{
		return add_query_arg(array('mode' => 'list', self::QUERY_VAR => $operation), admin_url('upload.php'));
	}

	/**
	 * Prints the dropdown in the list view's toolbar (`restrict_manage_posts`),
	 * on the upload screen only.
	 *
	 * @return void
	 */
	public function addFilterDropdown()
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (null === $screen || 'upload' !== $screen->base) {
			return;
		}
		$selected = $this->selectedFilterValue();

		echo '<label for="' . esc_attr(self::QUERY_VAR) . '" class="screen-reader-text">' . esc_html__('Filter by Bulk Enhance state', 'enable-media-replace') . '</label>';
		echo '<select name="' . esc_attr(self::QUERY_VAR) . '" id="' . esc_attr(self::QUERY_VAR) . '">' . "\n";
		foreach ($this->getFilters() as $value => $label) {
			echo '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>' . "\n";
		}
		echo '</select>' . "\n";
	}

	/**
	 * The selected filter: the `query[emr_bulk]` of the grid's AJAX request,
	 * else the `emr_bulk` query argument. Unknown values count as 'all'.
	 *
	 * @return string
	 */
	public function selectedFilterValue()
	{
		$value = '';
		if (wp_doing_ajax()) {
			if (isset($_REQUEST['query'][ self::QUERY_VAR ])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter of the list.
				$value = sanitize_key(wp_unslash($_REQUEST['query'][ self::QUERY_VAR ])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		} elseif (isset($_REQUEST[ self::QUERY_VAR ])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = sanitize_key(wp_unslash($_REQUEST[ self::QUERY_VAR ])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return array_key_exists($value, $this->getFilters()) ? $value : self::VALUE_ALL;
	}

	/**
	 * `pre_get_posts`: on the Media Library page and the grid's AJAX request,
	 * attaches the WHERE clause when an operation is selected.
	 *
	 * @param \WP_Query $query
	 * @return \WP_Query
	 */
	public function filterListener($query)
	{
		global $pagenow;

		if (empty($query->query_vars['post_type']) || 'attachment' !== $query->query_vars['post_type']) {
			return $query;
		}
		if (! in_array($pagenow, array('upload.php', 'admin-ajax.php'), true)) {
			return $query;
		}
		if (self::VALUE_ALL === $this->selectedFilterValue()) {
			return $query;
		}
		add_filter('posts_where', array($this, 'filterAddWhere'), 10, 2);
		return $query;
	}

	/**
	 * `posts_where`: keeps the attachments whose bulk marker holds the
	 * selected operation.
	 *
	 * @param string    $where
	 * @param \WP_Query $query
	 * @return string
	 */
	public function filterAddWhere($where, $query)
	{
		global $wpdb;

		$operation = $this->selectedFilterValue();
		if (self::VALUE_ALL === $operation || 'attachment' !== $query->get('post_type')) {
			return $where;
		}
		$where .= $wpdb->prepare(
			" AND {$wpdb->posts}.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s)",
			BulkImage::getMarkerKey($operation)
		);
		return $where;
	}

	/**
	 * `wp_enqueue_media`: the grid and media modal dropdown (js/emr-media-filter.js),
	 * with the options localized as `emrMediaFilter`.
	 *
	 * @return void
	 */
	public function enqueueGridFilter()
	{
		wp_enqueue_script('emr_media_filter', plugins_url('js/emr-media-filter.js', EMR_ROOT_FILE), array('media-views'), EMR_VERSION, true);
		wp_localize_script('emr_media_filter', 'emrMediaFilter', array(
			'queryVar' => self::QUERY_VAR,
			'filters'  => $this->getFilters(),
		));
	}
}
