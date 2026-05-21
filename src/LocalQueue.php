<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Local deferred queue for create / update / delete (ParrotPoster sync), Bitrix vettich.sp3 LocalQueue analogue.
 */
class LocalQueue
{
	public const STATUS_PENDING = 'pending';

	public const STATUS_PROCESSING = 'processing';

	public const STATUS_FAILED = 'failed';

	public const OP_CREATE = 'create';

	public const OP_UPDATE = 'update';

	public const OP_DELETE = 'delete';

	private const LOCK_LEASE_SECONDS = 120;

	private const MAX_ATTEMPTS = 4;

	private const HTTP_PROCESS_MAX_ITEMS = 10;

	private const HTTP_PROCESS_TIME_BUDGET_SEC = 5;

	private const FAILED_PURGE_BATCH = 200;

	public const OPTION_WAKE_PENDING = 'parrotposter_lq_wake_pending';

	/** DB schema version that stores local queue datetimes in UTC. */
	public const DB_VERSION_UTC = '1.0.9';

	private static $pp_wake_shutdown_registered = false;

	/**
	 * Current UTC time for queue columns (next_attempt_at, created_at).
	 */
	private static function now_utc_for_db(): string
	{
		return gmdate('Y-m-d H:i:s');
	}

	private static function table(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'parrotposter_local_queue';
	}

	public static function enqueue_create(int $wp_post_id): void
	{
		$wp_post_id = (int) $wp_post_id;
		if ($wp_post_id <= 0) {
			return;
		}

		PP::log(['LocalQueue::enqueue_create', 'wp_post_id' => $wp_post_id]);

		self::remove_pending_op(self::OP_DELETE, $wp_post_id);
		self::enqueue_pending_upsert($wp_post_id, self::OP_CREATE, '{}', false);
		self::schedule_wake_on_shutdown();
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function enqueue_update(int $wp_post_id, array $payload = []): void
	{
		$wp_post_id = (int) $wp_post_id;
		if ($wp_post_id <= 0) {
			return;
		}

		PP::log(['LocalQueue::enqueue_update', 'wp_post_id' => $wp_post_id]);

		if (self::has_pending_create($wp_post_id)) {
			return;
		}

		self::remove_pending_op(self::OP_DELETE, $wp_post_id);

		$json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			$json = '{}';
		}

		self::enqueue_pending_upsert($wp_post_id, self::OP_UPDATE, $json, true);
		self::schedule_wake_on_shutdown();
	}

	public static function enqueue_delete(int $wp_post_id): void
	{
		$wp_post_id = (int) $wp_post_id;
		if ($wp_post_id <= 0) {
			return;
		}

		PP::log(['LocalQueue::enqueue_delete', 'wp_post_id' => $wp_post_id]);

		self::remove_pending_op(self::OP_UPDATE, $wp_post_id);
		self::remove_pending_op(self::OP_CREATE, $wp_post_id);
		self::enqueue_pending_upsert($wp_post_id, self::OP_DELETE, '{}', false);
		self::schedule_wake_on_shutdown();
	}

