#!/usr/bin/env php
<?php

/**
 * Smoke tests for WP-10: adaptive polling interval — server signal (primary_health /
 * recommended_poll_interval_s) vs local heuristic (last_primary_call_at watchdog) vs standard
 * backoff+jitter (SPEC-002-03 §10, DEC-002-06 D6/D7). No live WP DB — same pattern as
 * bin/test-wp04-contract.php / bin/test-wp09-signature-dispatch.php (in-memory option stubs).
 *
 * Usage: php bin/test-wp10-adaptive-polling.php
 */

define('ABSPATH', true);

/** @var array<string, mixed> */
$GLOBALS['pp_test_options'] = [];

if (!function_exists('get_option')) {
	function get_option($key, $default = false)
	{
		return array_key_exists($key, $GLOBALS['pp_test_options'])
			? $GLOBALS['pp_test_options'][$key]
			: $default;
	}
}

if (!function_exists('update_option')) {
	function update_option($key, $value, $autoload = true)
	{
		$GLOBALS['pp_test_options'][$key] = $value;

		return true;
	}
}

if (!function_exists('delete_option')) {
	function delete_option($key)
	{
		unset($GLOBALS['pp_test_options'][$key]);

		return true;
	}
}

require_once dirname(__DIR__) . '/src/Settings.php';
require_once dirname(__DIR__) . '/src/OutboundPollScheduler.php';

use parrotposter\OutboundPollScheduler as Scheduler;
use parrotposter\Settings;

function assert_true(string $name, bool $condition): void
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$name}\n");
		exit(1);
	}
	echo "OK: {$name}\n";
}

function assert_eq(string $name, $expected, $actual): void
{
	if ($expected !== $actual) {
		fwrite(STDERR, "FAIL: {$name}\n");
		fwrite(STDERR, '  expected: ' . var_export($expected, true) . "\n");
		fwrite(STDERR, '  actual:   ' . var_export($actual, true) . "\n");
		exit(1);
	}
	echo "OK: {$name}\n";
}

function reset_options(): void
{
	$GLOBALS['pp_test_options'] = [];
}

// =====================================================================================
// 1. Priority order (§10.4): server signal overrides local heuristic on conflict.
//    This is the task's own explicitly-required test.
// =====================================================================================

// DEGRADED wins over a watchdog-active local heuristic (which alone would recommend the
// backoff ceiling) -- server signal always wins on conflict.
assert_eq(
	'DEGRADED overrides watchdog-active local heuristic -> fast mode (MIN), not the ceiling',
	Scheduler::MIN_INTERVAL_SEC,
	Scheduler::compute_base_interval_sec('DEGRADED', null, true, 0)
);

// OK + recommended_poll_interval_s also wins over a watchdog-active local heuristic.
assert_eq(
	'OK + recommended_poll_interval_s overrides watchdog-active local heuristic',
	900,
	Scheduler::compute_base_interval_sec('OK', 900, true, 0)
);

// UNKNOWN has no server correction (checklist item 2), so *now* the local heuristic is free to
// apply: watchdog-active -> ceiling.
assert_eq(
	'UNKNOWN + watchdog active -> local heuristic applies (ceiling), no server correction',
	Scheduler::MAX_INTERVAL_SEC,
	Scheduler::compute_base_interval_sec('UNKNOWN', 12345, true, 0)
);

// UNKNOWN + local heuristic NOT active (no recent direct call) -> falls through to standard
// backoff (priority level 3).
assert_eq(
	'UNKNOWN + watchdog inactive -> standard backoff',
	Scheduler::compute_backoff_interval_sec(3),
	Scheduler::compute_base_interval_sec('UNKNOWN', null, false, 3)
);

// =====================================================================================
// 2. Backoff grows monotonically to the ceiling and never exceeds it.
// =====================================================================================

$prev = 0;
for ($n = 0; $n <= 20; $n++) {
	$interval = Scheduler::compute_backoff_interval_sec($n);
	assert_true("backoff monotonically non-decreasing at n={$n}", $interval >= $prev);
	assert_true("backoff never exceeds MAX_INTERVAL_SEC at n={$n}", $interval <= Scheduler::MAX_INTERVAL_SEC);
	$prev = $interval;
}
assert_eq('backoff reaches the ceiling for a long empty streak', Scheduler::MAX_INTERVAL_SEC, Scheduler::compute_backoff_interval_sec(20));
assert_eq('backoff at n=0 is the floor (no empty streak yet)', Scheduler::MIN_INTERVAL_SEC, Scheduler::compute_backoff_interval_sec(0));
assert_true('backoff strictly grows at least once before capping', Scheduler::compute_backoff_interval_sec(1) > Scheduler::compute_backoff_interval_sec(0));

// =====================================================================================
// 3. DEGRADED resets the interval regardless of current backoff state.
// =====================================================================================

