<?php
/**
 * Replacer module entry point.
 *
 * Holds the Replacer class, which rewrites every reference to one media file
 * (main file and thumbnail sizes) into references to another file across the
 * WordPress database.
 *
 * @package EnableMediaReplace\Replacer
 */

namespace EnableMediaReplace\Replacer;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use Helper\Functional;
use EnableMediaReplace\Replacer\Classes\Finder;
use EnableMediaReplace\Replacer\Classes\Setup;
use EnableMediaReplace\Replacer\Classes\Updater;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Replacer\Libraries\Unserialize\Unserialize;

/** Module: Replacer.
 *
 * - Able to replace across database
 * - Only replace thumbnails feature dependent on media library
 * - Support for page builders / strange data
 *
 * Search-and-replace engine for media URLs. The caller sets a source URL and
 * a target URL, optionally the attachment metadata of both (so thumbnail
 * sizes are replaced as well) and optional extra URL pairs, then calls
 * replace(). replace() builds balanced search/replace arrays of relative URL
 * paths and rewrites them in `post_content`, in the post, comment, term and
 * user meta tables and in the options table, followed by any additional runs
 * that integrations register (page builders that store URLs escaped,
 * urlencoded or through their own save API).
 *
 * replaceContent() is public and is also used directly by consumers and by
 * the integration modules; it understands plain strings, serialized data,
 * JSON, arrays and objects.
 *
 * Lifecycle: getInstance() returns a shared instance, but the constructor is
 * public and consumers may also create their own instances. Every
 * construction calls loadFormats(), which instantiates the integration
 * modules; those are singletons, so their hooks are registered only once per
 * request. Source, target, metadata, extra URL pairs and replace settings are
 * instance state and are not reset after replace().
 *
 * Classes\Setup, Classes\Finder and Classes\Updater form a newer API that is
 * reachable through Setup(), Finder() and Updater(); replace() does not use
 * them.
 *
 * Bundling: this source is written with the ShortPixel namespace, the
 * ShortPixel text domain and the `shortpixel/replacer/*`,
 * `shortpixel/externals/*` and `shortpixel/file/*` hook prefixes. The build
 * tool that bundles the module into a plugin rewrites the namespace, the text
 * domain and these hook prefixes mechanically, so each consuming plugin (for
 * example Enable Media Replace) sees its own namespace and its own hook
 * prefix. Hook names in these docblocks are given in the source's terms.
 *
 * Hooks fired by this class:
 * - action `shortpixel/replacer/replace_urls` (replace())
 * - filter `shortpixel/replacer/custom_replace_query` (replace())
 * - filter `shortpixel/replacer/replacement_tables` (handleMetaData())
 * - filter `shortpixel/replacer/load_meta_value` (handleMetaData())
 * - filter `shortpixel/replacer/save_meta_value` (handleMetaData())
 * - filter `shortpixel/replacer/content` (replaceContent())
 * - filter `shortpixel/file/basedir_check` (fileIsRestricted())
 *
 * @todo The extra URL pairs from addURLArray() accumulate in $search_urls and
 *       $replace_urls and are never cleared, so on the shared instance from
 *       getInstance() a second replace() in the same request reuses the pairs
 *       of the first one. Suggested fix: clear both arrays at the end of
 *       replace() or add a reset method.
 */

class Replacer
{

	/**
	 * Full URL of the file being replaced (the old file).
	 *
	 * @var string|null
	 */
	protected $source_url;

	/**
	 * Full URL of the replacement file (the new file).
	 *
	 * @var string|null
	 */
	protected $target_url;

	/**
	 * Attachment metadata of the source file, as stored in
	 * `_wp_attachment_metadata` (keys used: `file`, `width`, `sizes`).
	 *
	 * @var array
	 */
	protected $source_metadata = [];

	/**
	 * Attachment metadata of the target file, same shape as $source_metadata.
	 *
	 * @var array
	 */
	protected $target_metadata = [];

	/**
	 * Extra search URLs added through addURLArray(), merged into the
	 * generated search array by replace().
	 *
	 * @var array
	 */
	protected $search_urls = [];

	/**
	 * Extra replace URLs added through addURLArray(), merged into the
	 * generated replace array by replace().
	 *
	 * @var array
	 */
	protected $replace_urls = [];

	/**
	 * Shared instance returned by getInstance().
	 *
	 * @var Replacer|null
	 */
	protected static $instance;

	/**
	 * Defaults for the replace settings, see setReplaceSettings().
	 *
	 * @var array
	 */
	private $default_replace_settings = array(
		'component' => 'unset',
		'json_flags' => JSON_UNESCAPED_SLASHES,
		'replacer_do_save' => true,
		'replace_no_serialize' => false,
	);

	/**
	 * Settings of the current replace run, see setReplaceSettings(). Null
	 * until the first run or the first replaceContent() call.
	 *
	 * @var array|null
	 */
	private $replace_settings;

	/**
	 * Creates a replacer and loads the integration modules.
	 */
	public function __construct()
	{
		$this->loadFormats();
	}

	/**
	 * Returns the shared instance, creating it on first use.
	 *
	 * @return Replacer
	 */
	public static function getInstance()
	{
		 if (is_null(self::$instance))
		 {
			 self::$instance = new Replacer(); 
		 }

		 return self::$instance; 
	}

