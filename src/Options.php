<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class Options
{
	const KEY = 'parrotposter_options';
	const USER_ID_KEY = 'parrotposter_user_id';
	const TOKEN_KEY = 'parrotposter_token';

	public static function options()
	{
		$options = get_option(self::KEY, []);
		return $options;
	}

	public static function user_id()
	{
		return get_option(self::USER_ID_KEY);
	}

	public static function token()
	{
		return get_option(self::TOKEN_KEY);
	}

	public static function set_user_data($userId, $token)
	{
		update_option(self::USER_ID_KEY, $userId);
		update_option(self::TOKEN_KEY, $token);
	}

	public static function delete_data()
	{
		delete_option(self::USER_ID_KEY);
		delete_option(self::TOKEN_KEY);
	}

	public static function log_enabled()
	{
		$options = self::options();
		if (!isset($options['log_enabled'])) {
			return false;
		}
		return $options['log_enabled'];
	}
}
