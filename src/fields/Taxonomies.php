<?php

namespace parrotposter\fields;

defined('ABSPATH') || exit;

class Taxonomies
{
	public static function get_fields($post_type, $field_types)
	{
		$fields = [];
		foreach ($field_types as $type) {
			$fields = array_merge($fields, self::get_fields_by_type($post_type, $type));
		}
		return $fields;
	}

	private static function get_fields_by_type($post_type, $field_type)
	{
		$fn = "get_{$field_type}_fields";
		return method_exists(get_class(), $fn) ? self::$fn($post_type) : [];
	}

	private static function get_text_fields($post_type)
	{
		$fields = [];
		$taxonomies = get_object_taxonomies($post_type, 'objects');
		foreach ($taxonomies as $tax) {
			if ($tax->show_ui) {
				$fields[] = [
					'key' => sprintf('{%s}', $tax->name),
					'label' => $tax->label,
				];
			}
		}
		return $fields;
	}

	public static function get_field_value($field, $post)
	{
		$terms = wp_get_post_terms($post->ID, $field, ['fields' => 'names']);
		if (!is_array($terms)) {
			return null;
		}
		return implode(', ', $terms);
	}
}
