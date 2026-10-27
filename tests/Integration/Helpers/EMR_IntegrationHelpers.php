<?php
/**
 * Shared integration-test helpers (trait).
 *
 * Holds everything EMR integration tests need regardless of their WP base
 * class: the MockEmrApi lifecycle, the option snapshot, fixture upload
 * helpers, the singleton resets that keep tests independent, and the
 * fail-fast filter that refuses every outbound HTTP request the mock does
 * not serve.
 *
 * Lives in a trait because the suites need the SAME baseline under two
 * different WP base classes: WP_UnitTestCase (EMR_IntegrationTestCase) and
 * WP_Ajax_UnitTestCase (EMR_AjaxTestCase) — PHP has no multiple
 * inheritance, and duplicating this much load-bearing setup would drift.
 *
 * Consumers call emrSetUpBaseline() / emrTearDownBaseline() from their
 * set_up() / tear_down() around the parent calls.
 *
 * @package Enable_Media_Replace
 */

trait EMR_IntegrationHelpers {

	/** @var MockEmrApi */
	protected $api;

	/** @var int[] Attachment ids created via uploadFixture()/uploadFile(), deleted in tear_down. */
	protected $uploadedAttachments = array();

	/** @var string[] Files copied into uploads by uploadFile(), removed in tear_down when still present. */
	protected $uploadedFiles = array();

	/**
	 * Options snapshotted in set_up and restored in tear_down:
	 * name => [ 'exists' => bool, 'value' => mixed ].
	 *
	 * @var array<string,array>
	 */
	protected $emrOptionSnapshot = array();

	/**
	 * The options EMR writes: the last-used replace / background settings
	 * (Api, the view controllers) and the ShortPixel API key
	 * (ApiKeyManager::OPTION_NAME).
	 *
	 * @return string[]
	 */
	protected function emrSnapshotOptionNames(): array {
		return array( 'enable_media_replace', 'emr_shortpixel_api_key' );
	}

	/**
	 * Singletons that cache state across tests, as class => static property.
	 *
	 * RemoveBackgroundViewController is declared as RemoveBackGroundViewController;
	 * class names are case-insensitive, so either spelling resolves.
	 *
	 * @return array<string,string>
	 */
	protected function emrSingletons(): array {
		return array(
			'EnableMediaReplace\UIHelper'                                       => 'instance',
			'EnableMediaReplace\Helper\EnvHelper'                               => 'instance',
			'EnableMediaReplace\ApiKeyManager'                                  => 'instance',
			'EnableMediaReplace\Controller\RemoteNoticeController'              => 'instance',
			'EnableMediaReplace\ViewController\ReplaceViewController'           => 'instance',
			'EnableMediaReplace\ViewController\UploadViewController'            => 'instance',
			'EnableMediaReplace\ViewController\RemoveBackgroundViewController'  => 'instance',
			'EnableMediaReplace\Replacer\Replacer'                              => 'instance',
			'EnableMediaReplace\Notices\NoticeController'                       => 'instance',
		);
	}

	/**
	 * Per-test baseline: mock API registered and reset, EMR options
	 * snapshotted, the remote-notice throttle cleared, plugin singletons
	 * dropped.
	 *
	 * Call AFTER parent::set_up().
	 */
	protected function emrSetUpBaseline(): void {
		$this->api = MockEmrApi::register();
		$this->api->reset();

		$this->emrOptionSnapshot = array();
		$missing                 = new stdClass();
		foreach ( $this->emrSnapshotOptionNames() as $name ) {
			$value                            = get_option( $name, $missing );
			$this->emrOptionSnapshot[ $name ] = ( $value === $missing )
				? array( 'exists' => false, 'value' => null )
				: array( 'exists' => true, 'value' => $value );
		}

		// RemoteNoticeController only fetches when this transient is unset;
		// clearing it makes every test start from "not fetched yet".
		delete_transient( 'emr_remote_notice' );

		// Core's update checks (wp_version_check(), wp_update_plugins(),
		// wp_update_themes()) run from inside WP_Upgrader::run() and on some
		// admin screens. Older cores (5.9) trigger a warning when the request
		// fails, which this harness turns into a test error. Answer
		// api.wordpress.org locally with an empty, successful body so those
		// checks are a no-op; every other unmocked host is still refused by
		// emrBlockOutboundHttp().
		add_filter( 'pre_http_request', array( $this, 'emrAnswerWordPressOrgUpdateChecks' ), 10, 3 );

		$this->resetPluginSingletons();
	}