	// Load classes that handle alternative formats that can occur in the metadata / post data.
	/**
	 * Instantiates the five integration modules.
	 *
	 * Elementor, WpBakery, YoastSeo, Breakdance and SmartSlider are singletons;
	 * each one checks in its constructor whether its plugin is active and only
	 * then registers its hooks. SmartSlider receives this replacer instance
	 * (only the first instance passed is kept, and the module is inert, see
	 * Modules\SmartSlider).
	 *
	 * @return void
	 */
	protected function loadFormats()
	{
		Modules\Elementor::getInstance();
		Modules\WpBakery::getInstance();
		Modules\YoastSeo::getInstance();
		Modules\Breakdance::getInstance();
		Modules\SmartSlider::getInstance($this);
	}

	/**
	 * Sets the URL of the file being replaced.
	 *
	 * @param string $url Full URL of the source (old) file.
	 * @return void
	 */
	public function setSource($url)
	{
		$this->source_url = $url;
	}

	/**
	 * Returns the URL of the file being replaced.
	 *
	 * @return string|null Source URL, null when not set.
	 */
	public function getSource()
	{
		return $this->source_url;
	}

	/**
	 * Sets the URL of the replacement file.
	 *
	 * @param string $url Full URL of the target (new) file.
	 * @return void
	 */
	public function setTarget($url)
	{
		$this->target_url = $url;
	}

	/**
	 * Returns the URL of the replacement file.
	 *
	 * @return string|null Target URL, null when not set.
	 */
	public function getTarget()
	{
		return $this->target_url;
	}

	/**
	 * Sets the attachment metadata of the source file.
	 *
	 * Used to build the thumbnail URLs to search for and by findNearestSize().
	 *
	 * @param array $meta Attachment metadata (`file`, `width`, `sizes`).
	 * @return void
	 */
	public function setSourceMeta($meta)
	{
		$this->source_metadata = $meta;
	}

	/**
	 * Sets the attachment metadata of the target file.
	 *
	 * @param array $meta Attachment metadata (`file`, `width`, `sizes`).
	 * @return void
	 */
	public function setTargetMeta($meta)
	{
		$this->target_metadata = $meta;
	}

	/**
	 * Adds extra search/replace URL pairs to the next replace().
	 *
	 * Both arrays are merged into the stored ones with array_merge(): string
	 * keys overwrite earlier entries with the same key, numeric keys are
	 * appended. replace() merges them after the generated relative URLs, so
	 * a string key equal to a size name (or `base`, `file`) overrides the
	 * generated pair. The caller keeps both arrays the same length and in the
	 * same order; the stored arrays are not cleared after replace().
	 * Non-array arguments are ignored.
	 *
	 * @param array $search_urls  URLs or paths to search for.
	 * @param array $replace_urls Replacements, index-matched to $search_urls.
	 * @return void
	 */
	public function addURLArray($search_urls = [], $replace_urls = [])
	{
		if (true === is_array($search_urls))
		{
			$this->search_urls = array_merge($this->search_urls, $search_urls);
		}
		if (true === is_array($replace_urls))
		{
			$this->replace_urls = array_merge($this->replace_urls, $replace_urls);
		}
	}

	/**
	 * Returns a Setup object of the newer search/replace API.
	 *
	 * Setup::getInstance() creates a new object on every call, so each call
	 * here starts with empty data.
	 *
	 * @return Setup
	 */
	public function Setup()
	{
		 return Setup::getInstance();
	}

	/**
	 * Returns a new Finder of the newer search/replace API.
	 *
	 * @param array $args Finder constructor arguments (`callback`, `base_url`,
	 *                    `return_data`), see Classes\Finder::__construct().
	 * @return Finder
	 */
	public function Finder($args = [])
	{

		 return new Finder($args);
	}

	/**
	 * Returns a new Updater of the newer search/replace API.
	 *
	 * @return Updater
	 */
	public function Updater()
	{
		 return new Updater();
	}