foreach ([0, 1, 5, 20] as $consecutive_empty) {
	assert_eq(
		"DEGRADED floors to MIN regardless of consecutive_empty={$consecutive_empty}",
		Scheduler::MIN_INTERVAL_SEC,
		Scheduler::compute_base_interval_sec('DEGRADED', null, false, $consecutive_empty)
	);
	assert_eq(
		"DEGRADED floors to MIN regardless of consecutive_empty={$consecutive_empty} (watchdog active too)",
		Scheduler::MIN_INTERVAL_SEC,
		Scheduler::compute_base_interval_sec('DEGRADED', null, true, $consecutive_empty)
	);
}

// =====================================================================================
// 4. OK clamps recommended_poll_interval_s to [MIN, MAX].
// =====================================================================================

assert_eq('OK clamps a too-small recommendation up to MIN', Scheduler::MIN_INTERVAL_SEC, Scheduler::compute_base_interval_sec('OK', 1, false, 0));
assert_eq('OK clamps a too-large recommendation down to MAX', Scheduler::MAX_INTERVAL_SEC, Scheduler::compute_base_interval_sec('OK', 999999, false, 0));
assert_eq('OK passes an in-range recommendation through unchanged', 1200, Scheduler::compute_base_interval_sec('OK', 1200, false, 0));
assert_eq('OK with no recommendation at all falls back to BASE_INTERVAL_SEC', Scheduler::BASE_INTERVAL_SEC, Scheduler::compute_base_interval_sec('OK', null, false, 0));

// =====================================================================================
// 5. is_watchdog_active(): T_healthy boundary.
// =====================================================================================

$now = 2_000_000;
assert_true('watchdog active just inside T_healthy', Scheduler::is_watchdog_active(gmdate('Y-m-d H:i:s', $now - (Scheduler::T_HEALTHY_SEC - 1)) , $now));
assert_true('watchdog inactive exactly at T_healthy (strict less-than)', !Scheduler::is_watchdog_active(gmdate('Y-m-d H:i:s', $now - Scheduler::T_HEALTHY_SEC), $now));
assert_true('watchdog inactive well past T_healthy', !Scheduler::is_watchdog_active(gmdate('Y-m-d H:i:s', $now - Scheduler::T_HEALTHY_SEC - 3600), $now));
assert_true('watchdog inactive when never called (null)', !Scheduler::is_watchdog_active(null, $now));
assert_true('watchdog inactive on unparseable timestamp', !Scheduler::is_watchdog_active('not-a-date', $now));

// =====================================================================================
// 6. apply_jitter(): stays within [MIN, MAX] and within +/-10-20% of the input, both signs.
// =====================================================================================

$fixed_high = static function (int $min, int $max): int {
	return $max; // always pick the top of the range: 20% magnitude, positive sign
};
$fixed_low_negative = static function (int $min, int $max): int {
	return $min; // 10% magnitude; sign selector also returns $min=0 -> negative
};

$base = 1000;
$jittered_up = Scheduler::apply_jitter($base, $fixed_high);
assert_eq('jitter +20% of 1000', 1200, $jittered_up);

$jittered_down = Scheduler::apply_jitter($base, $fixed_low_negative);
assert_eq('jitter -10% of 1000 (min magnitude, min=negative sign)', 900, $jittered_down);

// Real random_int()-backed jitter: run many samples, assert bounds hold and both directions occur.
$saw_increase = false;
$saw_decrease = false;
for ($i = 0; $i < 200; $i++) {
	$sample = Scheduler::apply_jitter($base);
	assert_true("random jitter sample #{$i} within +/-20%", $sample >= (int) round($base * 0.8) && $sample <= (int) round($base * 1.2));
	if ($sample > $base) {
		$saw_increase = true;
	}
	if ($sample < $base) {
		$saw_decrease = true;
	}
}
assert_true('random jitter produced at least one increase over 200 samples', $saw_increase);
assert_true('random jitter produced at least one decrease over 200 samples', $saw_decrease);

// Jitter never pushes the result outside the global [MIN, MAX] bounds even at the extremes.
assert_true('jitter on MAX_INTERVAL_SEC stays <= MAX_INTERVAL_SEC', Scheduler::apply_jitter(Scheduler::MAX_INTERVAL_SEC, $fixed_high) <= Scheduler::MAX_INTERVAL_SEC);
assert_true('jitter on MIN_INTERVAL_SEC stays >= MIN_INTERVAL_SEC', Scheduler::apply_jitter(Scheduler::MIN_INTERVAL_SEC, $fixed_low_negative) >= Scheduler::MIN_INTERVAL_SEC);

// =====================================================================================
// 7. record_lease_outcome() / is_lease_due(): orchestration layer (options-backed state).
// =====================================================================================

reset_options();
$t0 = 5_000_000;

// First-ever tick: no state persisted yet -> due immediately.
assert_true('lease is due before any state has ever been recorded', Scheduler::is_lease_due($t0));

// Empty response streak grows the backoff and pushes next_due_at out.
$i1 = Scheduler::record_lease_outcome('UNKNOWN', null, false, $t0);
assert_true('not due immediately after recording an outcome', !Scheduler::is_lease_due($t0 + 1));
assert_true('due once next_due_at has elapsed', Scheduler::is_lease_due($t0 + $i1));

