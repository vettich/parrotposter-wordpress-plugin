<?php

namespace parrotposter;

defined('ABSPATH') || exit;

use parrotposter\fields\Fields;

class TextProcessor
{
	public static function parse_keys($text)
	{
		$text = trim($text);
		if (empty($text)) {
			return [];
		}

		preg_match_all('/\{(?<key>[a-zA-Z_]+)\}/', $text, $matches, PREG_SET_ORDER, 0);

		$keys = [];
		foreach ($matches as $m) {
			$keys[] = $m['key'];
		}

		return $keys;
	}

	public static function replace_post_text($post, $text)
	{
		$text = str_replace(["\n", "\r"], '', $text);
		$keys = self::parse_keys($text);
		$values = Fields::get_field_values($keys, $post);
		foreach ($values as $key => $v) {
			$text = str_replace('{'.$key.'}', $v, $text);
		}
		return $text;
	}

	public static function replace_post_image_text_to_ids($post, $images_txt)
	{
		$keys = self::parse_keys($images_txt);
		$ids = [];
		foreach ($keys as $key) {
			$ids = array_merge($ids, Fields::get_field_value($key, $post));
		}
		return $ids;
	}
}
