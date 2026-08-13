#!/usr/bin/env php
<?php

/**
 * Smoke tests for WP-04: contract helpers, scope_filter, changed_fields filtering.
 *
 * Usage: php bin/test-wp04-contract.php
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

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, $options = 0, $depth = 512)
	{
		return json_encode($data, $options, $depth);
	}
}

require_once dirname(__DIR__) . '/src/Settings.php';
require_once dirname(__DIR__) . '/src/ExpressionEval.php';
require_once dirname(__DIR__) . '/src/PushEventService.php';

use parrotposter\ExpressionEval;
use parrotposter\PushEventService;
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

// scope_filter from nested source_filters
$contract = [
	'source_filters' => [
		'scope_filter' => [
			'kind' => 'compare',
			'field' => 'post_type',
			'op' => 'eq',
			'value' => 'post',
		],
	],
];
assert_eq(
	'resolve_scope_filter from source_filters',
	$contract['source_filters']['scope_filter'],
	Settings::resolve_scope_filter($contract)
);

assert_eq(
	'resolve_template_required_fields',
	['title', 'content'],
	Settings::resolve_template_required_fields([
		'template_required_fields' => ['title', 'content', 'title'],
	])
);

$item_post = ['post_type' => 'post', 'title' => 'Hello'];
$item_page = ['post_type' => 'page', 'title' => 'Hello'];
$scope = Settings::resolve_scope_filter($contract);
assert_true('scope_filter passes matching post_type', ExpressionEval::evaluate($scope, $item_post));
assert_true('scope_filter rejects other post_type', !ExpressionEval::evaluate($scope, $item_page));

assert_eq(
	'filter_changed_fields_by_template intersects',
	['title'],
	PushEventService::filter_changed_fields_by_template(
		['title', 'status'],
		['template_required_fields' => ['title', 'content']]
	)
);

assert_eq(
	'filter_changed_fields_by_template empty when no overlap',
	[],
	PushEventService::filter_changed_fields_by_template(
		['status'],
		['template_required_fields' => ['title']]
	)
);

assert_eq(
	'filter_changed_fields_by_template passthrough when contract has no template fields',
	['title', 'status'],
	PushEventService::filter_changed_fields_by_template(['title', 'status'], [])
);

// notify_contract sends scope only under source_filters; a second notify must refresh
// top-level scope_filter (stale top-level used to win forever).
$scope_post = [
	'kind' => 'compare',
	'field' => 'post_type',
	'op' => 'eq',
	'value' => 'post',
];
$scope_page = [
	'kind' => 'compare',
	'field' => 'post_type',
	'op' => 'eq',
	'value' => 'page',
];
Settings::apply_pipeline_contract_snapshot([
	'pipeline_id' => 'pipe-1',
	'contract_version' => 1,
	'required_fields' => ['title'],
	'source_path' => [['key' => 'post_type', 'value' => 'post']],
	'source_filters' => ['scope_filter' => $scope_post],
]);
$stored = Settings::get_pipeline_contract('pipe-1');
assert_eq('first notify caches nested scope as top-level', $scope_post, $stored['scope_filter'] ?? null);

Settings::apply_pipeline_contract_snapshot([
	'pipeline_id' => 'pipe-1',
	'contract_version' => 2,
	'required_fields' => ['title', 'content'],
	'source_path' => [['key' => 'post_type', 'value' => 'page']],
	'source_filters' => ['scope_filter' => $scope_page],
]);
$stored = Settings::get_pipeline_contract('pipe-1');
assert_eq('second notify refreshes stale top-level scope_filter', $scope_page, $stored['scope_filter'] ?? null);
assert_eq('second notify bumps contract_version', 2, (int) ($stored['contract_version'] ?? 0));
assert_eq(
	'resolve uses refreshed scope_filter',
	$scope_page,
	Settings::resolve_scope_filter($stored)
);

// Taxonomy / set ops (Content Rules select parity with legacy scheduler term IDs)
$taxonomy_filter = [
	'kind' => 'compare',
	'field' => 'category',
	'op' => 'includes_any',
	'value' => ['12', '34'],
];
assert_true(
	'includes_any matches overlapping term ids',
	ExpressionEval::evaluate($taxonomy_filter, ['category' => ['12', '99']])
);
assert_true(
	'includes_any rejects non-overlapping term ids',
	!ExpressionEval::evaluate($taxonomy_filter, ['category' => ['99']])
);
assert_true(
	'includes_all requires every selected id',
	ExpressionEval::evaluate(
		['kind' => 'compare', 'field' => 'category', 'op' => 'includes_all', 'value' => ['12', '34']],
		['category' => ['12', '34', '56']]
	)
);
assert_true(
	'includes_all fails when one id missing',
	!ExpressionEval::evaluate(
		['kind' => 'compare', 'field' => 'category', 'op' => 'includes_all', 'value' => ['12', '34']],
		['category' => ['12']]
	)
);
assert_true(
	'excludes_any passes when no overlap',
	ExpressionEval::evaluate(
		['kind' => 'compare', 'field' => 'category', 'op' => 'excludes_any', 'value' => ['12']],
		['category' => ['99']]
	)
);
assert_true(
	'excludes_any fails on overlap',
	!ExpressionEval::evaluate(
		['kind' => 'compare', 'field' => 'category', 'op' => 'excludes_any', 'value' => ['12']],
		['category' => ['12', '99']]
	)
);
assert_true(
	'is_empty true for empty taxonomy array',
	ExpressionEval::evaluate(
		['kind' => 'compare', 'field' => 'category', 'op' => 'is_empty'],
		['category' => []]
	)
);
assert_true(
	'is_set true when taxonomy has terms',
	ExpressionEval::evaluate(
		['kind' => 'compare', 'field' => 'category', 'op' => 'is_set'],
		['category' => ['12']]
	)
);
assert_true(
	'missing taxonomy field is_empty',
	ExpressionEval::evaluate(
		['kind' => 'compare', 'field' => 'category', 'op' => 'is_empty'],
		[]
	)
);

// remove_pipeline_id drops id + contract; leaves siblings intact
Settings::set_pipeline_ids([
	'11111111-1111-1111-1111-111111111111',
	'22222222-2222-2222-2222-222222222222',
]);
Settings::set_pipeline_contract('11111111-1111-1111-1111-111111111111', [
	'contract_version' => 3,
	'required_fields' => ['title'],
]);
Settings::set_pipeline_contract('22222222-2222-2222-2222-222222222222', [
	'contract_version' => 1,
	'required_fields' => ['link'],
]);
Settings::remove_pipeline_id('11111111-1111-1111-1111-111111111111');
assert_eq(
	'remove_pipeline_id drops id from list',
	['22222222-2222-2222-2222-222222222222'],
	Settings::get_pipeline_ids()
);
assert_eq(
	'remove_pipeline_id drops contract snapshot',
	null,
	Settings::get_pipeline_contract('11111111-1111-1111-1111-111111111111')
);
assert_eq(
	'remove_pipeline_id keeps sibling contract',
	1,
	(int) (Settings::get_pipeline_contract('22222222-2222-2222-2222-222222222222')['contract_version'] ?? 0)
);
Settings::remove_pipeline_id('11111111-1111-1111-1111-111111111111');
assert_eq(
	'remove_pipeline_id is idempotent',
	['22222222-2222-2222-2222-222222222222'],
	Settings::get_pipeline_ids()
);

echo "\nAll WP-04 smoke tests passed.\n";
