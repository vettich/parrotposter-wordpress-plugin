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
- **Pipeline events (v2):** operation prefix `pe_` — always enqueued in the save hook; GraphQL runs on flush (see below), not synchronously in [`PushEventService`](src/PushEventService.php).

- **Shutdown self-flush:** when a queue row is enqueued, [`LocalQueue::schedule_wake_on_shutdown()`](src/LocalQueue.php) registers one callback per request. If `fastcgi_finish_request()` exists, the HTTP response is closed first, then [`handle_http_process(true)`](src/LocalQueue.php) runs (up to 10 items / 5s) including `pluginPipelineEventIngest`. If `has_more`, post-queue chain wake runs as before. Without FPM (`fastcgi_finish_request` unavailable), shutdown runs [`handle_http_process(true)`](src/LocalQueue.php) when `site_to_pp` is set (pipeline GraphQL HMAC — avoids post-queue callback to `admin-ajax`, which may be behind nginx auth-request on dev). Legacy-only sites without `site_to_pp` still use [`Api::request_local_queue_wake()`](src/Api.php). Cron (`parrotposter_retry_local_queue`, every minute) also flushes via `site_to_pp` before falling back to wake.

## Pipeline push events (v2)

When [`Settings::get_migration_mode()`](src/Settings.php) is `pipeline`, [`PipelineHooks`](src/PipelineHooks.php) enqueues pipeline events via [`PushEventService`](src/PushEventService.php) → [`LocalQueue`](src/LocalQueue.php). The save/delete hook does **not** wait on PP latency. GraphQL delivery uses `site_to_pp` Bearer + HMAC ([`Api::graphql_mutation`](src/Api.php)) during queue flush. Legacy [`Scheduler`](src/Scheduler.php) hooks still run when `migration_mode = legacy`.

- **GraphQL transport:** HTTP POST to [`Env::graphql_api_uri()`](src/Env.php) (`/api/graphql`); HMAC `signing_input` path is [`Env::graphql_signing_path()`](src/Env.php) (`/graphql` — back-app route after nginx strips `/api`). `pluginPipelineEventIngest` sends `payload` as `[SourceFieldInput!]!` (typed bag per SPEC-002-18 §2.2), not raw JSON — see [`PushEventService::payload_to_source_fields`](src/PushEventService.php).

