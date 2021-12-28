<?php

namespace parrotposter\fields;

defined('ABSPATH') || exit;

class Fields
{
	private static $implements = [
		'CommonType',
		'Taxonomies',
		'ProductType',
	];

	public static function get_fields($post_type = 'post', $field_types = ['text', 'link'])
	{
		$fields = [];

		foreach (self::$implements as $impl) {
			$res = self::call($impl, 'get_fields', [$post_type, $field_types]);
			if ($res == null) {
				continue;
			}

			$fields = array_merge($fields, $res);
		}

		return $fields;
	}

	public static function get_field_values($fields, $post)
	{
		$values = [];
		foreach ($fields as $field) {
			$values[$field] = self::get_field_value($field, $post);
		}
		return $values;
	}

	public static function get_field_value($field, $post)
	{
		foreach (self::$implements as $impl) {
			// $res = self::call($impl, "get_field_value_$field", [$post]);
			$res = self::call($impl, "get_field_value", [$field, $post]);
			if ($res !== null) {
				return $res;
			}
		}
	}

	private static function call($class, $method, $args = [])
	{
		$cls = __NAMESPACE__."\\$class";
		if (!class_exists($cls)) {
			$cls = $class;
			if (!class_exists($cls)) {
				return null;
			}
		}

		if (!method_exists($cls, $method)) {
			return null;
		}

		return call_user_func_array([$cls, $method], $args);
	}
}