	/**
	 * Replaces every reference to the source file with the target file.
	 *
	 * Steps:
	 * 1. Returns false when the source or target URL is not set.
	 * 2. Resets the replace settings to the defaults with `component` set to
	 *    `emr` (the same value in every consuming plugin).
	 * 3. Derives $base_url: the path of the source URL with the extension
	 *    removed by str_replace('.ext', ''). This is the LIKE pattern used to
	 *    find candidate rows.
	 * 4. Fail-safes: when fileIsRestricted() reports the path as allowed and
	 *    is_dir($base_url) is true (the URL path, tested as an absolute
	 *    filesystem path), or when $base_url is empty, returns an array with
	 *    one translated error message.
	 * 5. Builds relative URL arrays for source and target with
	 *    getRelativeURLS(): key `base` for the main file, `file` for the
	 *    metadata file, and one key per thumbnail size name.
	 * 6. With `thumbnails_only`, drops `base` and `file` from both sides.
	 * 7. Appends the pairs added with addURLArray().
	 * 8. Drops target sizes that do not exist in the source.
	 * 9. For source sizes missing in the target, fills in the target file
	 *    whose width is closest (findNearestSize()), placed in the source
	 *    size's directory. Sizes without a nearest match stay unpaired.
	 * 10. Drops pairs whose search and replace values are identical.
	 * 11. When the arrays still differ in length, returns an array with one
	 *     translated error message, because str_replace() would otherwise
	 *     replace the unpaired entries with an empty string.
	 * 12. Fires `shortpixel/replacer/replace_urls`.
	 * 13. Returns 0 when nothing is left to search for.
	 * 14. Runs doReplaceQuery() for posts and meta tables.
	 * 15. Applies `shortpixel/replacer/custom_replace_query` and runs
	 *     doReplaceQuery() once per returned entry, with that entry's `args`
	 *     as the replace settings (or the defaults when it has none). The
	 *     settings of the last run stay on the instance.
	 *
	 * @hook action shortpixel/replacer/replace_urls Fired with
	 *       ($search_urls, $replace_urls, $base_url) after the arrays are
	 *       balanced and before any query runs.
	 * @hook filter shortpixel/replacer/custom_replace_query Receives
	 *       (array $runs, $base_url, $search_urls, $replace_urls). Each entry,
	 *       keyed by a component name, is an array with `base_url`,
	 *       `search_urls`, `replace_urls` and optional `args` (replace
	 *       settings for that run, see setReplaceSettings()).
	 *
	 * @param array $args {
	 *     @type bool $thumbnails_only Only replace thumbnail sizes, not the
	 *                                 main file. Default false.
	 * }
	 * @return int|array|false Number of rows matched by all queries; an array
	 *                         of error strings when a fail-safe aborts; false
	 *                         when source or target is missing.
	 *
	 * @todo The extension is removed with str_replace(), which also removes
	 *       an identical text earlier in the path (`/a.jpg-dir/b.jpg` becomes
	 *       `/a-dir/b`), giving a LIKE pattern that matches nothing. Suggested
	 *       fix: strip only the trailing extension, for example with
	 *       substr() on the length of the extension.
	 * @todo The return type mixes int (success), array (errors) and false
	 *       (missing input), so callers must type-check the result. Suggested
	 *       fix: return a result object, or always return the errors array
	 *       and expose the update count separately.
	 * @todo In step 9 the filled-in target file is placed in the source
	 *       size's directory, which is wrong when the target file lives in a
	 *       different directory. Suggested fix: build it from the directory
	 *       of the target's `base` relative URL.
	 */
	public function replace($args = array())
	{
		if (is_null($this->source_url) || is_null($this->target_url)) {
			Log::addWarn('Replacer called without source or target ');
			return false;
		}
		$defaults = array(
			'thumbnails_only' => false,
		);

		$errors = array();
		$args = wp_parse_args($args, $defaults);

		$this->setReplaceSettings(['component' => 'emr']); // set to defaults.

		// Search-and-replace filename in post database
		// @todo Check this with scaled images.
		$base_url = parse_url($this->source_url, PHP_URL_PATH);
		$base_url = str_replace('.' . pathinfo($base_url, PATHINFO_EXTENSION), '', $base_url);

		/** Fail-safe if base_url is a whole directory, don't go search/replace */
		if (false === $this->fileIsRestricted($base_url) && is_dir($base_url)) {
			Log::addError('Search Replace tried to replace to directory - ' . $base_url);
			$errors[] = __('Fail Safe :: Source Location seems to be a directory.', 'enable-media-replace');
			return $errors;
		}

		if (strlen(trim($base_url)) == 0) {
			Log::addError('Current Base URL emtpy - ' . $base_url);
			$errors[] = __('Fail Safe :: Source Location returned empty string. Not replacing content', 'enable-media-replace');
			return $errors;
		}

		// get relurls of both source and target.
		$urls = $this->getRelativeURLS();


		if ($args['thumbnails_only']) {
			foreach ($urls as $side => $data) {
				if (isset($data['base'])) {
					unset($urls[$side]['base']);
				}
				if (isset($data['file'])) {
					unset($urls[$side]['file']);
				}
			}
		}

		$search_urls = $urls['source'];
		$replace_urls = $urls['target'];


	 	$search_urls = array_merge($search_urls, $this->search_urls); 
	 	$replace_urls = array_merge($replace_urls, $this->replace_urls); 

		/* If the replacement is much larger than the source, there can be more thumbnails. This leads to disbalance in the search/replace arrays.
	      Remove those from the equation. If the size doesn't exist in the source, it shouldn't be in use either */
		foreach ($replace_urls as $size => $url) {
			if (! isset($search_urls[$size])) {
				Log::addDebug('Dropping size ' . $size . ' - not found in source urls');
				unset($replace_urls[$size]);
			}
		}

		Log::addDebug('Source', $this->source_metadata);
		Log::addDebug('Target', $this->target_metadata);
		/* If on the other hand, some sizes are available in source, but not in target, try to replace them with something closeby.  */
		foreach ($search_urls as $size => $url) {
			if (! isset($replace_urls[$size])) {
				$closest = $this->findNearestSize($size);
				if ($closest) {
					$sourceUrl = $search_urls[$size];
					$baseurl = trailingslashit(str_replace(wp_basename($sourceUrl), '', $sourceUrl));
					Log::addDebug('Nearest size of source ' . $size . ' for target is ' . $closest);
					$replace_urls[$size] = $baseurl . $closest;
				} else {
					Log::addDebug('Unset size ' . $size . ' - no closest found in source');
				}
			}
		}

		/* If source and target are the same, remove them from replace. This happens when replacing a file with same name, and +/- same dimensions generated.

	    After previous loops, for every search there should be a replace size.
	    */
		foreach ($search_urls as $size => $url) {
			$replace_url = isset($replace_urls[$size]) ? $replace_urls[$size] : false;
			if ($url == $replace_url) // if source and target as the same, no need for replacing.
			{
				unset($search_urls[$size]);
				unset($replace_urls[$size]);
			}
		}

		// If the two sides are disbalanced, the str_replace part will cause everything that has an empty replace counterpart to replace it with empty. Unwanted.
		if (count($search_urls) !== count($replace_urls)) {
			Log::addError('Unbalanced Replace Arrays, aborting', array($search_urls, $replace_urls, count($search_urls), count($replace_urls)));
			$errors[] = __('There was an issue with updating your image URLS: Search and replace have different amount of values. Aborting updating thumbnails', 'enable-media-replace');
			return $errors;
		}

		Log::addDebug('Doing meta search and replace -', array($search_urls, $replace_urls));
		Log::addDebug('Searching with BaseuRL ' . $base_url);

		do_action('emr/replacer/replace_urls', $search_urls, $replace_urls, $base_url);
		$updated = 0;

		if (0 === count($search_urls)) 
		{
			 Log::addDebug('Search URLS array empty, nothing to replace this go '); 
			 return $updated; 
		}
		$updated += $this->doReplaceQuery($base_url, $search_urls, $replace_urls);

		$replaceRuns = apply_filters('emr/replacer/custom_replace_query', array(), $base_url, $search_urls, $replace_urls);

		foreach ($replaceRuns as $component => $run) {
			Log::addDebug('Running additional replace for : ' . $component, $run);

			// @todo This could perhaps benefit from a more general approach somewhere in class for settings.
			if (isset($run['args'])) {
				// Update current settings for this run only.
				$this->setReplaceSettings($run['args']);
			} else {
				$this->setReplaceSettings();
			}
			$updated += $this->doReplaceQuery($run['base_url'], $run['search_urls'], $run['replace_urls']);
		}

		Log::addDebug("Updated Records : " . $updated);
		return $updated;
	}

