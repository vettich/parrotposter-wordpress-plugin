<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Plugin site binding (auth_code → machine secrets) and post-bind pipeline bootstrap.
 */
class PluginConnect
{
	public const BIND_RETRY_TRANSIENT = 'parrotposter_bind_retry';

	public const BIND_RETRY_TTL = 3600;

	public const STATUS_SYNC_TRANSIENT = 'parrotposter_status_sync';

	public const BIND_INFLIGHT_OPTION = 'parrotposter_bind_inflight';

	public const BIND_INFLIGHT_TTL_SEC = 120;

	/**
	 * How long {@see silent_bind()} waits on a live inflight lock for the other
	 * request to finish. Shorter than {@see BIND_INFLIGHT_TTL_SEC} (waiters do
	 * not sit until the lock is stealable). Smokes define
	 * PARROTPOSTER_TEST_BIND_LOCK_WAIT_SEC as 0 so they do not sleep.
	 */
	public const BIND_LOCK_WAIT_SEC = 25;

	private const BIND_LOCK_POLL_USEC = 250000;

	public static function init(): void
	{
		add_action('admin_post_parrotposter_connect_disconnect', [self::class, 'handle_disconnect']);
		add_action('admin_post_parrotposter_connect_reconnect', [self::class, 'handle_reconnect']);
	}

	/**
	 * Whether a background bind attempt should run (logged-in, not connected, throttle clear).
	 * Intentional disconnect/disable sets {@see Settings::skip_auto_bind()} and is skipped here;
	 * explicit {@see silent_bind()} (reconnect / OAuth) does not consult this gate.
	 */
	public static function should_attempt_auto_bind(): bool
	{
		if (Settings::is_connected()) {
			return false;
		}
		if (Settings::skip_auto_bind()) {
			return false;
		}
		if (Options::token() === '') {
			return false;
		}
		$uid = Options::user_id();
		if (!is_string($uid) || $uid === '') {
			return false;
		}

		return get_transient(self::BIND_RETRY_TRANSIENT) === false;
	}

	/**
	 * Retry site bind after 2.0.0 installs that swallowed a one-shot silent_bind failure.
	 * Throttled so admin page loads do not hammer GraphQL; first attempt is immediate.
	 */
	public static function maybe_auto_bind(): void
	{
		if (!self::should_attempt_auto_bind()) {
			return;
		}

		set_transient(self::BIND_RETRY_TRANSIENT, 1, self::BIND_RETRY_TTL);
		$bind = self::silent_bind(false);
		if (empty($bind['error'])) {
			delete_transient(self::BIND_RETRY_TRANSIENT);

			return;
		}

		PP::log(['event' => 'maybe_auto_bind_failed', 'error' => $bind['error']]);
	}

	/**
	 * Settings page plus throttled admin/cron: if PP says this plugin is no longer
	 * active, clear local bind. Network / PP errors are fail-open.
	 */
	public static function maybe_sync_remote_status(): void
	{
		if (!Settings::is_connected()) {
			return;
		}
		if (Options::token() === '') {
			return;
		}
		if (get_transient(self::STATUS_SYNC_TRANSIENT) !== false) {
			return;
		}

		set_transient(self::STATUS_SYNC_TRANSIENT, 1, self::BIND_RETRY_TTL);
		self::sync_remote_status();
	}

