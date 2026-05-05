<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Выбор доступного домена ParrotPoster по ping и учёт сетевых ошибок.
 */
class DomainSelector
{
	private const HTTP_TIMEOUT = 2;

	private const UNAVAILABLE_PING = -1;

	private const ERROR_CACHE_TTL = 300;

	private const ERROR_THRESHOLD = 2;

	private static function state(): array
	{
		return DomainCache::load();
	}

	private static function has_recent_errors(string $domain): bool
	{
		$hash = md5($domain);
		$state = self::state();
		$errors = isset($state['errors'][$hash]) && is_array($state['errors'][$hash]) ? $state['errors'][$hash] : [];
		$cutoff = time() - self::ERROR_CACHE_TTL;
		$recent_errors = array_filter($errors, static function ($time) use ($cutoff) {
			return $time > $cutoff;
		});

		return count($recent_errors) >= self::ERROR_THRESHOLD;
	}

	/**
	 * Домен с учётом недавних ошибок (при необходимости принудительное обновление).
	 *
	 * @return string|false
	 */
	public static function get_reliable_domain()
	{
		$domain = static::get_best_domain();
		if (!$domain) {
			return false;
		}

		if (static::has_recent_errors($domain)) {
			static::force_refresh();
			$domain = static::get_best_domain();
		}

		return $domain;
	}

	/**
	 * Наиболее быстрый доступный домен.
	 *
	 * @return string|false
	 */
	public static function get_best_domain()
	{
		static::update_domains_if_need();

		$available = static::get_available_domains();
		if (empty($available)) {
			return false;
		}

		usort($available, static function ($a, $b) {
			return ($a['ping'] ?? PHP_INT_MAX) <=> ($b['ping'] ?? PHP_INT_MAX);
		});

		return $available[0]['domain'];
	}

	/**
	 * Список доменов по приоритету (ping), без проблемных по ошибкам.
	 *
	 * @return string[]
	 */
	public static function get_priority_domains(bool $force_refresh = false): array
	{
		if ($force_refresh) {
			static::force_refresh();
		} else {
			static::update_domains_if_need();
		}

		$available_domains = self::state()['available_domains'] ?: [];
		if (empty($available_domains) || !is_array($available_domains)) {
			return [];
		}

		$available_domains = array_filter($available_domains, static function ($domain) {
			return isset($domain['ping'], $domain['domain'])
				&& is_numeric($domain['ping'])
				&& (int) $domain['ping'] > 0
				&& !self::has_recent_errors($domain['domain']);
		});

		if (empty($available_domains)) {
			return [];
		}

		usort($available_domains, static function ($a, $b) {
			return ((int) $a['ping']) <=> ((int) $b['ping']);
		});

		return array_values(array_map(static fn ($d) => $d['domain'], $available_domains));
	}

	/**
	 * @return array<int, array{domain: string, ping: int, available: bool}>
	 */
	private static function get_available_domains(): array
	{
		$available_domains = self::state()['available_domains'];
		if (empty($available_domains) || !is_array($available_domains)) {
			return [];
		}

		return array_values(array_filter($available_domains, static function ($domain) {
			return isset($domain['ping'], $domain['domain'])
				&& (int) $domain['ping'] > 0;
		}));
	}

	public static function mark_domain_error(string $domain): void
	{
		$hash = md5($domain);

		DomainCache::with_lock(static function (array $state) use ($hash) {
			$errors = isset($state['errors'][$hash]) && is_array($state['errors'][$hash]) ? $state['errors'][$hash] : [];
			$errors[] = time();
			$cutoff = time() - self::ERROR_CACHE_TTL;
			$errors = array_values(array_filter($errors, static function ($time) use ($cutoff) {
				return $time > $cutoff;
			}));

			if ($errors === []) {
				unset($state['errors'][$hash]);
			} else {
				$state['errors'][$hash] = $errors;
			}

			return $state;
		});

		if (self::has_recent_errors($domain)) {
			self::force_refresh();
		}
	}

	/**
	 * Uses cached domain ping data. Does not block HTTP requests when cache is stale —
	 * hourly WP-Cron ({@see cron_refresh_domains}) refreshes ping scores.
	 * Runs synchronous HEAD probes only when the cache has never been populated (cold start).
	 */
	private static function update_domains_if_need(): void
	{
		$state = self::state();
		$last_check = (int) ($state['last_check_domains'] ?? 0);
		$available = $state['available_domains'] ?? [];
		$cold = $last_check === 0 && (empty($available) || !is_array($available));

		if ($cold) {
			static::check_and_update_domains();
		}
	}

	/**
	 * WP-Cron: refresh domain availability / ping (hourly).
	 */
	public static function cron_refresh_domains(): void
	{
		static::check_and_update_domains();
	}

	private static function check_and_update_domains(): void
	{
		$domains = Env::domains();
		$check_uri = Env::available_check_uri();

		if (empty($domains) || !is_array($domains)) {
			DomainCache::save(array_merge(DomainCache::load(), [
				'available_domains' => [],
				'last_check_domains' => time(),
			]));

			return;
		}

		$results = [];

		foreach ($domains as $domain) {
			if (!is_string($domain) || trim($domain) === '') {
				continue;
			}

			$url = rtrim($domain, '/') . '/' . ltrim($check_uri, '/');
			$t0 = microtime(true);
			$response = wp_remote_head($url, [
				'timeout' => self::HTTP_TIMEOUT,
				'redirection' => 0,
				'sslverify' => true,
				'user-agent' => Api::USER_AGENT . '/DomainSelector',
			]);

			$dt_ms = (int) round((microtime(true) - $t0) * 1000);
			$result = [
				'domain' => $domain,
			];

			if (is_wp_error($response)) {
				$result['ping'] = self::UNAVAILABLE_PING;
				$result['available'] = false;
			} else {
				$code = (int) wp_remote_retrieve_response_code($response);
				if ($code === 200) {
					$result['ping'] = max(1, $dt_ms);
					$result['available'] = true;
				} else {
					$result['ping'] = self::UNAVAILABLE_PING;
					$result['available'] = false;
				}
			}

			$results[] = $result;
		}

		if ($results === []) {
			DomainCache::save(array_merge(DomainCache::load(), [
				'available_domains' => [],
				'last_check_domains' => time(),
			]));

			return;
		}

		DomainCache::save(array_merge(DomainCache::load(), [
			'available_domains' => $results,
			'last_check_domains' => time(),
		]));
	}

	private static function force_refresh(): void
	{
		static::check_and_update_domains();
	}
}
