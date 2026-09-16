#!/usr/bin/env php
<?php

/**
 * Smoke tests for WP-08: OutboundTaskWorker's pure decision logic (no live WP DB).
 *
 * Covers the three acceptance points called out in TASK-002-WP-08:
 *   - idempotent re-lease of the same task_id never re-executes, only re-reports
 *   - expires_at-before-execution short-circuits to `expired` without calling dispatch
 *   - the claim/process batch loop stops within its time budget
 *
 * OutboundTaskWorker::run_batch_with_time_budget() / decide_action() / is_expired() /
 * resolve_pending_outcome() are all pure (callables/injectable clock, no $wpdb access), so they
 * are exercised directly here — same pattern as bin/test-wp06-exclude-sync.php.
 *
 * Usage: php bin/test-wp08-outbound-worker.php
 */

define('ABSPATH', true);

require_once dirname(__DIR__) . '/src/OutboundTaskQueue.php';
require_once dirname(__DIR__) . '/src/OutboundTaskWorker.php';

use parrotposter\OutboundTaskQueue;
use parrotposter\OutboundTaskWorker;

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

// --- Idempotency: decide_action() (WP-08 checklist "Устойчивость") ---
//
// A task_id already terminal locally (done/failed/expired/skipped) must never be executed
// again — only pending rows are ever 'execute'. If the terminal row hasn't been reported yet
// (report call failed, or crash happened between local execution and report), only the report
// is retried; once reported, it's a pure no-op.

assert_eq('pending row -> execute', 'execute', OutboundTaskWorker::decide_action(OutboundTaskQueue::STATUS_PENDING, false));
assert_eq('processing row -> execute (already claimed, not terminal)', 'execute', OutboundTaskWorker::decide_action(OutboundTaskQueue::STATUS_PROCESSING, false));

foreach ([OutboundTaskQueue::STATUS_DONE, OutboundTaskQueue::STATUS_FAILED, OutboundTaskQueue::STATUS_EXPIRED, OutboundTaskQueue::STATUS_SKIPPED] as $terminal) {
	assert_eq("terminal row ({$terminal}), not yet reported -> resend_report (never re-execute)", 'resend_report', OutboundTaskWorker::decide_action($terminal, false));
	assert_eq("terminal row ({$terminal}), already reported -> skip", 'skip', OutboundTaskWorker::decide_action($terminal, true));
}

// Simulate the exact crash scenario from the task: lease returns the same task_id twice after
// a crash between claim and report. First pass executes and reaches a terminal status locally
// but the report never lands (network blip); second pass (same task_id row) must decide
// 'resend_report', not 'execute' again.
$first_pass_status = OutboundTaskQueue::STATUS_PENDING;
$first_action = OutboundTaskWorker::decide_action($first_pass_status, false);
assert_eq('first observation of task_id is pending -> execute', 'execute', $first_action);
// ... local execution runs, row becomes 'done', report call fails, row stays reported=0 ...
$second_pass_status = OutboundTaskQueue::STATUS_DONE;
$second_action = OutboundTaskWorker::decide_action($second_pass_status, false);
assert_eq('same task_id re-observed after crash-before-report -> resend_report, not execute', 'resend_report', $second_action);

// --- expires_at staleness (SPEC-002-03 §8): is_expired() + resolve_pending_outcome() ---

$now_ts = 1_000_000;
assert_true('is_expired(): expires_at in the past', OutboundTaskWorker::is_expired('@' . ($now_ts - 60), $now_ts));
assert_true('is_expired(): expires_at exactly now counts as expired', OutboundTaskWorker::is_expired('@' . $now_ts, $now_ts));
assert_true('is_expired(): expires_at in the future is not expired', !OutboundTaskWorker::is_expired('@' . ($now_ts + 60), $now_ts));
assert_true('is_expired(): empty expires_at fails open (not expired)', !OutboundTaskWorker::is_expired('', $now_ts));
assert_true('is_expired(): unparseable expires_at fails open (not expired)', !OutboundTaskWorker::is_expired('not-a-date', $now_ts));

