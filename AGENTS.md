# plugin-wordpress

WordPress plugin for ParrotPoster. Repo root for all commands (`make docker-up`, `bin/make-zip.sh`, `bin/test-*.php`). Comments in code: English.

Two directions, two queues — do not fold outbound work into `LocalQueue`:

| Direction | Classes | Role |
| --------- | ------- | ---- |
| Site → PP | [`LocalQueue`](src/LocalQueue.php), [`PushEventService`](src/PushEventService.php), [`PipelineHooks`](src/PipelineHooks.php) | Deferred push (legacy create/update/delete + v2 `pe_*` pipeline events) |
| PP → site | [`OutboundTaskWorker`](src/OutboundTaskWorker.php), [`OutboundTaskQueue`](src/OutboundTaskQueue.php), [`OutboundTaskDispatch`](src/OutboundTaskDispatch.php), [`OutboundPollScheduler`](src/OutboundPollScheduler.php) | Fallback poll when PP cannot push `callback_url` |

Legacy autopost lives in [`Scheduler`](src/Scheduler.php) when `migration_mode = legacy`. New pipeline work: hooks → enqueue → GraphQL on flush, not sync in the save hook.

## Conventions

- **UTC** for queue/settings datetimes (`gmdate` / `UTC_TIMESTAMP()`). Never `current_time('mysql')` or SQL `NOW()` for readiness.
- **LocalQueue flush:** enqueue registers one shutdown callback. Prefer `handle_http_process()` after `fastcgi_finish_request()` (or on shutdown when `site_to_pp` is set — avoids `admin-ajax` behind nginx auth-request). Legacy-only sites without `site_to_pp` still `Api::request_local_queue_wake()`. Cron `parrotposter_retry_local_queue` flushes the same way. Batch: 10 items / 5s.
- **GraphQL:** POST [`Env::graphql_api_uri()`](src/Env.php) (`/api/graphql`); HMAC path is [`Env::graphql_signing_path()`](src/Env.php) (`/graphql` after nginx strips `/api`). `pluginPipelineEventIngest` payload is `[SourceFieldInput!]!`, not raw JSON ([`PushEventService::payload_to_source_fields`](src/PushEventService.php)).
- **Activity timestamps (UTC):** `last_primary_call_at` only from [`WireProtocol::authorize_request()`](src/WireProtocol.php). `last_site_to_pp_call_at` only from [`Api::do_graphql_site_request`](src/Api.php) after a successful machine GraphQL call. Clear both on `Settings::disconnect()`.
- **Outbound:** cron ticks at `OutboundPollScheduler::MIN_INTERVAL_SEC`; only `pluginOutboundTasksLease` is throttled (`is_lease_due()`). GraphQL `pluginOutboundTasksLease` / `pluginOutboundTaskReport` via `site_to_pp`. `decide_action()` never re-executes a terminal `task_id`; `INSERT IGNORE` on re-lease. Check `expires_at` before dispatch. Dispatch is the `parrotposter_outbound_task_dispatch` filter — [`OutboundTaskDispatch`](src/OutboundTaskDispatch.php) only. `fetch_next`/`fetch_batch` reuse [`WireProtocol`](src/WireProtocol.php) body handlers; do not reimplement query logic. `rotate_secrets` is the only rotation path (confirm in the same report). Signing pubkey arrives in `completePluginBinding` (`outboundTaskSigningPublicKey`); current+prev keys, drop prev only after first verify against **current**.
- **Canonical JSON:** [`CanonicalJson`](src/CanonicalJson.php) must stay byte-compatible with back-app. Do not approximate as “sorted keys + `json_encode`”. Golden vectors: `bin/test-wp09-signature-dispatch.php`.
- **Pipeline events:** enqueue on save; ingest on flush. `idempotencyKey` required. `pipeline.not_found` → drop id via `Settings::remove_pipeline_id` and delete the row (no retry). `_pp_prev_fields` updates only after successful ingest. `source_item_id` is `{post_type}:{post_id}`. Taxonomy fields in wire/pipeline payloads are term **IDs**; legacy macros still use names. `SelectionFilterQuery` is fail-closed (unsupported AST → 422 `unsupported_selection_filter`).
- **Legacy media update:** omit empty `images` / `image_urls` (`Tools::filter_post_fields_for_api`) so `[]` is not “clear all media”.

## Wire REST (PP → site)

[`WireProtocol`](src/WireProtocol.php) at `parrotposter/v1` and `parrotposter/v1/pp/v1`. Auth: Bearer `pp_to_site` or `X-ParrotPoster-Secret`.

`GET info` · `GET fields?post_type=&locale=` · `GET items/{source_item_id}` · `GET items/latest` · `POST items/next` · `POST items/batch` · `POST published_ids_sync` · `POST notify_contract`

Iframe wizard must not HTTP-loopback to these routes: use AJAX (`field_schema`, `list_preview_items`) that calls the same `WireProtocol` helpers. Optional `locale`/`lang` on fields is labels only — keys stay stable.

Bind is server-to-server: [`PluginConnect::silent_bind()`](src/PluginConnect.php) (`createPluginAuthCode` → `completePluginBinding`). SSO to the web app is a separate `issueSessionKey` URL. Greenfield bind returns `migration_mode = legacy` until migration runs.

## Tests and local WP

Smoke tests (no live WPDB unless noted): `bin/test-wp*.php`. Daily docker: `make docker-up` (bind-mounts this repo). WP-Cron in compose is service `wp-cron` (`DISABLE_WP_CRON`); do not rely on pseudo-cron to `wp-pp.l2.vettich.ru`. Upgrade ZIPs: [`docker-upgrade/`](docker-upgrade/) + [`meta/local-dev/checklist-plugin-upgrade.md`](../meta/local-dev/checklist-plugin-upgrade.md) — not the daily bind-mount stack. Pipeline e2e notes: [`meta/local-dev/wordpress-pipeline-testing.md`](../meta/local-dev/wordpress-pipeline-testing.md).
