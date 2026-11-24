<?php
/**
 * Replacer module entry point.
 *
 * Holds the Replacer2 class, which rewrites every reference to one media file
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
use EnableMediaReplace\Replacer\Classes\Record;
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
 * Search-and-replace engine for media URLs. Through Setup() the caller sets
 * a source URL (`search()` side) and a target URL (`replace()` side),
 * optionally the attachment metadata of both (so thumbnail sizes are
 * replaced as well) and optional extra URLs, then calls replace(). replace()
 * builds balanced search/replace arrays of relative URL paths and rewrites
 * them in `post_content`, in the post, comment, term and user meta tables
 * and in the options table, followed by any additional runs that
 * integrations register (page builders that store URLs escaped,
 * urlencoded or through their own save API).
 *
 * replaceContent() is public and is also used directly by consumers and by
 * the integration modules; it understands plain strings, serialized data,
 * JSON, arrays and objects.
 *
 * Lifecycle: there is no shared instance; consumers create one with
 * `new Replacer2($component)` per replacement. Every construction calls
 * loadFormats(), which instantiates the integration modules; those are
 * singletons, so their hooks are registered only once per request. The
 * Setup (source, target, metadata, extra URLs) and the replace settings are
 * instance state and are not reset after replace().
 *
 * replace() is built from the classes of this module: Classes\Setup
 * collects and balances the search/replace data, Classes\Finder finds the
 * rows as Classes\Record objects (each knowing its table and row),
 * replaceRecord() replaces their content and Classes\Updater writes them
 * back. Integrations extend a replace through `custom_replace_query` (an
 * extra run with its own LIKE fragment and settings), `replacement_tables`
 * (an extra table to search) or `records` (records of their own).
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
 * - filter `shortpixel/replacer/custom_replace_query` (getReplaceRuns())
 * - filter `shortpixel/replacer/records` (doReplaceRun())
 * - filter `shortpixel/replacer/replacement_tables` (Classes\Finder)
 * - filter `shortpixel/replacer/load_meta_value` (replaceRecord())
 * - filter `shortpixel/replacer/save_meta_value` (replaceRecord())
 * - filter `shortpixel/replacer/content` (replaceContent())
 * - filter `shortpixel/file/basedir_check` (Classes\Setup::fileIsRestricted())
 *
 * @todo Extra URLs added with Data::addUrls() are never cleared, so a
 *       second replace() on the same instance reuses the extra URLs of the
 *       first one (unless overwritten by key). The consumers create a new
 *       instance per replacement, so it does not show there. Suggested fix:
 *       add a reset method to Setup/Data, or clear the extra URLs at the end
 *       of replace().
 */

class Replacer2
{

	/**
	 * json_encode() flags added to every JSON re-encode of the replacer, on
	 * top of the run's `json_flags`. encodeJSON() is the only place in this
	 * class that encodes, so no re-encode can leave them out.
	 *
	 * Decoding turns escapes such as `\u003c` into literal characters, and
	 * json_encode() writes `<`, `>`, `&`, `'` and `"` literally unless told
	 * otherwise. Post content is written back without kses, so content that
	 * kses accepted as inert text (a JSON string holding `\u003cimg ...
	 * onerror=...\u003e`) would come out as live markup. With these flags
	 * the characters stay escaped. The output is equivalent JSON, so
	 * anything that reads it with a JSON decoder sees the same value.
	 *
	 * @var int
	 */
	const JSON_SAFE_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	/**
	 * Defaults for the replace settings, see setReplaceSettings().
	 *
	 * @var array
	 */
	private $default_replace_settings = array(
		'component' => 'unset',
		'json_flags' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		'replacer_do_save' => true,
		'replace_no_serialize' => false,
	);

	/**
	 * Settings of the current replace run, see setReplaceSettings(). The
	 * constructor sets the defaults with this replacer's component; every
	 * run replaces them with its own.
	 *
	 * @var array
	 */
	private $replace_settings;

	/**
	 * Name of the main replace run, passed to the meta filters so
	 * integrations can tell runs apart (for example `spio`).
	 *
	 * @var string
	 */
	private $component;

	/**
	 * Collector of the search and replace data.
	 *
	 * @var Setup
	 */
	private $setup;

