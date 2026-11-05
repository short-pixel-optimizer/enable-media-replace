<?php
/**
 * Attachment adapter for the bulk queue.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Model\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\Controller\ReplaceController;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * The image a bulk queue item works on.
 *
 * ShortPixel Image Optimizer hands its queue an ImageModel; EMR has no
 * image model, so this small adapter wraps one attachment and answers the
 * questions the queue layer asks: id, type, file, URL, width, MIME type,
 * whether the bulk actions apply, and the bulk marker meta. File and URL
 * come from ReplaceController (setupSource() and getSourceUrl()), so
 * offloaded files are translated the same way the single flow does.
 *
 * Lifecycle: `new` per use through getById(); nothing is cached between
 * requests. Listens to no hooks; the ReplaceController it creates applies
 * `emr/replace/original_image_path` and `emr/file/virtual/translate`.
 */
class BulkImage
{
	/**
	 * Prefix of the marker post meta: one key per operation
	 * (`_emr_bulk_background`, `_emr_bulk_upscale`, getMarkerKey()), each an
	 * array of the operation's data plus the relative `backup` path. One key
	 * per fact, as SPIO's `_shortpixel_*` flags, so the restore source, the
	 * dashboard count and the Media Library filter all query `meta_key = %s`
	 * (queryMarked(), countMarked()) instead of parsing a serialized value.
	 */
	const MARKER_META_PREFIX = '_emr_bulk_';

	/** The operations a marker can record. */
	const OPERATIONS = array('background', 'upscale');

	/**
	 * File status codes of a result (QueueItemResult::$fileStatus), on the
	 * image as SPIO keeps them on its ImageModel, same values. The bulk screen
	 * opens the before/after preview on FILE_STATUS_SUCCESS only; a restore
	 * answers FILE_STATUS_RESTORED, so it never does.
	 */
	const FILE_STATUS_ERROR = -1;
	const FILE_STATUS_UNPROCESSED = 0;
	const FILE_STATUS_PENDING = 1;
	const FILE_STATUS_SUCCESS = 2;
	const FILE_STATUS_RESTORED = 3;

	/** @var int */
	protected $id;

	/** @var \WP_Post */
	protected $post;

	/** @var ReplaceController|null */
	protected $replaceController;

	/** @var \EnableMediaReplace\FileSystem\Model\File\FileModel|null */
	protected $file;

	/** @var array|false|null Attachment metadata, loaded on first use. */
	protected $meta;

	/**
	 * @param \WP_Post $post The attachment post.
	 */
	protected function __construct(\WP_Post $post)
	{
		$this->post = $post;
		$this->id   = (int) $post->ID;
	}

	/**
	 * Loads the adapter for an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return BulkImage|false False when the post is missing or not an image attachment.
	 */
	public static function getById($id)
	{
		$post = get_post((int) $id);
		if (! is_object($post) || 'attachment' !== $post->post_type) {
			return false;
		}
		if (! wp_attachment_is_image($post)) {
			return false;
		}
		return new static($post);
	}

	/**
	 * Mirrors ImageModel::get() for the two properties the queue layer reads.
	 *
	 * @param string $name 'id' or 'type'.
	 * @return mixed
	 */
	public function get($name)
	{
		if ('id' === $name) {
			return $this->id;
		}
		if ('type' === $name) {
			return 'media';
		}
		return null;
	}

	/**
	 * The attachment post.
	 *
	 * @return \WP_Post
	 */
	public function getPost()
	{
		return $this->post;
	}

	/**
	 * The ReplaceController for this attachment, created on first use.
	 *
	 * @return ReplaceController
	 */
	public function getReplaceController()
	{
		if (is_null($this->replaceController)) {
			$this->replaceController = new ReplaceController($this->id);
		}
		return $this->replaceController;
	}

	/**
	 * The main file (the unscaled original when WordPress made a -scaled copy).
	 *
	 * @return \EnableMediaReplace\FileSystem\Model\File\FileModel
	 */
	public function getFile()
	{
		if (is_null($this->file)) {
			$this->file = $this->getReplaceController()->getSourceFile();
		}
		return $this->file;
	}

	/**
	 * File name of the main file.
	 *
	 * @return string
	 */
	public function getFileName()
	{
		return $this->getFile()->getFileName();
	}

	/**
	 * URL the API downloads the source from (original image URL, attachment URL as fallback).
	 *
	 * @return string|false
	 */
	public function getUrl()
	{
		return $this->getReplaceController()->getSourceUrl();
	}

	/**
	 * Attachment metadata.
	 *
	 * @return array|false
	 */
	public function getMeta()
	{
		if (is_null($this->meta)) {
			$this->meta = wp_get_attachment_metadata($this->id);
		}
		return $this->meta;
	}

	/**
	 * Width in pixels from the metadata; 0 when unknown.
	 *
	 * @return int
	 */
	public function getWidth()
	{
		$meta = $this->getMeta();
		return (is_array($meta) && isset($meta['width'])) ? (int) $meta['width'] : 0;
	}

	/**
	 * Post MIME type.
	 *
	 * @return string
	 */
	public function getMime()
	{
		return (string) get_post_mime_type($this->post);
	}

