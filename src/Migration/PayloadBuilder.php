<?php

namespace parrotposter\Migration;

defined('ABSPATH') || exit;

/**
 * Build GraphQL MigratedPipelineConfigInput from a legacy autoposting DB row.
 */
class PayloadBuilder
{
	const HASHTAGS_LIMIT = 20;

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function from_row(array $row)
	{
		$warnings = [];
		$name = isset($row['name']) ? trim((string) $row['name']) : '';
		if ($name === '') {
			$name = 'Migrated pipeline';
		}
		$post_type = isset($row['wp_post_type']) ? trim((string) $row['wp_post_type']) : '';
		if ($post_type === '') {
			$post_type = 'post';
		}

		$account_ids = [];
		if (isset($row['account_ids']) && is_array($row['account_ids'])) {
			foreach ($row['account_ids'] as $aid) {
				if (!is_string($aid) && !is_numeric($aid)) {
					continue;
				}
				$id = trim((string) $aid);
				if ($id !== '') {
					$account_ids[] = $id;
				}
			}
		}
		if ($account_ids === []) {
			$warnings[] = 'Config "' . $name . '" has no accounts; pipeline created without targets';
		}

		$legacy_template = isset($row['post_text']) ? (string) $row['post_text'] : '';
		if (trim($legacy_template) === '') {
			$legacy_template = '{{ item.title }}' . "\n" . '{{ item.excerpt }}';
		}
		$body = MacroConverter::convert($legacy_template);
		if (isset($row['post_tags'])) {
			$converted_tags = trim(MacroConverter::convert((string) $row['post_tags']));
			if ($converted_tags !== '') {
				$body .= "\n\n" . self::append_hashtags_filter($converted_tags);
			}
		}

		$legacy_link = isset($row['post_link']) ? (string) $row['post_link'] : '';
		$link_template = trim($legacy_link) === ''
			? '{{ item.link }}'
			: MacroConverter::convert($legacy_link);

		$media = MediaFieldConverter::convert(isset($row['post_images']) ? $row['post_images'] : []);
		$warnings = array_merge($warnings, $media['warnings']);

		$utm_vk = UtmVkConverter::convert($row, $account_ids, $link_template);
		$warnings = array_merge($warnings, $utm_vk['warnings']);

		$conditions = ConditionsConverter::convert(
			isset($row['conditions']) ? $row['conditions'] : [],
			$name
		);
		$warnings = array_merge($warnings, $conditions['warnings']);

		$default_block = [
			'text' => [
				'bodyTemplate' => $body,
				'format' => 'PLAIN',
			],
			'link' => [
				'urlTemplate' => $link_template,
				'needUtm' => $utm_vk['needUtm'],
				'preview' => [
					'titleTemplate' => '{{ item.title }}',
					'captionTemplate' => '{{ item.excerpt }}',
					'image' => [
						'field' => 'featured_image',
						'mode' => 'ONE',
						'kind' => 'IMAGE',
						'source' => 'DEFAULT',
					],
				],
			],
		];
		if ($utm_vk['utmParams'] !== null) {
			$default_block['link']['utmParams'] = $utm_vk['utmParams'];
		}
		if ($media['sources'] !== []) {
			$default_block['media'] = ['sources' => $media['sources']];
		}
		if ($utm_vk['platformData'] !== null) {
			$default_block['platformData'] = $utm_vk['platformData'];
		}

		$template = [
			'default' => $default_block,
		];
		if ($utm_vk['socialTypeOverrides'] !== []) {
			$template['socialTypeOverrides'] = $utm_vk['socialTypeOverrides'];
		}

		$payload = [
			'legacyId' => isset($row['id']) ? (string) $row['id'] : '',
			'name' => $name,
			'postType' => $post_type,
			'accountIds' => $account_ids,
			'template' => $template,
			'trigger' => TriggerDelayConverter::convert(
				isset($row['when_publish']) ? $row['when_publish'] : null,
				isset($row['publish_delay']) ? $row['publish_delay'] : 0
			),
		];
		if ($conditions['item'] !== null) {
			$payload['contentRules'] = ['item' => $conditions['item']];
		}
		if ($warnings !== []) {
			$payload['warnings'] = $warnings;
		}

		return $payload;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array<string, mixed>
	 */
	public static function from_cluster(array $rows)
	{
		if ($rows === []) {
			return self::from_row([]);
		}
		if (count($rows) === 1) {
			return self::from_row($rows[0]);
		}

		$canonical = TemplateClusterer::pick_canonical($rows);
		$union_accounts = TemplateClusterer::union_account_ids($rows);
		$synthetic = $canonical;
		$synthetic['account_ids'] = $union_accounts;

		if (UtmVkConverter::has_vk_account($union_accounts)
			&& !UtmVkConverter::has_vk_account(TemplateClusterer::account_ids($canonical))
		) {
			$vk_row = TemplateClusterer::pick_vk_row($rows);
			if (is_array($vk_row)) {
				$synthetic['extra_vk_from_group'] = isset($vk_row['extra_vk_from_group'])
					? $vk_row['extra_vk_from_group']
					: 0;
				$synthetic['extra_vk_signed'] = isset($vk_row['extra_vk_signed'])
					? $vk_row['extra_vk_signed']
					: 0;
			}
		}

		$payload = self::from_row($synthetic);
		$payload['name'] = TemplateClusterer::cluster_name($rows);
		$payload['legacyId'] = TemplateClusterer::cluster_legacy_id($rows);

		$canonical_default = isset($payload['template']['default']) && is_array($payload['template']['default'])
			? $payload['template']['default']
			: [];
		$overrides_by_type = [];
		if (isset($payload['template']['socialTypeOverrides'])
			&& is_array($payload['template']['socialTypeOverrides'])
		) {
			foreach ($payload['template']['socialTypeOverrides'] as $entry) {
				if (!is_array($entry) || empty($entry['socialType']) || !isset($entry['block']) || !is_array($entry['block'])) {
					continue;
				}
				$overrides_by_type[(string) $entry['socialType']] = $entry['block'];
			}
		}

		$canonical_content = TemplateClusterer::content_key($canonical);
		foreach ($rows as $row) {
			if (TemplateClusterer::content_key($row) === $canonical_content) {
				continue;
			}
			$member_payload = self::from_row($row);
			$member_default = isset($member_payload['template']['default'])
				&& is_array($member_payload['template']['default'])
				? $member_payload['template']['default']
				: [];
			$diff = self::default_block_diff($canonical_default, $member_default);
			if ($diff === null) {
				continue;
			}
			foreach (UtmVkConverter::social_types_from_account_ids(TemplateClusterer::account_ids($row)) as $type) {
				$base = isset($overrides_by_type[$type]) ? $overrides_by_type[$type] : [];
				$overrides_by_type[$type] = self::merge_override_blocks($base, $diff);
			}
		}

		if ($overrides_by_type !== []) {
			$list = [];
			foreach (self::ordered_social_types(array_keys($overrides_by_type)) as $type) {
				$list[] = [
					'socialType' => $type,
					'block' => $overrides_by_type[$type],
				];
			}
			$payload['template']['socialTypeOverrides'] = $list;
		}

		$warnings = isset($payload['warnings']) && is_array($payload['warnings'])
			? $payload['warnings']
			: [];
		$warnings[] = 'Merged ' . count($rows) . ' autoposting templates into one pipeline';
		$payload['warnings'] = array_values(array_unique($warnings));

		return $payload;
	}

	/**
	 * @param array<string, mixed> $canonical
	 * @param array<string, mixed> $member
	 * @return array<string, mixed>|null
	 */
	private static function default_block_diff(array $canonical, array $member)
	{
		$block = [];

		$c_text = isset($canonical['text']) && is_array($canonical['text']) ? $canonical['text'] : [];
		$m_text = isset($member['text']) && is_array($member['text']) ? $member['text'] : [];
		$c_body = isset($c_text['bodyTemplate']) ? (string) $c_text['bodyTemplate'] : '';
		$m_body = isset($m_text['bodyTemplate']) ? (string) $m_text['bodyTemplate'] : '';
		$c_format = isset($c_text['format']) ? (string) $c_text['format'] : 'PLAIN';
		$m_format = isset($m_text['format']) ? (string) $m_text['format'] : 'PLAIN';
		if ($c_body !== $m_body || $c_format !== $m_format) {
			$block['text'] = [
				'bodyTemplate' => $m_body,
				'format' => $m_format,
			];
		}

		$c_link = isset($canonical['link']) && is_array($canonical['link']) ? $canonical['link'] : [];
		$m_link = isset($member['link']) && is_array($member['link']) ? $member['link'] : [];
		$c_url = isset($c_link['urlTemplate']) ? (string) $c_link['urlTemplate'] : '';
		$m_url = isset($m_link['urlTemplate']) ? (string) $m_link['urlTemplate'] : '';
		$c_need = !empty($c_link['needUtm']);
		$m_need = !empty($m_link['needUtm']);
		$c_params = isset($c_link['utmParams']) && is_array($c_link['utmParams']) ? $c_link['utmParams'] : null;
		$m_params = isset($m_link['utmParams']) && is_array($m_link['utmParams']) ? $m_link['utmParams'] : null;
		if ($c_url !== $m_url || $c_need !== $m_need || $c_params !== $m_params) {
			$link = [
				'urlTemplate' => $m_url !== '' ? $m_url : $c_url,
				'needUtm' => $m_need,
			];
			if ($m_params !== null) {
				$link['utmParams'] = $m_params;
			}
			if (isset($c_link['preview'])) {
				$link['preview'] = $c_link['preview'];
			} elseif (isset($m_link['preview'])) {
				$link['preview'] = $m_link['preview'];
			}
			$block['link'] = $link;
		}

		$c_pd = isset($canonical['platformData']) && is_array($canonical['platformData'])
			? $canonical['platformData']
			: null;
		$m_pd = isset($member['platformData']) && is_array($member['platformData'])
			? $member['platformData']
			: null;
		if ($m_pd !== null && $m_pd !== $c_pd) {
			$block['platformData'] = $m_pd;
		}

		return $block === [] ? null : $block;
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $diff
	 * @return array<string, mixed>
	 */
	private static function merge_override_blocks(array $base, array $diff)
	{
		foreach ($diff as $key => $value) {
			if ($key === 'link' && isset($base['link']) && is_array($base['link']) && is_array($value)) {
				$base['link'] = array_merge($base['link'], $value);
				continue;
			}
			if ($key === 'platformData' && isset($base['platformData']) && is_array($base['platformData']) && is_array($value)) {
				$base['platformData'] = array_merge($base['platformData'], $value);
				continue;
			}
			$base[$key] = $value;
		}

		return $base;
	}

	/**
	 * Attach `| hashtags: 20` to `{{ item.<field> }}` tokens in a migrated tags fragment.
	 *
	 * @param string $liquid
	 * @return string
	 */
	private static function append_hashtags_filter($liquid)
	{
		$replaced = preg_replace_callback(
			'/\{\{\s*item\.([a-zA-Z0-9_.]+)((?:\s*\|\s*[^|}]+)*)\s*\}\}/',
			[self::class, 'append_hashtags_filter_token'],
			$liquid
		);

		return is_string($replaced) ? $replaced : $liquid;
	}

	/**
	 * @param array<int, string> $match
	 * @return string
	 */
	private static function append_hashtags_filter_token(array $match)
	{
		$field = $match[1];
		$filters = isset($match[2]) ? $match[2] : '';
		if (preg_match('/\|\s*hashtags\b/', $filters)) {
			return $match[0];
		}

		return '{{ item.' . $field . $filters . ' | hashtags: ' . self::HASHTAGS_LIMIT . ' }}';
	}

	/**
	 * @param list<string> $types
	 * @return list<string>
	 */
	private static function ordered_social_types(array $types)
	{
		$order = ['VK', 'TG', 'FB', 'OK', 'IG', 'MAX', 'TEST'];
		$present = [];
		foreach ($types as $type) {
			$present[$type] = true;
		}
		$out = [];
		foreach ($order as $type) {
			if (isset($present[$type])) {
				$out[] = $type;
				unset($present[$type]);
			}
		}
		$rest = array_keys($present);
		sort($rest, SORT_STRING);

		return array_merge($out, $rest);
	}
}
