<?php
/**
 * HTTP-layer mock of the ShortPixel endpoints EMR talks to, for integration
 * tests.
 *
 * Intercepts every outbound request to *.shortpixel.com via the
 * `pre_http_request` filter (priority 10) so the REAL plugin code (request
 * building in Api, ApiKeyManager and RemoteNoticeController, response
 * parsing, the result download through FileSystemController) runs
 * unmodified while no traffic leaves the test machine. Every intercepted
 * request is logged in $requests.
 *
 * Endpoints, matched on the URL path:
 *   - `free-reducer.php` (Api without a key) and `reducer.php` (Api with a
 *     key): a JSON ARRAY with one object per `urllist` entry, in order.
 *     Success is `Status->Code` "2" with `LosslessURL` / `LossyURL`
 *     pointing at this mock's `/f/<token>` download URLs.
 *   - `cleanup.php` (Api::purgeRemoteCache()): `{"Status":{"Code":"2"}}`.
 *   - `api-status.php` (ApiKeyManager::callApi()):
 *     `{"Status":{"Code":2,"Message":""},"Unlimited":"true"}`.
 *   - `notices.php` (RemoteNoticeController): a JSON array of notices.
 *   - `/f/<token>`: the result image bytes (200, image content type),
 *     stream-aware so download_url() and FileSystemController::downloadFile()
 *     get a real file.
 *   - anything else on shortpixel.com: 200 `{}`.
 *
 * Status code TYPES matter and are reproduced on purpose:
 *   - Api::request() compares the reducer's code STRICTLY against strings
 *     (`'2' === $json[0]->Status->Code`, `'1' === ...`), so the reducer
 *     entries carry the STRING "2" / "1" / "-403". An int 2 there would be
 *     treated as an error by EMR.
 *   - ApiKeyManager::callApi() compares api-status loosely
 *     (`$data->Status->Code != 2`), so api-status sends the int 2, as the
 *     real endpoint does.
 *
 * Note for test authors: Api::request() retries a pending ("1") answer by
 * calling itself after sleep(3) with a key, or sleep(counter + 3) without
 * one, so every waiting round costs real seconds.
 *
 * Knobs (public properties, also settable through setKnob(); reset() puts
 * every one back to its default):
 *   - forceStatusCode (string|null): reducer answer for every URL. '1' =
 *     pending ("Pending"); combined with waitingRounds > 0 the URL turns to
 *     success after that many pending rounds, with waitingRounds = 0 it
 *     stays pending. Any other value (e.g. '-403', '-102') = an error entry
 *     with that code and $errorMessage as `Message`.
 *   - waitingRounds (int): number of pending rounds a URL answers before
 *     succeeding (works with or without forceStatusCode '1').
 *   - errorMessage (string): `Message` of a forced error entry.
 *   - quotaExceeded (bool): reducer answers Code "-403" with a Message
 *     containing 'Quota exceeded' (what Api::request() looks for).
 *   - malformedBody (string|null): reducer returns this raw body verbatim.
 *   - wpErrorMessage (string|null): reducer, cleanup, api-status and
 *     notices calls return WP_Error('http_request_failed', <message>).
 *   - apiStatusCode (int|string): api-status `Status->Code` (2 = valid key,
 *     e.g. -401 = wrong key).
 *   - apiStatusMessage (string): api-status `Status->Message`.
 *   - unlimited (string|null): api-status `Unlimited` ('true' / 'false');
 *     null leaves the property out.
 *   - notices (array|string): notices.php body; an array is JSON-encoded,
 *     a string is sent verbatim.
 *   - resultFixture (string): file in tests/fixtures/ whose bytes the /f/
 *     download URLs serve.
 *
 * @package Enable_Media_Replace
 */
class MockEmrApi {

	/** Reducer status codes, as the strings the real API sends. */
	const CODE_SUCCESS        = '2';
	const CODE_PENDING        = '1';
	const CODE_QUOTA_EXCEEDED = '-403';

	/** Host prefix of the download URLs this mock hands out. */
	const DOWNLOAD_BASE = 'https://api.shortpixel.com/f/';

