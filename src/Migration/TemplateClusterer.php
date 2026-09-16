<?php

namespace parrotposter\Migration;

defined('ABSPATH') || exit;

/**
 * Group legacy autoposting rows that should become one pipeline.
 *
 * Stage 1: same skeleton + content, accounts may differ → one cluster.
 * Stage 2: same skeleton, different content, disjoint social types → one cluster
 * (per-network template overlays are applied later by PayloadBuilder).
 */
class TemplateClusterer
{
	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<list<array<string, mixed>>>
	 */
	public static function cluster(array $rows)
	{
		$valid = [];
		foreach ($rows as $row) {
			if (is_array($row)) {
				$valid[] = $row;
			}
		}
		if ($valid === []) {
			return [];
		}

		$by_skeleton = [];
		foreach ($valid as $row) {
			$by_skeleton[self::skeleton_key($row)][] = $row;
		}

		$clusters = [];
		foreach ($by_skeleton as $group) {
			foreach (self::merge_skeleton_group($group) as $cluster) {
				$clusters[] = $cluster;
			}
		}

		usort($clusters, function ($a, $b) {
			return self::min_row_id($a) - self::min_row_id($b);
		});

		return $clusters;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array<string, mixed>
	 */
	public static function pick_canonical(array $rows)
	{
		$best = $rows[0];
		$best_accounts = count(self::account_ids($best));
		$best_id = self::row_id_int($best);
		foreach ($rows as $row) {
			$n = count(self::account_ids($row));
			$id = self::row_id_int($row);
			if ($n > $best_accounts || ($n === $best_accounts && $id < $best_id)) {
				$best = $row;
				$best_accounts = $n;
				$best_id = $id;
			}
		}

		return $best;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<string>
	 */
	public static function union_account_ids(array $rows)
	{
		$seen = [];
		$out = [];
		foreach ($rows as $row) {
			foreach (self::account_ids($row) as $id) {
				if (isset($seen[$id])) {
					continue;
				}
				$seen[$id] = true;
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return list<string>
	 */
	public static function account_ids(array $row)
	{
		$out = [];
		if (!isset($row['account_ids']) || !is_array($row['account_ids'])) {
			return $out;
		}
		foreach ($row['account_ids'] as $aid) {
			if (!is_string($aid) && !is_numeric($aid)) {
				continue;
			}
			$id = trim((string) $aid);
			if ($id !== '') {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return string
	 */
	public static function cluster_name(array $rows)
	{
		$names = [];
		$seen = [];
		foreach ($rows as $row) {
			$name = isset($row['name']) ? trim((string) $row['name']) : '';
			if ($name === '' || isset($seen[$name])) {
				continue;
			}
			$seen[$name] = true;
			$names[] = $name;
		}
		if ($names === []) {
			return 'Migrated pipeline';
		}

		return implode(' · ', $names);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return string
	 */
	public static function cluster_legacy_id(array $rows)
	{
		$ids = [];
		foreach ($rows as $row) {
			$id = self::row_id($row);
			if ($id !== '') {
				$ids[] = $id;
			}
		}
		usort($ids, function ($a, $b) {
			$ia = (int) $a;
			$ib = (int) $b;
			if ($ia !== $ib) {
				return $ia - $ib;
			}

			return strcmp($a, $b);
		});

		return implode('+', $ids);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<string>
	 */
	public static function cluster_member_ids(array $rows)
	{
		$ids = [];
		foreach ($rows as $row) {
			$id = self::row_id($row);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<string>
	 */
	public static function cluster_network_labels(array $rows)
	{
		$present = [];
		foreach ($rows as $row) {
			foreach (UtmVkConverter::social_types_from_account_ids(self::account_ids($row)) as $type) {
				$present[$type] = true;
			}
		}
		$order = [
			'VK' => 'VK',
			'TG' => 'Telegram',
			'FB' => 'Facebook',
			'OK' => 'OK',
			'IG' => 'Instagram',
			'MAX' => 'MAX',
			'TEST' => 'Test',
		];
		$out = [];
		foreach ($order as $type => $label) {
			if (isset($present[$type])) {
				$out[] = $label;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return string
	 */
	public static function row_id(array $row)
	{
		return isset($row['id']) ? trim((string) $row['id']) : '';
	}

	/**
	 * @param array<string, mixed> $row
	 * @return string
	 */
	public static function content_key(array $row)
	{
		return implode("\n", [
			self::normalize_macros(isset($row['post_text']) ? (string) $row['post_text'] : ''),
			self::normalize_macros(isset($row['post_tags']) ? (string) $row['post_tags'] : ''),
			self::normalize_macros(isset($row['post_link']) ? (string) $row['post_link'] : ''),
			self::utm_fingerprint($row),
			!empty($row['extra_vk_from_group']) ? '1' : '0',
			!empty($row['extra_vk_signed']) ? '1' : '0',
		]);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array<string, mixed>|null
	 */
	public static function pick_vk_row(array $rows)
	{
		$best = null;
		$best_id = PHP_INT_MAX;
		foreach ($rows as $row) {
			if (!UtmVkConverter::has_vk_account(self::account_ids($row))) {
				continue;
			}
			$id = self::row_id_int($row);
			if ($best === null || $id < $best_id) {
				$best = $row;
				$best_id = $id;
			}
		}

		return $best;
	}

	/**
	 * @param list<array<string, mixed>> $group
	 * @return list<list<array<string, mixed>>>
	 */
	private static function merge_skeleton_group(array $group)
	{
		$by_content = [];
		foreach ($group as $row) {
			$by_content[self::content_key($row)][] = $row;
		}

		$packs = [];
		foreach ($by_content as $members) {
			$packs[] = [
				'members' => $members,
				'social' => self::social_types_for_rows($members),
				'min_id' => self::min_row_id($members),
			];
		}
		usort($packs, function ($a, $b) {
			return $a['min_id'] - $b['min_id'];
		});

		$pipelines = [];
		foreach ($packs as $pack) {
			$placed = false;
			foreach ($pipelines as $i => $existing) {
				if (!self::can_merge_packs($existing['social'], $pack['social'])) {
					continue;
				}
				$pipelines[$i]['members'] = array_merge($existing['members'], $pack['members']);
				$pipelines[$i]['social'] = self::union_strings($existing['social'], $pack['social']);
				$placed = true;
				break;
			}
			if (!$placed) {
				$pipelines[] = $pack;
			}
		}

		$out = [];
		foreach ($pipelines as $pipeline) {
			$out[] = $pipeline['members'];
		}

		return $out;
	}

	/**
	 * @param list<string> $a
	 * @param list<string> $b
	 * @return bool
	 */
	private static function can_merge_packs(array $a, array $b)
	{
		if ($a === [] || $b === []) {
			return false;
		}

		return array_intersect($a, $b) === [];
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<string>
	 */
	private static function social_types_for_rows(array $rows)
	{
		$out = [];
		foreach ($rows as $row) {
			$out = self::union_strings(
				$out,
				UtmVkConverter::social_types_from_account_ids(self::account_ids($row))
			);
		}

		return $out;
	}

	/**
	 * @param list<string> $a
	 * @param list<string> $b
	 * @return list<string>
	 */
	private static function union_strings(array $a, array $b)
	{
		$seen = [];
		$out = [];
		foreach (array_merge($a, $b) as $item) {
			if (isset($seen[$item])) {
				continue;
			}
			$seen[$item] = true;
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return string
	 */
	private static function skeleton_key(array $row)
	{
		$post_type = isset($row['wp_post_type']) ? trim((string) $row['wp_post_type']) : '';
		if ($post_type === '') {
			$post_type = 'post';
		}
		$when = isset($row['when_publish']) ? trim((string) $row['when_publish']) : '';
		$delay = isset($row['publish_delay']) && is_numeric($row['publish_delay'])
			? (int) $row['publish_delay']
			: 0;

		return implode("\n", [
			$post_type,
			self::canonical_conditions(isset($row['conditions']) ? $row['conditions'] : []),
			$when,
			(string) $delay,
			self::images_fingerprint(isset($row['post_images']) ? $row['post_images'] : []),
		]);
	}

	/**
	 * @param mixed $conditions
	 * @return string
	 */
	private static function canonical_conditions($conditions)
	{
		if ($conditions === null || $conditions === '' || $conditions === []) {
			return '';
		}
		$rows = ConditionsConverter::legacy_rows($conditions);
		if ($rows === null) {
			if (!is_array($conditions)) {
				return '';
			}

			return self::json_fingerprint(self::ksort_recursive($conditions));
		}
		$leaves = [];
		foreach ($rows as $item) {
			if (!is_array($item)) {
				$leaves[] = self::json_fingerprint($item);
				continue;
			}
			$key = isset($item['key']) ? trim((string) $item['key']) : '';
			if ($key === '') {
				continue;
			}
			$leaves[] = self::json_fingerprint([
				'key' => $key,
				'op' => isset($item['op']) ? (string) $item['op'] : '',
				'value' => self::canonical_condition_value(
					array_key_exists('value', $item) ? $item['value'] : null
				),
			]);
		}
		if ($leaves === []) {
			return '';
		}
		sort($leaves, SORT_STRING);

		return implode("\n", $leaves);
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function canonical_condition_value($value)
	{
		if (!is_array($value) || $value === []) {
			return $value;
		}
		$is_list = array_keys($value) === range(0, count($value) - 1);
		if ($is_list) {
			$copy = $value;
			$copy = array_map('strval', $copy);
			sort($copy, SORT_STRING);

			return $copy;
		}

		return self::ksort_recursive($value);
	}

	/**
	 * @param mixed $post_images
	 * @return string
	 */
	private static function images_fingerprint($post_images)
	{
		if (!is_array($post_images)) {
			return '';
		}
		$fields = [];
		foreach ($post_images as $raw) {
			if (!is_string($raw) && !is_numeric($raw)) {
				continue;
			}
			$field = MediaFieldConverter::normalize_field_key((string) $raw);
			if ($field === 'content_images') {
				$field = 'images_in_content';
			}
			if ($field !== '') {
				$fields[] = $field;
			}
		}
		sort($fields, SORT_STRING);

		return implode("\n", $fields);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return string
	 */
	private static function utm_fingerprint(array $row)
	{
		$enable = !empty($row['utm_enable']) ? '1' : '0';
		$parts = [$enable];
		foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $field) {
			$parts[] = $field . '=' . self::normalize_macros(
				isset($row[$field]) ? (string) $row[$field] : ''
			);
		}

		return implode("\n", $parts);
	}

	/**
	 * @param string $raw
	 * @return string
	 */
	private static function normalize_macros($raw)
	{
		return MacroConverter::convert($raw);
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function json_fingerprint($value)
	{
		$encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return is_string($encoded) ? $encoded : '';
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function ksort_recursive($value)
	{
		if (!is_array($value)) {
			return $value;
		}
		foreach ($value as $k => $v) {
			$value[$k] = self::ksort_recursive($v);
		}
		ksort($value);

		return $value;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return int
	 */
	private static function row_id_int(array $row)
	{
		$id = self::row_id($row);

		return $id === '' ? PHP_INT_MAX : (int) $id;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return int
	 */
	private static function min_row_id(array $rows)
	{
		$min = PHP_INT_MAX;
		foreach ($rows as $row) {
			$id = self::row_id_int($row);
			if ($id < $min) {
				$min = $id;
			}
		}

		return $min;
	}
}
