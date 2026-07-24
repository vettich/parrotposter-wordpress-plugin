<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Local published exclude-set cache per pipeline (SPEC-002-09 §7.3 / WP-06).
 *
 * Rows live in `{prefix}parrotposter_exclude_ids`; version meta in wp_options.
 */
class ExcludeCache
{
	public const DB_VERSION = '1.0.10';

	private const OPTION_META = 'parrotposter_exclude_cache_meta';

	/**
	 * @return string
	 */
	public static function table_name()
	{
		global $wpdb;
		return $wpdb->prefix . 'parrotposter_exclude_ids';
	}

	/**
	 * @return string
	 */
	public static function schema_sql()
	{
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table = self::table_name();

		return "CREATE TABLE {$table} (
			pipeline_id varchar(64) NOT NULL,
			source_item_id varchar(191) NOT NULL,
			PRIMARY KEY (pipeline_id, source_item_id),
			KEY idx_exclude_pipeline (pipeline_id)
		) {$charset_collate};";
	}

	/**
	 * @param string $pipeline_id
	 * @return array{version: int, ids: list<string>}
	 */
	public static function get(string $pipeline_id): array
	{
		$meta = self::get_meta($pipeline_id);
		return [
			'version' => (int) ($meta['version'] ?? 0),
			'ids' => self::list_ids($pipeline_id),
		];
	}

	/**
	 * @param string $pipeline_id
	 * @return list<string>
	 */
	public static function list_ids(string $pipeline_id): array
	{
		global $wpdb;
		$table = self::table_name();
		$rows = $wpdb->get_col($wpdb->prepare(
			"SELECT source_item_id FROM {$table} WHERE pipeline_id = %s ORDER BY source_item_id ASC",
			$pipeline_id
		));
		if (!is_array($rows)) {
			return [];
		}
		return array_values(array_map('strval', $rows));
	}

	/**
	 * Replace the whole set for a pipeline (full snapshot page 1 starts replace).
	 *
	 * @param string $pipeline_id
	 * @param list<string> $source_item_ids
	 * @param int $server_version
	 * @param bool $replace_all when true, clear existing rows first
	 */
	public static function apply_snapshot_page(
		string $pipeline_id,
		array $source_item_ids,
		int $server_version,
		bool $replace_all
	): void {
		global $wpdb;
		$table = self::table_name();

		if ($replace_all) {
			$wpdb->delete($table, ['pipeline_id' => $pipeline_id], ['%s']);
		}

		foreach ($source_item_ids as $id) {
			if (!is_string($id) && !is_numeric($id)) {
				continue;
			}
			$id = (string) $id;
			if ($id === '') {
				continue;
			}
			$wpdb->replace(
				$table,
				[
					'pipeline_id' => $pipeline_id,
					'source_item_id' => $id,
				],
				['%s', '%s']
			);
		}

		self::set_meta($pipeline_id, [
			'version' => $server_version,
			'pending_snapshot' => false,
		]);
	}

	/**
	 * Merge delta into the local set and bump client version.
	 *
	 * @param string $pipeline_id
	 * @param int $server_version
	 * @param list<string> $added
	 * @param list<string> $removed
	 */
	public static function apply_delta(
		string $pipeline_id,
		int $server_version,
		array $added,
		array $removed
	): void {
		global $wpdb;
		$table = self::table_name();

		foreach ($removed as $id) {
			if (!is_string($id) && !is_numeric($id)) {
				continue;
			}
			$id = (string) $id;
			if ($id === '') {
				continue;
			}
			$wpdb->delete(
				$table,
				[
					'pipeline_id' => $pipeline_id,
					'source_item_id' => $id,
				],
				['%s', '%s']
			);
		}

		foreach ($added as $id) {
			if (!is_string($id) && !is_numeric($id)) {
				continue;
			}
			$id = (string) $id;
			if ($id === '') {
				continue;
			}
			$wpdb->replace(
				$table,
				[
					'pipeline_id' => $pipeline_id,
					'source_item_id' => $id,
				],
				['%s', '%s']
			);
		}

		self::set_meta($pipeline_id, [
			'version' => $server_version,
			'pending_snapshot' => false,
		]);
	}

	/**
	 * @param string $pipeline_id
	 * @return array<string, mixed>
	 */
	private static function get_meta(string $pipeline_id): array
	{
		$all = get_option(self::OPTION_META, []);
		if (!is_array($all)) {
			$all = [];
		}
		$meta = $all[$pipeline_id] ?? [];
		return is_array($meta) ? $meta : [];
	}

	/**
	 * @param string $pipeline_id
	 * @param array<string, mixed> $meta
	 */
	private static function set_meta(string $pipeline_id, array $meta): void
	{
		$all = get_option(self::OPTION_META, []);
		if (!is_array($all)) {
			$all = [];
		}
		$all[$pipeline_id] = $meta;
		update_option(self::OPTION_META, $all, false);
	}
}