	/**
	 * Runs one search/replace pass over post content and the meta tables.
	 *
	 * Selects `ID, post_content` from the posts table for posts with status
	 * publish, future, draft, pending or private whose content is LIKE
	 * `%$base_url%` ($base_url is not passed through esc_like(), so `_` and
	 * `%` in it act as wildcards). Each row goes through replaceContent()
	 * (not deep, strict check); when the content changes it is written with
	 * a direct `$wpdb` UPDATE (no post hooks, no revision, post_modified is
	 * untouched) followed by clean_post_cache(). Then handleMetaData() runs
	 * with the same arrays.
	 *
	 * @global \wpdb $wpdb
	 *
	 * @param string $base_url     LIKE fragment used to select candidate rows.
	 * @param array  $search_urls  Values to search for.
	 * @param array  $replace_urls Replacements, index-matched to $search_urls.
	 * @return int Number of matched rows (posts plus meta rows), counted
	 *             whether or not their content changed.
	 */
	private function doReplaceQuery($base_url, $search_urls, $replace_urls)
	{
		global $wpdb;
		/* Search and replace in WP_POSTS */
		// Removed $wpdb->remove_placeholder_escape from here, not compatible with WP 4.8

		$posts_sql = $wpdb->prepare(
			"SELECT ID, post_content FROM $wpdb->posts WHERE post_status in ('publish', 'future', 'draft', 'pending', 'private')
				AND post_content LIKE %s",
			'%' . $base_url . '%'
		);

		$rs = $wpdb->get_results($posts_sql, ARRAY_A);
		$number_of_updates = 0;

		if (! empty($rs)) {
			foreach ($rs as $rows) {
				$number_of_updates = $number_of_updates + 1;
				// replace old URLs with new URLs.

				$post_content = $rows["post_content"];
				$post_id = $rows['ID'];
				$replaced_content = $this->replaceContent($post_content, $search_urls, $replace_urls, false, true);

				if ($replaced_content !== $post_content) {

					// @todo This should be moved indeed to wp_update_post ( And Updater.php class ) to better do with cache etc
					//  $result = wp_update_post($post_ar);
					$sql = 'UPDATE ' . $wpdb->posts . ' SET post_content = %s WHERE ID = %d';
					$sql = $wpdb->prepare($sql, $replaced_content, $post_id);

					$result = $wpdb->query($sql);

					if ($result === false) {
						// Notice::addError('Something went wrong while replacing' .  $result->get_error_message() );
						Log::addError('WP-Error during post update', $result);
					}
			        clean_post_cache($post_id);

				}
			}
		}

		$number_of_updates += $this->handleMetaData($base_url, $search_urls, $replace_urls);
		return $number_of_updates;
	}