$dispatch_calls = 0;
$dispatch_fn = static function () use (&$dispatch_calls): array {
	++$dispatch_calls;

	return ['status' => OutboundTaskQueue::STATUS_SKIPPED, 'result' => null, 'error_code' => null];
};

$expired_outcome = OutboundTaskWorker::resolve_pending_outcome('@' . ($now_ts - 60), $now_ts, $dispatch_fn);
assert_eq('expired task -> status expired', OutboundTaskQueue::STATUS_EXPIRED, $expired_outcome['status']);
assert_true('expired task -> dispatched=false (no side effects, SPEC-002-03 §8)', $expired_outcome['dispatched'] === false);
assert_eq('expired task -> dispatch() never called', 0, $dispatch_calls);

$fresh_outcome = OutboundTaskWorker::resolve_pending_outcome('@' . ($now_ts + 60), $now_ts, $dispatch_fn);
assert_eq('non-expired task -> dispatch() result status propagates', OutboundTaskQueue::STATUS_SKIPPED, $fresh_outcome['status']);
assert_true('non-expired task -> dispatched=true', $fresh_outcome['dispatched'] === true);
assert_eq('non-expired task -> dispatch() called exactly once', 1, $dispatch_calls);

// --- Time budget enforcement: run_batch_with_time_budget() ---
//
// Fake clock: each call to $now_fn() advances a counter by a fixed step, simulating elapsed
// wall-clock time per iteration without sleeping in the test.

function make_fake_clock(float $step_sec)
{
	$t = 0.0;

	return static function () use (&$t, $step_sec): float {
		$t += $step_sec;

		return $t;
	};
}

// 100 items available, but the clock advances 1s per check against a 5s deadline -> stops
// well before exhausting the queue and never exceeds the deadline.
$available = range(1, 100);
$processed_log = [];
$claim_next = static function () use (&$available): ?array {
	if (empty($available)) {
		return null;
	}

	return ['task_id' => (string) array_shift($available)];
};
$process_one = static function (array $row) use (&$processed_log): void {
	$processed_log[] = $row['task_id'];
};

$processed = OutboundTaskWorker::run_batch_with_time_budget(
	$claim_next,
	$process_one,
	10,
	5.0,
	make_fake_clock(1.0)
);
assert_true('time budget: stops at or before the deadline check trips (<=5 checks -> <=5 processed)', $processed <= 5);
assert_true('time budget: processed at least one item before the deadline', $processed >= 1);
assert_eq('time budget: processed count matches process_one() call count', count($processed_log), $processed);

// max_items caps it even when the deadline would allow more (deadline far in the future).
$available2 = range(1, 100);
$claim_next2 = static function () use (&$available2): ?array {
	if (empty($available2)) {
		return null;
	}

	return ['task_id' => (string) array_shift($available2)];
};
$processed_capped = OutboundTaskWorker::run_batch_with_time_budget(
	$claim_next2,
	static function (): void {},
	10,
	1000000.0,
	make_fake_clock(0.001)
);
assert_eq('max_items caps the batch even with budget to spare', 10, $processed_capped);

// Empty queue stops immediately regardless of budget.
$processed_empty = OutboundTaskWorker::run_batch_with_time_budget(
	static function (): ?array {
		return null;
	},
	static function (): void {},
	10,
	1000000.0,
	make_fake_clock(0.001)
);
assert_eq('empty claim_next() -> zero processed', 0, $processed_empty);

// Deadline already passed before the first iteration -> zero processed, no items claimed.
$claim_attempted = false;
$processed_deadline_passed = OutboundTaskWorker::run_batch_with_time_budget(
	static function () use (&$claim_attempted): ?array {
		$claim_attempted = true;

		return ['task_id' => 'should-not-be-claimed'];
	},
	static function (): void {},
	10,
	0.0,
	static function (): float {
		return 1.0; // already past the deadline on the very first check
	}
);
assert_eq('deadline already passed -> zero processed', 0, $processed_deadline_passed);
assert_true('deadline already passed -> claim_next() never called', !$claim_attempted);

echo "\nAll WP-08 outbound worker smoke tests passed.\n";
