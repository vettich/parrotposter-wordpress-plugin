<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class Menu
{
	private static $items = null;

	public static function init()
	{
		if (empty(Options::user_id())) {
			self::$items = [];
			return;
		}
		self::$items = [
			[
				'id' => 'parrotposter_posts',
				'label' => _x('Posts', 'menu', 'parrotposter'),
			],
			[
				'id' => 'parrotposter_accounts',
				'label' => _x('Accounts', 'menu', 'parrotposter'),
			],
			[
				'id' => 'parrotposter_scheduler',
				'label' => _x('Scheduler', 'menu', 'parrotposter'),
			],
			[
				'id' => 'parrotposter_tariffs',
				'label' => _x('Tariffs', 'menu', 'parrotposter'),
			],
			[
				'id' => 'parrotposter_profile',
				'label' => _x('Profile', 'menu', 'parrotposter'),
			],
			[
				'id' => 'parrotposter_help',
				'label' => _x('Help', 'menu', 'parrotposter'),
			],
		];
	}

	public static function get_items()
	{
		if (!self::$items) {
			self::init();
		}
		return self::$items;
	}

	public static function get_main_item()
	{
		$items = self::get_items();
		if (!empty($items) && count($items) > 0) {
			return $items[0];
		}
		return null;
	}

	public static function get_link($item)
	{
		if (is_array($item)) {
			$page = $item['id'];
		} elseif (is_string($item)) {
			$page = $item;
		}
		return "admin.php?page=$page";
	}

	public static function the_link($item)
	{
		echo self::get_link($item);
	}

	public static function the_active_class($item)
	{
		$page = isset($_GET['page']) ? $_GET['page'] : '';
		if (isset($item['id']) && $page == $item['id']) {
			echo 'active';
		}
	}
}
