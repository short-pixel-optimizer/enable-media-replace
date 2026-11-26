<?php

/**
 * Polyfills for WordPress core functions missing on old WordPress versions.
 *
 * Global namespace. Each function is declared only when core does not
 * already provide it. ReplaceController calls wp_delete_attachment_files()
 * (which needs wp_delete_file_from_directory() and wp_get_upload_dir()) and
 * wp_get_upload_dir() directly.
 *
 * readme.txt declares WordPress 4.9.7 as the minimum. All three functions
 * exist in core from that version on, so none of these polyfills is ever
 * declared on a supported install.
 *
 * @package enable-media-replace
 *
 * @todo The whole file is dead on the readme minimum (WordPress 4.9.7). Remove
 *       it, together with its require_once in the root file, when the
 *       compatibility shims are cleaned up.
 */

// Compatibility functions for old version of WordPress / PHP / Other


/*
* Introduced in  WP 4.9.7 - https://developer.wordpress.org/reference/functions/wp_delete_attachment_files/
* Compat for previous versions.
*/
if (! function_exists('wp_delete_attachment_files'))
{
  /**
   * Polyfill of core wp_delete_attachment_files() (WordPress 4.9.7+).
   *
   * Deletes an attachment's files from the uploads directory: the legacy
   * `thumb` file (unless another attachment's metadata references it), every
   * intermediate size in $meta['sizes'], every backup size, and the main file.
   * Each deletion goes through wp_delete_file_from_directory(), which refuses
   * paths outside the expected directory.
   *
   * @global \wpdb $wpdb
   *
   * @param int    $post_id      Attachment ID; excluded from the shared-thumb lookup.
   * @param array  $meta        Attachment metadata (`file`, `sizes`, `thumb`).
   * @param array  $backup_sizes Value of the `_wp_attachment_backup_sizes` meta.
   * @param string $file         Absolute path of the attached file.
   * @return bool True when every deletion succeeded, false if any one failed.
   *
   * @todo Dead on the readme minimum (WordPress 4.9.7 provides the core
   *       function); remove with the rest of this file.
   */
  function wp_delete_attachment_files($post_id, $meta, $backup_sizes, $file )
  {
    global $wpdb;
  $uploadpath = wp_get_upload_dir();
  $deleted    = true;

    if ( ! empty( $meta['thumb'] ) ) {
        // Don't delete the thumb if another attachment uses it.
        if ( ! $wpdb->get_row( $wpdb->prepare( "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s AND post_id <> %d", '%' . $wpdb->esc_like( $meta['thumb'] ) . '%', $post_id ) ) ) {
            $thumbfile = str_replace( wp_basename( $file ), $meta['thumb'], $file );
            if ( ! empty( $thumbfile ) ) {
                $thumbfile = path_join( $uploadpath['basedir'], $thumbfile );
                $thumbdir  = path_join( $uploadpath['basedir'], dirname( $file ) );

                if ( ! wp_delete_file_from_directory( $thumbfile, $thumbdir ) ) {
                    $deleted = false;
                }
            }
        }
    }

    // Remove intermediate and backup images if there are any.
    if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
        $intermediate_dir = path_join( $uploadpath['basedir'], dirname( $file ) );
        foreach ( $meta['sizes'] as $size => $sizeinfo ) {
            $intermediate_file = str_replace( wp_basename( $file ), $sizeinfo['file'], $file );
            if ( ! empty( $intermediate_file ) ) {
                $intermediate_file = path_join( $uploadpath['basedir'], $intermediate_file );

                if ( ! wp_delete_file_from_directory( $intermediate_file, $intermediate_dir ) ) {
                    $deleted = false;
                }
            }
        }
    }

    if ( is_array( $backup_sizes ) ) {
        $del_dir = path_join( $uploadpath['basedir'], dirname( $meta['file'] ) );
        foreach ( $backup_sizes as $size ) {
            $del_file = path_join( dirname( $meta['file'] ), $size['file'] );
            if ( ! empty( $del_file ) ) {
                $del_file = path_join( $uploadpath['basedir'], $del_file );

                if ( ! wp_delete_file_from_directory( $del_file, $del_dir ) ) {
                    $deleted = false;
                }
            }
        }
    }

    if ( ! wp_delete_file_from_directory( $file, $uploadpath['basedir'] ) ) {
        $deleted = false;
    }

    return $deleted;

  }
} // end function


/*
* Introduced in  WP 4.9.7 - https://developer.wordpress.org/reference/functions/wp_delete_attachment_files/
* Compat for previous versions.
*/
if (! function_exists('wp_delete_file_from_directory'))
{
  /**
   * Polyfill of core wp_delete_file_from_directory() (WordPress 4.9.7+).
   *
   * Deletes $file with wp_delete_file() only when its real path lies inside
   * $directory. Stream-wrapper paths are normalised but not realpath()ed.
   *
   * The block comment above links to the wp_delete_attachment_files()
   * reference page instead of this function's own page.
   *
   * @param string $file      Path of the file to delete.
   * @param string $directory Directory the file must be inside.
   * @return bool False when either path does not resolve or the file is
   *              outside the directory; true otherwise (the deletion itself
   *              is not verified).
   *
   * @todo Dead on the readme minimum (WordPress 4.9.7 provides the core
   *       function); remove with the rest of this file.
   */
  function wp_delete_file_from_directory( $file, $directory ) {
    if ( wp_is_stream( $file ) ) {
        $real_file      = wp_normalize_path( $file );
        $real_directory = wp_normalize_path( $directory );
    } else {
        $real_file      = realpath( wp_normalize_path( $file ) );
        $real_directory = realpath( wp_normalize_path( $directory ) );
    }

    if ( false === $real_file || false === $real_directory || strpos( $real_file, trailingslashit( $real_directory ) ) !== 0 ) {
        return false;
    }

    wp_delete_file( $file );

    return true;
  }

} // end function


/*
* Introduced in WP 4.5.0 - needed for compat function of wp_delete_attachment_files
*/
if (! function_exists('wp_get_upload_dir'))
{
  /**
   * Polyfill of core wp_get_upload_dir() (WordPress 4.5+).
   *
   * Returns the uploads directory information without creating the month
   * subdirectory.
   *
   * @return array Same shape as wp_upload_dir().
   *
   * @todo Dead: core provides this since WordPress 4.5, well below the readme
   *       minimum of 4.9.7; remove with the rest of this file.
   */
  function wp_get_upload_dir() {
	         return wp_upload_dir( null, false );
         }
}
