#!/usr/bin/env php
<?php

/**
 * Smoke tests: maybe_auto_bind gates (connected / token / throttle / skip / inflight lock).
 *
 * Usage: php bin/test-wp15-auto-bind.php
 */

namespace parrotposter {
	class Api
	{
		public static function create_plugin_auth_code($domain, $callback_url, $plugin_version = null)
		{
			return ['error' => ['msg' => 'stub_no_graphql']];
		}

		public static function complete_plugin_binding($code, $domain, $callback_url, $plugin_version = null)
		{
			return ['error' => ['msg' => 'stub_no_graphql']];
		}

		public static function plugin_status($plugin_id)
		{
			return ['error' => ['msg' => 'stub_no_graphql']];
		}
	}
}

namespace {
	define('ABSPATH', true);
	define('PARROTPOSTER_TEST_BIND_LOCK_WAIT_SEC', 0);

	/** @var array<string, mixed> */
	$GLOBALS['pp_test_options'] = [];
	/** @var array<string, mixed> */
	$GLOBALS['pp_test_transients'] = [];

	if (!function_exists('get_option')) {
		function get_option($key, $default = false)
		{
			return array_key_exists($key, $GLOBALS['pp_test_options'])
				? $GLOBALS['pp_test_options'][$key]
				: $default;
		}
	}

