<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Конфигурация окружения: домены ParrotPoster, URI API/GraphQL/фронта.
 */
class Env
{
	private const DEFAULT_DOMAINS = [
		'https://parrotposter.com',
		'https://mirror-pl.parrotposter.com',
	];

	private const DEFAULT_AVAILABLE_CHECK_URI = '/api/v1/ping';
	private const DEFAULT_API_URI = '/api/v1';
	private const DEFAULT_GRAPHQL_API_URI = '/api/graphql';
	private const DEFAULT_FRONT_BASE_URI = '/plugin/wp';
	private const DEFAULT_LOG_ENABLED = false;

	/**
	 * Список базовых URL сервиса (scheme://host[:port]), без пути API.
	 *
	 * @return string[]
	 */
	public static function domains(): array
	{
		if (defined('PARROTPOSTER_DOMAINS') && is_string(PARROTPOSTER_DOMAINS) && PARROTPOSTER_DOMAINS !== '') {
			$parts = preg_split('/\s*,\s*/', PARROTPOSTER_DOMAINS, -1, PREG_SPLIT_NO_EMPTY);

			return $parts ? array_values($parts) : self::DEFAULT_DOMAINS;
		}

		return self::DEFAULT_DOMAINS;
	}

	/**
	 * URI для проверки доступности (относительно домена).
	 */
	public static function available_check_uri(): string
	{
		return self::DEFAULT_AVAILABLE_CHECK_URI;
	}

	/**
	 * Префикс REST API на домене (например /api/v1).
	 */
	public static function api_uri(): string
	{
		return self::DEFAULT_API_URI;
	}

	/**
	 * Путь GraphQL на домене.
	 */
	public static function graphql_api_uri(): string
	{
		return self::DEFAULT_GRAPHQL_API_URI;
	}

	/**
	 * Базовый путь фронта plugin/wp на домене (без завершающего слэша).
	 */
	public static function front_base_uri(): string
	{
		return self::DEFAULT_FRONT_BASE_URI;
	}

	/**
	 * Подробный console.log в iframe-loader.
	 */
	public static function iframe_debug(): bool
	{
		return defined('PARROTPOSTER_IFRAME_DEBUG') && PARROTPOSTER_IFRAME_DEBUG;
	}

	public static function log_enabled(): bool {
		if (defined('PARROTPOSTER_LOG_ENABLED')) {
			return PARROTPOSTER_LOG_ENABLED;
		}
		return self::DEFAULT_LOG_ENABLED;
	}

	/**
	 * Seed one pending local-queue row in admin (for UI preview). Set in wp-config.php:
	 * define('PARROTPOSTER_LQ_TEST_SEED', true);
	 */
	public static function local_queue_test_seed(): bool
	{
		return defined('PARROTPOSTER_LQ_TEST_SEED') && PARROTPOSTER_LQ_TEST_SEED;
	}
}
