<?php

namespace parrotposter\fields;

defined('ABSPATH') || exit;

class PostType
{
	public static function get_fields($post_type, $field_types)
	{
		if ($post_type !== 'post') {
			return [];
		}

		$fields = [];
		foreach ($field_types as $type) {
			$fields = array_merge($fields, self::get_fields_by_type($type));
		}
		return $fields;
	}

	private static function get_fields_by_type($type)
	{
		$fn = "get_{$type}_fields";
		return method_exists(get_class(), $fn) ? self::$fn() : [];
	}

	private static function get_text_fields()
	{
		$fields = [
			[
				'key' => '{categories}',
				'label' => parrotposter_x('Post categories', 'wp_post_field'),
			],
		];
		return $fields;
	}

	private static function get_link_fields()
	{
		$fields = [
		];
		return $fields;
	}

	private static function get_date_fields()
	{
		$fields = [
		];
		return $fields;
	}

	private static function get_image_fields()
	{
		$fields = [
		];
		return $fields;
	}
}
