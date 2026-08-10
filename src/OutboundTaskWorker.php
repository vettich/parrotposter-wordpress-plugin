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
 *
 * Adaptive lease interval (TASK-002-WP-10): {@see OutboundPollScheduler} decides how often the
 * `lease` GraphQL call actually goes out, based on the last response's `primaryHealth` /
 * `recommendedPollIntervalS`, the {@see Settings::last_primary_call_at()} local heuristic, and
 * exponential backoff+jitter — see that class's doc comment for the full priority order. This
 * class only calls {@see OutboundPollScheduler::is_lease_due()} / `record_lease_outcome()`; it
 * doesn't own any of the interval decision logic itself.
 */
class OutboundTaskWorker
{
	public const CRON_HOOK = 'parrotposter_outbound_lease_tick';

	private const CRON_SCHEDULE = 'parrotposter_outbound_base_interval';

	/** Mirrors LocalQueue::HTTP_PROCESS_MAX_ITEMS / HTTP_PROCESS_TIME_BUDGET_SEC (same pattern). */
	private const MAX_ITEMS = 10;

	private const TIME_BUDGET_SEC = 5;

	/** SPEC-002-09 §6.4: lease `limit` 1-5, server clamps; ask for the max allowed per tick. */
	private const LEASE_LIMIT = 5;

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_cron_schedule(array $schedules): array
	{
		// TASK-002-WP-10: the recurring WP-Cron schedule itself now ticks at the finest
		// granularity the adaptive interval could ever need (OutboundPollScheduler::MIN_INTERVAL_SEC)
		// — WP-Cron's built-in recurring schedules can't vary their interval per tick, so the
		// actual `lease` network call frequency is throttled inside run_cron_tick() via
		// OutboundPollScheduler::is_lease_due() instead. The cron hook itself still fires every
		// tick to drain/report already-claimed local rows regardless of lease throttling.
		if (!isset($schedules[self::CRON_SCHEDULE])) {
			$interval = OutboundPollScheduler::MIN_INTERVAL_SEC;
			$schedules[self::CRON_SCHEDULE] = [
				'interval' => $interval,
				'display' => 'Every ' . (int) ($interval / 60) . ' minutes (ParrotPoster outbound lease)',
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

		// TASK-002-WP-10: the cron hook fires every tick (MIN_INTERVAL_SEC granularity), but the
		// actual `lease` network call is throttled to the adaptively-computed interval — draining
		// already-claimed local rows below still runs every tick regardless.
		if (OutboundPollScheduler::is_lease_due()) {
			self::lease_and_store();
		}

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
			// No server signal to go on this round — fall back to the standard backoff/local-
			// heuristic path (WP-10) rather than leaving next_due_at stale, so a persistent lease
			// failure still widens the retry interval instead of retrying every single cron tick.
			OutboundPollScheduler::record_lease_outcome('UNKNOWN', null, false);

			return;
		}

		$payload = $res['data']['pluginOutboundTasksLease'] ?? null;
		$tasks = is_array($payload) && isset($payload['tasks']) && is_array($payload['tasks'])
			? $payload['tasks']
			: [];
		$primary_health = is_array($payload) && isset($payload['primaryHealth'])
			? (string) $payload['primaryHealth']
			: 'UNKNOWN';
		$recommended_poll_interval_s = is_array($payload) && isset($payload['recommendedPollIntervalS']) && $payload['recommendedPollIntervalS'] !== null
			? (int) $payload['recommendedPollIntervalS']
			: null;

		OutboundPollScheduler::record_lease_outcome($primary_health, $recommended_poll_interval_s, $tasks !== []);

		if ($tasks !== []) {
			OutboundTaskQueue::store_leased_tasks($tasks);
		}
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
	 * @return array{status: string, result: mixed, error_code: ?string, dispatched: bool, confirm: ?array{rotation_id: string}}
	 */
	public static function resolve_pending_outcome(string $expires_at_iso, int $now_ts, callable $dispatch_fn): array
	{
		if (self::is_expired($expires_at_iso, $now_ts)) {
			return [
				'status' => OutboundTaskQueue::STATUS_EXPIRED,
				'result' => null,
				'error_code' => null,
				'dispatched' => false,
				'confirm' => null,
			];
		}

		$outcome = $dispatch_fn();

		return [
			'status' => is_array($outcome) && isset($outcome['status']) ? (string) $outcome['status'] : OutboundTaskQueue::STATUS_SKIPPED,
			'result' => is_array($outcome) ? ($outcome['result'] ?? null) : null,
			'error_code' => is_array($outcome) && isset($outcome['error_code']) ? (string) $outcome['error_code'] : null,
			'dispatched' => true,
			// WP-09: `rotate_secrets` dispatch hands back `confirm.rotation_id` so it can ride
			// the same report call instead of a separate primary confirmation round-trip
			// (SPEC-002-09 §6.4 `confirm`). Not persisted to OutboundTaskQueue — a crash between
			// this point and send_report() below means the confirm is lost on resend_report()
			// retry; PP's own `rotation_expires_at` cron rollback (SPEC-002-01 §6 step 5) is the
			// safety net for that narrow window, so it never leaves the old secret unusable.
			'confirm' => is_array($outcome) && isset($outcome['confirm']) && is_array($outcome['confirm'])
				? ['rotation_id' => (string) ($outcome['confirm']['rotation_id'] ?? '')]
				: null,
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
		self::send_report($task_id, $outcome['status'], $outcome['result'], $outcome['error_code'], $outcome['confirm'] ?? null);
	}

	/**
	 * Signature verification and per-`type` dispatch (`fetch_next` / `fetch_batch` /
	 * `rotate_secrets` / `ping`) live behind the `parrotposter_outbound_task_dispatch` filter —
	 * {@see OutboundTaskDispatch} (TASK-002-WP-09) hooks it, so this file never needs to know
	 * about signature verification, `SelectionFilterQuery`, or rotation details. Falls back to
	 * `skipped` if nothing hooks the filter (e.g. in isolated tests of this class alone).
	 *
	 * @param array<string, mixed> $row
	 * @return array{status: string, result: mixed, error_code: ?string, confirm?: array{rotation_id: string}}
	 */
	private static function dispatch_task(array $row): array
	{
		$outcome = apply_filters('parrotposter_outbound_task_dispatch', null, $row);
		if (is_array($outcome) && isset($outcome['status'])) {
			$result = [
				'status' => (string) $outcome['status'],
				'result' => $outcome['result'] ?? null,
				'error_code' => isset($outcome['error_code']) ? (string) $outcome['error_code'] : null,
			];
			if (isset($outcome['confirm']) && is_array($outcome['confirm'])) {
				$result['confirm'] = $outcome['confirm'];
			}

			return $result;
		}

		return [
			'status' => OutboundTaskQueue::STATUS_SKIPPED,
			'result' => null,
			'error_code' => null,
		];
	}

	/**
	 * @param mixed                       $result
	 * @param array{rotation_id: string}|null $confirm SPEC-002-09 §6.4 `confirm` (WP-09):
	 *                                                  `rotate_secrets` dispatch rides its
	 *                                                  confirmation on this same report call
	 *                                                  instead of a separate primary round-trip.
	 */
	private static function send_report(string $task_id, string $local_status, $result, ?string $error_code, ?array $confirm = null): void
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
		if ($confirm !== null && ($confirm['rotation_id'] ?? '') !== '') {
			$variables['confirm'] = ['rotationId' => $confirm['rotation_id']];
		}

		$res = Api::graphql_mutation('pluginOutboundTaskReport', $variables, [
			'curl_timeout' => 8,
			'curl_connect_timeout' => 3,
		]);

		if (!empty($res['error'])) {
			PP::log(['OutboundTaskWorker::report_failed', 'task_id' => $task_id, 'error' => $res['error']]);

			return; // stays unreported locally; retried next tick via claim_next_unreported()
		}

		OutboundTaskQueue::mark_reported($task_id);

		// SPEC-002-01 §6 step 3 (commit): when this report's `confirm` committed a rotation, PP
		// mints a fresh `site_to_pp` and returns it here, one-time plaintext — the only channel
		// the plugin has to learn it (RotateSecretsConfirmInput doc comment, back-app dto.rs).
		if ($confirm !== null) {
			$payload = $res['data']['pluginOutboundTaskReport'] ?? null;
			$new_site_to_pp = is_array($payload) && !empty($payload['newSiteToPpSecret'])
				? (string) $payload['newSiteToPpSecret']
				: '';
			if ($new_site_to_pp !== '') {
				Settings::set_site_to_pp_secret($new_site_to_pp);
				// PP's own commit_rotation() drops its staged pp_to_site candidate at the same
				// atomic step — mirror that locally now that the rotation is confirmed done.
				Settings::clear_pp_to_site_prev_secret();
			}
		}
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
