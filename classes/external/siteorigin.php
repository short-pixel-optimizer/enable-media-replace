<?php
/**
 * EMR-side SiteOrigin Page Builder integration.
 *
 * @package EnableMediaReplace
 */
namespace EnableMediaReplace\Externals;


/**
 * Adds the SiteOrigin panels widget option to the replacer's option fields.
 *
 * Singleton, loaded by Externals::loadSiteOrigins(). Active when the
 * `SITEORIGIN_PANELS_VERSION` constant is defined; it then registers the
 * filter `emr/replacer/option_fields` -> addOption().
 *
 * The replacer module never applies `emr/replacer/option_fields`, so this
 * integration currently has no effect on a replace.
 *
 * @todo SiteOrigin hooks `emr/replacer/option_fields`, a filter the replacer module never applies, so the `widget_siteorigin-panels-builder` option is never searched; either add an options-table pass in the replacer that applies this filter, or remove the integration.
 */
class SiteOrigin
{
	 /**
	  * Singleton instance.
	  *
	  * @var SiteOrigin|null
	  */
	 protected static $instance;

	 /**
	  * Registers the option-fields filter when SiteOrigin Page Builder is detected.
	  *
	  * @hook filter emr/replacer/option_fields
	  */
	 public function __construct()
	 {
		  if (defined('SITEORIGIN_PANELS_VERSION'))
			{
				add_filter('emr/replacer/option_fields', array($this, 'addOption'));
			}
	 }

	 /**
	  * Returns the singleton, creating it on first call.
	  *
	  * @return SiteOrigin
	  */
	 public static function getInstance()
	 {
		  if (is_null(self::$instance))
			{
				 self::$instance = new SiteOrigin();
			}

			return self::$instance;
	 }

	 /**
	  * Appends the SiteOrigin panels widget option name.
	  *
	  * @hook filter emr/replacer/option_fields
	  *
	  * @param string[] $options Option names to search.
	  * @return string[] $options with `widget_siteorigin-panels-builder` appended.
	  */
	 public function addOption($options)
	 {
		  $options[] = 'widget_siteorigin-panels-builder';
			return $options;
	 }
} // class
