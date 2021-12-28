<?php

namespace parrotposter\fields\conditions;

defined('ABSPATH') || exit;

class Common
{
	public static function get_fields($post_type)
	{
		$fields = [
			[
				'key' => 'title',
				'label' => parrotposter__('Title'),
				'ops' => [
					'include' => parrotposter_x('Include', 'wp_post_condition'),
					'not_include' => parrotposter_x('Not include', 'wp_post_condition'),
				],
				'input' => 'text',
			],
			[
				'key' => 'author',
				'label' => parrotposter__('Author'),
				'ops' => [
					'equal' => parrotposter_x('Equals one of', 'wp_post_condition'),
					'not_equal' => parrotposter_x('Not equal to one of', 'wp_post_condition'),
				],
				'input' => 'select',
				'values' => self::get_users_list(),
				'multi' => true,
			],
		];
		return $fields;
	}

	public static function check($cond, $post)
	{
		$fn = "check_{$cond['key']}";
		if (!method_exists(get_class(), $fn)) {
			return null;
		}

		return self::$fn($cond, $post);
	}

	private static function check_title($cond, $post)
	{
		return Operations::apply($cond['op'], $post->post_title, $cond['value']);
	}

	private static function check_author($cond, $post)
	{
		return Operations::apply($cond['op'], $post->post_author, $cond['value']);
	}

	private static function get_users_list()
	{
		$users = get_users(['fields' => ['ID', 'display_name']]);
		$list = [];
		foreach ($users as $user) {
			$list[] = [
				'key' => $user->ID,
				'label' => $user->display_name,
			];
		}
		return $list;
	}
}
