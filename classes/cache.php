<?php
/**
 * Cache flushing after a replacement.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace;

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Detects page-cache plugins and flushes them after a file is replaced.
 *
 * Not a singleton: ReplaceController creates a new instance after each
 * replacement and calls flushCache(). Registers no hooks; detection happens
 * inside flushCache() through checkCaches(), so plugins loaded after this
 * object is created are still found.
 *
 * Detected plugins: W3 Total Cache, WP Super Cache (detected but not
 * flushed), WP Engine, WP Fastest Cache, SiteGround Optimizer, LiteSpeed
 * Cache, FastPixel, WP Rocket, Breeze, Cache Enabler.
 */
class emrCache
{
    /**
     * @var bool WP Super Cache detected (`wp_cache_clean_cache()` exists).
     *           Detected only; flushCache() does not act on it.
     */
    protected $has_supercache  = false; // supercache seems to replace quite fine, without our help. @todo Test if this is needed
    /**
     * @var bool W3 Total Cache detected (`w3tc_pgcache_flush()` exists).
     */
    protected $has_w3tc = false;
    /**
     * @var bool WP Engine detected (`WpeCommon` class exists).
     */
    protected $has_wpengine = false;
    /**
     * @var bool WP Fastest Cache detected (`WpFastestCache::deleteCache()`
     *           exists and the `$wp_fastest_cache` global is set).
     */
    protected $has_fastestcache = false;
    /**
     * @var bool SiteGround Optimizer detected (`sg_cachepress_purge_cache()` exists).
     */
    protected $has_siteground = false;
    /**
     * @var bool LiteSpeed Cache detected (`LSCWP_DIR` constant defined).
     */
    protected $has_litespeed = false;
    /**
     * @var bool FastPixel detected (a callback is hooked on `fastpixel/purge/all`).
     */
    protected $has_fastpixel = false;
    /**
     * @var bool WP Rocket detected (`rocket_clean_domain()` exists).
     */
    protected $has_wprocket = false;
    /**
     * @var bool Breeze detected (`BREEZE_VERSION` constant defined).
     */
    protected $has_breeze = false;
    /**
     * @var bool Cache Enabler detected (`Cache_Enabler` class exists).
     */
    protected $has_cacheenabler = false;

    /**
     * Does nothing; detection runs in flushCache().
     */
    public function __construct()
    {

    }

    /** Checks which cache plugins are active on the moment a flush is needed
     *
     * Sets the matching `has_*` flags to true. Flags are only ever set, never
     * reset, so a second call on the same instance keeps earlier results.
     *
     * @global object $wp_fastest_cache WP Fastest Cache's main instance.
     *
     * @return void
     */
    public function checkCaches()
    {
      if ( function_exists( 'w3tc_pgcache_flush' ) )
        $this->has_w3tc = true;

      if ( function_exists('wp_cache_clean_cache') )
        $this->has_supercache = true;

      if ( class_exists( 'WpeCommon' ) )
          $this->has_wpengine = true;

      global $wp_fastest_cache;
      if ( method_exists( 'WpFastestCache', 'deleteCache' ) && !empty( $wp_fastest_cache ) )
          $this->has_fastestcache = true;

      // SG SuperCacher
      if (function_exists('sg_cachepress_purge_cache')) {
	        $this->has_siteground = true;
      }

      if (defined( 'LSCWP_DIR' ))
      {
          $this->has_litespeed = true;
      }

      if (has_action('fastpixel/purge/all')) {
          $this->has_fastpixel = true;
      }

      if (function_exists('rocket_clean_domain')) {
          $this->has_wprocket = true;
      }

      if (defined('BREEZE_VERSION')) {
          $this->has_breeze = true;
      }

      if (class_exists('Cache_Enabler')) {
          $this->has_cacheenabler = true;
      }

      // @todo BlueHost Caching?
    }

