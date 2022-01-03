<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class ApiHelpers
{
	const RFC3339_EXTENDED = 'Y-m-d\TH:i:s.uP';

	public static function prepare_api_response($resp = [], $field_in_resp = '', $default_value = null)
	{
		$result = [
			$default_value, // response
			null, // error
		];

		if (empty($resp)) {
			return $result;
		}

		if (isset($resp['error'])) {
			$result[1] = $resp['error'];
		}

		if (isset($resp['response'])) {
			if (empty($field_in_resp)) {
				$result[0] = $resp['response'];
			} else {
				$result[0] = $resp['response'][$field_in_resp];
			}
		}

		return $result;
	}

	public static function retrieve_response($resp = [], $field = '')
	{
		if (empty($resp) || empty($resp['response'])) {
			return false;
		}

		if (!empty($field)) {
			return isset($resp['response'][$field]) ? $resp['response'][$field] : null;
		}

		return $resp['response'];
	}

	public static function fix_accounts_photos($accounts = [])
	{
		if (empty($accounts)) {
			return [];
		}

		foreach ($accounts as $key => $account) {
			if (!isset($account['photo'])) {
				$accounts[$key]['photo'] = PP::asset('images/no-photo.svg');
				continue;
			}
			$res = wp_remote_get($account['photo']);

			$res_code = wp_remote_retrieve_response_code($res);
			if ($res_code != "200") {
				$accounts[$key]['photo'] = PP::asset('images/no-photo.svg');
			}

			$corp_header = wp_remote_retrieve_header($res, 'cross-origin-resource-policy');
			if ($corp_header == 'same-origin') {
				$accounts[$key]['photo'] = PP::asset('images/no-photo.svg');
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

	public static function getTimestamp($apiTime)
	{
		$d = new \DateTimeImmutable($apiTime);
		return $d->getTimestamp();
	}

	public static function get_social_network_name($account_id)
	{
		list($user_id, $type, $network_id) = explode(':', $account_id);
		switch ($type) {
		case 'vk':
			return __('VKontakte', 'parrotposter');
		case 'fb':
			return __('Facebook', 'parrotposter');
		case 'ok':
			return __('Odnoklassniki', 'parrotposter');
		case 'tg':
			return __('Telegram', 'parrotposter');
		case 'insta':
			return __('Instagram', 'parrotposter');
		}
		return '';
	}

	public static function list_social_network_names($account_ids, $return_string = true)
	{
		$names = [];
		foreach ($account_ids as $id) {
			$names[] = self::get_social_network_name($id);
		}
		if ($return_string) {
			return implode(', ', $names);
		}
		return $names;
	}

	public static function get_post_status_text($status)
	{
		switch ($status) {
		case 'success':
			return __('Published', 'parrotposter');
		case 'fail':
			return __('Published with error', 'parrotposter');
		case 'ready':
			return __('In queue', 'parrotposter');
		case 'queue':
			return __('Publishing in progress', 'parrotposter');
		}
	}
}
