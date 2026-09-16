<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Compiles Expression AST (selection_filter) into WP_Query args for Archive fetch_next.
 *
 * Fail-closed: unsupported nodes return WP_Error 422 instead of silently widening the set.
 */
class SelectionFilterQuery
{
	/** @var list<string> */
	public const COMPARE_OPS = [
		'eq',
		'neq',
		'is_true',
		'is_false',
		'includes_any',
		'is_set',
		'is_empty',
	];

	/** @var list<string> */
	public const SORTABLE_FIELDS = [
		'date',
		'published_at',
		'title',
		'ID',
		'id',
	];

	/**
	 * Apply selection_filter to WP_Query args (mutates $query_args).
	 *
	 * @param array<string, mixed> $query_args
	 * @param mixed                $filter
	 * @return true|\WP_Error
	 */
	public static function apply(array &$query_args, $filter, string $post_type)
	{
		if ($filter === null || $filter === [] || $filter === '') {
			return true;
		}
		if (!is_array($filter)) {
			return self::unsupported('selection_filter must be an Expression AST object');
		}

		$parts = self::compile_expr($filter, $post_type);
		if (is_wp_error($parts)) {
			return $parts;
		}

		self::merge_parts_into_query($query_args, $parts);

		return true;
	}

	/**
	 * Install temporary posts_where hooks for exact core-field compares; returns cleanup callable.
	 *
	 * @param array<string, mixed> $query_args
	 * @return callable(): void
	 */
	public static function install_where_hooks(array $query_args): callable
	{
		$clauses = isset($query_args['_pp_where_clauses']) && is_array($query_args['_pp_where_clauses'])
			? $query_args['_pp_where_clauses']
			: [];
		if ($clauses === []) {
			return static function (): void {
			};
		}

		$callback = static function (string $where) use ($clauses): string {
			global $wpdb;
			foreach ($clauses as $clause) {
				if (!is_array($clause)) {
					continue;
				}
				$column = isset($clause['column']) ? (string) $clause['column'] : '';
				$op = isset($clause['op']) ? (string) $clause['op'] : '=';
				$value = $clause['value'] ?? '';
				if ($column === '') {
					continue;
				}
				$col_sql = $wpdb->posts . '.' . $column;
				if ($op === 'empty') {
					$where .= " AND ({$col_sql} = '' OR {$col_sql} IS NULL)";
					continue;
				}
				if ($op === 'not_empty') {
					$where .= " AND ({$col_sql} <> '' AND {$col_sql} IS NOT NULL)";
					continue;
				}
				$sql_op = $op === '!=' ? '!=' : '=';
				$where .= $wpdb->prepare(" AND {$col_sql} {$sql_op} %s", (string) $value);
			}

			return $where;
		};

		add_filter('posts_where', $callback, 10, 1);

		return static function () use ($callback): void {
			remove_filter('posts_where', $callback, 10);
		};
	}

	/**
	 * @return array{
	 *   compare_ops: list<string>,
	 *   sortable_fields: list<string>
	 * }
	 */
	public static function filter_capabilities(): array
	{
		return [
			'compare_ops' => self::COMPARE_OPS,
			'sortable_fields' => ['date', 'published_at', 'title', 'ID'],
		];
	}

