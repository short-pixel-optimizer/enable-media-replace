<?php
/**
 * Time-limited promotional banner for the admin screens of a ShortPixel plugin.
 *
 * @package EnableMediaReplace\PromoBanner
 */
namespace EnableMediaReplace\PromoBanner;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Shows a campaign banner (the Black Friday offer) on chosen admin screens,
 * only inside the campaign's time window, and lets the user dismiss it.
 *
 * Reusable across ShortPixel plugins: the build tool copies this module into
 * each plugin and rewrites the namespace, the `shortpixel/` hook prefix and
 * the text domain, so every plugin gets its own copy. Each plugin creates one
 * instance with its own configuration and calls register():
 *
 *     $banner = new PromoBanner(array(
 *         'id'         => 'black-friday-2026',
 *         'start'      => '2026-11-23 00:00:00',
 *         'end'        => '2026-11-30 23:59:59',
 *         'timezone'   => 'America/New_York',
 *         'url'        => 'https://shortpixel.com/...&utm_medium=emr',
 *         'prefix'     => 'emr',
 *         'assets_url' => plugins_url('build/shortpixel/promo-banner/assets/', PLUGIN_FILE),
 *         'condition'  => function () { return is the plugin's own page; },
 *     ));
 *     $banner->register();
 *
 * Shown when all of these hold (shouldShow()): the current time is inside
 * [start, end] in `timezone` (both inclusive, so an end of 23:59:59 covers the
 * whole last day; the server clock is read in UTC, the site's timezone setting
 * does not matter); the user has `capability` (default manage_options, filter
 * `shortpixel/promo_banner/capability`); the user has not dismissed this
 * campaign; the screen matches (`screens`: screen ids, and/or `condition`: a
 * callable returning bool); banners are not switched off (filter
 * `shortpixel/settings/no_banner` or the SHORTPIXEL_NO_BANNER constant, the
 * same switches as ShortPixel Image Optimizer's settings banner). The filter
 * `shortpixel/promo_banner/show` has the last word; `shortpixel/promo_banner/in_window`
 * replaces only the time check (to preview a campaign before it starts). In a
 * plugin's build, `shortpixel/` in these hook names is that plugin's prefix.
 *
 * Output: by default on `admin_notices`, a `div.notice` WordPress's script
 * moves below the page's `.wp-header-end` once the page has loaded (so it
 * shows at the top for a moment). With `hook` false the plugin prints it with
 * render() right after its header instead: no `notice` class, nothing moves
 * it. At most
 * 120px tall; it takes the full content width (a plugin narrows it with its
 * own CSS where its boxes are narrower) and adapts its layout to its own
 * width (CSS container queries), down to phones. Assets:
 * `promo-banner.css` and `promo-banner.js` (+ img/) from `assets_url`,
 * enqueued only when the banner shows. No inline script or style.
 *
 * Dismissal: the close button posts to `wp_ajax_<prefix>_promo_banner_dismiss`
 * (nonce action of the same name); the user meta
 * `shortpixel_promo_dismissed_<id>` stores the time. The meta key has no
 * plugin prefix on purpose: dismissing the campaign in one ShortPixel plugin
 * hides it in the others too.
 */
class PromoBanner
{
	/** User meta prefix of a dismissed campaign (+ the campaign id). Shared by every ShortPixel plugin. */
	const META_PREFIX = 'shortpixel_promo_dismissed_';

	/** @var array The configuration, see the class docblock and $defaults in the constructor. */
	protected $args;

	/**
	 * @param array $args {
	 *     @type string        $id         Campaign id: the dismissal key. Default 'black-friday-2026'.
	 *     @type string        $start      First moment shown, 'Y-m-d H:i:s' in $timezone.
	 *     @type string        $end        Last moment shown (inclusive), 'Y-m-d H:i:s' in $timezone.
	 *     @type string        $timezone   PHP timezone of start and end. Default 'America/New_York' (US Eastern).
	 *     @type string        $url        The "Claim Deal" link.
	 *     @type string        $prefix     Plugin prefix of the AJAX action and nonce, e.g. 'emr'.
	 *     @type string        $assets_url URL of this module's assets/ directory, with a trailing slash.
	 *     @type string        $version    Asset version for cache busting.
	 *     @type string[]      $screens    Screen ids (get_current_screen()->id) to show on.
	 *     @type callable|null $condition  Returns true on the screens to show on; checked with $screens (either matches).
	 *     @type string        $capability Who sees it. Default 'manage_options'.
	 *     @type string|false  $hook       Action that prints it. Default 'admin_notices' (a `div.notice`
	 *                                     WordPress's script moves below `.wp-header-end` after load).
	 *                                     False: the plugin calls render() itself, right after its
	 *                                     header; no `notice` class, nothing moves it, so no flash.
	 * }
	 */
	public function __construct(array $args = array())
	{
		$defaults   = array(
			'id'         => 'black-friday-2026',
			'start'      => '2026-11-23 00:00:00',
			'end'        => '2026-11-30 23:59:59',
			'timezone'   => 'America/New_York',
			'url'        => 'https://shortpixel.com/pricing',
			'prefix'     => 'shortpixel',
			'assets_url' => '',
			'version'    => '1.0',
			'screens'    => array(),
			'condition'  => null,
			'capability' => 'manage_options',
			'hook'       => 'admin_notices',
		);
		$this->args = array_merge($defaults, $args);
		$this->args['id'] = sanitize_key($this->args['id']);
	}

	/**
	 * Hooks the banner output, its assets and the dismiss endpoint.
	 *
	 * @return void
	 */
	public function register()
	{
		if (false !== $this->args['hook']) {
			add_action($this->args['hook'], array($this, 'render'));
		}
		add_action('admin_enqueue_scripts', array($this, 'enqueue'));
		add_action('wp_ajax_' . $this->getAjaxAction(), array($this, 'ajaxDismiss'));
	}

	/** @return string The AJAX action (and nonce action) of the close button. */
	public function getAjaxAction()
	{
		return sanitize_key($this->args['prefix']) . '_promo_banner_dismiss';
	}

	/** @return string The user meta key of this campaign's dismissal. */
	public function getMetaKey()
	{
		return self::META_PREFIX . $this->args['id'];
	}

	/**
	 * Is $now inside [start, end] in the campaign's timezone? Both ends inclusive.
	 *
	 * @param \DateTimeInterface|null $now Defaults to the current time.
	 * @return bool
	 */
	public function isInWindow($now = null)
	{
		try {
			$zone  = new \DateTimeZone($this->args['timezone']);
			$start = new \DateTimeImmutable($this->args['start'], $zone);
			$end   = new \DateTimeImmutable($this->args['end'], $zone);
			$now   = (null === $now) ? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) : $now;
		} catch (\Exception $e) {
			return false;
		}
		return ($now->getTimestamp() >= $start->getTimestamp() && $now->getTimestamp() <= $end->getTimestamp());
	}

	/**
	 * Has the user dismissed this campaign?
	 *
	 * @param int|null $userId Defaults to the current user.
	 * @return bool
	 */
	public function isDismissed($userId = null)
	{
		$userId = (null === $userId) ? get_current_user_id() : (int) $userId;
		return ($userId > 0 && '' !== (string) get_user_meta($userId, $this->getMetaKey(), true));
	}

	/** @return bool Whether the current screen is one of the configured ones. */
	protected function isOnScreen()
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (null !== $screen && in_array($screen->id, (array) $this->args['screens'], true)) {
			return true;
		}
		return (is_callable($this->args['condition']) && true === (bool) call_user_func($this->args['condition']));
	}

	/** @return string The capability that sees the banner, after `shortpixel/promo_banner/capability`. */
	protected function getCapability()
	{
		return (string) apply_filters('emr/promo_banner/capability', $this->args['capability'], $this->args['id']);
	}

	/**
	 * Every condition of the class docblock, in one place.
	 *
	 * @return bool
	 */
	public function shouldShow()
	{
		$show = (bool) apply_filters('emr/promo_banner/in_window', $this->isInWindow(), $this->args['id'])
			&& current_user_can($this->getCapability())
			&& false === $this->isDismissed()
			&& $this->isOnScreen()
			&& true !== apply_filters('emr/settings/no_banner', false)
			&& ! (defined('SHORTPIXEL_NO_BANNER') && true == constant('SHORTPIXEL_NO_BANNER'));

		return (bool) apply_filters('emr/promo_banner/show', $show, $this->args['id']);
	}

	/**
	 * Enqueues the stylesheet and the dismiss script when the banner shows.
	 *
	 * @return void
	 */
	public function enqueue()
	{
		if (! $this->shouldShow() || '' === $this->args['assets_url']) {
			return;
		}
		$handle = sanitize_key($this->args['prefix']) . '_promo_banner';
		wp_enqueue_style($handle, $this->args['assets_url'] . 'promo-banner.css', array(), $this->args['version']);
		wp_enqueue_script($handle, $this->args['assets_url'] . 'promo-banner.js', array(), $this->args['version'], true);
	}

	/**
	 * Prints the banner when it should show (`admin_notices`).
	 *
	 * @return void
	 */
	public function render()
	{
		if ($this->shouldShow()) {
			echo $this->getMarkup(); // Escaped in getMarkup().
		}
	}

	/**
	 * The "Offer ends" date: the end in the campaign's timezone, localized.
	 *
	 * @return string
	 */
	protected function getEndDate()
	{
		try {
			$zone = new \DateTimeZone($this->args['timezone']);
			$end  = new \DateTimeImmutable($this->args['end'], $zone);
		} catch (\Exception $e) {
			return '';
		}
		return function_exists('wp_date') ? wp_date('F j, Y', $end->getTimestamp(), $zone) : $end->format('F j, Y');
	}

	/**
	 * The banner HTML, escaped.
	 *
	 * @return string
	 */
	public function getMarkup()
	{
		$img  = $this->args['assets_url'] . 'img/';
		$url  = (string) apply_filters('emr/promo_banner/url', $this->args['url'], $this->args['id']);
		// `notice` only on admin_notices, where WordPress moves it below `.wp-header-end`.
		$class = ('admin_notices' === $this->args['hook']) ? 'notice shortpixel-bf' : 'shortpixel-bf';
		$html  = '<div class="' . esc_attr($class) . '" role="region" aria-label="' . esc_attr__('ShortPixel Black Friday offer', 'enable-media-replace') . '"'
			. ' data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '"'
			. ' data-action="' . esc_attr($this->getAjaxAction()) . '"'
			. ' data-nonce="' . esc_attr(wp_create_nonce($this->getAjaxAction())) . '">';

		$html .= '<div class="shortpixel-bf__confetti" aria-hidden="true">' . str_repeat('<i></i>', 10) . '</div>';
		// The flex row; the banner itself is the size container its layout queries (promo-banner.css).
		$html .= '<div class="shortpixel-bf__inner">';
		// Three equal parts: the images (robot and splashes), the perks, the call to action.
		$html .= '<div class="shortpixel-bf__hero">'
			. '<img class="shortpixel-bf__robot" src="' . esc_url($img . 'robot.svg') . '" alt="" aria-hidden="true">'
			. '<img class="shortpixel-bf__forever" src="' . esc_url($img . 'forever.png') . '" alt="' . esc_attr__('25% off forever!', 'enable-media-replace') . '">'
			. '<img class="shortpixel-bf__bfsale" src="' . esc_url($img . 'black-friday-sale.png') . '" alt="' . esc_attr__('Black Friday Sale', 'enable-media-replace') . '">'
			. '</div>';

		/* translators: %s: "25% OFF" in bold. */
		$perkYearly = sprintf(esc_html__('%s any yearly plan for life', 'enable-media-replace'), '<strong>' . esc_html__('25% OFF', 'enable-media-replace') . '</strong>');
		/* translators: %s: "2x" in bold. */
		$perkCredits = sprintf(esc_html__('%s One-Time Credits', 'enable-media-replace'), '<strong>' . esc_html__('2x', 'enable-media-replace') . '</strong>');
		$html .= '<ul class="shortpixel-bf__perks">'
			. '<li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5" fill="currentColor"/></svg><span>' . $perkYearly . '</span></li>'
			. '<li><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/></svg><span>' . $perkCredits . '</span></li>'
			. '</ul>';

		/* translators: %s: the last day of the offer, in bold. */
		$ends  = sprintf(esc_html__('Offer ends %s', 'enable-media-replace'), '<strong>' . esc_html($this->getEndDate()) . '</strong>');
		$html .= '<div class="shortpixel-bf__cta">'
			. '<a class="shortpixel-bf__btn" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('Claim Deal', 'enable-media-replace')
			. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>'
			. '<span class="shortpixel-bf__ends">' . $ends . '</span>'
			. '</div>';
		$html .= '</div>'; // .shortpixel-bf__inner

		$html .= '<button type="button" class="shortpixel-bf__close" aria-label="' . esc_attr__('Dismiss', 'enable-media-replace') . '">&times;</button>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * The close button's AJAX endpoint: stores the dismissal for the current user.
	 *
	 * Answers JSON: success after the nonce and capability checks, an error
	 * (403) otherwise.
	 *
	 * @return void
	 */
	public function ajaxDismiss()
	{
		if (false === check_ajax_referer($this->getAjaxAction(), 'nonce', false) || ! current_user_can($this->getCapability())) {
			wp_send_json_error(null, 403);
			return;
		}
		update_user_meta(get_current_user_id(), $this->getMetaKey(), time());
		wp_send_json_success();
	}
}
