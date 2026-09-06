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

		$fallback = PP::asset('images/no-photo.svg');
		foreach ($accounts as $key => $account) {
			$photo = isset($account['photo']) ? trim((string) $account['photo']) : '';
			if ($photo === '') {
				$accounts[$key]['photo'] = $fallback;
			}
		}

		return $accounts;
	}

	public static function formatCurrentDatetime($addMinutes = 0) {
		$datetime = current_datetime();
		if ($addMinutes > 0) {
			$datetime = $datetime->modify("+{$addMinutes} minutes");
		}
		return $datetime->format(self::RFC3339_EXTENDED);
	}

	public static function formatISO8601Datetime($datetime) {
		$dt = new \DateTimeImmutable($datetime);
		return $dt->format(self::RFC3339_EXTENDED);
	}

	public static function getTimestamp($apiTime)
	{
		$d = new \DateTimeImmutable($apiTime);
		return $d->getTimestamp();
	}

	/**
	 * Social type from `{user_id}:{type}:{group_id}` (and aliases like `ig` → `insta`).
	 */
	public static function get_account_social_type($account_id)
	{
		$parts = explode(':', (string) $account_id);
		$type = isset($parts[1]) ? strtolower($parts[1]) : '';
		if ($type === 'ig' || $type === 'instagram' || $type === 'inst') {
			return 'insta';
		}

		return $type;
	}

	public static function get_social_network_name($account_id)
	{
		switch (self::get_account_social_type($account_id)) {
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
		case 'max':
			return __('Max', 'parrotposter');
		}
		return '';
	}

	public static function list_social_network_names($account_ids, $return_string = true)
	{
		$names = [];
		$seen = [];
		if (!is_array($account_ids)) {
			$account_ids = [];
		}
		foreach ($account_ids as $id) {
			$name = self::get_social_network_name($id);
			if ($name === '' || isset($seen[$name])) {
				continue;
			}
			$seen[$name] = true;
			$names[] = $name;
		}
		if ($return_string) {
			return implode(', ', $names);
		}
		return $names;
	}

	/**
	 * Unique social types from account ids, for icon rendering (no links).
	 *
	 * @param array $account_ids
	 * @return list<array{type: string, link: string}>
	 */
	public static function socials_from_account_ids($account_ids)
	{
		$socials = [];
		$seen = [];
		if (!is_array($account_ids)) {
			return $socials;
		}
		foreach ($account_ids as $id) {
			$type = self::get_account_social_type($id);
			if ($type === '' || isset($seen[$type])) {
				continue;
			}
			$seen[$type] = true;
			$socials[] = [
				'type' => $type,
				'link' => '',
			];
		}

		return $socials;
	}

	/**
	 * id → {name, photo, type} from REST `accounts`, cached in a transient.
	 *
	 * @return array<string, array{name: string, photo: string, type: string}>
	 */
	public static function accounts_directory(): array
	{
		$key = 'parrotposter_accounts_dir';
		if (function_exists('get_transient')) {
			$cached = get_transient($key);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$dir = [];
		if (!class_exists(__NAMESPACE__ . '\\Api', false)) {
			return $dir;
		}

		list($accounts, $error) = Api::list_accounts();
		if (empty($error) && is_array($accounts)) {
			foreach ($accounts as $account) {
				if (!is_array($account)) {
					continue;
				}
				$id = isset($account['id']) ? (string) $account['id'] : '';
				if ($id === '') {
					continue;
				}
				$dir[$id] = [
					'name' => isset($account['name']) ? (string) $account['name'] : '',
					'photo' => isset($account['photo']) ? (string) $account['photo'] : '',
					'type' => isset($account['type']) ? (string) $account['type'] : '',
				];
			}
		}

		if (function_exists('set_transient')) {
			$ttl = defined('MINUTE_IN_SECONDS') ? 15 * MINUTE_IN_SECONDS : 900;
			set_transient($key, $dir, $ttl);
		}

		return $dir;
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
		case 'prepare':
			return __('Preparing', 'parrotposter');
		case 'updating':
			return __('Updating', 'parrotposter');
		case 'deleting':
			return __('Deleting', 'parrotposter');
		default:
			return (string) $status;
		}
	}
}
