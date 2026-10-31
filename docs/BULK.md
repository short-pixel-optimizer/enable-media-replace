# Bulk processing: design note

Enable Media Replace runs two operations on many attachments at once, from
the visible **Media > Bulk** page (`upload.php?page=emr-bulk`, capability
`manage_options`):

- **Remove background** of every image used by WooCommerce products: the
  featured image and the gallery of products and variations (not trashed).
- **Upscale** every JPEG and PNG attachment of the media library narrower
  than a width threshold (default 1200 px), each with the largest factor
  its width allows (`UIHelper::getUpscaleFactors()`).

Both apply the result **in place** through the single-image replace flow
(`ReplaceController`, `MODE_REPLACE`: same file name, thumbnails
regenerated, references untouched), after copying the original to
`uploads/EMR_Backups/<yyyy>/<mm>/`. The attachment then carries the
`_emr_bulk` post meta (operation, options, backup path), which a later run
of the same operation skips and which a **bulk restore** consumes to put the
original back. The bulk needs a ShortPixel API key on an Unlimited plan.

## Layout

The queue layer is a port of ShortPixel Image Optimizer's (SPIO) queue
controllers onto the bundled ShortQ module and keeps SPIO's class and method
names, so that fixes port one-to-one:

| EMR | Role | SPIO origin |
|---|---|---|
| `Controller/Bulk/BulkQueue` | the ShortQ queue (slug `emr`, name `bulk`): create / prepare / start / finish, stats | `Queue` + `MediaLibraryQueue` |
| `Controller/Bulk/QueueController` | one tick: processor key and plan gates, run the queue, process the items, JSON for the screen | `QueueController` |
| `Controller/Bulk/BulkController` | create / start / finish a bulk run, settings (`bulk_*` keys of `enable_media_replace`), the bulk logs (`emr_bulk_logs`) | `BulkController` |
| `Controller/Bulk/ActionController` | send an item to the API, apply the result (download, backup, replace, marker), restore | `OptimizeController` + `OptimizerBase` |
| `Controller/Bulk/BulkApi` | the reducer / cleanup client for `remove_background` and `scale_image` | `ApiController` + `RequestManager` |
| `Controller/Bulk/BackupController` | the originals under `EMR_Backups` | `BackupController` |
| `Controller/Bulk/ResponseController` | per-item messages for the screen and the log | `ResponseController` |
| `Controller/Bulk/Selector/*` | the prepare-phase sources (`WooCommerceProductImages`, `UpscaleCandidates`) | (new) |
| `Controller/Bulk/BulkAjaxController` | `wp_ajax_emr_bulk_process` / `_request` / `_settings`, the two-tab processor key | `AjaxController` |
| `Controller/Bulk/CronController` | WP-Cron ticks while the page is closed | `CronController` |
| `Model/Bulk/QueueItem`, `QueueItemData`, `QueueItemResult` | an attachment + action + data + result | `QueueItem`, `QueueItemData`, `QueueItemResult` |
| `Model/Bulk/BulkImage` | the attachment adapter (file, URL, width, factor, marker) | `MediaLibraryModel` (reduced) |
| `ViewController/BulkViewController`, `views/bulk/*` | the page | `BulkViewController`, `view-bulk.php` |
| `js/bulk/emr-worker.js`, `emr-processor.js`, `emr-bulk-screen.js` | the browser processor and the screen (vanilla JS) | `shortpixel-worker.js`, `shortpixel-processor.js`, `screen-bulk.js` |

Only ShortQ is shared with SPIO and Resize Image After Upload; the rows of
the common `{prefix}shortpixel_queue` table are scoped by `plugin_slug` and
`queue_name`, and the queue status lives in the `shortqwp_emr` option, so
the three plugins never see each other's items.

## Lifecycle

1. **Create.** `BulkController::createNewBulk($operation, $options)` stores
   the operation and its options (background type and colour, threshold,
   cron flag) in the queue's custom data and flags `preparing`.