	if (!function_exists('add_option')) {
		function add_option($key, $value, $deprecated = '', $autoload = true)
		{
			if (array_key_exists($key, $GLOBALS['pp_test_options'])) {
				return false;
			}
			$GLOBALS['pp_test_options'][$key] = $value;

			return true;
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

	if (!function_exists('get_transient')) {
		function get_transient($key)
		{
			return array_key_exists($key, $GLOBALS['pp_test_transients'])
				? $GLOBALS['pp_test_transients'][$key]
				: false;
		}
	}

	if (!function_exists('set_transient')) {
		function set_transient($key, $value, $expiration = 0)
		{
			$GLOBALS['pp_test_transients'][$key] = $value;

			return true;
		}
	}

	if (!function_exists('delete_transient')) {
		function delete_transient($key)
		{
			unset($GLOBALS['pp_test_transients'][$key]);

			return true;
		}
	}

	if (!function_exists('rest_url')) {
		function rest_url($path = '')
		{
			return 'https://example.test/wp-json/' . ltrim((string) $path, '/');
		}
	}

	require_once dirname(__DIR__) . '/src/Options.php';
	require_once dirname(__DIR__) . '/src/Settings.php';
	require_once dirname(__DIR__) . '/src/PluginConnect.php';

	use parrotposter\PluginConnect;
	use parrotposter\Settings;

	function assert_true(string $name, bool $condition): void
	{
		if (!$condition) {
			fwrite(STDERR, "FAIL: {$name}\n");
			exit(1);
		}
		echo "OK: {$name}\n";
	}

	function reset_state(): void
	{
		$GLOBALS['pp_test_options'] = [];
		$GLOBALS['pp_test_transients'] = [];
	}

	function seed_credentials(): void
	{
		update_option('parrotposter_user_id', '534644f6-c30f-46f0-ab44-cd240ab4acd4');
		update_option('parrotposter_token', 'test-token');
	}

	function seed_local_bind(): void
	{
		Settings::set_plugin_id('plugin-test-id');
		$GLOBALS['pp_test_options']['parrotposter_site_to_pp'] = 'pp_plg_test_secret';
	}

	reset_state();
	assert_true('empty install does not auto-bind', PluginConnect::should_attempt_auto_bind() === false);
	PluginConnect::maybe_auto_bind();
	assert_true('empty install leaves throttle unset', get_transient(PluginConnect::BIND_RETRY_TRANSIENT) === false);

	reset_state();
	seed_credentials();
	assert_true('logged-in unconnected site should auto-bind', PluginConnect::should_attempt_auto_bind() === true);

	reset_state();
	seed_credentials();
	seed_local_bind();
	Settings::disconnect();
	assert_true('disconnect sets skip_auto_bind', Settings::skip_auto_bind() === true);
	assert_true('intentional disconnect does not auto-bind', PluginConnect::should_attempt_auto_bind() === false);
	PluginConnect::maybe_auto_bind();
	assert_true('disconnect skip does not set throttle', get_transient(PluginConnect::BIND_RETRY_TRANSIENT) === false);

	reset_state();
	seed_credentials();
	seed_local_bind();
	Settings::disconnect();
	assert_true('skip is set before wipe-before-rebind', Settings::skip_auto_bind() === true);
	Settings::disconnect(false);
	assert_true('disconnect(false) clears skip_auto_bind', Settings::skip_auto_bind() === false);
	assert_true('wipe-before-rebind restores auto-bind', PluginConnect::should_attempt_auto_bind() === true);

	reset_state();
	seed_credentials();
	seed_local_bind();
	Settings::set_migration_mode(Settings::MIGRATION_MODE_PIPELINE);
	Settings::set_pipeline_ids(['pipe-migrated', 'pipe-wizard']);
	Settings::disconnect(false);
	assert_true('wipe-before-rebind keeps pipeline mode', Settings::get_migration_mode() === Settings::MIGRATION_MODE_PIPELINE);
	assert_true(
		'wipe-before-rebind keeps pipeline ids',
		Settings::get_pipeline_ids() === ['pipe-migrated', 'pipe-wizard']
	);

	reset_state();
	seed_credentials();
	seed_local_bind();
	Settings::set_migration_mode(Settings::MIGRATION_MODE_PIPELINE);
	Settings::set_pipeline_ids(['pipe-migrated']);
	Settings::disconnect();
	assert_true('intentional disconnect resets pipeline mode', Settings::get_migration_mode() === Settings::MIGRATION_MODE_LEGACY);
	assert_true('intentional disconnect clears pipeline ids', Settings::get_pipeline_ids() === []);

	reset_state();
	seed_credentials();
	Settings::disconnect();
	Settings::clear_skip_auto_bind();
	assert_true('clearing skip restores auto-bind for never-bound retry', PluginConnect::should_attempt_auto_bind() === true);

	reset_state();
	seed_credentials();
	seed_local_bind();
	assert_true('already connected does not auto-bind', PluginConnect::should_attempt_auto_bind() === false);
	PluginConnect::maybe_auto_bind();
	assert_true('connected skip does not set throttle', get_transient(PluginConnect::BIND_RETRY_TRANSIENT) === false);

	reset_state();
	seed_credentials();
	set_transient(PluginConnect::BIND_RETRY_TRANSIENT, 1, PluginConnect::BIND_RETRY_TTL);
	assert_true('throttle blocks a second attempt', PluginConnect::should_attempt_auto_bind() === false);
	PluginConnect::maybe_auto_bind();
	assert_true('throttled maybe_auto_bind is a no-op', Settings::is_connected() === false);

	reset_state();
	seed_credentials();
	update_option(PluginConnect::BIND_INFLIGHT_OPTION, time());
	$bind = PluginConnect::silent_bind(false);
	assert_true('fresh inflight returns bind_in_progress', ($bind['error'] ?? '') === 'bind_in_progress');
	assert_true('fresh inflight does not connect', Settings::is_connected() === false);
	assert_true('fresh inflight keeps lock', get_option(PluginConnect::BIND_INFLIGHT_OPTION, false) !== false);

	reset_state();
	seed_credentials();
	update_option(PluginConnect::BIND_INFLIGHT_OPTION, time() - PluginConnect::BIND_INFLIGHT_TTL_SEC - 30);
	$bind = PluginConnect::silent_bind(false);
	assert_true('stale inflight is stolen not bind_in_progress', ($bind['error'] ?? '') === 'stub_no_graphql');
	assert_true('stale inflight lock released after attempt', get_option(PluginConnect::BIND_INFLIGHT_OPTION, false) === false);
	assert_true('stale inflight did not persist secrets', Settings::is_connected() === false);

	reset_state();
	seed_credentials();
	Settings::disconnect();
	assert_true('reconnect start sees skip from disconnect', Settings::skip_auto_bind() === true);
	Settings::clear_skip_auto_bind();
	Settings::disconnect(false);
	$bind = PluginConnect::silent_bind(false);
	assert_true('failed reconnect reports graphql error', ($bind['error'] ?? '') === 'stub_no_graphql');
	assert_true('failed reconnect clears skip for hourly retry', Settings::skip_auto_bind() === false);
	assert_true('failed reconnect still allows auto-bind', PluginConnect::should_attempt_auto_bind() === true);

	reset_state();
	seed_credentials();
	seed_local_bind();
	Settings::set_callback_url('http://old.example/wp-json/parrotposter/v1');
	assert_true('stored callback mismatch is stale', Settings::callback_url_is_stale() === true);
	Settings::disconnect();
	assert_true('disconnect drops stored callback url', Settings::callback_url() === '');

	reset_state();
	seed_local_bind();
	assert_true('empty stored callback is not stale', Settings::callback_url_is_stale() === false);
	PluginConnect::apply_remote_status_result([
		'data' => [
			'plugin' => [
				'id' => 'plugin-test-id',
				'status' => 'ACTIVE',
				'callbackUrl' => 'http://old.example/wp-json/parrotposter/v1',
			],
		],
	]);
	assert_true(
		'status sync backfills stored callback from PP',
		Settings::callback_url() === 'http://old.example/wp-json/parrotposter/v1'
	);
	assert_true('backfilled http vs https rest_url is stale', Settings::callback_url_is_stale() === true);
	assert_true('ACTIVE backfill keeps local bind', Settings::is_connected() === true);

	reset_state();
	seed_credentials();
	seed_local_bind();
	PluginConnect::maybe_sync_remote_status();
	assert_true('connected status sync sets throttle', get_transient(PluginConnect::STATUS_SYNC_TRANSIENT) !== false);
	assert_true('status sync fail-open keeps bind', Settings::is_connected() === true);

	reset_state();
	seed_credentials();
	PluginConnect::maybe_sync_remote_status();
	assert_true('unconnected status sync does not throttle', get_transient(PluginConnect::STATUS_SYNC_TRANSIENT) === false);

	$acquire = new \ReflectionMethod(PluginConnect::class, 'try_acquire_bind_lock');
	$acquire->setAccessible(true);
	$release = new \ReflectionMethod(PluginConnect::class, 'release_bind_lock');
	$release->setAccessible(true);

	reset_state();
	$token_a = $acquire->invoke(null);
	assert_true('first lock acquire returns owner token', is_string($token_a) && $token_a !== '');
	$token_b = $acquire->invoke(null);
	assert_true('live lock is not stolen', $token_b === null);
	$release->invoke(null, 'not-the-owner');
	assert_true('foreign release leaves lock', get_option(PluginConnect::BIND_INFLIGHT_OPTION, false) !== false);
	$release->invoke(null, $token_a);
	assert_true('owner release deletes lock', get_option(PluginConnect::BIND_INFLIGHT_OPTION, false) === false);

	reset_state();
	update_option(PluginConnect::BIND_INFLIGHT_OPTION, [
		't' => time() - PluginConnect::BIND_INFLIGHT_TTL_SEC - 30,
		'o' => 'old-owner',
	]);
	$token_c = $acquire->invoke(null);
	assert_true('stale lock steal returns new owner token', is_string($token_c) && $token_c !== '');
	$stolen = get_option(PluginConnect::BIND_INFLIGHT_OPTION, false);
	assert_true('stolen lock stores owner token', is_array($stolen) && ($stolen['o'] ?? '') === $token_c);
	$release->invoke(null, 'old-owner');
	assert_true('previous owner cannot release stolen lock', get_option(PluginConnect::BIND_INFLIGHT_OPTION, false) !== false);
	$release->invoke(null, $token_c);
	assert_true('new owner releases stolen lock', get_option(PluginConnect::BIND_INFLIGHT_OPTION, false) === false);

	echo "ALL OK\n";
}
