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
                'message' => __('Please enter an API Key.', 'enable-media-replace'),
            ];
        }

        if (strlen($key) !== 20) {
            return [
                'success' => false,
                'message' => __('The API Key must be exactly 20 characters.', 'enable-media-replace'),
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
                    __('Please <a href="%s" target="_blank" rel="noopener">upgrade</a> to the Unlimited or Unlimited AI plan to unlock unlimited background removal.', 'enable-media-replace'),
                    'https://shortpixel.com/pricing'
                ),
            ];
        }

        $this->apiKey = $key;
        update_option(self::OPTION_NAME, $key);

        return [
            'success' => true,
            'message' => __('API Key saved and verified successfully. You now have unlimited background removal.', 'enable-media-replace'),
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
     * @return array{valid: bool, unlimited: bool, message: string}
     */
    private function callApi($key, $validate = false)
    {
        $default = [
            'valid'     => false,
            'unlimited' => false,
            'message'   => __('API Key could not be validated. Please check your internet connection and try again.', 'enable-media-replace'),
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
            $default['message'] = __('The API Key is not correct. Please try again.', 'enable-media-replace');
            return $default;
        }

        return [
            'valid'     => true,
            'unlimited' => property_exists($data, 'Unlimited') && $data->Unlimited == 'true',
            'message'   => '',
        ];
    }
}