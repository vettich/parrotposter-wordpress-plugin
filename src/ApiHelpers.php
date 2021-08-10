<?php

namespace parrotposter;

use ParrotPoster;

class ApiHelpers
{
	const RFC3339_EXTENDED = 'Y-m-d\TH:i:s.uP';

	public static function retrieve_response($resp = [], $field = '')
	{
		if (empty($resp) || empty($resp['response'])) {
			return false;
		}

		if (!empty($field) && !empty($resp['response'][$field])) {
			return $resp['response'][$field];
		}

		return $resp['response'];
	}

	public static function fix_accounts_photos($accounts = [])
	{
		foreach ($accounts as $key => $account) {
			if (!isset($account['photo'])) {
				continue;
			}
			$res = wp_remote_get($account['photo']);

			$res_code = wp_remote_retrieve_response_code($res);
			if ($res_code != "200") {
				$accounts[$key]['photo'] = ParrotPoster::asset('images/no-photo.png');
			}

			$corp_header = wp_remote_retrieve_header($res, 'cross-origin-resource-policy');
			if ($corp_header == 'same-origin') {
				$accounts[$key]['photo'] = ParrotPoster::asset('images/no-photo.png');
			}
		}
		return $accounts;
	}

	public static function datetimeFormat($strtime)
	{
		$nowtime = strtotime('now');
		if (empty($strtime)) {
			$strtime = $nowtime;
		} else {
			$strtime = strtotime($strtime);
			if ($strtime < $nowtime) {
				$strtime = $nowtime;
			}
		}
		return date(self::RFC3339_EXTENDED, $strtime);
	}
}