	/** Knob defaults; reset() restores exactly these. */
	const KNOB_DEFAULTS = array(
		'forceStatusCode'  => null,
		'waitingRounds'    => 0,
		'errorMessage'     => 'Forced by test',
		'quotaExceeded'    => false,
		'malformedBody'    => null,
		'wpErrorMessage'   => null,
		'apiStatusCode'    => 2,
		'apiStatusMessage' => '',
		'unlimited'        => 'true',
		'notices'          => array(),
		'resultFixture'    => 'test-image.jpg',
	);

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,string> token => bytes, for the /f/ download URLs. */
	private $files = array();

	/** @var array<string,int> Per-URL count of reducer rounds already answered. */
	private $rounds = array();

	/**
	 * Log of intercepted requests, oldest first. Each entry:
	 * [ 'url' => string, 'path' => string, 'method' => string,
	 *   'args' => array (the WP HTTP args), 'request' => array|null ].
	 * 'request' is the decoded JSON body (reducer, cleanup), the form-field
	 * body (api-status) or the query arguments (notices), null otherwise.
	 *
	 * @var array
	 */
	public $requests = array();

	/** @var string|null See the class docblock. */
	public $forceStatusCode = null;

	/** @var int See the class docblock. */
	public $waitingRounds = 0;

	/** @var string See the class docblock. */
	public $errorMessage = 'Forced by test';

	/** @var bool See the class docblock. */
	public $quotaExceeded = false;

	/** @var string|null See the class docblock. */
	public $malformedBody = null;

	/** @var string|null See the class docblock. */
	public $wpErrorMessage = null;

	/** @var int|string See the class docblock. */
	public $apiStatusCode = 2;

	/** @var string See the class docblock. */
	public $apiStatusMessage = '';

	/** @var string|null See the class docblock. */
	public $unlimited = 'true';

	/** @var array|string See the class docblock. */
	public $notices = array();

	/** @var string See the class docblock. */
	public $resultFixture = 'test-image.jpg';

	public static function getInstance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Hook the mock into pre_http_request (priority 10) and return it. */
	public static function register(): self {
		$mock = self::getInstance();
		add_filter( 'pre_http_request', array( $mock, 'intercept' ), 10, 3 );
		return $mock;
	}

	/** Remove the filter and drop the instance (and with it all state). */
	public static function unregister(): void {
		if ( null !== self::$instance ) {
			remove_filter( 'pre_http_request', array( self::$instance, 'intercept' ), 10 );
		}
		self::$instance = null;
	}

	private function __construct() {
	}

	// -------------------------------------------------------------------
	// Interception
	// -------------------------------------------------------------------

	/**
	 * pre_http_request callback. Returns $preempt unchanged for any
	 * non-shortpixel host, so the fail-fast filter of the integration base
	 * classes (priority 9999) can refuse it.
	 *
	 * @param false|array|WP_Error $preempt Filter passthrough.
	 * @param array                $args    HTTP request args.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function intercept( $preempt, $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || false === strpos( $host, 'shortpixel.com' ) ) {
			return $preempt;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$args = is_array( $args ) ? $args : array();

		$this->requests[] = array(
			'url'     => $url,
			'path'    => $path,
			'method'  => isset( $args['method'] ) ? (string) $args['method'] : 'GET',
			'args'    => $args,
			'request' => $this->decodeParams( $url, $args ),
		);

		if ( 0 === strpos( $path, '/f/' ) ) {
			return $this->handleDownload( $path, $args );
		}

		// Matches both free-reducer.php and reducer.php; they answer in the
		// same shape. requestsTo() tells them apart.
		if ( false !== strpos( $path, 'reducer.php' ) ) {
			if ( null !== $this->wpErrorMessage ) {
				return new WP_Error( 'http_request_failed', $this->wpErrorMessage );
			}
			if ( null !== $this->malformedBody ) {
				return $this->httpResponse( $this->malformedBody );
			}
			return $this->handleReducer( $url, $args );
		}

		if ( false !== strpos( $path, 'cleanup.php' ) ) {
			if ( null !== $this->wpErrorMessage ) {
				return new WP_Error( 'http_request_failed', $this->wpErrorMessage );
			}
			return $this->httpResponse( wp_json_encode( $this->cleanupBody() ) );
		}

		if ( false !== strpos( $path, 'api-status.php' ) ) {
			if ( null !== $this->wpErrorMessage ) {
				return new WP_Error( 'http_request_failed', $this->wpErrorMessage );
			}
			return $this->httpResponse( wp_json_encode( $this->apiStatusBody() ) );
		}

		if ( false !== strpos( $path, 'notices.php' ) ) {
			if ( null !== $this->wpErrorMessage ) {
				return new WP_Error( 'http_request_failed', $this->wpErrorMessage );
			}
			return $this->httpResponse( $this->noticesBody() );
		}

		// Anything else on *.shortpixel.com: benign empty-JSON 200 so
		// callers fail soft. EMR calls no other endpoint today; a test that
		// sees this in requestsTo() has found a new one.
		return $this->httpResponse( '{}' );
	}

	// -------------------------------------------------------------------
	// Reducer endpoints (free-reducer.php / reducer.php)
	// -------------------------------------------------------------------

	/**
	 * Answer a reducer call: one entry per urllist item, in order.
	 *
	 * Api::request() sends a JSON body; without a key the urllist entries
	 * are urlencode()d, with a key they are raw. Either way the entry's
	 * OriginalURL echoes the submitted value.
	 */
	private function handleReducer( $url, array $args ) {
		$request = $this->decodeParams( $url, $args );
		$urllist = ( is_array( $request ) && isset( $request['urllist'] ) ) ? array_values( (array) $request['urllist'] ) : array();

		$response = array();
		foreach ( $urllist as $sourceUrl ) {
			$response[] = $this->buildReducerEntry( (string) $sourceUrl );
		}

		return $this->httpResponse( wp_json_encode( $response ) );
	}

