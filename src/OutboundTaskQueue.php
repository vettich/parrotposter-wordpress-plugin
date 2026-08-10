<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Local storage for leased `PluginOutboundTask` rows (SPEC-002-03 / SPEC-002-09 §6.4).
 *
 * Direction: PP → site **pull** — the opposite of {@see LocalQueue} (site → PP push). Not the
 * same class/table on purpose (TASK-002-WP-08 notes): different semantics, different retry
 * model, no shared code beyond the general "claim + time budget" architectural pattern.
 *
 * Row lifecycle: `pending` → `processing` (locked) → terminal (`done` | `failed` | `expired` |
 * `skipped`). Terminal rows are kept (not deleted) with `reported = 0` until
 * `pluginOutboundTaskReport` succeeds — this is the idempotency guard: a `task_id` that PP
 * re-leases after a crash between local execution and report is recognized as already-terminal
 * locally and is never re-executed, only re-reported (see {@see OutboundTaskWorker::decide_action()}).
 */
class OutboundTaskQueue
{
	public const STATUS_PENDING = 'pending';

	public const STATUS_PROCESSING = 'processing';

	public const STATUS_DONE = 'done';

	public const STATUS_FAILED = 'failed';

	public const STATUS_EXPIRED = 'expired';

	public const STATUS_SKIPPED = 'skipped';

	private const TERMINAL_STATUSES = [
		self::STATUS_DONE,
		self::STATUS_FAILED,
		self::STATUS_EXPIRED,
		self::STATUS_SKIPPED,
	];

	/** Mirrors LocalQueue::LOCK_LEASE_SECONDS (same claim-lock pattern). */
	private const LOCK_LEASE_SECONDS = 120;

	/** Reported terminal rows older than this are purged (mirrors LocalQueue's failed-row purge). */
	private const REPORTED_PURGE_AFTER_DAYS = 7;

	public static function is_terminal_status(string $status): bool
	{
		return in_array($status, self::TERMINAL_STATUSES, true);
	}

	public static function table(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'parrotposter_outbound_tasks';
	}

