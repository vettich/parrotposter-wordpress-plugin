#!/usr/bin/env php
<?php

/**
 * Smoke tests for WP-05: SelectionFilterQuery compile + sort helpers (no live WP DB).
 *
 * Usage: php bin/test-wp05-fetch-next.php
 */

define('ABSPATH', true);

if (!class_exists('WP_Error')) {
	class WP_Error
	{
		/** @var string */
		public $code;
		/** @var string */
		public $message;
		/** @var array<string, mixed> */
		public $data;

		public function __construct($code = '', $message = '', $data = [])
		{
			$this->code = (string) $code;
			$this->message = (string) $message;
			$this->data = is_array($data) ? $data : [];
		}

		public function get_error_code()
		{
			return $this->code;
		}

		public function get_error_message()
		{
			return $this->message;
		}
	}
}

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing)
	{
		return $thing instanceof WP_Error;
	}
}

if (!function_exists('taxonomy_exists')) {
	function taxonomy_exists($taxonomy)
	{
		return in_array((string) $taxonomy, ['category', 'post_tag'], true);
	}
}

require_once dirname(__DIR__) . '/src/SelectionFilterQuery.php';

use parrotposter\SelectionFilterQuery;

function assert_true(string $name, bool $condition): void
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$name}\n");
		exit(1);
	}
	echo "OK: {$name}\n";
}

$caps = SelectionFilterQuery::filter_capabilities();
assert_true('compare_ops includes includes_any', in_array('includes_any', $caps['compare_ops'], true));
assert_true('sortable includes date', in_array('date', $caps['sortable_fields'], true));

$query = [
	'post_type' => 'post',
	'posts_per_page' => 1,
];
$ok = SelectionFilterQuery::apply($query, null, 'post');
assert_true('null filter ok', $ok === true);

$query = ['post_type' => 'post', 'posts_per_page' => 1];
$ok = SelectionFilterQuery::apply($query, [
	'kind' => 'compare',
	'field' => 'category',
	'op' => 'includes_any',
	'value' => ['42', '7'],
], 'post');
assert_true('includes_any category compiles', $ok === true);
assert_true(
	'tax_query wrapped for WP_Tax_Query',
	isset($query['tax_query'][0]['taxonomy']) && $query['tax_query'][0]['taxonomy'] === 'category'
);
assert_true('tax terms', $query['tax_query'][0]['terms'] === [42, 7]);
assert_true('tax operator IN', ($query['tax_query'][0]['operator'] ?? '') === 'IN');

$query = ['post_type' => 'post', 'posts_per_page' => 1];
$ok = SelectionFilterQuery::apply($query, [
	'kind' => 'compare',
	'field' => 'post_tag',
	'op' => 'includes_any',
	'value' => ['3'],
], 'post');
assert_true('includes_any post_tag compiles', $ok === true);
assert_true(
	'post_tag tax_query wrapped',
	isset($query['tax_query'][0]['taxonomy']) && $query['tax_query'][0]['taxonomy'] === 'post_tag'
);
assert_true('post_tag terms', $query['tax_query'][0]['terms'] === [3]);

$query = ['post_type' => 'post', 'posts_per_page' => 1];
$ok = SelectionFilterQuery::apply($query, [
	'kind' => 'and',
	'children' => [
		[
			'kind' => 'compare',
			'field' => 'category',
			'op' => 'includes_any',
			'value' => [1],
		],
		[
			'kind' => 'compare',
			'field' => 'post_tag',
			'op' => 'includes_any',
			'value' => [3],
		],
	],
], 'post');
assert_true('and two taxonomies compiles', $ok === true);
assert_true('and tax relation AND', ($query['tax_query']['relation'] ?? '') === 'AND');
assert_true('and tax has two children', isset($query['tax_query'][0], $query['tax_query'][1]));

$query = ['post_type' => 'post', 'posts_per_page' => 1];
$err = SelectionFilterQuery::apply($query, [
	'kind' => 'compare',
	'field' => 'title',
	'op' => 'contains',
	'value' => 'x',
], 'post');
assert_true('unsupported op fail-closed', is_wp_error($err) && $err->get_error_code() === 'unsupported_selection_filter');
assert_true('unsupported status 422', ($err->data['status'] ?? 0) === 422);

$query = ['post_type' => 'post', 'posts_per_page' => 1];
$ok = SelectionFilterQuery::apply($query, [
	'kind' => 'and',
	'children' => [
		[
			'kind' => 'compare',
			'field' => 'category',
			'op' => 'includes_any',
			'value' => [1],
		],
		[
			'kind' => 'compare',
			'field' => 'title',
			'op' => 'eq',
			'value' => 'Hello',
		],
	],
], 'post');
assert_true('and category+title compiles', $ok === true);
assert_true('where clauses present', !empty($query['_pp_where_clauses']));

$query = ['post_type' => 'post', 'posts_per_page' => 1];
$ok = SelectionFilterQuery::apply($query, [
	'kind' => 'compare',
	'field' => 'post_type',
	'op' => 'eq',
	'value' => 'page',
], 'post');
assert_true('contradictory post_type forces empty', $ok === true && isset($query['post__in']) && $query['post__in'] === [0]);

echo "All WP-05 smoke tests passed.\n";
