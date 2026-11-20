<?php
/**
 * Updater class of the newer search/replace API.
 *
 * @package EnableMediaReplace\Replacer
 */
namespace EnableMediaReplace\Replacer\Classes;


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;


/**
 * Writes replaced content back to the database.
 *
 * Two entry points:
 * - updateRecord(): writes a Record found by the Finder back to the table
 *   and row it came from, and clears the matching object cache. This is
 *   what Replacer2::replace() uses.
 * - updatePost(): saves post content through wp_update_post(), for callers
 *   that want post hooks and revisions.
 *
 * A new object is created per use (Replacer2::Updater()). It fires no hooks
 * itself; wp_update_post() fires the usual post hooks.
 */
class Updater
{

    /**
     * Number of successful updatePost() calls in this request, shared by all
     * instances. Not read anywhere in the module.
     *
     * @var int
     */
    protected static $updatesNumber = 0;

    /**
     * Writes a changed record back to where it was found.
     *
     * Unchanged and skipped records are not written. Per table key:
     * - `posts`: direct UPDATE of `post_content` followed by
     *   clean_post_cache(). No post hooks, no revision and `post_modified`
     *   is untouched, as the replacer has always done: going through
     *   wp_update_post() would run the content through kses for users
     *   without `unfiltered_html` and fire save hooks of every plugin.
     * - `options`: UPDATE on the id column, then the `alloptions`,
     *   `notoptions` and the option's own name are removed from the
     *   `options` cache group.
     * - any other table: UPDATE on the id column, then the owning object
     *   (`main_id` value) is removed from the definition's cache group, when
     *   both are known.
     *
     * Values are written as they are; replaceContent() has already
     * serialized arrays and objects unless the run asked otherwise.
     *
     * @global \wpdb $wpdb
     *
     * @param Record $record The record to write.
     * @return bool True when a row was written, false when nothing was to be
     *              written or the write failed (logged).
     */
    public function updateRecord(Record $record): bool
    {
        global $wpdb;

        if (false === $record->isChanged()) {
            return false;
        }

        $table_name = $record->getTableName();
        $id = $record->getId();

        if (null === $table_name || null === $id) {
            Log::addError('Updater: record without table or id, not saved', [$record->getTable(), $record->getDefinition()]);
            return false;
        }

        $result = $wpdb->update(
            $table_name,
            [ $record->getValueField() => $record->getContent() ],
            [ $record->getIdField() => $id ],
            [ '%s' ],
            [ '%d' ]
        );

        if (false === $result) {
            Log::addError('Updater: update failed on ' . $table_name . ' id ' . $id, $wpdb->last_error);
            return false;
        }

        $this->clearRecordCache($record);

        self::$updatesNumber++;
        return true;
    }

    /**
     * Writes several records, see updateRecord().
     *
     * @param Record[] $records Records to write.
     * @return int Number of rows written.
     */
    public function updateRecords(array $records): int
    {
        $written = 0;
        foreach ($records as $record) {
            if (true === $this->updateRecord($record)) {
                $written++;
            }
        }
        return $written;
    }

    /**
     * Clears the object cache entries of a written record.
     *
     * @param Record $record The written record.
     * @return void
     */
    protected function clearRecordCache(Record $record)
    {
        $main_id = $record->getMainId();

        switch ($record->getTable()) {
            case 'posts':
                clean_post_cache($record->getId());
                break;
            case 'options':
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('notoptions', 'options');
                if (null !== $main_id) {
                    wp_cache_delete($main_id, 'options');
                }
                break;
            default:
                $cache_group = $record->getCacheGroup();
                if (null !== $cache_group && null !== $main_id) {
                    wp_cache_delete($main_id, $cache_group);
                }
                break;
        }
    }

    /**
     * Replaces the content of a post, keeping its modified date.
     *
     * Saves through wp_update_post() (with wp_slash() applied to the
     * content), so post hooks, revisions and cache invalidation run. Then
     * writes the original `post_modified` and `post_modified_gmt` back with
     * a direct `$wpdb` update and calls clean_post_cache().
     *
     * @global \wpdb $wpdb
     *
     * @param int    $post_id Post ID.
     * @param string $content New, unslashed post content.
     * @return bool True on success, false when the post does not exist or
     *              wp_update_post() returns a WP_Error.
     */
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