- **Settings cache:** `migration_mode`, `site_to_pp`, `pipeline_ids`, per-pipeline `contract_version` / `source_path`.
- **Greenfield connect:** `PluginConnect::silent_bind()` stores secrets; `completePluginBinding` returns `migration_mode = legacy` until migration runs.
- **Pipeline cache on site:** `pipeline_ids` + contracts from legacy migration (`MigrationService`) and from PP push `POST .../notify_contract` (wire protocol). Contract snapshot includes `required_fields`, `source_path`, `scope_filter` (top-level or nested in `source_filters`), `template_required_fields`, `contract_version`.
- **Event push filters (SPEC-002-08 §8.6):** before ingest, `PushEventService` evaluates `scope_filter` per pipeline contract; `UPDATED` sends only when `changedFields` ∩ `template_required_fields` is non-empty. Payload includes all `required_fields` keys (via `Fields` / `CommonType`); image values are absolute URLs.
- **Publishability:** `CREATED` on first →`publish`; `UPDATED` while staying `publish`; leaving `publish` for `draft`/`private`/… → `DELETED` from `on_post_saved` (skip when new status is `trash` — handled by `on_post_deleted`). WooCommerce product meta/price saves also fire `UPDATED` via `woocommerce_update_product`; product_* keys are in the `_pp_prev_fields` diff baseline.
- **`GET /fields`:** `post_type` query is required; unknown type → 400. Response is `FieldSchema` with Instant MVP keys (`title`, `excerpt`, `content`, `link`, `date`, plus media: `featured_image`, `images_in_content`, `featured_video`, `videos_in_content`, `attached_videos`, `gifs_in_content`, `attached_audio`, `attached_documents`) grouped into sections `basic` / `media`. Taxonomies with `show_ui` (e.g. `category`, `post_tag`) are included with `semantic: taxonomy`, `type: array`, section `taxonomies`, and inline `value_schema.options` (`value` = term_id string, `label` from legacy `conditions\Taxonomies::get_terms`). For `product`, all `product_*` fields go to section `woocommerce` (including `product_image` / `product_gallery`). Legacy macro `content_first_paragraph` is excluded from pipeline FieldSchema (still available in legacy scheduler via [`CommonType`](src/fields/CommonType.php)). Also returns `filter_capabilities` (`compare_ops` MVP whitelist + `sortable_fields`). Per-field: `sortable` for `date`/`title`/`published_at`/`ID`; media `filterable` only for `featured_image` (`is_set`/`is_empty`); other media `filterable: false`.
- **Taxonomy payload (wire/pipeline):** required taxonomy fields in `build_item_payload` are `string[]` of term IDs (for `includes_any` / scope_filter). Legacy TextProcessor macros `{category}` / `{post_tag}` still resolve to comma-separated **names**.
- **`ExpressionEval`:** supports set ops `includes_any` / `includes_all` / `excludes_any` plus `is_set` / `is_empty` (mirrors back-app), used by Instant `scope_filter` and `/items/latest?filter=`.
- **`SelectionFilterQuery`:** Archive `fetch_next` compiles `selection_filter` AST → `tax_query` / `meta_query` / `date_query` / `posts_where` **before** `posts_per_page=1`. Pushable ops: `eq`, `neq`, `is_true`, `is_false`, `includes_any`, `is_set`, `is_empty`. Unsupported nodes → **422** `unsupported_selection_filter` (fail-closed).
- **Post meta:** `_pp_prev_fields` — diff baseline for `changedFields` on `UPDATED`; updated only after successful queue flush ingest ([`PushEventService::apply_side_effects_after_ingest`](src/PushEventService.php)), not at enqueue time.
- **`source_item_id`:** `{post_type}:{post_id}`.
- **Source fields:** Instant MVP keys via [`CommonType`](src/fields/CommonType.php) / [`PushEventService::build_item_payload`](src/PushEventService.php). Media payload items are `{url, mime?, kind}` objects (`kind`: `image|video|gif|audio|file`). For `product` post type, WooCommerce fields from [`ProductType`](src/fields/ProductType.php) (e.g. `product_regular_price`, `product_image`, `product_gallery`) are always included in the push payload (and when listed in `required_fields` / `items/latest?required_fields=`). Content media URL → attachment resolve prefers exact `attachment_url_to_postid` (no LIKE basename remap for external/broken URLs). Legacy scheduler still supports `{content_first_paragraph}` via [`Tools::content_first_paragraph`](src/Tools.php).

## Wire protocol REST (PP→site, v2)

[`WireProtocol`](src/WireProtocol.php) registers CMS callbacks at `parrotposter/v1` and `parrotposter/v1/pp/v1` (aliases):

| Endpoint | Purpose |
|----------|---------|
| `GET .../info` | `migration_mode`, `capabilities`, `plugin_version`, `filter_capabilities` |
| `GET .../fields?post_type=` | `FieldSchema` (`fields` + `sections` + `filter_capabilities`) |
| `GET .../items/{source_item_id}` | Single `SourceItem` for preview |
| `GET .../items/latest?post_type=&limit=&offset=&filter=&required_fields=` | Latest published items for template preview; optional Expression AST `filter` scans until `limit` matches (returns `next_offset`); optional `required_fields` (JSON string array) merges into item payload beyond MVP keys |
| `POST .../items/next` | Sequential `fetch_next` (BE-18/WP-06 JSON: `pipeline_id`, `contract_version`, `source_path`, `selection_filter?`, `sort?`, `exclude_mode`, `published_ids` **or** `exclude_sync`, `run_exclude_ids`) → `{ item, contract_version, exclude_ack? }` |
| `POST .../published_ids_sync` | Full published exclude snapshot pages (WP-06; PP pushes `source_item_ids` + `server_version`) |
| `POST .../notify_contract` | PP→site contract push (`pipeline_id`, `contract_version`, `source_path`, …) |

- **Exclude cache (WP-06):** table `{prefix}parrotposter_exclude_ids` + option `parrotposter_exclude_cache_meta` (`ExcludeCache`). Inline `published_ids` when ≤L; when `exclude_sync.mode=delta`, merge into cache before SQL; `full_snapshot_required` → 409 until PP pushes `published_ids_sync`.
- **Auth:** `Authorization: Bearer {pp_to_site}` or `X-ParrotPoster-Secret`; verified via HMAC-hash in [`Settings::verify_pp_to_site_secret()`](src/Settings.php) (`hash_equals`, dual-key rotation window).
- **Rate limit:** transient `pp_wire_rate_{ip}`, 60 req/min.
- **Migration AJAX:** `wp_ajax_pp_migrate_to_pipeline` / `wp_ajax_pp_revert_migration` / `wp_ajax_pp_dismiss_migration_banner` → [`MigrationService`](src/MigrationService.php) or [`UserPreferences`](src/UserPreferences.php). Revert/migrate use GraphQL `migratePluginToPipeline` / `revertPluginToLegacy` ([`Api::graphql_user_mutation`](src/Api.php), user session Bearer). Requires `manage_options` + `parrotposter_ajax` nonce. Calls [`PluginConnect::silent_bind()`](src/PluginConnect.php) when `plugin_id` is missing.

