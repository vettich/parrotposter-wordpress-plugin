<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Adaptive interval for {@see OutboundTaskWorker}'s `pluginOutboundTasksLease` cron tick
 * (TASK-002-WP-10, SPEC-002-03 §10, DEC-002-06 D6/D7). Replaces WP-08's fixed
 * `OutboundTaskWorker::BASE_INTERVAL_SEC` — that constant's own doc comment named this task as
 * "the single swappable point WP-10 will replace".
 *
 * Scope is `auto` only (DEC-002-06 D7) — no manual `push_only`/`poll_only`/`polling_reduced`
 * mode toggle, only the interval itself adapts.
 *
 * Priority order for the next lease interval (SPEC-002-03 §10.4, minus the manual-mode level
 * D7 drops):
 *   1. Server signal from the *last* lease response (`primary_health` / `recommended_poll_interval_s`,
 *      TASK-002-BE-50) — always wins on conflict with the local heuristic or backoff state.
 *   2. Local heuristic: {@see Settings::last_primary_call_at()} — a recent direct primary call
 *      from PP (any wire route, not just lease) means lease urgency is low ("watchdog" mode).
 *   3. Standard exponential backoff with ceiling, when neither of the above applies.
 * Jitter (±10-20%) is applied to whichever value item 1-3 produced, every time.
 *
 * Split into a pure decision core (unit-tested in bin/test-wp10-adaptive-polling.php, no WPDB —
 * mirrors the split in back-app's `adaptive_poll.rs`, this task's server-side counterpart) and a
 * thin WP-options-backed orchestration layer ({@see record_lease_outcome()}, {@see is_lease_due()}).
 *
 * Cron mechanics: WP-Cron's recurring `cron_schedules` entries can't vary their interval per
 * tick, so {@see OutboundTaskWorker}'s recurring schedule now ticks at the finest granularity
 * the adaptive interval could ever need ({@see MIN_INTERVAL_SEC}) and this class throttles the
 * actual `pluginOutboundTasksLease` network call to the adaptively-computed interval via a
 * persisted `next_due_at` — {@see OutboundTaskWorker::run_cron_tick()} still fires every tick to
 * drain/report already-claimed local rows (unaffected by lease throttling), it just skips the
 * lease call itself when not yet due.
 */
class OutboundPollScheduler
{
	/**
	 * Fast/recovery mode floor (`degraded` server signal, or backoff with no empty streak).
	 * Matches back-app's `MIN_POLL_INTERVAL` (adaptive_poll.rs) so both sides agree on what
	 * "as fast as possible" means.
	 */
	public const MIN_INTERVAL_SEC = 300; // 5 min

	/**
	 * SPEC-002-03 §10.1 default base — used as the `ok`-health fallback when the server didn't
	 * send `recommended_poll_interval_s`. Matches OutboundTaskWorker's former fixed
	 * `BASE_INTERVAL_SEC`, kept as the same value for continuity.
	 */
	public const BASE_INTERVAL_SEC = 600; // 10 min

	/** SPEC-002-03 §10.1 backoff ceiling — also the watchdog-mode interval (checklist item 1). */
	public const MAX_INTERVAL_SEC = 3600; // 60 min

	/**
	 * "T_healthy" (task checklist): a direct primary call from PP more recent than this means PP
	 * is clearly reaching the site directly right now, so aggressive lease polling isn't as
	 * urgent — fall back to the backoff ceiling instead of waiting on/needing a server signal.
	 */
	public const T_HEALTHY_SEC = 7200; // 2 hours

	/**
	 * Grace period after a lease returns at least one task: the interval stays at
	 * {@see MIN_INTERVAL_SEC} and the empty-response streak stays frozen at 0 — even across
	 * subsequent empty responses — until this elapses, before backoff growth resumes.
	 *
	 * Not numerically specified by the task/spec ("your call... document it"). Own decision:
	 * 30 minutes, i.e. up to 6 consecutive ticks at the 5-minute floor. Reasoning: publishing
	 * tends to be bursty (a batch of items lands close together), so a single quiet lease right
	 * after a burst shouldn't immediately start ramping the interval back up — but 30 minutes is
	 * still short enough that a plugin that's genuinely gone idle reaches the backoff ceiling
	 * well within {@see T_HEALTHY_SEC}, so watchdog mode remains the dominant long-idle behavior.
	 */
	public const EMPTY_GRACE_SEC = 1800; // 30 min