	/**
	 * Runs one search/replace pass over the meta tables and the options table.
	 *
	 * The tables come from an array keyed by the `$wpdb` table property name
	 * (`postmeta`, `commentmeta`, `termmeta`, `usermeta`, `options`). Each
	 * entry has:
	 * - `id`: primary key column (required).
	 * - `value`: column holding the content (required).
	 * - `main_id`: column of the owning object id (optional). Selected with
	 *   the row; without it the query selects `NULL as main_id`.
	 * - `cache_group`: object cache group of the owning object (optional).
	 *
	 * For `postmeta` the rows are limited by a subquery to posts with status
	 * publish, future, draft, pending or private. Every other table is
	 * queried as `$wpdb->{$table}` with `value LIKE %$url%`.
	 *
	 * For each matched row the value passes the `load_meta_value` filter.
	 * When the filter returns null the row is skipped. Otherwise the value goes
	 * through replaceContent() (not deep, no strict check) and the
	 * `save_meta_value` filter. When the `replacer_do_save` setting is true
	 * the value is written with a direct UPDATE on the `id` column, and the
	 * cache is cleared: for `options` only the `alloptions` and `notoptions`
	 * keys of the `options` group, for other tables the `main_id` value in
	 * `cache_group` when a cache group is set. When `replacer_do_save` is
	 * false, saving is left to the `save_meta_value` callback.
	 *
	 * @global \wpdb $wpdb
	 *
	 * @hook filter shortpixel/replacer/replacement_tables Receives and returns
	 *       the table array described above.
	 * @hook filter shortpixel/replacer/load_meta_value Receives ($content,
	 *       array $row, string $component). $row holds the selected id, value
	 *       and main id columns; $component is the `component` replace
	 *       setting. Return null to leave the row untouched.
	 * @hook filter shortpixel/replacer/save_meta_value Receives the replaced
	 *       ($content, array $row, string $component) and returns the value to
	 *       save.
	 *
	 * @param string $url          LIKE fragment used to select candidate rows.
	 * @param array  $search_urls  Values to search for.
	 * @param array  $replace_urls Replacements, index-matched to $search_urls.
	 * @return int Number of matched rows, counted whether or not they changed
	 *             or were skipped.
	 *
	 * @todo Inside the row loop `$main_id = $row[$main_id];` overwrites the
	 *       column name with the row's value, so from the second row of a
	 *       table the cache key is read from `$row[<previous id value>]`
	 *       (undefined index) and the wrong cache entry is cleared. Suggested
	 *       fix: store the row value in a separate variable.
	 * @todo For `options` only `alloptions` and `notoptions` are cleared, so a
	 *       non-autoloaded option keeps its stale value in the object cache.
	 *       Suggested fix: also select `option_name` and delete that key in
	 *       the `options` group.
	 * @todo The skip log message prints `$id_field` (the column name) instead
	 *       of `$id` (the row id). Suggested fix: log `$id`.
	 * @todo The postmeta subquery quotes the statuses with double quotes,
	 *       which MySQL reads as identifiers under the ANSI_QUOTES SQL mode.
	 *       Suggested fix: use single quotes as doReplaceQuery() does.
	 */
	private function handleMetaData($url, $search_urls, $replace_urls)
	{
		global $wpdb;

//		$meta_options = apply_filters('emr/replacer/metadata_tables', array('post', 'comment', 'term', 'user', 'options'));
		$number_of_updates = 0;

		/*
		$meta_default = [
			'id' => 'meta_id',
			'value' => 'meta_value',
		]; */


		$table_options = [
			'postmeta' => [
				'id' => 'meta_id', 
				'value' => 'meta_value', 
				'main_id' => 'post_id',
				'cache_group' => 'post_meta'
			],
			'commentmeta' => [
				'id' => 'meta_id', 
				'value' => 'meta_value', 
				'main_id' => 'comment_id',
				'cache_group' => 'comment_meta'
			],
			'termmeta' => [
				'id' => 'meta_id', 
				'value' => 'meta_value', 
				'main_id' => 'term_id',
				'cache_group' => 'term_meta'
			],
			'usermeta' => [
				'id' => 'umeta_id', 
				'value' => 'meta_value', 
				'main_id' => 'user_id',
				'cache_group' => 'user_meta'
			],
			'options' => [
				'id' => 'option_id',
				'value' => 'option_value',		
			]

		];

		$table_options = apply_filters('emr/replacer/replacement_tables', $table_options);

		foreach ($table_options as $table => $data) {
			// These must be always defined.
			$id_field = esc_sql($data['id']);
			$value_field = esc_sql($data['value']);
			$main_id = isset($data['main_id']) ? esc_sql($data['main_id']) : 'NULL as main_id'; 
			

			switch ($table) {
				case "postmeta": // special case.
					$sql = "SELECT $id_field, $value_field, $main_id FROM " . $wpdb->postmeta . '
	                WHERE post_id in (SELECT ID from ' . $wpdb->posts . ' where post_status in ("publish", "future", "draft", "pending", "private") ) AND meta_value like %s';
					$type = 'post';

					$update_sql = ' UPDATE ' . $wpdb->postmeta . ' SET meta_value = %s WHERE meta_id = %d';
					break;
				default:
					$wp_table = $wpdb->{$table};  // termmeta, commentmeta etc

					$sql = "SELECT $id_field, $value_field, $main_id FROM $wp_table WHERE $value_field like %s";

					$update_sql = " UPDATE $wp_table set $value_field = %s WHERE $id_field  = %d ";
					break;
			}

			$sql = $wpdb->prepare($sql, '%' . $url . '%');

			// This is a desparate solution. Can't find anyway for wpdb->prepare not the add extra slashes to the query, which messes up the query.
			$rsmeta = $wpdb->get_results($sql, ARRAY_A);

			if (! empty($rsmeta)) {
				foreach ($rsmeta as $row) {
					$number_of_updates++;
					$content = $row[$value_field];

					$component = $this->replace_settings['component'];

					$id = $row[$id_field];

					// Content as how it's loading.
					$content = apply_filters('emr/replacer/load_meta_value', $content, $row, $component);

					// If content is null, break out of everything and don't replace this.
					if (null === $content) {
						Log::addDebug('Content returned null, aborting this record, meta_id : ' . $id_field);
					} else {
						$content = $this->replaceContent($content, $search_urls, $replace_urls, false, false);

						// Content as how it's going to dbase.
						$content = apply_filters('emr/replacer/save_meta_value', $content, $row, $component);

						// Check if usual save should be prevented. This is for integrations.
						if (true === $this->replace_settings['replacer_do_save']) {
							$prepared_sql = $wpdb->prepare($update_sql, $content, $id);
							$wpdb->query($prepared_sql);
							
							if ('options' === $table)
							{
								wp_cache_delete( 'alloptions', 'options' );         // Clear all options array
								wp_cache_delete( 'notoptions', 'options' );         // Reset non-existent list
							} 
							else 
							{
								if (isset($data['cache_group']))
								{
									$main_id = $row[$main_id];
									wp_cache_delete($main_id, $data['cache_group']);
								}
							}
						}
					}
				} // Loop
			} // if
		} // foreach on table options

		return $number_of_updates;
	} // function


