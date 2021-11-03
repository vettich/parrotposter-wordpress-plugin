<?php

namespace parrotposter;

use ParrotPoster;
use parrotposter\Api;

class Profile
{
	public static function get_info()
	{
		$res = Api::me();
		$user = $res['response'] ?: [];

		if (!empty($user)) {
			$res = Api::get_tariff($user['tariff']['id'] ?: $user['tariff']['code']);
			$tariff = $res['response'] ?: [];
			if (isset($res['error'])) {
				$error_msg = $res['error']['msg'];
			}
			$lang = Tools::get_current_lang();
			if (isset($tariff['translates'][$lang])) {
				$tariff['name'] = $tariff['translates'][$lang]['name'];
			}

			$interval = (new \DateTime('now'))->diff(new \DateTime($user['tariff']['expiry_at']));
			if ($interval->invert) {
				$left = parrotposter__('(expired)');
			} elseif ($interval->y > 0) {
				$left = parrotposter_n('(left %s year)', '(left %s years)', $interval->y, $interval->y);
			} elseif ($interval->m > 0) {
				$left = parrotposter_n('(left %s month)', '(left %s months)', $interval->m, $interval->m);
			} elseif ($interval->d > 0) {
				$left = parrotposter_n('(left %s day)', '(left %s days)', $interval->d, $interval->d);
			}
		}

		return [
			'error' => isset($res['error']) ? $res['error'] : null,
			'user' => $user,
			'tariff' => $tariff,
			'left' => $left,
		];
	}
}
