<?php
/**
 * Base of the bulk source selectors.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk\Selector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\Controller\Bulk\BulkQueue;

/**
 * A selector answers "which attachments does this bulk operation work on",
 * one page at a time, while the queue prepares.
 *
 * ShortPixel Image Optimizer scans the media library in
 * MediaLibraryQueue::queryPostMeta(), paged by `last_item_id`. EMR's
 * sources differ per operation (WooCommerce products for the background
 * removal, the media library for the upscale), so the scan lives in a
 * selector per operation. Each page is at most the queue's `enqueue_limit`
 * source rows, newest first; the selector keeps its position in the
 * queue's custom_data (BulkQueue::getSelectorCursor()), which
 * createNewBulk() resets, and returns an empty page when the source is
 * exhausted, which ends the preparing phase.
 *
 * Lifecycle: `new` per prepare page through forOperation(). Hooks:
 * - filter `emr/bulk/selector` (Selector|null, string operation): replaces the selector for an operation.
 */
abstract class Selector
{
	/**
	 * The selector for a bulk operation, or null when EMR has none.
	 *
	 * @param string $operation BulkQueue::OPERATION_BACKGROUND or OPERATION_UPSCALE.
	 * @return Selector|null
	 */
	public static function forOperation($operation)
	{
		$selector = null;
		if (BulkQueue::OPERATION_BACKGROUND === $operation) {
			$selector = new WooCommerceProductImages();
		} elseif (BulkQueue::OPERATION_UPSCALE === $operation) {
			$selector = new UpscaleCandidates();
		}

		$filtered = apply_filters('emr/bulk/selector', $selector, $operation);
		return ($filtered instanceof Selector) ? $filtered : $selector;
	}

	/**
	 * Whether the source of this selector exists on the site (for example
	 * WooCommerce is active). An unavailable selector returns empty pages.
	 *
	 * @return bool
	 */
	abstract public function isAvailable();

	/**
	 * A human-readable reason why the selector is unavailable, for the bulk screen.
	 *
	 * @return string Empty when available.
	 */
	abstract public function getUnavailableReason();

	/**
	 * The next page of attachment IDs, newest first.
	 *
	 * @param BulkQueue $queue The preparing queue (cursor, options, limit).
	 * @return int[] Empty when the source is exhausted.
	 */
	abstract public function getIds(BulkQueue $queue);

	/**
	 * Counts the source rows the selector would scan, for the summary panel.
	 * Attachments the prepare phase skips (not an image, already processed)
	 * are not subtracted.
	 *
	 * @return int
	 */
	abstract public function countSource();

	/**
	 * The page size: the queue's `enqueue_limit`.
	 *
	 * @param BulkQueue $queue
	 * @return int
	 */
	protected function limit(BulkQueue $queue)
	{
		return max(1, (int) $queue->getShortQ()->getOption('enqueue_limit'));
	}
}