	/**
	 * Whether the main file is present locally (or as a translated virtual file).
	 *
	 * @return bool
	 */
	public function exists()
	{
		return $this->getFile()->exists();
	}

	/** The mime types the API accepts (the upscale selector queries the same list). */
	const MIME_TYPES = array('image/jpeg', 'image/png');

	/**
	 * Whether the API accepts this image: JPEG or PNG, as the single flow
	 * requires, by exact mime type (the list the upscale selector queries).
	 *
	 * @return bool
	 */
	public function isSupportedType()
	{
		return in_array($this->getMime(), self::MIME_TYPES, true);
	}

	/**
	 * The upscale factor for this image: the largest factor whose maximum source width
	 * (UIHelper::getUpscaleFactors()) is not below the image width. 0 when none fits
	 * or the width is unknown.
	 *
	 * @return int 0, 2, 3 or 4.
	 */
	public function getUpscaleFactor()
	{
		$width = $this->getWidth();
		if ($width <= 0) {
			return 0;
		}
		$factors = \emr()->uiHelper()->getUpscaleFactors();
		krsort($factors);
		foreach ($factors as $factor => $maxWidth) {
			if ($width <= (int) $maxWidth) {
				return (int) $factor;
			}
		}
		return 0;
	}

	/**
	 * The marker meta key of an operation.
	 *
	 * @param string $operation 'background' or 'upscale'.
	 * @return string
	 */
	public static function getMarkerKey($operation)
	{
		return self::MARKER_META_PREFIX . sanitize_key($operation);
	}

	/**
	 * The bulk marker: what was done and where the backup is, merged over
	 * the operations: `['background' => data, 'upscale' => data, 'backup' => path]`.
	 * Every operation backs up the same file (the original before the first
	 * one, BackupController::backup() keeps an existing backup), so one
	 * `backup` entry stands for all.
	 *
	 * @return array Empty when the bulk never touched this attachment.
	 */
	public function getMarker()
	{
		$marker = array();
		foreach (self::OPERATIONS as $operation) {
			$data = $this->getOperationMarker($operation);
			if (array() === $data) {
				continue;
			}
			if (isset($data['backup']) && ! isset($marker['backup'])) {
				$marker['backup'] = $data['backup'];
			}
			unset($data['backup']);
			$marker[ $operation ] = $data;
		}
		return $marker;
	}

	/**
	 * The stored marker of one operation, `backup` included.
	 *
	 * @param string $operation
	 * @return array Empty when the operation never ran on this attachment.
	 */
	public function getOperationMarker($operation)
	{
		$data = get_post_meta($this->id, self::getMarkerKey($operation), true);
		return is_array($data) ? $data : array();
	}

	/**
	 * Records a bulk operation on this attachment.
	 *
	 * @param string $operation 'background' or 'upscale'.
	 * @param array  $data      Operation data (timestamp, factor, from_width).
	 * @param string $backup    Relative backup path.
	 * @return void
	 */
	public function setMarker($operation, $data, $backup)
	{
		$data['backup'] = $backup;
		update_post_meta($this->id, self::getMarkerKey($operation), $data);
	}

	/**
	 * Removes the bulk marker: one operation's, or every operation's (after a
	 * restore, which puts the original back and undoes them all).
	 *
	 * @param string|null $operation
	 * @return void
	 */
	public function deleteMarker($operation = null)
	{
		$operations = is_null($operation) ? self::OPERATIONS : array($operation);
		foreach ($operations as $op) {
			delete_post_meta($this->id, self::getMarkerKey($op));
		}
	}

	/**
	 * Whether a given bulk operation already ran on this attachment.
	 *
	 * @param string $operation 'background' or 'upscale'.
	 * @return bool
	 */
	public function hasOperation($operation)
	{
		return array() !== $this->getOperationMarker($operation);
	}

	/**
	 * Number of attachments carrying the marker of an operation (the restore
	 * candidates of the dashboard).
	 *
	 * @param string $operation
	 * @return int
	 */
	public static function countMarked($operation)
	{
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
			self::getMarkerKey($operation)
		));
	}

	/**
	 * A page of attachments carrying the marker of an operation, newest
	 * first, below $last_id (the restore's source, paged like SPIO's
	 * MediaLibraryQueue on `last_item_id`). Pages by matches, so a page is
	 * empty only when no marked attachment is left.
	 *
	 * @param string|false $operation One operation, or false for any.
	 * @param int          $last_id   Only IDs below this one; 0 for the newest page.
	 * @param int          $limit
	 * @return int[]
	 */
	public static function queryMarked($operation, $last_id, $limit)
	{
		global $wpdb;
		$keys    = (false === $operation) ? array_map(array(__CLASS__, 'getMarkerKey'), self::OPERATIONS) : array(self::getMarkerKey($operation));
		$sql     = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (" . implode(', ', array_fill(0, count($keys), '%s')) . ')';
		$prepare = $keys;
		if ($last_id > 0) {
			$sql      .= ' AND post_id < %d';
			$prepare[] = (int) $last_id;
		}
		$sql      .= ' ORDER BY post_id DESC LIMIT %d';
		$prepare[] = max(1, (int) $limit);

		return array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $prepare)));
	}
}