	/** Pick the entry for one urllist item according to the knobs. */
	private function buildReducerEntry( $sourceUrl ) {
		if ( $this->quotaExceeded ) {
			return $this->reducerErrorEntry( self::CODE_QUOTA_EXCEEDED, 'Quota exceeded. You need to subscribe to a plan to continue.', $sourceUrl );
		}

		$forced = ( null === $this->forceStatusCode ) ? null : (string) $this->forceStatusCode;

		if ( null !== $forced && self::CODE_PENDING !== $forced ) {
			return $this->reducerErrorEntry( $forced, $this->errorMessage, $sourceUrl );
		}

		$key                  = md5( $sourceUrl );
		$this->rounds[ $key ] = isset( $this->rounds[ $key ] ) ? $this->rounds[ $key ] + 1 : 1;

		if ( self::CODE_PENDING === $forced && 0 === (int) $this->waitingRounds ) {
			return $this->reducerPendingEntry( $sourceUrl );
		}
		if ( $this->rounds[ $key ] <= (int) $this->waitingRounds ) {
			return $this->reducerPendingEntry( $sourceUrl );
		}

		return $this->reducerSuccessEntry( $sourceUrl );
	}

	/**
	 * A successful reducer entry for $sourceUrl. Stashes the result bytes
	 * and returns download URLs for them: LossyURL is what Api::request()
	 * uses for compression level 1 or 2, LosslessURL otherwise.
	 *
	 * @param string $sourceUrl URL as submitted in urllist.
	 * @return array{Status: array{Code: string, Message: string}, OriginalURL: string, LosslessURL: string, LossyURL: string, OriginalSize: int, LosslessSize: int, LossySize: int}
	 */
	public function reducerSuccessEntry( $sourceUrl ) {
		$bytes        = $this->resultBytes();
		$key          = md5( $sourceUrl );
		$localPath    = $this->urlToPath( rawurldecode( (string) $sourceUrl ) );
		$originalSize = ( null !== $localPath && file_exists( $localPath ) ) ? (int) filesize( $localPath ) : 0;

		return array(
			'Status'       => array(
				'Code'    => self::CODE_SUCCESS,
				'Message' => 'Success',
			),
			'OriginalURL'  => $sourceUrl,
			'LosslessURL'  => $this->stashImage( $bytes, $key . '-lossless' ),
			'LossyURL'     => $this->stashImage( $bytes, $key . '-lossy' ),
			'OriginalSize' => $originalSize,
			'LosslessSize' => strlen( $bytes ),
			'LossySize'    => strlen( $bytes ),
		);
	}

