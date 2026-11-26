<?php
/**
 * Smoke: the live ShortPixel API, through EMR's own client classes.
 *
 * Opt-in only. Keyed by the SHORTPIXEL_SMOKE_KEY environment variable
 * (bin/test.sh --smoke passes it into the container); without it every
 * test here skips. The mock is unregistered and the integration base
 * class's outbound-HTTP block is lifted, so real quota is consumed:
 * never part of --all, --matrix or CI.
 *
 * What the live API can and cannot prove from this harness:
 *
 *   - api-status.php (key verification, plan check) works from anywhere:
 *     a valid key, a wrong key.
 *   - reducer.php / free-reducer.php fetch the image from the site URL.
 *     The test install's URL (example.org) is not reachable from the API,
 *     so with the default settings the reducer request can only prove the
 *     contract: the endpoint answers in the documented envelope, EMR maps
 *     it into its result object, and nothing crashes. A full background
 *     removal or upscale, including the download of the result, runs when
 *     SHORTPIXEL_SMOKE_IMAGE_URL names a publicly reachable image: the
 *     test points wp_get_attachment_url() at it for the seeded attachment.
 *     The upscale requests send the `upscale` factor the upscale screen
 *     posts (2, 3 or 4) instead of `bg_remove`.
 *
 * Self-check without a key: EMR_SMOKE_USE_MOCK=1 keeps MockEmrApi
 * registered and runs the same test bodies against it, so the suite can
 * be verified without quota. The runner forwards both variables.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Api;
use EnableMediaReplace\ApiKeyManager;

class RealRemoveBackgroundSmokeTest extends EMR_IntegrationTestCase {

	/** @var string The live key, or the mock's accepted key in mock mode. */
	private $key = '';

	/** @var bool True when MockEmrApi stays registered (self-check mode). */
	private $mockMode = false;

	/** @var string|null A public image URL for a full removal, when given. */
	private $publicImageUrl = null;

	/** @var int Seeded attachment. */
	private $attachmentId = 0;

	/** @var string[] Temporary files to remove. */
	private $tmpFiles = array();

	public function set_up() {
		$key  = (string) getenv( 'SHORTPIXEL_SMOKE_KEY' );
		$mock = '1' === (string) getenv( 'EMR_SMOKE_USE_MOCK' );
		if ( '' === $key && ! $mock ) {
			$this->markTestSkipped( 'Live-API smoke test: set SHORTPIXEL_SMOKE_KEY (bin/test.sh --smoke) or EMR_SMOKE_USE_MOCK=1 for the mock self-check.' );
		}

		parent::set_up();

		$this->mockMode       = $mock;
		$this->publicImageUrl = (string) getenv( 'SHORTPIXEL_SMOKE_IMAGE_URL' ) ?: null;
		$this->tmpFiles       = array();

		if ( $this->mockMode ) {
			// The mock accepts any 20-character key; keep the knobs at their
			// defaults (Code 2, Unlimited true, success on the first round).
			$this->key = '' !== $key ? $key : 'MOCKMOCKMOCKMOCKMOCK';
		} else {
			$this->key = $key;
			// Live mode: no mock, no outbound block. The api.wordpress.org
			// stub of the baseline stays (core update checks are not the
			// point here).
			MockEmrApi::unregister();
			remove_all_filters( 'pre_http_request', 9999 );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->attachmentId = $this->uploadFixture( 'codeispoetry.png' );

		if ( null !== $this->publicImageUrl ) {
			$id  = $this->attachmentId;
			$url = $this->publicImageUrl;
			add_filter(
				'wp_get_attachment_url',
				static function ( $value, $post_id ) use ( $id, $url ) {
					return (int) $post_id === $id ? $url : $value;
				},
				10,
				2
			);
		}
	}

	public function tear_down() {
		foreach ( $this->tmpFiles as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$this->tmpFiles = array();
		delete_option( ApiKeyManager::OPTION_NAME );
		parent::tear_down();
	}

	/** Stores the key as the UI does and asserts the live verification accepted it. */
	private function storeVerifiedKey(): void {
		$this->resetSingleton( 'EnableMediaReplace\ApiKeyManager' );
		$answer = ApiKeyManager::getInstance()->saveAndVerify( $this->key );
		$this->assertIsArray( $answer );
		$this->assertTrue( $answer['success'], 'The key verifies: ' . $answer['message'] );
		$this->assertSame( $this->key, get_option( ApiKeyManager::OPTION_NAME ) );
	}

	/** The remove-background POST as js/remove_bg.js sends it. */
	private function post( array $background = array( 'type' => 'transparent' ) ): array {
		$_POST = array(
			'attachment_id' => (string) $this->attachmentId,
			'background'    => $background,
			'nonce'         => wp_create_nonce( 'emr_remove_background' ),
		);
		return $_POST;
	}

	/** The upscale POST as js/remove_bg.js sends it from the upscale screen. */
	private function upscalePost( $factor = '2' ): array {
		$_POST = array(
			'attachment_id' => (string) $this->attachmentId,
			'upscale'       => (string) $factor,
			'nonce'         => wp_create_nonce( 'emr_remove_background' ),
		);
		return $_POST;
	}

	/** Runs the request and, when the API could fetch the image, downloads and checks the result. */
	private function assertRequestAndDownload( $result, string $what ): void {
		$this->assertResultEnvelope( $result );

		if ( null === $this->publicImageUrl && ! $this->mockMode ) {
			$this->assertFalse( $result->success, 'The API cannot fetch the test install URL; the refusal is the documented envelope: ' . $result->message );
			return;
		}

		$this->assertTrue( $result->success, $what . ' succeeds: ' . $result->message );
		$key = ( new Api() )->handleSuccess( $result );
		$this->assertIsString( $key );
		$download = ( new Api() )->handleDownload( $key );
		$this->assertTrue( $download->success, 'The result downloads: ' . $download->message );
		$this->tmpFiles[] = $download->image;
		$this->assertFileExists( $download->image );
		$this->assertGreaterThan( 0, filesize( $download->image ) );
		$this->assertNotFalse( getimagesize( $download->image ), 'The downloaded result is an image.' );
	}

	/** Asserts the envelope Api::request() documents, whatever the outcome. */
	private function assertResultEnvelope( $result ): void {
		$this->assertInstanceOf( 'stdClass', $result );
		$this->assertObjectHasProperty( 'success', $result );
		$this->assertObjectHasProperty( 'message', $result );
		$this->assertObjectHasProperty( 'image', $result );
		$this->assertIsBool( $result->success );
		if ( $result->success ) {
			$this->assertIsString( $result->image );
			$this->assertMatchesRegularExpression( '#^https?://#', $result->image, 'A result image is a URL the plugin downloads next.' );
		} else {
			$this->assertIsString( $result->message );
			$this->assertNotSame( '', $result->message, 'A refusal carries a message for the user.' );
		}
	}

	// -------------------------------------------------------------------
	// api-status.php
	// -------------------------------------------------------------------

	public function test_a_valid_key_is_verified_and_reports_an_unlimited_plan() {
		$this->storeVerifiedKey();
		$this->resetSingleton( 'EnableMediaReplace\ApiKeyManager' );
		$this->assertTrue( ApiKeyManager::getInstance()->verifyUnlimitedPlan(), 'The stored key passes the plan check used before every keyed request.' );
	}

	public function test_a_wrong_key_is_refused_with_a_message() {
		if ( $this->mockMode ) {
			$this->api->setKnob( 'apiStatusCode', -401 );
		}
		$this->resetSingleton( 'EnableMediaReplace\ApiKeyManager' );
		$answer = ApiKeyManager::getInstance()->saveAndVerify( 'AAAAAAAAAAAAAAAAAAAA' );
		$this->assertIsArray( $answer );
		$this->assertFalse( $answer['success'] );
		$this->assertIsString( $answer['message'] );
		$this->assertStringContainsString( 'not correct', $answer['message'] );
		$this->assertFalse( get_option( ApiKeyManager::OPTION_NAME ), 'A refused key is not stored.' );
	}

	// -------------------------------------------------------------------
	// reducer.php / free-reducer.php
	// -------------------------------------------------------------------

	/**
	 * Keyed removal. With SHORTPIXEL_SMOKE_IMAGE_URL set the API can fetch
	 * the image and the whole path runs: request, result URL, download of
	 * the result to a temporary file that is a real image. Without it the
	 * API cannot reach example.org and the test proves the envelope only.
	 */
	public function test_keyed_remove_background_answers_in_the_documented_envelope() {
		$this->storeVerifiedKey();

		$result = ( new Api() )->request( $this->post() );
		$this->assertResultEnvelope( $result );

		if ( null === $this->publicImageUrl && ! $this->mockMode ) {
			$this->assertFalse( $result->success, 'The API cannot fetch the test install URL; the refusal is the documented envelope: ' . $result->message );
			return;
		}

		$this->assertTrue( $result->success, 'Removal succeeds: ' . $result->message );
		$key = ( new Api() )->handleSuccess( $result );
		$this->assertIsString( $key );
		$download = ( new Api() )->handleDownload( $key );
		$this->assertTrue( $download->success, 'The result downloads: ' . $download->message );
		$this->tmpFiles[] = $download->image;
		$this->assertFileExists( $download->image );
		$this->assertGreaterThan( 0, filesize( $download->image ) );
		$this->assertNotFalse( getimagesize( $download->image ), 'The downloaded result is an image.' );
	}

	/** Free (unkeyed) removal: same envelope, no key sent. */
	public function test_free_remove_background_answers_in_the_documented_envelope() {
		$this->resetSingleton( 'EnableMediaReplace\ApiKeyManager' );
		$this->assertFalse( ApiKeyManager::getInstance()->hasApiKey(), 'Precondition: no key stored.' );

		$result = ( new Api() )->request( $this->post() );
		$this->assertResultEnvelope( $result );

		if ( null === $this->publicImageUrl && ! $this->mockMode ) {
			$this->assertFalse( $result->success, 'The API cannot fetch the test install URL: ' . $result->message );
			return;
		}
		$this->assertTrue( $result->success, 'Free removal succeeds: ' . $result->message );
	}

	// -------------------------------------------------------------------
	// reducer.php / free-reducer.php with the `upscale` factor
	// -------------------------------------------------------------------

	/**
	 * Keyed upscale by 2x. With SHORTPIXEL_SMOKE_IMAGE_URL set the whole
	 * path runs and the downloaded result is an image; without it the test
	 * proves the envelope only. The result's `settings` summary names the
	 * factor and no `bg_remove`, which shows the upscale body was sent.
	 */
	public function test_keyed_upscale_answers_in_the_documented_envelope() {
		$this->storeVerifiedKey();

		$result = ( new Api() )->request( $this->upscalePost( '2' ) );

		$this->assertRequestAndDownload( $result, 'Upscale' );
		if ( $result->success ) {
			$this->assertStringContainsString( ' upscale:2 ', $result->settings );
			$this->assertStringNotContainsString( 'bg_remove', $result->settings );
		}
	}

	/** Free (unkeyed) upscale: same envelope, no key sent. */
	public function test_free_upscale_answers_in_the_documented_envelope() {
		$this->resetSingleton( 'EnableMediaReplace\ApiKeyManager' );
		$this->assertFalse( ApiKeyManager::getInstance()->hasApiKey(), 'Precondition: no key stored.' );

		$result = ( new Api() )->request( $this->upscalePost( '3' ) );
		$this->assertResultEnvelope( $result );

		if ( null === $this->publicImageUrl && ! $this->mockMode ) {
			$this->assertFalse( $result->success, 'The API cannot fetch the test install URL: ' . $result->message );
			return;
		}
		$this->assertTrue( $result->success, 'Free upscale succeeds: ' . $result->message );
		$this->assertStringContainsString( ' upscale:3 ', $result->settings );
	}

	/** The factor check runs locally: nothing is sent for a factor the screens never offer. */
	public function test_an_invalid_upscale_factor_is_refused_before_the_api_is_called() {
		$result = ( new Api() )->request( $this->upscalePost( '7' ) );

		$this->assertResultEnvelope( $result );
		$this->assertFalse( $result->success );
		$this->assertSame( 'Invalid upscale factor', $result->message );
	}
}