$i2 = Scheduler::record_lease_outcome('UNKNOWN', null, false, $t0 + $i1);
$i3 = Scheduler::record_lease_outcome('UNKNOWN', null, false, $t0 + $i1 + $i2);
assert_true('consecutive empty responses widen the interval (backoff growing)', $i3 >= $i1);

// A lease that returns a task resets to the floor and starts the grace window.
$i_task = Scheduler::record_lease_outcome('UNKNOWN', null, true, $t0 + $i1 + $i2 + $i3);
assert_true('getting a task resets close to MIN_INTERVAL_SEC (pre/post jitter band)', $i_task <= (int) round(Scheduler::MIN_INTERVAL_SEC * 1.2));

// Immediately after, another empty response still lands inside the grace window -> stays at the
// floor rather than resuming backoff growth.
$now_after_task = $t0 + $i1 + $i2 + $i3 + $i_task;
$i_grace = Scheduler::record_lease_outcome('UNKNOWN', null, false, $now_after_task + 60);
assert_true('empty response inside the post-task grace window stays near MIN, does not resume backoff growth', $i_grace <= (int) round(Scheduler::MIN_INTERVAL_SEC * 1.2));

// Past the grace window, an empty response resumes backoff growth from scratch (consecutive_empty
// was reset to 0 by the task, so this first post-grace empty response is still a "small" step).
$after_grace_ts = $now_after_task + Scheduler::EMPTY_GRACE_SEC + 1;
$i_post_grace = Scheduler::record_lease_outcome('UNKNOWN', null, false, $after_grace_ts);
assert_true('empty response past the grace window is still bounded by MAX_INTERVAL_SEC', $i_post_grace <= Scheduler::MAX_INTERVAL_SEC);

// DEGRADED short-circuits next_due_at back to (near) the floor even with a long empty streak.
reset_options();
$t = 9_000_000;
$interval = $t;
for ($i = 0; $i < 10; $i++) {
	$interval = Scheduler::record_lease_outcome('UNKNOWN', null, false, $t);
	$t += $interval;
}
assert_true('after 10 empty responses the interval has grown well past the floor', $interval > Scheduler::MIN_INTERVAL_SEC);
$degraded_interval = Scheduler::record_lease_outcome('DEGRADED', null, false, $t);
assert_true(
	'DEGRADED immediately resets next lease interval to (near) the floor regardless of accumulated backoff',
	$degraded_interval <= (int) round(Scheduler::MIN_INTERVAL_SEC * 1.2)
);

// =====================================================================================
// 8. Settings connection activity timestamps: UTC storage convention and disconnect cleanup.
// =====================================================================================

reset_options();
assert_true('last_primary_call_at() is null before any primary call was ever recorded', Settings::last_primary_call_at() === null);
Settings::touch_last_primary_call();
$stored = Settings::last_primary_call_at();
assert_true('touch_last_primary_call() stores a non-empty UTC Y-m-d H:i:s string', is_string($stored) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stored) === 1);
// Round-trips through is_watchdog_active() using "now" as the true current UTC time (matches
// how OutboundPollScheduler consumes it in production, no injected clock).
assert_true('a just-touched last_primary_call_at is watchdog-active against the real current time', Scheduler::is_watchdog_active($stored, time()));

assert_true('last_site_to_pp_call_at() is null before any site-to-PP call was recorded', Settings::last_site_to_pp_call_at() === null);
Settings::touch_last_site_to_pp_call();
$site_to_pp_stored = Settings::last_site_to_pp_call_at();
assert_true('touch_last_site_to_pp_call() stores a non-empty UTC Y-m-d H:i:s string', is_string($site_to_pp_stored) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $site_to_pp_stored) === 1);

Settings::disconnect();
assert_true('disconnect() clears last_primary_call_at()', Settings::last_primary_call_at() === null);
assert_true('disconnect() clears last_site_to_pp_call_at()', Settings::last_site_to_pp_call_at() === null);

// =====================================================================================
// 9. Lease diagnostics / admin banner (DEC-002-08 polling evidence).
// =====================================================================================

reset_options();
$t_now = 1_700_000_000;
assert_true('banner hidden when never leased (WP-Cron on)', Scheduler::should_show_poll_banner($t_now) === false);
Scheduler::record_lease_contact(true, null, $t_now);
assert_eq('last_lease_at after successful contact', $t_now, Scheduler::last_lease_at());
assert_true('banner hidden after a fresh lease', Scheduler::should_show_poll_banner($t_now) === false);
assert_true(
	'lease is stale after MAX*2 without another success',
	Scheduler::is_lease_stale($t_now + Scheduler::MAX_INTERVAL_SEC * 2 + 1)
);
assert_true(
	'banner shown when lease is stale',
	Scheduler::should_show_poll_banner($t_now + Scheduler::MAX_INTERVAL_SEC * 2 + 1)
);
Scheduler::record_lease_contact(false, 'timeout', $t_now + 10);
assert_eq('last_lease_error after failed contact', 'timeout', Scheduler::last_lease_error());
assert_eq('failed contact does not wipe last_lease_at', $t_now, Scheduler::last_lease_at());

echo "\nAll WP-10 adaptive polling smoke tests passed.\n";
