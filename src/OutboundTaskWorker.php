<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Background lease/report worker (TASK-002-WP-08, SPEC-002-03 §9, SPEC-002-09 §6.4).
 *
 * Direction: PP → site **pull** — opposite of {@see LocalQueue} (site → PP push). Same
 * architectural pattern (WP-Cron tick, time-budgeted claim-then-process batch, no heavy work on
 * a visitor hit) but a separate class/table, per the task's explicit note not to reuse LocalQueue.
 *
 * Cycle per cron tick: `lease` (GraphQL, `site_to_pp` Bearer via {@see Api::graphql_mutation}) →
 * store into {@see OutboundTaskQueue} → time-budgeted claim/execute/report batch. Execution only
 * ever happens here, inside the WP-Cron callback — never inside a request-context handler, since
 * this plugin has no inbound "lease" endpoint to begin with (it only originates lease calls).
 *
 * Dispatch stub: signature verification and per-`type` dispatch (`fetch_next` / `fetch_batch` /
 * `rotate_secrets` / `ping`) is TASK-002-WP-09's job, not this one. {@see dispatch_task()} always
 * reports `skipped` unless the `parrotposter_outbound_task_dispatch` filter is hooked (WP-09's
 * extension seam) — this proves the lease → report round trip end-to-end without executing any
 * real task logic yet.
 */
class OutboundTaskWorker
{
	public const CRON_HOOK = 'parrotposter_outbound_lease_tick';

	private const CRON_SCHEDULE = 'parrotposter_outbound_base_interval';

	/**
	 * Fixed base interval (SPEC-002-03 §10.1). TASK-002-WP-10 makes this adaptive
	 * (`primary_health` / `recommendedPollIntervalS` / backoff+jitter) — until then this single
	 * constant is the whole schedule, deliberately easy to swap out later.
	 */
	private const BASE_INTERVAL_SEC = 600;

	/** Mirrors LocalQueue::HTTP_PROCESS_MAX_ITEMS / HTTP_PROCESS_TIME_BUDGET_SEC (same pattern). */
	private const MAX_ITEMS = 10;

	private const TIME_BUDGET_SEC = 5;

	/** SPEC-002-09 §6.4: lease `limit` 1-5, server clamps; ask for the max allowed per tick. */
	private const LEASE_LIMIT = 5;

