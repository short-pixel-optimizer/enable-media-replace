<?php
/**
 * Yoast SEO integration of the replacer module.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Modules;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

// Integration to reset indexes of Yoast  (used for Og:image) when something is converted.
/**
 * Deletes Yoast indexables that reference the replaced file.
 *
 * Yoast SEO caches the Open Graph and Twitter image URLs in its
 * `yoast_indexable` table. Deleting the matching rows makes Yoast rebuild
 * them with the new URL.
 *
 * Detection: the `WPSEO_VERSION` constant is defined.
 *
 * Lifecycle: singleton via getInstance(), created by
 * Replacer::loadFormats().
 *
 * Hooks listened to: action `shortpixel/replacer/replace_urls` ->
 * removeIndexes().
 */
class YoastSeo
{

	/**
	 * Full name of the Yoast indexable table (`{prefix}yoast_indexable`).
	 * Null when Yoast is not active.
	 *
	 * @var string|null
	 */
	private $yoastTable;

	/**
	 * Shared instance.
	 *
	 * @var YoastSeo|null
	 */
	private static $instance;

	/**
	 * Returns the shared instance, creating it on first use.
	 *
	 * @return YoastSeo
	 */
	public static function getInstance()
	{
			if (is_null(self::$instance))
				self::$instance = new YoastSeo();

			return self::$instance;
	}

	/**
	 * Sets the table name and registers the hook when Yoast is active.
	 *
	 * @global \wpdb $wpdb
	 *
	 * @hook action shortpixel/replacer/replace_urls removeIndexes(),
	 *       priority 10, 2 args.
	 */
	public function __construct()
	{
		if (true === $this->yoast_is_active())   // elementor is active
		{
			 global $wpdb;
			 $this->yoastTable = $wpdb->prefix . 'yoast_indexable';

			 add_action('emr/replacer/replace_urls', array($this, 'removeIndexes'),10,2);
		}
	}

	/**
	 * Deletes indexables whose social image matches the replaced file.
	 *
	 * Runs one DELETE for the `base` search entry and one for the `file`
	 * entry (each only when present), removing rows whose `twitter_image` or
	 * `open_graph_image` is LIKE `%value%`. Both entries are absent when the
	 * replace runs with `thumbnails_only`, or when the pair was dropped as
	 * identical, and then nothing is deleted. The action fires before the
	 * replacement queries run.
	 *
	 * @global \wpdb $wpdb
	 *
	 * @hook action shortpixel/replacer/replace_urls
	 *
	 * @param array $search_urls  Search array of the main run.
	 * @param array $replace_urls Replace array of the main run (unused).
	 * @return void
	 */
	public function removeIndexes($search_urls, $replace_urls)
	{
		 global $wpdb;

			$sql = 'DELETE FROM  ' . $this->yoastTable . ' WHERE ';
			$prepare = array();

			$base = isset($search_urls['base']) ? $search_urls['base'] : null;
			$file = isset($search_urls['file']) ? $search_urls['file'] : null;

			if (! is_null($base))
			{
						$querySQL = $sql . ' twitter_image like %s or open_graph_image like %s ';
						$querySQL = $wpdb->prepare($querySQL, '%' . $base . '%', '%' . $base . '%');

						$wpdb->query($querySQL);
			}

			if (! is_null($file))
			{
						$querySQL = $sql . ' twitter_image like %s or open_graph_image like %s ';
						$querySQL = $wpdb->prepare($querySQL, '%' . $file . '%', '%' . $file . '%');

						$wpdb->query($querySQL);
			}

	}

	/**
	 * Tells whether Yoast SEO is active.
	 *
	 * @return bool True when `WPSEO_VERSION` is defined.
	 */
	protected function yoast_is_active()
	{
		 if (defined('WPSEO_VERSION'))
		 {
			  return true;
		 }
		 return false;
	}




}
