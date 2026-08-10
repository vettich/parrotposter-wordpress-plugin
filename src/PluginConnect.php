<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Plugin site binding (auth_code → machine secrets) and post-bind pipeline bootstrap.
 */
class PluginConnect
{
	public static function init(): void
	{
		add_action('admin_post_parrotposter_connect_disconnect', [self::class, 'handle_disconnect']);
	}

	/**
	 * Server-to-server bind: createPluginAuthCode + completePluginBinding (no browser redirect).
	 *
	 * @return array{ok: true}|array{error: string}
	 */
	public static function silent_bind(): array
	{
		if (Settings::is_connected()) {
			return ['ok' => true];
		}

		if (Options::token() === '') {
			return ['error' => 'user_token_empty'];
		}

		// A stale rewrite cache (e.g. after a site migration/restore, or a
		// permalink change that never got re-saved) makes /wp-json/* 404 at
		// the webserver level even though WordPress thinks pretty permalinks
		// are enabled. Resync before we hand out a callback URL that PP will
		// poll — this is what Settings > Permalinks > Save does under the hood.
		flush_rewrite_rules();

		if (!self::rest_routes_reachable()) {
			return ['error' => 'rest_routes_unreachable'];
		}

		$domain = home_url();
		$callback_url = rest_url('parrotposter/v1');
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
		// TASK-002-BE-52: initial signing key delivery — previously the only way this site could
		// ever learn a signing key was a rotate_secrets fallback task, and nothing created one of
		// those either. Uses the same setter WP-09 already built for the rotation path
		// (Settings::set_outbound_task_signing_public_key() correctly shifts any prior value into
		// the `_prev` slot rather than dropping it, per DEC-002-06 D4 — a no-op here on first
		// bind, since there is no prior value yet).
		if (isset($res['outbound_task_signing_public_key']) && is_string($res['outbound_task_signing_public_key'])) {
			Settings::set_outbound_task_signing_public_key($res['outbound_task_signing_public_key']);
		}

		self::maybe_auto_migrate_to_pipeline();

		return ['ok' => true];
	}

	/**
	 * Loopback-check the exact REST path back-app will poll (parrotposter/v1/pp/v1/info),
	 * not just wp-json root: a raw 404 here (text/html, from Apache/nginx) means the
	 * request never reached WordPress, as opposed to a REST-level error (JSON body),
	 * which still proves the route is dispatchable.
	 */
	private static function rest_routes_reachable(): bool
	{
		$check_url = rest_url('parrotposter/v1/pp/v1/info');
		$response = wp_remote_get($check_url, [
			'timeout' => 5,
			'redirection' => 0,
		]);

		if (is_wp_error($response)) {
			PP::log(['event' => 'rest_routes_unreachable', 'url' => $check_url, 'error' => $response->get_error_message()]);

			return false;
		}

		$content_type = wp_remote_retrieve_header($response, 'content-type');
		$reachable = is_string($content_type) && stripos($content_type, 'json') !== false;

		if (!$reachable) {
			PP::log([
				'event' => 'rest_routes_unreachable',
				'url' => $check_url,
				'status' => wp_remote_retrieve_response_code($response),
				'content_type' => $content_type,
			]);
		}

		return $reachable;
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
			__('Сайт отключён от автоматизации ParrotPoster.', 'parrotposter'),
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
