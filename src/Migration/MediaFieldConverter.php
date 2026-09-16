<?php

namespace parrotposter\Migration;

defined('ABSPATH') || exit;

/**
 * Map legacy WP media field keys to GraphQL TemplateMediaSourceRefInput.
 */
class MediaFieldConverter
{
	/**
	 * @param mixed $post_images
	 * @return array{sources: list<array<string, mixed>>, warnings: list<string>}
	 */
	public static function convert($post_images)
	{
		$warnings = [];
		$sources = [];
		if (!is_array($post_images)) {
			return ['sources' => $sources, 'warnings' => $warnings];
		}

		foreach ($post_images as $raw) {
			if (!is_string($raw) && !is_numeric($raw)) {
				continue;
			}
			$field = self::normalize_field_key((string) $raw);
			if ($field === '') {
				continue;
			}
			if ($field === 'content_images') {
				$warnings[] = 'Unrecognized media field content_images mapped to images_in_content';
				$field = 'images_in_content';
			}
			$sources[] = [
				'field' => $field,
				'mode' => $field === 'images_in_content' ? 'MANY' : 'ONE',
				'kind' => 'IMAGE',
				'source' => 'DEFAULT',
			];
		}

		return ['sources' => $sources, 'warnings' => $warnings];
	}

	/**
	 * @param string $key
	 * @return string
	 */
	public static function normalize_field_key($key)
	{
		$key = trim($key);
		if ($key === '') {
			return '';
		}
		if (isset($key[0]) && $key[0] === '{' && substr($key, -1) === '}') {
			$key = substr($key, 1, -1);
		}

		return $key;
	}
}
