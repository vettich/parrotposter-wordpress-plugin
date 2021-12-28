<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class DBAutopostingTable
{
	public static function get_by_id($id)
	{
		global $wpdb;
		$id = intval($id);
		$data = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}parrotposter_autoposting WHERE id = $id",
			ARRAY_A
		);
		$data = self::autoposting_from_db($data);
		return $data;
	}

	public static function get_all($only_is_enable = true)
	{
		global $wpdb;
		$sql = "SELECT * FROM {$wpdb->prefix}parrotposter_autoposting";
		if ($only_is_enable) {
			$sql .= " WHERE enable = 1";
		}
		$items = $wpdb->get_results($sql, ARRAY_A);
		foreach ($items as $k => $item) {
			$items[$k] = DB::autoposting_from_db($item);
		}
		return $items;
	}

	public static function get_total_count()
	{
		global $wpdb;
		$total = $wpdb->get_var("SELECT count(*) FROM {$wpdb->prefix}parrotposter_autoposting");
		return $total;
	}

	public static function get_list($limit, $offset)
	{
		global $wpdb;
		$limit = intval($limit);
		$offset = intval($offset);
		$items = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}parrotposter_autoposting LIMIT $limit OFFSET $offset",
			ARRAY_A
		);
		foreach ($items as $k => $item) {
			$items[$k] = DB::autoposting_from_db($item);
		}
		return $items;
	}

	public static function insert($data, $format)
	{
		global $wpdb;
		$data = DB::autoposting_to_db($data);
		$res = $wpdb->insert($wpdb->prefix.'parrotposter_autoposting', $data, $format);
		if ($res === false) {
			return $wpdb->last_error;
		}
		return false;
	}

	public static function update($id, $data, $format)
	{
		global $wpdb;
		$data = DB::autoposting_to_db($data);
		$res = $wpdb->update(
			$wpdb->prefix.'parrotposter_autoposting',
			$data,
			['id' => intval($id)],
			$format,
			['%d']
		);
		if ($res === false) {
			return $wpdb->last_error;
		}
		return false;
	}

	public static function switch_enable($id, $enable)
	{
		global $wpdb;
		$data = ['enable' => intval($enable)];
		$format = ['%d'];
		$where = ['id' => intval($id)];
		$where_format = ['%d'];
		$wpdb->update($wpdb->prefix.'parrotposter_autoposting', $data, $where, $format, $where_format);
		if ($res === false) {
			return $wpdb->last_error;
		}
		return false;
	}

	public static function delete($id)
	{
		global $wpdb;
		$res = $wpdb->delete($wpdb->prefix.'parrotposter_autoposting', ['id' => intval($id)], ['%d']);
		if ($res === false) {
			return $wpdb->last_error;
		}
		return false;
	}

	protected static function autoposting_to_db($data)
	{
		if (isset($data['conditions'])) {
			$data['conditions'] = empty($data['conditions']) ? '[]' : json_encode($data['conditions']);
		}

		if (isset($data['account_ids'])) {
			$data['account_ids'] = empty($data['account_ids']) ? '[]' : json_encode($data['account_ids']);
		}

		if (isset($data['post_images'])) {
			$data['post_images'] = empty($data['post_images']) ? '[]' : json_encode($data['post_images']);
		}

		return $data;
	}

	protected static function autoposting_from_db($data)
	{
		// decode json arrays
		foreach (['conditions', 'account_ids', 'post_images'] as $key) {
			if (empty($data[$key])) {
				$data[$key] = [];
				continue;
			}

			if (!is_string($data[$key])) {
				continue;
			}

			$data[$key] = json_decode($data[$key], true);
		}

		return $data;
	}
}
