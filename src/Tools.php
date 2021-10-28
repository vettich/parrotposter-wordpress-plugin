<?php

namespace parrotposter;

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
		return trim(html_entity_decode(strip_tags($text)));
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
}