	/**
	 * Server-to-server bind: createPluginAuthCode + completePluginBinding (no browser redirect).
	 *
	 * @param bool $flush_rewrites When true (explicit reconnect / OAuth), resync permalinks
	 *     before handing PP a callback URL. Background {@see maybe_auto_bind()} passes false
	 *     so hourly cron/admin retries do not flush rewrite rules.
	 * @return array{ok: true}|array{error: string}
	 */
	public static function silent_bind(bool $flush_rewrites = true): array
	{
		if (Settings::is_connected()) {
			Settings::clear_skip_auto_bind();

			return ['ok' => true];
		}

		if (Options::token() === '') {
			return ['error' => 'user_token_empty'];
		}

		$lock_token = self::try_acquire_bind_lock();
		if ($lock_token === null) {
			$lock_token = self::wait_for_bind_lock();
			if ($lock_token === null) {
				if (Settings::is_connected()) {
					Settings::clear_skip_auto_bind();

					return ['ok' => true];
				}

				return ['error' => 'bind_in_progress'];
			}
		}

		try {
			if (Settings::is_connected()) {
				Settings::clear_skip_auto_bind();

				return ['ok' => true];
			}

			// A stale rewrite cache (e.g. after a site migration/restore, or a
			// permalink change that never got re-saved) makes /wp-json/* 404 at
			// the webserver level even though WordPress thinks pretty permalinks
			// are enabled. Resync before we hand out a callback URL that PP will
			// poll — this is what Settings > Permalinks > Save does under the hood.
			if ($flush_rewrites && function_exists('flush_rewrite_rules')) {
				flush_rewrite_rules();
			}

			self::log_rest_route_status();

			$domain = function_exists('home_url') ? home_url() : '';
			$callback_url = function_exists('rest_url') ? rest_url('parrotposter/v1') : '';
			$version = defined('PARROTPOSTER_VERSION') ? (string) PARROTPOSTER_VERSION : null;

			$auth_code = Api::create_plugin_auth_code($domain, $callback_url, $version);
			if (!empty($auth_code['error'])) {
				$msg = isset($auth_code['error']['msg']) ? (string) $auth_code['error']['msg'] : 'create_plugin_auth_code failed';

				return ['error' => $msg];
			}

			$code = isset($auth_code['code']) ? (string) $auth_code['code'] : '';
			if ($code === '') {
				return ['error' => 'empty_auth_code'];
			}

			$res = Api::complete_plugin_binding($code, $domain, $callback_url, $version);
			if (!empty($res['error'])) {
				$msg = isset($res['error']['msg']) ? (string) $res['error']['msg'] : 'complete_plugin_binding failed';

				return ['error' => $msg];
			}

			Settings::set_plugin_id($res['plugin_id']);
			Settings::set_site_to_pp_secret($res['site_to_pp_secret']);
			Settings::set_pp_to_site_secret($res['pp_to_site_secret']);
			if (isset($res['migration_mode']) && is_string($res['migration_mode'])) {
				Settings::set_migration_mode(strtolower($res['migration_mode']));
			}
			if (!empty($res['pipeline_ids_from_migration']) && is_array($res['pipeline_ids_from_migration'])) {
				$ids = Settings::get_pipeline_ids();
				foreach ($res['pipeline_ids_from_migration'] as $id) {
					if (is_string($id) && trim($id) !== '') {
						$ids[] = trim($id);
					}
				}
				Settings::set_pipeline_ids($ids);
			}
			// TASK-002-BE-52: initial signing key delivery — previously the only way this site could
			// ever learn a signing key was a rotate_secrets fallback task, and nothing created one of
			// those either. Uses the same setter WP-09 already built for the rotation path
			// (Settings::set_outbound_task_signing_public_key() correctly shifts any prior value into
			// the `_prev` slot rather than dropping it, per DEC-002-06 D4 — a no-op here on first
			// bind, since there is no prior value yet).
			if (isset($res['outbound_task_signing_public_key']) && is_string($res['outbound_task_signing_public_key'])) {
				Settings::set_outbound_task_signing_public_key($res['outbound_task_signing_public_key']);
			}

			Settings::set_callback_url($callback_url);
			Settings::clear_skip_auto_bind();
			self::maybe_auto_migrate_to_pipeline();

			return ['ok' => true];
		} finally {
			self::release_bind_lock($lock_token);
		}
	}

	private static function bind_lock_wait_sec(): int
	{
		if (defined('PARROTPOSTER_TEST_BIND_LOCK_WAIT_SEC')) {
			return (int) PARROTPOSTER_TEST_BIND_LOCK_WAIT_SEC;
		}

		return self::BIND_LOCK_WAIT_SEC;
	}

