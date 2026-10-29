<?php
/**
 * Plugin Name: EMR E2E: Mock ShortPixel and wordpress.org APIs
 * Description: Test-support mu-plugin for the browser E2E suite. Answers every outbound request to *.shortpixel.com and *.wordpress.org at the WordPress HTTP layer, so the REAL plugin code runs while no traffic leaves the container. Inert unless the EMR_E2E constant is true.
 *
 * Port of tests/Integration/Helpers/MockEmrApi.php (the PHPUnit mock) to a
 * LIVE WordPress. The knobs and the response shapes are the same; what
 * differs:
 *
 *   1. State is persisted, not in memory. Every browser request is a fresh
 *      PHP process, so the knobs, the per-URL round counters and the request
 *      log live in options (emr_e2e_mock_knobs, emr_e2e_mock_state,
 *      emr_e2e_mock_requests) and the result images live on disk under
 *      uploads/emr-e2e-mock/.
 *   2. wordpress.org is answered too: api.wordpress.org with an empty JSON
 *      success, downloads.wordpress.org/plugin/<slug>.zip with a zip built
 *      on the fly that holds a stub plugin (so the upsell installer in
 *      classes/external/upsell_installer.php can install and activate it),
 *      every other *.wordpress.org URL (dashboard feeds) with an empty RSS
 *      feed. The stub's main file carries the STUB_MARKER string, which is
 *      how the support endpoint's reset recognises and removes it.
 *
 * Endpoints on *.shortpixel.com, matched on the URL path:
 *   - free-reducer.php (Api without a key) and reducer.php (Api with a key):
 *     a JSON array with one entry per `urllist` item. Success is
 *     Status->Code "2" (a STRING: Api::request() compares strictly) with
 *     LosslessURL / LossyURL pointing at /f/<token> on this mock.
 *   - cleanup.php (Api::purgeRemoteCache()): {"Status":{"Code":"2"}}.
 *   - api-status.php (ApiKeyManager::callApi()): Status->Code (an INT,
 *     compared loosely) and Unlimited.
 *   - notices.php (RemoteNoticeController): the `notices` knob.
 *   - /f/<token>.<ext>: the stashed result bytes, stream-aware (the plugin
 *     downloads with `stream` + `filename`).
 *   - anything else: 200 `{}`.
 *
 * The browser also shows the result image: js/remove_bg.js puts the
 * LosslessURL into an <img>. tests/E2E/fixtures.ts answers that browser
 * request from the same stash (served by Apache under
 * /wp-content/uploads/emr-e2e-mock/), so the preview renders without the
 * browser reaching shortpixel.com.
 *
 * Knobs (POST /emr-e2e/v1/mock merges them; unknown names are refused):
 *   forceStatusCode  string|null  reducer answer for every URL. '1' = pending
 *                                 (with waitingRounds > 0 it succeeds after
 *                                 that many rounds, with 0 it stays pending);
 *                                 any other value = an error entry with that
 *                                 code and errorMessage.
 *   waitingRounds    int          pending rounds per URL before success. Each
 *                                 round costs real seconds: Api::request()
 *                                 sleeps 3 s (with a key) or counter + 3 s
 *                                 (free) before asking again.
 *   errorMessage     string       Message of a forced error entry.
 *   quotaExceeded    bool         reducer answers Code "-403" with a Message
 *                                 containing "Quota exceeded".
 *   malformedBody    string|null  reducer body, verbatim.
 *   wpErrorMessage   string|null  reducer, cleanup, api-status and notices
 *                                 answer WP_Error('http_request_failed').
 *   apiStatusCode    int|string   api-status Status->Code (2 = valid key,
 *                                 -401 = wrong key).
 *   apiStatusMessage string       api-status Status->Message.
 *   unlimited        string|null  api-status Unlimited ('true' / 'false');
 *                                 null leaves the property out. 'false' with
 *                                 a stored key is the "plan expired" state:
 *                                 Api::request() refuses with plan_expired.
 *   notices          array|string notices.php body (array = JSON-encoded).
 *   resultFixture    string       file in tests/fixtures/ whose bytes the /f/
 *                                 URLs serve; a missing file falls back to a
 *                                 small GD-drawn JPEG.
 *   downloadsFail    bool         downloads.wordpress.org answers
 *                                 WP_Error('http_request_failed'), so the
 *                                 upsell installer's download fails.
 *
 * @package Enable_Media_Replace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'EMR_E2E' ) || ! EMR_E2E ) {
	return;
}

class EMR_E2E_MockApi {

	/** Reducer status codes, as the strings the real API sends. */
	const CODE_SUCCESS        = '2';
	const CODE_PENDING        = '1';
	const CODE_QUOTA_EXCEEDED = '-403';

	/** Host prefix of the download URLs this mock hands out. */
	const DOWNLOAD_BASE = 'https://api.shortpixel.com/f/';

	const OPTION_KNOBS    = 'emr_e2e_mock_knobs';
	const OPTION_STATE    = 'emr_e2e_mock_state';
	const OPTION_REQUESTS = 'emr_e2e_mock_requests';
	const REQUEST_LOG_CAP = 200;

	/** Directory under uploads holding the stashed result images. */
	const STASH_DIRNAME = 'emr-e2e-mock';

	/** Present in every stub plugin this mock builds; reset() looks for it. */
	const STUB_MARKER = 'EMR E2E stub plugin';

	/** Knob defaults; resetAll() restores exactly these. */
	const KNOB_DEFAULTS = array(
		'forceStatusCode'  => null,
		'waitingRounds'    => 0,
		'errorMessage'     => 'Forced by E2E test',
		'quotaExceeded'    => false,
		'malformedBody'    => null,
		'wpErrorMessage'   => null,
		'apiStatusCode'    => 2,
		'apiStatusMessage' => '',
		'unlimited'        => 'true',
		'notices'          => array(),
		'resultFixture'    => 'test-image.jpg',
		'downloadsFail'    => false,
	);

	/**
	 * Main file of each plugin the upsell installer can download, keyed by
	 * the zip's slug (the basenames emr_activate() activates).
	 */
	const STUB_PLUGINS = array(
		'shortpixel-image-optimiser'    => array( 'wp-shortpixel.php', 'ShortPixel Image Optimizer' ),
		'shortpixel-adaptive-images'    => array( 'short-pixel-ai.php', 'ShortPixel Adaptive Images' ),
		'fastpixel-website-accelerator' => array( 'fastpixel.php', 'FastPixel' ),
		'envira-gallery-lite'           => array( 'envira-gallery-lite.php', 'Envira Gallery Lite' ),
	);

	/** @var array Knobs of the current request. */
	private $knobs = array();

	/** @var array Per-URL round counters, persisted between requests. */
	private $state = array();

	public static function register() {
		add_filter( 'pre_http_request', array( new self(), 'intercept' ), 10, 3 );
	}

	/** The stored knobs over the defaults. */
	public static function knobs() {
		return array_merge( self::KNOB_DEFAULTS, (array) get_option( self::OPTION_KNOBS, array() ) );
	}

	/**
	 * Merge knobs into the stored set.
	 *
	 * @param array $knobs Name => value.
	 * @return array|WP_Error The full knob set, or an error naming the
	 *                        unknown knob, so a typo cannot silently leave a
	 *                        default in place.
	 */
	public static function setKnobs( array $knobs ) {
		foreach ( array_keys( $knobs ) as $name ) {
			if ( ! array_key_exists( $name, self::KNOB_DEFAULTS ) ) {
				return new WP_Error(
					'emr_e2e_unknown_knob',
					'The mock has no knob named ' . $name . '; known: ' . implode( ', ', array_keys( self::KNOB_DEFAULTS ) ),
					array( 'status' => 400 )
				);
			}
		}
		$merged = array_merge( self::knobs(), $knobs );
		update_option( self::OPTION_KNOBS, $merged, false );
		return $merged;
	}

	/** Wipe knobs, counters, request log and the on-disk stash. */
	public static function resetAll() {
		delete_option( self::OPTION_KNOBS );
		delete_option( self::OPTION_STATE );
		delete_option( self::OPTION_REQUESTS );

		$dir = self::stashDir();
		foreach ( (array) glob( $dir . '*' ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/** @return array The request log, oldest first. */
	public static function requests() {
		return array_values( (array) get_option( self::OPTION_REQUESTS, array() ) );
	}

	/** Absolute path of the stash directory, with trailing slash (created on demand). */
	public static function stashDir() {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::STASH_DIRNAME . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	// -------------------------------------------------------------------
	// Interception
	// -------------------------------------------------------------------

	/**
	 * pre_http_request callback. Hosts other than *.shortpixel.com and
	 * *.wordpress.org pass through untouched.
	 *
	 * @param false|array|WP_Error $preempt Filter passthrough.
	 * @param array                $args    HTTP request args.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function intercept( $preempt, $args, $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$isSp = self::hostIs( $host, 'shortpixel.com' );
		$isWp = self::hostIs( $host, 'wordpress.org' );
		if ( ! $isSp && ! $isWp ) {
			return $preempt;
		}

		$args        = is_array( $args ) ? $args : array();
		$path        = (string) wp_parse_url( $url, PHP_URL_PATH );
		$this->knobs = self::knobs();
		$this->state = array_merge( array( 'rounds' => array() ), (array) get_option( self::OPTION_STATE, array() ) );

		$this->logRequest( $url, $host, $path, $args );

		try {
			if ( $isWp ) {
				return $this->handleWordPressOrg( $host, $path, $args );
			}
			return $this->handleShortPixel( $url, $path, $args );
		} finally {
			update_option( self::OPTION_STATE, $this->state, false );
		}
	}

	/** True when $host is $domain or one of its subdomains. */
	private static function hostIs( $host, $domain ) {
		return $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain;
	}

	private function handleShortPixel( $url, $path, array $args ) {
		if ( 0 === strpos( $path, '/f/' ) ) {
			return $this->handleDownload( $path, $args );
		}

		// Matches both free-reducer.php and reducer.php: same answer shape.
		if ( false !== strpos( $path, 'reducer.php' ) ) {
			if ( null !== $this->knobs['wpErrorMessage'] ) {
				return new WP_Error( 'http_request_failed', (string) $this->knobs['wpErrorMessage'] );
			}
			if ( null !== $this->knobs['malformedBody'] ) {
				return $this->httpResponse( (string) $this->knobs['malformedBody'] );
			}
			return $this->handleReducer( $url, $args );
		}

		if ( false !== strpos( $path, 'cleanup.php' ) ) {
			if ( null !== $this->knobs['wpErrorMessage'] ) {
				return new WP_Error( 'http_request_failed', (string) $this->knobs['wpErrorMessage'] );
			}
			return $this->httpResponse( wp_json_encode( array( 'Status' => array( 'Code' => self::CODE_SUCCESS ) ) ) );
		}

		if ( false !== strpos( $path, 'api-status.php' ) ) {
			if ( null !== $this->knobs['wpErrorMessage'] ) {
				return new WP_Error( 'http_request_failed', (string) $this->knobs['wpErrorMessage'] );
			}
			return $this->httpResponse( wp_json_encode( $this->apiStatusBody() ) );
		}

		if ( false !== strpos( $path, 'notices.php' ) ) {
			if ( null !== $this->knobs['wpErrorMessage'] ) {
				return new WP_Error( 'http_request_failed', (string) $this->knobs['wpErrorMessage'] );
			}
			$notices = $this->knobs['notices'];
			$body    = is_string( $notices ) ? $notices : (string) wp_json_encode( array_values( (array) $notices ) );
			return $this->httpResponse( $body );
		}

		// EMR calls no other endpoint; a spec that finds one in the request
		// log has found a new one.
		return $this->httpResponse( '{}' );
	}

	/** Append to the capped request log (no raw args: they may hold a stream filename). */
	private function logRequest( $url, $host, $path, array $args ) {
		$log   = (array) get_option( self::OPTION_REQUESTS, array() );
		$log[] = array(
			'time'    => gmdate( 'c' ),
			'url'     => $url,
			'host'    => $host,
			'path'    => $path,
			'method'  => isset( $args['method'] ) ? (string) $args['method'] : 'GET',
			'request' => $this->decodeParams( $url, $args ),
		);
		if ( count( $log ) > self::REQUEST_LOG_CAP ) {
			$log = array_slice( $log, -self::REQUEST_LOG_CAP );
		}
		update_option( self::OPTION_REQUESTS, $log, false );
	}

	// -------------------------------------------------------------------
	// Reducer endpoints
	// -------------------------------------------------------------------

	private function handleReducer( $url, array $args ) {
		$request = $this->decodeParams( $url, $args );
		$urllist = ( is_array( $request ) && isset( $request['urllist'] ) ) ? array_values( (array) $request['urllist'] ) : array();

		$response = array();
		foreach ( $urllist as $sourceUrl ) {
			$response[] = $this->buildReducerEntry( (string) $sourceUrl );
		}
		return $this->httpResponse( wp_json_encode( $response ) );
	}

	private function buildReducerEntry( $sourceUrl ) {
		if ( ! empty( $this->knobs['quotaExceeded'] ) ) {
			return $this->reducerErrorEntry( self::CODE_QUOTA_EXCEEDED, 'Quota exceeded. You need to subscribe to a plan to continue.', $sourceUrl );
		}

		$forced = ( null === $this->knobs['forceStatusCode'] ) ? null : (string) $this->knobs['forceStatusCode'];
		if ( null !== $forced && self::CODE_PENDING !== $forced ) {
			return $this->reducerErrorEntry( $forced, (string) $this->knobs['errorMessage'], $sourceUrl );
		}

		$key                           = md5( $sourceUrl );
		$rounds                        = isset( $this->state['rounds'][ $key ] ) ? (int) $this->state['rounds'][ $key ] + 1 : 1;
		$this->state['rounds'][ $key ] = $rounds;
		$waitingRounds                 = (int) $this->knobs['waitingRounds'];

		if ( self::CODE_PENDING === $forced && 0 === $waitingRounds ) {
			return $this->reducerPendingEntry( $sourceUrl );
		}
		if ( $rounds <= $waitingRounds ) {
			return $this->reducerPendingEntry( $sourceUrl );
		}

		$bytes        = $this->resultBytes();
		$localPath    = $this->urlToPath( rawurldecode( $sourceUrl ) );
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

	private function reducerPendingEntry( $sourceUrl ) {
		return array(
			'Status'      => array(
				'Code'    => self::CODE_PENDING,
				'Message' => 'Pending',
			),
			'OriginalURL' => $sourceUrl,
		);
	}

	private function reducerErrorEntry( $code, $message, $sourceUrl ) {
		return array(
			'Status'      => array(
				'Code'    => (string) $code,
				'Message' => (string) $message,
			),
			'OriginalURL' => $sourceUrl,
		);
	}

	/** api-status.php body from the apiStatusCode / apiStatusMessage / unlimited knobs. */
	private function apiStatusBody() {
		$body = array(
			'Status' => array(
				'Code'    => $this->knobs['apiStatusCode'],
				'Message' => (string) $this->knobs['apiStatusMessage'],
			),
		);
		if ( null !== $this->knobs['unlimited'] ) {
			$body['Unlimited'] = $this->knobs['unlimited'];
		}
		return $body;
	}

	// -------------------------------------------------------------------
	// Download endpoint (/f/<token>.<ext>), disk-backed
	// -------------------------------------------------------------------

	/** Store bytes in the stash and return the /f/ URL serving them. */
	private function stashImage( $bytes, $token ) {
		$ext  = strtolower( pathinfo( (string) $this->knobs['resultFixture'], PATHINFO_EXTENSION ) );
		$ext  = ( '' !== $ext && is_file( $this->fixturePath() ) ) ? $ext : 'jpg';
		$name = $token . '.' . $ext;
		file_put_contents( self::stashDir() . $name, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return self::DOWNLOAD_BASE . $name;
	}

	/** Serve stashed bytes; an unknown token answers 404. */
	private function handleDownload( $path, array $args ) {
		$token = basename( $path );
		$file  = self::stashDir() . $token;
		if ( '' === $token || ! is_file( $file ) ) {
			return $this->httpResponse( 'mock: unknown file token ' . $token, 404 );
		}

		$filetype = wp_check_filetype( $token );
		$headers  = array(
			'content-type'   => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
			'content-length' => (string) filesize( $file ),
		);
		return $this->fileResponse( (string) file_get_contents( $file ), $args, $headers ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private function fixturePath() {
		return WP_PLUGIN_DIR . '/enable-media-replace/tests/fixtures/' . basename( (string) $this->knobs['resultFixture'] );
	}

	/** Bytes of the result image: the resultFixture file, or a GD-drawn JPEG. */
	private function resultBytes() {
		$path = $this->fixturePath();
		if ( is_file( $path ) ) {
			return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$img = imagecreatetruecolor( 16, 16 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 255, 255 ) );
		ob_start();
		imagejpeg( $img, null, 80 );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------
	// wordpress.org
	// -------------------------------------------------------------------

	private function handleWordPressOrg( $host, $path, array $args ) {
		if ( 'downloads.wordpress.org' === $host ) {
			if ( ! empty( $this->knobs['downloadsFail'] ) ) {
				return new WP_Error( 'http_request_failed', 'downloads.wordpress.org failure forced by E2E test' );
			}
			if ( preg_match( '#^/plugin/([a-z0-9-]+)(?:\.[0-9.]+)?\.zip$#', $path, $m ) ) {
				$zip = $this->buildStubZip( $m[1] );
				if ( null === $zip ) {
					return new WP_Error( 'http_request_failed', 'mock: could not build the stub zip' );
				}
				return $this->fileResponse( $zip, $args, array( 'content-type' => 'application/zip', 'content-length' => (string) strlen( $zip ) ) );
			}
			// Signatures (.sig) and anything else.
			return $this->httpResponse( 'mock: not found', 404 );
		}

		if ( 'api.wordpress.org' === $host ) {
			return $this->httpResponse( '{}', 200, array( 'content-type' => 'application/json' ) );
		}

		// wordpress.org/news/feed, planet.wordpress.org/feed and the like.
		$rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>EMR E2E</title><link>https://wordpress.org/</link><description>empty</description></channel></rss>';
		return $this->httpResponse( $rss, 200, array( 'content-type' => 'application/rss+xml' ) );
	}

	/**
	 * A zip holding <slug>/<main file> with a valid plugin header and no code,
	 * so Plugin_Upgrader can install it and activate_plugin() can activate it.
	 *
	 * @param string $slug Plugin slug from the download URL.
	 * @return string|null Zip bytes, or null when no zip library is available.
	 */
	private function buildStubZip( $slug ) {
		$main = isset( self::STUB_PLUGINS[ $slug ] ) ? self::STUB_PLUGINS[ $slug ] : array( $slug . '.php', $slug );
		$php  = "<?php\n/**\n * Plugin Name: " . $main[1] . "\n * Description: " . self::STUB_MARKER . " (served by emr-e2e-mock-api.php). Contains no code.\n * Version: 0.0.1-e2e\n */\n";

		$tmp = wp_tempnam( 'emr-e2e-stub.zip' );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- ZipArchive wants to create the file itself.

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $tmp, ZipArchive::CREATE ) ) {
				return null;
			}
			$zip->addEmptyDir( $slug );
			$zip->addFromString( $slug . '/' . $main[0], $php );
			$zip->close();
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$zip = new PclZip( $tmp );
			$ok  = $zip->create(
				array(
					array(
						PCLZIP_ATT_FILE_NAME    => $slug . '/' . $main[0],
						PCLZIP_ATT_FILE_CONTENT => $php,
					),
				)
			);
			if ( 0 === $ok ) {
				return null;
			}
		}

		$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return '' === $bytes ? null : $bytes;
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

	/**
	 * A file response. In stream mode (download_url(), the filesystem
	 * module's downloadFile()) WordPress expects the body written to
	 * $args['filename'] and left off the response.
	 */
	private function fileResponse( $bytes, array $args, array $headers ) {
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$response             = $this->httpResponse( '', 200, $headers );
			$response['filename'] = $args['filename'];
			return $response;
		}
		return $this->httpResponse( $bytes, 200, $headers );
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

EMR_E2E_MockApi::register();
