<?php

namespace parrotposter\Migration;

defined('ABSPATH') || exit;

/**
 * Convert legacy WP autopost macros ({title} / {{ title }}) into pipeline Liquid.
 */
class MacroConverter
{
	const SOCIAL_CODE_PLACEHOLDER = '#SOCIAL_CODE#';

	/**
	 * @param string $input
	 * @return string
	 */
	public static function convert($input)
	{
		if (!is_string($input) || $input === '') {
			return '';
		}

		$converted = preg_replace_callback(
			'/\{\{?\s*([a-zA-Z0-9_]+)\s*\}\}?/',
			[self::class, 'replace_macro'],
			$input
		);

		return is_string($converted) ? $converted : $input;
	}

	/**
	 * @param array<int, string> $caps
	 * @return string
	 */
	private static function replace_macro(array $caps)
	{
		$key = isset($caps[1]) ? $caps[1] : '';
		switch ($key) {
			case 'br':
				return "\n";
			case 'title':
				return '{{ item.title }}';
			case 'excerpt':
				return '{{ item.excerpt }}';
			case 'url':
			case 'link':
				return '{{ item.link }}';
			case 'social_code':
				return self::SOCIAL_CODE_PLACEHOLDER;
			default:
				if (strpos($key, 'item.') === 0) {
					return '{{ item.' . $key . ' }}';
				}

				return '{{ item.' . $key . ' }}';
		}
	}
}