    /** Tries to flush cache there were we have issues
    *
    * Runs checkCaches(), then:
    * - WordPress object cache: clean_post_cache($post_id) when `flush_mode`
    *   is 'post' and post_id > 0; otherwise wp_cache_flush(), which empties
    *   the whole object cache.
    * - each detected cache plugin's flush (all of them site-wide, none is
    *   limited to the replaced post); WP Super Cache is skipped, its call is
    *   commented out;
    * - finally fires `emr/cache/flush` so other code can add its own purge.
    *
    * ReplaceController also passes `source_url` and `target_url`; they are
    * kept in $args but not used here.
    *
    * @param array $args {
    *     @type string $flush_mode 'post' (default) for a per-post object-cache
    *                              clean, anything else for a full flush.
    *     @type int    $post_id    Attachment ID. Default 0 (can be zero).
    * }
    * @return void
    *
    * @hook action emr/cache/flush Fired last, with the post ID (int, may be 0).
    * @hook action litespeed_purge_all   Fired through litespeedReset().
    * @hook action fastpixel/purge/all   Fired through fastpixelReset().
    * @hook action breeze_clear_all_cache Fired through breezeReset().
    * @hook action cache_enabler_clear_complete_cache Fired through cacheEnablerReset().
    */
    public function flushCache($args)
    {
        $defaults = array(
            'flush_mode' => 'post',
            'post_id' => 0,
        );

        $args = wp_parse_args($args, $defaults);
        $post_id = $args['post_id']; // can be zero!

        // important - first check the available cache plugins
        $this->checkCaches();

        // general WP
        if ($args['flush_mode']  === 'post' && $post_id > 0)
          clean_post_cache($post_id);
        else
          wp_cache_flush();

        /*  Verified working without.
          if ($this->has_supercache)
            $this->removeSuperCache();
        */
        if ($this->has_w3tc)
            $this->removeW3tcCache();

        if ($this->has_wpengine)
            $this->removeWpeCache();

        if ($this->has_siteground)
            $this->removeSiteGround();

        if ($this->has_fastestcache)
            $this->removeFastestCache();

        if ($this->has_litespeed)
            $this->litespeedReset($post_id);

        if ($this->has_fastpixel)
            $this->fastpixelReset();

        if ($this->has_wprocket)
            $this->wprocketReset();

        if ($this->has_breeze)
            $this->breezeReset();

        if ($this->has_cacheenabler)
            $this->cacheEnablerReset();

        do_action('emr/cache/flush', $post_id);
    }

    /**
     * Cleans the WP Super Cache cache for the current file prefix.
     *
     * Resolves the cache directory with get_supercache_dir() when the global
     * is empty, then calls wp_cache_clean_cache().
     *
     * Never called: the only call site, in flushCache(), is commented out.
     *
     * @global string $file_prefix   WP Super Cache file prefix.
     * @global string $supercachedir WP Super Cache directory.
     *
     * @return void
     *
     * @todo The method is unreachable while has_supercache is detected but
     *       not acted on. Either remove the method and the detection, or
     *       re-enable the call once a test shows WP Super Cache needs it.
     */
    protected function removeSuperCache()
    {
       global $file_prefix, $supercachedir;
	     if ( empty( $supercachedir ) && function_exists( 'get_supercache_dir' ) ) {
	          $supercachedir = get_supercache_dir();
	     }
	     wp_cache_clean_cache( $file_prefix );
    }

    /**
     * Flushes the W3 Total Cache page cache (w3tc_pgcache_flush()).
     *
     * @return void
     */
    protected function removeW3tcCache()
    {
      w3tc_pgcache_flush();
    }

    /**
     * Purges WP Engine's memcached, MaxCDN and Varnish caches.
     *
     * Each WpeCommon method is called only when it exists.
     *
     * @return void
     */
    protected function removeWpeCache()
    {
      if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
          \WpeCommon::purge_memcached();
      }
      if ( method_exists( 'WpeCommon', 'clear_maxcdn_cache' ) ) {
          \WpeCommon::clear_maxcdn_cache();
      }
      if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
          \WpeCommon::purge_varnish_cache();
      }
    }

    /**
     * Deletes the WP Fastest Cache cache through its global instance.
     *
     * @global object $wp_fastest_cache WP Fastest Cache's main instance.
     *
     * @return void
     */
    protected function removeFastestCache()
    {
      global $wp_fastest_cache;
      $wp_fastest_cache->deleteCache();
    }

    /**
     * Purges the SiteGround Optimizer cache (sg_cachepress_purge_cache()).
     *
     * @return void
     */
    protected function removeSiteGround()
    {
    	sg_cachepress_purge_cache();
    }

    /**
     * Purges the whole LiteSpeed Cache.
     *
     * @param int $post_id Attachment ID; unused, the purge is site-wide.
     * @return void
     *
     * @hook action litespeed_purge_all
     */
    protected function litespeedReset($post_id)
    {
      do_action('litespeed_purge_all');
    }

    /**
     * Purges the whole FastPixel cache.
     *
     * @return void
     *
     * @hook action fastpixel/purge/all
     */
    protected function fastpixelReset()
    {
      do_action('fastpixel/purge/all');
    }

    /**
     * Cleans the WP Rocket cache for the whole domain (rocket_clean_domain()).
     *
     * @return void
     */
    protected function wprocketReset()
    {
      rocket_clean_domain();
    }

    /**
     * Clears all Breeze caches.
     *
     * @return void
     *
     * @hook action breeze_clear_all_cache
     */
    protected function breezeReset()
    {
      do_action('breeze_clear_all_cache');
    }

    /**
     * Clears the complete Cache Enabler cache.
     *
     * @return void
     *
     * @hook action cache_enabler_clear_complete_cache
     */
    protected function cacheEnablerReset()
    {
      do_action('cache_enabler_clear_complete_cache');
    }

}
