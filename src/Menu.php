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
				'label' => parrotposter__('Posts'),
			],
			[
				'id' => 'parrotposter_accounts',
				'label' => parrotposter__('Accounts'),
			],
			[
				'id' => 'parrotposter_scheduler',
				'label' => parrotposter__('Scheduler'),
			],
			[
				'id' => 'parrotposter_tariffs',
				'label' => parrotposter__('Tariffs'),
			],
			[
				'id' => 'parrotposter_profile',
				'label' => parrotposter__('Profile'),
			],
			[
				'id' => 'parrotposter_help',
				'label' => parrotposter__('Help'),
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
