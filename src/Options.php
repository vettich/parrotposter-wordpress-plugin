<?php

namespace parrotposter;

class Options
{
	const KEY = 'parrotposter_options';

	public static function options()
	{
		$options = get_option(self::KEY, []);
		return $options;
	}

	public static function user_id()
	{
		$options = self::options();
		if (!isset($options['user_id'])) {
			return '';
		}
		return $options['user_id'];
	}

	public static function token()
	{
		$options = self::options();
		if (!isset($options['token'])) {
			return '';
		}
		return $options['token'];
	}

	public static function set_user_data($userId, $token)
	{
		$options = self::options();
		$options['user_id'] = $userId;
		$options['token'] = $token;
		update_option(self::KEY, $options);
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