2. **Prepare.** Each tick (`QueueController::processQueue()`) enqueues one
   page of candidate IDs. The source is the explicit `items` list of the
   options when given, else the `emr/bulk/source_ids` filter, else the
   operation's `Selector`. `prepareItems()` drops non-images, unsupported
   mime types, attachments that already carry the marker, files shared by
   several attachments, items already queued, and (upscale) widths at or
   above the threshold. An empty page ends the preparing phase.
3. **Run.** `startBulk()` flags `running`; each tick dequeues `numitems`
   (5) items. `ActionController::sendToProcessing()` posts them to the API
   (`bg_remove` or `upscale` in the reducer body); a pending answer leaves
   the item in the queue for the next tick (ShortQ `wait` mode,
   `process_timeout` 10 s); a result is downloaded, the original backed up,
   the file replaced, the marker set; a failure is logged and the item is
   done with an error.
4. **Finish.** `finishBulk()` writes the log entry (operation, images,
   errors, date), resets the queue and unschedules the cron event.
5. **Restore.** The `bulk-restore` custom operation enqueues the marked
   attachments of an operation; each item copies its backup back through
   the same replace flow and deletes the marker.

## Driving the queue

- **Browser.** `js/bulk/emr-processor.js` posts a tick every
  `emr/bulk/interval` ms (3 s; 200 ms while preparing) through the Web
  Worker to `wp_ajax_emr_bulk_process` and hands the JSON to
  `emr-bulk-screen.js`, which switches the panels (dashboard, selection,
  summary, process, finished, restore) and writes the stats. The JSON keeps
  SPIO's `media` / `total` shape so the script port stays close to its
  origin. The first processor claims the `emr_bulk_secret` transient
  (2 min, renewed per tick); a tab with another key is answered
  `PROCESSOR_ACTIVE`, shows the blocked banner and rechecks after three
  minutes. Everything the scripts need is localized as `emrBulk`
  (`EnableMediaReplacePlugin::bulkScriptData()`); nothing is inline.
- **WP-Cron.** With the `background_process` setting on, `startBulk()`
  schedules `emr_bulk_cron` on the `emr_bulk_interval` schedule (60 s,
  `emr/bulk/cron_interval`). Each run is a loop of ticks (10 runs, 50 s,
  1 s apart; `emr/bulk/cron/options`) that stops on an empty queue or a
  refused tick. Cron never prepares and never takes the browser's key.

## Gates

Every AJAX action needs `manage_options` and its own nonce. A tick also
needs the processor key and a key on an Unlimited plan, checked once per
hour (`emr_bulk_plan_ok`, `emr/bulk/plan_check_ttl`); a failed check
answers `PLAN_EXPIRED` and the screen pauses with the over-quota notice.
The background operation is unavailable without the `product` post type
(`BulkController::canRun()`).

## Hooks

`emr/bulk/selector`, `emr/bulk/source_ids`, `emr/bulk/background/post_types`,
`emr/bulk/upscale/threshold`, `emr/bulk/prepare_items`, `emr/bulk/queue_options`,
`emr/bulk/custom_options`, `emr/bulk/backup_dir`, `emr/bulk/api/request`,
`emr/bulk/api/timeout`, `emr/bulk/plan_check_ttl`, `emr/bulk/item/done`,
`emr/bulk/item/failed`, `emr/bulk/interval`, `emr/bulk/defer_interval`,
`emr/bulk/cron_interval`, `emr/bulk/cron/options`.

## Tests

Unit: `tests/Controller/Bulk/*` (one file per class, ported from SPIO's
queue tests), `tests/ViewController/test-BulkViewController.php`,
`tests/Modules/test-ShortQ.php`. Integration: `tests/Integration/test-BulkFlow.php`
(whole runs against the mocked API, the cron case included);
`tests/Compat/test-CompatWooCommerce.php` (the product selector against
real WooCommerce). Browser: `tests/E2E/specs/bulk.spec.ts` with the page
object `tests/E2E/helpers/bulk-page.ts` (see TESTING.md). Every test class
that touches the queue installs the table in `set_up_before_class()`
(TESTING.md, "Tests that touch the ShortQ queue table").
