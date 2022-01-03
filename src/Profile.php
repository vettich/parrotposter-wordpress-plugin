<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class Profile
{
	public static function get_info()
	{
		list($user, $error) = Api::me();

		if (!empty($user)) {
			list($tariff, $error) = Api::get_tariff($user['tariff']['id'] ?: $user['tariff']['code']);

			$lang = Tools::get_current_lang();
			if (isset($tariff['translates'][$lang])) {
				$tariff['name'] = $tariff['translates'][$lang]['name'];
			}

			$interval = (new \DateTime('now'))->diff(new \DateTime($user['tariff']['expiry_at']));
			if ($interval->invert) {
				$left = _x('(expired)', 'tariff expired', 'parrotposter');
			} elseif ($interval->y > 0) {
				$left = sprintf(_n('(left %s year)', '(left %s years)', $interval->y, 'parrotposter'), $interval->y);
			} elseif ($interval->m > 0) {
				$left = sprintf(_n('(left %s month)', '(left %s months)', $interval->m, 'parrotposter'), $interval->m);
			} elseif ($interval->d > 0) {
				$left = sprintf(_n('(left %s day)', '(left %s days)', $interval->d, 'parrotposter'), $interval->d);
			}
		}

		return [
			'error' => $error,
			'user' => $user,
			'tariff' => $tariff,
			'left' => $left,
		];
	}
}
