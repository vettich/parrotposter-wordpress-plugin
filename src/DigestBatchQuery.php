<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Pure query-building helpers for Digest `fetch_batch` (SPEC-002-09 §7.3.3, BE-22, WP-07).
 *
 * Kept independent of WP_REST_Request / WP_Query so the window / tiebreak / pagination
 * logic is unit-testable without a live WP DB — mirrors how SelectionFilterQuery
 * separates the pure Expression AST -> WP_Query compiler from the HTTP layer.
 */
class DigestBatchQuery
{
	/**
	 * Sort field -> WP_Query orderby key. Same whitelist as
	 * SelectionFilterQuery::SORTABLE_FIELDS (WP-05) — not extended here (out of scope).
	 *
	 * @var array<string, string>
	 */
	private const SORT_FIELD_TO_ORDERBY_KEY = [
		'title' => 'title',
		'ID' => 'ID',
		'id' => 'ID',
		'published_at' => 'date',
		'date' => 'date',
	];

	/**
	 * Half-open window `[window_from, window_to)` as a WP_Query `date_query` array
	 * (SPEC-002-09 §7.3.3: `window_to` boundary is excluded).
	 *
	 * @return array<string, mixed>
	 */
	public static function build_window_date_query(string $window_from, string $window_to): array
	{
		return [
			'relation' => 'AND',
			[
				'column' => 'post_date',
				'after' => $window_from,
				'inclusive' => true,
			],
			[
				'column' => 'post_date',
				'before' => $window_to,
				'inclusive' => false,
			],
		];
	}

	/**
	 * Merge the window date bound into $query_args, AND-combined with any `date_query`
	 * already produced by SelectionFilterQuery::apply() from `selection_filter` — both
	 * must hold simultaneously, neither may clobber the other.
	 *
	 * Must run *after* SelectionFilterQuery::apply(): that method overwrites
	 * `$query_args['date_query']` wholesale (merge_parts_into_query()), so calling
	 * this first would just get discarded by a filter that also constrains a date field.
	 *
	 * @param array<string, mixed> $query_args
	 * @param array<string, mixed> $window_date_query
	 */
	public static function merge_window_date_query(array &$query_args, array $window_date_query): void
	{
		$existing = isset($query_args['date_query']) && is_array($query_args['date_query'])
			? $query_args['date_query']
			: [];

		if ($existing === []) {
			$query_args['date_query'] = $window_date_query;

			return;
		}

		$query_args['date_query'] = [
			'relation' => 'AND',
			$window_date_query,
			$existing,
		];
	}

	/**
	 * Apply sort with a mandatory secondary `ID DESC` tiebreak (D13 / DEC-002-01).
	 *
	 * Without a total order, two rows sharing the same primary sort value (e.g. an
	 * identical `post_date`) can be ordered differently between two paginated
	 * WP_Query calls — one row gets skipped, another duplicated across pages.
	 *
	 * Deliberately a separate function from WireProtocol::apply_sort_to_query()
	 * (used by `fetch_next`, `posts_per_page = 1`, no tiebreak needed there — do not
	 * merge these, Sequential's behavior must stay unchanged).
	 *
	 * @param array<string, mixed> $query_args
	 * @param mixed                $sort
	 */
	public static function apply_sort_with_tiebreak(array &$query_args, $sort): void
	{
		$field = 'date';
		$direction = 'DESC';

		if (is_array($sort)) {
			$first = null;
			// SPEC-002-07 §5: { kind: sort, rules: [{ field, direction }] }
			if (isset($sort['rules']) && is_array($sort['rules']) && isset($sort['rules'][0]) && is_array($sort['rules'][0])) {
				$first = $sort['rules'][0];
			} elseif (isset($sort[0]) && is_array($sort[0])) {
				$first = $sort[0];
			} elseif (isset($sort['field'])) {
				// BE wire shorthand: { kind: sort, field, direction }
				$first = $sort;
			}
			if (is_array($first)) {
				$field = isset($first['field']) ? (string) $first['field'] : $field;
				$direction = isset($first['direction']) && strtolower((string) $first['direction']) === 'asc'
					? 'ASC'
					: 'DESC';
			}
		}

		$orderby_key = self::SORT_FIELD_TO_ORDERBY_KEY[$field] ?? 'date';

		if ($orderby_key === 'ID') {
			// ID alone is already a total order — no separate tiebreak key needed,
			// and WP_Query orderby arrays can't repeat a key.
			$query_args['orderby'] = ['ID' => $direction];
		} else {
			$query_args['orderby'] = [$orderby_key => $direction, 'ID' => 'DESC'];
		}
		unset($query_args['order']);
	}

	/**
	 * `has_more = found_posts > page * page_size` (SPEC-002-09 §7.3.3 / BE-22).
	 */
	public static function compute_has_more(int $found_posts, int $page, int $page_size): bool
	{
		if ($page_size <= 0) {
			return false;
		}

		return $found_posts > $page * $page_size;
	}
}
