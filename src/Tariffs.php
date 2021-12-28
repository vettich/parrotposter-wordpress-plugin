<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class Tariffs
{
	public static function get_periods()
	{
		return [
			[
				'period' => 1,
				'label' => parrotposter__('1 month'),
				'discount' => 0,
			],
			[
				'period' => 3,
				'label' => parrotposter__('3 month'),
				'discount' => 4,
			],
			[
				'period' => 6,
				'label' => parrotposter__('6 month'),
				'discount' => 10,
			],
			[
				'period' => 12,
				'label' => parrotposter__('1 year'),
				'discount' => 22,
			],
		];
	}

	public static function get_price($tariff)
	{
		if (empty($tariff)) {
			return 0;
		}
		$price = $tariff['price'] / 100;
		return $price;
	}

	public static function get_period_price($tariff, $period)
	{
		if (empty($tariff)) {
			return 0;
		}
		foreach ($tariff['periods'] as $item) {
			if ($item['period'] == $period) {
				return $item['price'] / 100;
			}
		}
	}

	private static $_exchange = 0;
	private static function get_exchange()
	{
		if (self::$_exchange == 0) {
			$res = Api::get_exchange_rate_usd();
			$exrate = ApiHelpers::retrieve_response($res);
			self::$_exchange = $exrate;
		}
		return self::$_exchange;
	}

	public static function rub_to_dollar($price)
	{
		$exrate = self::get_exchange();
		if ($exrate == 0) {
			return 0;
		}
		return round($price / $exrate, 2);
	}

	public static function is_active($code, $expiry_at)
	{
		if ($code == 'trial') {
			return false;
		}
		if (strtotime($expiry_at) < strtotime('now')) {
			return false;
		}
		return true;
	}

	public static function apply_translates(&$tariffs)
	{
		$lang = Tools::get_current_lang();
		foreach ($tariffs as &$tariff) {
			if (isset($tariff['translates'][$lang])) {
				$tariff['name'] = $tariff['translates'][$lang]['name'];
			}
		}
	}
}
