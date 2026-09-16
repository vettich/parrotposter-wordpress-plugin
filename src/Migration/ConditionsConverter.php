<?php

namespace parrotposter\Migration;

defined('ABSPATH') || exit;

/**
 * Convert legacy WP `{key,op,value}[]` conditions into GraphQL ExpressionInput (best-effort).
 */
class ConditionsConverter
{
	/**
	 * @param mixed  $conditions
	 * @param string $config_name
	 * @return array{item: array<string, mixed>|null, warnings: list<string>}
	 */
	public static function convert($conditions, $config_name = '')
	{
		$warnings = [];
		if ($conditions === null || $conditions === '') {
			return ['item' => null, 'warnings' => $warnings];
		}

		if (!is_array($conditions)) {
			$warnings[] = self::config_warning($config_name, 'conditions could not be converted');

			return ['item' => null, 'warnings' => $warnings];
		}

		if (isset($conditions['kind']) && is_string($conditions['kind'])) {
			return ['item' => $conditions, 'warnings' => $warnings];
		}

		$rows = self::legacy_rows($conditions);
		if ($rows === null) {
			$warnings[] = self::config_warning($config_name, 'conditions could not be converted');

			return ['item' => null, 'warnings' => $warnings];
		}

		if ($rows === []) {
			return ['item' => null, 'warnings' => $warnings];
		}

		$children = [];
		foreach ($rows as $item) {
			$leaf = self::convert_leaf($item);
			if ($leaf === false) {
				continue;
			}
			if ($leaf === null) {
				$key = is_array($item) && isset($item['key']) ? (string) $item['key'] : '';
				$warnings[] = self::config_warning(
					$config_name,
					'condition on "' . $key . '" could not be converted and was dropped'
				);
				continue;
			}
			$children[] = $leaf;
		}

		if ($children === []) {
			return ['item' => null, 'warnings' => $warnings];
		}

		if (count($children) === 1) {
			return ['item' => $children[0], 'warnings' => $warnings];
		}

		return [
			'item' => [
				'kind' => 'AND',
				'children' => $children,
			],
			'warnings' => $warnings,
		];
	}

	/**
	 * Packed list of legacy `{key,op,value}` rows, or null if this is not that shape.
	 *
	 * WP `json_encode` of a PHP array with holes (deleted form rows) stores an object
	 * like `{"1":{...}}`; json_decode gives sparse numeric keys. Treat those as a list.
	 *
	 * @param mixed $conditions
	 * @return list<mixed>|null
	 */
	public static function legacy_rows($conditions)
	{
		if (!is_array($conditions)) {
			return null;
		}
		if (isset($conditions['kind']) && is_string($conditions['kind'])) {
			return null;
		}
		if ($conditions === []) {
			return [];
		}
		foreach (array_keys($conditions) as $k) {
			if (is_int($k)) {
				continue;
			}
			if (is_string($k) && $k !== '' && ctype_digit($k)) {
				continue;
			}

			return null;
		}

		return array_values($conditions);
	}

	/**
	 * @param mixed $cond
	 * @return array<string, mixed>|null|false  expr, dropped-with-warning (null), or skip (false)
	 */
	private static function convert_leaf($cond)
	{
		if (!is_array($cond)) {
			return false;
		}
		$key = isset($cond['key']) ? trim((string) $cond['key']) : '';
		if ($key === '') {
			return false;
		}
		$op = isset($cond['op']) ? trim((string) $cond['op']) : '';
		$value = array_key_exists('value', $cond) ? $cond['value'] : null;
		$value_is_array = is_array($value);

		switch ($op) {
			case 'include':
				$gql_op = 'CONTAINS';
				break;
			case 'not_include':
				$gql_op = 'NOT_CONTAINS';
				break;
			case 'equal':
				$gql_op = $value_is_array ? 'INCLUDES_ANY' : 'EQ';
				break;
			case 'not_equal':
				$gql_op = $value_is_array ? 'EXCLUDES_ANY' : 'NEQ';
				break;
			case 'less':
				$gql_op = 'LT';
				break;
			case 'less_or_equal':
				$gql_op = 'LTE';
				break;
			case 'greater':
				$gql_op = 'GT';
				break;
			case 'greater_or_equal':
				$gql_op = 'GTE';
				break;
			default:
				return null;
		}

		$compare = [
			'field' => $key,
			'op' => $gql_op,
		];

		if ($gql_op === 'INCLUDES_ANY' || $gql_op === 'EXCLUDES_ANY') {
			$compare['stringValues'] = self::string_list($value);
		} elseif ($gql_op === 'CONTAINS' || $gql_op === 'NOT_CONTAINS') {
			$compare['stringValue'] = self::stringify_scalar($value);
		} elseif ($gql_op === 'LT' || $gql_op === 'LTE' || $gql_op === 'GT' || $gql_op === 'GTE') {
			if (!is_numeric($value)) {
				return null;
			}
			$compare['numberValue'] = (float) $value;
		} elseif ($gql_op === 'EQ' || $gql_op === 'NEQ') {
			if (is_int($value) || is_float($value)) {
				$compare['numberValue'] = (float) $value;
			} else {
				$compare['stringValue'] = self::stringify_scalar($value);
			}
		}

		return [
			'kind' => 'COMPARE',
			'compare' => $compare,
		];
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function string_list($value)
	{
		if (!is_array($value)) {
			return [self::stringify_scalar($value)];
		}
		$out = [];
		foreach ($value as $item) {
			$out[] = self::stringify_scalar($item);
		}

		return $out;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function stringify_scalar($value)
	{
		if ($value === null) {
			return '';
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (is_array($value)) {
			return '';
		}

		return (string) $value;
	}

	/**
	 * @param string $config_name
	 * @param string $message
	 * @return string
	 */
	private static function config_warning($config_name, $message)
	{
		$name = $config_name !== '' ? $config_name : 'config';

		return 'Config "' . $name . '": ' . $message;
	}
}
