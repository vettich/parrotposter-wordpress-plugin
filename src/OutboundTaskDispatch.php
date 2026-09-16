<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Signature verify + per-`type` dispatch for outbound fallback tasks (TASK-002-WP-09,
 * SPEC-002-03). Hooks the `parrotposter_outbound_task_dispatch` filter WP-08 built specifically
 * as this task's extension seam ({@see OutboundTaskWorker}'s `dispatch_task()` doc comment) —
 * kept as a separate class/file rather than folded into `OutboundTaskWorker` so WP-09 never
 * needs to touch that file's lease/report/time-budget machinery, only this filter callback.
 *
 * Routing reuses the exact handlers the *primary* PP→site paths already use — no parallel
 * implementation (task's explicit requirement):
 *  - `fetch_next`  -> {@see WireProtocol::handle_items_next_from_body()} (WP-05's
 *    `SelectionFilterQuery` + `ExcludeCache` query builder, fed from `payload` instead of an
 *    HTTP request body)
 *  - `fetch_batch` -> {@see WireProtocol::handle_items_batch_from_body()} (WP-07's pagination
 *    path, same treatment)
 *  - `rotate_secrets` -> applies the new `pp_to_site` secret + signing public key directly
 *    (SPEC-002-01 §6 step 2/3) and reports back with `confirm.rotationId` in the *same* report
 *    call ({@see OutboundTaskWorker::send_report()}) instead of a separate primary
 *    confirmation round-trip. Note: unlike `fetch_next`/`fetch_batch`, there is no pre-existing
 *    "primary rotate-secrets handler" in this codebase to reuse — confirmed by grep and by
 *    back-app's own `rotation.rs` doc comment ("no primary-transport rotation-confirm path
 *    exists yet ... rotate_secrets has no primary wire call"). This *is* the only
 *    implementation, not a second one duplicating an existing primary path — see WP-09's report
 *    for the full reasoning.
 *  - `ping` -> no-op acknowledgment. `last_seen_at`-equivalent bookkeeping already happens on
 *    PP's side for every authenticated `site_to_pp` call (SPEC-002-01 §2: "last_seen_at:
 *    последний контакт с сайта или успешный ping") — the lease + report round trip that invokes
 *    this dispatcher *is* such a call, so no additional plugin-side side effect is needed.
 *
 * Expiry ordering: `expires_at` staleness is already gated **before** this filter ever fires, by
 * {@see OutboundTaskWorker::resolve_pending_outcome()} (WP-08) — not re-checked here. That
 * satisfies the task's "expired -> report:expired before verify" requirement without
 * duplicating the check (WP-08's `is_expired()` is reused as-is, per the task's own note that
 * this "may already partially exist from WP-08").
 */
class OutboundTaskDispatch
{
	public static function init(): void
	{
		add_filter('parrotposter_outbound_task_dispatch', [self::class, 'dispatch'], 10, 2);
	}

	/**
	 * @param array<string, mixed>|null $outcome ignored — this is the only expected hook on this
	 *                                            filter (WP-08's contract allows multiple, first
	 *                                            non-null wins, but WP-09 owns the whole seam)
	 * @param array<string, mixed>      $row     raw `OutboundTaskQueue` row (task_type, payload
	 *                                           as JSON text, payload_signature, ...)
	 * @return array{status: string, result: mixed, error_code: ?string, confirm?: array{rotation_id: string}}
	 */
	public static function dispatch($outcome, array $row): array
	{
		$task_type = (string) ($row['task_type'] ?? '');
		$payload = self::decode_payload($row['payload'] ?? null);
		$signature_b64 = (string) ($row['payload_signature'] ?? '');

		$verify = self::verify_signature($payload, $signature_b64);
		if (!$verify['ok']) {
			return [
				'status' => OutboundTaskQueue::STATUS_FAILED,
				'result' => null,
				'error_code' => 'signature_invalid',
			];
		}
		if ($verify['used'] === 'current') {
			// D4 (DEC-002-06): the previous key is retained only until the *first* successful
			// verify with the current key — this is that moment, for any task type.
			Settings::clear_outbound_task_signing_public_key_prev();
		}

		switch ($task_type) {
			case 'ping':
				return self::dispatch_ping();
			case 'rotate_secrets':
				return self::dispatch_rotate_secrets($payload);
			case 'fetch_next':
				return self::dispatch_fetch_next($payload);
			case 'fetch_batch':
				return self::dispatch_fetch_batch($payload);
			default:
				return [
					'status' => OutboundTaskQueue::STATUS_SKIPPED,
					'result' => null,
					'error_code' => 'unknown_task_type',
				];
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{ok: bool, used: 'current'|'prev'|null}
	 */
	private static function verify_signature(array $payload, string $signature_b64): array
	{
		$current = Settings::outbound_task_signing_public_key();
		$prev = Settings::outbound_task_signing_public_key_prev();

		return OutboundTaskSignature::verify_with_key_retention(
			$current,
			$prev,
			static function (string $key) use ($payload, $signature_b64): bool {
				return OutboundTaskSignature::verify($payload, $signature_b64, $key);
			}
		);
	}

	/**
	 * @return array{status: string, result: mixed, error_code: ?string}
	 */
	private static function dispatch_ping(): array
	{
		return [
			'status' => OutboundTaskQueue::STATUS_DONE,
			'result' => null,
			'error_code' => null,
		];
	}

	/**
	 * Applies SPEC-002-01 §6 step 2 (delivery) locally, then confirms in the same report call —
	 * step 3 (commit) happens on PP's side when it receives `confirm.rotationId`
	 * ({@see OutboundTaskWorker::send_report()}), which mints a new `site_to_pp` and returns it
	 * as `newSiteToPpSecret` (captured there, not here).
	 *
	 * @param array<string, mixed> $payload {rotation_id, new_pp_to_site_secret,
	 *                                       new_signing_public_key_b64} — field names match
	 *                                       back-app's `rotation::RotationStart` (snake_case,
	 *                                       confirmed via `dto.rs`'s `rotation_id` payload
	 *                                       extraction; no enqueue call exists yet in back-app to
	 *                                       cross-check the other two field names against, so
	 *                                       this is WP-09's best-effort call — flagged in the
	 *                                       report).
	 * @return array{status: string, result: mixed, error_code: ?string, confirm?: array{rotation_id: string}}
	 */
	private static function dispatch_rotate_secrets(array $payload): array
	{
		$rotation_id = isset($payload['rotation_id']) ? (string) $payload['rotation_id'] : '';
		$new_pp_to_site = isset($payload['new_pp_to_site_secret']) ? (string) $payload['new_pp_to_site_secret'] : '';
		$new_signing_key = isset($payload['new_signing_public_key_b64']) ? (string) $payload['new_signing_public_key_b64'] : '';

		if ($rotation_id === '' || $new_pp_to_site === '' || $new_signing_key === '') {
			return [
				'status' => OutboundTaskQueue::STATUS_FAILED,
				'result' => null,
				'error_code' => 'invalid_rotate_secrets_payload',
			];
		}

		Settings::rotate_pp_to_site_secret($new_pp_to_site);
		Settings::set_outbound_task_signing_public_key($new_signing_key);

		return [
			'status' => OutboundTaskQueue::STATUS_DONE,
			'result' => null,
			'error_code' => null,
			'confirm' => ['rotation_id' => $rotation_id],
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{status: string, result: mixed, error_code: ?string}
	 */
	private static function dispatch_fetch_next(array $payload): array
	{
		$result = WireProtocol::handle_items_next_from_body($payload);

		return self::result_to_outcome($result);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{status: string, result: mixed, error_code: ?string}
	 */
	private static function dispatch_fetch_batch(array $payload): array
	{
		$result = WireProtocol::handle_items_batch_from_body($payload);

		return self::result_to_outcome($result);
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array{status: string, result: mixed, error_code: ?string}
	 */
	private static function result_to_outcome($result): array
	{
		if (is_wp_error($result)) {
			return [
				'status' => OutboundTaskQueue::STATUS_FAILED,
				'result' => null,
				'error_code' => (string) $result->get_error_code(),
			];
		}

		return [
			'status' => OutboundTaskQueue::STATUS_DONE,
			'result' => $result,
			'error_code' => null,
		];
	}

	/**
	 * @param mixed $raw
	 * @return array<string, mixed>
	 */
	private static function decode_payload($raw): array
	{
		if (is_array($raw)) {
			return $raw;
		}
		if (!is_string($raw) || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}
}
