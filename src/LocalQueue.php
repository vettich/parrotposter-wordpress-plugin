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

	private const BATCH_LIMIT = 10;

	private const FAILED_PURGE_BATCH = 200;

	public const OPTION_WAKE_PENDING = 'parrotposter_lq_wake_pending';

	private static $pp_wake_shutdown_registered = false;

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
				WHERE status = %s AND (locked_until IS NULL OR locked_until < NOW())",
				self::STATUS_PENDING,
				self::STATUS_PROCESSING
			)
		);
	}

	/**
	 * @return int[]
	 */
	private static function claim_pending_batch_ids(): array
	{
		global $wpdb;

		$t = self::table();
		$ids = [];

		$wpdb->query('START TRANSACTION');
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$t}
				WHERE status = %s AND next_attempt_at <= NOW()
				ORDER BY id ASC
				LIMIT %d
				FOR UPDATE",
				self::STATUS_PENDING,
				self::BATCH_LIMIT
			)
		);
		if (!is_array($rows)) {
			$rows = [];
		}
		$ids = array_map('intval', $rows);
		if (!empty($ids)) {
			$in = implode(',', $ids);
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$t} SET status = %s, locked_until = DATE_ADD(NOW(), INTERVAL %d SECOND) WHERE id IN ({$in})",
					self::STATUS_PROCESSING,
					self::LOCK_LEASE_SECONDS
				)
			);
		}
		$wpdb->query('COMMIT');

		return $ids;
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
		$now = current_time('mysql');

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
	public static function handle_http_process(): array
	{
		$processed = self::process_pending_batch();
		$has_more = self::has_pending_ready();

		if ($processed > 0) {
			self::set_wake_pending(false);
		}

		if ($has_more && !empty(Options::token())) {
			$chain_ok = Api::request_local_queue_wake();
			if (!$chain_ok) {
				self::set_wake_pending(true);
			}
		}

		PP::log(['LocalQueue::handle_http_process', 'processed' => $processed, 'has_more' => $has_more]);

		return [
			'ok' => true,
			'processed' => $processed,
			'has_more' => $has_more,
		];
	}

	private static function has_pending_ready(): bool
	{
		global $wpdb;

		$t = self::table();
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE status = %s AND next_attempt_at <= NOW() ORDER BY id ASC LIMIT 1",
				self::STATUS_PENDING
			)
		);

		return (int) $id > 0;
	}

	private static function process_pending_batch(): int
	{
		self::recover_stale_processing();

		$ids = self::claim_pending_batch_ids();
		if (empty($ids)) {
			return 0;
		}

		global $wpdb;

		$t = self::table();
		$in = implode(',', array_map('intval', $ids));
		$rows = $wpdb->get_results("SELECT * FROM {$t} WHERE id IN ({$in}) ORDER BY id ASC", ARRAY_A);
		if (!is_array($rows)) {
			$rows = [];
		}

		$processed = 0;
		foreach ($rows as $row) {
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
					continue;
				}
				$wpdb->delete($t, ['id' => $id], ['%d']);
				++$processed;
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
			}
		}

		return $processed;
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
