#!/usr/bin/env php
<?php

/**
 * Smoke tests for WP-07: DigestBatchQuery (window/tiebreak/has_more) + SelectionFilterQuery
 * interaction for Digest fetch_batch — no live WP DB (SPEC-002-09 §7.3.3, DEC-002-01 D11/D13).
 *
 * Usage: php bin/test-wp07-fetch-batch.php
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

require_once dirname(__DIR__) . '/src/DigestBatchQuery.php';
require_once dirname(__DIR__) . '/src/SelectionFilterQuery.php';

use parrotposter\DigestBatchQuery;
use parrotposter\SelectionFilterQuery;

$failures = 0;

function assert_true(string $name, bool $condition): void
{
	global $failures;
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$name}\n");
		$failures++;
		return;
	}
	echo "OK: {$name}\n";
}

// ---------------------------------------------------------------------------
// 1. Window [from, to) is half-open: `after` inclusive=true, `before` inclusive=false.
// ---------------------------------------------------------------------------

$window_from = '2026-07-13T00:00:00+00:00';
$window_to = '2026-07-20T00:00:00+00:00';
$window_dq = DigestBatchQuery::build_window_date_query($window_from, $window_to);

assert_true('window date_query has AND relation', ($window_dq['relation'] ?? '') === 'AND');
assert_true('window has 2 clauses', count(array_filter(array_keys($window_dq), 'is_int')) === 2);

$after_clause = null;
$before_clause = null;
foreach ($window_dq as $key => $clause) {
	if (!is_int($key) || !is_array($clause)) {
		continue;
	}
	if (isset($clause['after'])) {
		$after_clause = $clause;
	}
	if (isset($clause['before'])) {
		$before_clause = $clause;
	}
}
assert_true('after clause present', $after_clause !== null);
assert_true('after = window_from', ($after_clause['after'] ?? null) === $window_from);
assert_true('after is inclusive (>= window_from)', ($after_clause['inclusive'] ?? null) === true);
assert_true('after column is post_date', ($after_clause['column'] ?? '') === 'post_date');

assert_true('before clause present', $before_clause !== null);
assert_true('before = window_to', ($before_clause['before'] ?? null) === $window_to);
assert_true(
	'before is EXCLUSIVE (< window_to) — a record exactly at window_to must be excluded',
	($before_clause['inclusive'] ?? null) === false
);
assert_true('before column is post_date', ($before_clause['column'] ?? '') === 'post_date');

// ---------------------------------------------------------------------------
// 2. merge_window_date_query: window AND-combines with a selection_filter date
//    clause instead of clobbering it (order-of-operations bug this task exists to avoid).
// ---------------------------------------------------------------------------

$query_args = ['post_type' => 'post'];
// No selection_filter date clause — window becomes date_query verbatim.
DigestBatchQuery::merge_window_date_query($query_args, $window_dq);
assert_true('merge without existing date_query sets window directly', $query_args['date_query'] === $window_dq);

$query_args = ['post_type' => 'post'];
$filter_ok = SelectionFilterQuery::apply($query_args, [
	'kind' => 'compare',
	'field' => 'date',
	'op' => 'eq',
	'value' => '2026-07-15T00:00:00+00:00',
], 'post');
assert_true('selection_filter date compare compiles', $filter_ok === true);
assert_true('selection_filter alone produced a date_query', !empty($query_args['date_query']));
$filter_date_query = $query_args['date_query'];

DigestBatchQuery::merge_window_date_query($query_args, $window_dq);
assert_true(
	'merge combines window + filter date_query via AND (neither clobbers the other)',
	($query_args['date_query']['relation'] ?? '') === 'AND'
	&& $query_args['date_query'][0] === $window_dq
	&& $query_args['date_query'][1] === $filter_date_query
);

// ---------------------------------------------------------------------------
// 3. D13 tiebreak: orderby always carries a secondary ID DESC key so that two
//    posts sharing the same primary sort value can't swap position between pages
//    (100/page, 3 pages, all same post_date -> would lose/duplicate a row without this).
// ---------------------------------------------------------------------------

$query_args = ['post_type' => 'post'];
DigestBatchQuery::apply_sort_with_tiebreak($query_args, null);
assert_true('default sort orderby is an array (not a scalar orderby)', is_array($query_args['orderby'] ?? null));
assert_true('default sort primary key is date DESC', ($query_args['orderby']['date'] ?? null) === 'DESC');
assert_true('default sort carries ID DESC tiebreak (D13)', ($query_args['orderby']['ID'] ?? null) === 'DESC');
assert_true('order (scalar) unset when orderby is an array', !isset($query_args['order']));

$query_args = ['post_type' => 'post'];
DigestBatchQuery::apply_sort_with_tiebreak($query_args, [
	'kind' => 'sort',
	'field' => 'title',
	'direction' => 'asc',
]);
assert_true('explicit sort by title ASC honored', ($query_args['orderby']['title'] ?? null) === 'ASC');
assert_true('title sort still carries ID DESC tiebreak (D13)', ($query_args['orderby']['ID'] ?? null) === 'DESC');

// Sorting by ID itself: ID is already a total order, tiebreak key must not duplicate.
$query_args = ['post_type' => 'post'];
DigestBatchQuery::apply_sort_with_tiebreak($query_args, [
	'kind' => 'sort',
	'field' => 'ID',
	'direction' => 'asc',
]);
assert_true('sort by ID has a single ID key (no duplicate key collision)', $query_args['orderby'] === ['ID' => 'ASC']);

// ---------------------------------------------------------------------------
// 4. has_more: pure function of found_posts / page / page_size — covers the
//    "3 pages of 100" pagination shape and the boundary at the last page.
// ---------------------------------------------------------------------------

// 250 total rows, page_size 100 -> pages of 100, 100, 50.
assert_true('page 1 of 3 (250 rows/100) has_more=true', DigestBatchQuery::compute_has_more(250, 1, 100) === true);
assert_true('page 2 of 3 (250 rows/100) has_more=true', DigestBatchQuery::compute_has_more(250, 2, 100) === true);
assert_true('page 3 (last, 250 rows/100) has_more=false', DigestBatchQuery::compute_has_more(250, 3, 100) === false);

// Exact multiple: 300 rows / 100 per page -> page 3 is exactly the end.
assert_true('exact multiple last page has_more=false', DigestBatchQuery::compute_has_more(300, 3, 100) === false);
assert_true('exact multiple prior page has_more=true', DigestBatchQuery::compute_has_more(300, 2, 100) === true);

// Empty page.
assert_true('empty result has_more=false', DigestBatchQuery::compute_has_more(0, 1, 100) === false);

// Degenerate page_size.
assert_true('page_size<=0 never reports has_more', DigestBatchQuery::compute_has_more(999, 1, 0) === false);

// ---------------------------------------------------------------------------
// 5. Exclude is structural: post__not_in must be present in $query_args before
//    orderby/date_query/filter are layered on top and before WP_Query runs —
//    i.e. it's part of the same WHERE, not a post-fetch filter.
// ---------------------------------------------------------------------------

$excluded_ids = [5, 9, 12];
$query_args = [
	'post_type' => 'post',
	'post_status' => 'publish',
	'posts_per_page' => 100,
	'paged' => 1,
	'post__not_in' => $excluded_ids,
	'ignore_sticky_posts' => true,
	'no_found_rows' => false,
];
DigestBatchQuery::apply_sort_with_tiebreak($query_args, null);
$filter_ok = SelectionFilterQuery::apply($query_args, null, 'post');
$window_dq2 = DigestBatchQuery::build_window_date_query($window_from, $window_to);
DigestBatchQuery::merge_window_date_query($query_args, $window_dq2);

assert_true('post__not_in present before WP_Query assembly', ($query_args['post__not_in'] ?? null) === $excluded_ids);
assert_true('post__not_in untouched by sort/date_query/filter layering', $query_args['post__not_in'] === $excluded_ids);
assert_true('no_found_rows stays false (fetch_batch needs found_posts for has_more)', $query_args['no_found_rows'] === false);
assert_true('paged/posts_per_page set for pagination (not LIMIT 1 like fetch_next)', $query_args['paged'] === 1 && $query_args['posts_per_page'] === 100);

// ---------------------------------------------------------------------------
// 6. force_empty (selection_filter contradiction) still combines with window —
//    post__in=[0] short-circuits regardless, but merge must not throw/crash.
// ---------------------------------------------------------------------------

$query_args = ['post_type' => 'post'];
$filter_ok = SelectionFilterQuery::apply($query_args, [
	'kind' => 'compare',
	'field' => 'post_type',
	'op' => 'eq',
	'value' => 'page',
], 'post');
assert_true('contradictory post_type filter compiles ok', $filter_ok === true);
assert_true('force_empty sets post__in=[0]', ($query_args['post__in'] ?? null) === [0]);
DigestBatchQuery::merge_window_date_query($query_args, $window_dq);
assert_true('window merge does not throw on force_empty query_args', isset($query_args['date_query']));

if ($failures > 0) {
	fwrite(STDERR, "\n{$failures} WP-07 smoke test(s) FAILED.\n");
	exit(1);
}

echo "\nAll WP-07 smoke tests passed.\n";
