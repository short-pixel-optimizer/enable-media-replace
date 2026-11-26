<?php
/**
 * Backups of the files the bulk replaces.
 *
 * @package enable-media-replace
 */
namespace EnableMediaReplace\Controller\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnableMediaReplace\Model\Bulk\BulkImage;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Keeps a copy of the original file before the bulk swaps it, and hands it
 * back for a restore.
 *
 * Backups live under `wp-content/uploads/EMR_Backups/<yyyy>/<mm>/<name>`,
 * mirroring the attachment's own upload folder, so two attachments with
 * the same file name in different months do not collide. Only the main
 * file (the unscaled original when WordPress made a -scaled copy) is kept:
 * a restore runs through ReplaceController, which regenerates every size.
 * An existing backup is never overwritten, so a second bulk run on the
 * same image keeps the true original.
 *
 * All methods are static; nothing is cached. Hooks:
 * - filter `emr/bulk/backup_dir` (path): absolute path of the backup root.
 */
class BackupController
{
	/** Folder name under the uploads base directory. */
	const BACKUP_FOLDER = 'EMR_Backups';

	/**
	 * Absolute path of the backup root, without trailing slash.
	 *
	 * @return string
	 */
	public static function getBackupPath()
	{
		$uploads = wp_get_upload_dir();
		$base    = untrailingslashit($uploads['basedir']) . '/' . self::BACKUP_FOLDER;
		return untrailingslashit((string) apply_filters('emr/bulk/backup_dir', $base));
	}

	/**
	 * The backup root as a directory object, created when missing.
	 *
	 * @return \EnableMediaReplace\FileSystem\Model\File\DirectoryModel
	 */
	public static function getBackupDirectory()
	{
		$dir = \emr()->filesystem()->getDirectory(self::getBackupPath());
		$dir->check(true);
		return $dir;
	}

	/**
	 * Relative path (under the backup root) a backup of this image gets:
	 * the attachment's upload subfolder plus the main file name.
	 *
	 * @param BulkImage $image
	 * @return string
	 */
	public static function getRelativePath(BulkImage $image)
	{
		$attached = (string) get_post_meta($image->get('id'), '_wp_attached_file', true);
		$subdir   = trim(str_replace('\\', '/', dirname($attached)), '/.');
		$name     = $image->getFileName();
		return ('' === $subdir) ? $name : $subdir . '/' . $name;
	}

	/**
	 * Copies the image's main file into the backup folder.
	 *
	 * @param BulkImage $image
	 * @return string|false The relative backup path, or false when the source is
	 *                      missing or the copy failed. An existing backup is kept.
	 */
	public static function backup(BulkImage $image)
	{
		$source = $image->getFile();
		if (! $source->exists()) {
			Log::addError('Bulk backup: source file does not exist', $source->getFullPath());
			return false;
		}

		$relative = self::getRelativePath($image);
		$target   = self::getBackupFile($relative);

		if ($target->exists()) {
			return $relative;
		}

		$dir = $target->getFileDir();
		if (false === $dir->check(true)) {
			Log::addError('Bulk backup: directory not writable', $dir->getPath());
			return false;
		}

		if (false === $source->copy($target)) {
			Log::addError('Bulk backup: copy failed', array($source->getFullPath(), $target->getFullPath()));
			return false;
		}

		return $relative;
	}

	/**
	 * The backup file object for a relative path (may not exist).
	 *
	 * @param string $relative
	 * @return \EnableMediaReplace\FileSystem\Model\File\FileModel
	 */
	public static function getBackupFile($relative)
	{
		$relative = ltrim(str_replace('..', '', (string) $relative), '/');
		return \emr()->filesystem()->getFile(self::getBackupPath() . '/' . $relative);
	}

	/**
	 * Whether a backup exists for the image (according to its marker).
	 *
	 * @param BulkImage $image
	 * @return bool
	 */
	public static function hasBackup(BulkImage $image)
	{
		$marker = $image->getMarker();
		if (empty($marker['backup'])) {
			return false;
		}
		return self::getBackupFile($marker['backup'])->exists();
	}

	/**
	 * Deletes a backup file.
	 *
	 * @param string $relative
	 * @return bool
	 */
	public static function deleteBackup($relative)
	{
		$file = self::getBackupFile($relative);
		if (! $file->exists()) {
			return true;
		}
		return (bool) $file->delete();
	}

	/**
	 * URL of a backup file, for the before/after preview.
	 *
	 * @param string $relative
	 * @return string|false
	 */
	public static function getBackupUrl($relative)
	{
		$file = self::getBackupFile($relative);
		if (! $file->exists()) {
			return false;
		}
		return \emr()->filesystem()->pathToUrl($file);
	}
}