	/**
	 * Creates a replacer and loads the integration modules.
	 *
	 * @param string $component Name of the main replace run.
	 */
	public function __construct(string $component)
	{
		$this->component = $component;
		$this->setReplaceSettings(['component' => $component]);
		$this->loadFormats();

        $this->setup = new Setup(); 
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
	 * Returns the Setup of this replacer, which collects and validates the
	 * search and replace data.
	 *
	 * One Setup per Replacer2 instance: every call returns the same object,
	 * so data set through it is what replace() uses.
	 *
	 * @return Setup
	 */
	public function Setup()
	{
		 return $this->setup; 
	}

	/**
	 * Returns a new Finder.
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
	 * Returns a new Updater.
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
	 * Flow:
	 * 1. Input: returns false when the search or replace side has no main
	 *    URL.
	 * 2. Validation: Setup::validateData($args) always runs here, also when
	 *    the caller already ran it; its errors are returned when it fails
	 *    (fail-safes on the search base, invalid input, unbalanced arrays).
	 *    With `thumbnails_only` the validation leaves the main file out; the
	 *    sides themselves are not changed.
	 * 3. Fires `shortpixel/replacer/replace_urls`; returns 0 when nothing is
	 *    left to search for.
	 * 4. For the main run and every integration run (getReplaceRuns()):
	 *    find records, replace their content, write the changed ones
	 *    (doReplaceRun()).
	 *
	 * @hook action shortpixel/replacer/replace_urls Fired with
	 *       ($search_urls, $replace_urls, $base_url) after the arrays are
	 *       balanced and before any query runs.
	 *
	 * @param array $args {
	 *     @type bool $thumbnails_only Only replace thumbnail sizes, not the
	 *                                 main file. Default false.
	 * }
	 * @return int|array|false Number of records found by all runs; an array
	 *                         of error strings when validation fails; false
	 *                         when source or target is missing.
	 *
	 * @todo The return type mixes int (success), array (errors) and false
	 *       (missing input), so callers must type-check the result. Suggested
	 *       fix: return a result object, or always return the errors array
	 *       and expose the update count separately.
	 */
	public function replace($args = array())
	{
		$defaults = array(
			'thumbnails_only' => false,
		);
		$args = wp_parse_args(is_array($args) ? $args : array(), $defaults);

		if (false === $this->setup->search()->hasUrl() || false === $this->setup->replace()->hasUrl()) {
			Log::addWarn('Replacer called without source or target ');
			return false;
		}

		// Always validate right here, so replace() never runs on stale or unchecked data.
		if (false === $this->setup->validateData($args)) {
			return $this->setup->getValidationErrors();
		}
		$data = $this->setup->getValidatedData();

		$base_url = $data['base_url'];
		$search_urls = $data['search'];
		$replace_urls = $data['replace'];

		Log::addDebug('Doing search and replace -', array($search_urls, $replace_urls));
		Log::addDebug('Searching with base URL ' . $base_url);

		do_action('emr/replacer/replace_urls', $search_urls, $replace_urls, $base_url);

		if (0 === count($search_urls)) {
			Log::addDebug('Search URLS array empty, nothing to replace this go ');
			return 0;
		}

		$matched = 0;
		foreach ($this->getReplaceRuns($base_url, $search_urls, $replace_urls) as $run) {
			$matched += $this->doReplaceRun($run);
		}

		Log::addDebug('Matched records : ' . $matched);
		return $matched;
	}

	/**
	 * Returns the runs of one replace(): the main run plus the integrations'.
	 *
	 * A run is one find / replace / update pass with its own LIKE fragment,
	 * its own search and replace arrays and its own replace settings. The
	 * main run uses this replacer's component; the integration runs come
	 * from the `shortpixel/replacer/custom_replace_query` filter (page
	 * builders that store URLs slash-escaped or urlencoded). An integration
	 * run without `args` uses the default settings (component `unset`).
	 *
	 * @hook filter shortpixel/replacer/custom_replace_query Receives
	 *       (array $runs, $base_url, $search_urls, $replace_urls). Each entry,
	 *       keyed by a component name, is an array with `base_url`,
	 *       `search_urls`, `replace_urls` and optional `args` (replace
	 *       settings for that run, see setReplaceSettings()).
	 *
	 * @param string $base_url     LIKE fragment of the main run.
	 * @param array  $search_urls  Values to search for.
	 * @param array  $replace_urls Replacements, index-matched to $search_urls.
	 * @return array[] Runs, each with `name`, `base_url`, `search_urls`,
	 *                 `replace_urls` and `args`.
	 */
	protected function getReplaceRuns($base_url, $search_urls, $replace_urls): array
	{
		$runs = [];
		$runs[] = [
			'name' => $this->component,
			'base_url' => $base_url,
			'search_urls' => $search_urls,
			'replace_urls' => $replace_urls,
			'args' => ['component' => $this->component],
		];

		$custom_runs = apply_filters('emr/replacer/custom_replace_query', array(), $base_url, $search_urls, $replace_urls);

		if (! is_array($custom_runs)) {
			Log::addError('custom_replace_query filter did not return an array, ignoring the custom runs');
			$custom_runs = array();
		}

		foreach ($custom_runs as $name => $run) {
			// A run needs a non-empty base_url (an empty one matches every row) and balanced arrays (str_replace() pairs by position).
			if (! is_array($run) || empty($run['base_url']) || ! is_string($run['base_url'])
				|| ! isset($run['search_urls'], $run['replace_urls']) || ! is_array($run['search_urls']) || ! is_array($run['replace_urls'])
				|| count($run['search_urls']) !== count($run['replace_urls'])
			) {
				Log::addError('Custom replace run ' . $name . ' is incomplete or unbalanced, skipping', $run);
				continue;
			}
			$runs[] = [
				'name' => $name,
				'base_url' => $run['base_url'],
				'search_urls' => $run['search_urls'],
				'replace_urls' => $run['replace_urls'],
				'args' => isset($run['args']) ? $run['args'] : [],
			];
		}

		return $runs;
	}

	/**
	 * Runs one find / replace / update pass.
	 *
	 * 1. Applies the run's replace settings.
	 * 2. Finds every row containing the run's base URL with
	 *    Finder::findRecords() (posts, post meta and the other tables).
	 * 3. Lets integrations add, remove or adjust records through
	 *    `shortpixel/replacer/records`.
	 * 4. Replaces the content of each record with replaceRecord().
	 * 5. Writes the changed records with Updater::updateRecord(), when
	 *    shouldSave() allows it.
	 *
	 * The settings of the run stay on the instance afterwards.
	 *
	 * @hook filter shortpixel/replacer/records Receives (Record[] $records,
	 *       string $component, array $run) and returns the records to
	 *       process. An integration can append Records for its own tables;
	 *       their table definition tells the Updater where to write.
	 *
	 * @param array $run A run as returned by getReplaceRuns().
	 * @return int Number of records found, whether or not they changed.
	 */
	protected function doReplaceRun(array $run): int
	{
		$this->setReplaceSettings($run['args']);
		$component = $this->replace_settings['component'];

		Log::addDebug('Replace run: ' . $run['name'] . ' (component ' . $component . ')');

		$records = $this->Finder(['base_url' => $run['base_url']])->findRecords();
		$records = apply_filters('emr/replacer/records', $records, $component, $run);

		$updater = $this->Updater();
		foreach ($records as $record) {
			$this->replaceRecord($record, $run['search_urls'], $run['replace_urls']);

			if (true === $this->shouldSave($record)) {
				$updater->updateRecord($record);
			}
		}

		return count($records);
	}

	/**
	 * Replaces the content of one record.
	 *
	 * Post content goes straight through replaceContent() (strict check).
	 * Every other record passes the `load_meta_value` filter first (null
	 * marks the record skipped), then replaceContent() (no strict check),
	 * then the `save_meta_value` filter. The result is stored on the record;
	 * nothing is written here.
	 *
	 * @hook filter shortpixel/replacer/load_meta_value Receives ($content,
	 *       array $row, string $component). $row holds the selected columns
	 *       under their original names. Return null to leave the row
	 *       untouched.
	 * @hook filter shortpixel/replacer/save_meta_value Receives the replaced
	 *       ($content, array $row, string $component) and returns the value to
	 *       save.
	 *
	 * @param Record $record       Record to replace in.
	 * @param array  $search_urls  Values to search for.
	 * @param array  $replace_urls Replacements, index-matched to $search_urls.
	 * @return Record The same record.
	 */
	public function replaceRecord(Record $record, $search_urls, $replace_urls): Record
	{
		if ('posts' === $record->getTable()) {
			$record->setContent($this->replaceContent($record->getContent(), $search_urls, $replace_urls, false, true));
			return $record;
		}

		$component = $this->replace_settings['component'];
		$row = $record->getRow();

		// Content as how it's loading.
		$content = apply_filters('emr/replacer/load_meta_value', $record->getContent(), $row, $component);

		if (null === $content) {
			Log::addDebug('Content returned null, skipping record ' . $record->getTable() . ' : ' . $record->getId());
			$record->skip();
			return $record;
		}

		$content = $this->replaceContent($content, $search_urls, $replace_urls, false, false);

		// Content as how it's going to the database.
		$content = apply_filters('emr/replacer/save_meta_value', $content, $row, $component);

		$record->setContent($content);
		return $record;
	}

	/**
	 * Tells whether a replaced record is written by the Updater.
	 *
	 * Post content is always written. Other records are written only when
	 * the run's `replacer_do_save` setting is true; integrations that save
	 * through their own API (Breakdance) set it to false and save in their
	 * `save_meta_value` callback.
	 *
	 * @param Record $record The replaced record.
	 * @return bool
	 */
	protected function shouldSave(Record $record): bool
	{
		if (false === $record->isChanged()) {
			return false;
		}
		if ('posts' === $record->getTable()) {
			return true;
		}
		return true === $this->replace_settings['replacer_do_save'];
	}

	/**
	 * Sets the settings of the current replace run.
	 *
	 * Missing keys take their default, so a call without arguments resets all
	 * settings. Keys:
	 * - `component` (string, default `unset`): name of the run, passed to the
	 *   `load_meta_value` and `save_meta_value` filters so integrations can
	 *   act only on their own run. replace() uses the component given to the
	 *   constructor for the main run.
	 * - `json_flags` (int, default JSON_UNESCAPED_SLASHES |
	 *   JSON_UNESCAPED_UNICODE): flags for json_encode() when
	 *   replaceContent() re-encodes JSON. JSON_SAFE_FLAGS are always added
	 *   and cannot be switched off here.
	 * - `replacer_do_save` (bool, default true): whether doReplaceRun()
	 *   writes replaced records other than post content itself.
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
	 * - Serialized string: unserialized with `allowed_classes => ['stdClass']`
	 *   through the Unserialize polyfill. The polyfill's own branch is only
	 *   for PHP below 7; the consuming plugins require PHP 7.4, so the native
	 *   unserialize() runs. stdClass has no magic methods, so it is
	 *   instantiated and walked like any object; every other class becomes
	 *   __PHP_Incomplete_Class and none of its methods can run. When the
	 *   top-level result is an incomplete object the original serialized
	 *   string is returned unchanged. An incomplete object nested in an
	 *   array or stdClass is kept as it is, and serialize() writes it back
	 *   under its original class name with its original properties.
	 *   Whatever was unserialized is serialized back at the same level (top
	 *   level or nested), so the stored value keeps its type, except for a
	 *   serialized JSON string (see the @todo below). A value that
	 *   unserializes to another serialized string (serialized twice) has its
	 *   inner layer walked as well, so both layers keep valid lengths. A
	 *   string that looks serialized but does not unserialize (a wrong
	 *   length) is returned unchanged.
	 * - JSON document (a string that decodes to an array or object, see
	 *   isJSON()): decoded with json_decode() into objects
	 *   and arrays, processed as such and encoded back at the same level
	 *   (top level or nested), with the `json_flags` setting plus
	 *   JSON_SAFE_FLAGS, so `<`, `>`, `&`, `'` and `"` inside strings stay
	 *   `\u` escaped and no markup appears that was not there. The string is
	 *   only re-encoded when the processed value differs from a second
	 *   decode of the input (compared by value, with `!=`); otherwise the
	 *   original string is returned byte for byte, so JSON without a match
	 *   keeps its escaping.
	 * - String: passed through the `shortpixel/replacer/content` filter, then
	 *   str_replace($search, $replace). A JSON string literal (a text
	 *   wrapped in double quotes, see isJSONString()) is not decoded as a
	 *   document: replaceJSONString() replaces in it and keeps its quotes,
	 *   surrounding whitespace and escaping, and also finds a search value
	 *   written with escaped slashes (`\/`).
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
	 *   is true. A walked serialized value is always serialized back, the
	 *   setting notwithstanding (see the @todo below).
	 *
	 * The settings of the latest replace run apply; before the first run
	 * these are the defaults with the constructor's component.
	 *
	 * @hook filter shortpixel/replacer/content Receives ($content, $search,
	 *       $replace) for every string leaf (values and array keys) before
	 *       str_replace() and returns the string to replace in.
	 *
	 * @param mixed $content      The content to replace in.
	 * @param array $search       Values to search for.
	 * @param array $replace      Replacements, index-matched to $search.
	 * @param bool  $in_deep      True for nested levels: an array or object
	 *                            is then returned as it is, without the
	 *                            top-level maybe_serialize(). Serialized and
	 *                            JSON values are written back in their own
	 *                            form at every level.
	 * @param bool  $strict_check Passed down the recursion but not otherwise
	 *                            read; every unserialize uses
	 *                            `allowed_classes => ['stdClass']`
	 *                            regardless.
	 * @return mixed The replaced content, in the input's form at the top
	 *               level (string for serialized, JSON or string input).
	 *
	 * @todo A serialized JSON string (`s:N:"{...}";`) loses its
	 *       serialization layer: the `$isJson` branch re-encodes the JSON
	 *       and the `$was_unserialized` branch, which would serialize it
	 *       back, is an `elseif` of it. The "unchanged" comparison decodes
	 *       the serialized original (null), so this happens even when
	 *       nothing was replaced. Suggested fix: serialize() after the JSON
	 *       re-encode when `$was_unserialized` is true, and compare against
	 *       the unserialized original.
	 * @todo With `replace_no_serialize` true, a serialized input still comes
	 *       back as a serialized string: the `$was_unserialized` branch runs
	 *       serialize() before the setting is checked, so a
	 *       `save_meta_value` callback of such a run gets a string, not the
	 *       array the setting promises. Suggested fix: check the setting in
	 *       that branch too (top level only), or document that the setting
	 *       only covers array and object input.
	 */
	public function replaceContent($content, $search, $replace, $in_deep = false, $strict_check = true)
	{

		// 1) used to return content back if incomplete classes are found, prevent destroying the original information
		// 2) used to return same content if json. 
		// 3) Ideally would return this if no changes made, but there is also the check in replace. 
	 	$raw_content = $content; 

		// Since ReplaceContent can now be called directly, this might not be set, set defaults if so
		if (is_null($this->replace_settings)) {
			$this->setReplaceSettings([]);
		}

		$was_unserialized = false; 

		if (true === is_serialized($content)) {

			// Only allow stdClass which can't have magic methods. 
			//$args = array('allowed_classes' => false);
			$args = array('allowed_classes' => array('stdClass'));

			// Suppressed: a broken value warns on PHP 8.3+ and is handled right below.
			$content = @Unserialize::unserialize($content, $args);
			$was_unserialized = true;

			// Looks serialized but does not unserialize (e.g. a broken length): leave it as it is, serializing false back would destroy it.
			if (false === $content && 'b:0;' !== trim($raw_content)) {
				Log::addWarn('Replacer: value looks serialized but does not unserialize, left unchanged');
				return $raw_content;
			}

			// bail directly on incomplete classes. In < PHP 7.2 is_object is false on incomplete objects!
			if (true === $this->checkIncomplete($content)) {
				return $raw_content;
			}
		}

		$isJson = $this->isJSON($content);

		if ($isJson) {
			$content = json_decode($content);
			$decoded_previous_content = json_decode($raw_content);
		}

		if (true === $was_unserialized && is_string($content) && true === is_serialized($content))
		{
			// A value serialized twice: walk the inner layer too, so its lengths stay valid. A disallowed class inside comes back unchanged.
			$content = $this->replaceContent($content, $search, $replace, true, $strict_check);
		}
		elseif (is_string($content))  // regular string.
		{
			$content = apply_filters('emr/replacer/content', $content, $search, $replace);
			if (true === $this->isJSONString($content))
			{
				$content = $this->replaceJSONString($content, $search, $replace);
			}
			else
			{
				$content = str_replace($search, $replace, $content);
			}
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
				return $raw_content;
			}
			foreach ($content as $key => $value) {
				$content->{$key} = $this->replaceContent($value, $search, $replace, true, $strict_check);
			}
		}

		// Fix removed is_deep check here to encode each time json was found.  It can happen in serialized array where subdata is in json format
	    if ($isJson) // convert back to JSON, if this was JSON. Different than serialize which does WP automatically.
	    {
	      // wp-slash -> WP does stripslashes_deep which destroys JSON
		  //	Log::addDebug('Value was found to be JSON, encoding it back');

		  		// Might work badly, but might help to limit to amount of re-encodes since there is always a risk of data loss
		  		if ($content != $decoded_previous_content)
				{
					$content = $this->encodeJSON($content);
				}
				else 
				{
					$content = $raw_content;
				}
				
	    }
		elseif (true === $was_unserialized)
		{
			 $content = serialize($content);
		}
		 elseif (
			$this->replace_settings['replace_no_serialize'] === false &&
			$in_deep === false && (is_array($content) || is_object($content))
		) {
			$content = maybe_serialize($content);
		}
		return $content;
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


	/* Check if given content is JSON format. */
	/**
	 * Checks whether a value is a JSON document: a string that decodes to an
	 * array or an object.
	 *
	 * Arrays, objects and null are never JSON. Other values are passed to
	 * json_decode(); the result is true when the decoded value is a
	 * non-empty array or an object. Everything that decodes to a scalar
	 * returns false: numeric strings, `true`, `false`, `null` and a quoted
	 * JSON string literal such as `"abc"`, which is a text wrapped in quotes
	 * and is handled as a string (see isJSONString()). `[]` decodes to an
	 * empty array and also returns false.
	 *
	 * @param mixed $content Value to check.
	 * @return bool True when the content is treated as JSON.
	 */
	private function isJSON($content)
	{
		if (is_array($content) || is_object($content) || is_null($content))
			return false; // can never be.

		$json = json_decode($content);

		// A scalar is not a document. Decoding and encoding a quoted text would rewrite it for nothing.
		if (false === is_array($json) && false === is_object($json))
			return false;

		return $json && $json != $content;
	}

	/**
	 * Checks whether a string is a JSON string literal: a text wrapped in
	 * double quotes that json_decode() reads as a string, such as `"abc"` or
	 * `"http:\/\/example.org\/a.jpg"`. This is both a quotation somebody
	 * typed with the quotes and a string a plugin stored JSON-encoded;
	 * the two cannot be told apart.
	 *
	 * @param mixed $content Value to check.
	 * @return bool True for a string that is a JSON string literal.
	 */
	private function isJSONString($content)
	{
		if (false === is_string($content) || '"' !== substr(ltrim($content), 0, 1))
			return false;

		return is_string(json_decode($content));
	}

	/**
	 * Replaces inside a JSON string literal (see isJSONString()) and keeps
	 * its bytes: the quotes, the whitespace around them and the escaping of
	 * everything that is not replaced.
	 *
	 * The text is replaced as it is, first with the search values and then
	 * with their slash-escaped form (`\/`), which is how json_encode()
	 * writes a URL by default. The result is accepted when it decodes to the
	 * same text as replacing in the decoded literal gives. When it does not
	 * (a search value that is written with other escapes in the literal,
	 * such as `\u00e9`), the replaced decoded text is encoded with
	 * encodeJSON() (the `json_flags` setting plus JSON_SAFE_FLAGS, so markup
	 * characters that were escaped stay escaped) and the original
	 * surrounding whitespace is put back. A literal in which nothing is
	 * replaced is returned unchanged.
	 *
	 * @param string       $content A JSON string literal.
	 * @param array|string $search  Values to search for.
	 * @param array|string $replace Replacements, index-matched to $search.
	 * @return string The literal with the values replaced.
	 */
	private function replaceJSONString($content, $search, $replace)
	{
		$decoded  = json_decode($content);
		$expected = str_replace($search, $replace, $decoded);

		if ($expected === $decoded)
		{
			return $content;
		}

		$replaced = str_replace($search, $replace, $content);
		$replaced = str_replace(str_replace('/', '\\/', $search), str_replace('/', '\\/', $replace), $replaced);

		if (json_decode($replaced) === $expected)
		{
			return $replaced;
		}

		$leading  = substr($content, 0, strlen($content) - strlen(ltrim($content)));
		$trailing = substr($content, strlen(rtrim($content)));

		return $leading . $this->encodeJSON($expected) . $trailing;
	}

	/**
	 * Encodes a value as JSON for writing back to the database.
	 *
	 * Every JSON re-encode of the replacer goes through here: the run's
	 * `json_flags` setting with JSON_SAFE_FLAGS always added, whatever the
	 * run asked for. Replaced content is written back without kses, so an
	 * encode that leaves these flags out turns escaped markup characters
	 * into literal ones.
	 *
	 * @param mixed $value Decoded value to encode.
	 * @return string|false The JSON text, or false when json_encode() fails.
	 */
	private function encodeJSON($value)
	{
		return json_encode($value, $this->replace_settings['json_flags'] | self::JSON_SAFE_FLAGS);
	}

	/**
	 * Checks whether a value is an incomplete (unloadable class) object.
	 *
	 * With `allowed_classes => ['stdClass']` every unserialized object of
	 * another class is one.
	 *
	 * @param mixed $var Value to check.
	 * @return bool True for a __PHP_Incomplete_Class instance.
	 */
	private function checkIncomplete($var)
	{
		return ($var instanceof \__PHP_Incomplete_Class);
	}
} // class
