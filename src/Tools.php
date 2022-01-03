<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class Tools
{

	// https://www.php.net/manual/ru/function.array-merge-recursive.php#118727
	public static function array_merge_recursive_distinct(array &$array1, array &$array2)
	{
		static $level = 0;
		$merged = $array1;

		foreach ($array2 as $key => &$value) {
			if (is_numeric($key)) {
				$merged [] = $value;
			} else {
				$merged[$key] = $value;
			}

			if (is_array($value) && isset($array1 [$key]) && is_array($array1 [$key])) {
				$level++;
				$merged [$key] = self::array_merge_recursive_distinct($array1 [$key], $value);
				$level--;
			}
		}
		unset($merged["mergeWithParent"]);
		return $merged;
	}

	public static function clear_text($text)
	{
		$replaces = [
			'&nbsp;' => ' ',
		];
		$text = str_replace(array_keys($replaces), array_values($replaces), $text);
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
		$text = trim($text);
		$text = preg_replace("/(?:\r?\n|\r){2,}/", "\n\n", $text);
		return $text;
	}

	public static function get_current_lang()
	{
		$locale = get_user_locale();
		$locale_parsed = explode('_', $locale);
		if (count($locale_parsed) > 0) {
			return $locale_parsed[0];
		}
		return 'en';
	}

	public static function clear_account_link($link)
	{
		$replaces = [
			'http://' => '',
			'https://' => '',
			'instagram.com/' => '@',
			'facebook.com/' => 'fb.com/',
			'telegram.com/' => 't.me/',
		];
		return str_replace(array_keys($replaces), array_values($replaces), $link);
	}

	public static function arr_value($arr, $field, $default = '')
	{
		if (isset($arr[$field])) {
			return $arr[$field];
		}
		return $default;
	}

	public static function clear_null_from_array($arr)
	{
		$result = [];
		foreach ($arr as $k => $v) {
			if ($v === null) {
				continue;
			}
			$result[$k] = $v;
		}
		return $result;
	}

	public static function in_array($needle, $haystack)
	{
		foreach ($haystack as $v) {
			if ($needle == $v) {
				return true;
			}
		}
		return false;
	}
}
