<?php 
namespace EnableMediaReplace\Replacer\Classes; 


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


class Updater
{

    protected static $updatesNumber = 0; 

    public function updatePost($post_id, $content)
    {
        global $wpdb;

        // Use the WordPress API so hooks, revisions and cache invalidation
        // run as expected. Preserve the original post_modified timestamps
        // so bulk attribute-only edits don't change the visible "last
        // updated" date.
        $post = get_post($post_id, ARRAY_A);
        if (false === $post || null === $post) {
            Log::addError('Post not found for updatePost: ' . $post_id);
            return false;
        }

        $orig_modified = $post['post_modified'];
        $orig_modified_gmt = $post['post_modified_gmt'];

        $update = [
            'ID' => $post_id,
            'post_content' => wp_slash($content),
        ];

        $result = wp_update_post($update, true);

        if (is_wp_error($result)) {
            Log::addError('WP-Error during post update', $result->get_error_message());
            return false;
        }

        // Restore the original modified timestamps so themes and sitemaps
        // that display post_modified are not affected by content-only
        // AI attribute replacements.
        $wpdb->update(
            $wpdb->posts,
            [ 'post_modified' => $orig_modified, 'post_modified_gmt' => $orig_modified_gmt ],
            [ 'ID' => $post_id ]
        );

        clean_post_cache($post_id);

        self::$updatesNumber++;
        return true;
    }



}