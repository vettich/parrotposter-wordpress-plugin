# Context: parrotposter wordpress plugin

Plugin for ParrotPoster autoposting service.

## Local sync queue (admin UI)

Deferred create/update/delete for WP posts is stored in `{wpdb->prefix}parrotposter_local_queue` ([`src/LocalQueue.php`](src/LocalQueue.php)).

- **Datetimes (UTC):** `next_attempt_at`, `created_at`, and `locked_until` are stored and compared in **UTC** (`gmdate` / `UTC_TIMESTAMP()`). Do not use `current_time('mysql')` or SQL `NOW()` for queue readiness. Retry backoff already uses `gmdate` (UTC). On upgrade to DB version `1.0.9`, `migrate_enqueue_rows_to_utc()` converts existing `pending` rows with `attempts = 0` from the site WordPress timezone to UTC and triggers `request_post_queue_wake()`.
- **HTTP batch:** each `handle_http_process()` / `process_pending_admin()` call claims and runs **one row at a time**, up to **10** items and within **5 seconds** (`HTTP_PROCESS_MAX_ITEMS`, `HTTP_PROCESS_TIME_BUDGET_SEC`). Then `has_more` drives post-queue chain wake when enabled.
- **Alert** (yellow admin notice): shown on **Posts** and **Scheduler** when `LocalQueue::get_pending_count() > 0` (`status = pending` only). Views: [`views/local-queue/setup.php`](views/local-queue/setup.php) included from [`views/posts/index.php`](views/posts/index.php) and [`views/scheduler/header.php`](views/scheduler/header.php).
- **Modal “View”**: loads rows via AJAX `wp_ajax_parrotposter_local_queue_list` ([`AdminAjaxPost::local_queue_list`](src/AdminAjaxPost.php)) — lists `pending`, `processing`, and `failed` with WP post title/edit link. Requires `manage_options` and `parrotposter_ajax` nonce.
- **Manual process (no post-queue)**: `wp_ajax_parrotposter_process_local_queue_admin` ([`AdminAjaxPost::process_local_queue_admin`](src/AdminAjaxPost.php)) — runs [`LocalQueue::process_pending_admin()`](src/LocalQueue.php) (batch, `handle_http_process(false)`) or [`LocalQueue::process_queue_row($id)`](src/LocalQueue.php) for one row. Distinct from scheduler callback `parrotposter_process_local_queue`. UI: **Process now** in modal footer, **Process** per `pending`/`failed` row. Does not call `Api::request_local_queue_wake()`.
- **Assets**: module `local-queue-admin` (`assets/js/local-queue-admin.js`, `assets/css/local-queue-admin.css`; depends on `modal`).
- **Test seed**: `define('PARROTPOSTER_LQ_TEST_SEED', true);` in `wp-config.php` inserts one pending `update` row on ParrotPoster admin screens (`LocalQueue::maybe_seed_test_record`). Remove the constant after UI check.

## Media upload (autopost create / update)

When a WordPress post is published or updated, images for ParrotPoster are resolved in [`Scheduler::upload_wp_images()`](src/Scheduler.php) with an optional shared [`MediaUploadCache`](src/MediaUploadCache.php) for the whole run:

- **`publish_post()`** and **`update_pp_posts_for_wp_post()`** share one [`MediaUploadCache`](src/MediaUploadCache.php) for the whole run. The cache is filled **lazily** while each template (or linked PP post) is processed—no upfront upload for all merged `post_images`. The same `attachment_id` / URL is uploaded at most once per run; later templates reuse the cached `file_id` or fallback URL.
- **`Api::upload_file()`** retries transient failures (up to 3 attempts); on failure, **`image_urls`** fallback uses `wp_get_attachment_url()` when upload of a local file fails.
- **Update sync:** [`Tools::filter_post_fields_for_api()`](src/Tools.php) omits empty `images` / `image_urls` before [`Api::update_post()`](src/Api.php) so the API does not interpret `[]` as “clear all media”. Non-empty values still update files/URLs. Create and manual publish are unchanged.
- **`fields.extra`** includes `wp_autoposting_id` (template id) for api-server create rate-limit guard keys per template.
- Manual publish via template (`publish_post_by_template_without_check` without cache) still works for a single template.

## Common tips

- Write doc comments and other comments in code in English
