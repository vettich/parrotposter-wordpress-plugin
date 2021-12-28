<?php

namespace parrotposter\fields\conditions;

defined('ABSPATH') || exit;

class Conditions
{
	private static $implements = [
		'Common',
		'Taxonomies',
		'ProductType',
	];

	public static function get_fields($post_type = 'post')
	{
		$fields = [];

		foreach (self::$implements as $impl) {
			$res = self::call($impl, 'get_fields', [$post_type]);
			if ($res == null) {
				continue;
			}

			$fields = array_merge($fields, $res);
		}

		return $fields;
	}

	public static function check($conditions, $post)
	{
		foreach ($conditions as $cond) {
			foreach (self::$implements as $impl) {
				$res = self::call($impl, "check", [$cond, $post]);
				if ($res === false) {
					return false;
				}
			}
		}
		return true;
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