	/**
	 * A pending reducer entry (Api::request() retries on it).
	 *
	 * @return array{Status: array{Code: string, Message: string}, OriginalURL: string}
	 */
	public function reducerPendingEntry( $sourceUrl ) {
		return array(
			'Status'      => array(
				'Code'    => self::CODE_PENDING,
				'Message' => 'Pending',
			),
			'OriginalURL' => $sourceUrl,
		);
	}

	/**
	 * An error reducer entry. Api::request() copies `Message` into its
	 * result, and replaces it with its own text when it contains
	 * 'Quota exceeded'.
	 *
	 * @param string $code      Status code, as a string (e.g. '-403').
	 * @param string $message   Status message.
	 * @param string $sourceUrl URL as submitted in urllist.
	 * @return array{Status: array{Code: string, Message: string}, OriginalURL: string}
	 */
	public function reducerErrorEntry( $code, $message, $sourceUrl ) {
		return array(
			'Status'      => array(
				'Code'    => (string) $code,
				'Message' => (string) $message,
			),
			'OriginalURL' => $sourceUrl,
		);
	}

	// -------------------------------------------------------------------
	// Other endpoints
	// -------------------------------------------------------------------

	/**
	 * Body of cleanup.php. Api::purgeRemoteCache() only logs it.
	 *
	 * @return array{Status: array{Code: string}}
	 */
	public function cleanupBody() {
		return array( 'Status' => array( 'Code' => self::CODE_SUCCESS ) );
	}

	/**
	 * Body of api-status.php, from the apiStatusCode / apiStatusMessage /
	 * unlimited knobs. ApiKeyManager::callApi() reports a valid key when
	 * `Status->Code` loosely equals 2, and Unlimited when the property
	 * loosely equals 'true'.
	 *
	 * @return array
	 */
	public function apiStatusBody() {
		$body = array(
			'Status' => array(
				'Code'    => $this->apiStatusCode,
				'Message' => $this->apiStatusMessage,
			),
		);
		if ( null !== $this->unlimited ) {
			$body['Unlimited'] = $this->unlimited;
		}
		return $body;
	}

	/**
	 * Body of notices.php: the notices knob, JSON-encoded when it is an
	 * array, verbatim when it is a string. RemoteNoticeController treats a
	 * body that does not decode to an array as "no notices".
	 *
	 * @return string
	 */
	public function noticesBody() {
		if ( is_string( $this->notices ) ) {
			return $this->notices;
		}
		return (string) wp_json_encode( array_values( (array) $this->notices ) );
	}

	// -------------------------------------------------------------------
	// Download endpoint (/f/<token>)
	// -------------------------------------------------------------------

	/**
	 * Store image bytes under a token and return the download URL that
	 * serves them. Used by reducerSuccessEntry(); tests can call it to get
	 * a valid result URL without going through the reducer.
	 *
	 * @param string|null $bytes Bytes to serve; null = the resultFixture bytes.
	 * @param string|null $token Token without extension; null = random.
	 * @return string https://api.shortpixel.com/f/<token>.<ext>
	 */
	public function stashImage( $bytes = null, $token = null ) {
		if ( null === $bytes ) {
			$bytes = $this->resultBytes();
		}
		if ( null === $token ) {
			$token = md5( uniqid( 'emr-mock', true ) );
		}
		$ext                  = strtolower( pathinfo( $this->resultFixture, PATHINFO_EXTENSION ) );
		$name                 = $token . ( '' !== $ext ? '.' . $ext : '' );
		$this->files[ $name ] = (string) $bytes;
		return self::DOWNLOAD_BASE . $name;
	}

	/**
	 * Serve stashed bytes. An unknown token answers 404, so a test that
	 * downloads a URL this mock never handed out fails visibly.
	 *
	 * @return array WP HTTP response (stream-aware).
	 */
	private function handleDownload( $path, array $args ) {
		$token = basename( $path );

		if ( ! isset( $this->files[ $token ] ) ) {
			return $this->httpResponse( 'mock: unknown file token ' . $token, 404 );
		}

		$bytes    = $this->files[ $token ];
		$filetype = wp_check_filetype( $token );
		$headers  = array(
			'content-type'   => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
			'content-length' => (string) strlen( $bytes ),
		);

		// download_url() and FileSystemController::downloadFile() use stream
		// mode: WP expects the body written to $args['filename'] and left
		// off the response.
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$response             = $this->httpResponse( '', 200, $headers );
			$response['filename'] = $args['filename'];
			return $response;
		}