	/**
	 * Sets the settings of the current replace run.
	 *
	 * Missing keys take their default, so a call without arguments resets all
	 * settings. Keys:
	 * - `component` (string, default `unset`): name of the run, passed to the
	 *   `load_meta_value` and `save_meta_value` filters so integrations can
	 *   act only on their own run. replace() uses `emr` for the main run.
	 * - `json_flags` (int, default JSON_UNESCAPED_SLASHES): flags for
	 *   json_encode() when replaceContent() re-encodes JSON.
	 * - `replacer_do_save` (bool, default true): whether handleMetaData()
	 *   writes the replaced meta value itself.
	 * - `replace_no_serialize` (bool, default false): when true,
	 *   replaceContent() returns arrays and objects as they are instead of
	 *   serializing them at the top level.
	 *
	 * @param array $settings Settings to apply over the defaults.
	 * @return void
	 */
	private function setReplaceSettings($settings = array())
	{
		$this->replace_settings = wp_parse_args($settings, $this->default_replace_settings);
	}

	/**
	 * Replaces Content across several levels of possible data
	 *
	 * Recursion contract:
	 * - Serialized string: unserialized with `allowed_classes => false`
	 *   through the Unserialize polyfill. The polyfill's own branch is only
	 *   for PHP below 7; the consuming plugins require PHP 7.4, so the native
	 *   unserialize() runs. With no class allowed, every object (stdClass
	 *   included) becomes __PHP_Incomplete_Class. When the top-level result
	 *   is an incomplete object the original serialized string is returned
	 *   unchanged.
	 * - JSON string (see isJSON()): decoded with json_decode() into objects
	 *   and arrays and processed as such. Only at the top level
	 *   ($in_deep false) is it encoded back, with the `json_flags` setting.
	 * - String: passed through the `shortpixel/replacer/content` filter, then
	 *   str_replace($search, $replace).
	 * - WP_Error: returned untouched.
	 * - Array: every value is processed recursively ($in_deep true). String
	 *   keys are processed too, and a key that changes (a URL used as key)
	 *   is renamed with change_key().
	 * - Object: every public property is processed recursively. An
	 *   incomplete object is returned as it is (or, at the top level of a
	 *   serialized value, the original serialized string is returned).
	 * - Other scalars and null: returned untouched.
	 * - At the top level, arrays and objects that were not JSON are passed
	 *   through maybe_serialize(), unless the `replace_no_serialize` setting
	 *   is true.
	 *
	 * When no replace run has set the settings yet, the defaults are applied
	 * first. Otherwise the settings of the latest run apply.
	 *
	 * @hook filter shortpixel/replacer/content Receives ($content, $search,
	 *       $replace) for every string leaf (values and array keys) before
	 *       str_replace() and returns the string to replace in.
	 *
	 * @param mixed $content      The content to replace in.
	 * @param array $search       Values to search for.
	 * @param array $replace      Replacements, index-matched to $search.
	 * @param bool  $in_deep      True for nested levels; prevents
	 *                            re-serialising and JSON re-encoding below the
	 *                            top level.
	 * @param bool  $strict_check Passed down the recursion but not otherwise
	 *                            read; every unserialize uses
	 *                            `allowed_classes => false` regardless.
	 * @return mixed The replaced content, in the input's form at the top
	 *               level (string for serialized, JSON or string input).
	 *
	 * @todo A JSON string nested inside serialized data or inside a decoded
	 *       structure is decoded but not re-encoded because $in_deep is true,
	 *       so it comes back as stdClass or array instead of a JSON string.
	 *       Suggested fix: re-encode whenever this call decoded JSON,
	 *       independent of $in_deep. The same applies to a serialized string
	 *       nested inside other data, which comes back unserialized.
	 */
	public function replaceContent($content, $search, $replace, $in_deep = false, $strict_check = true)
	{

		// Since ReplaceContent can now be called directly, this might not be set, set defaults if so
		if (is_null($this->replace_settings)) {
			$this->setReplaceSettings([]);
		}

		if (true === is_serialized($content)) {
			$serialized_content = $content; // use to return content back if incomplete classes are found, prevent destroying the original information

			// Only allow stdClass which can't have magic methods. 
			$args = array('allowed_classes' => false);
			//$args = array('allowed_classes' => array('stdClass'));

			$content = Unserialize::unserialize($content, $args);
			// bail directly on incomplete classes. In < PHP 7.2 is_object is false on incomplete objects!
			if (true === $this->checkIncomplete($content)) {
				return $serialized_content;
			}
		}

		$isJson = $this->isJSON($content);

		if ($isJson) {
			$content = json_decode($content);
		}

		if (is_string($content))  // let's check the normal one first.
		{
			$content = apply_filters('emr/replacer/content', $content, $search, $replace);
			$content = str_replace($search, $replace, $content);
		} elseif (is_wp_error($content)) // seen this.
		{
			//return $content;  // do nothing.
		} elseif (is_array($content)) // array metadata and such.
		{
			foreach ($content as $index => $value) {
				$content[$index] = $this->replaceContent($value, $search, $replace, true, $strict_check); //str_replace($value, $search, $replace);
				if (is_string($index)) // If the key is the URL (sigh)
				{

					$index_replaced = $this->replaceContent($index, $search, $replace, true, $strict_check);
					if ($index_replaced !== $index)
						$content = $this->change_key($content, array($index => $index_replaced));
				}
			}
		} elseif (is_object($content)) // metadata objects, they exist.
		{
			// bail directly on incomplete classes.
			if (true === $this->checkIncomplete($content)) {
				// if it was serialized, return the original as not to corrupt data.
				if (isset($serialized_content)) {
					return $serialized_content;
				} else { // else just return the content.
					return $content;
				}

			}
			foreach ($content as $key => $value) {
				$content->{$key} = $this->replaceContent($value, $search, $replace, true, $strict_check);
			}
		}

		if ($isJson && $in_deep === false) // convert back to JSON, if this was JSON. Different than serialize which does WP automatically.
		{

			// wp-slash -> WP does stripslashes_deep which destroys JSON
			$json_flags = $this->replace_settings['json_flags'];
			Log::addDebug('Value was found to be JSON, encoding with flag: ' . $json_flags);
			$content = json_encode($content, $json_flags);
			Log::addDebug('Content returning (array ours) ', array($content));
		} elseif (
			$this->replace_settings['replace_no_serialize'] === false &&
			$in_deep === false && (is_array($content) || is_object($content))
		) {
			$content = maybe_serialize($content);
		}
		return $content;
	}