	/**
	 * Poll until this request holds the inflight lock. Returns null on timeout or
	 * when the other request already finished ({@see Settings::is_connected()}).
	 * Does not delete another request's lock.
	 */
	private static function wait_for_bind_lock(): ?string
	{
		$deadline = time() + self::bind_lock_wait_sec();
		while (time() < $deadline) {
			if (Settings::is_connected()) {
				return null;
			}
			usleep(self::BIND_LOCK_POLL_USEC);
			$token = self::try_acquire_bind_lock();
			if ($token !== null) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Atomic INSERT lock so admin + cron cannot run two completePluginBinding calls at once.
	 * Stale locks older than {@see BIND_INFLIGHT_TTL_SEC} are stolen via delete+insert
	 * (not update_option). Returns the owner token, or null when another request holds a live lock.
	 */
	private static function try_acquire_bind_lock(): ?string
	{
		$now = time();
		$token = self::new_bind_lock_token();
		$payload = ['t' => $now, 'o' => $token];
		if (function_exists('add_option') && add_option(self::BIND_INFLIGHT_OPTION, $payload, '', false)) {
			return $token;
		}
		$started = self::bind_lock_started_at(get_option(self::BIND_INFLIGHT_OPTION, 0));
		if ($started > 0 && ($now - $started) < self::BIND_INFLIGHT_TTL_SEC) {
			return null;
		}
		delete_option(self::BIND_INFLIGHT_OPTION);
		if (function_exists('add_option') && add_option(self::BIND_INFLIGHT_OPTION, $payload, '', false)) {
			return $token;
		}

		return null;
	}

	private static function release_bind_lock(string $token): void
	{
		$current = get_option(self::BIND_INFLIGHT_OPTION, null);
		if (!is_array($current) || ($current['o'] ?? '') !== $token) {
			return;
		}
		delete_option(self::BIND_INFLIGHT_OPTION);
	}

	private static function new_bind_lock_token(): string
	{
		if (function_exists('wp_generate_uuid4')) {
			return wp_generate_uuid4();
		}

		return bin2hex(random_bytes(16));
	}

	/**
	 * @param mixed $stored
	 */
	private static function bind_lock_started_at($stored): int
	{
		if (is_array($stored) && isset($stored['t'])) {
			return (int) $stored['t'];
		}
		if (is_int($stored) || (is_string($stored) && is_numeric($stored))) {
			return (int) $stored;
		}

		return 0;
	}

	/**
	 * In-process check that parrotposter REST routes are registered. Does not
	 * HTTP-loopback to the public site URL (hairpin NAT / HTTP→HTTPS 301 used to
	 * abort bind before GraphQL). Failure is logged only — bind still proceeds
	 * because PP→CMS primary can fall back to outbound poll.
	 */
	private static function log_rest_route_status(): void
	{
		if (!function_exists('rest_do_request') || !class_exists('WP_REST_Request')) {
			return;
		}

		$request = new \WP_REST_Request('GET', '/parrotposter/v1/pp/v1/info');
		$response = rest_do_request($request);
		$status = method_exists($response, 'get_status') ? (int) $response->get_status() : 0;
		if ($status === 401 || $status === 200) {
			return;
		}

		PP::log(['event' => 'rest_routes_unexpected', 'status' => $status]);
	}

	/**
	 * If PP says this plugin is no longer active, clear local bind.
	 * Network / PP errors are fail-open (keep local secrets).
	 */
	public static function sync_remote_status(): void
	{
		if (!Settings::is_connected()) {
			return;
		}
		if (Options::token() === '') {
			return;
		}

		self::apply_remote_status_result(Api::plugin_status(Settings::plugin_id()));
	}

	/**
	 * Apply a `plugin(id)` GraphQL result to local bind state.
	 *
	 * @param array{data?: mixed, error?: mixed} $res
	 * @return bool true when local secrets were cleared
	 */
	public static function apply_remote_status_result(array $res): bool
	{
		if (!empty($res['error'])) {
			return false;
		}

		$plugin = is_array($res['data'] ?? null) ? ($res['data']['plugin'] ?? null) : null;
		if (!is_array($plugin)) {
			Settings::disconnect();

			return true;
		}

		$status = strtoupper((string) ($plugin['status'] ?? ''));
		if ($status !== 'ACTIVE') {
			Settings::disconnect();

			return true;
		}

		$callback = $plugin['callbackUrl'] ?? $plugin['callback_url'] ?? null;
		if (is_string($callback) && trim($callback) !== '') {
			Settings::set_callback_url($callback);
		}

		return false;
	}

	/**
	 * After bind: auto-switch to pipeline when no active legacy templates exist.
	 */
	public static function maybe_auto_migrate_to_pipeline(): void
	{
		if (Settings::get_migration_mode() !== Settings::MIGRATION_MODE_LEGACY) {
			return;
		}
		if (!Settings::is_connected()) {
			return;
		}
		if (Settings::has_enabled_legacy_templates()) {
			return;
		}

		$res = MigrationService::migrate_to_pipeline(null);
		if (empty($res['success'])) {
			PP::log(['event' => 'auto_migrate_to_pipeline_failed', 'error' => $res['error'] ?? 'unknown']);
		}
	}

	public static function handle_disconnect(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Forbidden', 'parrotposter'), '', ['response' => 403]);
		}

		$redirect_back = admin_url('admin.php?page=parrotposter_settings');
		$plugin_id = Settings::plugin_id();

		if ($plugin_id !== '') {
			Api::disable_plugin($plugin_id);
		}

		Settings::disconnect();

		self::redirect_with_notice(
			__('The site has been disconnected from ParrotPoster automation.', 'parrotposter'),
			'success',
			$redirect_back
		);
	}

	public static function handle_reconnect(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Forbidden', 'parrotposter'), '', ['response' => 403]);
		}
		if (!FormHelpers::check_post_nonce()) {
			wp_die(esc_html__('Forbidden', 'parrotposter'), '', ['response' => 403]);
		}

		$redirect_back = admin_url('admin.php?page=parrotposter_settings');
		Settings::clear_skip_auto_bind();
		Settings::disconnect(false);
		$bind = self::silent_bind();
		if (!empty($bind['error'])) {
			self::redirect_with_notice(
				__('Could not connect the site to ParrotPoster automation.', 'parrotposter'),
				'error',
				$redirect_back
			);
		}

		self::redirect_with_notice(
			__('The site is connected to ParrotPoster automation.', 'parrotposter'),
			'success',
			$redirect_back
		);
	}

	private static function redirect_with_notice(string $message, string $type, string $redirect_url): void
	{
		$key = $type === 'success' ? 'parrotposter_success_data' : 'parrotposter_error_msg';
		wp_safe_redirect(add_query_arg($key, $message, $redirect_url));
		exit;
	}
}
