<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Кэш состояния доменов (доступность ping, ошибки запросов, circuit breaker).
 */
class DomainCache
{
	private const OPTION_KEY = 'parrotposter_pp_domain_state';

	/**
	 * @return array{available_domains: array, last_check_domains: int, errors: array<string, array<int, int>>, pp_down_until: int}
	 */
	public static function empty_state(): array
	{
		return [
			'available_domains' => [],
			'last_check_domains' => 0,
			'errors' => [],
			'pp_down_until' => 0,
		];
	}

	/**
	 * @param array $raw
	 *
	 * @return array{available_domains: array, last_check_domains: int, errors: array, pp_down_until: int}
	 */
	public static function normalize_state(array $raw): array
	{
		$s = self::empty_state();
		if (isset($raw['available_domains']) && is_array($raw['available_domains'])) {
			$s['available_domains'] = $raw['available_domains'];
		}
		if (isset($raw['last_check_domains']) && is_numeric($raw['last_check_domains'])) {
			$s['last_check_domains'] = (int) $raw['last_check_domains'];
		}
		if (isset($raw['errors']) && is_array($raw['errors'])) {
			$s['errors'] = $raw['errors'];
		}
		if (isset($raw['pp_down_until']) && is_numeric($raw['pp_down_until'])) {
			$s['pp_down_until'] = (int) $raw['pp_down_until'];
		}

		return $s;
	}

	/**
	 * @return array{available_domains: array, last_check_domains: int, errors: array, pp_down_until: int}
	 */
	public static function load(): array
	{
		$raw = get_option(self::OPTION_KEY, null);
		if (!is_array($raw)) {
			return self::empty_state();
		}

		return self::normalize_state($raw);
	}

	/**
	 * @param array{available_domains?: array, last_check_domains?: int, errors?: array, pp_down_until?: int} $state
	 */
	public static function save(array $state): void
	{
		update_option(self::OPTION_KEY, self::normalize_state($state), false);
	}

	public static function delete_persistent(): void
	{
		delete_option(self::OPTION_KEY);
	}

	/**
	 * @param callable(array): array $fn
	 */
	public static function with_lock(callable $fn): void
	{
		$lock_key = 'parrotposter_domain_cache_lock';
		$attempts = 30;
		while ($attempts-- > 0) {
			if (get_transient($lock_key)) {
				usleep(50000);
				continue;
			}
			set_transient($lock_key, 1, 30);
			try {
				$state = self::load();
				$new_state = $fn($state);
				if (is_array($new_state)) {
					self::save($new_state);
				}
			} finally {
				delete_transient($lock_key);
			}

			return;
		}
	}
}