		return $this->httpResponse( $bytes, 200, $headers );
	}

	/**
	 * Bytes of the result image: the resultFixture file, or, when it is
	 * missing, a small JPEG drawn with GD.
	 *
	 * @return string
	 */
	private function resultBytes() {
		$path = dirname( __DIR__, 2 ) . '/fixtures/' . $this->resultFixture;
		if ( file_exists( $path ) ) {
			return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$img = imagecreatetruecolor( 16, 16 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 255, 255 ) );
		ob_start();
		imagejpeg( $img, null, 80 );
		$bytes = (string) ob_get_clean();
		if ( PHP_VERSION_ID < 80000 ) {
			// No-op from 8.0 and deprecated in 8.5; still frees memory on 7.4.
			imagedestroy( $img );
		}
		return $bytes;
	}

	// -------------------------------------------------------------------
	// Test API
	// -------------------------------------------------------------------

	/**
	 * Set one knob by name.
	 *
	 * @param string $name  A key of KNOB_DEFAULTS.
	 * @param mixed  $value The new value.
	 * @return self
	 * @throws InvalidArgumentException For an unknown knob, so a typo cannot
	 *                                  silently leave the default in place.
	 */
	public function setKnob( $name, $value ) {
		if ( ! array_key_exists( $name, self::KNOB_DEFAULTS ) ) {
			throw new InvalidArgumentException( 'MockEmrApi has no knob named ' . $name . '; known: ' . implode( ', ', array_keys( self::KNOB_DEFAULTS ) ) );
		}
		$this->$name = $value;
		return $this;
	}

	/** @return array All intercepted requests, oldest first. */
	public function getRequests() {
		return $this->requests;
	}

	/** @return array|null The most recent intercepted request, or null. */
	public function lastRequest() {
		if ( empty( $this->requests ) ) {
			return null;
		}
		return $this->requests[ count( $this->requests ) - 1 ];
	}

	/**
	 * Intercepted requests whose URL path contains $pathFragment
	 * (e.g. 'free-reducer.php', 'api-status', '/f/').
	 *
	 * @param string $pathFragment Substring of the path.
	 * @return array Matching requests, oldest first, re-indexed from 0.
	 */
	public function requestsTo( $pathFragment ) {
		$matches = array();
		foreach ( $this->requests as $request ) {
			if ( false !== strpos( $request['path'], (string) $pathFragment ) ) {
				$matches[] = $request;
			}
		}
		return $matches;
	}

	/** Reset the request log, stashed files, round counters and every knob, keeping the filter registered. */
	public function reset(): void {
		$this->requests = array();
		$this->files    = array();
		$this->rounds   = array();
		foreach ( self::KNOB_DEFAULTS as $name => $value ) {
			$this->$name = $value;
		}
	}

	// -------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------

	/** Map an uploads URL (possibly carrying a query) to a local file path, or null. */
	private function urlToPath( $url ) {
		$url     = strtok( $url, '?' );
		$uploads = wp_get_upload_dir();
		if ( is_string( $url ) && 0 === strpos( $url, $uploads['baseurl'] ) ) {
			return $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
		}
		return null;
	}

	/**
	 * Decode the request payload: a JSON string body (reducer, cleanup), a
	 * form-field array body (api-status), or else the query arguments
	 * (notices.php is a GET with its parameters in the URL).
	 */
	private function decodeParams( $url, array $args ) {
		if ( isset( $args['body'] ) && is_string( $args['body'] ) && '' !== $args['body'] ) {
			$decoded = json_decode( $args['body'], true );
			return is_array( $decoded ) ? $decoded : null;
		}
		if ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
			return $args['body'];
		}
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			$params = array();
			wp_parse_str( $query, $params );
			return $params;
		}
		return null;
	}

	/** Build a WP-HTTP-shaped response array. */
	private function httpResponse( $body, $code = 200, array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => ( 200 === $code ) ? 'OK' : 'Not Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