	private static function has_pending_create(int $wp_post_id): bool
	{
		global $wpdb;

		$t = self::table();
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE wp_post_id = %d AND operation = %s AND status = %s LIMIT 1",
				$wp_post_id,
				self::OP_CREATE,
				self::STATUS_PENDING
			)
		);

		return $n > 0;
	}

	private static function remove_pending_op(string $op, int $wp_post_id): void
	{
		global $wpdb;

		$t = self::table();
		$wpdb->delete(
			$t,
			[
				'wp_post_id' => $wp_post_id,
				'operation' => $op,
				'status' => self::STATUS_PENDING,
			],
			['%d', '%s', '%s']
		);
	}

	private static function recover_stale_processing(): void
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
	 * Atomically claim one ready pending row for HTTP/admin processing.
	 */
	private static function claim_pending_one_id(): ?int
	{
		global $wpdb;

		$t = self::table();
		$id = null;

		$wpdb->query('START TRANSACTION');
		$row_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$t}
				WHERE status = %s AND next_attempt_at <= UTC_TIMESTAMP()
				ORDER BY id ASC
				LIMIT 1
				FOR UPDATE",
				self::STATUS_PENDING
			)
		);
		if ($row_id !== null && $row_id !== '') {
			$id = (int) $row_id;
			if ($id > 0) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$t} SET status = %s, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND) WHERE id = %d",
						self::STATUS_PROCESSING,
						self::LOCK_LEASE_SECONDS,
						$id
					)
				);
			} else {
				$id = null;
			}
		}
		$wpdb->query('COMMIT');

		return $id;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function enqueue_pending_upsert(
		int $wp_post_id,
		string $operation,
		string $payload,
		bool $update_payload_on_dup
	): void {
		global $wpdb;

		$t = self::table();
		$now = self::now_utc_for_db();

		$upd_payload = $update_payload_on_dup ? ', payload = VALUES(payload)' : '';

		$sql = "INSERT INTO {$t} (wp_post_id, operation, payload, status, attempts, next_attempt_at, created_at, locked_until)
			VALUES (%d, %s, %s, %s, 0, %s, %s, NULL)
			ON DUPLICATE KEY UPDATE
			next_attempt_at = VALUES(next_attempt_at){$upd_payload},
			locked_until = IF(status = %s, locked_until, NULL),
			attempts = IF(status = %s, 0, attempts),
			status = IF(status = %s, %s, status)";

		$wpdb->query(
			$wpdb->prepare(
				$sql,
				$wp_post_id,
				$operation,
				$payload,
				self::STATUS_PENDING,
				$now,
				$now,
				self::STATUS_PROCESSING,
				self::STATUS_FAILED,
				self::STATUS_FAILED,
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function handle_http_process(bool $chain_wake = true): array
	{
		$started = microtime(true);
		$batch = self::process_pending_batch();
		$has_more = self::has_pending_ready();

		if ($batch['processed'] > 0) {
			self::set_wake_pending(false);
		}

		if ($chain_wake && $has_more && !empty(Options::token())) {
			$chain_ok = Api::request_local_queue_wake();
			if (!$chain_ok) {
				self::set_wake_pending(true);
			}
		}

		PP::log([
			'LocalQueue::handle_http_process',
			'processed' => $batch['processed'],
			'items_attempted' => $batch['items_attempted'],
			'elapsed_sec' => round(microtime(true) - $started, 3),
			'has_more' => $has_more,
			'chain_wake' => $chain_wake,
		]);

		return [
			'ok' => true,
			'processed' => $batch['processed'],
			'has_more' => $has_more,
		];
	}

	/**
	 * Admin UI: process ready pending batch on this site (no post-queue wake).
	 *
	 * @return array<string, mixed>
	 */
	public static function process_pending_admin(): array
	{
		return self::handle_http_process(false);
	}

	/**
	 * Admin UI: process a single queue row on this site (no post-queue wake).
	 *
	 * @return array<string, mixed>
	 */
	public static function process_queue_row(int $id): array
	{
		$id = (int) $id;
		if ($id < 1) {
			return [
				'ok' => false,
				'error' => 'invalid_id',
				'processed' => 0,
				'queue_id' => $id,
			];
		}

		self::recover_stale_processing();

		global $wpdb;

		$t = self::table();
		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id),
			ARRAY_A
		);
		if (!is_array($row)) {
			return [
				'ok' => false,
				'error' => 'not_found',
				'processed' => 0,
				'queue_id' => $id,
			];
		}

		$status = (string) ($row['status'] ?? '');
		if ($status === self::STATUS_PROCESSING) {
			return [
				'ok' => false,
				'error' => 'busy',
				'processed' => 0,
				'queue_id' => $id,
			];
		}

		if ($status === self::STATUS_FAILED) {
			$wpdb->update(
				$t,
				[
					'status' => self::STATUS_PENDING,
					'next_attempt_at' => self::now_utc_for_db(),
					'locked_until' => null,
				],
				['id' => $id],
				['%s', '%s', '%s'],
				['%d']
			);
			$status = self::STATUS_PENDING;
		}

		if ($status !== self::STATUS_PENDING) {
			return [
				'ok' => false,
				'error' => 'not_processable',
				'processed' => 0,
				'queue_id' => $id,
			];
		}

		self::ensure_row_ready_for_claim($id);

		if (!self::claim_row_by_id($id)) {
			return [
				'ok' => false,
				'error' => 'busy',
				'processed' => 0,
				'queue_id' => $id,
			];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id),
			ARRAY_A
		);
		if (!is_array($row)) {
			return [
				'ok' => false,
				'error' => 'not_found',
				'processed' => 0,
				'queue_id' => $id,
			];
		}

		$processed = self::execute_claimed_row($row);
		if ($processed > 0) {
			self::set_wake_pending(false);
		}

		PP::log(['LocalQueue::process_queue_row', 'queue_id' => $id, 'processed' => $processed]);

		return [
			'ok' => true,
			'processed' => $processed,
			'queue_id' => $id,
			'has_more' => self::has_pending_ready(),
		];
	}

	private static function ensure_row_ready_for_claim(int $id): void
	{
		global $wpdb;

		$t = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET next_attempt_at = %s WHERE id = %d AND status = %s AND next_attempt_at > UTC_TIMESTAMP()",
				self::now_utc_for_db(),
				$id,
				self::STATUS_PENDING
			)
		);
	}

	private static function claim_row_by_id(int $id): bool
	{
		global $wpdb;

		$t = self::table();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET status = %s, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)
				WHERE id = %d AND status = %s AND next_attempt_at <= UTC_TIMESTAMP()",
				self::STATUS_PROCESSING,
				self::LOCK_LEASE_SECONDS,
				$id,
				self::STATUS_PENDING
			)
		);

		return is_int($updated) && $updated > 0;
	}

	private static function has_pending_ready(): bool
	{
		global $wpdb;

		$t = self::table();
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE status = %s AND next_attempt_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT 1",
				self::STATUS_PENDING
			)
		);

		return (int) $id > 0;
	}

	/**
	 * @return array{processed: int, items_attempted: int}
	 */
	private static function process_pending_batch(): array
	{
		self::recover_stale_processing();

		global $wpdb;

		$t = self::table();
		$deadline = microtime(true) + self::HTTP_PROCESS_TIME_BUDGET_SEC;
		$processed = 0;
		$items_attempted = 0;

		while ($items_attempted < self::HTTP_PROCESS_MAX_ITEMS && microtime(true) < $deadline) {
			$id = self::claim_pending_one_id();
			if ($id === null) {
				break;
			}

			$row = $wpdb->get_row(
				$wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id),
				ARRAY_A
			);
			if (!is_array($row)) {
				break;
			}

			++$items_attempted;
			$processed += self::execute_claimed_row($row);
		}

		return [
			'processed' => $processed,
			'items_attempted' => $items_attempted,
		];
	}

	/**
	 * Run operation for a row already in processing status.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function execute_claimed_row(array $row): int
	{
		global $wpdb;

		$t = self::table();
		$id = (int) ($row['id'] ?? 0);
		$wp_post_id = (int) ($row['wp_post_id'] ?? 0);
		$op = (string) ($row['operation'] ?? '');

		try {
			if ($op === self::OP_UPDATE) {
				self::run_update($wp_post_id);
			} elseif ($op === self::OP_DELETE) {
				self::run_delete($wp_post_id);
			} elseif ($op === self::OP_CREATE) {
				self::run_create($wp_post_id);
			} else {
				$wpdb->update(
					$t,
					[
						'status' => self::STATUS_FAILED,
						'locked_until' => null,
					],
					['id' => $id],
					['%s', '%s'],
					['%d']
				);

				return 0;
			}
			$wpdb->delete($t, ['id' => $id], ['%d']);

			return 1;
		} catch (\Throwable $e) {
			PP::log(['LocalQueue::process', 'id' => $id, 'err' => $e->getMessage()]);
			$attempts = (int) ($row['attempts'] ?? 0) + 1;
			if ($attempts >= self::MAX_ATTEMPTS) {
				$wpdb->update(
					$t,
					[
						'status' => self::STATUS_FAILED,
						'attempts' => $attempts,
						'locked_until' => null,
					],
					['id' => $id],
					['%s', '%d', '%s'],
					['%d']
				);
			} else {
				$delay_sec = min(3600, (int) pow(2, $attempts) * 60);
				$next = gmdate('Y-m-d H:i:s', time() + $delay_sec);
				$wpdb->update(
					$t,
					[
						'status' => self::STATUS_PENDING,
						'attempts' => $attempts,
						'next_attempt_at' => $next,
						'locked_until' => null,
					],
					['id' => $id],
					['%s', '%d', '%s', '%s'],
					['%d']
				);
			}

			return 0;
		}
	}

	private static function run_create(int $wp_post_id): void
	{
		$wp_post = get_post($wp_post_id);
		if (!$wp_post instanceof \WP_Post || $wp_post->post_status !== 'publish') {
			return;
		}

		Scheduler::get_instance()->publish_post($wp_post);
	}

	private static function run_delete(int $wp_post_id): void
	{
		Scheduler::delete_all_pp_posts_for_wp_post($wp_post_id);
	}

	private static function run_update(int $wp_post_id): void
	{
		$wp_post = get_post($wp_post_id);
		if (!$wp_post instanceof \WP_Post || $wp_post->post_status !== 'publish') {
			return;
		}

		Scheduler::update_pp_posts_for_wp_post($wp_post);
	}

	private static function purge_old_failed_records(): void
	{
		global $wpdb;

		$t = self::table();
		$cutoff = gmdate('Y-m-d H:i:s', strtotime('-1 month'));

		for ($i = 0; $i < 50; ++$i) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE status = %s AND created_at < %s ORDER BY id ASC LIMIT %d",
					self::STATUS_FAILED,
					$cutoff,
					self::FAILED_PURGE_BATCH
				)
			);
			if (!is_array($ids) || empty($ids)) {
				break;
			}
			foreach ($ids as $id) {
				$wpdb->delete($t, ['id' => (int) $id], ['%d']);
			}
			if (count($ids) < self::FAILED_PURGE_BATCH) {
				break;
			}
		}
	}

	public static function retry_via_cron(): void
	{
		self::purge_old_failed_records();

		$need_wake = self::is_wake_pending() || self::has_pending_ready();
		if (!$need_wake) {
			return;
		}

		if (empty(Options::token())) {
			return;
		}

		$ok = Api::request_local_queue_wake();
		self::set_wake_pending(!$ok);
	}

	public static function get_pending_count(): int
	{
		global $wpdb;

		$t = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE status = %s",
				self::STATUS_PENDING
			)
		);
	}

	public static function get_active_count(): int
	{
		global $wpdb;

		$t = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE status IN (%s, %s, %s)",
				self::STATUS_PENDING,
				self::STATUS_PROCESSING,
				self::STATUS_FAILED
			)
		);
	}

	/**
	 * Rows for admin UI (pending, processing, failed).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list_for_admin(): array
	{
		global $wpdb;

		$t = self::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, wp_post_id, operation, payload, status, attempts, next_attempt_at, created_at, locked_until
				FROM {$t}
				WHERE status IN (%s, %s, %s)
				ORDER BY id ASC",
				self::STATUS_PENDING,
				self::STATUS_PROCESSING,
				self::STATUS_FAILED
			),
			ARRAY_A
		);
		if (!is_array($rows)) {
			return [];
		}

		$items = [];
		foreach ($rows as $row) {
			$items[] = self::format_admin_row($row);
		}

		return $items;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function format_admin_row(array $row): array
	{
		$wp_post_id = (int) ($row['wp_post_id'] ?? 0);
		$operation = (string) ($row['operation'] ?? '');
		$status = (string) ($row['status'] ?? '');
		$payload = (string) ($row['payload'] ?? '');

		$post_title = '';
		$post_status = '';
		$post_type = '';
		$edit_link = '';
		$post_missing = true;

		if ($wp_post_id > 0) {
			$wp_post = get_post($wp_post_id);
			if ($wp_post instanceof \WP_Post) {
				$post_missing = false;
				$post_title = $wp_post->post_title;
				$post_status = $wp_post->post_status;
				$post_type = $wp_post->post_type;
				$link = get_edit_post_link($wp_post_id, 'raw');
				$edit_link = is_string($link) ? $link : '';
			}
		}

		$payload_display = '';
		if ($operation === self::OP_UPDATE && $payload !== '' && $payload !== '{}') {
			$payload_display = $payload;
			if (strlen($payload_display) > 200) {
				$payload_display = substr($payload_display, 0, 200) . '…';
			}
		}

		return [
			'id' => (int) ($row['id'] ?? 0),
			'wp_post_id' => $wp_post_id,
			'operation' => $operation,
			'operation_label' => self::label_operation($operation),
			'status' => $status,
			'status_label' => self::label_status($status),
			'attempts' => (int) ($row['attempts'] ?? 0),
			'next_attempt_at' => (string) ($row['next_attempt_at'] ?? ''),
			'created_at' => (string) ($row['created_at'] ?? ''),
			'locked_until' => (string) ($row['locked_until'] ?? ''),
			'payload' => $payload_display,
			'post_title' => $post_title,
			'post_status' => $post_status,
			'post_type' => $post_type,
			'edit_link' => $edit_link,
			'post_missing' => $post_missing,
		];
	}

	private static function label_operation(string $operation): string
	{
		switch ($operation) {
			case self::OP_CREATE:
				return _x('Create', 'local queue operation', 'parrotposter');
			case self::OP_UPDATE:
				return _x('Update', 'local queue operation', 'parrotposter');
			case self::OP_DELETE:
				return _x('Delete', 'local queue operation', 'parrotposter');
			default:
				return $operation;
		}
	}

	private static function label_status(string $status): string
	{
		switch ($status) {
			case self::STATUS_PENDING:
				return _x('Pending', 'local queue status', 'parrotposter');
			case self::STATUS_PROCESSING:
				return _x('Processing', 'local queue status', 'parrotposter');
			case self::STATUS_FAILED:
				return _x('Failed', 'local queue status', 'parrotposter');
			default:
				return $status;
		}
	}

	public static function get_wake_pending_flag(): bool
	{
		return self::is_wake_pending();
	}

	/**
	 * One-time upgrade: rows enqueued with current_time('mysql') → UTC (pending, attempts = 0 only).
	 *
	 * @return int Number of updated rows
	 */
	public static function migrate_enqueue_rows_to_utc(): int
	{
		global $wpdb;

		$t = self::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, next_attempt_at, created_at FROM {$t} WHERE status = %s AND attempts = 0",
				self::STATUS_PENDING
			),
			ARRAY_A
		);
		if (!is_array($rows) || empty($rows)) {
			return 0;
		}

		$wp_tz = wp_timezone();
		$utc_tz = new \DateTimeZone('UTC');
		$updated = 0;

		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? 0);
			if ($id < 1) {
				continue;
			}

			$next_raw = (string) ($row['next_attempt_at'] ?? '');
			$created_raw = (string) ($row['created_at'] ?? '');
			if ($next_raw === '' || $created_raw === '') {
				continue;
			}

			try {
				$next_utc = self::convert_queue_datetime_wp_tz_to_utc($next_raw, $wp_tz, $utc_tz);
				$created_utc = self::convert_queue_datetime_wp_tz_to_utc($created_raw, $wp_tz, $utc_tz);
			} catch (\Throwable $e) {
				PP::log(['LocalQueue::migrate_enqueue_rows_to_utc', 'id' => $id, 'err' => $e->getMessage()]);
				continue;
			}

			if ($next_utc === $next_raw && $created_utc === $created_raw) {
				continue;
			}

			$wpdb->update(
				$t,
				[
					'next_attempt_at' => $next_utc,
					'created_at' => $created_utc,
				],
				['id' => $id],
				['%s', '%s'],
				['%d']
			);
			++$updated;
		}

		if ($updated > 0) {
			PP::log(['LocalQueue::migrate_enqueue_rows_to_utc', 'updated' => $updated]);
		}

		return $updated;
	}

	/**
	 * Register post-queue callback with ParrotPoster (no-op without API token).
	 */
	public static function request_post_queue_wake(): bool
	{
		if (empty(Options::token())) {
			return false;
		}

		$ok = Api::request_local_queue_wake();
		self::set_wake_pending(!$ok);

		return $ok;
	}

	private static function convert_queue_datetime_wp_tz_to_utc(
		string $value,
		\DateTimeZone $wp_tz,
		\DateTimeZone $utc_tz
	): string {
		$dt = new \DateTimeImmutable($value, $wp_tz);

		return $dt->setTimezone($utc_tz)->format('Y-m-d H:i:s');
	}

	/**
	 * Dev preview: one pending update row for admin UI testing.
	 * Enable with define('PARROTPOSTER_LQ_TEST_SEED', true); in wp-config.php.
	 */
	public static function seed_test_record_for_admin_ui(): void
	{
		$wp_post_id = self::resolve_test_wp_post_id();
		self::enqueue_pending_upsert(
			$wp_post_id,
			self::OP_UPDATE,
			wp_json_encode(
				[
					'_pp_admin_ui_test' => true,
					'note' => 'Test local queue row for ParrotPoster admin preview',
				],
				JSON_UNESCAPED_UNICODE
			) ?: '{}',
			true
		);
	}

	/**
	 * Called on admin_init when PARROTPOSTER_LQ_TEST_SEED is enabled.
	 */
	public static function maybe_seed_test_record(): void
	{
		if (!Env::local_queue_test_seed()) {
			return;
		}
		if (!is_admin() || !current_user_can('manage_options')) {
			return;
		}
		if (!PP::is_parrotposter_admin_context()) {
			return;
		}

		self::seed_test_record_for_admin_ui();
	}

	private static function resolve_test_wp_post_id(): int
	{
		$posts = get_posts(
			[
				'post_status' => 'publish',
				'posts_per_page' => 1,
				'orderby' => 'ID',
				'order' => 'DESC',
				'post_type' => 'any',
			]
		);
		if (!empty($posts[0]) && $posts[0] instanceof \WP_Post) {
			return (int) $posts[0]->ID;
		}

		return 1;
	}

	private static function schedule_wake_on_shutdown(): void
	{
		if (self::$pp_wake_shutdown_registered) {
			return;
		}
		self::$pp_wake_shutdown_registered = true;
		register_shutdown_function([__CLASS__, 'run_wake_on_shutdown']);
	}

	public static function run_wake_on_shutdown(): void
	{
		if (empty(Options::token())) {
			return;
		}

		$ok = Api::request_local_queue_wake();
		PP::log(['LocalQueue::run_wake_on_shutdown', 'ok' => $ok]);
		self::set_wake_pending(!$ok);
	}

	private static function set_wake_pending(bool $on): void
	{
		update_option(self::OPTION_WAKE_PENDING, $on ? '1' : '0', false);
	}

	private static function is_wake_pending(): bool
	{
		return get_option(self::OPTION_WAKE_PENDING, '0') === '1';
	}
}
