<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Per-admin UI preferences (user_meta), separate from site-level Settings.
 */
class UserPreferences
{
	private const SHOW_MIGRATION_BANNER_KEY = 'parrotposter_show_migration_banner';

	public static function show_migration_banner(?int $user_id = null): bool
	{
		$user_id = self::resolve_user_id($user_id);
		if ($user_id <= 0) {
			return true;
		}

		$value = get_user_meta($user_id, self::SHOW_MIGRATION_BANNER_KEY, true);
		if ($value === '' || $value === false) {
			return true;
		}

		return $value === '1' || $value === 1 || $value === true;
	}

	public static function set_show_migration_banner(bool $show, ?int $user_id = null): void
	{
		$user_id = self::resolve_user_id($user_id);
		if ($user_id <= 0) {
			return;
		}

		update_user_meta($user_id, self::SHOW_MIGRATION_BANNER_KEY, $show ? '1' : '0');
	}

	private static function resolve_user_id(?int $user_id): int
	{
		if ($user_id !== null && $user_id > 0) {
			return $user_id;
		}

		return (int) get_current_user_id();
	}
}
