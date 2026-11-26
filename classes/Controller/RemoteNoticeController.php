<?php
/**
 * Remote notices: messages fetched from the ShortPixel API and shown as admin notices.
 *
 * @package EnableMediaReplace\Controller
 */
namespace EnableMediaReplace\Controller;
use EnableMediaReplace\Notices\NoticeController as Notices;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Fetches remote notices from api.shortpixel.com and adds them as persistent notices.
 *
 * Lifecycle: singleton via getInstance(). The constructor does all the work, so the first
 * getInstance() call in a request fetches (when the transient allows) and registers the
 * notices. EnableMediaReplacePlugin::setScreen() calls getInstance() on the attachment,
 * EMR and media library screens when the `remote_notice` feature is on.
 *
 * Registers no hooks. State: the `emr_remote_notice` transient (DAY_IN_SECONDS) throttles
 * the remote call; each notice is stored by the notices module as a persistent notice
 * keyed by its remote ID for MONTH_IN_SECONDS.
 *
 * Remote notice format (JSON array of objects): `id`, `message`, optional `type`
 * ('warning', 'error', or 'notice' / anything else for a normal notice).
 */
class RemoteNoticeController
{
		/**
		 * The singleton instance.
		 *
		 * @var RemoteNoticeController|null
		 */
		protected static $instance;

		/**
		 * Remote notices endpoint. Query args `version` (EMR_VERSION), `plugin`
		 * ('enable-media-replace') and `target` (4) are added in get_remote_notices().
		 *
		 * @var string
		 */
		private $remote_message_endpoint = 'https://api.shortpixel.com/v2/notices.php';


		/**
		 * Fetches and registers the remote notices immediately.
		 */
		public function __construct()
		{
			 $this->doRemoteNotices();
		}

		/**
		 * Returns the singleton, creating it (and so fetching notices) on first call.
		 *
		 * @return RemoteNoticeController
		 */
		public static function getInstance()
		{
				if ( is_null(self::$instance))
				{
					  self::$instance = new RemoteNoticeController();
				}

				return self::$instance;
		}

		/**
		 * Adds every remote notice that is not registered yet as a persistent notice.
		 *
		 * Does nothing when get_remote_notices() returns a non-array. For each notice, the
		 * message is escaped with esc_html() and the ID sanitized; when the notices module
		 * has no notice with that ID yet, it is added as a warning, error or normal notice
		 * (by `type`, default 'notice') and made persistent for MONTH_IN_SECONDS under the
		 * remote ID, so it keeps showing until dismissed or expired.
		 *
		 * @todo The guard `! isset($remoteNotice->id) && ! isset($remoteNotice->message)`
		 *       only skips entries missing both fields, and uses `return`, so one bad entry
		 *       stops the remaining ones while an entry missing only one field is processed
		 *       with an undefined property. Suggested fix: `||` and `continue`.
		 *
		 * @return void
		 */
		protected function doRemoteNotices()
    {
        $notices = $this->get_remote_notices();

        if (! is_array($notices))
            return;

        foreach($notices as $remoteNotice)
        {
            if (! isset($remoteNotice->id) && ! isset($remoteNotice->message))
                return;

            if (! isset($remoteNotice->type))
                $remoteNotice->type = 'notice';

            $message = esc_html($remoteNotice->message);
            $id = sanitize_text_field($remoteNotice->id);

            $noticeController = Notices::getInstance();
            $noticeObj = $noticeController->getNoticeByID($id);

            // not added to system yet
            if ($noticeObj === false)
            {
                switch ($remoteNotice->type)
                {
                    case 'warning':
                        $new_notice = Notices::addWarning($message);
                        break;
                    case 'error':
                        $new_notice = Notices::addError($message);
                        break;
                    case 'notice':
                    default:
                        $new_notice = Notices::addNormal($message);
                        break;
                }

                Notices::makePersistent($new_notice, $id, MONTH_IN_SECONDS);
            }

        }
    }

		/**
		 * Fetches the notices from the endpoint, throttled by the `emr_remote_notice` transient.
		 *
		 * Only requests (wp_safe_remote_request(), GET) when the transient is false or 'none'.
		 * After a response without WP_Error, the decoded body is returned (or 'none' when it
		 * is not an array) and the transient is set to the string 'true' for DAY_IN_SECONDS.
		 * While that transient lives, the method returns 'true', which is not an array, so
		 * doRemoteNotices() adds nothing new; notices registered earlier still show because
		 * they are persistent. On a WP_Error the transient is set to false, which is the same
		 * as no transient, so the next request fetches again. The HTTP status code is not
		 * checked, and the transient is never stored as 'none', so that comparison never
		 * matches. `$content` is unused.
		 *
		 * @todo The transient holds the string 'true' instead of the fetched notices, so
		 *       notices are only processed on the request that fetched them, and a failed
		 *       fetch is retried on every request. Suggested fix: store `$notices` (or 'none')
		 *       in the transient and store 'none' on error.
		 *
		 * @return array|string|false Array of notice objects on a fresh successful fetch;
		 *                            'none' when the body is not an array; the cached
		 *                            transient value ('true') otherwise; false on a failed
		 *                            fetch.
		 */
		private function get_remote_notices()
		{
				$transient_name = 'emr_remote_notice';
				$transient_duration = DAY_IN_SECONDS;


		//		$keyControl = new apiKeyController();
				//$keyControl->loadKey();

				$notices = get_transient($transient_name);
				$url = $this->remote_message_endpoint;
				$url = add_query_arg(array(  // has url
						'version' => EMR_VERSION,
						'plugin' => 'enable-media-replace',
						'target' => 4,

				), $url);


				if ( $notices === false || $notices == 'none'  ) {
						$notices_response = wp_safe_remote_request( $url );

						$content = false;
						if (! is_wp_error( $notices_response ) )
						{
								$notices = json_decode($notices_response['body']);

								if (! is_array($notices))
										$notices = 'none';

								// Save transient anywhere to prevent over-asking when nothing good is there.
								set_transient( $transient_name, 'true', $transient_duration );
						}
						else
						{
								set_transient( $transient_name, false, $transient_duration );
						}
				}

				return $notices;
		}

} // class
