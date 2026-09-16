<?php

namespace parrotposter\Migration;

defined('ABSPATH') || exit;

/**
 * Map legacy UTM / VK extras to GraphQL template fields (camelCase).
 */
class UtmVkConverter
{
	/**
	 * @param array<string, mixed> $row
	 * @param list<string>         $account_ids
	 * @param string               $url_template
	 * @return array{
	 *   needUtm: bool,
	 *   utmParams: array<string, string>|null,
	 *   socialTypeOverrides: list<array<string, mixed>>,
	 *   platformData: array<string, mixed>|null,
	 *   warnings: list<string>
	 * }
	 */
	public static function convert(array $row, array $account_ids, $url_template)
	{
		$utm = self::build_utm($row, $account_ids, $url_template);
		$platform_data = self::build_platform_data($row, $account_ids);

		return [
			'needUtm' => $utm['needUtm'],
			'utmParams' => $utm['utmParams'],
			'socialTypeOverrides' => $utm['socialTypeOverrides'],
			'platformData' => $platform_data,
			'warnings' => $utm['warnings'],
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string>         $account_ids
	 * @param string               $url_template
	 * @return array{
	 *   needUtm: bool,
	 *   utmParams: array<string, string>|null,
	 *   socialTypeOverrides: list<array<string, mixed>>,
	 *   warnings: list<string>
	 * }
	 */
	private static function build_utm(array $row, array $account_ids, $url_template)
	{
		if (empty($row['utm_enable'])) {
			return [
				'needUtm' => false,
				'utmParams' => null,
				'socialTypeOverrides' => [],
				'warnings' => [],
			];
		}

		$warnings = [];
		$raw_fields = [
			'source' => isset($row['utm_source']) ? (string) $row['utm_source'] : '',
			'medium' => isset($row['utm_medium']) ? (string) $row['utm_medium'] : '',
			'campaign' => isset($row['utm_campaign']) ? (string) $row['utm_campaign'] : '',
			'term' => isset($row['utm_term']) ? (string) $row['utm_term'] : '',
			'content' => isset($row['utm_content']) ? (string) $row['utm_content'] : '',
		];

		$converted = [];
		foreach ($raw_fields as $name => $raw) {
			if ($raw === '') {
				continue;
			}
			$converted[$name] = MacroConverter::convert($raw);
		}

		$default_params = [];
		$override_utm = [];
		$social_types = self::social_types_from_account_ids($account_ids);

		foreach ($converted as $field_name => $value) {
			if (strpos($value, MacroConverter::SOCIAL_CODE_PLACEHOLDER) !== false) {
				$warnings[] = 'utm ' . $field_name . ' uses per-network placeholder; check per-platform UTM overrides';
				$default_params[$field_name] = '';
				foreach ($social_types as $social_type) {
					$slug = self::social_type_slug($social_type);
					if (!isset($override_utm[$social_type])) {
						$override_utm[$social_type] = [];
					}
					$override_utm[$social_type][$field_name] = $slug;
				}
			} else {
				$default_params[$field_name] = $value;
			}
		}

		$has_any = false;
		foreach ($default_params as $value) {
			if ($value !== '') {
				$has_any = true;
				break;
			}
		}
		if (!$has_any && $converted !== []) {
			$has_any = true;
		}

		$utm_params = $has_any ? $default_params : null;
		$overrides = [];
		foreach ($override_utm as $social_type => $params) {
			$overrides[] = [
				'socialType' => $social_type,
				'block' => [
					'link' => [
						'urlTemplate' => $url_template,
						'needUtm' => true,
						'utmParams' => $params,
					],
				],
			];
		}

		return [
			'needUtm' => true,
			'utmParams' => $utm_params,
			'socialTypeOverrides' => $overrides,
			'warnings' => $warnings,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string>         $account_ids
	 * @return array<string, mixed>|null
	 */
	private static function build_platform_data(array $row, array $account_ids)
	{
		if (!self::has_vk_account($account_ids)) {
			return null;
		}

		return [
			'vk' => [
				'fromGroup' => !empty($row['extra_vk_from_group']),
				'signed' => !empty($row['extra_vk_signed']),
			],
		];
	}

	/**
	 * @param list<string> $account_ids
	 * @return bool
	 */
	public static function has_vk_account(array $account_ids)
	{
		return in_array('VK', self::social_types_from_account_ids($account_ids), true);
	}

	/**
	 * @param list<string> $account_ids
	 * @return list<string> GraphQL SocialType enum values
	 */
	public static function social_types_from_account_ids(array $account_ids)
	{
		$out = [];
		foreach ($account_ids as $id) {
			$type = self::social_type_from_account_id($id);
			if ($type === null) {
				continue;
			}
			if (!in_array($type, $out, true)) {
				$out[] = $type;
			}
		}

		return $out;
	}

	/**
	 * @param string $account_id
	 * @return string|null
	 */
	private static function social_type_from_account_id($account_id)
	{
		$parts = explode(':', (string) $account_id);
		if (count($parts) < 2) {
			return null;
		}
		$map = [
			'vk' => 'VK',
			'fb' => 'FB',
			'ok' => 'OK',
			'ig' => 'IG',
			'tg' => 'TG',
			'telegram' => 'TG',
			'max' => 'MAX',
			'test' => 'TEST',
		];
		$slug = strtolower($parts[1]);

		return isset($map[$slug]) ? $map[$slug] : null;
	}

	/**
	 * @param string $social_type GraphQL enum
	 * @return string persist slug used as UTM value
	 */
	private static function social_type_slug($social_type)
	{
		$map = [
			'VK' => 'vk',
			'FB' => 'fb',
			'OK' => 'ok',
			'IG' => 'ig',
			'TG' => 'tg',
			'MAX' => 'max',
			'TEST' => 'test',
		];

		return isset($map[$social_type]) ? $map[$social_type] : strtolower($social_type);
	}
}