	public static function base_interval_seconds(): int
	{
		return self::BASE_INTERVAL_SEC;
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_cron_schedule(array $schedules): array
	{
		if (!isset($schedules[self::CRON_SCHEDULE])) {
			$schedules[self::CRON_SCHEDULE] = [
				'interval' => self::BASE_INTERVAL_SEC,
				'display' => 'Every ' . (int) (self::BASE_INTERVAL_SEC / 60) . ' minutes (ParrotPoster outbound lease)',
			];
		}

		return $schedules;
	}

	public static function ensure_scheduled(): void
	{
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 90, self::CRON_SCHEDULE, self::CRON_HOOK);
		}
	}

	public static function clear_scheduled(): void
	{
		wp_clear_scheduled_hook(self::CRON_HOOK);
	}

	/**
	 * WP-Cron entry point. Never call this from a request-context handler (SPEC-002-03 §9.2) —
	 * kept as an explicit invariant even though this plugin has no inbound lease HTTP handler to
	 * accidentally block on.
	 */
	public static function run_cron_tick(): void
	{
		if (Settings::site_to_pp_secret() === '') {
			return; // not bound to PP yet
		}

		OutboundTaskQueue::recover_stale_processing();

		$deadline = microtime(true) + self::TIME_BUDGET_SEC;

		self::lease_and_store();

		$processed = self::process_batch(self::MAX_ITEMS, $deadline);

		OutboundTaskQueue::purge_old_reported();

		PP::log([
			'OutboundTaskWorker::run_cron_tick',
			'processed' => $processed,
			'elapsed_sec' => round(microtime(true) - ($deadline - self::TIME_BUDGET_SEC), 3),
		]);
	}

	private static function lease_and_store(): void
	{
		$res = Api::graphql_mutation('pluginOutboundTasksLease', [
			'limit' => self::LEASE_LIMIT,
			'clientInstanceId' => self::client_instance_id(),
		], [
			'curl_timeout' => 8,
			'curl_connect_timeout' => 3,
		]);

		if (!empty($res['error'])) {
			PP::log(['OutboundTaskWorker::lease_failed', 'error' => $res['error']]);

			return;
		}

		$payload = $res['data']['pluginOutboundTasksLease'] ?? null;
		if (!is_array($payload) || empty($payload['tasks']) || !is_array($payload['tasks'])) {
			return;
		}

		OutboundTaskQueue::store_leased_tasks($payload['tasks']);
	}

	private static function client_instance_id(): string
	{
		return 'wp-cron-' . substr(md5(site_url()), 0, 12);
	}

	private static function process_batch(int $max_items, float $deadline): int
	{
		return self::run_batch_with_time_budget(
			static function (): ?array {
				// Prefer flushing locally-terminal-but-unreported rows first (crash between
				// local execution and report — retry the report only, never re-execute).
				$row = OutboundTaskQueue::claim_next_unreported();
				if ($row !== null) {
					return $row;
				}

				return OutboundTaskQueue::claim_next_pending();
			},
			[self::class, 'process_one_row'],
			$max_items,
			$deadline,
			static function (): float {
				return microtime(true);
			}
		);
	}

	/**
	 * Pure claim → process loop bounded by item count and a deadline. No WPDB / WP coupling —
	 * `$claim_next` / `$process_one` / `$now_fn` are injected callables, which is what makes this
	 * unit-testable without a live WP DB (see bin/test-wp08-outbound-worker.php).
	 *
	 * @param callable $claim_next  (): array<string, mixed>|null
	 * @param callable $process_one (array<string, mixed> $row): void
	 * @param callable $now_fn      (): float monotonic-ish clock, compared against $deadline
	 */
	public static function run_batch_with_time_budget(
		callable $claim_next,
		callable $process_one,
		int $max_items,
		float $deadline,
		callable $now_fn
	): int {
		$processed = 0;
		while ($processed < $max_items) {
			if ($now_fn() >= $deadline) {
				break;
			}
			$row = $claim_next();
			if ($row === null) {
				break;
			}
			$process_one($row);
			++$processed;
		}

		return $processed;
	}

	/**
	 * Idempotency decision (WP-08 checklist "Устойчивость"): a `task_id` already terminal
	 * locally must never be executed again. If it hasn't been reported yet (report call failed
	 * or the process crashed before reaching it), only the report is retried.
	 *
	 * Pure — no WPDB access — directly unit-tested.
	 */
	public static function decide_action(string $local_status, bool $already_reported): string
	{
		if (OutboundTaskQueue::is_terminal_status($local_status)) {
			return $already_reported ? 'skip' : 'resend_report';
		}

		return 'execute';
	}

	/**
	 * `expires_at` staleness check (SPEC-002-03 §8): compared against CMS clock **before**
	 * execution. Pure — `$now_ts` is injectable for deterministic tests.
	 */
	public static function is_expired(string $expires_at, ?int $now_ts = null): bool
	{
		$expires_at = trim($expires_at);
		if ($expires_at === '') {
			// No expiry info: fail-open to "attempt execution" rather than fail-closed to
			// expired — an empty/unparseable expires_at should not silently drop real work.
			return false;
		}
		$ts = strtotime($expires_at);
		if ($ts === false) {
			return false;
		}
		$now_ts = $now_ts ?? time();

		return $ts <= $now_ts;
	}

	/**
	 * Decide the terminal outcome for a freshly claimed pending task: expired short-circuit
	 * (no dispatch call at all — SPEC-002-03 §8 "без побочных эффектов") or the dispatch result.
	 * Pure aside from invoking the injected `$dispatch_fn` — directly unit-tested with a fake
	 * dispatcher standing in for {@see dispatch_task()}.
	 *
	 * @return array{status: string, result: mixed, error_code: ?string, dispatched: bool}
	 */
	public static function resolve_pending_outcome(string $expires_at_iso, int $now_ts, callable $dispatch_fn): array
	{
		if (self::is_expired($expires_at_iso, $now_ts)) {
			return [
				'status' => OutboundTaskQueue::STATUS_EXPIRED,
				'result' => null,
				'error_code' => null,
				'dispatched' => false,
			];
		}

		$outcome = $dispatch_fn();

		return [
			'status' => is_array($outcome) && isset($outcome['status']) ? (string) $outcome['status'] : OutboundTaskQueue::STATUS_SKIPPED,
			'result' => is_array($outcome) ? ($outcome['result'] ?? null) : null,
			'error_code' => is_array($outcome) && isset($outcome['error_code']) ? (string) $outcome['error_code'] : null,
			'dispatched' => true,
		];
	}

	/**
	 * @param array<string, mixed> $row OutboundTaskQueue row (either freshly claimed pending, or
	 *                                  a terminal-but-unreported row)
	 */
	public static function process_one_row(array $row): void
	{
		$task_id = (string) ($row['task_id'] ?? '');
		if ($task_id === '') {
			return;
		}

		$local_status = (string) ($row['status'] ?? '');
		$already_reported = (int) ($row['reported'] ?? 0) === 1;
		$action = self::decide_action($local_status, $already_reported);

		if ($action === 'skip') {
			return;
		}

		if ($action === 'resend_report') {
			self::send_report(
				$task_id,
				$local_status,
				self::decode_result($row['result'] ?? null),
				self::nullable_string($row['error_code'] ?? null)
			);

			return;
		}

		// $action === 'execute' — row came from claim_next_pending(), already locked to 'processing'.
		$outcome = self::resolve_pending_outcome(
			(string) ($row['expires_at'] ?? ''),
			time(),
			static function () use ($row): array {
				return self::dispatch_task($row);
			}
		);

		OutboundTaskQueue::mark_terminal($task_id, $outcome['status'], $outcome['result'], $outcome['error_code']);
		self::send_report($task_id, $outcome['status'], $outcome['result'], $outcome['error_code']);
	}

	/**
	 * WP-09 TODO: verify `payloadSignature` (Ed25519 vs `outbound_task_signing_public_key`) and
	 * dispatch by `type` (`fetch_next` / `fetch_batch` / `rotate_secrets` / `ping`) onto the
	 * existing PP→site op handlers. Left as a stub here so WP-08 can prove the lease → report
	 * round trip end-to-end: every task is reported back untouched as `skipped`, unless a later
	 * change hooks the `parrotposter_outbound_task_dispatch` filter (WP-09's extension seam) —
	 * that keeps WP-09 from needing to touch this file or OutboundTaskQueue at all.
	 *
	 * @param array<string, mixed> $row
	 * @return array{status: string, result: mixed, error_code: ?string}
	 */
	private static function dispatch_task(array $row): array
	{
		$outcome = apply_filters('parrotposter_outbound_task_dispatch', null, $row);
		if (is_array($outcome) && isset($outcome['status'])) {
			return [
				'status' => (string) $outcome['status'],
				'result' => $outcome['result'] ?? null,
				'error_code' => isset($outcome['error_code']) ? (string) $outcome['error_code'] : null,
			];
		}

		return [
			'status' => OutboundTaskQueue::STATUS_SKIPPED,
			'result' => null,
			'error_code' => null,
		];
	}

	/**
	 * @param mixed $result
	 */
	private static function send_report(string $task_id, string $local_status, $result, ?string $error_code): void
	{
		$gql_status = self::to_report_status($local_status);
		if ($gql_status === null) {
			return;
		}

		$variables = [
			'taskId' => $task_id,
			'status' => $gql_status,
		];
		if ($result !== null) {
			$variables['result'] = $result;
		}
		if ($error_code !== null && $error_code !== '') {
			$variables['errorCode'] = $error_code;
		}
		// `confirm` (RotateSecretsConfirmInput) is intentionally never sent from here —
		// rotate_secrets dispatch + confirm is WP-09's job (see dispatch_task() above).

		$res = Api::graphql_mutation('pluginOutboundTaskReport', $variables, [
			'curl_timeout' => 8,
			'curl_connect_timeout' => 3,
		]);

		if (!empty($res['error'])) {
			PP::log(['OutboundTaskWorker::report_failed', 'task_id' => $task_id, 'error' => $res['error']]);

			return; // stays unreported locally; retried next tick via claim_next_unreported()
		}

		OutboundTaskQueue::mark_reported($task_id);
	}

	private static function to_report_status(string $local_status): ?string
	{
		switch ($local_status) {
			case OutboundTaskQueue::STATUS_DONE:
				return 'DONE';
			case OutboundTaskQueue::STATUS_FAILED:
				return 'FAILED';
			case OutboundTaskQueue::STATUS_EXPIRED:
				return 'EXPIRED';
			case OutboundTaskQueue::STATUS_SKIPPED:
				return 'SKIPPED';
			default:
				return null;
		}
	}

	/**
	 * @param mixed $raw
	 * @return mixed
	 */
	private static function decode_result($raw)
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		if (is_array($raw)) {
			return $raw;
		}
		$decoded = json_decode((string) $raw, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @param mixed $value
	 */
	private static function nullable_string($value): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}

		return (string) $value;
	}
}