	/** Check if path is allowed within openbasedir restrictions. This is an attempt to limit notices in file funtions if so.  Most likely the path will be relative in that case.
	 *
	 * Returns false when `open_basedir` is not set. Otherwise splits it on
	 * `:` or `;` and returns false when any entry occurs anywhere in $path
	 * (substring test, not a prefix test), true when none does. The result
	 * passes a filter before it is returned.
	 *
	 * @hook filter shortpixel/file/basedir_check Receives the boolean
	 *       "restricted" result and returns it, so server setups with
	 *       symlinks can override a false positive.
	 *
	 * @param string $path Path to check.
	 * @return bool True when the path is outside every open_basedir entry.
	 */
	private function fileIsRestricted($path)
	{

		$basedir = ini_get('open_basedir');

		if (false === $basedir || strlen($basedir) == 0) {
			return false;
		}

		$restricted = true;
		$basedirs = preg_split('/:|;/i', $basedir);

		foreach ($basedirs as $basepath) {
			if (strpos($path, $basepath) !== false) {
				$restricted = false;
				break;
			}
		}

		// Allow this to be overridden due to specific server configs ( ie symlinks ) might get this flagged falsely.
		$restricted = apply_filters('emr/file/basedir_check', $restricted);

		return $restricted;
	}

	/**
	 * Renames array keys, keeping the order of the elements.
	 *
	 * Builds a new array in which every key found in $set is replaced by its
	 * mapped value. Nested arrays are processed with the same map, so a
	 * matching key is renamed at every depth. Non-array input is returned
	 * unchanged.
	 *
	 * @param array $arr Array to rename keys in.
	 * @param array $set Map of old key => new key.
	 * @return array|mixed The array with renamed keys, or $arr unchanged when
	 *                     either argument is not an array.
	 */
	private function change_key($arr, $set)
	{
		if (is_array($arr) && is_array($set)) {
			$newArr = array();
			foreach ($arr as $k => $v) {
				$key = array_key_exists($k, $set) ? $set[$k] : $k;
				$newArr[$key] = is_array($v) ? $this->change_key($v, $set) : $v;
			}
			return $newArr;
		}
		return $arr;
	}

