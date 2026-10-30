<?php
/**
 * Bulk source: the media library images an upscale may apply to.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk\Selector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\Controller\Bulk\BulkQueue;

/**
 * Selects every JPEG and PNG attachment of the media library, newest
 * first, `enqueue_limit` per page. The width threshold and the factor
 * table are applied by BulkQueue::prepareItems() per attachment, because
 * the width lives in the serialized attachment metadata, which SQL cannot
 * filter reliably.
 *
 * Always available: the media library exists on every site.
 */
class UpscaleCandidates extends Selector
{
	/** MIME types the API accepts. */
	const MIME_TYPES = array('image/jpeg', 'image/png');

	/**
	 * @return bool
	 */
	public function isAvailable()
	{
		return true;
	}

	/**
	 * @return string
	 */
	public function getUnavailableReason()
	{
		return '';
	}

	/**
	 * @param BulkQueue $queue
	 * @return int[]
	 */
	public function getIds(BulkQueue $queue)
	{
		global $wpdb;

		$limit    = $this->limit($queue);
		$cursor   = (int) $queue->getSelectorCursor();
		$mimeList = implode(',', array_fill(0, count(self::MIME_TYPES), '%s'));
		$prepare  = self::MIME_TYPES;

		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($mimeList)";
		if ($cursor > 0) {
			$sql      .= ' AND ID < %d';
			$prepare[] = $cursor;
		}
		$sql      .= ' ORDER BY ID DESC LIMIT %d';
		$prepare[] = $limit;

		$ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $prepare)));
		if (count($ids) > 0) {
			$queue->setSelectorCursor(min($ids));
		}
		return $ids;
	}

	/**
	 * Number of JPEG and PNG attachments.
	 *
	 * @return int
	 */
	public function countSource()
	{
		global $wpdb;
		$mimeList = implode(',', array_fill(0, count(self::MIME_TYPES), '%s'));
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($mimeList)", self::MIME_TYPES));
	}
}
