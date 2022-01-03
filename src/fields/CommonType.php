<?php

namespace parrotposter\fields;

defined('ABSPATH') || exit;

use parrotposter\WpPostHelpers;

class CommonType
{
	public static function get_fields($post_type, $field_types)
	{
		$fields = [];
		foreach ($field_types as $type) {
			$fields = array_merge($fields, self::get_fields_by_type($type));
		}
		return $fields;
	}

	private static function get_fields_by_type($type)
	{
		$fn = "get_{$type}_fields";
		return method_exists(get_class(), $fn) ? self::$fn() : [];
	}

	private static function get_text_fields()
	{
		$fields = [
			[
				'key' => '{br}',
				'label' => _x('Break line', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{title}',
				'label' => _x('Title', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{content}',
				'label' => _x('Content', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{excerpt}',
				'label' => _x('Excerpt', 'wp_post_field', 'parrotposter'),
			],
		];
		return $fields;
	}

	private static function get_link_fields()
	{
		$fields = [
			[
				'key' => '{link}',
				'label' => _x('Link', 'wp_post_field', 'parrotposter'),
			],
		];
		return $fields;
	}

	private static function get_date_fields()
	{
		$fields = [
			[
				'key' => '{date}',
				'label' => _x('Date', 'wp_post_field', 'parrotposter'),
			],
		];
		return $fields;
	}

	private static function get_image_fields()
	{
		$fields = [
			[
				'key' => '{featured_image}',
				'label' => _x('Featured image', 'wp_post_field', 'parrotposter'),
			],
			[
				'key' => '{images_in_content}',
				'label' => _x('Images in content', 'wp_post_field', 'parrotposter'),
			],
		];
		return $fields;
	}

	public static function get_field_value($field, $post)
	{
		$fn = "get_field_value_{$field}";
		if (!method_exists(get_class(), $fn)) {
			return null;
		}

		return self::$fn($post);
	}

	private static function get_field_value_br($post)
	{
		return "\n";
	}

	private static function get_field_value_social_code($post)
	{
		return '#SOCIAL_CODE#';
	}

	private static function get_field_value_title($post)
	{
		return $post->post_title;
	}

	private static function get_field_value_excerpt($post)
	{
		$excerpt = $post->post_excerpt;
		if (empty($excerpt)) {
			$excerpt = wp_trim_excerpt('', $post);
			$excerpt = str_replace('[&hellip;]', '', $excerpt);
		}
		return $excerpt;
	}

	private static function get_field_value_content($post)
	{
		return $post->post_content;
	}

	private static function get_field_value_link($post)
	{
		return urldecode(get_permalink($post));
	}

	private static function get_field_value_date($post)
	{
		return $post->post_date;
	}

	private static function get_field_value_featured_image($post)
	{
		$id = get_post_thumbnail_id($post);
		if (empty($id)) {
			return [];
		}
		return [$id];
	}

	private static function get_field_value_images_in_content($post)
	{
		$content = apply_filters('the_content', $post->post_content);
		return WpPostHelpers::get_image_ids_from_content($content);
	}
}
