#!/usr/bin/env php
<?php

/**
 * Smoke tests: remote disable sync (wire disconnect + settings-page status apply).
 *
 * Usage: php bin/test-wp14-disconnect-sync.php
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

function reset_options(): void
{
	$GLOBALS['pp_test_options'] = [];
}

function seed_local_bind(): void
{
	Settings::set_plugin_id('plugin-test-id');
	$GLOBALS['pp_test_options']['parrotposter_site_to_pp'] = 'pp_plg_test_secret';
}

reset_options();
seed_local_bind();
assert_true('seeded bind looks connected', Settings::is_connected());

Settings::disconnect();
assert_true('wire disconnect handler path clears plugin_id', Settings::plugin_id() === '');
assert_true('wire disconnect handler path is no longer connected', Settings::is_connected() === false);

reset_options();
seed_local_bind();
Settings::set_migration_mode(Settings::MIGRATION_MODE_PIPELINE);
Settings::set_pipeline_ids(['pipe-a']);
$cleared = PluginConnect::apply_remote_status_result([
	'data' => ['plugin' => ['id' => 'plugin-test-id', 'status' => 'DISABLED']],
]);
assert_true('DISABLED remote status clears local bind', $cleared === true);
assert_true('DISABLED remote status empties plugin_id', Settings::plugin_id() === '');
assert_true('DISABLED remote status resets pipeline mode', Settings::get_migration_mode() === Settings::MIGRATION_MODE_LEGACY);
assert_true('DISABLED remote status clears pipeline ids', Settings::get_pipeline_ids() === []);

reset_options();
seed_local_bind();
$cleared = PluginConnect::apply_remote_status_result([
	'data' => [
		'plugin' => [
			'id' => 'plugin-test-id',
			'status' => 'ACTIVE',
			'callbackUrl' => 'https://example.test/wp-json/parrotposter/v1',
		],
	],
]);
assert_true('ACTIVE remote status keeps local bind', $cleared === false);
assert_true('ACTIVE remote status keeps plugin_id', Settings::plugin_id() === 'plugin-test-id');
assert_true(
	'ACTIVE remote status backfills callback url',
	Settings::callback_url() === 'https://example.test/wp-json/parrotposter/v1'
);

reset_options();
seed_local_bind();
$cleared = PluginConnect::apply_remote_status_result([
	'data' => ['plugin' => null],
]);
assert_true('missing plugin row clears local bind', $cleared === true);
assert_true('missing plugin row empties plugin_id', Settings::plugin_id() === '');

reset_options();
seed_local_bind();
$cleared = PluginConnect::apply_remote_status_result([
	'error' => ['msg' => 'server is unavailable', 'code' => -11],
]);
assert_true('network error is fail-open (does not clear)', $cleared === false);
assert_true('network error keeps plugin_id', Settings::plugin_id() === 'plugin-test-id');

echo "ALL OK\n";