	public static function schema_sql(): string
	{
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$t = self::table();

		return "CREATE TABLE {$t} (
			task_id varchar(64) NOT NULL,
			task_type varchar(64) NOT NULL,
			payload longtext NOT NULL,
			payload_signature varchar(255) NOT NULL default '',
			expires_at datetime NOT NULL,
			rotation_id varchar(64) NULL default NULL,
			status varchar(20) NOT NULL default 'pending',
			result longtext NULL default NULL,
			error_code varchar(64) NULL default NULL,
			reported tinyint(1) NOT NULL default 0,
			leased_at datetime NOT NULL,
			locked_until datetime NULL default NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (task_id),
			KEY idx_ot_pending (status, locked_until),
			KEY idx_ot_unreported (reported, status)
		) {$charset_collate};";
	}

	private static function now_utc_for_db(): string
	{
		return gmdate('Y-m-d H:i:s');
	}

	private static function normalize_datetime(string $iso): string
	{
		if ($iso === '') {
			return self::now_utc_for_db();
		}
		$ts = strtotime($iso);
		if ($ts === false) {
			return self::now_utc_for_db();
		}

		return gmdate('Y-m-d H:i:s', $ts);
	}

	/**
	 * Insert newly leased tasks (GraphQL `PluginOutboundTask[]`, camelCase keys: taskId, type,
	 * payload, payloadSignature, expiresAt, rotationId — SPEC-002-09 §6.4).
	 *
	 * Uses INSERT IGNORE keyed on `task_id`: a `task_id` PP re-leases after claim_ttl expiry
	 * (e.g. plugin crashed between claim and report) already exists locally — its row (and
	 * whatever local status it reached) is left untouched. Never resets a terminal row back to
	 * `pending`.
	 *
	 * @param list<array<string, mixed>> $tasks
	 */
	public static function store_leased_tasks(array $tasks): void
	{
		global $wpdb;
		$t = self::table();
		$now = self::now_utc_for_db();

		foreach ($tasks as $task) {
			if (!is_array($task)) {
				continue;
			}
			$task_id = isset($task['taskId']) ? (string) $task['taskId'] : '';
			if ($task_id === '') {
				continue;
			}
			$task_type = isset($task['type']) ? (string) $task['type'] : '';
			$payload_json = wp_json_encode($task['payload'] ?? [], JSON_UNESCAPED_UNICODE);
			if ($payload_json === false) {
				$payload_json = '{}';
			}
			$payload_signature = isset($task['payloadSignature']) ? (string) $task['payloadSignature'] : '';
			$expires_at = self::normalize_datetime((string) ($task['expiresAt'] ?? ''));
			$rotation_id = isset($task['rotationId']) && $task['rotationId'] !== null
				? (string) $task['rotationId']
				: null;

			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$t}
						(task_id, task_type, payload, payload_signature, expires_at, rotation_id, status, reported, leased_at, updated_at)
					VALUES (%s, %s, %s, %s, %s, %s, %s, 0, %s, %s)",
					$task_id,
					$task_type,
					$payload_json,
					$payload_signature,
					$expires_at,
					$rotation_id,
					self::STATUS_PENDING,
					$now,
					$now
				)
			);
		}
	}

	/**
	 * Requeue rows stuck in `processing` past their lock (worker died mid-execution).
	 */
	public static function recover_stale_processing(): void
	{
		global $wpdb;
		$t = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET status = %s, locked_until = NULL
				WHERE status = %s AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())",
				self::STATUS_PENDING,
				self::STATUS_PROCESSING
			)
		);
	}

	/**
	 * Atomically claim one pending row for execution (FIFO by lease time).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function claim_next_pending(): ?array
	{
		global $wpdb;
		$t = self::table();

		$wpdb->query('START TRANSACTION');
		$task_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT task_id FROM {$t} WHERE status = %s ORDER BY leased_at ASC LIMIT 1 FOR UPDATE",
				self::STATUS_PENDING
			)
		);
		if ($task_id !== null && $task_id !== '') {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$t} SET status = %s, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND) WHERE task_id = %s",
					self::STATUS_PROCESSING,
					self::LOCK_LEASE_SECONDS,
					$task_id
				)
			);
		}
		$wpdb->query('COMMIT');

		if ($task_id === null || $task_id === '') {
			return null;
		}

		return self::find_row((string) $task_id);
	}

	/**
	 * Rows already executed locally (terminal status) but not yet confirmed reported to PP —
	 * the crash-between-execute-and-report recovery path. Only the report is retried here, the
	 * task is never re-dispatched.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function claim_next_unreported(): ?array
	{
		global $wpdb;
		$t = self::table();
		$statuses = self::TERMINAL_STATUSES;
		$placeholders = implode(',', array_fill(0, count($statuses), '%s'));

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE reported = 0 AND status IN ({$placeholders}) ORDER BY updated_at ASC LIMIT 1", // phpcs:ignore
				...$statuses
			),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function find_row(string $task_id): ?array
	{
		global $wpdb;
		$t = self::table();
		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$t} WHERE task_id = %s", $task_id),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * @param mixed $result JSON-encodable result payload, or null
	 */
	public static function mark_terminal(string $task_id, string $status, $result, ?string $error_code): void
	{
		global $wpdb;
		$t = self::table();
		$result_json = $result !== null ? wp_json_encode($result, JSON_UNESCAPED_UNICODE) : null;
		if ($result_json === false) {
			$result_json = null;
		}

		$wpdb->update(
			$t,
			[
				'status' => $status,
				'result' => $result_json,
				'error_code' => $error_code,
				'locked_until' => null,
				'updated_at' => self::now_utc_for_db(),
			],
			['task_id' => $task_id],
			['%s', '%s', '%s', '%s', '%s'],
			['%s']
		);
	}

	public static function mark_reported(string $task_id): void
	{
		global $wpdb;
		$t = self::table();
		$wpdb->update(
			$t,
			[
				'reported' => 1,
				'updated_at' => self::now_utc_for_db(),
			],
			['task_id' => $task_id],
			['%d', '%s'],
			['%s']
		);
	}

	/**
	 * Housekeeping: drop reported terminal rows older than {@see REPORTED_PURGE_AFTER_DAYS}.
	 */
	public static function purge_old_reported(): void
	{
		global $wpdb;
		$t = self::table();
		$cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . self::REPORTED_PURGE_AFTER_DAYS . ' days'));
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$t} WHERE reported = 1 AND updated_at < %s LIMIT 500",
				$cutoff
			)
		);
	}
}
