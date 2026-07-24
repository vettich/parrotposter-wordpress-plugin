<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * MVP Expression AST evaluator (compare + and/or). Mirrors back-app expression_eval.rs.
 */
class ExpressionEval
{
	/**
	 * @param mixed $conditions
	 * @param array<string, mixed> $item
	 */
	public static function evaluate($conditions, array $item): bool
	{
		if ($conditions === null) {
			return true;
		}
		if (!is_array($conditions)) {
			return true;
		}
		if ($conditions === []) {
			return true;
		}

		try {
			return self::eval_expr($conditions, $item);
		} catch (\Throwable $e) {
			PP::log(['ExpressionEval::evaluate', $e->getMessage(), $conditions]);

			return false;
		}
	}

	/**
	 * @param array<string, mixed> $expr
	 * @param array<string, mixed> $item
	 */
	private static function eval_expr(array $expr, array $item): bool
	{
		$kind = isset($expr['kind']) ? (string) $expr['kind'] : '';
		if ($kind === '') {
			return true;
		}

		switch ($kind) {
			case 'and':
				$children = isset($expr['children']) && is_array($expr['children']) ? $expr['children'] : [];
				if ($children === []) {
					return true;
				}
				foreach ($children as $child) {
					if (!is_array($child) || !self::eval_expr($child, $item)) {
						return false;
					}
				}

				return true;
			case 'or':
				$children = isset($expr['children']) && is_array($expr['children']) ? $expr['children'] : [];
				if ($children === []) {
					return true;
				}
				foreach ($children as $child) {
					if (is_array($child) && self::eval_expr($child, $item)) {
						return true;
					}
				}

				return false;
			case 'compare':
				return self::eval_compare($expr, $item);
			default:
				return true;
		}
	}

	/**
	 * @param array<string, mixed> $expr
	 * @param array<string, mixed> $item
	 */
	private static function eval_compare(array $expr, array $item): bool
	{
		$field = isset($expr['field']) ? (string) $expr['field'] : '';
		$op = isset($expr['op']) ? (string) $expr['op'] : '';
		if ($field === '' || $op === '') {
			return true;
		}

		$field_present = array_key_exists($field, $item);
		$left = $field_present ? $item[$field] : null;
		$right = $expr['value'] ?? null;

		if (!$field_present) {
			return $op === 'is_empty';
		}

		switch ($op) {
			case 'is_empty':
				return self::is_empty_value($left);
			case 'is_set':
				return !self::is_empty_value($left);
			case 'is_true':
				return $left === true;
			case 'is_false':
				return $left === false;
			case 'eq':
				return self::json_eq($left, $right);
			case 'neq':
				return !self::json_eq($left, $right);
			case 'contains':
				$hay = is_string($left) ? $left : '';
				$needle = is_string($right) ? $right : (is_scalar($right) ? (string) $right : '');

				return $needle !== '' && strpos($hay, $needle) !== false;
			case 'not_contains':
				$hay = is_string($left) ? $left : '';
				$needle = is_string($right) ? $right : (is_scalar($right) ? (string) $right : '');

				return $needle === '' || strpos($hay, $needle) === false;
			case 'starts_with':
				$hay = is_string($left) ? $left : '';
				$prefix = is_string($right) ? $right : (is_scalar($right) ? (string) $right : '');

				return $prefix !== '' && strpos($hay, $prefix) === 0;
			case 'ends_with':
				$hay = is_string($left) ? $left : '';
				$suffix = is_string($right) ? $right : (is_scalar($right) ? (string) $right : '');
				if ($suffix === '') {
					return false;
				}
				$len = strlen($suffix);

				return substr($hay, -$len) === $suffix;
			case 'gt':
			case 'gte':
			case 'lt':
			case 'lte':
				return self::compare_ordered($left, $right, $op);
			case 'includes_any':
				return self::eval_includes($left, $right, 'any');
			case 'includes_all':
				return self::eval_includes($left, $right, 'all');
			case 'excludes_any':
				return self::eval_includes($left, $right, 'excludes_any');
			default:
				return true;
		}
	}

	/**
	 * @param mixed $left
	 * @param mixed $right
	 */
	private static function eval_includes($left, $right, string $mode): bool
	{
		if (!is_array($right) || $right === []) {
			return false;
		}

		$left_values = self::value_as_string_set($left);
		$right_values = [];
		foreach ($right as $entry) {
			if (is_string($entry) || is_numeric($entry)) {
				$right_values[] = (string) $entry;
			}
		}
		if ($right_values === []) {
			return false;
		}

		$left_set = array_fill_keys($left_values, true);
		$overlap = false;
		foreach ($right_values as $value) {
			if (isset($left_set[$value])) {
				$overlap = true;
				break;
			}
		}

		switch ($mode) {
			case 'any':
				return $overlap;
			case 'all':
				foreach ($right_values as $value) {
					if (!isset($left_set[$value])) {
						return false;
					}
				}

				return true;
			case 'excludes_any':
				return !$overlap;
			default:
				return false;
		}
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function value_as_string_set($value): array
	{
		if (is_array($value)) {
			$out = [];
			foreach ($value as $item) {
				if (is_string($item) || is_numeric($item)) {
					$out[] = (string) $item;
				}
			}

			return $out;
		}
		if (is_string($value) && $value !== '') {
			return [$value];
		}
		if (is_numeric($value)) {
			return [(string) $value];
		}

		return [];
	}

	/**
	 * @param mixed $value
	 */
	private static function is_empty_value($value): bool
	{
		if ($value === null) {
			return true;
		}
		if (is_string($value)) {
			return $value === '';
		}
		if (is_array($value)) {
			return $value === [];
		}

		return false;
	}

	/**
	 * @param mixed $left
	 * @param mixed $right
	 */
	private static function json_eq($left, $right): bool
	{
		if (is_array($left) || is_array($right)) {
			return wp_json_encode($left) === wp_json_encode($right);
		}

		return $left === $right;
	}

	/**
	 * @param mixed $left
	 * @param mixed $right
	 */
	private static function compare_ordered($left, $right, string $op): bool
	{
		if (is_numeric($left) && is_numeric($right)) {
			$a = (float) $left;
			$b = (float) $right;
		} else {
			$a = is_scalar($left) ? (string) $left : '';
			$b = is_scalar($right) ? (string) $right : '';
		}

		switch ($op) {
			case 'gt':
				return $a > $b;
			case 'gte':
				return $a >= $b;
			case 'lt':
				return $a < $b;
			case 'lte':
				return $a <= $b;
			default:
				return false;
		}
	}
}
