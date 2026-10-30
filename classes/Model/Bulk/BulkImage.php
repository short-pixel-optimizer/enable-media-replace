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
	/** Post meta that records what the bulk did to an attachment (and where its backup is). */
	const MARKER_META = '_emr_bulk';

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

	/**
	 * Whether the API accepts this image: JPEG or PNG, as the single flow requires.
	 *
	 * @return bool
	 */
	public function isSupportedType()
	{
		$mime = $this->getMime();
		foreach (array('jpg', 'png', 'jpeg') as $extension) {
			if (false !== strpos($mime, $extension)) {
				return true;
			}
		}
		return false;
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
	 * The bulk marker meta: what was done and where the backup is.
	 *
	 * @return array Empty when the bulk never touched this attachment.
	 */
	public function getMarker()
	{
		$marker = get_post_meta($this->id, self::MARKER_META, true);
		return is_array($marker) ? $marker : array();
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
		$marker = $this->getMarker();
		$marker[ $operation ] = $data;
		$marker['backup'] = $backup;
		update_post_meta($this->id, self::MARKER_META, $marker);
	}

	/**
	 * Removes the bulk marker (after a restore).
	 *
	 * @return void
	 */
	public function deleteMarker()
	{
		delete_post_meta($this->id, self::MARKER_META);
	}

	/**
	 * Whether a given bulk operation already ran on this attachment.
	 *
	 * @param string $operation 'background' or 'upscale'.
	 * @return bool
	 */
	public function hasOperation($operation)
	{
		$marker = $this->getMarker();
		return isset($marker[ $operation ]);
	}
}