	/**
	 * @param array<string, mixed> $expr
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_expr(array $expr, string $post_type)
	{
		$kind = isset($expr['kind']) ? (string) $expr['kind'] : '';
		switch ($kind) {
			case 'and':
				return self::compile_and($expr, $post_type);
			case 'or':
				return self::compile_or($expr, $post_type);
			case 'compare':
				return self::compile_compare($expr, $post_type);
			case '':
				return self::unsupported('selection_filter.kind is required');
			default:
				return self::unsupported(sprintf('unsupported expression kind: %s', $kind));
		}
	}

	/**
	 * @param array<string, mixed> $expr
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_and(array $expr, string $post_type)
	{
		$children = isset($expr['children']) && is_array($expr['children']) ? $expr['children'] : [];
		if ($children === []) {
			return self::empty_parts();
		}

		$merged = self::empty_parts();
		foreach ($children as $child) {
			if (!is_array($child)) {
				return self::unsupported('and.children entries must be objects');
			}
			$part = self::compile_expr($child, $post_type);
			if (is_wp_error($part)) {
				return $part;
			}
			$merged = self::merge_parts($merged, $part, 'AND');
			if (is_wp_error($merged)) {
				return $merged;
			}
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $expr
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_or(array $expr, string $post_type)
	{
		$children = isset($expr['children']) && is_array($expr['children']) ? $expr['children'] : [];
		if ($children === []) {
			return self::empty_parts();
		}

		$tax_branches = [];
		foreach ($children as $child) {
			if (!is_array($child)) {
				return self::unsupported('or.children entries must be objects');
			}
			$part = self::compile_expr($child, $post_type);
			if (is_wp_error($part)) {
				return $part;
			}
			// MVP: OR only when every branch is tax-only (no meta/date/where).
			if (
				!empty($part['meta_query'])
				|| !empty($part['date_query'])
				|| !empty($part['where_clauses'])
			) {
				return self::unsupported('or is only supported for taxonomy compares in MVP');
			}
			if (empty($part['tax_query'])) {
				return self::unsupported('or branch produced no taxonomy clause');
			}
			$tax_branches[] = $part['tax_query'];
		}

		$or_tax = ['relation' => 'OR'];
		foreach ($tax_branches as $branch) {
			if (isset($branch['relation']) || self::is_list($branch)) {
				$or_tax[] = $branch;
			} else {
				$or_tax[] = $branch;
			}
		}

		$parts = self::empty_parts();
		$parts['tax_query'] = $or_tax;

		return $parts;
	}

	/**
	 * @param array<string, mixed> $expr
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_compare(array $expr, string $post_type)
	{
		$field = isset($expr['field']) ? (string) $expr['field'] : '';
		$op = isset($expr['op']) ? (string) $expr['op'] : '';
		if ($field === '' || $op === '') {
			return self::unsupported('compare requires field and op');
		}
		if (!in_array($op, self::COMPARE_OPS, true)) {
			return self::unsupported(sprintf('unsupported compare op: %s', $op));
		}

		$value = $expr['value'] ?? null;

		if ($field === 'post_type') {
			return self::compile_post_type_compare($op, $value, $post_type);
		}

		if (taxonomy_exists($field)) {
			return self::compile_taxonomy_compare($field, $op, $value);
		}

		if (in_array($field, ['title', 'excerpt', 'content'], true)) {
			return self::compile_core_text_compare($field, $op, $value);
		}

		if ($field === 'date' || $field === 'published_at') {
			return self::compile_date_compare($op, $value);
		}

		if ($field === 'featured_image') {
			return self::compile_thumbnail_compare($op);
		}

		if ($op === 'is_true' || $op === 'is_false') {
			return self::compile_bool_meta_compare($field, $op);
		}

		if ($op === 'is_set' || $op === 'is_empty') {
			return self::compile_meta_presence_compare($field, $op);
		}

		if ($op === 'eq' || $op === 'neq') {
			return self::compile_meta_value_compare($field, $op, $value);
		}

		return self::unsupported(
			sprintf('op %s is not mapped to SQL for field %s', $op, $field)
		);
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_post_type_compare(string $op, $value, string $post_type)
	{
		if ($op === 'eq') {
			$expected = is_scalar($value) ? (string) $value : '';
			if ($expected !== '' && $expected !== $post_type) {
				// Contradiction with source_path — empty result via impossible ID.
				$parts = self::empty_parts();
				$parts['force_empty'] = true;

				return $parts;
			}

			return self::empty_parts();
		}
		if ($op === 'neq') {
			$expected = is_scalar($value) ? (string) $value : '';
			if ($expected === $post_type) {
				$parts = self::empty_parts();
				$parts['force_empty'] = true;

				return $parts;
			}

			return self::empty_parts();
		}

		return self::unsupported(sprintf('post_type does not support op %s in SQL MVP', $op));
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_taxonomy_compare(string $taxonomy, string $op, $value)
	{
		$parts = self::empty_parts();

		switch ($op) {
			case 'includes_any':
				$term_ids = self::term_ids_from_value($value);
				if ($term_ids === []) {
					return self::unsupported('includes_any requires a non-empty term id array');
				}
				$parts['tax_query'] = [
					'taxonomy' => $taxonomy,
					'field' => 'term_id',
					'terms' => $term_ids,
					'operator' => 'IN',
					'include_children' => true,
				];

				return $parts;
			case 'eq':
				$term_ids = self::term_ids_from_value(is_array($value) ? $value : [$value]);
				if (count($term_ids) !== 1) {
					return self::unsupported('taxonomy eq requires a single term id');
				}
				$parts['tax_query'] = [
					'taxonomy' => $taxonomy,
					'field' => 'term_id',
					'terms' => $term_ids,
					'operator' => 'IN',
					'include_children' => true,
				];

				return $parts;
			case 'neq':
				$term_ids = self::term_ids_from_value(is_array($value) ? $value : [$value]);
				if ($term_ids === []) {
					return self::unsupported('taxonomy neq requires term id(s)');
				}
				$parts['tax_query'] = [
					'taxonomy' => $taxonomy,
					'field' => 'term_id',
					'terms' => $term_ids,
					'operator' => 'NOT IN',
					'include_children' => true,
				];

				return $parts;
			case 'is_set':
				$parts['tax_query'] = [
					'taxonomy' => $taxonomy,
					'operator' => 'EXISTS',
				];

				return $parts;
			case 'is_empty':
				$parts['tax_query'] = [
					'taxonomy' => $taxonomy,
					'operator' => 'NOT EXISTS',
				];

				return $parts;
			default:
				return self::unsupported(
					sprintf('taxonomy field %s does not support op %s in SQL MVP', $taxonomy, $op)
				);
		}
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_core_text_compare(string $field, string $op, $value)
	{
		$column = [
			'title' => 'post_title',
			'excerpt' => 'post_excerpt',
			'content' => 'post_content',
		][$field];

		$parts = self::empty_parts();
		switch ($op) {
			case 'eq':
			case 'neq':
				if (!is_scalar($value)) {
					return self::unsupported(sprintf('%s %s requires a scalar value', $field, $op));
				}
				$parts['where_clauses'][] = [
					'column' => $column,
					'op' => $op === 'neq' ? '!=' : '=',
					'value' => (string) $value,
				];

				return $parts;
			case 'is_set':
				$parts['where_clauses'][] = [
					'column' => $column,
					'op' => 'not_empty',
					'value' => '',
				];

				return $parts;
			case 'is_empty':
				$parts['where_clauses'][] = [
					'column' => $column,
					'op' => 'empty',
					'value' => '',
				];

				return $parts;
			default:
				return self::unsupported(sprintf('%s does not support op %s in SQL MVP', $field, $op));
		}
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_date_compare(string $op, $value)
	{
		$parts = self::empty_parts();
		if ($op === 'is_set') {
			$parts['where_clauses'][] = [
				'column' => 'post_date',
				'op' => 'not_empty',
				'value' => '',
			];

			return $parts;
		}
		if ($op === 'is_empty') {
			$parts['force_empty'] = true;

			return $parts;
		}
		if ($op !== 'eq' && $op !== 'neq') {
			return self::unsupported(sprintf('date does not support op %s in SQL MVP', $op));
		}
		if (!is_scalar($value) || (string) $value === '') {
			return self::unsupported('date eq/neq requires a datetime string');
		}

		$ts = strtotime((string) $value);
		if ($ts === false) {
			return self::unsupported('date value is not a parseable datetime');
		}

		$ymd = gmdate('Y-m-d', $ts);
		$clause = [
			'year' => (int) gmdate('Y', $ts),
			'month' => (int) gmdate('m', $ts),
			'day' => (int) gmdate('d', $ts),
			'compare' => $op === 'neq' ? '!=' : '=',
		];
		// WP date_query compare != is limited; for neq use exclusive range around the day.
		if ($op === 'neq') {
			$parts['date_query'] = [
				'relation' => 'OR',
				[
					'before' => $ymd,
					'inclusive' => false,
				],
				[
					'after' => $ymd,
					'inclusive' => false,
				],
			];
		} else {
			$parts['date_query'] = [
				[
					'year' => $clause['year'],
					'month' => $clause['month'],
					'day' => $clause['day'],
				],
			];
		}

		return $parts;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_thumbnail_compare(string $op)
	{
		$parts = self::empty_parts();
		if ($op === 'is_set') {
			$parts['meta_query'] = [
				'key' => '_thumbnail_id',
				'compare' => 'EXISTS',
			];

			return $parts;
		}
		if ($op === 'is_empty') {
			$parts['meta_query'] = [
				'relation' => 'OR',
				[
					'key' => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				],
				[
					'key' => '_thumbnail_id',
					'value' => '',
					'compare' => '=',
				],
			];

			return $parts;
		}

		return self::unsupported(sprintf('featured_image does not support op %s in SQL MVP', $op));
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_bool_meta_compare(string $field, string $op)
	{
		$parts = self::empty_parts();
		$truthy = ['1', 'true', 'yes', 'on'];
		$falsy = ['0', 'false', 'no', 'off', ''];
		if ($op === 'is_true') {
			$parts['meta_query'] = [
				'key' => $field,
				'value' => $truthy,
				'compare' => 'IN',
			];
		} else {
			$parts['meta_query'] = [
				'relation' => 'OR',
				[
					'key' => $field,
					'compare' => 'NOT EXISTS',
				],
				[
					'key' => $field,
					'value' => $falsy,
					'compare' => 'IN',
				],
			];
		}

		return $parts;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_meta_presence_compare(string $field, string $op)
	{
		$parts = self::empty_parts();
		if ($op === 'is_set') {
			$parts['meta_query'] = [
				'key' => $field,
				'compare' => 'EXISTS',
			];
		} else {
			$parts['meta_query'] = [
				'relation' => 'OR',
				[
					'key' => $field,
					'compare' => 'NOT EXISTS',
				],
				[
					'key' => $field,
					'value' => '',
					'compare' => '=',
				],
			];
		}

		return $parts;
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function compile_meta_value_compare(string $field, string $op, $value)
	{
		if (!is_scalar($value)) {
			return self::unsupported(sprintf('meta field %s eq/neq requires a scalar value', $field));
		}
		$parts = self::empty_parts();
		$parts['meta_query'] = [
			'key' => $field,
			'value' => (string) $value,
			'compare' => $op === 'neq' ? '!=' : '=',
		];

		return $parts;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function empty_parts(): array
	{
		return [
			'tax_query' => [],
			'meta_query' => [],
			'date_query' => [],
			'where_clauses' => [],
			'force_empty' => false,
		];
	}

	/**
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function merge_parts(array $left, array $right, string $relation)
	{
		$out = self::empty_parts();
		$out['force_empty'] = !empty($left['force_empty']) || !empty($right['force_empty']);
		$out['where_clauses'] = array_merge(
			isset($left['where_clauses']) && is_array($left['where_clauses']) ? $left['where_clauses'] : [],
			isset($right['where_clauses']) && is_array($right['where_clauses']) ? $right['where_clauses'] : []
		);

		$out['tax_query'] = self::merge_query_group(
			isset($left['tax_query']) && is_array($left['tax_query']) ? $left['tax_query'] : [],
			isset($right['tax_query']) && is_array($right['tax_query']) ? $right['tax_query'] : [],
			$relation
		);
		$out['meta_query'] = self::merge_query_group(
			isset($left['meta_query']) && is_array($left['meta_query']) ? $left['meta_query'] : [],
			isset($right['meta_query']) && is_array($right['meta_query']) ? $right['meta_query'] : [],
			$relation
		);
		$out['date_query'] = self::merge_query_group(
			isset($left['date_query']) && is_array($left['date_query']) ? $left['date_query'] : [],
			isset($right['date_query']) && is_array($right['date_query']) ? $right['date_query'] : [],
			$relation
		);

		return $out;
	}

	/**
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 * @return array<string, mixed>
	 */
	private static function merge_query_group(array $left, array $right, string $relation): array
	{
		if ($left === []) {
			return $right;
		}
		if ($right === []) {
			return $left;
		}

		$wrap = static function (array $group): array {
			if ($group === []) {
				return [];
			}
			if (isset($group['relation']) || self::is_list($group)) {
				return $group;
			}

			return [$group];
		};

		return [
			'relation' => $relation,
			$wrap($left),
			$wrap($right),
		];
	}

