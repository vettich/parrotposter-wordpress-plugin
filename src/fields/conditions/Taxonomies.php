<?php

namespace parrotposter\fields\conditions;

defined('ABSPATH') || exit;

class Taxonomies
{
	public static function get_fields($post_type)
	{
		$fields = [];
		$taxonomies = get_object_taxonomies($post_type, 'objects');
		foreach ($taxonomies as $tax) {
			if ($tax->show_ui) {
				$fields[] = [
					'key' => $tax->name,
					'label' => $tax->label,
					'ops' => [
						'equal' => _x('Equals one of', 'wp_post_condition', 'parrotposter'),
						'not_equal' => _x('Not equal to one of', 'wp_post_condition', 'parrotposter'),
					],
					'input' => 'select',
					'values' => self::get_terms($tax->name),
					'multi' => true,
				];
			}
		}
		return $fields;
	}

	public static function check($cond, $post)
	{
		$tax = get_taxonomy($cond['key']);
		if ($tax === false || !$tax->show_ui) {
			return null;
		}

		$terms = wp_get_post_terms($post->ID, $cond['key'], ['fields' => 'ids']);
		if (is_wp_error($terms)) {
			return null;
		}

		return Operations::apply($cond['op'], $terms, $cond['value']);
	}

	public static function get_terms($taxonomy, $parent = 0, $pad = 0)
	{
		$args = [
			'taxonomy' => $taxonomy,
			'parent' => $parent,
			'pad_counts' => true,
			'hide_empty' => false,
		];
		$terms = get_terms($args);
		if (is_wp_error($terms)) {
			return [];
		}

		$list = [];
		foreach ($terms as $term) {
			$list[] = [
				'key' => $term->term_id,
				'label' => str_repeat('— ', $pad)."$term->name ($term->count)",
			];
			$list = array_merge($list, self::get_terms($taxonomy, $term->term_id, $pad + 1));
		}
		return $list;
	}
}
