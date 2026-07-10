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
	 * First substantive text block from raw post_content (no the_content filters).
	 *
	 * Walks content in document order: paragraphs, list items, headings.
	 * Skips image-only paragraphs and decorative separators (e.g. "-----").
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

		return self::first_paragraph_from_html($content);
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
				$text = self::paragraph_text_from_html_fragment(self::block_inner_html($block));
				if (self::is_substantive_paragraph_text($text)) {
					return $text;
				}
				continue;
			}

			if ($name === 'core/list-item') {
				$text = self::paragraph_text_from_html_fragment(self::block_inner_html($block));
				if (self::is_substantive_paragraph_text($text)) {
					return $text;
				}
				continue;
			}

			if ($name === 'core/list') {
				if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
					$nested = self::first_paragraph_from_blocks($block['innerBlocks']);
					if ($nested !== '') {
						return $nested;
					}
				}
				continue;
			}

			if ($name === null || $name === 'core/html' || $name === 'core/freeform') {
				$html = self::block_inner_html($block);
				if ($html !== '') {
					$from_html = self::first_paragraph_from_html($html);
					if ($from_html !== '') {
						return $from_html;
					}
				}
				continue;
			}

			if (in_array($name, ['core/image', 'core/gallery', 'core/separator', 'core/spacer'], true)) {
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

	/**
	 * @param array<string, mixed> $block
	 */
	private static function block_inner_html(array $block): string
	{
		if (!empty($block['innerHTML'])) {
			return (string) $block['innerHTML'];
		}
		if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
			return implode('', $block['innerContent']);
		}
		return '';
	}

	public static function is_substantive_paragraph_text(string $text): bool
	{
		$text = trim($text);
		if ($text === '') {
			return false;
		}
		if (preg_match('/^[-–—_=.*·•\s]+$/u', $text)) {
			return false;
		}
		return (bool) preg_match('/\p{L}/u', $text);
	}

	public static function paragraph_text_from_html_fragment(string $html): string
	{
		return self::clear_text($html);
	}

	public static function first_paragraph_from_html(string $html): string
	{
		$html = trim($html);
		if ($html === '') {
			return '';
		}

		if (class_exists('DOMDocument')) {
			$from_dom = self::first_paragraph_from_html_dom($html);
			if ($from_dom !== '') {
				return $from_dom;
			}
		}

		$from_regex = self::first_paragraph_from_html_regex($html);
		if ($from_regex !== '') {
			return $from_regex;
		}

		if (strpos($html, '<') === false) {
			$parts = preg_split("/\n\n+/", $html);
			if (is_array($parts)) {
				foreach ($parts as $part) {
					$part = trim($part);
					if (self::is_substantive_paragraph_text($part)) {
						return $part;
					}
				}
			}
			if (self::is_substantive_paragraph_text($html)) {
				return $html;
			}
		}

		return '';
	}

	private static function first_paragraph_from_html_dom(string $html): string
	{
		$previous = libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8"><div id="pp-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (!$loaded) {
			return '';
		}

		$xpath = new \DOMXPath($dom);
		$nodes = $xpath->query('//*[@id="pp-root"]//p | //*[@id="pp-root"]//li | //*[@id="pp-root"]//h1 | //*[@id="pp-root"]//h2 | //*[@id="pp-root"]//h3 | //*[@id="pp-root"]//h4 | //*[@id="pp-root"]//h5 | //*[@id="pp-root"]//h6');
		if ($nodes === false) {
			return '';
		}

		foreach ($nodes as $node) {
			if (!$node instanceof \DOMElement) {
				continue;
			}
			$tag = strtolower($node->tagName);
			$inner_html = '';
			foreach ($node->childNodes as $child) {
				$inner_html .= $dom->saveHTML($child);
			}
			if ($tag === 'p' && self::is_media_only_html($inner_html)) {
				continue;
			}
			$text = self::paragraph_text_from_html_fragment($inner_html);
			if (self::is_substantive_paragraph_text($text)) {
				return $text;
			}
		}

		return '';
	}

	private static function first_paragraph_from_html_regex(string $html): string
	{
		$patterns = [
			'/<p[^>]*>(.*?)<\/p>/is',
			'/<li[^>]*>(.*?)<\/li>/is',
			'/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is',
		];

		$offset = 0;
		$length = strlen($html);
		while ($offset < $length) {
			$best_pos = null;
			$best_inner = null;
			$best_tag = null;

			foreach ($patterns as $pattern) {
				if (!preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE, $offset)) {
					continue;
				}
				$pos = $matches[0][1];
				if ($best_pos === null || $pos < $best_pos) {
					$best_pos = $pos;
					$best_inner = $matches[1][0];
					$best_tag = $pattern;
				}
			}

			if ($best_pos === null || $best_inner === null || $best_tag === null) {
				break;
			}

			$offset = $best_pos + 1;
			if ($best_tag === '/<p[^>]*>(.*?)<\/p>/is' && self::is_media_only_html($best_inner)) {
				continue;
			}
			$text = self::paragraph_text_from_html_fragment($best_inner);
			if (self::is_substantive_paragraph_text($text)) {
				return $text;
			}
		}

		return '';
	}

	private static function is_media_only_html(string $html): bool
	{
		$stripped = preg_replace('/<(img|figure|picture|video|audio|iframe|embed|source|track|svg|canvas)\b[^>]*\/?>\s*/i', '', $html);
		if ($stripped === null) {
			return false;
		}
		$stripped = preg_replace('/<br\s*\/?>\s*/i', '', $stripped);
		if ($stripped === null) {
			return false;
		}
		return !self::is_substantive_paragraph_text(self::paragraph_text_from_html_fragment($stripped));
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