## Plugin connect (site binding)

[`PluginConnect`](src/PluginConnect.php) binds the WP site to PP **server-to-server** (no browser OAuth):

- **Silent bind:** [`PluginConnect::silent_bind()`](src/PluginConnect.php) — `createPluginAuthCode` (user bearer) → `completePluginBinding` → store `plugin_id` / secrets via [`Settings`](src/Settings.php). Idempotent when [`Settings::is_connected()`](src/Settings.php) is already true.
- **Triggers:** after login/signup in [`AdminAjaxPost`](src/AdminAjaxPost.php); on plugin update in [`Install::check_version()`](src/Install.php) when `Options::user_id()` is set.
- **Auto pipeline:** after bind, if `migration_mode = legacy` and no enabled legacy templates ([`Settings::has_enabled_legacy_templates()`](src/Settings.php)), calls [`MigrationService::migrate_to_pipeline([])`](src/MigrationService.php) without UI.
- **Disconnect:** Settings page — `admin-post.php?action=parrotposter_connect_disconnect` — [`Api::disable_plugin`](src/Api.php) (user bearer) + local [`Settings::disconnect()`](src/Settings.php).
- **SSO to web app:** Settings — [`Api::build_sso_url()`](src/Api.php) → `{PP}/auth/enter-with-token?token=…` (via `issueSessionKey`), separate from plugin bind.

## Pipeline migration UI

When `migration_mode = legacy` and [`Settings::has_enabled_legacy_templates()`](src/Settings.php): Scheduler shows migrate/revert banner ([`views/scheduler/migration-banner.php`](views/scheduler/migration-banner.php)). After migration, admin menu label switches from **Scheduler** to **Pipelines** ([`Menu.php`](src/Menu.php)) — iframe embed to front-app `/plugin/wp/pipelines`. Pipeline-active compact banner ([`views/partials/migration-banner-pipeline-active.php`](views/partials/migration-banner-pipeline-active.php)) on Pipelines/Scheduler when legacy templates remain; dismiss per-user via [`UserPreferences`](src/UserPreferences.php) (`user_meta`), restore in **Settings → Pipelines**. Revert also available in Settings.

## Media upload (autopost create / update)

When a WordPress post is published or updated, images for ParrotPoster are resolved in [`Scheduler::upload_wp_images()`](src/Scheduler.php) with an optional shared [`MediaUploadCache`](src/MediaUploadCache.php) for the whole run:

- **`publish_post()`** and **`update_pp_posts_for_wp_post()`** share one [`MediaUploadCache`](src/MediaUploadCache.php) for the whole run. The cache is filled **lazily** while each template (or linked PP post) is processed—no upfront upload for all merged `post_images`. The same `attachment_id` / URL is uploaded at most once per run; later templates reuse the cached `file_id` or fallback URL.
- **`Api::upload_file()`** retries transient failures (up to 3 attempts); on failure, **`image_urls`** fallback uses `wp_get_attachment_url()` when upload of a local file fails.
- **Update sync:** [`Tools::filter_post_fields_for_api()`](src/Tools.php) omits empty `images` / `image_urls` before [`Api::update_post()`](src/Api.php) so the API does not interpret `[]` as “clear all media”. Non-empty values still update files/URLs. Create and manual publish are unchanged.
- **`fields.extra`** includes `wp_autoposting_id` (template id) for api-server create rate-limit guard keys per template.
- Manual publish via template (`publish_post_by_template_without_check` without cache) still works for a single template.

## Local e2e testing (agents)

On the developer machine, see workspace [`meta/local-dev/wordpress-pipeline-testing.md`](../meta/local-dev/wordpress-pipeline-testing.md) and load [`meta/local-dev/.env.local`](../meta/local-dev/.env.local) (refresh via `meta/local-dev/refresh-env-local.sh`). Requires host network access outside the Cursor sandbox.

## Common tips

- Write doc comments and other comments in code in English
