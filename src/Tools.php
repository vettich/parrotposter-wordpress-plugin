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
			'<br>' => "\n",
			'<br/>' => "\n",
		];
		$text = str_replace(array_keys($replaces), array_values($replaces), $text);
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
		$text = trim($text);
		$text = preg_replace("/(?:\r?\n|\r){2,}/", "\n\n", $text);
		return $text;
	}

	/**
	 * First paragraph from raw post_content (no the_content filters).
	 */
	public static function content_first_paragraph(string $content): string
	{
		$content = trim($content);
		if ($content === '') {
			return '';
		}

		if (function_exists('parse_blocks')) {
			$from_blocks = self::first_paragraph_from_blocks(parse_blocks($content));
			if ($from_blocks !== '') {
				return $from_blocks;
			}
		}

		if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $content, $matches)) {
			$text = self::clear_text($matches[1]);
			if ($text !== '') {
				return $text;
			}
		}

		$text = self::clear_text($content);
		if ($text === '') {
			return '';
		}

		$parts = preg_split("/\n\n+/", $text);
		foreach ($parts as $part) {
			$part = trim($part);
			if ($part !== '') {
				return $part;
			}
		}

		return '';
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 */
	private static function first_paragraph_from_blocks(array $blocks): string
	{
		foreach ($blocks as $block) {
			if (!is_array($block)) {
				continue;
			}

			$name = $block['blockName'] ?? null;
			if ($name === 'core/paragraph') {
				$html = '';
				if (!empty($block['innerHTML'])) {
					$html = (string) $block['innerHTML'];
				} elseif (!empty($block['innerContent']) && is_array($block['innerContent'])) {
					$html = implode('', $block['innerContent']);
				}
				$text = self::clear_text($html);
				if ($text !== '') {
					return $text;
				}
				continue;
			}

			if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$nested = self::first_paragraph_from_blocks($block['innerBlocks']);
				if ($nested !== '') {
					return $nested;
				}
			}
		}

		return '';
	}

	public static function truncate_text($text, $length = 100, $more = '...') {
		$text = trim($text);
		if (strlen($text) <= $length) {
			return $text;
		}

		$text = substr($text, 0, $length);
		$end = strrpos($text, ' ', strlen($more) * -1);
		if ($end !== false) {
			$text = substr($text, 0, $end).' ';
		}
		$text .= $more;

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

	public static function str_starts_with(string $haystack, string $needle): bool
	{
		if ($needle === '') {
			return true;
		}

		return strncmp($haystack, $needle, strlen($needle)) === 0;
	}

	/**
	 * Prepare post `fields` for ParrotPoster REST API.
	 *
	 * On update, omit empty `images` / `image_urls` so the server treats media as unchanged
	 * (null/omit), not as an explicit clear (`[]`).
	 *
	 * @param array<string, mixed> $fields
	 * @param bool                 $omit_empty_media
	 *
	 * @return array<string, mixed>
	 */
	public static function filter_post_fields_for_api(array $fields, bool $omit_empty_media = false): array
	{
		if (!$omit_empty_media) {
			return $fields;
		}

		if (empty($fields['images'])) {
			unset($fields['images']);
		}
		if (empty($fields['image_urls'])) {
			unset($fields['image_urls']);
		}

		return $fields;
	}
}
