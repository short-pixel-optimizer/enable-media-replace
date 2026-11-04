<?php
/**
 * Storage and verification of the ShortPixel API key used for remove-background.
 *
 * @package EnableMediaReplace
 */
namespace EnableMediaReplace;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Stores, masks and verifies the ShortPixel API key.
 *
 * Lifecycle: singleton via getInstance() (the constructor is protected). The key is read
 * from the option once, when the instance is created. Registers no hooks. The key is
 * stored in plain text in the `emr_shortpixel_api_key` option and is only accepted when
 * the account has an Unlimited plan.
 */
class ApiKeyManager
{
    /**
     * The singleton instance.
     *
     * @var ApiKeyManager|null
     */
    protected static $instance;

    /**
     * Option holding the API key (plain text, autoloaded by default).
     *
     * @var string
     */
    const OPTION_NAME  = 'emr_shortpixel_api_key';

    /**
     * Key status endpoint used by callApi().
     *
     * @var string
     */
    const API_ENDPOINT = 'https://api.shortpixel.com/v2/api-status.php';

    /**
     * Transient caching the AI credits of the stored key (getAiCredits()).
     *
     * @var string
     */
    const CREDITS_TRANSIENT = 'emr_ai_credits';

    /**
     * Where "Upgrade to Unlimited AI" leads: the pricing page, with EMR's UTM parameters.
     *
     * @var string
     */
    const UPGRADE_AI_URL = 'https://shortpixel.com/pricing?utm_source=plugin&utm_medium=emr&utm_campaign=emr_unlimited_ai';

    /**
     * The "upgrade" link of the message for a key that is not on an Unlimited plan.
     *
     * @var string
     */
    const UPGRADE_UNLIMITED_URL = 'https://shortpixel.com/pricing?utm_source=plugin&utm_medium=emr&utm_campaign=emr_upgrade_unlimited';

    /**
     * The stored key, or '' when none.
     *
     * @var string
     */
    protected $apiKey = '';

    /**
     * Loads the key from the option.
     */
    protected function __construct()
    {
        $this->apiKey = (string) get_option(self::OPTION_NAME, '');
    }