	private const STATE_KEY = 'parrotposter_outbound_poll_state';

	// ---- Pure decision core (no WPDB access — directly unit-tested) ----

	/**
	 * Local heuristic (checklist item 1): true when PP authenticated a primary call to this site
	 * more recently than {@see T_HEALTHY_SEC} ago.
	 */
	public static function is_watchdog_active(?string $last_primary_call_at_utc, int $now_ts): bool
	{
		if ($last_primary_call_at_utc === null || $last_primary_call_at_utc === '') {
			return false;
		}
		$ts = strtotime($last_primary_call_at_utc . ' UTC');
		if ($ts === false) {
			return false;
		}

		return ($now_ts - $ts) < self::T_HEALTHY_SEC;
	}

	/**
	 * Standard exponential backoff (checklist item 3): doubles per consecutive empty lease
	 * response, floored at {@see MIN_INTERVAL_SEC} and clamped to {@see MAX_INTERVAL_SEC} —
	 * monotonically non-decreasing in `$consecutive_empty` and never exceeds the ceiling.
	 */
	public static function compute_backoff_interval_sec(int $consecutive_empty): int
	{
		if ($consecutive_empty <= 0) {
			return self::MIN_INTERVAL_SEC;
		}
		$factor = 2 ** min($consecutive_empty, 8); // cap the exponent, int overflow guard
		$sec = self::MIN_INTERVAL_SEC * $factor;

		return min($sec, self::MAX_INTERVAL_SEC);
	}

	/**
	 * Combines all three priority levels (§10.4) into the base interval (pre-jitter) for the
	 * next lease call.
	 *
	 * @param string $primary_health `OK`|`DEGRADED`|`UNKNOWN` — the GraphQL enum wire shape
	 *                                (`PluginPrimaryHealth!`) from the last lease response, as-is
	 *                                (uppercase), not the back-app-internal lowercase form.
	 */
	public static function compute_base_interval_sec(
		string $primary_health,
		?int $recommended_poll_interval_s,
		bool $watchdog_active,
		int $consecutive_empty
	): int {
		if ($primary_health === 'DEGRADED') {
			// Server signal always wins on conflict with the local heuristic/backoff — even if
			// the queue was empty this lease and even if local backoff had already grown.
			return self::MIN_INTERVAL_SEC;
		}
		if ($primary_health === 'OK') {
			$recommended = $recommended_poll_interval_s ?? self::BASE_INTERVAL_SEC;

			return self::clamp($recommended, self::MIN_INTERVAL_SEC, self::MAX_INTERVAL_SEC);
		}

		// UNKNOWN: "standard backoff, no correction from the server signal" (checklist item 2) —
		// but the local heuristic (priority level 2) still runs ahead of standard backoff
		// (priority level 3): a recent direct primary call still short-circuits to watchdog mode.
		if ($watchdog_active) {
			return self::MAX_INTERVAL_SEC;
		}

		return self::compute_backoff_interval_sec($consecutive_empty);
	}

	/**
	 * ±10-20% jitter applied to every computed interval, regardless of which priority level
	 * produced it. Result stays within [MIN_INTERVAL_SEC, MAX_INTERVAL_SEC].
	 *
	 * @param callable|null $rand_fn (int $min, int $max): int — injectable for deterministic
	 *                                tests; defaults to `random_int()`.
	 */
	public static function apply_jitter(int $interval_sec, ?callable $rand_fn = null): int
	{
		$rand_fn = $rand_fn ?? static function (int $min, int $max): int {
			return random_int($min, $max);
		};

		$magnitude_pct = $rand_fn(10, 20);
		$delta = (int) round($interval_sec * $magnitude_pct / 100);
		$sign = $rand_fn(0, 1) === 1 ? 1 : -1;
		$jittered = $interval_sec + ($sign * $delta);

		return self::clamp($jittered, self::MIN_INTERVAL_SEC, self::MAX_INTERVAL_SEC);
	}

	private static function clamp(int $value, int $min, int $max): int
	{
		if ($value < $min) {
			return $min;
		}
		if ($value > $max) {
			return $max;
		}

		return $value;
	}

	// ---- Orchestration (WP options-backed state) ----