	/**
	 * pre_http_request callback: a benign 200 for api.wordpress.org only.
	 *
	 * @param false|array|WP_Error $preempt Existing pre-emption.
	 * @param array                $args    Request arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array
	 */
	public function emrAnswerWordPressOrgUpdateChecks( $preempt, $args, $url ) {
		if ( false !== $preempt || false === strpos( (string) $url, 'api.wordpress.org' ) ) {
			return $preempt;
		}
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** Per-test cleanup mirror of emrSetUpBaseline(). Call BEFORE parent::tear_down(). */
	protected function emrTearDownBaseline(): void {
		foreach ( $this->uploadedAttachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->uploadedAttachments = array();

		// A replace can move or rename the attachment's file, in which case
		// wp_delete_attachment() no longer finds the copy uploadFile() made.
		foreach ( $this->uploadedFiles as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$this->uploadedFiles = array();

		foreach ( $this->emrOptionSnapshot as $name => $snapshot ) {
			if ( $snapshot['exists'] ) {
				update_option( $name, $snapshot['value'] );
			} else {
				delete_option( $name );
			}
		}
		$this->emrOptionSnapshot = array();

		MockEmrApi::unregister();
		$this->resetPluginSingletons();
	}

	/**
	 * Refuse every outbound HTTP request nobody else answered.
	 *
	 * Registered at priority 9999 on pre_http_request, after MockEmrApi
	 * (priority 10) and after any filter a test adds at default priority:
	 * a request that reaches it unanswered gets a WP_Error naming the URL
	 * instead of leaving the container. WP_UnitTestCase restores the hooks
	 * in tear_down, so the filter lives for one test.
	 */
	protected function emrBlockOutboundHttp(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== $preempt ) {
					return $preempt;
				}
				return new WP_Error( 'http_blocked', 'No outbound HTTP in integration tests: ' . $url );
			},
			9999,
			3
		);
	}

	// -------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------

	/** Absolute path of a file in tests/fixtures/. */
	protected function fixturePath( string $name ): string {
		$path = dirname( __DIR__, 2 ) . '/fixtures/' . $name;
		if ( ! file_exists( $path ) ) {
			$this->fail( "Fixture not found: $path — fixtures live in tests/fixtures/." );
		}
		return $path;
	}

	/**
	 * Upload a fixture as a real Media Library attachment.
	 *
	 * Copies the fixture into the WP uploads dir, creates the attachment
	 * post, and generates full attachment metadata — which produces real
	 * thumbnail files for every registered size the image is larger than.
	 *
	 * @param string $fixture Filename inside tests/fixtures/.
	 * @return int Attachment id.
	 */
	protected function uploadFixture( string $fixture ): int {
		return $this->uploadFile( $this->fixturePath( $fixture ) );
	}

	/** Upload an arbitrary file on disk as a real Media Library attachment. */
	protected function uploadFile( string $source ): int {
		$uploads = wp_upload_dir();

		$target = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], basename( $source ) );
		copy( $source, $target );
		$this->uploadedFiles[] = $target;

		$filetype = wp_check_filetype( basename( $target ) );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'],
				'post_title'     => sanitize_file_name( pathinfo( $target, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$target
		);
		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id, 'wp_insert_attachment must succeed for ' . basename( $source ) );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $target );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$this->uploadedAttachments[] = $attachment_id;

		return $attachment_id;
	}

	// -------------------------------------------------------------------
	// Plugin state hygiene
	// -------------------------------------------------------------------

	/**
	 * Reset every EMR singleton listed in emrSingletons().
	 *
	 * The singletons cache state at construction (ApiKeyManager the stored
	 * key, EnvHelper the multisite/ajax/cron flags, RemoteNoticeController
	 * fetches on construction), so each test must get fresh instances.
	 * EnableMediaReplacePlugin itself is NOT reset: its constructor hooks
	 * `init` and `admin_init`, and a second instance would register those
	 * hooks twice.
	 */
	protected function resetPluginSingletons(): void {
		foreach ( $this->emrSingletons() as $class => $property ) {
			$this->resetSingleton( $class, $property );
		}
	}

	/**
	 * Set a static property of a class back to null, by reflection.
	 *
	 * @param string $class    Fully qualified class name.
	 * @param string $property Static property holding the instance.
	 * @return bool True when the property existed and was reset, false when
	 *              the class declares no such property.
	 */
	protected function resetSingleton( string $class, string $property = 'instance' ): bool {
		if ( ! class_exists( $class ) ) {
			$this->fail( "resetSingleton(): class $class does not exist — was it renamed?" );
		}
		$ref = new ReflectionClass( $class );
		if ( ! $ref->hasProperty( $property ) ) {
			return false;
		}
		$prop = $ref->getProperty( $property );
		if ( ! $prop->isStatic() ) {
			return false;
		}
		$prop->setAccessible( true );
		$prop->setValue( null, null );
		return true;
	}
}