    /**
     * Returns the singleton.
     *
     * @return ApiKeyManager
     */
    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new ApiKeyManager();
        }
        return self::$instance;
    }

    /**
     * Returns the stored key.
     *
     * @return string The key, or '' when none.
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * Whether a key is stored.
     *
     * @return bool
     */
    public function hasApiKey()
    {
        return ! empty($this->apiKey);
    }

    /**
     * Returns the key for display: first 4 and last 4 characters, asterisks in between.
     *
     * @todo For a stored key shorter than 8 characters (only possible when the option is
     *       written outside saveAndVerify()), str_repeat() gets a negative count and throws
     *       a ValueError on PHP 8. Suggested fix: `max(0, $len - 8)`.
     *
     * @return string The masked key, or the translated "Not set" when none.
     */
    public function getMaskedKey()
    {
        if (empty($this->apiKey)) {
            return __('Not set', 'enable-media-replace');
        }
        $len = strlen($this->apiKey);
        return substr($this->apiKey, 0, 4) . str_repeat('*', $len - 8) . substr($this->apiKey, -4);
    }

    /**
     * Validate a new key and save it if valid with Unlimited plan.
     * Returns ['success' => bool, 'message' => string].
     *
     * The key is trimmed and sanitized, must be non-empty and exactly 20 characters, is
     * checked with callApi() in validate mode (sends DomainCheck and WP/PHP versions), and
     * must report an Unlimited plan. Only then is it stored in the option and in this
     * instance. The upgrade message contains an HTML link.
     *
     * @param string $key The key as submitted.
     * @return array{success: bool, message: string}
     */
    public function saveAndVerify($key)
    {
        $key = sanitize_text_field(trim($key));

        if (empty($key)) {
            return [
                'success' => false,
                'message' => __('Please enter an API key.', 'enable-media-replace'),
            ];
        }

        if (strlen($key) !== 20) {
            return [
                'success' => false,
                'message' => __('The API key must be exactly 20 characters.', 'enable-media-replace'),
            ];
        }

        $result = $this->callApi($key, true);

        if (! $result['valid']) {
            return ['success' => false, 'message' => $result['message']];
        }

        if (! $result['unlimited']) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('This API key is not on an Unlimited plan. Please <a href="%s" target="_blank" rel="noopener">upgrade</a> to Unlimited or Unlimited AI to unlock background removal and image upscale with it.', 'enable-media-replace'),
                    esc_url(self::UPGRADE_UNLIMITED_URL)
                ),
            ];
        }

        $this->apiKey = $key;
        update_option(self::OPTION_NAME, $key);

        return [
            'success' => true,
            'message' => __('API key saved and verified. Bulk background removal and image upscale is unlocked.', 'enable-media-replace'),
        ];
    }

    /**
     * Removes the key from this instance and deletes the option.
     *
     * @return void
     */
    public function deleteKey()
    {
        $this->apiKey = '';
        delete_option(self::OPTION_NAME);
        delete_transient(self::CREDITS_TRANSIENT);
    }

    /**
     * The AI credits of the stored key: background removal and image upscale
     * use one per image. Unlimited plans get a monthly amount, Unlimited AI has
     * no limit.
     *
     * Read from the `AIUnlimited` and `CaptionsCalls*` fields of api-status.php,
     * the same fields ShortPixel Image Optimizer shows as "AI credits". Cached
     * in the CREDITS_TRANSIENT transient; every successful callApi() refreshes
     * it (verifyUnlimitedPlan() runs before each single-screen preview), and
     * the cache expires after `emr/ai_credits/ttl` seconds (default 600).
     *
     * @hook filter emr/ai_credits/ttl Seconds the credits stay cached. Default 600.
     *
     * @param bool $refresh Ask the API even when a cached value exists.
     * @return array{unlimited: bool, quota: int, used: int, remaining: int}|false
     *         False without a key, or when the API could not be reached.
     */
    public function getAiCredits($refresh = false)
    {
        if (empty($this->apiKey)) {
            return false;
        }
        $cached = get_transient(self::CREDITS_TRANSIENT);
        if (false === $refresh && is_array($cached) && isset($cached['key']) && hash_equals($cached['key'], md5($this->apiKey))) {
            unset($cached['key']);
            return $cached;
        }
        $result = $this->callApi($this->apiKey, false);
        return ($result['valid'] && is_array($result['credits'])) ? $result['credits'] : false;
    }

    /**
     * Drops the cached AI credits, so the next getAiCredits() asks the API.
     *
     * @return void
     */
    public function forgetAiCredits()
    {
        delete_transient(self::CREDITS_TRANSIENT);
    }

    /**
     * Reads the AI credits from an api-status.php answer and caches them.
     *
     * `AIUnlimited` is accepted as a boolean or the strings 'true' / '1';
     * a missing remaining count is computed from quota minus used, never
     * below 0.
     *
     * @param object $data Decoded api-status.php answer.
     * @param string $key  The key it was asked for (the cache is tied to it).
     * @return array{unlimited: bool, quota: int, used: int, remaining: int}
     */
    private function storeCredits($data, $key)
    {
        $quota     = isset($data->CaptionsCallsQuota) ? max(0, (int) $data->CaptionsCallsQuota) : 0;
        $used      = isset($data->CaptionsCallsMade) ? max(0, (int) $data->CaptionsCallsMade) : 0;
        $remaining = isset($data->CaptionsCallsRemaining) ? (int) $data->CaptionsCallsRemaining : $quota - $used;
        $flag      = isset($data->AIUnlimited) ? $data->AIUnlimited : false;
        $credits   = array(
            'unlimited' => (true === $flag || 1 === $flag || (is_string($flag) && in_array(strtolower($flag), array('true', '1'), true))),
            'quota'     => $quota,
            'used'      => $used,
            'remaining' => max(0, $remaining),
        );
        $ttl = (int) apply_filters('emr/ai_credits/ttl', 10 * MINUTE_IN_SECONDS);
        set_transient(self::CREDITS_TRANSIENT, $credits + array('key' => md5($key)), max(60, $ttl));
        return $credits;
    }

    /**
     * Check whether the stored key currently has an Unlimited plan.
     * Always calls the API — no caching — so plan changes are caught immediately.
     *
     * Api::request() calls this on the first request of every remove-background run, so
     * each run costs one extra blocking HTTP call (up to 15 s, twice with the http fallback).
     *
     * @return bool True when a key is stored, the API accepts it and reports Unlimited.
     */
    public function verifyUnlimitedPlan()
    {
        if (empty($this->apiKey)) {
            return false;
        }

        $result = $this->callApi($this->apiKey, false);
        return $result['valid'] && $result['unlimited'];
    }

    /**
     * Queries the api-status endpoint for a key.
     *
     * POSTs `key` and `host` (15 s timeout); in validate mode also `DomainCheck` (site URL)
     * and `Info` ("<wp version>|<php version>"). When the https request returns a WP_Error,
     * the same request, including the key, is retried over plain http. A non-200 status,
     * an empty body or a second WP_Error gives the default "could not be validated" result.
     * A `Status->Code` other than 2 (loose compare) gives "not correct". The plan is
     * Unlimited when the response has an `Unlimited` property that loosely equals the
     * string 'true' (so the string 'true' or boolean true; not 1 or '1').
     *
     * @param string $key      The key to check.
     * @param bool   $validate Whether to send the domain-check fields (on save).
     * @return array{valid: bool, unlimited: bool, credits: array|false, message: string}
     *         `credits`: the AI credits from storeCredits() (also cached) for a valid key.
     */
    private function callApi($key, $validate = false)
    {
        $default = [
            'valid'     => false,
            'unlimited' => false,
            'credits'   => false,
            'message'   => __('The API key could not be validated. Please check your internet connection and try again.', 'enable-media-replace'),
        ];

        $body = [
            'key'  => $key,
            'host' => parse_url(get_site_url(), PHP_URL_HOST),
        ];

        if ($validate) {
            $body['DomainCheck'] = get_site_url();
            $body['Info']        = get_bloginfo('version') . '|' . phpversion();
        }

        $args     = ['timeout' => 15, 'body' => $body];
        $response = wp_remote_post(self::API_ENDPOINT, $args);

        if (is_wp_error($response)) {
            $httpUrl  = str_replace('https://', 'http://', self::API_ENDPOINT);
            $response = wp_remote_post($httpUrl, $args);
            if (is_wp_error($response)) {
                return $default;
            }
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return $default;
        }

        $data = json_decode(wp_remote_retrieve_body($response));

        if (empty($data)) {
            return $default;
        }

        if ($data->Status->Code != 2) {
            $default['message'] = __('The API key is not correct. Please try again.', 'enable-media-replace');
            return $default;
        }

        return [
            'valid'     => true,
            'unlimited' => property_exists($data, 'Unlimited') && $data->Unlimited == 'true',
            'credits'   => $this->storeCredits($data, $key),
            'message'   => '',
        ];
    }
}