	/**
	 * @return array{consecutive_empty: int, grace_until: int, next_due_at: int, last_lease_at: int, last_lease_error: string}
	 */
	private static function load_state(): array
	{
		$raw = get_option(self::STATE_KEY, []);
		if (!is_array($raw)) {
			$raw = [];
		}

		return [
			'consecutive_empty' => max(0, (int) ($raw['consecutive_empty'] ?? 0)),
			'grace_until' => max(0, (int) ($raw['grace_until'] ?? 0)),
			'next_due_at' => max(0, (int) ($raw['next_due_at'] ?? 0)),
			'last_lease_at' => max(0, (int) ($raw['last_lease_at'] ?? 0)),
			'last_lease_error' => is_string($raw['last_lease_error'] ?? null)
				? (string) $raw['last_lease_error']
				: '',
		];
	}

	/**
	 * @param array<string, int|string> $state
	 */
	private static function save_state(array $state): void
	{
		$merged = array_merge(self::load_state(), $state);
		update_option(self::STATE_KEY, $merged, false);
	}

	public static function last_lease_at(): ?int
	{
		$ts = self::load_state()['last_lease_at'];

		return $ts > 0 ? $ts : null;
	}

	public static function last_lease_error(): ?string
	{
		$error = self::load_state()['last_lease_error'];

		return $error !== '' ? $error : null;
	}

	/** Unix timestamp of a successful or failed lease attempt (diagnostics). */
	public static function record_lease_contact(bool $ok, ?string $error = null, ?int $now_ts = null): void
	{
		$now_ts = $now_ts ?? time();
		if ($ok) {
			self::save_state([
				'last_lease_at' => $now_ts,
				'last_lease_error' => '',
			]);

			return;
		}
		self::save_state([
			'last_lease_error' => $error !== null && $error !== '' ? $error : 'lease_failed',
		]);
	}

	/** True when no successful lease happened within `MAX_INTERVAL_SEC * 2`. */
	public static function is_lease_stale(?int $now_ts = null): bool
	{
		$now_ts = $now_ts ?? time();
		$last = self::last_lease_at();
		if ($last === null) {
			return true;
		}

		return ($now_ts - $last) > (self::MAX_INTERVAL_SEC * 2);
	}

	public static function should_show_poll_banner(?int $now_ts = null): bool
	{
		$wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
		$stale = self::is_lease_stale($now_ts);
		if (!$stale) {
			return false;
		}
		if (self::last_lease_at() === null && self::last_lease_error() === null && !$wp_cron_disabled) {
			return false;
		}

		return true;
	}

	/**
	 * Whether it's time for {@see OutboundTaskWorker} to actually issue a `lease` GraphQL call
	 * on this cron tick (the cron hook itself still fires every tick, at {@see MIN_INTERVAL_SEC}
	 * granularity, to drain/report already-claimed local rows regardless).
	 */
	public static function is_lease_due(?int $now_ts = null): bool
	{
		$now_ts = $now_ts ?? time();

		return $now_ts >= self::load_state()['next_due_at'];
	}

	/**
	 * Update backoff bookkeeping for the lease response just received (or a failed lease
	 * attempt — see {@see OutboundTaskWorker::lease_and_store()}, which passes `UNKNOWN`/`null`/
	 * `false` on transport error so persistent failures still widen the retry interval instead
	 * of retrying every single cron tick), then compute + persist the next due time.
	 *
	 * @return int the computed (post-jitter) interval in seconds, mainly for logging/tests
	 */
	public static function record_lease_outcome(
		string $primary_health,
		?int $recommended_poll_interval_s,
		bool $had_tasks,
		?int $now_ts = null
	): int {
		$now_ts = $now_ts ?? time();
		$state = self::load_state();

		if ($had_tasks) {
			$consecutive_empty = 0;
			$grace_until = $now_ts + self::EMPTY_GRACE_SEC;
		} elseif ($now_ts < $state['grace_until']) {
			// Still within the post-task grace window — stay frozen, don't grow yet.
			$consecutive_empty = 0;
			$grace_until = $state['grace_until'];
		} else {
			$consecutive_empty = $state['consecutive_empty'] + 1;
			$grace_until = $state['grace_until'];
		}

		$watchdog_active = self::is_watchdog_active(Settings::last_primary_call_at(), $now_ts);
		$base = self::compute_base_interval_sec(
			$primary_health,
			$recommended_poll_interval_s,
			$watchdog_active,
			$consecutive_empty
		);
		$interval = self::apply_jitter($base);

		self::save_state([
			'consecutive_empty' => $consecutive_empty,
			'grace_until' => $grace_until,
			'next_due_at' => $now_ts + $interval,
		]);

		return $interval;
	}
}