	/**
	 * @param array<string, mixed> $query_args
	 * @param array<string, mixed> $parts
	 */
	private static function merge_parts_into_query(array &$query_args, array $parts): void
	{
		if (!empty($parts['force_empty'])) {
			$query_args['post__in'] = [0];

			return;
		}
		if (!empty($parts['tax_query']) && is_array($parts['tax_query'])) {
			// WP_Tax_Query iterates top-level entries as clauses. A bare first-order
			// assoc (`taxonomy`/`terms`/…) is ignored — wrap it in a list.
			$query_args['tax_query'] = self::normalize_query_group($parts['tax_query']);
		}
		if (!empty($parts['meta_query']) && is_array($parts['meta_query'])) {
			$query_args['meta_query'] = self::normalize_query_group($parts['meta_query']);
		}
		if (!empty($parts['date_query']) && is_array($parts['date_query'])) {
			$query_args['date_query'] = self::normalize_query_group($parts['date_query']);
		}
		if (!empty($parts['where_clauses']) && is_array($parts['where_clauses'])) {
			$query_args['_pp_where_clauses'] = $parts['where_clauses'];
		}
	}

	/**
	 * Ensure a tax/meta/date query group is valid for WP_*_Query constructors.
	 *
	 * @param array<string, mixed> $group
	 * @return array<int|string, mixed>
	 */
	private static function normalize_query_group(array $group): array
	{
		if ($group === []) {
			return $group;
		}
		if (isset($group['relation']) || self::is_list($group)) {
			return $group;
		}

		return [$group];
	}

	/**
	 * @param mixed $value
	 * @return list<int>
	 */
	private static function term_ids_from_value($value): array
	{
		if (!is_array($value)) {
			$value = [$value];
		}
		$ids = [];
		foreach ($value as $entry) {
			if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
				$id = (int) $entry;
				if ($id > 0) {
					$ids[] = $id;
				}
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @param array<mixed> $arr
	 */
	private static function is_list(array $arr): bool
	{
		if ($arr === []) {
			return true;
		}

		return array_keys($arr) === range(0, count($arr) - 1);
	}

	/**
	 * @return \WP_Error
	 */
	private static function unsupported(string $message)
	{
		return new \WP_Error(
			'unsupported_selection_filter',
			$message,
			['status' => 422]
		);
	}
}