	/**
	 * Builds the relative URL arrays of the source and the target file.
	 *
	 * For each side the result holds `base`, the URL path of the main file,
	 * and one entry per file from getFilesFromMetadata() (`file` and every
	 * size name): the directory of the main file's URL path plus the
	 * basename of that file. The directory is derived by removing the main
	 * file's basename from the path with str_replace(), which also removes
	 * the same text if it occurs earlier in the path.
	 *
	 * @return array {
	 *     @type array $source Relative paths keyed `base`, `file`, size names.
	 *     @type array $target Same shape for the target file.
	 * }
	 */
	private function getRelativeURLS()
	{
		$dataArray = array(
			'source' => array('url' => $this->source_url, 'metadata' => $this->getFilesFromMetadata($this->source_metadata)),
			'target' => array('url' => $this->target_url, 'metadata' => $this->getFilesFromMetadata($this->target_metadata)),
		);

		$result = array();

		foreach ($dataArray as $index => $item) {
			$result[$index] = array();
			$metadata = $item['metadata'];

			$baseurl = parse_url($item['url'], PHP_URL_PATH);
			$result[$index]['base'] = $baseurl;  // this is the relpath of the mainfile.
			$baseurl = trailingslashit(str_replace(wp_basename($item['url']), '', $baseurl)); // get the relpath of main file.

			foreach ($metadata as $name => $filename) {
				$result[$index][$name] =  $baseurl . wp_basename($filename); // filename can have a path like 19/08 etc.
			}
		}
		//    Log::addDebug('Relative URLS', $result);
		return $result;
	}


	/**
	 * Extracts the file names from attachment metadata.
	 *
	 * @param array $meta Attachment metadata.
	 * @return array Map of `file` => metadata `file` value (may include the
	 *               year/month path) and size name => size file name, for
	 *               every entry that has a `file` key.
	 */
	private function getFilesFromMetadata($meta)
	{
		$fileArray = array();
		if (isset($meta['file']))
			$fileArray['file'] = $meta['file'];

		if (isset($meta['sizes'])) {
			foreach ($meta['sizes'] as $name => $data) {
				if (isset($data['file'])) {
					$fileArray[$name] = $data['file'];
				}
			}
		}
		return $fileArray;
	}

	/** FindNearestsize
	 * This works on the assumption that when the exact image size name is not available, find the nearest width with the smallest possible difference to impact the site the least.
	 *
	 * When the source has no metadata for $sizeName or the target metadata
	 * has no `width` (non-image files such as PDF), returns the target URL's
	 * basename if the target URL contains `.svg` anywhere (an SVG replaces
	 * every thumbnail with the main file), else false.
	 *
	 * Otherwise starts with the target main file (basename of its metadata
	 * `file`) and the difference between the source size width and the
	 * target main width, then walks the target sizes and keeps the file of
	 * each size with a strictly smaller width difference. A size whose `file`
	 * is an array uses its first element. The loop variable reuses the name
	 * $sizeName, and $found_metasize is set but never read.
	 *
	 * @param string $sizeName Size name present in the source metadata.
	 * @return string|false File name (basename) of the closest target file,
	 *                      or false when none is found.
	 */
	private function findNearestSize($sizeName)
	{

		if (! isset($this->source_metadata['sizes'][$sizeName]) || ! isset($this->target_metadata['width'])) // This can happen with non-image files like PDF.
		{
			// Check if metadata-less item is a svg file. Just the main file to replace all thumbnails since SVG's don't need thumbnails.
			if (strpos($this->target_url, '.svg') !== false) {
				$svg_file = wp_basename($this->target_url);
				return $svg_file;  // this is the relpath of the mainfile.
			}

			return false;
		}
		$old_width = $this->source_metadata['sizes'][$sizeName]['width']; // the width from size not in new image
		$new_width = $this->target_metadata['width']; // default check - the width of the main image

		$diff = abs($old_width - $new_width);
		//  $closest_file = str_replace($this->relPath, '', $this->newMeta['file']);
		$closest_file = wp_basename($this->target_metadata['file']); // mainfile as default

		foreach ($this->target_metadata['sizes'] as $sizeName => $data) {
			$thisdiff = abs($old_width - $data['width']);

			if ($thisdiff  < $diff) {
				$closest_file = $data['file'];
				if (is_array($closest_file)) {
					$closest_file = $closest_file[0];
				} // HelpScout case 709692915
				if (!empty($closest_file)) {
					$diff = $thisdiff;
					$found_metasize = true;
				}
			}
		} 

		if (empty($closest_file)) return false;

		return $closest_file;
	}

	/* Check if given content is JSON format. */
	/**
	 * Checks whether a value is a JSON document.
	 *
	 * Arrays, objects and null are never JSON. Other values are passed to
	 * json_decode(); the result is true when the decoded value is truthy and
	 * loosely differs from the input. Numeric strings and `true` decode to a
	 * value that loosely equals the input, so they return false; `0`,
	 * `false` and `[]` decode to falsy values and also return false. A
	 * quoted JSON string literal such as `"abc"` (with the quotes) returns
	 * true.
	 *
	 * @param mixed $content Value to check.
	 * @return bool True when the content is treated as JSON.
	 */
	private function isJSON($content)
	{
		if (is_array($content) || is_object($content) || is_null($content))
			return false; // can never be.

		$json = json_decode($content);
		return $json && $json != $content;
	}

	/**
	 * Checks whether a value is an incomplete (unloadable class) object.
	 *
	 * With `allowed_classes => false` every unserialized object is one.
	 *
	 * @param mixed $var Value to check.
	 * @return bool True for a __PHP_Incomplete_Class instance.
	 */
	private function checkIncomplete($var)
	{
		return ($var instanceof \__PHP_Incomplete_Class);
	}
} // class
